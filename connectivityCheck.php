#!/usr/bin/php
<?php
include_once "/opt/fpp/www/common.php";
include_once __DIR__ ."/lib/watcherCommon.php";
include_once __DIR__ ."/lib/resetNetworkAdapter.php";
include_once __DIR__ ."/lib/config.php"; //Check for Config file and bootstrap if needed
include_once __DIR__ ."/lib/pingMetricsRollup.php"; //Rollup and rotation management

$config = readPluginConfig(); // Load and prepare configuration

// Resolve 'default' network adapter to actual interface and save it
if ($config['networkAdapter'] === 'default') {
    $actualNetworkAdapter = detectActiveNetworkInterface();
    // Save the detected interface to config so it's persistent
    /** @disregard P1010 */
    WriteSettingToFile('networkAdapter', $actualNetworkAdapter, WATCHERPLUGINNAME);
    logMessage("Auto-detected network adapter '$actualNetworkAdapter' from 'default' setting and saved to config");
} else {
    $actualNetworkAdapter = $config['networkAdapter'];
}

// Retention period for raw metrics (25 hours)
define("WATCHERMETRICSRETENTIONSECONDS", 25 * 60 * 60);

/**
 * Purge metrics log entries older than retention period (25 hours)
 *
 * Called periodically to keep the metrics file from growing unbounded.
 * FPP also handles log rotation at 10MB, but this provides more granular control.
 */
function rotateMetricsFile() {
    $metricsFile = WATCHERPINGMETRICSFILE;

    if (!file_exists($metricsFile)) {
        return;
    }

    $fp = fopen($metricsFile, 'c+');
    if (!$fp) {
        return;
    }

    // Take an exclusive lock so writes pause during rotation
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return;
    }

    // Read current metrics and keep only entries within retention period
    $cutoffTime = time() - WATCHERMETRICSRETENTIONSECONDS;
    $recentMetrics = [];
    $purgedCount = 0;

    rewind($fp);
    while (($line = fgets($fp)) !== false) {
        // Extract JSON from log entry format: [timestamp] JSON
        if (preg_match('/\[.*?\]\s+(.+)$/', $line, $matches)) {
            $jsonData = trim($matches[1]);
            $entry = json_decode($jsonData, true);

            if ($entry && isset($entry['timestamp'])) {
                if ($entry['timestamp'] >= $cutoffTime) {
                    $recentMetrics[] = $line;
                } else {
                    $purgedCount++;
                }
            }
        }
    }

    // Only rewrite file if we actually purged entries
    if ($purgedCount === 0) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return;
    }

    // Write recent metrics to new file, rename old file to backup atomically
    $backupFile = $metricsFile . '.old';
    $tempFile = $metricsFile . '.tmp';

    // Write recent entries to temp file
    $tempFp = fopen($tempFile, 'w');
    if ($tempFp) {
        if (!empty($recentMetrics)) {
            fwrite($tempFp, implode('', $recentMetrics));
        }
        fclose($tempFp);

        // Atomic swap: old -> backup, temp -> current
        @unlink($backupFile);
        rename($metricsFile, $backupFile);
        rename($tempFile, $metricsFile);

        logMessage("Metrics purge: removed {$purgedCount} old entries, kept " . count($recentMetrics) . " recent entries.");

        ensureFppOwnership($metricsFile);
        ensureFppOwnership($backupFile);
    } else {
        logMessage("ERROR: Unable to create temp file for metrics purge");
    }

    flock($fp, LOCK_UN);
    fclose($fp);
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

            // Log ping metrics to separate file in JSON format for easy parsing
            $metricsEntry = json_encode([
                'timestamp' => $checkTimestamp,
                'host' => $host,
                'latency' => $latency,
                'status' => 'success'
            ]);
            logMessage($metricsEntry, WATCHERPINGMETRICSFILE);

            $anySuccess = true;
        } else {
            // Log failed ping attempt
            $metricsEntry = json_encode([
                'timestamp' => $checkTimestamp,
                'host' => $host,
                'latency' => null,
                'status' => 'failure'
            ]);
            logMessage($metricsEntry, WATCHERPINGMETRICSFILE);
        }
    }
    return $anySuccess;
}

// Function to format and log ping statistics
function formatPingStats($stats) {
    $message = "Host: {$stats['host']}";
    
    if ($stats['latency'] !== null) {
        $message .= ", Latency: {$stats['latency']}ms";
    }

    return $message;
}


if (!$config['connectivityCheckEnabled']) {
    logMessage("Watcher Plugin connectivity check is disabled. Exiting.");    exit(0);
}

// Main monitoring loop
$failureCount = 0;
$hasResetAdapter = false;
$lastPingStats = [];
$lastRotationCheck = 0; // Track when rotation was last checked
$lastRollupCheck = 0; // Track when rollup was last processed

logMessage("=== Watcher Plugin Started ===");
logMessage("Check Interval: {$config['checkInterval']} seconds");
logMessage("Max Failures: {$config['maxFailures']}");
logMessage("Network Adapter: {$config['networkAdapter']}" . ($config['networkAdapter'] === 'default' ? " (detected: $actualNetworkAdapter)" : ""));
logMessage("Test Hosts: " . implode(', ', $config['testHosts']));

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
    } else {
        $failureCount++;
        logMessage("Connection FAILED (Failure count: $failureCount/{$config['maxFailures']})");

        if ($failureCount >= $config['maxFailures'] && !$hasResetAdapter) {
            logMessage("Maximum failures reached. Resetting network adapter...");
            resetNetworkAdapter($actualNetworkAdapter);
            $hasResetAdapter = true;
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
