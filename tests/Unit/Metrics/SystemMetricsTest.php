<?php
/**
 * Unit tests for SystemMetrics class
 *
 * @package Watcher\Tests\Unit\Metrics
 */

declare(strict_types=1);

namespace Watcher\Tests\Unit\Metrics;

use Watcher\Tests\TestCase;
use Watcher\Metrics\SystemMetrics;

/**
 * Testable subclass that allows mocking RRD-dependent methods
 */
class TestableSystemMetrics extends SystemMetrics
{
    private ?array $mockCollectdResult = null;
    private ?array $mockBatchedResult = null;
    private ?array $mockThermalZones = null;
    private ?array $mockWirelessInterfaces = null;
    private ?array $mockNetworkInterfaces = null;
    private ?int $mockCpuCount = null;

    public function __construct()
    {
        // Use reflection to call parent constructor
        $reflection = new \ReflectionClass(SystemMetrics::class);
        $constructor = $reflection->getConstructor();
        $constructor->setAccessible(true);
        $constructor->invoke($this);
    }

    public static function createForTesting(): self
    {
        return new self();
    }

    public function setMockCollectdResult(?array $result): void
    {
        $this->mockCollectdResult = $result;
    }

    public function setMockBatchedResult(?array $result): void
    {
        $this->mockBatchedResult = $result;
    }

    public function setMockThermalZones(?array $zones): void
    {
        $this->mockThermalZones = $zones;
    }

    public function setMockWirelessInterfaces(?array $interfaces): void
    {
        $this->mockWirelessInterfaces = $interfaces;
    }

    public function setMockNetworkInterfaces(?array $interfaces): void
    {
        $this->mockNetworkInterfaces = $interfaces;
    }

    public function setMockCpuCount(?int $count): void
    {
        $this->mockCpuCount = $count;
    }

    public function getCollectdMetrics(
        string $category,
        string $metric,
        string $consolidationFunction = 'AVERAGE',
        int $hoursBack = 24
    ): array {
        if ($this->mockCollectdResult !== null) {
            return $this->mockCollectdResult;
        }
        return parent::getCollectdMetrics($category, $metric, $consolidationFunction, $hoursBack);
    }

    public function getBatchedCollectdMetrics(
        array $rrdSources,
        string $consolidationFunction = 'AVERAGE',
        int $hoursBack = 24
    ): array {
        if ($this->mockBatchedResult !== null) {
            return $this->mockBatchedResult;
        }
        return parent::getBatchedCollectdMetrics($rrdSources, $consolidationFunction, $hoursBack);
    }

    public function getThermalZones(): array
    {
        if ($this->mockThermalZones !== null) {
            return $this->mockThermalZones;
        }
        return parent::getThermalZones();
    }

    public function getWirelessInterfaces(): array
    {
        if ($this->mockWirelessInterfaces !== null) {
            return $this->mockWirelessInterfaces;
        }
        return parent::getWirelessInterfaces();
    }

    public function getNetworkInterfaces(): array
    {
        if ($this->mockNetworkInterfaces !== null) {
            return $this->mockNetworkInterfaces;
        }
        return parent::getNetworkInterfaces();
    }

    public function getCPUCoreCount(): int
    {
        if ($this->mockCpuCount !== null) {
            return $this->mockCpuCount;
        }
        return parent::getCPUCoreCount();
    }
}

class SystemMetricsTest extends TestCase
{
    private TestableSystemMetrics $metrics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->metrics = TestableSystemMetrics::createForTesting();
    }

    // =========================================================================
    // Singleton Pattern Tests
    // =========================================================================

    public function testGetInstanceReturnsSameInstance(): void
    {
        $instance1 = SystemMetrics::getInstance();
        $instance2 = SystemMetrics::getInstance();

        $this->assertSame($instance1, $instance2);
    }

    public function testResetInstanceCreatesNewInstance(): void
    {
        $instance1 = SystemMetrics::getInstance();
        SystemMetrics::resetInstance();
        $instance2 = SystemMetrics::getInstance();

        $this->assertNotSame($instance1, $instance2);
    }

    // =========================================================================
    // getPingMetrics Tests
    // =========================================================================

    public function testGetPingMetricsFileNotFound(): void
    {
        $result = $this->metrics->getPingMetrics(24);

        $this->assertFalse($result['success']);
        $this->assertEquals('Metrics file not found', $result['error']);
        $this->assertEmpty($result['data']);
    }

    public function testGetPingMetricsWithValidData(): void
    {
        $pingFile = $this->createTempDir('data/ping') . '/metrics.log';
        $now = time();

        // Create sample ping data
        $entries = [
            ['timestamp' => $now - 60, 'host' => '192.168.1.1', 'latency' => 25.5, 'status' => 'success'],
            ['timestamp' => $now - 30, 'host' => '192.168.1.1', 'latency' => 30.2, 'status' => 'success'],
            ['timestamp' => $now, 'host' => '192.168.1.1', 'latency' => null, 'status' => 'timeout'],
        ];
        $this->writeJsonLinesFile($pingFile, $entries);

        // Use reflection to set the ping metrics file path (access parent class property)
        $reflection = new \ReflectionClass(SystemMetrics::class);
        $prop = $reflection->getProperty('pingMetricsFile');
        $prop->setAccessible(true);
        $prop->setValue($this->metrics, $pingFile);

        $result = $this->metrics->getPingMetrics(1);

        $this->assertTrue($result['success']);
        $this->assertCount(3, $result['data']);
        $this->assertEquals('1h', $result['period']);
    }

    public function testGetPingMetricsFiltersOldData(): void
    {
        $pingFile = $this->createTempDir('data/ping2') . '/metrics.log';
        $now = time();

        // Create data with old and new entries
        $entries = [
            ['timestamp' => $now - 7200, 'host' => '192.168.1.1', 'latency' => 20.0, 'status' => 'success'], // 2 hours ago
            ['timestamp' => $now - 60, 'host' => '192.168.1.1', 'latency' => 25.0, 'status' => 'success'],
        ];
        $this->writeJsonLinesFile($pingFile, $entries);

        $reflection = new \ReflectionClass(SystemMetrics::class);
        $prop = $reflection->getProperty('pingMetricsFile');
        $prop->setAccessible(true);
        $prop->setValue($this->metrics, $pingFile);

        // Request only 1 hour of data
        $result = $this->metrics->getPingMetrics(1);

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['data']); // Only the recent entry
    }

    public function testGetPingMetricsHandlesNullLatency(): void
    {
        $pingFile = $this->createTempDir('data/ping3') . '/metrics.log';
        $now = time();

        $entries = [
            ['timestamp' => $now, 'host' => '192.168.1.1', 'latency' => null, 'status' => 'timeout'],
        ];
        $this->writeJsonLinesFile($pingFile, $entries);

        $reflection = new \ReflectionClass(SystemMetrics::class);
        $prop = $reflection->getProperty('pingMetricsFile');
        $prop->setAccessible(true);
        $prop->setValue($this->metrics, $pingFile);

        $result = $this->metrics->getPingMetrics(1);

        $this->assertTrue($result['success']);
        $this->assertNull($result['data'][0]['latency']);
    }

    // =========================================================================
    // Directory Scanning Tests
    // =========================================================================

    public function testGetCPUCoreCountWithMockDirectories(): void
    {
        $collectdDir = $this->createTempDir('collectd/rrd/fpplocal');

        // Create mock CPU directories
        mkdir("{$collectdDir}/cpu-0", 0755, true);
        mkdir("{$collectdDir}/cpu-1", 0755, true);
        mkdir("{$collectdDir}/cpu-2", 0755, true);
        mkdir("{$collectdDir}/cpu-3", 0755, true);

        $reflection = new \ReflectionClass(SystemMetrics::class);
        $prop = $reflection->getProperty('collectdDir');
        $prop->setAccessible(true);
        $prop->setValue($this->metrics, dirname($collectdDir));

        $count = $this->metrics->getCPUCoreCount();

        $this->assertEquals(4, $count);
    }

    public function testGetCPUCoreCountNoCpuDirs(): void
    {
        $collectdDir = $this->createTempDir('collectd/rrd/fpplocal');

        $reflection = new \ReflectionClass(SystemMetrics::class);
        $prop = $reflection->getProperty('collectdDir');
        $prop->setAccessible(true);
        $prop->setValue($this->metrics, dirname($collectdDir));

        $count = $this->metrics->getCPUCoreCount();

        $this->assertEquals(0, $count);
    }

    public function testGetNetworkInterfacesWithMockDirectories(): void
    {
        $collectdDir = $this->createTempDir('collectd2/rrd/fpplocal');

        mkdir("{$collectdDir}/interface-eth0", 0755, true);
        mkdir("{$collectdDir}/interface-wlan0", 0755, true);

        $reflection = new \ReflectionClass(SystemMetrics::class);
        $prop = $reflection->getProperty('collectdDir');
        $prop->setAccessible(true);
        $prop->setValue($this->metrics, dirname($collectdDir));

        $interfaces = $this->metrics->getNetworkInterfaces();

        $this->assertCount(2, $interfaces);
        $this->assertContains('eth0', $interfaces);
        $this->assertContains('wlan0', $interfaces);
    }

    public function testGetNetworkInterfacesEmpty(): void
    {
        $collectdDir = $this->createTempDir('collectd3/rrd/fpplocal');

        $reflection = new \ReflectionClass(SystemMetrics::class);
        $prop = $reflection->getProperty('collectdDir');
        $prop->setAccessible(true);
        $prop->setValue($this->metrics, dirname($collectdDir));

        $interfaces = $this->metrics->getNetworkInterfaces();

        $this->assertEmpty($interfaces);
    }

    public function testGetThermalZonesWithMockDirectories(): void
    {
        $collectdDir = $this->createTempDir('collectd4/rrd/fpplocal');

        mkdir("{$collectdDir}/thermal-thermal_zone0", 0755, true);
        mkdir("{$collectdDir}/thermal-thermal_zone1", 0755, true);

        $reflection = new \ReflectionClass(SystemMetrics::class);
        $prop = $reflection->getProperty('collectdDir');
        $prop->setAccessible(true);
        $prop->setValue($this->metrics, dirname($collectdDir));

        $zones = $this->metrics->getThermalZones();

        $this->assertCount(2, $zones);
        $this->assertContains('thermal_zone0', $zones);
        $this->assertContains('thermal_zone1', $zones);
    }

    public function testGetWirelessInterfacesWithMockDirectories(): void
    {
        $collectdDir = $this->createTempDir('collectd5/rrd/fpplocal');

        mkdir("{$collectdDir}/wireless-wlan0", 0755, true);
        mkdir("{$collectdDir}/wireless-wlan1", 0755, true);

        $reflection = new \ReflectionClass(SystemMetrics::class);
        $prop = $reflection->getProperty('collectdDir');
        $prop->setAccessible(true);
        $prop->setValue($this->metrics, dirname($collectdDir));

        $interfaces = $this->metrics->getWirelessInterfaces();

        $this->assertCount(2, $interfaces);
        $this->assertContains('wlan0', $interfaces);
        $this->assertContains('wlan1', $interfaces);
    }

    // =========================================================================
    // getCollectdMetrics Tests
    // =========================================================================

    public function testGetCollectdMetricsFileNotFound(): void
    {
        // Use the real method (not mocked)
        $metrics = SystemMetrics::getInstance();

        // Set collectdDir to a nonexistent path
        $reflection = new \ReflectionClass($metrics);
        $prop = $reflection->getProperty('collectdDir');
        $prop->setAccessible(true);
        $prop->setValue($metrics, '/nonexistent/path');

        $result = $metrics->getCollectdMetrics('memory', 'memory-free');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('RRD file not found', $result['error']);
        $this->assertEmpty($result['data']);
    }

    // =========================================================================
    // getBatchedCollectdMetrics Tests
    // =========================================================================

    public function testGetBatchedCollectdMetricsEmptySources(): void
    {
        $result = $this->metrics->getBatchedCollectdMetrics([]);

        $this->assertFalse($result['success']);
        $this->assertEquals('No RRD sources provided', $result['error']);
        $this->assertEmpty($result['data']);
    }

    public function testGetBatchedCollectdMetricsNoValidFiles(): void
    {
        $sources = [
            ['name' => 'test1', 'file' => '/nonexistent/file1.rrd', 'ds' => 'value'],
            ['name' => 'test2', 'file' => '/nonexistent/file2.rrd', 'ds' => 'value'],
        ];

        $result = $this->metrics->getBatchedCollectdMetrics($sources);

        $this->assertFalse($result['success']);
        $this->assertEquals('No valid RRD files found', $result['error']);
    }

    // =========================================================================
    // Memory Metrics Transformation Tests
    // =========================================================================

    public function testGetMemoryFreeMetricsTransformation(): void
    {
        $now = time();

        // Mock the batched collectd result with raw byte values
        $this->metrics->setMockBatchedResult([
            'success' => true,
            'count' => 3,
            'data' => [
                ['timestamp' => $now - 120, 'free' => 1073741824, 'buffered' => 268435456, 'cached' => 536870912], // 1GB, 256MB, 512MB
                ['timestamp' => $now - 60, 'free' => 2147483648, 'buffered' => 536870912, 'cached' => 1073741824], // 2GB, 512MB, 1GB
                ['timestamp' => $now, 'free' => null, 'buffered' => 134217728, 'cached' => null], // null, 128MB, null
            ],
            'period' => '1h',
            'sources' => ['free', 'buffered', 'cached']
        ]);

        $result = $this->metrics->getMemoryFreeMetrics(1);

        $this->assertTrue($result['success']);
        $this->assertCount(3, $result['data']);

        // Check first entry conversion (bytes to MB)
        $this->assertEquals(1024.0, $result['data'][0]['free_mb']); // 1GB = 1024MB
        $this->assertEquals(256.0, $result['data'][0]['buffered_mb']);
        $this->assertEquals(512.0, $result['data'][0]['cached_mb']);
        $this->assertEquals(768.0, $result['data'][0]['buffer_cache_mb']); // 256 + 512

        // Check second entry
        $this->assertEquals(2048.0, $result['data'][1]['free_mb']);
        $this->assertEquals(1536.0, $result['data'][1]['buffer_cache_mb']); // 512 + 1024

        // Check null handling in third entry
        $this->assertNull($result['data'][2]['free_mb']);
        $this->assertEquals(128.0, $result['data'][2]['buffered_mb']);
        $this->assertNull($result['data'][2]['cached_mb']);
        $this->assertEquals(128.0, $result['data'][2]['buffer_cache_mb']); // Only buffered
    }

    public function testGetMemoryFreeMetricsFailure(): void
    {
        $this->metrics->setMockBatchedResult([
            'success' => false,
            'error' => 'No valid RRD files found',
            'data' => []
        ]);

        $result = $this->metrics->getMemoryFreeMetrics(1);

        $this->assertFalse($result['success']);
    }

    public function testGetMemoryFreeMetricsAllNull(): void
    {
        $now = time();

        $this->metrics->setMockBatchedResult([
            'success' => true,
            'count' => 1,
            'data' => [
                ['timestamp' => $now, 'free' => null, 'buffered' => null, 'cached' => null],
            ],
            'period' => '1h',
            'sources' => ['free', 'buffered', 'cached']
        ]);

        $result = $this->metrics->getMemoryFreeMetrics(1);

        $this->assertTrue($result['success']);
        $this->assertNull($result['data'][0]['free_mb']);
        $this->assertNull($result['data'][0]['buffered_mb']);
        $this->assertNull($result['data'][0]['cached_mb']);
        $this->assertNull($result['data'][0]['buffer_cache_mb']);
    }

    // =========================================================================
    // Disk Metrics Transformation Tests
    // =========================================================================

    public function testGetDiskFreeMetricsTransformation(): void
    {
        $now = time();

        // 10GB in bytes
        $tenGbBytes = 10 * 1024 * 1024 * 1024;

        $this->metrics->setMockCollectdResult([
            'success' => true,
            'count' => 2,
            'data' => [
                ['timestamp' => $now - 60, 'value' => $tenGbBytes],
                ['timestamp' => $now, 'value' => null],
            ],
            'period' => '1h',
            'metric' => 'df_complex-free',
            'category' => 'df-root'
        ]);

        $result = $this->metrics->getDiskFreeMetrics(1);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['data']);

        // First entry: 10GB
        $this->assertEquals(10.0, $result['data'][0]['free_gb']);
        $this->assertEquals($tenGbBytes, $result['data'][0]['free_bytes']);

        // Second entry: null
        $this->assertNull($result['data'][1]['free_gb']);
        $this->assertNull($result['data'][1]['free_bytes']);
    }

    public function testGetDiskFreeMetricsFailure(): void
    {
        $this->metrics->setMockCollectdResult([
            'success' => false,
            'error' => 'RRD file not found',
            'data' => []
        ]);

        $result = $this->metrics->getDiskFreeMetrics(1);

        $this->assertFalse($result['success']);
    }

    public function testGetDiskFreeMetricsPrecision(): void
    {
        $now = time();

        // 5.55 GB in bytes
        $bytes = 5.55 * 1024 * 1024 * 1024;

        $this->metrics->setMockCollectdResult([
            'success' => true,
            'count' => 1,
            'data' => [
                ['timestamp' => $now, 'value' => $bytes],
            ],
            'period' => '1h',
            'metric' => 'df_complex-free',
            'category' => 'df-root'
        ]);

        $result = $this->metrics->getDiskFreeMetrics(1);

        $this->assertTrue($result['success']);
        $this->assertEquals(5.55, $result['data'][0]['free_gb']);
    }

    // =========================================================================
    // CPU Average Metrics Transformation Tests
    // =========================================================================

    public function testGetCPUAverageMetricsNoCores(): void
    {
        $this->metrics->setMockCpuCount(0);

        $result = $this->metrics->getCPUAverageMetrics(1);

        $this->assertFalse($result['success']);
        $this->assertEquals('No CPU cores found', $result['error']);
    }

    public function testGetCPUAverageMetricsTransformation(): void
    {
        $now = time();

        $this->metrics->setMockCpuCount(4);
        $this->metrics->setMockBatchedResult([
            'success' => true,
            'count' => 2,
            'data' => [
                ['timestamp' => $now - 60, 'cpu0' => 90.0, 'cpu1' => 92.0, 'cpu2' => 88.0, 'cpu3' => 94.0], // idle values
                ['timestamp' => $now, 'cpu0' => 80.0, 'cpu1' => 85.0, 'cpu2' => 75.0, 'cpu3' => 90.0],
            ],
            'period' => '1h',
            'sources' => ['cpu0', 'cpu1', 'cpu2', 'cpu3']
        ]);

        $result = $this->metrics->getCPUAverageMetrics(1);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['data']);
        $this->assertEquals(4, $result['cpu_cores']);

        // First entry: avg idle = (90+92+88+94)/4 = 91%, so usage = 9%
        $this->assertEquals(91.0, $result['data'][0]['cpu_idle']);
        $this->assertEquals(9.0, $result['data'][0]['cpu_usage']);

        // Second entry: avg idle = (80+85+75+90)/4 = 82.5%, usage = 17.5%
        $this->assertEquals(82.5, $result['data'][1]['cpu_idle']);
        $this->assertEquals(17.5, $result['data'][1]['cpu_usage']);
    }

    public function testGetCPUAverageMetricsWithNullValues(): void
    {
        $now = time();

        $this->metrics->setMockCpuCount(4);
        $this->metrics->setMockBatchedResult([
            'success' => true,
            'count' => 1,
            'data' => [
                ['timestamp' => $now, 'cpu0' => 90.0, 'cpu1' => null, 'cpu2' => 90.0, 'cpu3' => null],
            ],
            'period' => '1h',
            'sources' => ['cpu0', 'cpu1', 'cpu2', 'cpu3']
        ]);

        $result = $this->metrics->getCPUAverageMetrics(1);

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['data']);

        // Only cpu0 and cpu2 have values, avg idle = 90%, usage = 10%
        $this->assertEquals(90.0, $result['data'][0]['cpu_idle']);
        $this->assertEquals(10.0, $result['data'][0]['cpu_usage']);
    }

    public function testGetCPUAverageMetricsAllNullSkipped(): void
    {
        $now = time();

        $this->metrics->setMockCpuCount(4);
        $this->metrics->setMockBatchedResult([
            'success' => true,
            'count' => 2,
            'data' => [
                ['timestamp' => $now - 60, 'cpu0' => null, 'cpu1' => null, 'cpu2' => null, 'cpu3' => null],
                ['timestamp' => $now, 'cpu0' => 85.0, 'cpu1' => 85.0, 'cpu2' => 85.0, 'cpu3' => 85.0],
            ],
            'period' => '1h',
            'sources' => ['cpu0', 'cpu1', 'cpu2', 'cpu3']
        ]);

        $result = $this->metrics->getCPUAverageMetrics(1);

        $this->assertTrue($result['success']);
        // First entry should be skipped because all values are null
        $this->assertCount(1, $result['data']);
        $this->assertEquals(85.0, $result['data'][0]['cpu_idle']);
    }

    public function testGetCPUAverageMetricsFailure(): void
    {
        $this->metrics->setMockCpuCount(4);
        $this->metrics->setMockBatchedResult([
            'success' => false,
            'error' => 'No CPU data available',
            'data' => []
        ]);

        $result = $this->metrics->getCPUAverageMetrics(1);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No CPU data available', $result['error']);
    }

    // =========================================================================
    // Network Bandwidth Transformation Tests
    // =========================================================================

    public function testGetInterfaceBandwidthMetricsTransformation(): void
    {
        $now = time();

        // 1 MB/s = 1024 * 1024 bytes/s = 8192 kbps
        $oneMbps = 1024 * 1024;

        $this->metrics->setMockCollectdResult([
            'success' => true,
            'count' => 2,
            'data' => [
                ['timestamp' => $now - 60, 'rx' => $oneMbps, 'tx' => $oneMbps / 2],
                ['timestamp' => $now, 'rx' => null, 'tx' => 128000], // ~1000 kbps
            ],
            'period' => '1h',
            'metric' => 'if_octets',
            'category' => 'interface-eth0'
        ]);

        $result = $this->metrics->getInterfaceBandwidthMetrics('eth0', 1);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['data']);
        $this->assertEquals('eth0', $result['interface']);

        // First entry: rx = 1 MB/s * 8 / 1024 = 8192 kbps
        $this->assertEquals(8192.0, $result['data'][0]['rx_kbps']);
        $this->assertEquals(4096.0, $result['data'][0]['tx_kbps']);
        $this->assertEquals($oneMbps, $result['data'][0]['rx_bytes']);
        $this->assertEquals($oneMbps / 2, $result['data'][0]['tx_bytes']);

        // Second entry: null rx
        $this->assertNull($result['data'][1]['rx_kbps']);
        $this->assertEquals(1000.0, $result['data'][1]['tx_kbps']);
    }

    public function testGetInterfaceBandwidthMetricsFailure(): void
    {
        $this->metrics->setMockCollectdResult([
            'success' => false,
            'error' => 'RRD file not found',
            'data' => []
        ]);

        $result = $this->metrics->getInterfaceBandwidthMetrics('eth0', 1);

        $this->assertFalse($result['success']);
    }

    public function testGetInterfaceBandwidthMetricsDifferentInterface(): void
    {
        $now = time();

        $this->metrics->setMockCollectdResult([
            'success' => true,
            'count' => 1,
            'data' => [
                ['timestamp' => $now, 'rx' => 1000, 'tx' => 500],
            ],
            'period' => '1h',
            'metric' => 'if_octets',
            'category' => 'interface-wlan0'
        ]);

        $result = $this->metrics->getInterfaceBandwidthMetrics('wlan0', 1);

        $this->assertTrue($result['success']);
        $this->assertEquals('wlan0', $result['interface']);
    }

    // =========================================================================
    // Load Average Transformation Tests
    // =========================================================================

    public function testGetLoadAverageMetricsTransformation(): void
    {
        $now = time();

        $this->metrics->setMockCollectdResult([
            'success' => true,
            'count' => 2,
            'data' => [
                ['timestamp' => $now - 60, 'shortterm' => 0.5, 'midterm' => 0.75, 'longterm' => 1.0],
                ['timestamp' => $now, 'shortterm' => 1.234567, 'midterm' => null, 'longterm' => 2.999999],
            ],
            'period' => '1h',
            'metric' => 'load',
            'category' => 'load'
        ]);

        $result = $this->metrics->getLoadAverageMetrics(1);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['data']);

        // First entry
        $this->assertEquals(0.5, $result['data'][0]['shortterm']);
        $this->assertEquals(0.75, $result['data'][0]['midterm']);
        $this->assertEquals(1.0, $result['data'][0]['longterm']);

        // Second entry - rounding to 2 decimal places
        $this->assertEquals(1.23, $result['data'][1]['shortterm']);
        $this->assertNull($result['data'][1]['midterm']);
        $this->assertEquals(3.0, $result['data'][1]['longterm']);
    }

    public function testGetLoadAverageMetricsFailure(): void
    {
        $this->metrics->setMockCollectdResult([
            'success' => false,
            'error' => 'RRD file not found',
            'data' => []
        ]);

        $result = $this->metrics->getLoadAverageMetrics(1);

        $this->assertFalse($result['success']);
    }

    // =========================================================================
    // Thermal Metrics Transformation Tests
    // =========================================================================

    public function testGetThermalMetricsNoZones(): void
    {
        $this->metrics->setMockThermalZones([]);

        $result = $this->metrics->getThermalMetrics(1);

        $this->assertFalse($result['success']);
        $this->assertEquals('No thermal zones found', $result['error']);
    }

    public function testGetThermalMetricsTransformation(): void
    {
        $now = time();

        $this->metrics->setMockThermalZones(['thermal_zone0', 'thermal_zone1']);
        $this->metrics->setMockBatchedResult([
            'success' => true,
            'count' => 2,
            'data' => [
                ['timestamp' => $now - 60, 'thermal_zone0' => 45.5, 'thermal_zone1' => 50.3],
                ['timestamp' => $now, 'thermal_zone0' => 48.789, 'thermal_zone1' => null],
            ],
            'period' => '1h',
            'sources' => ['thermal_zone0', 'thermal_zone1']
        ]);

        $result = $this->metrics->getThermalMetrics(1);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['data']);
        $this->assertContains('thermal_zone0', $result['zones']);
        $this->assertContains('thermal_zone1', $result['zones']);

        // Check rounding
        $this->assertEquals(45.5, $result['data'][0]['thermal_zone0']);
        $this->assertEquals(50.3, $result['data'][0]['thermal_zone1']);
        $this->assertEquals(48.79, $result['data'][1]['thermal_zone0']);
        $this->assertNull($result['data'][1]['thermal_zone1']);
    }

    public function testGetThermalMetricsAllNullSkipped(): void
    {
        $now = time();

        $this->metrics->setMockThermalZones(['thermal_zone0', 'thermal_zone1']);
        $this->metrics->setMockBatchedResult([
            'success' => true,
            'count' => 2,
            'data' => [
                ['timestamp' => $now - 60, 'thermal_zone0' => null, 'thermal_zone1' => null],
                ['timestamp' => $now, 'thermal_zone0' => 45.0, 'thermal_zone1' => 50.0],
            ],
            'period' => '1h',
            'sources' => ['thermal_zone0', 'thermal_zone1']
        ]);

        $result = $this->metrics->getThermalMetrics(1);

        $this->assertTrue($result['success']);
        // First entry with all nulls should be skipped
        $this->assertCount(1, $result['data']);
    }

    public function testGetThermalMetricsFailure(): void
    {
        $this->metrics->setMockThermalZones(['thermal_zone0']);
        $this->metrics->setMockBatchedResult([
            'success' => false,
            'error' => 'No thermal data available',
            'data' => []
        ]);

        $result = $this->metrics->getThermalMetrics(1);

        $this->assertFalse($result['success']);
    }

    // =========================================================================
    // formatThermalZoneType Tests
    // =========================================================================

    /**
     * @dataProvider thermalZoneTypeProvider
     */
    public function testFormatThermalZoneType(string $input, string $expected): void
    {
        $result = $this->metrics->formatThermalZoneType($input);
        $this->assertEquals($expected, $result);
    }

    public static function thermalZoneTypeProvider(): array
    {
        return [
            'simple cpu' => ['cpu', 'CPU'],
            'with underscore' => ['cpu_thermal', 'CPU Thermal'],
            'with hyphen' => ['cpu-thermal', 'CPU Thermal'],
            'gpu type' => ['gpu', 'GPU'],
            'soc type' => ['soc', 'SoC'],
            'acpi type' => ['acpi', 'ACPI'],
            'pch type' => ['pch', 'PCH'],
            'mixed case' => ['CPU_thermal', 'CPU Thermal'],
            'complex name' => ['soc_gpu_thermal', 'SoC GPU Thermal'],
            'no abbreviation' => ['thermal_zone', 'Thermal Zone'],
            'acpi_zone' => ['acpi_zone', 'ACPI Zone'],
            'pch_thermal' => ['pch_thermal', 'PCH Thermal'],
        ];
    }

    // =========================================================================
    // getThermalZoneFriendlyNames Tests
    // =========================================================================

    public function testGetThermalZoneFriendlyNamesFromCache(): void
    {
        $dataDir = $this->createTempDir('data');
        $cacheFile = $dataDir . '/thermal_zone_names.json';

        $cachedNames = [
            'thermal_zone0' => 'cpu-thermal',
            'thermal_zone1' => 'gpu-thermal',
        ];
        file_put_contents($cacheFile, json_encode($cachedNames));

        $reflection = new \ReflectionClass(SystemMetrics::class);
        $prop = $reflection->getProperty('dataDir');
        $prop->setAccessible(true);
        $prop->setValue($this->metrics, $dataDir);

        $result = $this->metrics->getThermalZoneFriendlyNames();

        $this->assertEquals($cachedNames, $result);
    }

    public function testGetThermalZoneFriendlyNamesEmptyCacheReadsFromSysfs(): void
    {
        $dataDir = $this->createTempDir('data2');
        $cacheFile = $dataDir . '/thermal_zone_names.json';

        // Create empty cache
        file_put_contents($cacheFile, '{}');

        $reflection = new \ReflectionClass(SystemMetrics::class);
        $prop = $reflection->getProperty('dataDir');
        $prop->setAccessible(true);
        $prop->setValue($this->metrics, $dataDir);

        // This will try to read from /sys/class/thermal which may not exist in test env
        $result = $this->metrics->getThermalZoneFriendlyNames();

        // Should be array (possibly empty if no thermal zones in test env)
        $this->assertIsArray($result);
    }

    public function testGetThermalZoneFriendlyNamesInvalidCacheJson(): void
    {
        $dataDir = $this->createTempDir('data3');
        $cacheFile = $dataDir . '/thermal_zone_names.json';

        // Create invalid JSON cache
        file_put_contents($cacheFile, 'not valid json');

        $reflection = new \ReflectionClass(SystemMetrics::class);
        $prop = $reflection->getProperty('dataDir');
        $prop->setAccessible(true);
        $prop->setValue($this->metrics, $dataDir);

        // Should fall back to reading from sysfs
        $result = $this->metrics->getThermalZoneFriendlyNames();
        $this->assertIsArray($result);
    }

    // =========================================================================
    // Wireless Metrics Tests
    // =========================================================================

    public function testGetWirelessMetricsNoInterfaces(): void
    {
        $this->metrics->setMockWirelessInterfaces([]);

        $result = $this->metrics->getWirelessMetrics(1);

        $this->assertFalse($result['success']);
        $this->assertEquals('No wireless interfaces found', $result['error']);
    }

    public function testGetWirelessMetricsTransformation(): void
    {
        $now = time();

        $this->metrics->setMockWirelessInterfaces(['wlan0']);
        $this->metrics->setMockBatchedResult([
            'success' => true,
            'count' => 2,
            'data' => [
                [
                    'timestamp' => $now - 60,
                    'wlan0_signal_quality' => 75.5,
                    'wlan0_signal_power' => -45.3,
                    'wlan0_signal_noise' => -95.0
                ],
                [
                    'timestamp' => $now,
                    'wlan0_signal_quality' => 80.123,
                    'wlan0_signal_power' => null,
                    'wlan0_signal_noise' => -92.0
                ],
            ],
            'period' => '1h',
            'sources' => ['wlan0_signal_quality', 'wlan0_signal_power', 'wlan0_signal_noise']
        ]);

        $result = $this->metrics->getWirelessMetrics(1);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['data']);
        $this->assertContains('wlan0', $result['interfaces']);

        // Check rounding
        $this->assertEquals(75.5, $result['data'][0]['wlan0_signal_quality']);
        $this->assertEquals(-45.3, $result['data'][0]['wlan0_signal_power']);
        $this->assertEquals(-95.0, $result['data'][0]['wlan0_signal_noise']);

        $this->assertEquals(80.12, $result['data'][1]['wlan0_signal_quality']);
        $this->assertNull($result['data'][1]['wlan0_signal_power']);
    }

    public function testGetWirelessMetricsMultipleInterfaces(): void
    {
        $now = time();

        $this->metrics->setMockWirelessInterfaces(['wlan0', 'wlan1']);
        $this->metrics->setMockBatchedResult([
            'success' => true,
            'count' => 1,
            'data' => [
                [
                    'timestamp' => $now,
                    'wlan0_signal_quality' => 70.0,
                    'wlan0_signal_power' => -50.0,
                    'wlan0_signal_noise' => -90.0,
                    'wlan1_signal_quality' => 65.0,
                    'wlan1_signal_power' => -55.0,
                    'wlan1_signal_noise' => -88.0,
                ],
            ],
            'period' => '1h',
            'sources' => [
                'wlan0_signal_quality', 'wlan0_signal_power', 'wlan0_signal_noise',
                'wlan1_signal_quality', 'wlan1_signal_power', 'wlan1_signal_noise'
            ]
        ]);

        $result = $this->metrics->getWirelessMetrics(1);

        $this->assertTrue($result['success']);
        $this->assertContains('wlan0', $result['interfaces']);
        $this->assertContains('wlan1', $result['interfaces']);

        $this->assertEquals(70.0, $result['data'][0]['wlan0_signal_quality']);
        $this->assertEquals(65.0, $result['data'][0]['wlan1_signal_quality']);
    }

    public function testGetWirelessMetricsAllNullSkipped(): void
    {
        $now = time();

        $this->metrics->setMockWirelessInterfaces(['wlan0']);
        $this->metrics->setMockBatchedResult([
            'success' => true,
            'count' => 2,
            'data' => [
                [
                    'timestamp' => $now - 60,
                    'wlan0_signal_quality' => null,
                    'wlan0_signal_power' => null,
                    'wlan0_signal_noise' => null
                ],
                [
                    'timestamp' => $now,
                    'wlan0_signal_quality' => 75.0,
                    'wlan0_signal_power' => -45.0,
                    'wlan0_signal_noise' => -90.0
                ],
            ],
            'period' => '1h',
            'sources' => ['wlan0_signal_quality', 'wlan0_signal_power', 'wlan0_signal_noise']
        ]);

        $result = $this->metrics->getWirelessMetrics(1);

        $this->assertTrue($result['success']);
        // First entry with all nulls should be skipped
        $this->assertCount(1, $result['data']);
    }

    public function testGetWirelessMetricsFailure(): void
    {
        $this->metrics->setMockWirelessInterfaces(['wlan0']);
        $this->metrics->setMockBatchedResult([
            'success' => false,
            'error' => 'No wireless data available',
            'data' => []
        ]);

        $result = $this->metrics->getWirelessMetrics(1);

        $this->assertFalse($result['success']);
    }

    public function testGetWirelessMetricsAvailableMetricsExtraction(): void
    {
        $now = time();

        $this->metrics->setMockWirelessInterfaces(['wlan0']);
        $this->metrics->setMockBatchedResult([
            'success' => true,
            'count' => 1,
            'data' => [
                [
                    'timestamp' => $now,
                    'wlan0_signal_quality' => 70.0,
                    'wlan0_signal_power' => -50.0,
                ],
            ],
            'period' => '1h',
            'sources' => ['wlan0_signal_quality', 'wlan0_signal_power']
        ]);

        $result = $this->metrics->getWirelessMetrics(1);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('available_metrics', $result);
        $this->assertArrayHasKey('wlan0', $result['available_metrics']);
        $this->assertContains('signal_quality', $result['available_metrics']['wlan0']);
        $this->assertContains('signal_power', $result['available_metrics']['wlan0']);
    }

    // =========================================================================
    // Edge Case Tests
    // =========================================================================

    public function testGetPingMetricsDefaultHours(): void
    {
        $pingFile = $this->createTempDir('data/ping5') . '/metrics.log';
        $now = time();

        $entries = [
            ['timestamp' => $now - 3600, 'host' => '192.168.1.1', 'latency' => 25.0, 'status' => 'success'],
        ];
        $this->writeJsonLinesFile($pingFile, $entries);

        $reflection = new \ReflectionClass(SystemMetrics::class);
        $prop = $reflection->getProperty('pingMetricsFile');
        $prop->setAccessible(true);
        $prop->setValue($this->metrics, $pingFile);

        // Default is 24 hours
        $result = $this->metrics->getPingMetrics();

        $this->assertTrue($result['success']);
        $this->assertEquals('24h', $result['period']);
    }

    public function testGetMemoryFreeMetricsDefaultHours(): void
    {
        $now = time();

        $this->metrics->setMockBatchedResult([
            'success' => true,
            'count' => 1,
            'data' => [
                ['timestamp' => $now, 'free' => 1073741824, 'buffered' => 268435456, 'cached' => 536870912],
            ],
            'period' => '24h',
            'sources' => ['free', 'buffered', 'cached']
        ]);

        // Default is 24 hours
        $result = $this->metrics->getMemoryFreeMetrics();

        $this->assertTrue($result['success']);
        $this->assertEquals('24h', $result['period']);
    }

    public function testGetDiskFreeMetricsZeroBytes(): void
    {
        $now = time();

        $this->metrics->setMockCollectdResult([
            'success' => true,
            'count' => 1,
            'data' => [
                ['timestamp' => $now, 'value' => 0],
            ],
            'period' => '1h',
            'metric' => 'df_complex-free',
            'category' => 'df-root'
        ]);

        $result = $this->metrics->getDiskFreeMetrics(1);

        $this->assertTrue($result['success']);
        $this->assertEquals(0.0, $result['data'][0]['free_gb']);
        $this->assertEquals(0, $result['data'][0]['free_bytes']);
    }

    public function testGetLoadAverageMetricsZeroLoad(): void
    {
        $now = time();

        $this->metrics->setMockCollectdResult([
            'success' => true,
            'count' => 1,
            'data' => [
                ['timestamp' => $now, 'shortterm' => 0.0, 'midterm' => 0.0, 'longterm' => 0.0],
            ],
            'period' => '1h',
            'metric' => 'load',
            'category' => 'load'
        ]);

        $result = $this->metrics->getLoadAverageMetrics(1);

        $this->assertTrue($result['success']);
        $this->assertEquals(0.0, $result['data'][0]['shortterm']);
        $this->assertEquals(0.0, $result['data'][0]['midterm']);
        $this->assertEquals(0.0, $result['data'][0]['longterm']);
    }

    public function testGetCPUAverageMetrics100PercentUsage(): void
    {
        $now = time();

        $this->metrics->setMockCpuCount(2);
        $this->metrics->setMockBatchedResult([
            'success' => true,
            'count' => 1,
            'data' => [
                ['timestamp' => $now, 'cpu0' => 0.0, 'cpu1' => 0.0], // 0% idle = 100% usage
            ],
            'period' => '1h',
            'sources' => ['cpu0', 'cpu1']
        ]);

        $result = $this->metrics->getCPUAverageMetrics(1);

        $this->assertTrue($result['success']);
        $this->assertEquals(0.0, $result['data'][0]['cpu_idle']);
        $this->assertEquals(100.0, $result['data'][0]['cpu_usage']);
    }

    public function testGetInterfaceBandwidthMetricsZeroBandwidth(): void
    {
        $now = time();

        $this->metrics->setMockCollectdResult([
            'success' => true,
            'count' => 1,
            'data' => [
                ['timestamp' => $now, 'rx' => 0, 'tx' => 0],
            ],
            'period' => '1h',
            'metric' => 'if_octets',
            'category' => 'interface-eth0'
        ]);

        $result = $this->metrics->getInterfaceBandwidthMetrics('eth0', 1);

        $this->assertTrue($result['success']);
        $this->assertEquals(0.0, $result['data'][0]['rx_kbps']);
        $this->assertEquals(0.0, $result['data'][0]['tx_kbps']);
    }

    // =========================================================================
    // Constants and Configuration Tests
    // =========================================================================

    public function testCollectdHostnameConstant(): void
    {
        // Access private constant via reflection
        $reflection = new \ReflectionClass(SystemMetrics::class);
        $constants = $reflection->getConstants();

        $this->assertArrayHasKey('COLLECTD_HOSTNAME', $constants);
        $this->assertEquals('fpplocal', $constants['COLLECTD_HOSTNAME']);
    }
}
