#!/usr/bin/php
<?php
/**
 * Test script to diagnose network interface detection
 * Run this on the FPP host: php test_interface_detection.php
 */

// Include the necessary libraries
include_once "/opt/fpp/www/common.php";
include_once __DIR__ . "/lib/watcherCommon.php";
include_once __DIR__ . "/lib/apiCall.php";

echo "=== Network Interface Detection Test ===\n\n";

// Fetch interfaces from API
echo "1. Fetching interfaces from FPP API...\n";
$interfaces = fetchWatcherNetworkInterfaces();

if (empty($interfaces)) {
    echo "ERROR: No interfaces returned from API!\n";
    exit(1);
}

echo "Raw API response:\n";
echo json_encode($interfaces, JSON_PRETTY_PRINT) . "\n\n";

// Check if data is wrapped
if (isset($interfaces['interfaces']) && is_array($interfaces['interfaces'])) {
    echo "Data is wrapped in 'interfaces' key\n";
    $interfaces = $interfaces['interfaces'];
} elseif (isset($interfaces['data']) && is_array($interfaces['data'])) {
    echo "Data is wrapped in 'data' key\n";
    $interfaces = $interfaces['data'];
} else {
    echo "Data is NOT wrapped (direct array)\n";
}

echo "\n2. Analyzing each interface:\n";
echo str_repeat("-", 80) . "\n";

$bestInterface = null;
$bestScore = -1;

foreach ($interfaces as $interface) {
    $ifname = $interface['ifname'] ?? 'UNKNOWN';
    echo "\nInterface: $ifname\n";

    // Check addr_info
    $addrInfo = $interface['addr_info'] ?? [];
    echo "  addr_info entries: " . count($addrInfo) . "\n";

    $ipv4Candidates = [];
    foreach ($addrInfo as $addr) {
        if (($addr['family'] ?? '') === 'inet' && !empty($addr['local'])) {
            $scope = $addr['scope'] ?? 'global';
            $ip = $addr['local'];

            // Skip link-local
            if (strpos($ip, '169.254.') === 0) {
                echo "  - Skipping link-local IP: $ip\n";
                continue;
            }

            $ipv4Candidates[] = ['ip' => $ip, 'scope' => $scope];
            echo "  - Found IPv4: $ip (scope: $scope)\n";
        }
    }

    if (empty($ipv4Candidates)) {
        echo "  SKIP: No usable IPv4 address\n";
        continue;
    }

    // Check operational state and flags
    $operState = strtoupper($interface['operstate'] ?? '');
    $flags = $interface['flags'] ?? [];
    if (!is_array($flags)) {
        $flags = [];
    }

    echo "  operstate: $operState\n";
    echo "  flags: " . implode(', ', $flags) . "\n";

    $isUp = ($operState === 'UP') || in_array('LOWER_UP', $flags, true) || in_array('RUNNING', $flags, true);
    $hasCarrier = !in_array('NO-CARRIER', $flags, true);

    echo "  isUp: " . ($isUp ? 'YES' : 'NO') . "\n";
    echo "  hasCarrier: " . ($hasCarrier ? 'YES' : 'NO') . "\n";

    // Calculate score
    $score = 3; // Base score for having IPv4
    if ($isUp) $score += 2;
    if ($hasCarrier) $score += 1;

    // Global scope bonus
    foreach ($ipv4Candidates as $candidate) {
        if ($candidate['scope'] === 'global') {
            $score += 1;
            echo "  Global scope bonus: +1\n";
            break;
        }
    }

    echo "  TOTAL SCORE: $score\n";

    if ($score > $bestScore) {
        $bestScore = $score;
        $bestInterface = $ifname;
        echo "  >>> NEW BEST INTERFACE <<<\n";
    }
}

echo "\n" . str_repeat("=", 80) . "\n";
echo "RESULT: Best interface is '$bestInterface' with score $bestScore\n";
echo str_repeat("=", 80) . "\n\n";

// Now test the actual function
echo "3. Testing actual detectActiveNetworkInterface() function:\n";
$detected = detectActiveNetworkInterface();
echo "Detected interface: $detected\n\n";

// Check logs
echo "4. Recent log entries from watcher log:\n";
$logFile = "/home/fpp/media/logs/fpp-plugin-watcher.log";
if (file_exists($logFile)) {
    echo "Last 20 lines of log:\n";
    echo str_repeat("-", 80) . "\n";
    $output = [];
    exec("tail -n 20 " . escapeshellarg($logFile), $output);
    foreach ($output as $line) {
        echo $line . "\n";
    }
} else {
    echo "Log file does not exist yet\n";
}

?>
