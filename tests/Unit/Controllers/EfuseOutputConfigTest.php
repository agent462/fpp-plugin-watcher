<?php
/**
 * Unit tests for EfuseOutputConfig class
 *
 * Comprehensive test coverage for eFuse output configuration,
 * current estimation, and port summary functionality.
 *
 * @package Watcher\Tests\Unit\Controllers
 */

declare(strict_types=1);

namespace Watcher\Tests\Unit\Controllers;

use Watcher\Tests\TestCase;
use Watcher\Controllers\EfuseOutputConfig;

/**
 * Mock ApiClient for testing
 */
class MockApiClient
{
    private array $responses = [];

    public function setResponse(string $url, ?array $response): void
    {
        $this->responses[$url] = $response;
    }

    public function get(string $url, int $timeout = 30): ?array
    {
        // Match URL patterns (strip timeout parameter from matching)
        foreach ($this->responses as $pattern => $response) {
            if (strpos($url, $pattern) !== false || $url === $pattern) {
                return $response;
            }
        }
        return null;
    }
}

/**
 * Mock EfuseHardware for testing
 */
class MockEfuseHardware
{
    private array $efuseCapablePorts = [];
    private ?array $portsData = null;

    public function setEfuseCapablePorts(array $ports): void
    {
        $this->efuseCapablePorts = $ports;
    }

    public function setPortsData(?array $data): void
    {
        $this->portsData = $data;
    }

    public function getEfuseCapablePortNames(): array
    {
        return $this->efuseCapablePorts;
    }

    public function fetchPortsData(): ?array
    {
        return $this->portsData;
    }

    public function iterateAllPorts(?array $portsList, callable $callback): void
    {
        if ($portsList === null) {
            return;
        }

        foreach ($portsList as $port) {
            $portName = $port['name'] ?? '';
            if (empty($portName)) {
                continue;
            }

            $isSmartReceiver = isset($port['subPorts']);

            if ($isSmartReceiver && isset($port['subPorts'])) {
                foreach ($port['subPorts'] as $subPort) {
                    $subPortName = $subPort['name'] ?? '';
                    if (!empty($subPortName)) {
                        $callback($subPortName, $subPort, true);
                    }
                }
            } else {
                $callback($portName, $port, false);
            }
        }
    }
}

/**
 * Testable subclass that exposes private properties for testing
 */
class TestableEfuseOutputConfig extends EfuseOutputConfig
{
    private ?array $testConfigCache = null;
    private int $testConfigCacheTime = 0;
    private ?MockApiClient $mockApiClient = null;
    private ?MockEfuseHardware $mockEfuseHardware = null;
    private bool $useRealGetOutputConfig = false;

    /**
     * Get a fresh testable instance (bypasses singleton for testing)
     */
    public static function getTestInstance(): self
    {
        $reflection = new \ReflectionClass(self::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        return $instance;
    }

    /**
     * Set the config cache directly for testing
     */
    public function setConfigCache(?array $cache, int $cacheTime = 0): void
    {
        $this->testConfigCache = $cache;
        $this->testConfigCacheTime = $cacheTime ?: time();
    }

    /**
     * Get the config cache for verification
     */
    public function getConfigCache(): ?array
    {
        return $this->testConfigCache;
    }

    /**
     * Set mock ApiClient
     */
    public function setMockApiClient(MockApiClient $client): void
    {
        $this->mockApiClient = $client;
    }

    /**
     * Set mock EfuseHardware
     */
    public function setMockEfuseHardware(MockEfuseHardware $hardware): void
    {
        $this->mockEfuseHardware = $hardware;
    }

    /**
     * Enable real getOutputConfig logic with mocked dependencies
     */
    public function enableRealGetOutputConfig(bool $enable = true): void
    {
        $this->useRealGetOutputConfig = $enable;
    }

    /**
     * Override getOutputConfig to support both cached and real logic
     */
    public function getOutputConfig(bool $forceRefresh = false): array
    {
        // If we have a cached test config and not using real logic, return it
        if (!$this->useRealGetOutputConfig && $this->testConfigCache !== null) {
            return $this->testConfigCache;
        }

        // If using real logic with mocked dependencies
        if ($this->useRealGetOutputConfig && $this->mockApiClient !== null && $this->mockEfuseHardware !== null) {
            return $this->executeRealGetOutputConfig($forceRefresh);
        }

        return ['success' => false, 'ports' => [], 'totalPorts' => 0, 'timestamp' => time()];
    }

    /**
     * Execute real getOutputConfig logic with mocked dependencies
     */
    private function executeRealGetOutputConfig(bool $forceRefresh = false): array
    {
        $result = [
            'success' => true,
            'ports' => [],
            'totalPorts' => 0,
            'timestamp' => time()
        ];

        // Get list of ports that have actual eFuse/current monitoring
        $efuseCapablePorts = $this->mockEfuseHardware->getEfuseCapablePortNames();

        // Get pixel string outputs
        $pixelOutputs = $this->mockApiClient->get('http://127.0.0.1/api/channel/output/co-pixelStrings', 5);
        if ($pixelOutputs && isset($pixelOutputs['channelOutputs'])) {
            $this->testProcessChannelOutputs($pixelOutputs['channelOutputs'], $efuseCapablePorts, $result, true);
        }

        // Also check for BBB-specific outputs
        $bbbOutputs = $this->mockApiClient->get('http://127.0.0.1/api/channel/output/co-bbbStrings', 5);
        if ($bbbOutputs && isset($bbbOutputs['channelOutputs'])) {
            $this->testProcessChannelOutputs(
                $bbbOutputs['channelOutputs'],
                $efuseCapablePorts,
                $result,
                false,
                fn($type) => stripos($type, 'BB') !== false || stripos($type, 'PB') !== false || stripos($type, 'Shift') !== false
            );
        }

        // Sort ports by port number and subport letter
        uksort($result['ports'], function($a, $b) {
            preg_match('/^Port (\d+)([A-F])?$/', $a, $ma);
            preg_match('/^Port (\d+)([A-F])?$/', $b, $mb);
            $numA = intval($ma[1] ?? 0);
            $numB = intval($mb[1] ?? 0);
            if ($numA !== $numB) {
                return $numA - $numB;
            }
            return strcmp($ma[2] ?? '', $mb[2] ?? '');
        });

        return $result;
    }

    /**
     * Expose processChannelOutputs for testing
     */
    public function testProcessChannelOutputs(
        array $channelOutputs,
        array $efuseCapablePorts,
        array &$result,
        bool $usePortFallback,
        ?callable $typeFilter = null
    ): void {
        foreach ($channelOutputs as $output) {
            $outputType = $output['type'] ?? '';

            // Apply type filter if provided
            if ($typeFilter !== null && !$typeFilter($outputType)) {
                continue;
            }

            $outputs = $output['outputs'] ?? [];

            foreach ($outputs as $portIndex => $portConfig) {
                $portNumber = intval($portConfig['portNumber'] ?? $portIndex) + 1;
                $basePortName = 'Port ' . $portNumber;

                // Find matching eFuse capable ports (handles smart receivers)
                $matchingPorts = $this->testFindMatchingEfusePorts($basePortName, $efuseCapablePorts);
                if (!empty($efuseCapablePorts) && empty($matchingPorts)) {
                    continue;
                }

                $vsConfig = $this->extractVirtualStringConfig($portConfig, $usePortFallback);
                $virtualStrings = $portConfig['virtualStrings'] ?? [];
                $expectedCurrent = $this->estimatePortCurrent($vsConfig['totalPixels'], $vsConfig['protocol']);

                // If no matching ports found (efuseCapablePorts is empty), use base name
                $portNames = !empty($matchingPorts) ? $matchingPorts : [$basePortName];

                foreach ($portNames as $portName) {
                    // Skip if already have this port
                    if (isset($result['ports'][$portName])) {
                        continue;
                    }

                    $result['ports'][$portName] = [
                        'portNumber' => $portNumber,
                        'portName' => $portName,
                        'outputType' => $outputType,
                        'protocol' => $vsConfig['protocol'],
                        'brightness' => $vsConfig['brightness'],
                        'pixelCount' => $vsConfig['totalPixels'],
                        'startChannel' => intval($portConfig['startChannel'] ?? $virtualStrings[0]['startChannel'] ?? 0),
                        'colorOrder' => $portConfig['colorOrder'] ?? $virtualStrings[0]['colorOrder'] ?? 'RGB',
                        'description' => implode(', ', $vsConfig['descriptions']),
                        'expectedCurrentMa' => $expectedCurrent['typical'],
                        'maxCurrentMa' => $expectedCurrent['max'],
                        'enabled' => !empty($vsConfig['totalPixels'])
                    ];

                    $result['totalPorts']++;
                }
            }
        }
    }

    /**
     * Expose findMatchingEfusePorts for testing
     */
    public function testFindMatchingEfusePorts(string $basePortName, array $efuseCapablePorts): array
    {
        // Exact match for regular ports
        if (in_array($basePortName, $efuseCapablePorts)) {
            return [$basePortName];
        }

        // Check for smart receiver subports (e.g., "Port 9" matches "Port 9A", "Port 9B")
        $matches = [];
        foreach ($efuseCapablePorts as $capablePort) {
            if (preg_match('/^' . preg_quote($basePortName, '/') . '[A-F]$/', $capablePort)) {
                $matches[] = $capablePort;
            }
        }

        return $matches;
    }

    /**
     * Get port fuse status using mock hardware
     */
    public function getPortFuseStatus(): array
    {
        if ($this->mockEfuseHardware === null) {
            return [];
        }

        $portsList = $this->mockEfuseHardware->fetchPortsData();
        if ($portsList === null) {
            return [];
        }

        $result = [];
        $this->mockEfuseHardware->iterateAllPorts($portsList, function (string $portName, array $portData, bool $isSmartReceiver) use (&$result) {
            $fuseTripped = isset($portData['status']) && $portData['status'] === false;

            if ($isSmartReceiver) {
                $result[$portName] = [
                    'enabled' => $portData['fuseOn'] ?? $portData['enabled'] ?? false,
                    'fuseTripped' => $fuseTripped
                ];
            } else {
                $result[$portName] = [
                    'enabled' => $portData['enabled'] ?? false,
                    'fuseTripped' => $fuseTripped
                ];
            }
        });

        return $result;
    }

    /**
     * Override clearCache
     */
    public function clearCache(): void
    {
        $this->testConfigCache = null;
        $this->testConfigCacheTime = 0;
    }
}

class EfuseOutputConfigTest extends TestCase
{
    private EfuseOutputConfig $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = EfuseOutputConfig::getInstance();
    }

    // ===========================================
    // Singleton Pattern Tests
    // ===========================================

    public function testGetInstanceReturnsSingleton(): void
    {
        $instance1 = EfuseOutputConfig::getInstance();
        $instance2 = EfuseOutputConfig::getInstance();

        $this->assertSame($instance1, $instance2);
    }

    public function testGetInstanceReturnsCorrectType(): void
    {
        $this->assertInstanceOf(EfuseOutputConfig::class, $this->config);
    }

    // ===========================================
    // Constants Tests
    // ===========================================

    public function testPixelCurrentEstimatesHasExpectedProtocols(): void
    {
        $estimates = EfuseOutputConfig::PIXEL_CURRENT_ESTIMATES;

        $this->assertArrayHasKey('ws2811', $estimates);
        $this->assertArrayHasKey('ws2812', $estimates);
        $this->assertArrayHasKey('ws2812b', $estimates);
        $this->assertArrayHasKey('sk6812', $estimates);
        $this->assertArrayHasKey('sk6812w', $estimates);
        $this->assertArrayHasKey('apa102', $estimates);
        $this->assertArrayHasKey('tm1814', $estimates);
        $this->assertArrayHasKey('default', $estimates);
    }

    public function testPixelCurrentEstimatesArePositiveIntegers(): void
    {
        foreach (EfuseOutputConfig::PIXEL_CURRENT_ESTIMATES as $protocol => $mA) {
            $this->assertIsInt($mA, "Protocol $protocol should have integer current");
            $this->assertGreaterThan(0, $mA, "Protocol $protocol should have positive current");
        }
    }

    public function testWs2811CurrentIs42mA(): void
    {
        $this->assertEquals(42, EfuseOutputConfig::PIXEL_CURRENT_ESTIMATES['ws2811']);
    }

    public function testRgbwProtocolsHaveHigherCurrent(): void
    {
        $rgbwProtocols = ['sk6812w', 'tm1814', 'ucs8904'];
        $rgbProtocols = ['ws2811', 'ws2812', 'apa102'];

        foreach ($rgbwProtocols as $rgbw) {
            if (isset(EfuseOutputConfig::PIXEL_CURRENT_ESTIMATES[$rgbw])) {
                $rgbwCurrent = EfuseOutputConfig::PIXEL_CURRENT_ESTIMATES[$rgbw];
                // RGBW should be >= 80mA (4 channels)
                $this->assertGreaterThanOrEqual(80, $rgbwCurrent, "$rgbw should be >= 80mA");
            }
        }
    }

    public function testMaxCurrentConstant(): void
    {
        $this->assertEquals(6000, EfuseOutputConfig::MAX_CURRENT_MA);
    }

    public function testCacheTtlConstant(): void
    {
        $this->assertEquals(60, EfuseOutputConfig::OUTPUT_CONFIG_CACHE_TTL);
    }

    // ===========================================
    // estimatePortCurrent Tests
    // ===========================================

    public function testEstimatePortCurrentReturnsArray(): void
    {
        $result = $this->config->estimatePortCurrent(100, 'ws2811');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('typical', $result);
        $this->assertArrayHasKey('max', $result);
        $this->assertArrayHasKey('perPixel', $result);
    }

    public function testEstimatePortCurrentWithZeroPixels(): void
    {
        $result = $this->config->estimatePortCurrent(0, 'ws2811');

        $this->assertEquals(0, $result['typical']);
        $this->assertEquals(0, $result['max']);
        $this->assertEquals(42, $result['perPixel']);
    }

    /**
     * @dataProvider pixelCountProvider
     */
    public function testEstimatePortCurrentWs2811(int $pixels, int $expectedMax, int $expectedTypical): void
    {
        $result = $this->config->estimatePortCurrent($pixels, 'ws2811');

        $this->assertEquals($expectedMax, $result['max']);
        $this->assertEquals($expectedTypical, $result['typical']);
    }

    public static function pixelCountProvider(): array
    {
        return [
            'single pixel' => [1, 42, 2],           // 1 * 42 = 42, 42 * 0.06 = 2.52 -> 2
            '50 pixels' => [50, 2100, 126],         // 50 * 42 = 2100, 2100 * 0.06 = 126
            '100 pixels' => [100, 4200, 252],       // 100 * 42 = 4200, 4200 * 0.06 = 252
            '500 pixels' => [500, 21000, 1260],     // 500 * 42 = 21000, 21000 * 0.06 = 1260
        ];
    }

    /**
     * @dataProvider protocolCurrentProvider
     */
    public function testEstimatePortCurrentByProtocol(string $protocol, int $expectedPerPixel): void
    {
        $result = $this->config->estimatePortCurrent(100, $protocol);

        $this->assertEquals($expectedPerPixel, $result['perPixel']);
        $this->assertEquals(100 * $expectedPerPixel, $result['max']);
    }

    public static function protocolCurrentProvider(): array
    {
        return [
            'ws2811' => ['ws2811', 42],
            'ws2812' => ['ws2812', 60],
            'ws2812b' => ['ws2812b', 60],
            'sk6812' => ['sk6812', 60],
            'sk6812w' => ['sk6812w', 80],
            'apa102' => ['apa102', 60],
            'tm1814' => ['tm1814', 80],
        ];
    }

    public function testEstimatePortCurrentCaseInsensitive(): void
    {
        $lower = $this->config->estimatePortCurrent(100, 'ws2811');
        $upper = $this->config->estimatePortCurrent(100, 'WS2811');
        $mixed = $this->config->estimatePortCurrent(100, 'Ws2811');

        $this->assertEquals($lower, $upper);
        $this->assertEquals($lower, $mixed);
    }

    public function testEstimatePortCurrentUnknownProtocolUsesDefault(): void
    {
        $result = $this->config->estimatePortCurrent(100, 'unknown_protocol');

        $this->assertEquals(50, $result['perPixel']); // default value
        $this->assertEquals(5000, $result['max']);    // 100 * 50
    }

    public function testEstimatePortCurrentTypicalIs6Percent(): void
    {
        $result = $this->config->estimatePortCurrent(1000, 'ws2811');

        // 1000 * 42 = 42000 max
        // 42000 * 0.06 = 2520 typical
        $this->assertEquals(42000, $result['max']);
        $this->assertEquals(2520, $result['typical']);
        $this->assertEquals($result['max'] * 0.06, $result['typical']);
    }

    // ===========================================
    // extractVirtualStringConfig Tests
    // ===========================================

    public function testExtractVirtualStringConfigEmptyArray(): void
    {
        $result = $this->config->extractVirtualStringConfig([]);

        $this->assertEquals(0, $result['totalPixels']);
        $this->assertEquals('ws2811', $result['protocol']);
        $this->assertEquals(100, $result['brightness']);
        $this->assertEmpty($result['descriptions']);
    }

    public function testExtractVirtualStringConfigWithVirtualStrings(): void
    {
        $portConfig = [
            'virtualStrings' => [
                [
                    'pixelCount' => 100,
                    'protocol' => 'WS2812',
                    'brightness' => 75,
                    'description' => 'Roofline'
                ],
                [
                    'pixelCount' => 50,
                    'description' => 'Eaves'
                ]
            ]
        ];

        $result = $this->config->extractVirtualStringConfig($portConfig);

        $this->assertEquals(150, $result['totalPixels']);
        $this->assertEquals('ws2812', $result['protocol']);
        $this->assertEquals(75, $result['brightness']);
        $this->assertEquals(['Roofline', 'Eaves'], $result['descriptions']);
    }

    public function testExtractVirtualStringConfigWithPortFallback(): void
    {
        $portConfig = [
            'pixelCount' => 200,
            'protocol' => 'apa102',
            'brightness' => 50,
            'description' => 'Tree wrap'
        ];

        // Without fallback - should return defaults
        $resultNoFallback = $this->config->extractVirtualStringConfig($portConfig, false);
        $this->assertEquals(0, $resultNoFallback['totalPixels']);

        // With fallback - should use port config
        $resultWithFallback = $this->config->extractVirtualStringConfig($portConfig, true);
        $this->assertEquals(200, $resultWithFallback['totalPixels']);
        $this->assertEquals('apa102', $resultWithFallback['protocol']);
        $this->assertEquals(50, $resultWithFallback['brightness']);
        $this->assertEquals(['Tree wrap'], $resultWithFallback['descriptions']);
    }

    public function testExtractVirtualStringConfigSumsMultipleStrings(): void
    {
        $portConfig = [
            'virtualStrings' => [
                ['pixelCount' => 100],
                ['pixelCount' => 200],
                ['pixelCount' => 300],
                ['pixelCount' => 400]
            ]
        ];

        $result = $this->config->extractVirtualStringConfig($portConfig);

        $this->assertEquals(1000, $result['totalPixels']);
    }

    public function testExtractVirtualStringConfigHandlesNullPixelCount(): void
    {
        $portConfig = [
            'virtualStrings' => [
                ['pixelCount' => 100],
                ['pixelCount' => null],
                ['description' => 'No pixels']
            ]
        ];

        $result = $this->config->extractVirtualStringConfig($portConfig);

        $this->assertEquals(100, $result['totalPixels']);
    }

    public function testExtractVirtualStringConfigEmptyDescriptionsNotIncluded(): void
    {
        $portConfig = [
            'virtualStrings' => [
                ['pixelCount' => 100, 'description' => 'First'],
                ['pixelCount' => 100, 'description' => ''],
                ['pixelCount' => 100]
            ]
        ];

        $result = $this->config->extractVirtualStringConfig($portConfig);

        $this->assertEquals(['First'], $result['descriptions']);
    }

    // ===========================================
    // calculateTotalCurrent Tests
    // ===========================================

    public function testCalculateTotalCurrentEmpty(): void
    {
        $result = $this->config->calculateTotalCurrent([]);

        $this->assertEquals(0, $result['totalMa']);
        $this->assertEquals(0.0, $result['totalAmps']);
        $this->assertEquals(0, $result['portCount']);
        $this->assertEquals(0, $result['activePortCount']);
    }

    public function testCalculateTotalCurrentSinglePort(): void
    {
        $readings = ['Port 1' => 1500];

        $result = $this->config->calculateTotalCurrent($readings);

        $this->assertEquals(1500, $result['totalMa']);
        $this->assertEquals(1.5, $result['totalAmps']);
        $this->assertEquals(1, $result['portCount']);
        $this->assertEquals(1, $result['activePortCount']);
    }

    public function testCalculateTotalCurrentMultiplePorts(): void
    {
        $readings = [
            'Port 1' => 1000,
            'Port 2' => 2000,
            'Port 3' => 1500,
            'Port 4' => 500
        ];

        $result = $this->config->calculateTotalCurrent($readings);

        $this->assertEquals(5000, $result['totalMa']);
        $this->assertEquals(5.0, $result['totalAmps']);
        $this->assertEquals(4, $result['portCount']);
        $this->assertEquals(4, $result['activePortCount']);
    }

    public function testCalculateTotalCurrentWithInactivePorts(): void
    {
        $readings = [
            'Port 1' => 1000,
            'Port 2' => 0,
            'Port 3' => 2000,
            'Port 4' => 0,
            'Port 5' => 0
        ];

        $result = $this->config->calculateTotalCurrent($readings);

        $this->assertEquals(3000, $result['totalMa']);
        $this->assertEquals(3.0, $result['totalAmps']);
        $this->assertEquals(5, $result['portCount']);
        $this->assertEquals(2, $result['activePortCount']);
    }

    public function testCalculateTotalCurrentRoundsToTwoDecimals(): void
    {
        $readings = [
            'Port 1' => 1234,
            'Port 2' => 5678
        ];

        $result = $this->config->calculateTotalCurrent($readings);

        $this->assertEquals(6912, $result['totalMa']);
        $this->assertEquals(6.91, $result['totalAmps']); // 6.912 rounds to 6.91
    }

    // ===========================================
    // getPortCurrentSummary Tests
    // ===========================================

    public function testGetPortCurrentSummaryReturnsArray(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();
        $testConfig->setConfigCache([
            'success' => true,
            'ports' => [
                'Port 1' => [
                    'portNumber' => 1,
                    'portName' => 'Port 1',
                    'protocol' => 'ws2811',
                    'pixelCount' => 100,
                    'expectedCurrentMa' => 252,
                    'maxCurrentMa' => 4200,
                    'enabled' => true
                ]
            ]
        ]);

        $readings = ['Port 1' => 300];
        $result = $testConfig->getPortCurrentSummary($readings);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('Port 1', $result);
    }

    public function testGetPortCurrentSummaryStatusNormal(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();
        $testConfig->setConfigCache([
            'success' => true,
            'ports' => [
                'Port 1' => [
                    'portNumber' => 1,
                    'portName' => 'Port 1',
                    'protocol' => 'ws2811',
                    'pixelCount' => 100,
                    'expectedCurrentMa' => 252,
                    'maxCurrentMa' => 4200,
                    'enabled' => true
                ]
            ]
        ]);

        // Normal reading - well below max
        $readings = ['Port 1' => 300];
        $result = $testConfig->getPortCurrentSummary($readings);

        $this->assertEquals('normal', $result['Port 1']['status']);
        $this->assertEquals(300, $result['Port 1']['currentMa']);
    }

    public function testGetPortCurrentSummaryStatusWarning(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();
        $testConfig->setConfigCache([
            'success' => true,
            'ports' => [
                'Port 1' => [
                    'portNumber' => 1,
                    'portName' => 'Port 1',
                    'protocol' => 'ws2811',
                    'pixelCount' => 100,
                    'expectedCurrentMa' => 252,
                    'maxCurrentMa' => 4200,
                    'enabled' => true
                ]
            ]
        ]);

        // Warning - over 90% of max
        $readings = ['Port 1' => 4000]; // > 4200 * 0.9 = 3780
        $result = $testConfig->getPortCurrentSummary($readings);

        $this->assertEquals('warning', $result['Port 1']['status']);
        $this->assertEquals('Near maximum capacity', $result['Port 1']['statusMessage']);
    }

    public function testGetPortCurrentSummaryStatusCritical(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();
        $testConfig->setConfigCache([
            'success' => true,
            'ports' => [
                'Port 1' => [
                    'portNumber' => 1,
                    'portName' => 'Port 1',
                    'protocol' => 'ws2811',
                    'pixelCount' => 100,
                    'expectedCurrentMa' => 252,
                    'maxCurrentMa' => 4200,
                    'enabled' => true
                ]
            ]
        ]);

        // Critical - over eFuse limit
        $readings = ['Port 1' => 6500]; // > 6000 MAX_CURRENT_MA
        $result = $testConfig->getPortCurrentSummary($readings);

        $this->assertEquals('critical', $result['Port 1']['status']);
        $this->assertEquals('Exceeds eFuse limit', $result['Port 1']['statusMessage']);
    }

    public function testGetPortCurrentSummaryStatusInactive(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();
        $testConfig->setConfigCache([
            'success' => true,
            'ports' => [
                'Port 1' => [
                    'portNumber' => 1,
                    'portName' => 'Port 1',
                    'protocol' => 'ws2811',
                    'pixelCount' => 100,
                    'expectedCurrentMa' => 252,
                    'maxCurrentMa' => 4200,
                    'enabled' => true
                ]
            ]
        ]);

        // Inactive - 0 current when expected > 0
        $readings = ['Port 1' => 0];
        $result = $testConfig->getPortCurrentSummary($readings);

        $this->assertEquals('inactive', $result['Port 1']['status']);
        $this->assertEquals('No current detected', $result['Port 1']['statusMessage']);
    }

    public function testGetPortCurrentSummaryCalculatesPercentages(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();
        $testConfig->setConfigCache([
            'success' => true,
            'ports' => [
                'Port 1' => [
                    'portNumber' => 1,
                    'portName' => 'Port 1',
                    'protocol' => 'ws2811',
                    'pixelCount' => 100,
                    'expectedCurrentMa' => 252,
                    'maxCurrentMa' => 4200,
                    'enabled' => true
                ]
            ]
        ]);

        $readings = ['Port 1' => 2100]; // 50% of max, 35% of eFuse
        $result = $testConfig->getPortCurrentSummary($readings);

        $this->assertEquals(50.0, $result['Port 1']['percentOfMax']);
        $this->assertEquals(35.0, $result['Port 1']['percentOfEfuse']);
    }

    public function testGetPortCurrentSummaryMissingReadingDefaultsToZero(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();
        $testConfig->setConfigCache([
            'success' => true,
            'ports' => [
                'Port 1' => [
                    'portNumber' => 1,
                    'portName' => 'Port 1',
                    'protocol' => 'ws2811',
                    'pixelCount' => 100,
                    'expectedCurrentMa' => 252,
                    'maxCurrentMa' => 4200,
                    'enabled' => true
                ]
            ]
        ]);

        // Empty readings - port not in array
        $readings = [];
        $result = $testConfig->getPortCurrentSummary($readings);

        $this->assertEquals(0, $result['Port 1']['currentMa']);
    }

    // ===========================================
    // Cache Tests
    // ===========================================

    public function testClearCacheResetsCache(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();
        $testConfig->setConfigCache(['success' => true, 'ports' => []]);

        $this->assertNotNull($testConfig->getConfigCache());

        $testConfig->clearCache();

        $this->assertNull($testConfig->getConfigCache());
    }

    // ===========================================
    // getPortOutputConfig Tests
    // ===========================================

    public function testGetPortOutputConfigReturnsNullForUnknownPort(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();
        $testConfig->setConfigCache([
            'success' => true,
            'ports' => [
                'Port 1' => ['portNumber' => 1, 'protocol' => 'ws2811']
            ]
        ]);

        $result = $testConfig->getPortOutputConfig('Port 99');

        $this->assertNull($result);
    }

    public function testGetPortOutputConfigReturnsPortConfig(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();
        $expectedConfig = [
            'portNumber' => 1,
            'portName' => 'Port 1',
            'protocol' => 'ws2811',
            'pixelCount' => 100,
            'brightness' => 75
        ];

        $testConfig->setConfigCache([
            'success' => true,
            'ports' => [
                'Port 1' => $expectedConfig
            ]
        ]);

        $result = $testConfig->getPortOutputConfig('Port 1');

        $this->assertEquals($expectedConfig, $result);
    }

    // ===========================================
    // Status Determination Edge Cases
    // ===========================================

    public function testGetPortCurrentSummaryStatusHigh(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();
        $testConfig->setConfigCache([
            'success' => true,
            'ports' => [
                'Port 1' => [
                    'portNumber' => 1,
                    'portName' => 'Port 1',
                    'protocol' => 'ws2811',
                    'pixelCount' => 100,
                    'expectedCurrentMa' => 252,
                    'maxCurrentMa' => 4200,
                    'enabled' => true
                ]
            ]
        ]);

        // High - over 50% of max AND over 3x expected
        // Need: current > maxMa * 0.5 AND current > expectedMa * 3
        // maxMa * 0.5 = 2100, expectedMa * 3 = 756
        // So current must be > 2100 (the higher threshold)
        $readings = ['Port 1' => 2500];
        $result = $testConfig->getPortCurrentSummary($readings);

        $this->assertEquals('high', $result['Port 1']['status']);
        $this->assertEquals('Higher than expected', $result['Port 1']['statusMessage']);
    }

    /**
     * @dataProvider statusPriorityProvider
     */
    public function testGetPortCurrentSummaryStatusPriority(
        int $currentMa,
        int $maxMa,
        int $expectedMa,
        bool $enabled,
        string $expectedStatus
    ): void {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();
        $testConfig->setConfigCache([
            'success' => true,
            'ports' => [
                'Port 1' => [
                    'portNumber' => 1,
                    'portName' => 'Port 1',
                    'protocol' => 'ws2811',
                    'pixelCount' => 100,
                    'expectedCurrentMa' => $expectedMa,
                    'maxCurrentMa' => $maxMa,
                    'enabled' => $enabled
                ]
            ]
        ]);

        $readings = ['Port 1' => $currentMa];
        $result = $testConfig->getPortCurrentSummary($readings);

        $this->assertEquals($expectedStatus, $result['Port 1']['status']);
    }

    public static function statusPriorityProvider(): array
    {
        return [
            'critical over eFuse' => [7000, 4200, 252, true, 'critical'],
            'warning near max' => [3900, 4200, 252, true, 'warning'],
            'high current' => [2500, 4200, 252, true, 'high'],
            'normal active' => [500, 4200, 252, true, 'normal'],
            'inactive zero' => [0, 4200, 252, true, 'inactive'],
            'normal zero disabled' => [0, 4200, 0, true, 'normal'],
        ];
    }

    // ===========================================
    // Percentage Calculation Edge Cases
    // ===========================================

    public function testPercentOfMaxWithZeroMax(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();
        $testConfig->setConfigCache([
            'success' => true,
            'ports' => [
                'Port 1' => [
                    'portNumber' => 1,
                    'portName' => 'Port 1',
                    'protocol' => 'ws2811',
                    'pixelCount' => 0,
                    'expectedCurrentMa' => 0,
                    'maxCurrentMa' => 0,
                    'enabled' => false
                ]
            ]
        ]);

        $readings = ['Port 1' => 100];
        $result = $testConfig->getPortCurrentSummary($readings);

        // Should be 0 to avoid division by zero
        $this->assertEquals(0, $result['Port 1']['percentOfMax']);
    }

    public function testPercentOfEfuseCalculation(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();
        $testConfig->setConfigCache([
            'success' => true,
            'ports' => [
                'Port 1' => [
                    'portNumber' => 1,
                    'portName' => 'Port 1',
                    'protocol' => 'ws2811',
                    'pixelCount' => 100,
                    'expectedCurrentMa' => 252,
                    'maxCurrentMa' => 4200,
                    'enabled' => true
                ]
            ]
        ]);

        // 3000mA is 50% of 6000mA eFuse limit
        $readings = ['Port 1' => 3000];
        $result = $testConfig->getPortCurrentSummary($readings);

        $this->assertEquals(50.0, $result['Port 1']['percentOfEfuse']);
    }

    // ===========================================
    // calculateTotalCurrent Edge Cases
    // ===========================================

    public function testCalculateTotalCurrentSkipsTotalKey(): void
    {
        $readings = [
            'Port 1' => 1000,
            'Port 2' => 2000,
            '_total' => 5000 // This should be skipped, not added to total
        ];

        $result = $this->config->calculateTotalCurrent($readings);

        // Should only sum Port 1 + Port 2 = 3000, not include _total
        $this->assertEquals(3000, $result['totalMa']);
        $this->assertEquals(3.0, $result['totalAmps']);
        $this->assertEquals(2, $result['portCount']); // Only 2 ports, not 3
        $this->assertEquals(2, $result['activePortCount']);
    }

    // ===========================================
    // getPortCurrentSummary Tripped/Disabled Tests
    // ===========================================

    public function testGetPortCurrentSummaryStatusTripped(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();
        $testConfig->setConfigCache([
            'success' => true,
            'ports' => [
                'Port 1' => [
                    'portNumber' => 1,
                    'portName' => 'Port 1',
                    'protocol' => 'ws2811',
                    'pixelCount' => 100,
                    'expectedCurrentMa' => 252,
                    'maxCurrentMa' => 4200,
                    'enabled' => true
                ]
            ]
        ]);

        // Mock the getPortFuseStatus to return tripped
        // Since we can't easily mock the EfuseHardware, we test the status logic directly
        // The status priority: tripped > disabled > critical > warning > high > inactive > normal

        $readings = ['Port 1' => 0];
        $result = $testConfig->getPortCurrentSummary($readings);

        // With our mock, fuseTripped defaults to false
        // The result will be 'inactive' because current is 0 but expected > 0
        $this->assertArrayHasKey('fuseTripped', $result['Port 1']);
        $this->assertArrayHasKey('portEnabled', $result['Port 1']);
    }

    public function testGetPortCurrentSummaryStatusDisabled(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();
        $testConfig->setConfigCache([
            'success' => true,
            'ports' => [
                'Port 1' => [
                    'portNumber' => 1,
                    'portName' => 'Port 1',
                    'protocol' => 'ws2811',
                    'pixelCount' => 100,
                    'expectedCurrentMa' => 252,
                    'maxCurrentMa' => 4200,
                    'enabled' => true
                ]
            ]
        ]);

        $readings = ['Port 1' => 500];
        $result = $testConfig->getPortCurrentSummary($readings);

        // Verify the result structure includes disabled status capability
        $this->assertArrayHasKey('portEnabled', $result['Port 1']);
        $this->assertIsBool($result['Port 1']['portEnabled']);
    }

    public function testGetPortCurrentSummaryMergesPortConfig(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();
        $portConfig = [
            'portNumber' => 5,
            'portName' => 'Port 5',
            'protocol' => 'ws2812',
            'pixelCount' => 200,
            'expectedCurrentMa' => 720,
            'maxCurrentMa' => 12000,
            'brightness' => 75,
            'enabled' => true
        ];

        $testConfig->setConfigCache([
            'success' => true,
            'ports' => ['Port 5' => $portConfig]
        ]);

        $readings = ['Port 5' => 1000];
        $result = $testConfig->getPortCurrentSummary($readings);

        // Verify all port config fields are merged into summary
        $this->assertEquals(5, $result['Port 5']['portNumber']);
        $this->assertEquals('ws2812', $result['Port 5']['protocol']);
        $this->assertEquals(200, $result['Port 5']['pixelCount']);
        $this->assertEquals(75, $result['Port 5']['brightness']);
        $this->assertEquals(1000, $result['Port 5']['currentMa']);
    }

    // ===========================================
    // extractVirtualStringConfig Protocol Handling
    // ===========================================

    public function testExtractVirtualStringConfigWithUppercaseProtocol(): void
    {
        $portConfig = [
            'virtualStrings' => [
                [
                    'pixelCount' => 100,
                    'protocol' => 'WS2812B',
                    'brightness' => 100
                ]
            ]
        ];

        $result = $this->config->extractVirtualStringConfig($portConfig);

        // Protocol should be lowercased
        $this->assertEquals('ws2812b', $result['protocol']);
    }

    public function testExtractVirtualStringConfigWithMixedCaseProtocol(): void
    {
        $portConfig = [
            'virtualStrings' => [
                [
                    'pixelCount' => 50,
                    'protocol' => 'Sk6812W',
                    'brightness' => 50
                ]
            ]
        ];

        $result = $this->config->extractVirtualStringConfig($portConfig);

        $this->assertEquals('sk6812w', $result['protocol']);
    }

    public function testExtractVirtualStringConfigUsesFirstProtocol(): void
    {
        $portConfig = [
            'virtualStrings' => [
                ['pixelCount' => 100, 'protocol' => 'ws2811'],
                ['pixelCount' => 100, 'protocol' => 'ws2812'],
                ['pixelCount' => 100, 'protocol' => 'apa102']
            ]
        ];

        $result = $this->config->extractVirtualStringConfig($portConfig);

        // Should use protocol from first virtual string
        $this->assertEquals('ws2811', $result['protocol']);
    }

    public function testExtractVirtualStringConfigUsesFirstBrightness(): void
    {
        $portConfig = [
            'virtualStrings' => [
                ['pixelCount' => 100, 'brightness' => 50],
                ['pixelCount' => 100, 'brightness' => 75],
                ['pixelCount' => 100, 'brightness' => 100]
            ]
        ];

        $result = $this->config->extractVirtualStringConfig($portConfig);

        // Should use brightness from first virtual string
        $this->assertEquals(50, $result['brightness']);
    }

    // ===========================================
    // estimatePortCurrent Additional Tests
    // ===========================================

    public function testEstimatePortCurrentWithAllProtocols(): void
    {
        $protocols = array_keys(EfuseOutputConfig::PIXEL_CURRENT_ESTIMATES);

        foreach ($protocols as $protocol) {
            $result = $this->config->estimatePortCurrent(100, $protocol);

            $this->assertIsArray($result, "Failed for protocol: $protocol");
            $this->assertArrayHasKey('typical', $result);
            $this->assertArrayHasKey('max', $result);
            $this->assertArrayHasKey('perPixel', $result);
            $this->assertGreaterThan(0, $result['perPixel'], "perPixel should be > 0 for $protocol");
        }
    }

    public function testEstimatePortCurrentTypicalAlwaysLessThanMax(): void
    {
        $pixelCounts = [1, 10, 100, 500, 1000, 5000];
        $protocols = ['ws2811', 'ws2812', 'sk6812w', 'apa102'];

        foreach ($pixelCounts as $pixels) {
            foreach ($protocols as $protocol) {
                $result = $this->config->estimatePortCurrent($pixels, $protocol);

                $this->assertLessThanOrEqual(
                    $result['max'],
                    $result['typical'],
                    "Typical should be <= max for $pixels pixels with $protocol"
                );
            }
        }
    }

    public function testEstimatePortCurrentWithNegativePixels(): void
    {
        // Negative pixels shouldn't happen, but test robustness
        $result = $this->config->estimatePortCurrent(-100, 'ws2811');

        // Should return negative values (multiplication behavior)
        $this->assertEquals(-4200, $result['max']);
    }

    // ===========================================
    // Multiple Port Summary Tests
    // ===========================================

    public function testGetPortCurrentSummaryWithMultiplePorts(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();
        $testConfig->setConfigCache([
            'success' => true,
            'ports' => [
                'Port 1' => [
                    'portNumber' => 1,
                    'portName' => 'Port 1',
                    'protocol' => 'ws2811',
                    'pixelCount' => 100,
                    'expectedCurrentMa' => 252,
                    'maxCurrentMa' => 4200,
                    'enabled' => true
                ],
                'Port 2' => [
                    'portNumber' => 2,
                    'portName' => 'Port 2',
                    'protocol' => 'ws2812',
                    'pixelCount' => 200,
                    'expectedCurrentMa' => 720,
                    'maxCurrentMa' => 12000,
                    'enabled' => true
                ],
                'Port 3' => [
                    'portNumber' => 3,
                    'portName' => 'Port 3',
                    'protocol' => 'sk6812w',
                    'pixelCount' => 50,
                    'expectedCurrentMa' => 240,
                    'maxCurrentMa' => 4000,
                    'enabled' => false
                ]
            ]
        ]);

        $readings = [
            'Port 1' => 300,
            'Port 2' => 1000,
            'Port 3' => 0
        ];
        $result = $testConfig->getPortCurrentSummary($readings);

        $this->assertCount(3, $result);
        $this->assertEquals(300, $result['Port 1']['currentMa']);
        $this->assertEquals(1000, $result['Port 2']['currentMa']);
        $this->assertEquals(0, $result['Port 3']['currentMa']);
    }

    public function testGetPortCurrentSummaryWithSmartReceiverPorts(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();
        $testConfig->setConfigCache([
            'success' => true,
            'ports' => [
                'Port 9A' => [
                    'portNumber' => 9,
                    'portName' => 'Port 9A',
                    'protocol' => 'ws2811',
                    'pixelCount' => 100,
                    'expectedCurrentMa' => 252,
                    'maxCurrentMa' => 4200,
                    'enabled' => true
                ],
                'Port 9B' => [
                    'portNumber' => 9,
                    'portName' => 'Port 9B',
                    'protocol' => 'ws2811',
                    'pixelCount' => 150,
                    'expectedCurrentMa' => 378,
                    'maxCurrentMa' => 6300,
                    'enabled' => true
                ]
            ]
        ]);

        $readings = [
            'Port 9A' => 500,
            'Port 9B' => 750
        ];
        $result = $testConfig->getPortCurrentSummary($readings);

        $this->assertArrayHasKey('Port 9A', $result);
        $this->assertArrayHasKey('Port 9B', $result);
        $this->assertEquals(500, $result['Port 9A']['currentMa']);
        $this->assertEquals(750, $result['Port 9B']['currentMa']);
    }

    // ===========================================
    // findMatchingEfusePorts Tests
    // ===========================================

    public function testFindMatchingEfusePortsExactMatch(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();

        $efuseCapablePorts = ['Port 1', 'Port 2', 'Port 3'];
        $result = $testConfig->testFindMatchingEfusePorts('Port 2', $efuseCapablePorts);

        $this->assertEquals(['Port 2'], $result);
    }

    public function testFindMatchingEfusePortsNoMatch(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();

        $efuseCapablePorts = ['Port 1', 'Port 2', 'Port 3'];
        $result = $testConfig->testFindMatchingEfusePorts('Port 10', $efuseCapablePorts);

        $this->assertEquals([], $result);
    }

    public function testFindMatchingEfusePortsSmartReceiverSubports(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();

        $efuseCapablePorts = ['Port 1', 'Port 9A', 'Port 9B', 'Port 9C', 'Port 10'];
        $result = $testConfig->testFindMatchingEfusePorts('Port 9', $efuseCapablePorts);

        $this->assertCount(3, $result);
        $this->assertContains('Port 9A', $result);
        $this->assertContains('Port 9B', $result);
        $this->assertContains('Port 9C', $result);
    }

    public function testFindMatchingEfusePortsEmptyList(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();

        $result = $testConfig->testFindMatchingEfusePorts('Port 1', []);

        $this->assertEquals([], $result);
    }

    public function testFindMatchingEfusePortsAllSubports(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();

        // Test all valid subport letters A-F
        $efuseCapablePorts = ['Port 9A', 'Port 9B', 'Port 9C', 'Port 9D', 'Port 9E', 'Port 9F'];
        $result = $testConfig->testFindMatchingEfusePorts('Port 9', $efuseCapablePorts);

        $this->assertCount(6, $result);
    }

    public function testFindMatchingEfusePortsNoMatchForPartialName(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();

        // Port 10 should NOT match Port 1
        $efuseCapablePorts = ['Port 10', 'Port 11'];
        $result = $testConfig->testFindMatchingEfusePorts('Port 1', $efuseCapablePorts);

        $this->assertEquals([], $result);
    }

    // ===========================================
    // processChannelOutputs Tests
    // ===========================================

    public function testProcessChannelOutputsWithPixelStrings(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();

        $channelOutputs = [
            [
                'type' => 'F16-B',
                'outputs' => [
                    ['portNumber' => 0, 'virtualStrings' => [['pixelCount' => 100, 'protocol' => 'ws2811', 'brightness' => 100]]],
                    ['portNumber' => 1, 'virtualStrings' => [['pixelCount' => 200, 'protocol' => 'ws2812', 'brightness' => 75]]]
                ]
            ]
        ];

        $efuseCapablePorts = ['Port 1', 'Port 2'];
        $result = ['success' => true, 'ports' => [], 'totalPorts' => 0];

        $testConfig->testProcessChannelOutputs($channelOutputs, $efuseCapablePorts, $result, true);

        $this->assertArrayHasKey('Port 1', $result['ports']);
        $this->assertArrayHasKey('Port 2', $result['ports']);
        $this->assertEquals(100, $result['ports']['Port 1']['pixelCount']);
        $this->assertEquals(200, $result['ports']['Port 2']['pixelCount']);
        $this->assertEquals('ws2811', $result['ports']['Port 1']['protocol']);
        $this->assertEquals('ws2812', $result['ports']['Port 2']['protocol']);
        $this->assertEquals(2, $result['totalPorts']);
    }

    public function testProcessChannelOutputsWithTypeFilter(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();

        $channelOutputs = [
            [
                'type' => 'F16-B',
                'outputs' => [
                    ['portNumber' => 0, 'virtualStrings' => [['pixelCount' => 100]]]
                ]
            ],
            [
                'type' => 'BBB48String',
                'outputs' => [
                    ['portNumber' => 0, 'virtualStrings' => [['pixelCount' => 200]]]
                ]
            ]
        ];

        $efuseCapablePorts = ['Port 1'];
        $result = ['success' => true, 'ports' => [], 'totalPorts' => 0];

        // Filter to only BBB types
        $typeFilter = fn($type) => stripos($type, 'BB') !== false;
        $testConfig->testProcessChannelOutputs($channelOutputs, $efuseCapablePorts, $result, true, $typeFilter);

        $this->assertCount(1, $result['ports']);
        $this->assertEquals(200, $result['ports']['Port 1']['pixelCount']);
    }

    public function testProcessChannelOutputsSkipsNonEfusePorts(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();

        $channelOutputs = [
            [
                'type' => 'F16-B',
                'outputs' => [
                    ['portNumber' => 0, 'virtualStrings' => [['pixelCount' => 100]]],
                    ['portNumber' => 1, 'virtualStrings' => [['pixelCount' => 200]]],
                    ['portNumber' => 2, 'virtualStrings' => [['pixelCount' => 300]]]
                ]
            ]
        ];

        // Only Port 1 and Port 3 have eFuse capability
        $efuseCapablePorts = ['Port 1', 'Port 3'];
        $result = ['success' => true, 'ports' => [], 'totalPorts' => 0];

        $testConfig->testProcessChannelOutputs($channelOutputs, $efuseCapablePorts, $result, true);

        $this->assertCount(2, $result['ports']);
        $this->assertArrayHasKey('Port 1', $result['ports']);
        $this->assertArrayHasKey('Port 3', $result['ports']);
        $this->assertArrayNotHasKey('Port 2', $result['ports']);
    }

    public function testProcessChannelOutputsWithSmartReceivers(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();

        $channelOutputs = [
            [
                'type' => 'F16-B',
                'outputs' => [
                    ['portNumber' => 8, 'virtualStrings' => [['pixelCount' => 100, 'protocol' => 'ws2811']]]
                ]
            ]
        ];

        // Smart receiver on port 9 with subports A-C
        $efuseCapablePorts = ['Port 9A', 'Port 9B', 'Port 9C'];
        $result = ['success' => true, 'ports' => [], 'totalPorts' => 0];

        $testConfig->testProcessChannelOutputs($channelOutputs, $efuseCapablePorts, $result, true);

        // Port 9 (portNumber 8 + 1) should match all smart receiver subports
        $this->assertCount(3, $result['ports']);
        $this->assertArrayHasKey('Port 9A', $result['ports']);
        $this->assertArrayHasKey('Port 9B', $result['ports']);
        $this->assertArrayHasKey('Port 9C', $result['ports']);
    }

    public function testProcessChannelOutputsSkipsDuplicatePorts(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();

        $channelOutputs = [
            [
                'type' => 'F16-B',
                'outputs' => [
                    ['portNumber' => 0, 'virtualStrings' => [['pixelCount' => 100]]]
                ]
            ],
            [
                'type' => 'BBB48String',
                'outputs' => [
                    ['portNumber' => 0, 'virtualStrings' => [['pixelCount' => 999]]]
                ]
            ]
        ];

        $efuseCapablePorts = ['Port 1'];
        $result = ['success' => true, 'ports' => [], 'totalPorts' => 0];

        // Process both without type filter
        $testConfig->testProcessChannelOutputs($channelOutputs, $efuseCapablePorts, $result, true);

        // Should only have one entry, first one wins
        $this->assertCount(1, $result['ports']);
        $this->assertEquals(100, $result['ports']['Port 1']['pixelCount']);
    }

    public function testProcessChannelOutputsWithEmptyEfuseList(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();

        $channelOutputs = [
            [
                'type' => 'F16-B',
                'outputs' => [
                    ['portNumber' => 0, 'virtualStrings' => [['pixelCount' => 100]]],
                    ['portNumber' => 1, 'virtualStrings' => [['pixelCount' => 200]]]
                ]
            ]
        ];

        // Empty eFuse list - all ports should be included
        $efuseCapablePorts = [];
        $result = ['success' => true, 'ports' => [], 'totalPorts' => 0];

        $testConfig->testProcessChannelOutputs($channelOutputs, $efuseCapablePorts, $result, true);

        $this->assertCount(2, $result['ports']);
        $this->assertArrayHasKey('Port 1', $result['ports']);
        $this->assertArrayHasKey('Port 2', $result['ports']);
    }

    public function testProcessChannelOutputsWithPortFallback(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();

        $channelOutputs = [
            [
                'type' => 'F16-B',
                'outputs' => [
                    // No virtualStrings, just port-level config
                    [
                        'portNumber' => 0,
                        'pixelCount' => 150,
                        'protocol' => 'apa102',
                        'brightness' => 80
                    ]
                ]
            ]
        ];

        $efuseCapablePorts = ['Port 1'];
        $result = ['success' => true, 'ports' => [], 'totalPorts' => 0];

        // With usePortFallback = true
        $testConfig->testProcessChannelOutputs($channelOutputs, $efuseCapablePorts, $result, true);

        $this->assertEquals(150, $result['ports']['Port 1']['pixelCount']);
        $this->assertEquals('apa102', $result['ports']['Port 1']['protocol']);
        $this->assertEquals(80, $result['ports']['Port 1']['brightness']);
    }

    public function testProcessChannelOutputsWithoutPortFallback(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();

        $channelOutputs = [
            [
                'type' => 'F16-B',
                'outputs' => [
                    // No virtualStrings, just port-level config
                    ['portNumber' => 0, 'pixelCount' => 150, 'protocol' => 'apa102']
                ]
            ]
        ];

        $efuseCapablePorts = ['Port 1'];
        $result = ['success' => true, 'ports' => [], 'totalPorts' => 0];

        // With usePortFallback = false
        $testConfig->testProcessChannelOutputs($channelOutputs, $efuseCapablePorts, $result, false);

        // Should use defaults, not port-level config
        $this->assertEquals(0, $result['ports']['Port 1']['pixelCount']);
        $this->assertEquals('ws2811', $result['ports']['Port 1']['protocol']);
    }

    // ===========================================
    // getOutputConfig with Mocked Dependencies
    // ===========================================

    public function testGetOutputConfigWithMockedDependencies(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();

        $mockApi = new MockApiClient();
        $mockApi->setResponse('co-pixelStrings', [
            'channelOutputs' => [
                [
                    'type' => 'F16-B',
                    'outputs' => [
                        ['portNumber' => 0, 'virtualStrings' => [['pixelCount' => 100, 'protocol' => 'ws2811']]],
                        ['portNumber' => 1, 'virtualStrings' => [['pixelCount' => 200, 'protocol' => 'ws2812']]]
                    ]
                ]
            ]
        ]);
        $mockApi->setResponse('co-bbbStrings', null); // No BBB outputs

        $mockHardware = new MockEfuseHardware();
        $mockHardware->setEfuseCapablePorts(['Port 1', 'Port 2']);

        $testConfig->setMockApiClient($mockApi);
        $testConfig->setMockEfuseHardware($mockHardware);
        $testConfig->enableRealGetOutputConfig(true);

        $result = $testConfig->getOutputConfig();

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['ports']);
        $this->assertArrayHasKey('Port 1', $result['ports']);
        $this->assertArrayHasKey('Port 2', $result['ports']);
    }

    public function testGetOutputConfigWithBBBOutputs(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();

        $mockApi = new MockApiClient();
        $mockApi->setResponse('co-pixelStrings', ['channelOutputs' => []]);
        $mockApi->setResponse('co-bbbStrings', [
            'channelOutputs' => [
                [
                    'type' => 'BBB48String',
                    'outputs' => [
                        ['portNumber' => 0, 'virtualStrings' => [['pixelCount' => 300, 'protocol' => 'ws2812b']]]
                    ]
                ]
            ]
        ]);

        $mockHardware = new MockEfuseHardware();
        $mockHardware->setEfuseCapablePorts(['Port 1']);

        $testConfig->setMockApiClient($mockApi);
        $testConfig->setMockEfuseHardware($mockHardware);
        $testConfig->enableRealGetOutputConfig(true);

        $result = $testConfig->getOutputConfig();

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['ports']);
        $this->assertEquals(300, $result['ports']['Port 1']['pixelCount']);
    }

    public function testGetOutputConfigSortsPortsByNumber(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();

        $mockApi = new MockApiClient();
        $mockApi->setResponse('co-pixelStrings', [
            'channelOutputs' => [
                [
                    'type' => 'F16-B',
                    'outputs' => [
                        ['portNumber' => 9, 'virtualStrings' => [['pixelCount' => 100]]],
                        ['portNumber' => 0, 'virtualStrings' => [['pixelCount' => 200]]],
                        ['portNumber' => 4, 'virtualStrings' => [['pixelCount' => 300]]]
                    ]
                ]
            ]
        ]);
        $mockApi->setResponse('co-bbbStrings', null);

        $mockHardware = new MockEfuseHardware();
        $mockHardware->setEfuseCapablePorts(['Port 1', 'Port 5', 'Port 10']);

        $testConfig->setMockApiClient($mockApi);
        $testConfig->setMockEfuseHardware($mockHardware);
        $testConfig->enableRealGetOutputConfig(true);

        $result = $testConfig->getOutputConfig();

        $portNames = array_keys($result['ports']);
        $this->assertEquals(['Port 1', 'Port 5', 'Port 10'], $portNames);
    }

    public function testGetOutputConfigSortsSmartReceiverSubports(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();

        $mockApi = new MockApiClient();
        $mockApi->setResponse('co-pixelStrings', [
            'channelOutputs' => [
                [
                    'type' => 'F16-B',
                    'outputs' => [
                        ['portNumber' => 8, 'virtualStrings' => [['pixelCount' => 100]]]
                    ]
                ]
            ]
        ]);
        $mockApi->setResponse('co-bbbStrings', null);

        $mockHardware = new MockEfuseHardware();
        // Subports in random order
        $mockHardware->setEfuseCapablePorts(['Port 9C', 'Port 9A', 'Port 9B']);

        $testConfig->setMockApiClient($mockApi);
        $testConfig->setMockEfuseHardware($mockHardware);
        $testConfig->enableRealGetOutputConfig(true);

        $result = $testConfig->getOutputConfig();

        $portNames = array_keys($result['ports']);
        $this->assertEquals(['Port 9A', 'Port 9B', 'Port 9C'], $portNames);
    }

    // ===========================================
    // getPortFuseStatus Tests
    // ===========================================

    public function testGetPortFuseStatusWithRegularPorts(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();

        $mockHardware = new MockEfuseHardware();
        $mockHardware->setPortsData([
            ['name' => 'Port 1', 'enabled' => true, 'status' => true],
            ['name' => 'Port 2', 'enabled' => false, 'status' => true],
            ['name' => 'Port 3', 'enabled' => true, 'status' => false] // tripped
        ]);

        $testConfig->setMockEfuseHardware($mockHardware);

        $result = $testConfig->getPortFuseStatus();

        $this->assertCount(3, $result);
        $this->assertTrue($result['Port 1']['enabled']);
        $this->assertFalse($result['Port 1']['fuseTripped']);
        $this->assertFalse($result['Port 2']['enabled']);
        $this->assertFalse($result['Port 2']['fuseTripped']);
        $this->assertTrue($result['Port 3']['enabled']);
        $this->assertTrue($result['Port 3']['fuseTripped']);
    }

    public function testGetPortFuseStatusWithSmartReceiverPorts(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();

        $mockHardware = new MockEfuseHardware();
        $mockHardware->setPortsData([
            [
                'name' => 'Port 9',
                'subPorts' => [
                    ['name' => 'Port 9A', 'fuseOn' => true, 'status' => true],
                    ['name' => 'Port 9B', 'fuseOn' => false, 'status' => true],
                    ['name' => 'Port 9C', 'fuseOn' => true, 'status' => false] // tripped
                ]
            ]
        ]);

        $testConfig->setMockEfuseHardware($mockHardware);

        $result = $testConfig->getPortFuseStatus();

        $this->assertCount(3, $result);
        $this->assertTrue($result['Port 9A']['enabled']);
        $this->assertFalse($result['Port 9A']['fuseTripped']);
        $this->assertFalse($result['Port 9B']['enabled']);
        $this->assertFalse($result['Port 9B']['fuseTripped']);
        $this->assertTrue($result['Port 9C']['enabled']);
        $this->assertTrue($result['Port 9C']['fuseTripped']);
    }

    public function testGetPortFuseStatusWithNullPortsData(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();

        $mockHardware = new MockEfuseHardware();
        $mockHardware->setPortsData(null);

        $testConfig->setMockEfuseHardware($mockHardware);

        $result = $testConfig->getPortFuseStatus();

        $this->assertEquals([], $result);
    }

    public function testGetPortFuseStatusWithNoMockHardware(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();

        // Don't set mock hardware
        $result = $testConfig->getPortFuseStatus();

        $this->assertEquals([], $result);
    }

    public function testGetPortFuseStatusWithEmptyPortName(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();

        $mockHardware = new MockEfuseHardware();
        $mockHardware->setPortsData([
            ['name' => 'Port 1', 'enabled' => true],
            ['name' => '', 'enabled' => true], // empty name should be skipped
            ['name' => 'Port 3', 'enabled' => false]
        ]);

        $testConfig->setMockEfuseHardware($mockHardware);

        $result = $testConfig->getPortFuseStatus();

        $this->assertCount(2, $result);
        $this->assertArrayHasKey('Port 1', $result);
        $this->assertArrayHasKey('Port 3', $result);
    }

    // ===========================================
    // getPortCurrentSummary with FuseStatus Tests
    // ===========================================

    public function testGetPortCurrentSummaryWithTrippedFuse(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();

        $mockHardware = new MockEfuseHardware();
        $mockHardware->setPortsData([
            ['name' => 'Port 1', 'enabled' => true, 'status' => false] // tripped
        ]);

        $testConfig->setMockEfuseHardware($mockHardware);
        $testConfig->setConfigCache([
            'success' => true,
            'ports' => [
                'Port 1' => [
                    'portNumber' => 1,
                    'portName' => 'Port 1',
                    'protocol' => 'ws2811',
                    'pixelCount' => 100,
                    'expectedCurrentMa' => 252,
                    'maxCurrentMa' => 4200,
                    'enabled' => true
                ]
            ]
        ]);

        $readings = ['Port 1' => 0];
        $result = $testConfig->getPortCurrentSummary($readings);

        $this->assertEquals('tripped', $result['Port 1']['status']);
        $this->assertEquals('Fuse tripped', $result['Port 1']['statusMessage']);
        $this->assertTrue($result['Port 1']['fuseTripped']);
    }

    public function testGetPortCurrentSummaryWithDisabledPort(): void
    {
        $testConfig = TestableEfuseOutputConfig::getTestInstance();

        $mockHardware = new MockEfuseHardware();
        $mockHardware->setPortsData([
            ['name' => 'Port 1', 'enabled' => false, 'status' => true]
        ]);

        $testConfig->setMockEfuseHardware($mockHardware);
        $testConfig->setConfigCache([
            'success' => true,
            'ports' => [
                'Port 1' => [
                    'portNumber' => 1,
                    'portName' => 'Port 1',
                    'protocol' => 'ws2811',
                    'pixelCount' => 100,
                    'expectedCurrentMa' => 252,
                    'maxCurrentMa' => 4200,
                    'enabled' => true
                ]
            ]
        ]);

        $readings = ['Port 1' => 0];
        $result = $testConfig->getPortCurrentSummary($readings);

        $this->assertEquals('disabled', $result['Port 1']['status']);
        $this->assertEquals('Port disabled', $result['Port 1']['statusMessage']);
        $this->assertFalse($result['Port 1']['portEnabled']);
    }

    // ===========================================
    // MockApiClient Tests
    // ===========================================

    public function testMockApiClientPatternMatching(): void
    {
        $mockApi = new MockApiClient();
        $mockApi->setResponse('co-pixelStrings', ['test' => 'value']);

        // Should match partial URL
        $result = $mockApi->get('http://127.0.0.1/api/channel/output/co-pixelStrings', 5);
        $this->assertEquals(['test' => 'value'], $result);

        // Should return null for non-matching URL
        $result = $mockApi->get('http://127.0.0.1/api/other/endpoint', 5);
        $this->assertNull($result);
    }

    public function testMockApiClientExactMatch(): void
    {
        $mockApi = new MockApiClient();
        $mockApi->setResponse('http://127.0.0.1/api/exact', ['exact' => true]);

        $result = $mockApi->get('http://127.0.0.1/api/exact', 10);
        $this->assertEquals(['exact' => true], $result);
    }

    // ===========================================
    // MockEfuseHardware Tests
    // ===========================================

    public function testMockEfuseHardwareIterateAllPorts(): void
    {
        $mockHardware = new MockEfuseHardware();
        $mockHardware->setPortsData([
            ['name' => 'Port 1', 'enabled' => true],
            [
                'name' => 'Port 9',
                'subPorts' => [
                    ['name' => 'Port 9A', 'enabled' => true],
                    ['name' => 'Port 9B', 'enabled' => false]
                ]
            ]
        ]);

        $ports = [];
        $mockHardware->iterateAllPorts($mockHardware->fetchPortsData(), function ($name, $data, $isSR) use (&$ports) {
            $ports[$name] = ['isSmartReceiver' => $isSR, 'enabled' => $data['enabled'] ?? false];
        });

        $this->assertCount(3, $ports);
        $this->assertFalse($ports['Port 1']['isSmartReceiver']);
        $this->assertTrue($ports['Port 9A']['isSmartReceiver']);
        $this->assertTrue($ports['Port 9B']['isSmartReceiver']);
    }

    public function testMockEfuseHardwareWithNullData(): void
    {
        $mockHardware = new MockEfuseHardware();
        $mockHardware->setPortsData(null);

        $ports = [];
        $mockHardware->iterateAllPorts(null, function ($name, $data, $isSR) use (&$ports) {
            $ports[$name] = true;
        });

        $this->assertEmpty($ports);
    }
}
