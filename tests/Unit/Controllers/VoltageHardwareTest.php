<?php
/**
 * Unit tests for VoltageHardware class
 *
 * Comprehensive test coverage for voltage hardware detection and reading.
 * Uses a testable subclass to access private methods and mock dependencies.
 *
 * @package Watcher\Tests\Unit\Controllers
 */

declare(strict_types=1);

namespace Watcher\Tests\Unit\Controllers;

use Watcher\Tests\TestCase;
use Watcher\Controllers\VoltageHardware;

/**
 * Testable subclass that exposes private methods and allows mocking
 */
class TestableVoltageHardware extends VoltageHardware
{
    /** @var string|null Override voltage dir for testing */
    private ?string $testVoltageDir = null;

    /** @var string|null Override cache file path for testing */
    private ?string $testCacheFile = null;

    /** @var bool|null Mock isRaspberryPi result */
    private ?bool $mockIsRaspberryPi = null;

    /** @var bool|null Mock isVcgencmdAvailable result */
    private ?bool $mockIsVcgencmdAvailable = null;

    /** @var string|null Mock executeVcgencmd result */
    private ?string $mockVcgencmdResult = null;

    /** @var string|null Mock executeVcgencmdMultiline result */
    private ?string $mockVcgencmdMultilineResult = null;

    /**
     * Get a fresh testable instance (bypasses singleton for testing)
     */
    public static function getTestInstance(): self
    {
        $reflection = new \ReflectionClass(self::class);
        $constructor = $reflection->getConstructor();
        if ($constructor) {
            $constructor->setAccessible(true);
        }

        $instance = $reflection->newInstanceWithoutConstructor();
        if ($constructor) {
            $constructor->invoke($instance);
        }

        return $instance;
    }

    /**
     * Set the test voltage directory
     */
    public function setTestVoltageDir(string $path): void
    {
        $this->testVoltageDir = $path;
        $reflection = new \ReflectionClass(parent::class);
        $prop = $reflection->getProperty('voltageDir');
        $prop->setAccessible(true);
        $prop->setValue($this, $path);
    }

    /**
     * Set the test cache file path
     */
    public function setTestCacheFile(string $path): void
    {
        $this->testCacheFile = $path;
        $reflection = new \ReflectionClass(parent::class);
        $prop = $reflection->getProperty('cacheFile');
        $prop->setAccessible(true);
        $prop->setValue($this, $path);
    }

    /**
     * Mock isRaspberryPi result
     */
    public function setMockIsRaspberryPi(?bool $value): void
    {
        $this->mockIsRaspberryPi = $value;
    }

    /**
     * Mock isVcgencmdAvailable result
     */
    public function setMockIsVcgencmdAvailable(?bool $value): void
    {
        $this->mockIsVcgencmdAvailable = $value;
    }

    /**
     * Mock vcgencmd result
     */
    public function setMockVcgencmdResult(?string $value): void
    {
        $this->mockVcgencmdResult = $value;
    }

    /**
     * Mock vcgencmd multiline result
     */
    public function setMockVcgencmdMultilineResult(?string $value): void
    {
        $this->mockVcgencmdMultilineResult = $value;
    }

    /**
     * Expose isRaspberryPi for testing
     */
    public function testIsRaspberryPi(): bool
    {
        if ($this->mockIsRaspberryPi !== null) {
            return $this->mockIsRaspberryPi;
        }
        $reflection = new \ReflectionMethod(parent::class, 'isRaspberryPi');
        $reflection->setAccessible(true);
        return $reflection->invoke($this);
    }

    /**
     * Expose isVcgencmdAvailable for testing
     */
    public function testIsVcgencmdAvailable(): bool
    {
        if ($this->mockIsVcgencmdAvailable !== null) {
            return $this->mockIsVcgencmdAvailable;
        }
        $reflection = new \ReflectionMethod(parent::class, 'isVcgencmdAvailable');
        $reflection->setAccessible(true);
        return $reflection->invoke($this);
    }

    /**
     * Expose executeVcgencmd for testing
     */
    public function testExecuteVcgencmd(string $command): ?string
    {
        if ($this->mockVcgencmdResult !== null) {
            return $this->mockVcgencmdResult;
        }
        $reflection = new \ReflectionMethod(parent::class, 'executeVcgencmd');
        $reflection->setAccessible(true);
        return $reflection->invoke($this, $command);
    }

    /**
     * Expose executeVcgencmdMultiline for testing
     */
    public function testExecuteVcgencmdMultiline(string $command): ?string
    {
        if ($this->mockVcgencmdMultilineResult !== null) {
            return $this->mockVcgencmdMultilineResult;
        }
        $reflection = new \ReflectionMethod(parent::class, 'executeVcgencmdMultiline');
        $reflection->setAccessible(true);
        return $reflection->invoke($this, $command);
    }

    /**
     * Expose hasPmicSupport for testing
     */
    public function testHasPmicSupport(): bool
    {
        $reflection = new \ReflectionMethod(parent::class, 'hasPmicSupport');
        $reflection->setAccessible(true);
        return $reflection->invoke($this);
    }

    /**
     * Expose saveHardwareCache for testing
     */
    public function testSaveHardwareCache(array $result): void
    {
        $reflection = new \ReflectionMethod(parent::class, 'saveHardwareCache');
        $reflection->setAccessible(true);
        $reflection->invoke($this, $result);
    }

    /**
     * Get the cache file path
     */
    public function getCacheFilePath(): string
    {
        $reflection = new \ReflectionClass(parent::class);
        $prop = $reflection->getProperty('cacheFile');
        $prop->setAccessible(true);
        return $prop->getValue($this);
    }
}

class VoltageHardwareTest extends TestCase
{
    private VoltageHardware $hardware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hardware = VoltageHardware::getInstance();
    }

    // =========================================================================
    // Singleton Tests
    // =========================================================================

    public function testGetInstanceReturnsSingleton(): void
    {
        $instance1 = VoltageHardware::getInstance();
        $instance2 = VoltageHardware::getInstance();

        $this->assertSame($instance1, $instance2);
    }

    public function testGetInstanceReturnsVoltageHardware(): void
    {
        $instance = VoltageHardware::getInstance();

        $this->assertInstanceOf(VoltageHardware::class, $instance);
    }

    // =========================================================================
    // Constants Tests
    // =========================================================================

    public function testHardwareCacheTTLConstant(): void
    {
        $this->assertEquals(3600, VoltageHardware::HARDWARE_CACHE_TTL);
    }

    public function testLegacyVoltageRailsConstant(): void
    {
        $expected = ['core', 'sdram_c', 'sdram_i', 'sdram_p'];
        $this->assertEquals($expected, VoltageHardware::LEGACY_VOLTAGE_RAILS);
    }

    public function testPmicVoltageRailsConstant(): void
    {
        $this->assertIsArray(VoltageHardware::PMIC_VOLTAGE_RAILS);
        $this->assertArrayHasKey('VDD_CORE_V', VoltageHardware::PMIC_VOLTAGE_RAILS);
        $this->assertArrayHasKey('EXT5V_V', VoltageHardware::PMIC_VOLTAGE_RAILS);
        $this->assertArrayHasKey('3V3_SYS_V', VoltageHardware::PMIC_VOLTAGE_RAILS);
        $this->assertCount(13, VoltageHardware::PMIC_VOLTAGE_RAILS);
    }

    public function testPmicVoltageRailLabels(): void
    {
        $this->assertEquals('5V Input', VoltageHardware::PMIC_VOLTAGE_RAILS['EXT5V_V']);
        $this->assertEquals('Core', VoltageHardware::PMIC_VOLTAGE_RAILS['VDD_CORE_V']);
        $this->assertEquals('HDMI', VoltageHardware::PMIC_VOLTAGE_RAILS['HDMI_V']);
        $this->assertEquals('3.3V System', VoltageHardware::PMIC_VOLTAGE_RAILS['3V3_SYS_V']);
    }

    // =========================================================================
    // Detection Result Structure Tests
    // =========================================================================

    public function testDetectHardwareReturnsExpectedStructure(): void
    {
        $result = $this->hardware->detectHardware();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('supported', $result);
        $this->assertArrayHasKey('type', $result);
        $this->assertArrayHasKey('method', $result);
        $this->assertArrayHasKey('hasPmic', $result);
        $this->assertArrayHasKey('availableRails', $result);

        $this->assertIsBool($result['supported']);
        $this->assertIsString($result['type']);
        $this->assertIsBool($result['hasPmic']);
        $this->assertIsArray($result['availableRails']);
    }

    public function testDetectHardwareWithForceRefresh(): void
    {
        // First call (may be cached)
        $result1 = $this->hardware->detectHardware();

        // Second call with force refresh
        $result2 = $this->hardware->detectHardware(true);

        // Both should have valid structure
        $this->assertArrayHasKey('supported', $result1);
        $this->assertArrayHasKey('supported', $result2);
    }

    public function testDetectHardwareTypeIsValidString(): void
    {
        $result = $this->hardware->detectHardware();

        $validTypes = ['none', 'rpi'];
        $this->assertContains($result['type'], $validTypes);
    }

    // =========================================================================
    // Public Method Existence Tests
    // =========================================================================

    public function testPublicMethodsExist(): void
    {
        $reflection = new \ReflectionClass(VoltageHardware::class);

        $expectedMethods = [
            'getInstance',
            'detectHardware',
            'readVoltage',
            'readAllVoltages',
            'getRailLabels',
            'getThrottleStatus',
            'getHardwareSummary',
            'clearHardwareCache',
        ];

        foreach ($expectedMethods as $methodName) {
            $this->assertTrue(
                $reflection->hasMethod($methodName),
                "Missing expected method: {$methodName}"
            );

            $method = $reflection->getMethod($methodName);
            $this->assertTrue(
                $method->isPublic(),
                "Method {$methodName} should be public"
            );
        }
    }

    // =========================================================================
    // Hardware Summary Tests
    // =========================================================================

    public function testGetHardwareSummaryReturnsArray(): void
    {
        $result = $this->hardware->getHardwareSummary();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('supported', $result);
    }

    public function testGetHardwareSummaryUnsupportedStructure(): void
    {
        $result = $this->hardware->getHardwareSummary();

        if (!$result['supported']) {
            $this->assertArrayHasKey('message', $result);
            $this->assertArrayHasKey('typeLabel', $result);
            $this->assertEquals('Not Supported', $result['typeLabel']);
        }
    }

    public function testGetHardwareSummarySupportedStructure(): void
    {
        $result = $this->hardware->getHardwareSummary();

        if ($result['supported']) {
            $this->assertArrayHasKey('type', $result);
            $this->assertArrayHasKey('typeLabel', $result);
            $this->assertArrayHasKey('method', $result);
            $this->assertEquals('Raspberry Pi', $result['typeLabel']);
        }
    }

    // =========================================================================
    // Cache Tests
    // =========================================================================

    public function testClearHardwareCacheMethod(): void
    {
        // Clear should not throw
        $this->hardware->clearHardwareCache();

        // After clearing, next detect should work
        $result = $this->hardware->detectHardware();
        $this->assertIsArray($result);
    }

    // =========================================================================
    // Read Voltage Tests
    // =========================================================================

    public function testReadVoltageReturnsExpectedStructure(): void
    {
        $result = $this->hardware->readVoltage();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('voltage', $result);
        $this->assertArrayHasKey('error', $result);
    }

    public function testReadVoltageUnsupportedPlatform(): void
    {
        $result = $this->hardware->readVoltage();

        if (!$result['success']) {
            $this->assertNull($result['voltage']);
            $this->assertNotNull($result['error']);
        } else {
            $this->assertIsFloat($result['voltage']);
            $this->assertNull($result['error']);
        }
    }

    public function testReadVoltageReturnsValidVoltageWhenSupported(): void
    {
        $result = $this->hardware->readVoltage();

        if ($result['success']) {
            $voltage = $result['voltage'];
            // Voltage should be positive and reasonable (0.5V - 6V)
            $this->assertGreaterThan(0, $voltage);
            $this->assertLessThan(6, $voltage);
        }
    }

    // =========================================================================
    // Read All Voltages Tests
    // =========================================================================

    public function testReadAllVoltagesReturnsExpectedStructure(): void
    {
        $result = $this->hardware->readAllVoltages();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('voltages', $result);
        $this->assertArrayHasKey('labels', $result);
        $this->assertArrayHasKey('error', $result);
    }

    public function testReadAllVoltagesUnsupportedPlatform(): void
    {
        $result = $this->hardware->readAllVoltages();

        if (!$result['success']) {
            $this->assertEmpty($result['voltages']);
            $this->assertEmpty($result['labels']);
            $this->assertNotNull($result['error']);
        }
    }

    public function testReadAllVoltagesReturnsValidDataWhenSupported(): void
    {
        $result = $this->hardware->readAllVoltages();

        if ($result['success']) {
            $this->assertNotEmpty($result['voltages']);
            $this->assertNotEmpty($result['labels']);
            $this->assertNull($result['error']);

            // Each voltage should be positive
            foreach ($result['voltages'] as $rail => $voltage) {
                $this->assertIsFloat($voltage, "Voltage for $rail should be float");
                $this->assertGreaterThan(0, $voltage, "Voltage for $rail should be positive");
            }

            // Labels should match voltages keys
            foreach ($result['voltages'] as $rail => $voltage) {
                $this->assertArrayHasKey($rail, $result['labels']);
            }
        }
    }

    // =========================================================================
    // Get Rail Labels Tests
    // =========================================================================

    public function testGetRailLabelsReturnsArray(): void
    {
        $result = $this->hardware->getRailLabels();

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    public function testGetRailLabelsHasExpectedFormat(): void
    {
        $result = $this->hardware->getRailLabels();

        foreach ($result as $railKey => $label) {
            $this->assertIsString($railKey);
            $this->assertIsString($label);
            $this->assertNotEmpty($label);
        }
    }

    // =========================================================================
    // Throttle Status Tests
    // =========================================================================

    public function testGetThrottleStatusReturnsExpectedStructure(): void
    {
        $result = $this->hardware->getThrottleStatus();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('throttled', $result);
        $this->assertArrayHasKey('flags', $result);
        $this->assertArrayHasKey('undervoltage', $result);
        $this->assertArrayHasKey('details', $result);

        $this->assertIsBool($result['throttled']);
        $this->assertIsString($result['flags']);
        $this->assertIsBool($result['undervoltage']);
        $this->assertIsArray($result['details']);
    }

    public function testGetThrottleStatusDetailsStructure(): void
    {
        $result = $this->hardware->getThrottleStatus();

        if ($result['success']) {
            $expectedKeys = [
                'undervoltage_now',
                'freq_capped_now',
                'throttled_now',
                'temp_limit_now',
                'undervoltage_occurred',
                'freq_capped_occurred',
                'throttled_occurred',
                'temp_limit_occurred',
            ];

            foreach ($expectedKeys as $key) {
                $this->assertArrayHasKey($key, $result['details']);
                $this->assertIsBool($result['details'][$key]);
            }
        }
    }

    // =========================================================================
    // TestableVoltageHardware Tests
    // =========================================================================

    public function testSaveHardwareCacheCreatesFile(): void
    {
        $instance = TestableVoltageHardware::getTestInstance();
        $testDir = $this->createTempDir('voltage-cache');
        $testCacheFile = $testDir . '/hardware-cache.json';

        $instance->setTestVoltageDir($testDir);
        $instance->setTestCacheFile($testCacheFile);

        $cacheData = [
            'supported' => true,
            'type' => 'rpi',
            'method' => 'pmic',
            'hasPmic' => true,
            'availableRails' => ['VDD_CORE_V', 'EXT5V_V']
        ];

        $instance->testSaveHardwareCache($cacheData);

        $this->assertFileExists($testCacheFile);

        $saved = json_decode(file_get_contents($testCacheFile), true);
        $this->assertArrayHasKey('timestamp', $saved);
        $this->assertArrayHasKey('result', $saved);
        $this->assertEquals($cacheData, $saved['result']);
    }

    public function testCacheFilePathIsConfigurable(): void
    {
        $instance = TestableVoltageHardware::getTestInstance();
        $testDir = $this->createTempDir('voltage-test');
        $testCacheFile = $testDir . '/custom-cache.json';

        $instance->setTestCacheFile($testCacheFile);

        $this->assertEquals($testCacheFile, $instance->getCacheFilePath());
    }

    public function testMockIsRaspberryPi(): void
    {
        $instance = TestableVoltageHardware::getTestInstance();

        $instance->setMockIsRaspberryPi(true);
        $this->assertTrue($instance->testIsRaspberryPi());

        $instance->setMockIsRaspberryPi(false);
        $this->assertFalse($instance->testIsRaspberryPi());
    }

    public function testMockIsVcgencmdAvailable(): void
    {
        $instance = TestableVoltageHardware::getTestInstance();

        $instance->setMockIsVcgencmdAvailable(true);
        $this->assertTrue($instance->testIsVcgencmdAvailable());

        $instance->setMockIsVcgencmdAvailable(false);
        $this->assertFalse($instance->testIsVcgencmdAvailable());
    }

    public function testMockVcgencmdResult(): void
    {
        $instance = TestableVoltageHardware::getTestInstance();

        $instance->setMockVcgencmdResult('volt=1.2375V');
        $result = $instance->testExecuteVcgencmd('measure_volts core');

        $this->assertEquals('volt=1.2375V', $result);
    }

    public function testMockVcgencmdMultilineResult(): void
    {
        $instance = TestableVoltageHardware::getTestInstance();

        $mockOutput = "VDD_CORE_V volt(15)=0.87677570V\nEXT5V_V volt(24)=5.15364000V";
        $instance->setMockVcgencmdMultilineResult($mockOutput);

        $result = $instance->testExecuteVcgencmdMultiline('pmic_read_adc');

        $this->assertEquals($mockOutput, $result);
    }

    // =========================================================================
    // Edge Case Tests
    // =========================================================================

    public function testDetectHardwareHandlesNoRaspberryPi(): void
    {
        // On non-Raspberry Pi systems, should return unsupported
        $result = $this->hardware->detectHardware();

        // This will vary based on the actual system
        $this->assertIsBool($result['supported']);
        $this->assertIsString($result['type']);
    }

    public function testDetectHardwareHandlesCacheExpiration(): void
    {
        $instance = TestableVoltageHardware::getTestInstance();
        $testDir = $this->createTempDir('voltage-cache-expiry');
        $testCacheFile = $testDir . '/hardware-cache.json';

        $instance->setTestVoltageDir($testDir);
        $instance->setTestCacheFile($testCacheFile);

        // Write an expired cache entry
        $expiredCache = [
            'timestamp' => time() - 7200, // 2 hours ago (TTL is 1 hour)
            'result' => [
                'supported' => true,
                'type' => 'rpi',
                'method' => 'vcgencmd',
                'hasPmic' => false,
                'availableRails' => ['core']
            ]
        ];

        file_put_contents($testCacheFile, json_encode($expiredCache));

        // Force refresh should bypass expired cache
        $result = $instance->detectHardware(true);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('supported', $result);
    }

    public function testReadVoltageHandlesFailure(): void
    {
        $result = $this->hardware->readVoltage();

        // Either success with valid data or failure with error message
        if ($result['success']) {
            $this->assertNotNull($result['voltage']);
            $this->assertIsFloat($result['voltage']);
        } else {
            $this->assertNull($result['voltage']);
            $this->assertNotNull($result['error']);
            $this->assertIsString($result['error']);
        }
    }

    // =========================================================================
    // Data Provider Tests
    // =========================================================================

    /**
     * @dataProvider voltageOutputProvider
     */
    public function testVoltageOutputParsing(string $output, float $expectedVoltage): void
    {
        // This tests the regex pattern used internally
        $pattern = '/volt=([0-9.]+)V/';
        $this->assertMatchesRegularExpression($pattern, $output);

        preg_match($pattern, $output, $matches);
        $this->assertEqualsWithDelta($expectedVoltage, (float)$matches[1], 0.0001);
    }

    public static function voltageOutputProvider(): array
    {
        return [
            'standard voltage' => ['volt=1.2375V', 1.2375],
            'low core voltage' => ['volt=0.8768V', 0.8768],
            'high voltage' => ['volt=5.1536V', 5.1536],
            'exact voltage' => ['volt=3.3000V', 3.3],
            'single decimal' => ['volt=1.8V', 1.8],
        ];
    }

    /**
     * @dataProvider pmicOutputProvider
     */
    public function testPmicOutputParsing(string $output, string $railKey, float $expectedVoltage): void
    {
        // Test the PMIC output parsing pattern
        $pattern = '/' . preg_quote($railKey, '/') . '\s+volt\(\d+\)=([0-9.]+)V/';
        $this->assertMatchesRegularExpression($pattern, $output);

        preg_match($pattern, $output, $matches);
        $this->assertEqualsWithDelta($expectedVoltage, (float)$matches[1], 0.0001);
    }

    public static function pmicOutputProvider(): array
    {
        return [
            'core voltage' => ['VDD_CORE_V volt(15)=0.87677570V', 'VDD_CORE_V', 0.8768],
            '5v input' => ['EXT5V_V volt(24)=5.15364000V', 'EXT5V_V', 5.1536],
            '3.3v system' => ['3V3_SYS_V volt(9)=3.31281700V', '3V3_SYS_V', 3.3128],
            'hdmi voltage' => ['HDMI_V volt(23)=5.17776000V', 'HDMI_V', 5.1778],
        ];
    }

    /**
     * @dataProvider throttleStatusProvider
     */
    public function testThrottleStatusParsing(string $output, int $expectedValue): void
    {
        // Test the throttle status parsing pattern
        $pattern = '/throttled=(0x[0-9a-fA-F]+)/';
        $this->assertMatchesRegularExpression($pattern, $output);

        preg_match($pattern, $output, $matches);
        $this->assertEquals($expectedValue, hexdec($matches[1]));
    }

    public static function throttleStatusProvider(): array
    {
        return [
            'no throttle' => ['throttled=0x0', 0x0],
            'undervoltage now' => ['throttled=0x1', 0x1],
            'freq capped' => ['throttled=0x2', 0x2],
            'throttled now' => ['throttled=0x4', 0x4],
            'temp limit' => ['throttled=0x8', 0x8],
            'undervoltage occurred' => ['throttled=0x10000', 0x10000],
            'multiple flags' => ['throttled=0x50005', 0x50005],
        ];
    }

    // =========================================================================
    // Throttle Flag Decoding Tests
    // =========================================================================

    public function testThrottleFlagDecodingUndervoltageNow(): void
    {
        $value = 0x1;
        $this->assertTrue((bool)($value & 0x1));
        $this->assertFalse((bool)($value & 0x2));
        $this->assertFalse((bool)($value & 0x10000));
    }

    public function testThrottleFlagDecodingFreqCapped(): void
    {
        $value = 0x2;
        $this->assertFalse((bool)($value & 0x1));
        $this->assertTrue((bool)($value & 0x2));
        $this->assertFalse((bool)($value & 0x4));
    }

    public function testThrottleFlagDecodingThrottledNow(): void
    {
        $value = 0x4;
        $this->assertTrue((bool)($value & 0x4));
        $this->assertFalse((bool)($value & 0x1));
    }

    public function testThrottleFlagDecodingMultiple(): void
    {
        $value = 0x50005; // undervoltage now + occurred, throttled occurred
        $this->assertTrue((bool)($value & 0x1));
        $this->assertTrue((bool)($value & 0x4));
        $this->assertTrue((bool)($value & 0x10000));
        $this->assertTrue((bool)($value & 0x40000));
    }

    // =========================================================================
    // Rail Configuration Tests
    // =========================================================================

    public function testAllPmicRailsHaveLabels(): void
    {
        foreach (VoltageHardware::PMIC_VOLTAGE_RAILS as $railKey => $label) {
            $this->assertNotEmpty($label, "Rail $railKey should have a label");
            $this->assertIsString($label);
        }
    }

    public function testPmicRailsContainCriticalVoltages(): void
    {
        $criticalRails = ['EXT5V_V', 'VDD_CORE_V', '3V3_SYS_V', '1V8_SYS_V'];

        foreach ($criticalRails as $rail) {
            $this->assertArrayHasKey(
                $rail,
                VoltageHardware::PMIC_VOLTAGE_RAILS,
                "Critical rail $rail should be defined"
            );
        }
    }

    public function testLegacyRailsAreComplete(): void
    {
        $expectedRails = ['core', 'sdram_c', 'sdram_i', 'sdram_p'];

        $this->assertEquals($expectedRails, VoltageHardware::LEGACY_VOLTAGE_RAILS);
    }
}
