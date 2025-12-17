<?php
include_once __DIR__ . "/fppSettings.php";

// API timeout constants (in seconds) - defined before apiCall.php include
define("WATCHER_TIMEOUT_STATUS", 2);    // Quick status checks (fppd/status, playback sync)
define("WATCHER_TIMEOUT_STANDARD", 5);  // Standard API requests (info, version, plugins)
define("WATCHER_TIMEOUT_LONG", 10);     // Longer operations (metrics/all, state changes)

include_once __DIR__ . "/apiCall.php";

global $settings;

$_watcherPluginInfo = @json_decode(file_get_contents(__DIR__ . '/../../pluginInfo.json'), true); // Parse version from pluginInfo.json
define("WATCHERPLUGINNAME", 'fpp-plugin-watcher');
define("WATCHERVERSION", 'v' . ($_watcherPluginInfo['version'] ?? '0.0.0'));
define("WATCHERPLUGINDIR", $settings['pluginDirectory']."/".WATCHERPLUGINNAME."/");
define("WATCHERCONFIGFILELOCATION", $settings['configDirectory']."/plugin.".WATCHERPLUGINNAME);
define("WATCHERLOGDIR", $settings['logDirectory']);
define("WATCHERLOGFILE", WATCHERLOGDIR."/".WATCHERPLUGINNAME.".log");
define("WATCHERFPPUSER", 'fpp');
define("WATCHERFPPGROUP", 'fpp');

// Data directory for metrics storage (plugin-data location)
define("WATCHERDATADIR", $settings['mediaDirectory']."/plugin-data/".WATCHERPLUGINNAME);
define("WATCHERPINGDIR", WATCHERDATADIR."/ping");
define("WATCHERMULTISYNCPINGDIR", WATCHERDATADIR."/multisync-ping");
define("WATCHERNETWORKQUALITYDIR", WATCHERDATADIR."/network-quality");
define("WATCHERMQTTDIR", WATCHERDATADIR."/mqtt");
define("WATCHERCONNECTIVITYDIR", WATCHERDATADIR."/connectivity");
define("WATCHEREFUSEDIR", WATCHERDATADIR."/efuse");
define("WATCHERCOLLECTDRRDDIR", WATCHERDATADIR."/collectd/rrd");

// Data file paths (now in plugin-data subdirectories)
define("WATCHERPINGMETRICSFILE", WATCHERPINGDIR."/raw.log");
define("WATCHERMULTISYNCPINGMETRICSFILE", WATCHERMULTISYNCPINGDIR."/raw.log");
define("WATCHERRESETSTATEFILE", WATCHERCONNECTIVITYDIR."/reset-state.json");
define("WATCHERMQTTEVENTSFILE", WATCHERMQTTDIR."/events.log");
define("WATCHERMIGRATIONMARKER", WATCHERDATADIR."/.migration-complete");

// eFuse metrics file paths
define("WATCHEREFUSERAWFILE", WATCHEREFUSEDIR."/raw.log");
define("WATCHEREFUSEROLLUPFILE", WATCHEREFUSEDIR."/1min.log");
define("WATCHEREFUSEROLLUPSTATEFILE", WATCHEREFUSEDIR."/rollup-state.json");
define("WATCHEREFUSECONFIGFILE", WATCHEREFUSEDIR."/config.json");

// Default plugin settings
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
        'falconMonitorEnabled' => false,
        'controlUIEnabled' => true,
        'mqttMonitorEnabled' => false,
        'mqttRetentionDays' => 60,
        'issueCheckOutputs' => true,
        'issueCheckSequences' => true,
        'efuseMonitorEnabled' => false,
        'efuseCollectionInterval' => 5,   // seconds (1-60)
        'efuseRetentionDays' => 7)        // days (1-90)
        );

// Settings that require FPP restart when changed
// Most connectivity settings now support hot-reload (checked every 60 seconds by daemon)
// Only collectd and MQTT require restart as they are separate services
define("WATCHERSETTINGSRESTARTREQUIRED",
    array(
        'connectivityCheckEnabled' => false,  // Hot-reloadable (daemon exits gracefully if disabled)
        'checkInterval' => false,             // Hot-reloadable
        'maxFailures' => false,               // Hot-reloadable
        'networkAdapter' => false,            // Hot-reloadable
        'testHosts' => false,                 // Hot-reloadable
        'collectdEnabled' => true,            // Service managed in postStart.sh
        'multiSyncMetricsEnabled' => false,   // UI visibility only
        'multiSyncPingEnabled' => false,      // Hot-reloadable
        'multiSyncPingInterval' => false,     // Hot-reloadable
        'falconMonitorEnabled' => false,      // UI visibility only
        'controlUIEnabled' => false,          // UI visibility only
        'mqttMonitorEnabled' => true,         // MQTT subscriber started/stopped
        'mqttRetentionDays' => false,         // Cleanup schedule, no restart needed
        'issueCheckOutputs' => false,         // UI feature only
        'issueCheckSequences' => false,       // UI feature only
        'efuseMonitorEnabled' => true,        // Daemon started/stopped in postStart.sh
        'efuseCollectionInterval' => false,   // Hot-reloadable
        'efuseRetentionDays' => false         // Hot-reloadable
    ));

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
    $pingResult = pingHost($gateway, $interface, 1);

    if (!$pingResult['success']) {
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

// Check if this FPP instance is running in remote mode with multi-sync active
function isRemoteModeWithMultiSync() {
    $result = apiCall('GET', 'http://127.0.0.1/api/fppd/status', [], true, 5);

    if ($result === false || !isset($result['mode_name'])) {
        return false;
    }

    // Must be in remote mode and have multi-sync enabled
    return $result['mode_name'] === 'remote';
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

        // Only include player and remote mode systems (skip bridge, etc.)
        $mode = $system['fppModeString'] ?? '';
        if ($mode !== 'player' && $mode !== 'remote') {
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

/**
 * Validate a host string (IP address or hostname)
 *
 * @param string $host The host to validate
 * @return bool True if valid, false otherwise
 */
function validateHost($host) {
    if (empty($host)) {
        return false;
    }
    // Valid if it's an IP address
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return true;
    }
    // Valid if it matches hostname pattern (alphanumeric, dash, dot)
    if (preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*$/', $host)) {
        return true;
    }
    return false;
}

/**
 * Ping a host and return result with latency
 *
 * @param string $address IP address or hostname to ping
 * @param string|null $interface Network interface to use (null for system default)
 * @param int $timeout Timeout in seconds (default: 1)
 * @return array ['success' => bool, 'latency' => float|null, 'output' => array]
 */
function pingHost($address, $interface = null, $timeout = 1) {
    $output = [];
    $returnVar = 0;

    $cmd = 'ping';
    if ($interface !== null && !empty($interface)) {
        $cmd .= ' -I ' . escapeshellarg($interface);
    }
    $cmd .= ' -c 1 -W ' . intval($timeout) . ' ' . escapeshellarg($address) . ' 2>&1';

    exec($cmd, $output, $returnVar);

    $result = [
        'success' => ($returnVar === 0),
        'latency' => null,
        'output' => $output
    ];

    if ($returnVar === 0) {
        foreach ($output as $line) {
            if (preg_match('/time=([0-9.]+)\s*ms/', $line, $matches)) {
                $result['latency'] = floatval($matches[1]);
                break;
            }
        }
    }

    return $result;
}

/**
 * Sort an array by timestamp field (ascending)
 *
 * @param array &$array Array to sort in place
 * @param string $field Field name containing timestamp (default: 'timestamp')
 */
function sortByTimestamp(&$array, $field = 'timestamp') {
    usort($array, function($a, $b) use ($field) {
        return ($a[$field] ?? 0) - ($b[$field] ?? 0);
    });
}

/**
 * Read a JSON-lines log file with optional filtering
 * Uses file locking for safe concurrent access.
 * When sinceTimestamp is provided, uses regex pre-filtering to skip old entries
 * without expensive JSON parsing (performance optimization).
 *
 * @param string $file Path to the file
 * @param int $sinceTimestamp Only return entries newer than this (default: 0)
 * @param callable|null $filterFn Optional filter function(entry) => bool
 * @param bool $sort Whether to sort results by timestamp (default: true)
 * @param string $timestampField Field name containing timestamp (default: 'timestamp')
 * @return array Array of parsed entries
 */
function readJsonLinesFile($file, $sinceTimestamp = 0, $filterFn = null, $sort = true, $timestampField = 'timestamp') {
    if (!file_exists($file)) {
        return [];
    }

    $entries = [];
    $fp = fopen($file, 'r');

    if (!$fp) {
        return [];
    }

    // Build regex pattern for timestamp extraction (avoids json_decode on old entries)
    $tsPattern = '/"' . preg_quote($timestampField, '/') . '"\s*:\s*(\d+)/';

    if (flock($fp, LOCK_SH)) {
        while (($line = fgets($fp)) !== false) {
            // When filtering by timestamp, extract it via regex first to skip old entries
            // without the overhead of json_decode
            if ($sinceTimestamp > 0) {
                if (!preg_match($tsPattern, $line, $tsMatch)) {
                    continue;
                }
                $entryTimestamp = (int)$tsMatch[1];
                if ($entryTimestamp <= $sinceTimestamp) {
                    continue;
                }
            }

            // Parse log format: [datetime] {json}
            if (preg_match('/\[.*?\]\s+(.+)$/', $line, $matches)) {
                $jsonData = trim($matches[1]);
                $entry = json_decode($jsonData, true);

                if ($entry && isset($entry[$timestampField])) {
                    // Apply custom filter if provided
                    if ($filterFn !== null && !$filterFn($entry)) {
                        continue;
                    }
                    $entries[] = $entry;
                }
            }
        }
        flock($fp, LOCK_UN);
    }

    fclose($fp);

    if ($sort && !empty($entries)) {
        sortByTimestamp($entries, $timestampField);
    }

    return $entries;
}

/**
 * Make a simple HTTP GET request and return JSON-decoded response
 * Lightweight wrapper for common fetch pattern.
 *
 * @param string $url URL to fetch
 * @param int $timeout Timeout in seconds (default: 10)
 * @param array $headers Optional headers
 * @return array|null Decoded JSON or null on failure
 */
function fetchJsonUrl($url, $timeout = 10, $headers = []) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'FPP-Plugin-Watcher/' . WATCHERVERSION);

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        return null;
    }

    return json_decode($response, true);
}

/**
 * Read the network adapter reset state
 * Returns state array or null if no state file exists
 */
function readResetState() {
    if (!file_exists(WATCHERRESETSTATEFILE)) {
        return null;
    }

    $content = @file_get_contents(WATCHERRESETSTATEFILE);
    if ($content === false) {
        return null;
    }

    $state = json_decode($content, true);
    return is_array($state) ? $state : null;
}

/**
 * Write the network adapter reset state
 */
function writeResetState($adapter, $reason = 'Max failures reached') {
    $state = [
        'hasResetAdapter' => true,
        'resetTimestamp' => time(),
        'adapter' => $adapter,
        'reason' => $reason
    ];

    $result = @file_put_contents(WATCHERRESETSTATEFILE, json_encode($state, JSON_PRETTY_PRINT));
    if ($result !== false) {
        ensureFppOwnership(WATCHERRESETSTATEFILE);
    }

    return $result !== false;
}

/**
 * Clear the network adapter reset state
 */
function clearResetState() {
    if (file_exists(WATCHERRESETSTATEFILE)) {
        return @unlink(WATCHERRESETSTATEFILE);
    }
    return true;
}

/**
 * Restart the connectivity check daemon
 * Kills existing process and starts a new one
 */
function restartConnectivityDaemon() {
    // Kill existing connectivity check process
    exec("pkill -f 'connectivityCheck.php'", $output, $returnVar);

    // Small delay to ensure process is terminated
    usleep(500000); // 0.5 seconds

    // Start new daemon in background
    $cmd = '/usr/bin/php ' . WATCHERPLUGINDIR . 'connectivityCheck.php > /dev/null 2>&1 &';
    exec($cmd, $output, $returnVar);

    logMessage("Connectivity daemon restarted");

    return true;
}

/**
 * Ensure all data directories exist with proper ownership
 */
function ensureDataDirectories() {
    $dirs = [
        WATCHERDATADIR,
        WATCHERPINGDIR,
        WATCHERMULTISYNCPINGDIR,
        WATCHERNETWORKQUALITYDIR,
        WATCHERMQTTDIR,
        WATCHERCONNECTIVITYDIR,
        WATCHEREFUSEDIR
    ];

    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
            ensureFppOwnership($dir);
        }
    }
}

/**
 * Get data category definitions for file management
 * Returns array of category => [name, dir, description, showFiles, warning, playerOnly]
 */
function getDataCategories() {
    return [
        'ping' => [
            'name' => 'Ping Metrics',
            'dir' => WATCHERPINGDIR,
            'description' => 'Connectivity ping history and rollups'
        ],
        'multisync-ping' => [
            'name' => 'Multi-Sync Ping',
            'dir' => WATCHERMULTISYNCPINGDIR,
            'description' => 'Multi-sync host ping history and rollups',
            'playerOnly' => true
        ],
        'network-quality' => [
            'name' => 'Network Quality',
            'dir' => WATCHERNETWORKQUALITYDIR,
            'description' => 'Network quality metrics (latency, jitter, packet loss)',
            'playerOnly' => true
        ],
        'mqtt' => [
            'name' => 'MQTT Events',
            'dir' => WATCHERMQTTDIR,
            'description' => 'MQTT event history',
            'playerOnly' => true
        ],
        'connectivity' => [
            'name' => 'Connectivity State',
            'dir' => WATCHERCONNECTIVITYDIR,
            'description' => 'Network adapter reset state'
        ],
        'collectd' => [
            'name' => 'Collectd RRD Data',
            'dir' => WATCHERCOLLECTDRRDDIR,
            'description' => 'System metrics collected by collectd (CPU, memory, disk, etc.)',
            'showFiles' => false,
            'recursive' => true,
            'warning' => 'Collectd uses fixed-size RRD files. Deleting these files will not free storage space unless collectd is disabled first.'
        ],
        'efuse' => [
            'name' => 'eFuse Metrics',
            'dir' => WATCHEREFUSEDIR,
            'description' => 'eFuse current monitoring history and rollups',
            'playerOnly' => false
        ]
    ];
}

/**
 * Get statistics for all data directories
 * Returns array with category => [files => [], totalSize => int, fileCount => int]
 */
function getDataDirectoryStats() {
    $categories = getDataCategories();
    $stats = [];

    foreach ($categories as $key => $category) {
        $dir = $category['dir'];
        $showFiles = $category['showFiles'] ?? true;
        $categoryStats = [
            'name' => $category['name'],
            'description' => $category['description'],
            'files' => [],
            'totalSize' => 0,
            'fileCount' => 0,
            'showFiles' => $showFiles,
            'recursive' => $category['recursive'] ?? false,
            'warning' => $category['warning'] ?? null,
            'playerOnly' => $category['playerOnly'] ?? false
        ];

        if (is_dir($dir)) {
            if ($showFiles) {
                // Standard behavior: list individual files
                $files = scandir($dir);
                foreach ($files as $file) {
                    if ($file === '.' || $file === '..') continue;
                    $filePath = $dir . '/' . $file;
                    if (is_file($filePath)) {
                        $size = filesize($filePath);
                        $categoryStats['files'][] = [
                            'name' => $file,
                            'size' => $size,
                            'modified' => filemtime($filePath)
                        ];
                        $categoryStats['totalSize'] += $size;
                        $categoryStats['fileCount']++;
                    }
                }
            } else {
                // For directories like collectd: calculate total size recursively
                $sizeInfo = getDirectorySizeRecursive($dir);
                $categoryStats['totalSize'] = $sizeInfo['size'];
                $categoryStats['fileCount'] = $sizeInfo['count'];
            }
        }

        $stats[$key] = $categoryStats;
    }

    return $stats;
}

/**
 * Calculate total size of a directory recursively
 * @param string $dir Directory path
 * @return array ['size' => int, 'count' => int]
 */
function getDirectorySizeRecursive($dir) {
    $size = 0;
    $count = 0;

    if (!is_dir($dir)) {
        return ['size' => 0, 'count' => 0];
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $size += $file->getSize();
            $count++;
        }
    }

    return ['size' => $size, 'count' => $count];
}

/**
 * Clear all data files in a specific category
 * @param string $category Category key (ping, multisync-ping, network-quality, mqtt, connectivity, collectd)
 * @return array [success => bool, deleted => int, errors => []]
 */
function clearDataCategory($category) {
    $categories = getDataCategories();

    if (!isset($categories[$category])) {
        return ['success' => false, 'deleted' => 0, 'errors' => ['Invalid category']];
    }

    $dir = $categories[$category]['dir'];
    $recursive = $categories[$category]['recursive'] ?? false;
    $deleted = 0;
    $errors = [];

    if (!is_dir($dir)) {
        return ['success' => true, 'deleted' => 0, 'errors' => []];
    }

    if ($recursive) {
        // Recursive deletion for directories like collectd
        $result = clearDirectoryRecursive($dir);
        $deleted = $result['deleted'];
        $errors = $result['errors'];
    } else {
        // Standard flat directory deletion
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $filePath = $dir . '/' . $file;
            if (is_file($filePath)) {
                if (@unlink($filePath)) {
                    $deleted++;
                } else {
                    $errors[] = "Failed to delete: $file";
                }
            }
        }
    }

    logMessage("Cleared data category '$category': deleted $deleted files" . (count($errors) > 0 ? ", errors: " . implode(', ', $errors) : ''));

    return [
        'success' => count($errors) === 0,
        'deleted' => $deleted,
        'errors' => $errors
    ];
}

/**
 * Recursively delete all files in a directory (keeps directory structure)
 * @param string $dir Directory path
 * @return array ['deleted' => int, 'errors' => []]
 */
function clearDirectoryRecursive($dir) {
    $deleted = 0;
    $errors = [];

    if (!is_dir($dir)) {
        return ['deleted' => 0, 'errors' => []];
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $filePath = $file->getPathname();
            if (@unlink($filePath)) {
                $deleted++;
            } else {
                $errors[] = "Failed to delete: " . $file->getFilename();
            }
        }
    }

    return ['deleted' => $deleted, 'errors' => $errors];
}

/**
 * Delete a specific file within a category
 * @param string $category Category key
 * @param string $filename Filename to delete (basename only, no path)
 * @return array [success => bool, error => string|null]
 */
function clearDataFile($category, $filename) {
    $categories = getDataCategories();

    if (!isset($categories[$category])) {
        return ['success' => false, 'error' => 'Invalid category'];
    }

    // Sanitize filename - only allow basename, no directory traversal
    $filename = basename($filename);
    if (empty($filename) || $filename === '.' || $filename === '..') {
        return ['success' => false, 'error' => 'Invalid filename'];
    }

    $dir = $categories[$category]['dir'];
    $filePath = $dir . '/' . $filename;

    if (!file_exists($filePath)) {
        return ['success' => false, 'error' => 'File not found'];
    }

    if (!is_file($filePath)) {
        return ['success' => false, 'error' => 'Not a file'];
    }

    if (@unlink($filePath)) {
        logMessage("Deleted file '$filename' from category '$category'");
        return ['success' => true, 'error' => null];
    }

    return ['success' => false, 'error' => 'Failed to delete file'];
}

/**
 * Acquire a daemon lock with stale lock detection
 *
 * Uses flock() for locking with PID tracking for stale detection.
 * If a lock file exists but the process is dead, automatically clears it.
 *
 * @param string $daemonName Short name for the daemon (used in lock filename and logs)
 * @param string|null $logFile Optional log file path for messages (uses main log if null)
 * @return resource|false File handle on success (keep open for daemon lifetime), false on failure
 *
 * Usage:
 *   $lockFp = acquireDaemonLock('efuse-collector', EFUSE_LOG_FILE);
 *   if (!$lockFp) exit(1);
 *   // ... daemon main loop ...
 *   releaseDaemonLock($lockFp, 'efuse-collector');
 */
function acquireDaemonLock($daemonName, $logFile = null) {
    $lockFile = "/tmp/fpp-watcher-{$daemonName}.lock";
    $logFile = $logFile ?? WATCHERLOGFILE;

    $lockFp = @fopen($lockFile, 'c');
    if (!$lockFp) {
        logMessage("[{$daemonName}] Failed to open lock file: $lockFile", $logFile);
        return false;
    }

    if (flock($lockFp, LOCK_EX | LOCK_NB)) {
        // Got the lock - write our PID
        ftruncate($lockFp, 0);
        fwrite($lockFp, getmypid());
        fflush($lockFp);
        return $lockFp;
    }

    // Lock failed - check if holding process is still alive
    $stalePid = @file_get_contents($lockFile);
    if ($stalePid && is_numeric(trim($stalePid))) {
        $stalePid = (int)trim($stalePid);

        // posix_kill with signal 0 tests process existence without sending a signal
        if (!posix_kill($stalePid, 0)) {
            // Process doesn't exist - lock is stale
            logMessage("[{$daemonName}] Detected stale lock from PID $stalePid (process no longer exists). Clearing stale lock...", $logFile);
            @fclose($lockFp);
            @unlink($lockFile);

            // Try again with fresh file
            $lockFp = @fopen($lockFile, 'c');
            if ($lockFp && flock($lockFp, LOCK_EX | LOCK_NB)) {
                ftruncate($lockFp, 0);
                fwrite($lockFp, getmypid());
                fflush($lockFp);
                logMessage("[{$daemonName}] Successfully acquired lock after clearing stale lock.", $logFile);
                return $lockFp;
            }

            logMessage("[{$daemonName}] Failed to acquire lock even after clearing stale lock. Exiting.", $logFile);
            return false;
        }

        // Process is alive
        logMessage("[{$daemonName}] Another instance (PID $stalePid) is already running. Exiting.", $logFile);
    } else {
        logMessage("[{$daemonName}] Another instance is already running. Exiting.", $logFile);
    }

    @fclose($lockFp);
    return false;
}

/**
 * Release a daemon lock acquired with acquireDaemonLock()
 *
 * @param resource $lockFp File handle from acquireDaemonLock()
 * @param string $daemonName Daemon name (must match acquireDaemonLock call)
 */
function releaseDaemonLock($lockFp, $daemonName) {
    $lockFile = "/tmp/fpp-watcher-{$daemonName}.lock";

    if ($lockFp) {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
    }
    @unlink($lockFile);
}

/**
 * Get the last N lines of a file in a category
 * @param string $category Category key
 * @param string $filename Filename
 * @param int $lines Number of lines to return
 * @return array [success => bool, content => string, error => string|null]
 */
function tailDataFile($category, $filename, $lines = 100) {
    $categories = getDataCategories();

    if (!isset($categories[$category])) {
        return ['success' => false, 'content' => '', 'error' => 'Invalid category'];
    }

    // Sanitize filename
    $filename = basename($filename);
    if (empty($filename) || $filename === '.' || $filename === '..') {
        return ['success' => false, 'content' => '', 'error' => 'Invalid filename'];
    }

    $dir = $categories[$category]['dir'];
    $filePath = $dir . '/' . $filename;

    if (!file_exists($filePath)) {
        return ['success' => false, 'content' => '', 'error' => 'File not found'];
    }

    if (!is_file($filePath) || !is_readable($filePath)) {
        return ['success' => false, 'content' => '', 'error' => 'Cannot read file'];
    }

    // Use tail command for efficiency on large files
    $output = [];
    $returnVar = 0;
    exec('tail -n ' . intval($lines) . ' ' . escapeshellarg($filePath) . ' 2>&1', $output, $returnVar);

    if ($returnVar !== 0) {
        // Fallback to PHP if tail fails
        $content = file_get_contents($filePath);
        if ($content === false) {
            return ['success' => false, 'content' => '', 'error' => 'Failed to read file'];
        }
        $allLines = explode("\n", $content);
        $output = array_slice($allLines, -$lines);
    }

    return [
        'success' => true,
        'content' => implode("\n", $output),
        'filename' => $filename,
        'category' => $category,
        'lines' => count($output),
        'error' => null
    ];
}
?>
