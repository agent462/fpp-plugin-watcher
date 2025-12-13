#!/usr/bin/env php
<?php
/**
 * eFuse Metrics Collection Daemon
 *
 * Runs in the background collecting eFuse current readings every 5 seconds.
 * Writes raw metrics and triggers rollup processing.
 *
 * Usage: php efuseCollector.php
 *
 * @package fpp-plugin-watcher
 */

// Include FPP common first (sets up $settings global)
include_once "/opt/fpp/www/common.php";

// Include required files (watcherCommon.php defines WATCHERPLUGINDIR)
require_once __DIR__ . '/lib/core/watcherCommon.php';
require_once __DIR__ . '/lib/core/config.php';
require_once __DIR__ . '/lib/metrics/efuseMetrics.php';

// Collection configuration
define('EFUSE_COLLECTION_INTERVAL', 5);     // Collect every 5 seconds
define('EFUSE_ROLLUP_INTERVAL', 60);        // Process rollups every 60 seconds
define('EFUSE_CONFIG_CHECK_INTERVAL', 60);  // Check config every 60 seconds
define('EFUSE_MAX_AMPERAGE', 6000);         // Max 6A per port in mA

// Error handling configuration
define('EFUSE_MAX_BACKOFF_SECONDS', 60);    // Max backoff when fppd unavailable
define('EFUSE_ERROR_LOG_INTERVAL', 60);     // Only log errors every N seconds to avoid spam

// Log file for eFuse collector
define('EFUSE_LOG_FILE', WATCHERLOGDIR . '/fpp-plugin-watcher-efuse.log');

/**
 * Log a message with timestamp
 */
function efuseLog($message) {
    logMessage("[eFuse Collector] $message", EFUSE_LOG_FILE);
}

/**
 * Check if eFuse monitoring is enabled in config
 */
function isEfuseEnabled() {
    $config = readPluginConfig(true); // Force refresh
    return !empty($config['efuseMonitorEnabled']);
}

/**
 * Check if compatible hardware is present
 */
function hasEfuseHardware() {
    $hardware = detectEfuseHardware();
    return $hardware['supported'];
}

/**
 * Main collection loop
 */
function runCollector() {
    efuseLog("eFuse Collector starting...");

    // Verify hardware on startup
    $hardware = detectEfuseHardware();
    if (!$hardware['supported']) {
        efuseLog("ERROR: No compatible eFuse hardware detected. Exiting.");
        exit(1);
    }

    efuseLog("Hardware detected: {$hardware['type']} with {$hardware['ports']} ports");
    efuseLog("Collection method: " . ($hardware['details']['method'] ?? 'unknown'));

    // Ensure data directories exist
    ensureDataDirectories();

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

        // Check config periodically to see if we should exit
        try {
            if (($now - $lastConfigCheckTime) >= EFUSE_CONFIG_CHECK_INTERVAL) {
                $lastConfigCheckTime = $now;

                if (!isEfuseEnabled()) {
                    efuseLog("eFuse monitoring disabled in config. Exiting gracefully.");
                    exit(0);
                }
            }
        } catch (Exception $e) {
            // Config check failed - continue anyway, will retry next interval
        }

        // Collect eFuse data with error resilience
        try {
            $reading = readEfuseData();

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

                    if (writeEfuseRawMetric($ports)) {
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
                processEfuseRollup();
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
        $baseInterval = EFUSE_COLLECTION_INTERVAL;

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
declare(ticks = 1);

function signalHandler($signo) {
    efuseLog("Received signal $signo, shutting down...");
    exit(0);
}

if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, 'signalHandler');
    pcntl_signal(SIGINT, 'signalHandler');
    pcntl_signal(SIGHUP, 'signalHandler');
}

// Prevent multiple instances
$lockFile = '/tmp/fpp-watcher-efuse-collector.lock';
$lockFp = fopen($lockFile, 'c');

if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    efuseLog("Another instance is already running. Exiting.");
    exit(1);
}

// Write PID to lock file
ftruncate($lockFp, 0);
fwrite($lockFp, getmypid());
fflush($lockFp);

// Keep lock file open for duration of process
// Lock will be released when process exits

// Verify enabled before starting
if (!isEfuseEnabled()) {
    efuseLog("eFuse monitoring is not enabled in config. Exiting.");
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    @unlink($lockFile);
    exit(0);
}

// Verify hardware before starting
if (!hasEfuseHardware()) {
    efuseLog("No compatible eFuse hardware detected. Exiting.");
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    @unlink($lockFile);
    exit(0);
}

// Run the collector
try {
    runCollector();
} catch (Exception $e) {
    efuseLog("FATAL ERROR: " . $e->getMessage());
    exit(1);
} finally {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    @unlink($lockFile);
}
?>
