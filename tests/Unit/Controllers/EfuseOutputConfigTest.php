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
 * Testable subclass that exposes private properties for testing
 */
class TestableEfuseOutputConfig extends EfuseOutputConfig
{
    private ?array $testConfigCache = null;
    private int $testConfigCacheTime = 0;

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
     * Override getOutputConfig to return test cache
     */
    public function getOutputConfig(bool $forceRefresh = false): array
    {
        if ($this->testConfigCache !== null) {
            return $this->testConfigCache;
        }
        return ['success' => false, 'ports' => [], 'totalPorts' => 0, 'timestamp' => time()];
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

        $this->assertEquals(0, $result['total']);
        $this->assertEquals(0.0, $result['totalAmps']);
        $this->assertEquals(0, $result['portCount']);
        $this->assertEquals(0, $result['activePortCount']);
    }

    public function testCalculateTotalCurrentSinglePort(): void
    {
        $readings = ['Port 1' => 1500];

        $result = $this->config->calculateTotalCurrent($readings);

        $this->assertEquals(1500, $result['total']);
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

        $this->assertEquals(5000, $result['total']);
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

        $this->assertEquals(3000, $result['total']);
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

        $this->assertEquals(6912, $result['total']);
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
}
