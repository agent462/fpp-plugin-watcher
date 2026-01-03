#!/usr/bin/env php
<?php
/**
 * Voltage Metrics Collection Daemon
 *
 * Runs in the background collecting Raspberry Pi core voltage readings every 3 seconds.
 * Writes raw metrics and triggers rollup processing. Only supported on Raspberry Pi.
 *
 * Usage: php voltageCollector.php
 *
 * @package fpp-plugin-watcher
 */

require_once __DIR__ . '/classes/autoload.php'; // Load class autoloader
require_once __DIR__ . '/lib/core/watcherCommon.php';
require_once __DIR__ . '/lib/core/config.php';

use Watcher\Core\Logger;
use Watcher\Core\DaemonLock;
use Watcher\Metrics\VoltageCollector;
use Watcher\Controllers\VoltageHardware;

// Config hot-reload tracking (global for checkAndReloadVoltageConfig)
$lastConfigMtime = file_exists(WATCHERCONFIGFILELOCATION) ? filemtime(WATCHERCONFIGFILELOCATION) : 0;
$config = readPluginConfig();
$collectionInterval = $config['voltageCollectionInterval'] ?? VOLTAGE_COLLECTION_INTERVAL;

function voltageLog($message) {
    Logger::getInstance()->info("[Voltage Collector] $message", VOLTAGE_LOG_FILE);
}

function isVoltageEnabled() {
    $config = readPluginConfig(true); // Force refresh
    return !empty($config['voltageMonitorEnabled']);
}

function hasVoltageHardware() {
    $hardware = VoltageHardware::getInstance()->detectHardware();
    return $hardware['supported'];
}

/**
 * Check if config file has changed and reload if necessary
 * Returns true if config was reloaded, false otherwise
 */
function checkAndReloadVoltageConfig() {
    global $config, $lastConfigMtime, $collectionInterval;

    $currentMtime = file_exists(WATCHERCONFIGFILELOCATION) ? filemtime(WATCHERCONFIGFILELOCATION) : 0;

    if ($currentMtime <= $lastConfigMtime) {
        return false;
    }

    voltageLog("Config file changed (mtime: $lastConfigMtime -> $currentMtime), reloading configuration...");
    $lastConfigMtime = $currentMtime;

    // Force reload config (bypass cache)
    $newConfig = readPluginConfig(true);

    // Check if voltage monitoring was disabled
    if (empty($newConfig['voltageMonitorEnabled'])) {
        voltageLog("Voltage monitoring disabled via config reload. Exiting gracefully.");
        exit(0);
    }

    // Check if collection interval changed
    $newInterval = $newConfig['voltageCollectionInterval'] ?? VOLTAGE_COLLECTION_INTERVAL;
    if ($newInterval !== $collectionInterval) {
        voltageLog("Collection interval changed: {$collectionInterval}s -> {$newInterval}s");
        $collectionInterval = $newInterval;
    }

    // Apply new config
    $config = $newConfig;

    voltageLog("Configuration reloaded successfully");
    return true;
}

/**
 * Main collection loop
 */
function runCollector() {
    global $collectionInterval;

    voltageLog("Voltage Collector starting...");

    // Verify hardware on startup
    $hardware = VoltageHardware::getInstance()->detectHardware();
    if (!$hardware['supported']) {
        voltageLog("ERROR: Voltage monitoring not supported (requires Raspberry Pi). Exiting.");
        exit(1);
    }

    $railCount = count($hardware['availableRails']);
    $railList = implode(', ', $hardware['availableRails']);
    voltageLog("Hardware detected: {$hardware['type']} via {$hardware['method']} ({$railCount} rails: {$railList})");
    voltageLog("Collection interval: {$collectionInterval}s, Retention: 24 hours");

    $lastRollupTime = 0;
    $lastConfigCheckTime = 0;
    $samplesCollected = 0;

    // Error tracking for resilience
    $consecutiveErrors = 0;
    $lastErrorLogTime = 0;
    $wasInErrorState = false;

    while (true) {
        $loopStart = microtime(true);
        $now = time();

        // Check config periodically for hot-reload
        try {
            if (($now - $lastConfigCheckTime) >= VOLTAGE_CONFIG_CHECK_INTERVAL) {
                $lastConfigCheckTime = $now;
                checkAndReloadVoltageConfig();
            }
        } catch (Exception $e) {
            // Config check failed; continue anyway, retry next interval
        }

        // Collect voltage data with error resilience
        try {
            $reading = VoltageHardware::getInstance()->readAllVoltages();

            if ($reading['success']) {
                // Success - reset error state
                if ($wasInErrorState) {
                    voltageLog("Recovered: voltage reading restored after $consecutiveErrors errors");
                    $wasInErrorState = false;
                }
                $consecutiveErrors = 0;

                $voltages = $reading['voltages'];
                $samplesCollected++;

                // Write all voltage readings
                if (VoltageCollector::getInstance()->writeRawMetrics($voltages)) {
                    // Successfully written
                }
            } else {
                // Read failed; track errors but don't exit
                $consecutiveErrors++;
                $wasInErrorState = true;

                // Only log errors periodically to avoid spam
                if (($now - $lastErrorLogTime) >= VOLTAGE_ERROR_LOG_INTERVAL) {
                    $lastErrorLogTime = $now;
                    voltageLog("WARNING: Voltage read failed ($consecutiveErrors consecutive errors): " . ($reading['error'] ?? 'Unknown error'));
                }
            }
        } catch (Exception $e) {
            // Catch any unexpected exceptions to prevent daemon crash
            $consecutiveErrors++;
            $wasInErrorState = true;

            if (($now - $lastErrorLogTime) >= VOLTAGE_ERROR_LOG_INTERVAL) {
                $lastErrorLogTime = $now;
                voltageLog("ERROR: Exception during collection: " . $e->getMessage());
            }
        }

        // Process rollups periodically (every 60 seconds)
        if (($now - $lastRollupTime) >= VOLTAGE_ROLLUP_INTERVAL) {
            $lastRollupTime = $now;

            try {
                VoltageCollector::getInstance()->processRollup();
            } catch (Exception $e) {
                voltageLog("ERROR: Rollup processing failed: " . $e->getMessage());
            }

            // Log status every rollup cycle
            if ($samplesCollected > 0) {
                voltageLog("Status: $samplesCollected samples collected");
                $samplesCollected = 0;
            } elseif ($wasInErrorState) {
                voltageLog("Status: voltage reads failing, waiting for recovery...");
            }
        }

        // Calculate sleep time with backoff when errors occur
        $elapsed = microtime(true) - $loopStart;
        $baseInterval = $collectionInterval;

        // Apply exponential backoff when in error state (max 60 seconds)
        if ($consecutiveErrors > 0) {
            $backoffSeconds = min(VOLTAGE_MAX_BACKOFF_SECONDS, pow(2, min($consecutiveErrors, 6)));
            $baseInterval = $backoffSeconds;
        }

        $sleepTime = max(0, $baseInterval - $elapsed);

        if ($sleepTime > 0) {
            usleep(intval($sleepTime * 1000000));
        }
    }
}

// Signal handling for graceful shutdown
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
}

function signalHandler($signo) {
    voltageLog("Received signal $signo, shutting down...");
    exit(0);
}

if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, 'signalHandler');
    pcntl_signal(SIGINT, 'signalHandler');
    pcntl_signal(SIGHUP, 'signalHandler');
}

// Acquire daemon lock (handles stale lock detection automatically)
$lockFp = DaemonLock::acquire('voltage-collector', VOLTAGE_LOG_FILE);
if (!$lockFp) {
    exit(1);
}

// Verify enabled before starting
if (!isVoltageEnabled()) {
    voltageLog("Voltage monitoring is not enabled in config. Exiting.");
    DaemonLock::release($lockFp, 'voltage-collector');
    exit(0);
}

// Verify hardware before starting
if (!hasVoltageHardware()) {
    voltageLog("Voltage monitoring not supported (requires Raspberry Pi). Exiting.");
    DaemonLock::release($lockFp, 'voltage-collector');
    exit(0);
}

// Run the collector
try {
    runCollector();
} catch (Exception $e) {
    voltageLog("FATAL ERROR: " . $e->getMessage());
    exit(1);
} finally {
    DaemonLock::release($lockFp, 'voltage-collector');
}
?>
