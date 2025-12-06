#!/usr/bin/php
<?php
include_once "/opt/fpp/www/common.php";
// Core
include_once __DIR__ ."/lib/core/watcherCommon.php";
include_once __DIR__ ."/lib/core/config.php";

// Metrics
include_once __DIR__ ."/lib/metrics/pingMetrics.php";
include_once __DIR__ ."/lib/metrics/multiSyncPingMetrics.php";
include_once __DIR__ ."/lib/metrics/networkQualityMetrics.php";

// Controllers
include_once __DIR__ ."/lib/controllers/networkAdapter.php";

$config = readPluginConfig(); // Load and prepare configuration

// Resolve 'default' network adapter to actual interface and save it
if ($config['networkAdapter'] === 'default') {
    $actualNetworkAdapter = detectActiveNetworkInterface();
    // Save the detected interface to config so it's persistent
    /** @disregard P1010 */
    WriteSettingToFile('networkAdapter', $actualNetworkAdapter, WATCHERPLUGINNAME);
    logMessage("Auto-detected network adapter '$actualNetworkAdapter' from 'default' setting and saved to config");
    $networkAdapterDisplay = "default (detected: $actualNetworkAdapter)";
} else {
    $actualNetworkAdapter = $config['networkAdapter'];
    $networkAdapterDisplay = $actualNetworkAdapter;
}

// Retention period for raw metrics (25 hours)
// Keeps 24 hours of data for graphing plus 1 hour buffer
define("WATCHERMETRICSRETENTIONSECONDS", 25 * 60 * 60);

/**
 * Purge metrics log entries older than retention period
 * Delegates to shared function in rollupBase.php
 */
function rotateMetricsFile() {
    rotateRawMetricsFileGeneric(WATCHERPINGMETRICSFILE, WATCHERMETRICSRETENTIONSECONDS);
}

/**
 * Write multiple metrics entries in a single file operation
 * Delegates to shared function in rollupBase.php
 */
function writeMetricsBatch($entries) {
    return writeMetricsBatchGeneric(WATCHERPINGMETRICSFILE, $entries);
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
        $output = [];
        $returnVar = 0;
        exec("ping -I " . escapeshellarg($networkAdapter) . " -c 1 -W 1 " . escapeshellarg($host) . " 2>&1", $output, $returnVar);
        if ($returnVar === 0) {
            // Successfully pinged, extract statistics
            $lastPingStats['host'] = $host;
            $latency = null;

            // Extract time from output (typically "time=XX.XX ms")
            foreach ($output as $line) {
                // Look for latency in format: time=X.XXX ms
                if (preg_match('/time=([0-9.]+)\s*ms/', $line, $matches)) {
                    $latency = floatval($matches[1]);
                    $lastPingStats['latency'] = $latency;
                    break;
                }
            }

            // Buffer metrics for batch write
            $metricsBuffer[] = [
                'timestamp' => $checkTimestamp,
                'host' => $host,
                'latency' => $latency,
                'status' => 'success'
            ];

            $anySuccess = true;
        } else {
            // Buffer failed ping attempt
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
    logMessage("Watcher Plugin connectivity check is disabled. Exiting.");    exit(0);
}

// Main monitoring loop
$failureCount = 0;
$lastPingStats = [];

// Check for existing reset state from previous runs
$resetState = readResetState();
$hasResetAdapter = ($resetState !== null && !empty($resetState['hasResetAdapter']));
if ($hasResetAdapter) {
    $resetTime = date('Y-m-d H:i:s', $resetState['resetTimestamp'] ?? 0);
    logMessage("Previous adapter reset detected from $resetTime - will exit if max failures reached again");
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

logMessage("=== Watcher Plugin Started ===");
logMessage("Check Interval: {$config['checkInterval']} seconds");
logMessage("Max Failures: {$config['maxFailures']}");
logMessage("Network Adapter: $networkAdapterDisplay");
logMessage("Test Hosts: " . implode(', ', $config['testHosts']));
logMessage("Multi-Sync Ping Monitoring: " . ($config['multiSyncPingEnabled'] ? 'Enabled' : 'Disabled'));

while (true) {
    $currentTime = time(); // Single timestamp for this iteration

    if (checkConnectivity($config['testHosts'], $actualNetworkAdapter)) {
        if ($failureCount > 0) {
            logMessage("Internet connectivity restored");
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
            processAllRollups();
            $lastRollupCheck = $currentTime;
        }

        // Multi-sync host pinging (only if enabled and in player mode)
        if ($config['multiSyncPingEnabled'] && isPlayerMode()) {
            // Refresh remote systems list periodically
            if ($cachedRemoteSystems === null || ($currentTime - $lastRemoteSystemsFetch) >= $remoteSystemsCacheInterval) {
                $cachedRemoteSystems = getMultiSyncRemoteSystems();
                $lastRemoteSystemsFetch = $currentTime;
                if (!empty($cachedRemoteSystems)) {
                    logMessage("Multi-sync: Found " . count($cachedRemoteSystems) . " remote systems to monitor");
                }
            }

            // Ping multi-sync hosts at the configured interval
            if (!empty($cachedRemoteSystems) && ($currentTime - $lastMultiSyncCheck) >= $multiSyncCheckInterval) {
                $pingResults = pingMultiSyncSystems($cachedRemoteSystems, $actualNetworkAdapter);
                $successCount = count(array_filter($pingResults, fn($r) => $r['success']));
                $lastMultiSyncCheck = $currentTime;
            }

            // Rotate multi-sync metrics file periodically
            $multiSyncRotationInterval = $config['metricsRotationInterval'] ?? 1800;
            if (($currentTime - $lastMultiSyncRotationCheck) >= $multiSyncRotationInterval) {
                rotateMultiSyncMetricsFile();
                $lastMultiSyncRotationCheck = $currentTime;
            }

            // Process multi-sync rollups every 60 seconds
            if (($currentTime - $lastMultiSyncRollupCheck) >= $rollupInterval) {
                processAllMultiSyncRollups();
                $lastMultiSyncRollupCheck = $currentTime;
            }

            // Collect network quality metrics (latency, jitter, packet loss from comparison API)
            if (($currentTime - $lastNetworkQualityCheck) >= $networkQualityCheckInterval) {
                collectNetworkQualityMetrics();
                $lastNetworkQualityCheck = $currentTime;
            }

            // Rotate network quality metrics file periodically
            $networkQualityRotationInterval = $config['metricsRotationInterval'] ?? 1800;
            if (($currentTime - $lastNetworkQualityRotationCheck) >= $networkQualityRotationInterval) {
                rotateNetworkQualityMetricsFile();
                $lastNetworkQualityRotationCheck = $currentTime;
            }

            // Process network quality rollups every 60 seconds
            if (($currentTime - $lastNetworkQualityRollupCheck) >= $rollupInterval) {
                processAllNetworkQualityRollups();
                $lastNetworkQualityRollupCheck = $currentTime;
            }
        }
    } else {
        $failureCount++;
        logMessage("Connection FAILED (Failure count: $failureCount/{$config['maxFailures']})");

        if ($failureCount >= $config['maxFailures'] && !$hasResetAdapter) {
            logMessage("Maximum failures reached. Resetting network adapter...");
            resetNetworkAdapter($actualNetworkAdapter);
            $hasResetAdapter = true;
            writeResetState($actualNetworkAdapter, 'Max failures reached');
            $failureCount = 0;
            sleep(10);
        } elseif ($failureCount >= $config['maxFailures'] && $hasResetAdapter) {
            logMessage("Network adapter has already been reset once. Exiting...");
            logMessage("Script stopped. Manual intervention required.");
            exit(1);
        }
    }

    sleep($config['checkInterval']);
}
?>
