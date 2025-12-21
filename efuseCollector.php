#!/usr/bin/env php
<?php
/**
 * eFuse Metrics Collection Daemon
 *
 * Runs in the background collecting eFuse current readings every X seconds.
 * Writes raw metrics and triggers rollup processing.
 *
 * Usage: php efuseCollector.php
 *
 * @package fpp-plugin-watcher
 */

require_once __DIR__ . '/classes/autoload.php'; // Load class autoloader
require_once __DIR__ . '/lib/core/watcherCommon.php';
require_once __DIR__ . '/lib/core/config.php';

use Watcher\Metrics\EfuseCollector;
use Watcher\Controllers\EfuseHardware;

// Config hot-reload tracking (global for checkAndReloadEfuseConfig)
$lastConfigMtime = file_exists(WATCHERCONFIGFILELOCATION) ? filemtime(WATCHERCONFIGFILELOCATION) : 0;
$config = readPluginConfig();
$collectionInterval = $config['efuseCollectionInterval'] ?? 5;
$retentionDays = $config['efuseRetentionDays'] ?? 14;

function efuseLog($message) {
    logMessage("[eFuse Collector] $message", EFUSE_LOG_FILE);
}

function isEfuseEnabled() {
    $config = readPluginConfig(true); // Force refresh
    return !empty($config['efuseMonitorEnabled']);
}

function hasEfuseHardware() {
    $hardware = EfuseHardware::getInstance()->detectHardware();
    return $hardware['supported'];
}

/**
 * Check if config file has changed and reload if necessary
 * Returns true if config was reloaded, false otherwise
 */
function checkAndReloadEfuseConfig() {
    global $config, $lastConfigMtime, $collectionInterval, $retentionDays;

    $currentMtime = file_exists(WATCHERCONFIGFILELOCATION) ? filemtime(WATCHERCONFIGFILELOCATION) : 0;

    if ($currentMtime <= $lastConfigMtime) {
        return false;
    }

    efuseLog("Config file changed (mtime: $lastConfigMtime -> $currentMtime), reloading configuration...");
    $lastConfigMtime = $currentMtime;

    // Force reload config (bypass cache)
    $newConfig = readPluginConfig(true);

    // Check if eFuse monitoring was disabled
    if (empty($newConfig['efuseMonitorEnabled'])) {
        efuseLog("eFuse monitoring disabled via config reload. Exiting gracefully.");
        exit(0);
    }

    // Log and apply collection interval changes
    $newInterval = $newConfig['efuseCollectionInterval'] ?? 5;
    if ($newInterval !== $collectionInterval) {
        efuseLog("Collection interval changed: {$collectionInterval}s -> {$newInterval}s");
        $collectionInterval = $newInterval;
    }

    // Log and apply retention changes
    $newRetention = $newConfig['efuseRetentionDays'] ?? 7;
    if ($newRetention !== $retentionDays) {
        efuseLog("Retention changed: {$retentionDays} days -> {$newRetention} days");
        $retentionDays = $newRetention;
    }

    // Apply new config
    $config = $newConfig;

    efuseLog("Configuration reloaded successfully");
    return true;
}

/**
 * Main collection loop
 */
function runCollector() {
    global $collectionInterval, $retentionDays;

    efuseLog("eFuse Collector starting...");

    // Verify hardware on startup
    $hardware = EfuseHardware::getInstance()->detectHardware();
    if (!$hardware['supported']) {
        efuseLog("ERROR: No compatible eFuse hardware detected. Exiting.");
        exit(1);
    }

    efuseLog("Hardware detected: {$hardware['type']} with {$hardware['ports']} ports");
    efuseLog("Collection method: " . ($hardware['details']['method'] ?? 'unknown'));
    efuseLog("Collection interval: {$collectionInterval}s, Retention: {$retentionDays} days");

    $lastRollupTime = 0;
    $lastConfigCheckTime = 0;
    $samplesCollected = 0;
    $nonZeroSamples = 0;

    // Error tracking for resilience
    $consecutiveErrors = 0;
    $lastErrorLogTime = 0;
    $wasInErrorState = false;

    while (true) {
        $loopStart = microtime(true);
        $now = time();

        // Check config periodically for hot-reload
        try {
            if (($now - $lastConfigCheckTime) >= EFUSE_CONFIG_CHECK_INTERVAL) {
                $lastConfigCheckTime = $now;
                checkAndReloadEfuseConfig();
            }
        } catch (Exception $e) {
            // Config check failed - continue anyway, will retry next interval
        }

        // Collect eFuse data with error resilience
        try {
            $reading = EfuseHardware::getInstance()->readEfuseData();

            if ($reading['success']) {
                // Success - reset error state
                if ($wasInErrorState) {
                    efuseLog("Recovered: fppd connection restored after $consecutiveErrors errors");
                    $wasInErrorState = false;
                }
                $consecutiveErrors = 0;

                $ports = $reading['ports'];
                $samplesCollected++;

                // Only write non-zero data (as per plan - skip zeros for storage efficiency)
                if (!empty($ports)) {
                    // Validate readings (cap at max amperage)
                    foreach ($ports as $portName => $mA) {
                        if ($mA > EFUSE_MAX_AMPERAGE) {
                            efuseLog("WARNING: Port $portName reading $mA mA exceeds max (capped at " . EFUSE_MAX_AMPERAGE . ")");
                            $ports[$portName] = EFUSE_MAX_AMPERAGE;
                        }
                    }

                    if (EfuseCollector::getInstance()->writeRawMetric($ports)) {
                        $nonZeroSamples++;
                    }
                }
                // If all ports are zero, we skip writing (no metric = zero by convention)
            } else {
                // Read failed - track errors but don't exit
                $consecutiveErrors++;
                $wasInErrorState = true;

                // Only log errors periodically to avoid spam
                if (($now - $lastErrorLogTime) >= EFUSE_ERROR_LOG_INTERVAL) {
                    $lastErrorLogTime = $now;
                    efuseLog("WARNING: fppd unavailable ($consecutiveErrors consecutive errors): " . ($reading['error'] ?? 'Unknown error'));
                }
            }
        } catch (Exception $e) {
            // Catch any unexpected exceptions to prevent daemon crash
            $consecutiveErrors++;
            $wasInErrorState = true;

            if (($now - $lastErrorLogTime) >= EFUSE_ERROR_LOG_INTERVAL) {
                $lastErrorLogTime = $now;
                efuseLog("ERROR: Exception during collection: " . $e->getMessage());
            }
        }

        // Process rollups periodically (with error handling)
        if (($now - $lastRollupTime) >= EFUSE_ROLLUP_INTERVAL) {
            $lastRollupTime = $now;

            try {
                EfuseCollector::getInstance()->processRollup();
            } catch (Exception $e) {
                efuseLog("ERROR: Rollup processing failed: " . $e->getMessage());
            }

            // Log status every rollup cycle (if we collected any samples)
            if ($samplesCollected > 0) {
                $efficiency = round(($nonZeroSamples / $samplesCollected) * 100, 1);
                efuseLog("Status: $samplesCollected samples collected, $nonZeroSamples with data ({$efficiency}% non-zero)");
                $samplesCollected = 0;
                $nonZeroSamples = 0;
            } elseif ($wasInErrorState) {
                // Log that we're still in error state
                efuseLog("Status: fppd unavailable, waiting for recovery...");
            }
        }

        // Calculate sleep time with backoff when errors occur
        $elapsed = microtime(true) - $loopStart;
        $baseInterval = $collectionInterval;

        // Apply exponential backoff when in error state (max 60 seconds)
        if ($consecutiveErrors > 0) {
            $backoffSeconds = min(EFUSE_MAX_BACKOFF_SECONDS, pow(2, min($consecutiveErrors, 6)));
            $baseInterval = $backoffSeconds;
        }

        $sleepTime = max(0, $baseInterval - $elapsed);

        if ($sleepTime > 0) {
            usleep(intval($sleepTime * 1000000));
        }
    }
}

// Signal handling for graceful shutdown
// Use async signals (PHP 7.1+) for immediate signal processing during sleep/blocking calls
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
}

function signalHandler($signo) {
    efuseLog("Received signal $signo, shutting down...");
    exit(0);
}

if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, 'signalHandler');
    pcntl_signal(SIGINT, 'signalHandler');
    pcntl_signal(SIGHUP, 'signalHandler');
}

// Acquire daemon lock (handles stale lock detection automatically)
$lockFp = acquireDaemonLock('efuse-collector', EFUSE_LOG_FILE);
if (!$lockFp) {
    exit(1);
}

// Verify enabled before starting
if (!isEfuseEnabled()) {
    efuseLog("eFuse monitoring is not enabled in config. Exiting.");
    releaseDaemonLock($lockFp, 'efuse-collector');
    exit(0);
}

// Verify hardware before starting
if (!hasEfuseHardware()) {
    efuseLog("No compatible eFuse hardware detected. Exiting.");
    releaseDaemonLock($lockFp, 'efuse-collector');
    exit(0);
}

// Run the collector
try {
    runCollector();
} catch (Exception $e) {
    efuseLog("FATAL ERROR: " . $e->getMessage());
    exit(1);
} finally {
    releaseDaemonLock($lockFp, 'efuse-collector');
}
?>
