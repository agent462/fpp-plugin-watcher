<?php
declare(strict_types=1);

namespace Watcher\Metrics;

use Watcher\Core\Logger;
use Watcher\Core\FileManager;

/**
 * System Metrics from Collectd
 *
 * Read-only interface to collectd RRD files for system metrics:
 * CPU, memory, disk, thermal, wireless, load average, network bandwidth
 *
 * Note: This class does NOT use rollup - collectd handles its own data rollup.
 */
class SystemMetrics
{
    private const COLLECTD_HOSTNAME = 'fpplocal';

    private static ?self $instance = null;
    private Logger $logger;
    private FileManager $fileManager;
    private string $collectdDir;
    private string $dataDir;
    private string $pingMetricsFile;
    private array $thermalZoneNamesCache = [];

    private function __construct(
        ?Logger $logger = null,
        ?FileManager $fileManager = null
    ) {
        $this->logger = $logger ?? Logger::getInstance();
        $this->fileManager = $fileManager ?? FileManager::getInstance();

        // Use constants if available
        $this->collectdDir = defined('WATCHERCOLLECTDRRDDIR')
            ? WATCHERCOLLECTDRRDDIR
            : '/var/lib/collectd/rrd';
        $this->dataDir = defined('WATCHERDATADIR')
            ? WATCHERDATADIR
            : '/home/fpp/media/logs/watcher-data';
        $this->pingMetricsFile = defined('WATCHERPINGMETRICSFILE')
            ? WATCHERPINGMETRICSFILE
            : '/home/fpp/media/logs/fpp-plugin-watcher-ping-metrics.log';
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Fetch ping metrics from the last N hours
     */
    public function getPingMetrics(int $hoursBack = 24): array
    {
        if (!file_exists($this->pingMetricsFile)) {
            return [
                'success' => false,
                'error' => 'Metrics file not found',
                'data' => []
            ];
        }

        $cutoffTime = time() - ($hoursBack * 60 * 60);

        // Use shared readJsonLinesFile function
        $entries = readJsonLinesFile($this->pingMetricsFile, $cutoffTime);

        // Transform to expected format
        $metrics = array_map(function ($entry) {
            return [
                'timestamp' => $entry['timestamp'],
                'host' => $entry['host'] ?? '',
                'latency' => isset($entry['latency']) && $entry['latency'] !== null
                    ? floatval($entry['latency'])
                    : null,
                'status' => $entry['status'] ?? ''
            ];
        }, $entries);

        return [
            'success' => true,
            'count' => count($metrics),
            'data' => $metrics,
            'period' => $hoursBack . 'h'
        ];
    }

    /**
     * Fetch collectd RRD metrics for a specific metric type
     */
    public function getCollectdMetrics(
        string $category,
        string $metric,
        string $consolidationFunction = 'AVERAGE',
        int $hoursBack = 24
    ): array {
        $rrdFile = $this->collectdDir . "/" . self::COLLECTD_HOSTNAME . "/{$category}/{$metric}.rrd";

        if (!file_exists($rrdFile)) {
            return [
                'success' => false,
                'error' => "RRD file not found: {$rrdFile}",
                'data' => []
            ];
        }

        $endTime = time();
        $startTime = $endTime - ($hoursBack * 3600);

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
            if ($index === 0) {
                $headers = preg_split('/\s+/', trim($line));
                continue;
            }

            if (preg_match('/^(\d+):\s+(.+)$/', $line, $matches)) {
                $timestamp = intval($matches[1]);
                $values = preg_split('/\s+/', trim($matches[2]));

                $dataPoint = ['timestamp' => $timestamp];

                foreach ($headers as $headerIndex => $header) {
                    if (isset($values[$headerIndex])) {
                        $value = $values[$headerIndex];
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
     */
    public function getBatchedCollectdMetrics(
        array $rrdSources,
        string $consolidationFunction = 'AVERAGE',
        int $hoursBack = 24
    ): array {
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

        // Build DEF and XPORT statements
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

        $jsonOutput = implode('', $output);
        $result = json_decode($jsonOutput, true);

        if (!$result || !isset($result['meta']) || !isset($result['data'])) {
            return [
                'success' => false,
                'error' => 'Failed to parse rrdtool xport output',
                'data' => []
            ];
        }

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
     * Fetch memory metrics (free, buffered, cached)
     */
    public function getMemoryFreeMetrics(int $hoursBack = 24): array
    {
        $basePath = $this->collectdDir . "/" . self::COLLECTD_HOSTNAME . "/memory";

        $rrdSources = [
            ['name' => 'free', 'file' => "{$basePath}/memory-free.rrd", 'ds' => 'value'],
            ['name' => 'buffered', 'file' => "{$basePath}/memory-buffered.rrd", 'ds' => 'value'],
            ['name' => 'cached', 'file' => "{$basePath}/memory-cached.rrd", 'ds' => 'value'],
        ];

        $result = $this->getBatchedCollectdMetrics($rrdSources, 'AVERAGE', $hoursBack);

        if (!$result['success']) {
            return $result;
        }

        // Convert to megabytes
        $formattedData = [];
        foreach ($result['data'] as $entry) {
            $freeMb = isset($entry['free']) && $entry['free'] !== null
                ? round($entry['free'] / (1024 * 1024), 2)
                : null;
            $bufferedMb = isset($entry['buffered']) && $entry['buffered'] !== null
                ? round($entry['buffered'] / (1024 * 1024), 2)
                : null;
            $cachedMb = isset($entry['cached']) && $entry['cached'] !== null
                ? round($entry['cached'] / (1024 * 1024), 2)
                : null;

            $bufferCacheMb = null;
            if ($bufferedMb !== null || $cachedMb !== null) {
                $bufferCacheMb = round(($bufferedMb ?? 0) + ($cachedMb ?? 0), 2);
            }

            $formattedData[] = [
                'timestamp' => $entry['timestamp'],
                'free_mb' => $freeMb,
                'buffer_cache_mb' => $bufferCacheMb,
                'buffered_mb' => $bufferedMb,
                'cached_mb' => $cachedMb,
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
     * Fetch disk free space metrics
     */
    public function getDiskFreeMetrics(int $hoursBack = 24): array
    {
        $result = $this->getCollectdMetrics('df-root', 'df_complex-free', 'AVERAGE', $hoursBack);

        if (!$result['success']) {
            return $result;
        }

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
     * Get number of CPU cores
     */
    public function getCPUCoreCount(): int
    {
        $cpuDirs = glob($this->collectdDir . "/" . self::COLLECTD_HOSTNAME . "/cpu-*", GLOB_ONLYDIR);
        return count($cpuDirs);
    }

    /**
     * Fetch averaged CPU usage metrics across all cores
     */
    public function getCPUAverageMetrics(int $hoursBack = 24): array
    {
        $cpuCount = $this->getCPUCoreCount();

        if ($cpuCount === 0) {
            return [
                'success' => false,
                'error' => 'No CPU cores found',
                'data' => []
            ];
        }

        $basePath = $this->collectdDir . "/" . self::COLLECTD_HOSTNAME;
        $rrdSources = [];
        for ($i = 0; $i < $cpuCount; $i++) {
            $rrdSources[] = [
                'name' => "cpu{$i}",
                'file' => "{$basePath}/cpu-{$i}/cpu-idle.rrd",
                'ds' => 'value'
            ];
        }

        $result = $this->getBatchedCollectdMetrics($rrdSources, 'AVERAGE', $hoursBack);

        if (!$result['success']) {
            return [
                'success' => false,
                'error' => $result['error'] ?? 'No CPU data available',
                'data' => []
            ];
        }

        $formattedData = [];
        foreach ($result['data'] as $entry) {
            $timestamp = $entry['timestamp'];
            $cpuValues = [];

            for ($i = 0; $i < $cpuCount; $i++) {
                $key = "cpu{$i}";
                if (isset($entry[$key]) && $entry[$key] !== null) {
                    $cpuValues[] = $entry[$key];
                }
            }

            if (!empty($cpuValues)) {
                $avgIdle = array_sum($cpuValues) / count($cpuValues);
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
     */
    public function getNetworkInterfaces(): array
    {
        $interfaceDirs = glob($this->collectdDir . "/" . self::COLLECTD_HOSTNAME . "/interface-*", GLOB_ONLYDIR);

        $interfaces = [];
        foreach ($interfaceDirs as $dir) {
            if (preg_match('/interface-(.+)$/', basename($dir), $matches)) {
                $interfaces[] = $matches[1];
            }
        }

        return $interfaces;
    }

    /**
     * Fetch network interface bandwidth metrics
     */
    public function getInterfaceBandwidthMetrics(string $interface = 'eth0', int $hoursBack = 24): array
    {
        $result = $this->getCollectdMetrics("interface-{$interface}", 'if_octets', 'AVERAGE', $hoursBack);

        if (!$result['success']) {
            return $result;
        }

        $formattedData = [];
        foreach ($result['data'] as $entry) {
            $formattedData[] = [
                'timestamp' => $entry['timestamp'],
                'rx_kbps' => isset($entry['rx']) && $entry['rx'] !== null
                    ? round(($entry['rx'] * 8) / 1024, 2)
                    : null,
                'tx_kbps' => isset($entry['tx']) && $entry['tx'] !== null
                    ? round(($entry['tx'] * 8) / 1024, 2)
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
     * Fetch load average metrics
     */
    public function getLoadAverageMetrics(int $hoursBack = 24): array
    {
        $result = $this->getCollectdMetrics('load', 'load', 'AVERAGE', $hoursBack);

        if (!$result['success']) {
            return $result;
        }

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
     */
    public function getThermalZones(): array
    {
        $thermalDirs = glob($this->collectdDir . "/" . self::COLLECTD_HOSTNAME . "/thermal-*", GLOB_ONLYDIR);

        $zones = [];
        foreach ($thermalDirs as $dir) {
            if (preg_match('/thermal-(.+)$/', basename($dir), $matches)) {
                $zones[] = $matches[1];
            }
        }

        return $zones;
    }

    /**
     * Get friendly names for thermal zones from sysfs with caching
     */
    public function getThermalZoneFriendlyNames(): array
    {
        $cacheFile = $this->dataDir . '/thermal_zone_names.json';

        // Check cache first
        if (file_exists($cacheFile)) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if (is_array($cached) && !empty($cached)) {
                return $cached;
            }
        }

        // Read from sysfs
        $zones = glob('/sys/class/thermal/thermal_zone*', GLOB_ONLYDIR);
        $friendlyNames = [];

        foreach ($zones as $zonePath) {
            $zone = basename($zonePath);
            $typeFile = "{$zonePath}/type";
            if (file_exists($typeFile)) {
                $type = trim(file_get_contents($typeFile));
                $friendlyNames[$zone] = $type ?: $zone;
            } else {
                $friendlyNames[$zone] = $zone;
            }
        }

        // Cache to file
        if (!empty($friendlyNames)) {
            @file_put_contents($cacheFile, json_encode($friendlyNames));
            $this->fileManager->ensureFppOwnership($cacheFile);
        }

        return $friendlyNames;
    }

    /**
     * Format thermal zone type for display
     */
    public function formatThermalZoneType(string $type): string
    {
        $abbreviations = [
            'cpu' => 'CPU',
            'gpu' => 'GPU',
            'soc' => 'SoC',
            'acpi' => 'ACPI',
            'pch' => 'PCH',
        ];

        $formatted = str_replace(['_', '-'], ' ', $type);
        $formatted = ucwords($formatted);

        foreach ($abbreviations as $search => $replace) {
            $formatted = preg_replace('/\b' . preg_quote(ucfirst($search), '/') . '\b/i', $replace, $formatted);
        }

        return $formatted;
    }

    /**
     * Get list of wireless interfaces
     */
    public function getWirelessInterfaces(): array
    {
        $wirelessDirs = glob($this->collectdDir . "/" . self::COLLECTD_HOSTNAME . "/wireless-*", GLOB_ONLYDIR);

        $interfaces = [];
        foreach ($wirelessDirs as $dir) {
            if (preg_match('/wireless-(.+)$/', basename($dir), $matches)) {
                $interfaces[] = $matches[1];
            }
        }

        return $interfaces;
    }

    /**
     * Fetch thermal zone temperature metrics for all zones
     */
    public function getThermalMetrics(int $hoursBack = 24): array
    {
        $zones = $this->getThermalZones();

        if (empty($zones)) {
            return [
                'success' => false,
                'error' => 'No thermal zones found',
                'data' => []
            ];
        }

        $basePath = $this->collectdDir . "/" . self::COLLECTD_HOSTNAME;
        $rrdSources = [];
        foreach ($zones as $zone) {
            $rrdSources[] = [
                'name' => $zone,
                'file' => "{$basePath}/thermal-{$zone}/temperature.rrd",
                'ds' => 'value'
            ];
        }

        $result = $this->getBatchedCollectdMetrics($rrdSources, 'AVERAGE', $hoursBack);

        if (!$result['success']) {
            return [
                'success' => false,
                'error' => $result['error'] ?? 'No thermal data available',
                'data' => []
            ];
        }

        $formattedData = [];
        foreach ($result['data'] as $entry) {
            $dataPoint = ['timestamp' => $entry['timestamp']];
            $hasValue = false;
            foreach ($zones as $zone) {
                if (isset($entry[$zone]) && $entry[$zone] !== null) {
                    $dataPoint[$zone] = round($entry[$zone], 2);
                    $hasValue = true;
                } else {
                    $dataPoint[$zone] = null;
                }
            }
            if ($hasValue) {
                $formattedData[] = $dataPoint;
            }
        }

        return [
            'success' => true,
            'count' => count($formattedData),
            'data' => $formattedData,
            'period' => $hoursBack . 'h',
            'zones' => $zones,
            'zone_names' => $this->getThermalZoneFriendlyNames()
        ];
    }

    /**
     * Fetch wireless metrics for all wireless interfaces
     */
    public function getWirelessMetrics(int $hoursBack = 24): array
    {
        $interfaces = $this->getWirelessInterfaces();

        if (empty($interfaces)) {
            return [
                'success' => false,
                'error' => 'No wireless interfaces found',
                'data' => []
            ];
        }

        $basePath = $this->collectdDir . "/" . self::COLLECTD_HOSTNAME;
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

        $result = $this->getBatchedCollectdMetrics($rrdSources, 'AVERAGE', $hoursBack);

        if (!$result['success']) {
            return [
                'success' => false,
                'error' => $result['error'] ?? 'No wireless data available',
                'data' => []
            ];
        }

        // Determine which metrics were actually found
        $availableMetrics = [];
        foreach ($result['sources'] as $source) {
            if (preg_match('/^(.+)_(signal_(?:quality|power|noise))$/', $source, $matches)) {
                $iface = $matches[1];
                $metric = $matches[2];
                $availableMetrics[$iface][] = $metric;
            }
        }

        $formattedData = [];
        foreach ($result['data'] as $entry) {
            $dataPoint = ['timestamp' => $entry['timestamp']];
            $hasValue = false;
            foreach ($result['sources'] as $source) {
                if (isset($entry[$source]) && $entry[$source] !== null) {
                    $dataPoint[$source] = round($entry[$source], 2);
                    $hasValue = true;
                } else {
                    $dataPoint[$source] = null;
                }
            }
            if ($hasValue) {
                $formattedData[] = $dataPoint;
            }
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
}
