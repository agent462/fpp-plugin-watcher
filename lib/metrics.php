<?php
include_once "/opt/fpp/www/common.php";
include_once __DIR__ . "/watcherCommon.php";

/**
 * Fetch ping metrics from the last 24 hours
 */
function getPingMetrics() {
    $metricsFile = WATCHERPINGMETRICSFILE;

    if (!file_exists($metricsFile)) {
        return [
            'success' => false,
            'error' => 'Metrics file not found',
            'data' => []
        ];
    }

    $twentyFourHoursAgo = time() - (24 * 60 * 60);
    $metrics = [];

    // Read the file line by line
    $file = fopen($metricsFile, 'r');
    if ($file) {
        while (($line = fgets($file)) !== false) {
            // Extract JSON from log entry format: [timestamp] JSON
            // The logMessage function prepends [timestamp] to each line
            if (preg_match('/\[.*?\]\s+(.+)$/', $line, $matches)) {
                $jsonData = trim($matches[1]);
                $entry = json_decode($jsonData, true);

                if ($entry && isset($entry['timestamp'])) {
                    // Only include entries from last 24 hours
                    if ($entry['timestamp'] >= $twentyFourHoursAgo) {
                        $metrics[] = [
                            'timestamp' => $entry['timestamp'],
                            'host' => $entry['host'],
                            'latency' => isset($entry['latency']) && $entry['latency'] !== null
                                ? floatval($entry['latency'])
                                : null,
                            'status' => $entry['status']
                        ];
                    }
                }
            }
        }
        fclose($file);
    }

    // Sort by timestamp ascending (oldest first)
    usort($metrics, function($a, $b) {
        return $a['timestamp'] - $b['timestamp'];
    });

    return [
        'success' => true,
        'count' => count($metrics),
        'data' => $metrics,
        'period' => '24h'
    ];
}

/**
 * Get hostname for collectd RRD path
 */
function getCollectdHostname() {
    /** Normally I delete commented code but I will leave this here.  Why hardcode the collectd hostname?
     * If a user changes the hostname of their FPP system after installing collectd, the RRD files will be
     * stored under the old hostname.  So we hardcode it to 'fpplocal' to avoid issues.
     */
    #return trim(shell_exec('hostname'));
    return 'fpplocal';
}

/**
 * Fetch collectd RRD metrics for a specific metric type
 *
 * @param string $category Category path (e.g., 'memory', 'cpu-0', 'interface-eth0')
 * @param string $metric Metric name (e.g., 'memory-free', 'cpu-idle', 'if_octets')
 * @param string $consolidationFunction CF to use: AVERAGE, MIN, MAX, LAST (default: AVERAGE)
 * @param int $hoursBack Number of hours to fetch (default: 24)
 * @return array Result with success status and data
 */
function getCollectdMetrics($category, $metric, $consolidationFunction = 'AVERAGE', $hoursBack = 24) {
    $hostname = getCollectdHostname();
    $rrdFile = "/var/lib/collectd/rrd/{$hostname}/{$category}/{$metric}.rrd";

    if (!file_exists($rrdFile)) {
        return [
            'success' => false,
            'error' => "RRD file not found: {$rrdFile}",
            'data' => []
        ];
    }

    // Calculate time range
    $endTime = time();
    $startTime = $endTime - ($hoursBack * 3600);

    // Build rrdtool fetch command
    $command = sprintf(
        'rrdtool fetch %s %s --start %d --end %d 2>&1',
        escapeshellarg($rrdFile),
        escapeshellarg($consolidationFunction),
        $startTime,
        $endTime
    );

    $output = [];
    $returnVar = 0;
    exec($command, $output, $returnVar);

    if ($returnVar !== 0) {
        return [
            'success' => false,
            'error' => 'Failed to fetch RRD data: ' . implode("\n", $output),
            'data' => []
        ];
    }

    // Parse output
    $metrics = [];
    $headers = [];

    foreach ($output as $index => $line) {
        // First line contains headers
        if ($index === 0) {
            $headers = preg_split('/\s+/', trim($line));
            continue;
        }

        // Parse data lines (format: "timestamp: value1 value2 ...")
        if (preg_match('/^(\d+):\s+(.+)$/', $line, $matches)) {
            $timestamp = intval($matches[1]);
            $values = preg_split('/\s+/', trim($matches[2]));

            $dataPoint = ['timestamp' => $timestamp];

            foreach ($headers as $headerIndex => $header) {
                if (isset($values[$headerIndex])) {
                    $value = $values[$headerIndex];
                    // Handle NaN and convert to float
                    $dataPoint[$header] = ($value === '-nan' || $value === 'nan') ? null : floatval($value);
                } else {
                    $dataPoint[$header] = null;
                }
            }

            $metrics[] = $dataPoint;
        }
    }

    return [
        'success' => true,
        'count' => count($metrics),
        'data' => $metrics,
        'period' => $hoursBack . 'h',
        'metric' => $metric,
        'category' => $category
    ];
}

/**
 * Fetch free memory metrics from collectd
 *
 * @param int $hoursBack Number of hours to fetch (default: 24)
 * @return array Result with formatted memory data
 */
function getMemoryFreeMetrics($hoursBack = 24) {
    $result = getCollectdMetrics('memory', 'memory-free', 'AVERAGE', $hoursBack);

    if (!$result['success']) {
        return $result;
    }

    // Convert bytes to megabytes for easier reading
    $formattedData = [];
    foreach ($result['data'] as $entry) {
        $formattedData[] = [
            'timestamp' => $entry['timestamp'],
            'free_mb' => isset($entry['value']) && $entry['value'] !== null
                ? round($entry['value'] / (1024 * 1024), 2)
                : null,
            'free_bytes' => $entry['value'] ?? null
        ];
    }

    return [
        'success' => true,
        'count' => count($formattedData),
        'data' => $formattedData,
        'period' => $hoursBack . 'h'
    ];
}

/**
 * Fetch disk free space metrics from collectd for root filesystem
 *
 * @param int $hoursBack Number of hours to fetch (default: 24)
 * @return array Result with formatted disk free data
 */
function getDiskFreeMetrics($hoursBack = 24) {
    $result = getCollectdMetrics('df-root', 'df_complex-free', 'AVERAGE', $hoursBack);

    if (!$result['success']) {
        return $result;
    }

    // Convert bytes to gigabytes for easier reading
    $formattedData = [];
    foreach ($result['data'] as $entry) {
        $formattedData[] = [
            'timestamp' => $entry['timestamp'],
            'free_gb' => isset($entry['value']) && $entry['value'] !== null
                ? round($entry['value'] / (1024 * 1024 * 1024), 2)
                : null,
            'free_bytes' => $entry['value'] ?? null
        ];
    }

    return [
        'success' => true,
        'count' => count($formattedData),
        'data' => $formattedData,
        'period' => $hoursBack . 'h'
    ];
}

/**
 * Get number of CPU cores available
 *
 * @return int Number of CPU cores
 */
function getCPUCoreCount() {
    $hostname = getCollectdHostname();
    $cpuDirs = glob("/var/lib/collectd/rrd/{$hostname}/cpu-*", GLOB_ONLYDIR);
    return count($cpuDirs);
}

/**
 * Fetch averaged CPU usage metrics across all cores
 *
 * @param int $hoursBack Number of hours to fetch (default: 24)
 * @return array Result with averaged CPU usage data
 */
function getCPUAverageMetrics($hoursBack = 24) {
    $hostname = getCollectdHostname();
    $cpuCount = getCPUCoreCount();

    if ($cpuCount === 0) {
        return [
            'success' => false,
            'error' => 'No CPU cores found',
            'data' => []
        ];
    }

    // Fetch idle time for all CPUs
    $allCPUData = [];
    for ($i = 0; $i < $cpuCount; $i++) {
        $result = getCollectdMetrics("cpu-{$i}", 'cpu-idle', 'AVERAGE', $hoursBack);
        if ($result['success'] && !empty($result['data'])) {
            $allCPUData[$i] = $result['data'];
        }
    }

    if (empty($allCPUData)) {
        return [
            'success' => false,
            'error' => 'No CPU data available',
            'data' => []
        ];
    }

    // Build a map of timestamps
    $timestampMap = [];
    foreach ($allCPUData as $cpuIndex => $cpuData) {
        foreach ($cpuData as $entry) {
            $timestamp = $entry['timestamp'];
            if (!isset($timestampMap[$timestamp])) {
                $timestampMap[$timestamp] = [];
            }
            $timestampMap[$timestamp][$cpuIndex] = $entry['value'];
        }
    }

    // Calculate averages
    $formattedData = [];
    foreach ($timestampMap as $timestamp => $cpuValues) {
        // Filter out null values
        $validValues = array_filter($cpuValues, function($v) { return $v !== null; });

        if (!empty($validValues)) {
            $avgIdle = array_sum($validValues) / count($validValues);
            // Convert idle to usage (100 - idle = usage)
            $avgUsage = 100 - $avgIdle;

            $formattedData[] = [
                'timestamp' => $timestamp,
                'cpu_usage' => round($avgUsage, 2),
                'cpu_idle' => round($avgIdle, 2)
            ];
        }
    }

    // Sort by timestamp
    usort($formattedData, function($a, $b) {
        return $a['timestamp'] - $b['timestamp'];
    });

    return [
        'success' => true,
        'count' => count($formattedData),
        'data' => $formattedData,
        'period' => $hoursBack . 'h',
        'cpu_cores' => $cpuCount
    ];
}

/**
 * Get list of available network interfaces
 *
 * @return array List of interface names
 */
function getNetworkInterfaces() {
    $hostname = getCollectdHostname();
    $interfaceDirs = glob("/var/lib/collectd/rrd/{$hostname}/interface-*", GLOB_ONLYDIR);

    $interfaces = [];
    foreach ($interfaceDirs as $dir) {
        // Extract interface name from path (e.g., interface-eth0 -> eth0)
        if (preg_match('/interface-(.+)$/', basename($dir), $matches)) {
            $interfaces[] = $matches[1];
        }
    }

    return $interfaces;
}

/**
 * Fetch network interface bandwidth metrics (RX/TX octets)
 *
 * @param string $interface Interface name (e.g., 'eth0', 'wlan0')
 * @param int $hoursBack Number of hours to fetch (default: 24)
 * @return array Result with formatted bandwidth data
 */
function getInterfaceBandwidthMetrics($interface = 'eth0', $hoursBack = 24) {
    $result = getCollectdMetrics("interface-{$interface}", 'if_octets', 'AVERAGE', $hoursBack);

    if (!$result['success']) {
        return $result;
    }

    // Convert bytes/sec to kilobytes/sec for easier reading
    $formattedData = [];
    foreach ($result['data'] as $entry) {
        $formattedData[] = [
            'timestamp' => $entry['timestamp'],
            'rx_kbps' => isset($entry['rx']) && $entry['rx'] !== null
                ? round(($entry['rx'] * 8) / 1024, 2) // Convert bytes to kilobits
                : null,
            'tx_kbps' => isset($entry['tx']) && $entry['tx'] !== null
                ? round(($entry['tx'] * 8) / 1024, 2) // Convert bytes to kilobits
                : null,
            'rx_bytes' => $entry['rx'] ?? null,
            'tx_bytes' => $entry['tx'] ?? null
        ];
    }

    return [
        'success' => true,
        'count' => count($formattedData),
        'data' => $formattedData,
        'period' => $hoursBack . 'h',
        'interface' => $interface
    ];
}

/**
 * Fetch load average metrics (1min, 5min, 15min)
 *
 * @param int $hoursBack Number of hours to fetch (default: 24)
 * @return array Result with formatted load average data
 */
function getLoadAverageMetrics($hoursBack = 24) {
    $result = getCollectdMetrics('load', 'load', 'AVERAGE', $hoursBack);

    if (!$result['success']) {
        return $result;
    }

    // Format data with all three load averages
    $formattedData = [];
    foreach ($result['data'] as $entry) {
        $formattedData[] = [
            'timestamp' => $entry['timestamp'],
            'shortterm' => isset($entry['shortterm']) && $entry['shortterm'] !== null
                ? round($entry['shortterm'], 2)
                : null,
            'midterm' => isset($entry['midterm']) && $entry['midterm'] !== null
                ? round($entry['midterm'], 2)
                : null,
            'longterm' => isset($entry['longterm']) && $entry['longterm'] !== null
                ? round($entry['longterm'], 2)
                : null
        ];
    }

    return [
        'success' => true,
        'count' => count($formattedData),
        'data' => $formattedData,
        'period' => $hoursBack . 'h'
    ];
}

/**
 * Get list of available thermal zones
 *
 * @return array List of thermal zone names
 */
function getThermalZones() {
    $hostname = getCollectdHostname();
    $thermalDirs = glob("/var/lib/collectd/rrd/{$hostname}/thermal-*", GLOB_ONLYDIR);

    $zones = [];
    foreach ($thermalDirs as $dir) {
        // Extract zone name from path (e.g., thermal-thermal_zone0 -> thermal_zone0)
        if (preg_match('/thermal-(.+)$/', basename($dir), $matches)) {
            $zones[] = $matches[1];
        }
    }

    return $zones;
}

/**
 * Fetch thermal zone temperature metrics for all zones
 *
 * @param int $hoursBack Number of hours to fetch (default: 24)
 * @return array Result with formatted temperature data for all zones
 */
function getThermalMetrics($hoursBack = 24) {
    $zones = getThermalZones();

    if (empty($zones)) {
        return [
            'success' => false,
            'error' => 'No thermal zones found',
            'data' => []
        ];
    }

    // Fetch temperature data for all zones
    $allZoneData = [];
    foreach ($zones as $zone) {
        $result = getCollectdMetrics("thermal-{$zone}", 'temperature', 'AVERAGE', $hoursBack);
        if ($result['success'] && !empty($result['data'])) {
            $allZoneData[$zone] = $result['data'];
        }
    }

    if (empty($allZoneData)) {
        return [
            'success' => false,
            'error' => 'No thermal data available',
            'data' => []
        ];
    }

    // Build a map of timestamps with all zone temperatures
    $timestampMap = [];
    foreach ($allZoneData as $zone => $zoneData) {
        foreach ($zoneData as $entry) {
            $timestamp = $entry['timestamp'];
            if (!isset($timestampMap[$timestamp])) {
                $timestampMap[$timestamp] = ['timestamp' => $timestamp];
            }
            // Temperature is stored in the 'value' field
            $timestampMap[$timestamp][$zone] = isset($entry['value']) && $entry['value'] !== null
                ? round($entry['value'], 2)
                : null;
        }
    }

    // Convert to array and sort by timestamp
    $formattedData = array_values($timestampMap);
    usort($formattedData, function($a, $b) {
        return $a['timestamp'] - $b['timestamp'];
    });

    return [
        'success' => true,
        'count' => count($formattedData),
        'data' => $formattedData,
        'period' => $hoursBack . 'h',
        'zones' => $zones
    ];
}

// If this file is called directly (not included), output JSON
if (basename($_SERVER['PHP_SELF']) == basename(__FILE__)) {
    header('Content-Type: application/json');
    echo json_encode(getPingMetrics(), JSON_PRETTY_PRINT);
}
?>
