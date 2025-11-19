#!/usr/bin/php
<?php
include_once "/opt/fpp/www/common.php";
include_once __DIR__ ."/lib/watcherCommon.php";
include_once __DIR__ ."/lib/resetNetworkAdapter.php";
include_once __DIR__ ."/lib/config.php"; //Check for Config file and bootstrap if needed
include_once __DIR__ ."/lib/pingMetricsRollup.php"; //Rollup and rotation management

$config = WATCHERCONFIGFILE; // Load and prepare configuration

// Maximum file size in bytes (10MB default)
define("WATCHERMETRICSFILEMAXSIZE", 10 * 1024 * 1024);

/**
 * Rotate metrics log file if it exceeds the maximum size
 * Keeps the most recent entries (last 24 hours worth)
 * 
 * Log rotation is being handled by FPP when files hit 10MB.  this function has been
 * added to allow for more frequent rotation to keep file sizes down or in the event the data
 * is moved to a location not managed by FPP.
 */
function rotateMetricsFile() {
    $metricsFile = WATCHERPINGMETRICSFILE;

    if (!file_exists($metricsFile)) {
        return;
    }

    $fileSize = filesize($metricsFile);

    // Check if rotation is needed
    if ($fileSize <= WATCHERMETRICSFILEMAXSIZE) {
        return;
    }

    logMessage("Metrics file size ($fileSize bytes) exceeds limit (" . WATCHERMETRICSFILEMAXSIZE . " bytes). Rotating...");

    // Read current metrics and keep only last 24 hours
    $twentyFourHoursAgo = time() - (24 * 60 * 60);
    $recentMetrics = [];

    $file = fopen($metricsFile, 'r');
    if ($file) {
        while (($line = fgets($file)) !== false) {
            // Extract JSON from log entry format: [timestamp] JSON
            if (preg_match('/\[.*?\]\s+(.+)$/', $line, $matches)) {
                $jsonData = trim($matches[1]);
                $entry = json_decode($jsonData, true);

                if ($entry && isset($entry['timestamp'])) {
                    // Keep only entries from last 24 hours
                    if ($entry['timestamp'] >= $twentyFourHoursAgo) {
                        $recentMetrics[] = $line;
                    }
                }
            }
        }
        fclose($file);
    }

    // Backup old file
    $backupFile = $metricsFile . '.old';
    if (file_exists($backupFile)) {
        unlink($backupFile);
    }
    rename($metricsFile, $backupFile);

    // Write recent metrics back to the file
    if (!empty($recentMetrics)) {
        file_put_contents($metricsFile, implode('', $recentMetrics));
        logMessage("Metrics file rotated. Kept " . count($recentMetrics) . " recent entries.");
    } else {
        // Create empty file
        touch($metricsFile);
        logMessage("Metrics file rotated. No recent entries to keep.");
    }
}

// Function to check internet connectivity and capture ping statistics
function checkConnectivity($testHosts, $networkAdapter) {
    global $lastPingStats;

    $lastPingStats = [
        'host' => null,
        'latency' => null,
    ];

    $anySuccess = false;

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
                'timestamp' => time(),
                'host' => $host,
                'latency' => $latency,
                'status' => 'success'
            ]);
            logMessage($metricsEntry, WATCHERPINGMETRICSFILE);

            $anySuccess = true;
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


if (!$config['enabled']) {
    logMessage("Watcher Plugin is disabled. Exiting.");    exit(0);
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
logMessage("Network Adapter: {$config['networkAdapter']}");
logMessage("Test Hosts: " . implode(', ', $config['testHosts']));

while (true) {
    if (checkConnectivity($config['testHosts'], $config['networkAdapter'])) {
        if ($failureCount > 0) {
            logMessage("Internet connectivity restored");
        }
        $failureCount = 0;

        // Check and rotate metrics file if needed, but only every configured interval
        $currentTime = time();
        $rotationInterval = isset($config['metricsRotationInterval']) ? $config['metricsRotationInterval'] : 1800;

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
            resetNetworkAdapter($config['networkAdapter']);
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