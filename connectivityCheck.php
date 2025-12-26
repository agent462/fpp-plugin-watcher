#!/usr/bin/php
<?php
require_once __DIR__ . '/classes/autoload.php'; // Load class autoloader
include_once __DIR__ ."/lib/core/watcherCommon.php";
include_once __DIR__ ."/lib/core/config.php";

use Watcher\Core\Settings;
use Watcher\Core\Logger;
use Watcher\Metrics\MetricsStorage;
use Watcher\Controllers\NetworkAdapter;
use Watcher\Metrics\PingCollector;
use Watcher\Metrics\MultiSyncPingCollector;
use Watcher\Metrics\NetworkQualityCollector;
use Watcher\MultiSync\SyncStatus;

$config = readPluginConfig(); // Load and prepare configuration

// Config hot-reload tracking
$configCheckInterval = 60; // Check for config changes every 60 seconds
$lastConfigCheck = 0;
$lastConfigMtime = file_exists(WATCHERCONFIGFILELOCATION) ? filemtime(WATCHERCONFIGFILELOCATION) : 0;

/**
 * Check if config file has changed and reload if necessary
 * Returns true if config was reloaded, false otherwise
 */
function checkAndReloadConfig() {
    global $config, $lastConfigMtime, $actualNetworkAdapter, $networkAdapterDisplay;
    global $cachedRemoteSystems, $lastRemoteSystemsFetch;

    $currentMtime = file_exists(WATCHERCONFIGFILELOCATION) ? filemtime(WATCHERCONFIGFILELOCATION) : 0;

    if ($currentMtime <= $lastConfigMtime) {
        return false;
    }

    Logger::getInstance()->info("Config file changed (mtime: $lastConfigMtime -> $currentMtime), reloading configuration...");
    $lastConfigMtime = $currentMtime;

    // Force reload config (bypass cache)
    $newConfig = readPluginConfig(true);

    // Check if connectivity check was disabled
    if (!$newConfig['connectivityCheckEnabled']) {
        Logger::getInstance()->info("Connectivity check disabled via config reload. Exiting gracefully.");
        exit(0);
    }

    // Update network adapter if changed
    if ($newConfig['networkAdapter'] === 'default') {
        $newAdapter = NetworkAdapter::getInstance()->detectActiveInterface();
        $newDisplay = "default (detected: $newAdapter)";
    } else {
        $newAdapter = $newConfig['networkAdapter'];
        $newDisplay = $newAdapter;
    }

    if ($newAdapter !== $actualNetworkAdapter) {
        Logger::getInstance()->info("Network adapter changed: $actualNetworkAdapter -> $newAdapter");
        $actualNetworkAdapter = $newAdapter;
        $networkAdapterDisplay = $newDisplay;
    }

    // Log significant changes
    if ($newConfig['checkInterval'] !== $config['checkInterval']) {
        Logger::getInstance()->info("Check interval changed: {$config['checkInterval']} -> {$newConfig['checkInterval']} seconds");
    }
    if ($newConfig['maxFailures'] !== $config['maxFailures']) {
        Logger::getInstance()->info("Max failures changed: {$config['maxFailures']} -> {$newConfig['maxFailures']}");
    }
    if ($newConfig['testHosts'] !== $config['testHosts']) {
        Logger::getInstance()->info("Test hosts changed: " . implode(',', $config['testHosts']) . " -> " . implode(',', $newConfig['testHosts']));
    }
    if ($newConfig['multiSyncPingEnabled'] !== $config['multiSyncPingEnabled']) {
        Logger::getInstance()->info("Multi-sync ping " . ($newConfig['multiSyncPingEnabled'] ? 'enabled' : 'disabled'));
    }

    // Clear cached remote systems to force refresh on next cycle
    $cachedRemoteSystems = null;
    $lastRemoteSystemsFetch = 0;

    // Apply new config
    $config = $newConfig;

    Logger::getInstance()->info("Configuration reloaded successfully");
    return true;
}

// Resolve 'default' network adapter to actual interface and save it
if ($config['networkAdapter'] === 'default') {
    $actualNetworkAdapter = NetworkAdapter::getInstance()->detectActiveInterface();
    // Save the detected interface to config so it's persistent
    Settings::getInstance()->writeSettingToFile('networkAdapter', $actualNetworkAdapter, WATCHERPLUGINNAME);
    Logger::getInstance()->info("Auto-detected network adapter '$actualNetworkAdapter' from 'default' setting and saved to config");
    $networkAdapterDisplay = "default (detected: $actualNetworkAdapter)";
} else {
    $actualNetworkAdapter = $config['networkAdapter'];
    $networkAdapterDisplay = $actualNetworkAdapter;
}

// Retention period for raw metrics (25 hours)
// Keeps 24 hours of data for graphing plus 1 hour buffer
define("WATCHERMETRICSRETENTIONSECONDS", 25 * 60 * 60);

// Shared MetricsStorage instance for ping metrics
$_connectivityMetricsStorage = new MetricsStorage();

/**
 * Purge metrics log entries older than retention period
 */
function rotateMetricsFile() {
    global $_connectivityMetricsStorage;
    $_connectivityMetricsStorage->rotate(WATCHERPINGMETRICSFILE, WATCHERMETRICSRETENTIONSECONDS);
}

/**
 * Write multiple metrics entries in a single file operation
 */
function writeMetricsBatch($entries) {
    global $_connectivityMetricsStorage;
    return $_connectivityMetricsStorage->writeBatch(WATCHERPINGMETRICSFILE, $entries);
}

// Function to check internet connectivity and capture ping statistics
function checkConnectivity($testHosts, $networkAdapter) {
    global $lastPingStats;

    $lastPingStats = [
        'host' => null,
        'latency' => null,
    ];

    $anySuccess = false;
    $checkTimestamp = time(); // Single timestamp for all hosts in this check cycle
    $metricsBuffer = []; // Collect metrics for batch write

    foreach ($testHosts as $host) {
        $pingResult = NetworkAdapter::ping($host, $networkAdapter, 1);

        if ($pingResult['success']) {
            $lastPingStats['host'] = $host;
            $lastPingStats['latency'] = $pingResult['latency'];

            $metricsBuffer[] = [
                'timestamp' => $checkTimestamp,
                'host' => $host,
                'latency' => $pingResult['latency'],
                'status' => 'success'
            ];

            $anySuccess = true;
        } else {
            $metricsBuffer[] = [
                'timestamp' => $checkTimestamp,
                'host' => $host,
                'latency' => null,
                'status' => 'failure'
            ];
        }
    }

    // Write all metrics in a single file operation
    writeMetricsBatch($metricsBuffer);

    return $anySuccess;
}

if (!$config['connectivityCheckEnabled']) {
    Logger::getInstance()->info("Watcher Plugin connectivity check is disabled. Exiting.");    exit(0);
}

// Main monitoring loop
$failureCount = 0;
$lastPingStats = [];

// Check for existing reset state from previous runs
$resetState = NetworkAdapter::getInstance()->getResetState();
$hasResetAdapter = ($resetState !== null && !empty($resetState['hasResetAdapter']));
if ($hasResetAdapter) {
    $resetTime = date('Y-m-d H:i:s', $resetState['resetTimestamp'] ?? 0);
    Logger::getInstance()->info("Previous adapter reset detected from $resetTime - will exit if max failures reached again");
}
$lastRotationCheck = 0; // Track when rotation was last checked
$lastRollupCheck = 0; // Track when rollup was last processed

// Multi-sync ping tracking
$lastMultiSyncCheck = 0; // Track when multi-sync systems were last pinged
$lastMultiSyncRotationCheck = 0; // Track when multi-sync metrics rotation was last checked
$lastMultiSyncRollupCheck = 0; // Track when multi-sync rollup was last processed
$multiSyncCheckInterval = 60; // Ping multi-sync hosts every 60 seconds
$cachedRemoteSystems = null; // Cache remote systems list
$lastRemoteSystemsFetch = 0; // Track when remote systems were last fetched
$remoteSystemsCacheInterval = 300; // Refresh remote systems list every 5 minutes

// Network quality tracking (latency, jitter, packet loss)
$lastNetworkQualityCheck = 0; // Track when network quality was last collected
$lastNetworkQualityRollupCheck = 0; // Track when network quality rollups were processed
$lastNetworkQualityRotationCheck = 0; // Track when network quality metrics rotation was checked
$networkQualityCheckInterval = 60; // Collect network quality every 60 seconds

Logger::getInstance()->info("=== Watcher Plugin Started ===");
Logger::getInstance()->info("Check Interval: {$config['checkInterval']} seconds");
Logger::getInstance()->info("Max Failures: {$config['maxFailures']}");
Logger::getInstance()->info("Network Adapter: $networkAdapterDisplay");
Logger::getInstance()->info("Test Hosts: " . implode(', ', $config['testHosts']));
Logger::getInstance()->info("Multi-Sync Ping Monitoring: " . ($config['multiSyncPingEnabled'] ? 'Enabled' : 'Disabled'));

while (true) {
    $currentTime = time(); // Single timestamp for this iteration

    // Check for config changes every 60 seconds
    if (($currentTime - $lastConfigCheck) >= $configCheckInterval) {
        checkAndReloadConfig();
        $lastConfigCheck = $currentTime;
    }

    if (checkConnectivity($config['testHosts'], $actualNetworkAdapter)) {
        if ($failureCount > 0) {
            Logger::getInstance()->info("Internet connectivity restored");
        }
        $failureCount = 0;

        // Check and rotate metrics file if needed, but only every configured interval
        $rotationInterval = $config['metricsRotationInterval'] ?? 1800;

        if (($currentTime - $lastRotationCheck) >= $rotationInterval) {
            rotateMetricsFile();
            $lastRotationCheck = $currentTime;
        }

        // Process rollups every 60 seconds (will handle all tiers internally)
        $rollupInterval = 60;
        if (($currentTime - $lastRollupCheck) >= $rollupInterval) {
            PingCollector::getInstance()->processAllRollups();
            $lastRollupCheck = $currentTime;
        }

        // Multi-sync host pinging (only if enabled and in player mode)
        if ($config['multiSyncPingEnabled'] && SyncStatus::getInstance()->isPlayerMode()) {
            // Refresh remote systems list periodically
            if ($cachedRemoteSystems === null || ($currentTime - $lastRemoteSystemsFetch) >= $remoteSystemsCacheInterval) {
                $cachedRemoteSystems = SyncStatus::getInstance()->getRemoteSystems();
                $lastRemoteSystemsFetch = $currentTime;
                if (!empty($cachedRemoteSystems)) {
                    Logger::getInstance()->info("Multi-sync: Found " . count($cachedRemoteSystems) . " remote systems to monitor");
                }
            }

            // Ping multi-sync hosts at the configured interval
            if (!empty($cachedRemoteSystems) && ($currentTime - $lastMultiSyncCheck) >= $multiSyncCheckInterval) {
                $pingResults = MultiSyncPingCollector::getInstance()->pingMultiSyncSystems($cachedRemoteSystems, $actualNetworkAdapter);
                $successCount = count(array_filter($pingResults, fn($r) => $r['success']));
                $lastMultiSyncCheck = $currentTime;
            }

            // Rotate multi-sync metrics file periodically
            $multiSyncRotationInterval = $config['metricsRotationInterval'] ?? 1800;
            if (($currentTime - $lastMultiSyncRotationCheck) >= $multiSyncRotationInterval) {
                MultiSyncPingCollector::getInstance()->rotateMetricsFile();
                $lastMultiSyncRotationCheck = $currentTime;
            }

            // Process multi-sync rollups every 60 seconds
            if (($currentTime - $lastMultiSyncRollupCheck) >= $rollupInterval) {
                MultiSyncPingCollector::getInstance()->processAllRollups();
                $lastMultiSyncRollupCheck = $currentTime;
            }

            // Collect network quality metrics (latency, jitter, packet loss from comparison API)
            if (($currentTime - $lastNetworkQualityCheck) >= $networkQualityCheckInterval) {
                NetworkQualityCollector::getInstance()->collectMetrics();
                $lastNetworkQualityCheck = $currentTime;
            }

            // Rotate network quality metrics file periodically
            $networkQualityRotationInterval = $config['metricsRotationInterval'] ?? 1800;
            if (($currentTime - $lastNetworkQualityRotationCheck) >= $networkQualityRotationInterval) {
                NetworkQualityCollector::getInstance()->rotateMetricsFile();
                $lastNetworkQualityRotationCheck = $currentTime;
            }

            // Process network quality rollups every 60 seconds
            if (($currentTime - $lastNetworkQualityRollupCheck) >= $rollupInterval) {
                NetworkQualityCollector::getInstance()->processAllRollups();
                $lastNetworkQualityRollupCheck = $currentTime;
            }
        }
    } else {
        $failureCount++;
        Logger::getInstance()->info("Connection FAILED (Failure count: $failureCount/{$config['maxFailures']})");

        if ($failureCount >= $config['maxFailures'] && !$hasResetAdapter) {
            Logger::getInstance()->info("Maximum failures reached. Resetting network adapter...");
            NetworkAdapter::getInstance()->resetAdapter($actualNetworkAdapter);
            $hasResetAdapter = true;
            NetworkAdapter::getInstance()->setResetState($actualNetworkAdapter, 'Max failures reached');
            $failureCount = 0;
            sleep(10);
        } elseif ($failureCount >= $config['maxFailures'] && $hasResetAdapter) {
            Logger::getInstance()->info("Network adapter has already been reset once. Exiting...");
            Logger::getInstance()->info("Script stopped. Manual intervention required.");
            exit(1);
        }
    }

    sleep($config['checkInterval']);
}
?>
