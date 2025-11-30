<?php
include_once __DIR__ . "/watcherCommon.php";

/**
 * Collectd hostname - hardcoded to avoid issues when user changes hostname after installing collectd.
 * RRD files are stored under this hostname, so it must remain constant.
 */
define('COLLECTD_HOSTNAME', 'fpplocal');

/**
 * Fetch ping metrics from the last N hours
 *
 * @param int $hoursBack Number of hours to fetch (default: 24)
 * @return array Result with success status and data
 */
function getPingMetrics($hoursBack = 24) {
    $metricsFile = WATCHERPINGMETRICSFILE;

    if (!file_exists($metricsFile)) {
        return [
            'success' => false,
            'error' => 'Metrics file not found',
            'data' => []
        ];
    }

    $cutoffTime = time() - ($hoursBack * 60 * 60);
    $metrics = [];

    // Read the file line by line
    $file = fopen($metricsFile, 'r');
    if ($file) {
        while (($line = fgets($file)) !== false) {
            // Quick extraction of timestamp without full JSON parse
            // Format: [log timestamp] {"timestamp":1234567890,...}
            if (preg_match('/"timestamp"\s*:\s*(\d+)/', $line, $tsMatch)) {
                $timestamp = (int)$tsMatch[1];

                // Skip old entries without expensive json_decode
                if ($timestamp < $cutoffTime) {
                    continue;
                }

                // Only parse JSON for entries within time range
                if (preg_match('/\[.*?\]\s+(.+)$/', $line, $matches)) {
                    $entry = json_decode(trim($matches[1]), true);

                    if ($entry) {
                        $metrics[] = [
                            'timestamp' => $timestamp,
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
    sortByTimestamp($metrics);

    return [
        'success' => true,
        'count' => count($metrics),
        'data' => $metrics,
        'period' => $hoursBack . 'h'
    ];
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
    $rrdFile = "/var/lib/collectd/rrd/" . COLLECTD_HOSTNAME . "/{$category}/{$metric}.rrd";

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
 * Fetch multiple RRD metrics in a single rrdtool xport command
 * Much more efficient than calling getCollectdMetrics() in a loop
 *
 * @param array $rrdSources Array of ['name' => 'label', 'file' => '/path/to.rrd', 'ds' => 'value']
 * @param string $consolidationFunction CF to use: AVERAGE, MIN, MAX, LAST (default: AVERAGE)
 * @param int $hoursBack Number of hours to fetch (default: 24)
 * @return array Result with success status and data keyed by source name
 */
function getBatchedCollectdMetrics($rrdSources, $consolidationFunction = 'AVERAGE', $hoursBack = 24) {
    if (empty($rrdSources)) {
        return [
            'success' => false,
            'error' => 'No RRD sources provided',
            'data' => []
        ];
    }

    // Filter to only existing files
    $validSources = [];
    foreach ($rrdSources as $source) {
        if (file_exists($source['file'])) {
            $validSources[] = $source;
        }
    }

    if (empty($validSources)) {
        return [
            'success' => false,
            'error' => 'No valid RRD files found',
            'data' => []
        ];
    }

    $endTime = time();
    $startTime = $endTime - ($hoursBack * 3600);

    // Build DEF and XPORT statements for rrdtool xport
    $defs = [];
    $xports = [];
    foreach ($validSources as $idx => $source) {
        $varName = "v{$idx}";
        $ds = $source['ds'] ?? 'value';
        $defs[] = sprintf(
            'DEF:%s=%s:%s:%s',
            $varName,
            escapeshellarg($source['file']),
            $ds,
            $consolidationFunction
        );
        $xports[] = sprintf('XPORT:%s:%s', $varName, escapeshellarg($source['name']));
    }

    // Build single rrdtool xport command
    $command = sprintf(
        'rrdtool xport --json --start %d --end %d %s %s 2>&1',
        $startTime,
        $endTime,
        implode(' ', $defs),
        implode(' ', $xports)
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

    // Parse JSON output
    $jsonOutput = implode('', $output);
    $result = json_decode($jsonOutput, true);

    if (!$result || !isset($result['meta']) || !isset($result['data'])) {
        return [
            'success' => false,
            'error' => 'Failed to parse rrdtool xport output',
            'data' => []
        ];
    }

    // Build structured output: array of [timestamp => value] per source
    $legends = $result['meta']['legend'] ?? [];
    $startTs = $result['meta']['start'] ?? $startTime;
    $step = $result['meta']['step'] ?? 10;
    $rows = $result['data'] ?? [];

    $metrics = [];
    $timestamp = $startTs;

    foreach ($rows as $row) {
        $dataPoint = ['timestamp' => $timestamp];
        foreach ($legends as $idx => $legend) {
            $value = $row[$idx] ?? null;
            // Handle null/NaN values
            $dataPoint[$legend] = ($value === null || is_nan($value)) ? null : floatval($value);
        }
        $metrics[] = $dataPoint;
        $timestamp += $step;
    }

    return [
        'success' => true,
        'count' => count($metrics),
        'data' => $metrics,
        'period' => $hoursBack . 'h',
        'sources' => array_column($validSources, 'name')
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
    $cpuDirs = glob("/var/lib/collectd/rrd/" . COLLECTD_HOSTNAME . "/cpu-*", GLOB_ONLYDIR);
    return count($cpuDirs);
}

/**
 * Fetch averaged CPU usage metrics across all cores
 * Uses batched RRD fetch for efficiency (single exec instead of N)
 *
 * @param int $hoursBack Number of hours to fetch (default: 24)
 * @return array Result with averaged CPU usage data
 */
function getCPUAverageMetrics($hoursBack = 24) {
    $cpuCount = getCPUCoreCount();

    if ($cpuCount === 0) {
        return [
            'success' => false,
            'error' => 'No CPU cores found',
            'data' => []
        ];
    }

    // Build RRD sources for all CPU cores (single exec instead of N)
    $basePath = "/var/lib/collectd/rrd/" . COLLECTD_HOSTNAME;
    $rrdSources = [];
    for ($i = 0; $i < $cpuCount; $i++) {
        $rrdSources[] = [
            'name' => "cpu{$i}",
            'file' => "{$basePath}/cpu-{$i}/cpu-idle.rrd",
            'ds' => 'value'
        ];
    }

    // Fetch all CPU data in one command
    $result = getBatchedCollectdMetrics($rrdSources, 'AVERAGE', $hoursBack);

    if (!$result['success']) {
        return [
            'success' => false,
            'error' => $result['error'] ?? 'No CPU data available',
            'data' => []
        ];
    }

    // Calculate averages across all cores for each timestamp
    $formattedData = [];
    foreach ($result['data'] as $entry) {
        $timestamp = $entry['timestamp'];
        $cpuValues = [];

        // Collect all CPU values for this timestamp
        for ($i = 0; $i < $cpuCount; $i++) {
            $key = "cpu{$i}";
            if (isset($entry[$key]) && $entry[$key] !== null) {
                $cpuValues[] = $entry[$key];
            }
        }

        if (!empty($cpuValues)) {
            $avgIdle = array_sum($cpuValues) / count($cpuValues);
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
    sortByTimestamp($formattedData);

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
    $interfaceDirs = glob("/var/lib/collectd/rrd/" . COLLECTD_HOSTNAME . "/interface-*", GLOB_ONLYDIR);

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
    $thermalDirs = glob("/var/lib/collectd/rrd/" . COLLECTD_HOSTNAME . "/thermal-*", GLOB_ONLYDIR);

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
 * Get list of available wireless interfaces
 *
 * @return array List of wireless interface names
 */
function getWirelessInterfaces() {
    $wirelessDirs = glob("/var/lib/collectd/rrd/" . COLLECTD_HOSTNAME . "/wireless-*", GLOB_ONLYDIR);

    $interfaces = [];
    foreach ($wirelessDirs as $dir) {
        // Extract interface name from path (e.g., wireless-wlan0 -> wlan0)
        if (preg_match('/wireless-(.+)$/', basename($dir), $matches)) {
            $interfaces[] = $matches[1];
        }
    }

    return $interfaces;
}

/**
 * Fetch thermal zone temperature metrics for all zones
 * Uses batched RRD fetch for efficiency (single exec instead of N)
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

    // Build RRD sources for all thermal zones (single exec instead of N)
    $basePath = "/var/lib/collectd/rrd/" . COLLECTD_HOSTNAME;
    $rrdSources = [];
    foreach ($zones as $zone) {
        $rrdSources[] = [
            'name' => $zone,
            'file' => "{$basePath}/thermal-{$zone}/temperature.rrd",
            'ds' => 'value'
        ];
    }

    // Fetch all thermal data in one command
    $result = getBatchedCollectdMetrics($rrdSources, 'AVERAGE', $hoursBack);

    if (!$result['success']) {
        return [
            'success' => false,
            'error' => $result['error'] ?? 'No thermal data available',
            'data' => []
        ];
    }

    // Format data with rounded temperatures
    $formattedData = [];
    foreach ($result['data'] as $entry) {
        $dataPoint = ['timestamp' => $entry['timestamp']];
        foreach ($zones as $zone) {
            $dataPoint[$zone] = isset($entry[$zone]) && $entry[$zone] !== null
                ? round($entry[$zone], 2)
                : null;
        }
        $formattedData[] = $dataPoint;
    }

    return [
        'success' => true,
        'count' => count($formattedData),
        'data' => $formattedData,
        'period' => $hoursBack . 'h',
        'zones' => $zones
    ];
}

/**
 * Fetch wireless metrics for all wireless interfaces
 * Includes signal quality, signal level, and noise level
 * Uses batched RRD fetch for efficiency (single exec instead of interfaces × metrics)
 *
 * @param int $hoursBack Number of hours to fetch (default: 24)
 * @return array Result with formatted wireless data for all interfaces
 */
function getWirelessMetrics($hoursBack = 24) {
    $interfaces = getWirelessInterfaces();

    if (empty($interfaces)) {
        return [
            'success' => false,
            'error' => 'No wireless interfaces found',
            'data' => []
        ];
    }

    // Build RRD sources for all interfaces × metrics (single exec instead of N×M)
    $basePath = "/var/lib/collectd/rrd/" . COLLECTD_HOSTNAME;
    $metricTypes = ['signal_quality', 'signal_power', 'signal_noise'];
    $rrdSources = [];

    foreach ($interfaces as $iface) {
        foreach ($metricTypes as $metric) {
            $rrdSources[] = [
                'name' => "{$iface}_{$metric}",
                'file' => "{$basePath}/wireless-{$iface}/{$metric}.rrd",
                'ds' => 'value'
            ];
        }
    }

    // Fetch all wireless data in one command
    $result = getBatchedCollectdMetrics($rrdSources, 'AVERAGE', $hoursBack);

    if (!$result['success']) {
        return [
            'success' => false,
            'error' => $result['error'] ?? 'No wireless data available',
            'data' => []
        ];
    }

    // Determine which metrics were actually found (from sources that existed)
    $availableMetrics = [];
    foreach ($result['sources'] as $source) {
        // Parse "iface_metric" format
        if (preg_match('/^(.+)_(signal_(?:quality|power|noise))$/', $source, $matches)) {
            $iface = $matches[1];
            $metric = $matches[2];
            $availableMetrics[$iface][] = $metric;
        }
    }

    // Format data with rounded values
    $formattedData = [];
    foreach ($result['data'] as $entry) {
        $dataPoint = ['timestamp' => $entry['timestamp']];
        foreach ($result['sources'] as $source) {
            $dataPoint[$source] = isset($entry[$source]) && $entry[$source] !== null
                ? round($entry[$source], 2)
                : null;
        }
        $formattedData[] = $dataPoint;
    }

    return [
        'success' => true,
        'count' => count($formattedData),
        'data' => $formattedData,
        'period' => $hoursBack . 'h',
        'interfaces' => $interfaces,
        'available_metrics' => $availableMetrics
    ];
}

// If this file is called directly (not included), output JSON
if (basename($_SERVER['PHP_SELF']) == basename(__FILE__)) {
    header('Content-Type: application/json');
    echo json_encode(getPingMetrics(), JSON_PRETTY_PRINT);
}
?>
