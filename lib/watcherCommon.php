<?php
include_once "/opt/fpp/www/common.php";
include_once __DIR__ . "/apiCall.php";

global $settings;

$_watcherPluginInfo = @json_decode(file_get_contents(__DIR__ . '/../pluginInfo.json'), true); // Parse version from pluginInfo.json
define("WATCHERPLUGINNAME", 'fpp-plugin-watcher');
define("WATCHERVERSION", 'v' . ($_watcherPluginInfo['version'] ?? '0.0.0'));
define("WATCHERPLUGINDIR", $settings['pluginDirectory']."/".WATCHERPLUGINNAME."/");
define("WATCHERCONFIGFILELOCATION", $settings['configDirectory']."/plugin.".WATCHERPLUGINNAME);
define("WATCHERLOGFILE", $settings['logDirectory']."/".WATCHERPLUGINNAME.".log");
define("WATCHERPINGMETRICSFILE", $settings['logDirectory']."/".WATCHERPLUGINNAME."-ping-metrics.log");
define("WATCHERMULTISYNCPINGMETRICSFILE", $settings['logDirectory']."/".WATCHERPLUGINNAME."-multisync-ping-metrics.log");
define("WATCHERFPPUSER", 'fpp');
define("WATCHERFPPGROUP", 'fpp');
define("WATCHERDEFAULTSETTINGS",
    array(
        'connectivityCheckEnabled' => false,
        'checkInterval' => 20,
        'maxFailures' => 3,
        'networkAdapter' => 'default',
        'testHosts' => '8.8.8.8,1.1.1.1',
        'metricsRotationInterval' => 1800,
        'collectdEnabled' => true,
        'multiSyncMetricsEnabled' => false,
        'multiSyncPingEnabled' => false,
        'multiSyncPingInterval' => 60,
        'falconMonitorEnabled' => false)
        );

// Ensure plugin-created files are owned by the FPP user/group for web access
function ensureFppOwnership($path) {
    if (!$path || !file_exists($path)) {
        return;
    }

    @chown($path, WATCHERFPPUSER);
    @chgrp($path, WATCHERFPPGROUP);
}

// Track files whose ownership has been verified this session
$_watcherOwnershipVerified = [];

// Function to log messages
function logMessage($message, $file = WATCHERLOGFILE) {
    global $_watcherOwnershipVerified;

    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message\n";
    $fileExisted = file_exists($file);

    // Serialize writes to avoid interleaving across processes
    $fp = @fopen($file, 'a');
    if ($fp) {
        if (flock($fp, LOCK_EX)) {
            fwrite($fp, $logEntry);
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }

    // Only set ownership once per file per session (when file is new or not yet verified)
    if (!$fileExisted || !isset($_watcherOwnershipVerified[$file])) {
        ensureFppOwnership($file);
        $_watcherOwnershipVerified[$file] = true;
    }
}

// Function to fetch network interfaces from FPP API
function fetchWatcherNetworkInterfaces() {
    $result = apiCall('GET', 'http://127.0.0.1/api/network/interface', [], true);

    if ($result === false || !is_array($result)) {
        logMessage("Failed to retrieve network interfaces from FPP API");
        return [];
    }

    return $result;
}

// Function to detect active network interface
function detectActiveNetworkInterface() {
    $interfaces = fetchWatcherNetworkInterfaces();

    // If API call failed or no interfaces returned
    if (empty($interfaces) || !is_array($interfaces)) {
        logMessage("Network interface detection: API call failed or returned invalid data, using fallback");
        return 'eth0';
    }

    // Some FPP builds wrap interfaces under 'data' or 'interfaces'
    if (isset($interfaces['interfaces']) && is_array($interfaces['interfaces'])) {
        $interfaces = $interfaces['interfaces'];
    } elseif (isset($interfaces['data']) && is_array($interfaces['data'])) {
        $interfaces = $interfaces['data'];
    }

    $bestInterface = null;
    $bestScore = -1;

    // Choose the interface with a usable IPv4 from addr_info, preferring one that is UP and has carrier
    foreach ($interfaces as $interface) {
        $ifname = $interface['ifname'] ?? null;
        if (!$ifname) {
            continue;
        }

        $addrInfo = $interface['addr_info'] ?? [];
        if (!is_array($addrInfo)) {
            $addrInfo = [];
        }

        $ipv4Candidates = [];
        foreach ($addrInfo as $addr) {
            if (($addr['family'] ?? '') === 'inet' && !empty($addr['local'])) {
                // Require IPv4; optionally ensure it's global (default scope when omitted)
                $scope = $addr['scope'] ?? 'global';
                $ip = $addr['local'];

                // Skip link-local IPv4 addresses (169.254.x.x)
                if (strpos($ip, '169.254.') === 0) {
                    continue;
                }
                $ipv4Candidates[] = ['ip' => $ip, 'scope' => $scope];
            }
        }

        if (empty($ipv4Candidates)) {
            logMessage("Network interface detection: Skipping '$ifname' (no usable IPv4 in addr_info)");
            continue; // Skip interfaces without a usable IPv4 address
        }

        $operState = strtoupper($interface['operstate'] ?? '');
        $flags = $interface['flags'] ?? [];
        if (!is_array($flags)) {
            $flags = [];
        }
        $isUp = ($operState === 'UP') || in_array('LOWER_UP', $flags ?? [], true) || in_array('RUNNING', $flags ?? [], true);
        $hasCarrier = !in_array('NO-CARRIER', $flags ?? [], true);

        // Score: usable IPv4 (3), global scope bonus (1), UP (2), has carrier (1)
        $score = 3 + ($isUp ? 2 : 0) + ($hasCarrier ? 1 : 0);

        // If any address is truly global, add a bonus to prefer it over config-only entries
        foreach ($ipv4Candidates as $candidate) {
            if ($candidate['scope'] === 'global') {
                $score += 1;
                break;
            }
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $bestInterface = $ifname;
        }

        $candidateIps = array_map(function($c) {
            return $c['ip'] . ($c['scope'] !== 'global' ? " ({$c['scope']})" : '');
        }, $ipv4Candidates);
        $stateSummary = "state={$operState}, flags=" . implode(',', $flags ?? []);
        logMessage("Network interface detection: Candidate '$ifname' with IP(s): " . implode(', ', $candidateIps) . " | $stateSummary | score=$score");
    }

    if ($bestInterface) {
        logMessage("Network interface detection: Selected interface '$bestInterface' (score $bestScore)");
        return $bestInterface;
    }

    // No interface found with IPv4, fallback to eth0
    logMessage("Network interface detection: No interface with IPv4 found, using fallback 'eth0'");
    return 'eth0';
}

// Try to find a reachable gateway for a specific interface
function detectGatewayForInterface($interface) {
    if (empty($interface)) {
        return null;
    }

    $routesOutput = [];
    $gateway = null;

    // Prefer the gateway bound to the detected interface
    exec("ip -4 route show default dev " . escapeshellarg($interface) . " 2>/dev/null", $routesOutput);

    // Fall back to any default route if none found for the interface
    if (empty($routesOutput)) {
        exec("ip -4 route show default 2>/dev/null", $routesOutput);
    }

    foreach ($routesOutput as $line) {
        if (preg_match('/default via ([0-9.]+)/', $line, $matches)) {
            $gateway = $matches[1];
            break;
        }
    }

    if (!$gateway) {
        logMessage("Gateway detection: No default route found for interface '$interface'");
        return null;
    }

    // Confirm the gateway is reachable before suggesting it
    $pingOutput = [];
    $returnVar = 0;
    exec("ping -I " . escapeshellarg($interface) . " -c 1 -W 1 " . escapeshellarg($gateway) . " 2>&1", $pingOutput, $returnVar);

    if ($returnVar !== 0) {
        logMessage("Gateway detection: Found gateway '$gateway' for interface '$interface' but ping failed");
        return null;
    }

    logMessage("Gateway detection: Found reachable gateway '$gateway' for interface '$interface'");
    return $gateway;
}

// Check if this FPP instance is running in player mode
function isPlayerMode() {
    $result = apiCall('GET', 'http://127.0.0.1/api/fppd/status', [], true, 5);

    if ($result === false || !isset($result['mode_name'])) {
        logMessage("isPlayerMode: Failed to determine mode from FPP status");
        return false;
    }

    return $result['mode_name'] === 'player';
}

/**
 * Fetch remote systems from multi-sync configuration
 * Returns an array of remote systems with hostname, address, model, version
 * Filters out local systems and deduplicates by hostname
 */
function getMultiSyncRemoteSystems() {
    $multiSyncData = apiCall('GET', 'http://127.0.0.1/api/fppd/multiSyncSystems', [], true, 5);

    if (!$multiSyncData || !isset($multiSyncData['systems']) || !is_array($multiSyncData['systems'])) {
        return [];
    }

    $systemsByHostname = [];

    foreach ($multiSyncData['systems'] as $system) {
        // Skip local systems
        if (!empty($system['local'])) {
            continue;
        }

        $hostname = $system['hostname'] ?? '';
        if (empty($hostname)) {
            continue;
        }

        // Dedupe by hostname, preferring entries with UUID
        if (!isset($systemsByHostname[$hostname])) {
            $systemsByHostname[$hostname] = $system;
        } elseif (!empty($system['uuid']) && empty($systemsByHostname[$hostname]['uuid'])) {
            // Replace with this one if it has UUID and current doesn't
            $systemsByHostname[$hostname] = $system;
        }
    }

    $remoteSystems = array_values($systemsByHostname);

    // Sort by IP address numerically
    usort($remoteSystems, function($a, $b) {
        return ip2long($a['address'] ?? '0.0.0.0') - ip2long($b['address'] ?? '0.0.0.0');
    });

    return $remoteSystems;
}
?>
