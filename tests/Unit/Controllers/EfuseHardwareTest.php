<?php
/**
 * Unit tests for EfuseHardware class
 *
 * Comprehensive test coverage for eFuse hardware detection and control.
 * Uses a testable subclass to access private methods.
 *
 * @package Watcher\Tests\Unit\Controllers
 */

declare(strict_types=1);

namespace Watcher\Tests\Unit\Controllers;

use Watcher\Tests\TestCase;
use Watcher\Controllers\EfuseHardware;

/**
 * Testable subclass that exposes private methods for testing
 */
class TestableEfuseHardware extends EfuseHardware
{
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
     * Expose detectPlatform for testing
     */
    public function testDetectPlatform(): string
    {
        $reflection = new \ReflectionMethod(parent::class, 'detectPlatform');
        $reflection->setAccessible(true);
        return $reflection->invoke($this);
    }

    /**
     * Expose getChannelOutputConfig for testing
     */
    public function testGetChannelOutputConfig(): ?array
    {
        $reflection = new \ReflectionMethod(parent::class, 'getChannelOutputConfig');
        $reflection->setAccessible(true);
        return $reflection->invoke($this);
    }

    /**
     * Expose loadStringConfig for testing
     */
    public function testLoadStringConfig(string $subType): ?array
    {
        $reflection = new \ReflectionMethod(parent::class, 'loadStringConfig');
        $reflection->setAccessible(true);
        return $reflection->invoke($this, $subType);
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
     * Expose logControl for testing
     */
    public function testLogControl(string $action, string $target, string $result): void
    {
        $reflection = new \ReflectionMethod(parent::class, 'logControl');
        $reflection->setAccessible(true);
        $reflection->invoke($this, $action, $target, $result);
    }
}

class EfuseHardwareTest extends TestCase
{
    private EfuseHardware $hardware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hardware = EfuseHardware::getInstance();
    }

    // =========================================================================
    // Singleton Tests
    // =========================================================================

    public function testGetInstanceReturnsSingleton(): void
    {
        $instance1 = EfuseHardware::getInstance();
        $instance2 = EfuseHardware::getInstance();

        $this->assertSame($instance1, $instance2);
    }

    // =========================================================================
    // Constants Tests
    // =========================================================================

    public function testHardwareCacheTTLConstant(): void
    {
        $this->assertEquals(3600, EfuseHardware::HARDWARE_CACHE_TTL);
    }

    public function testResetDelayConstant(): void
    {
        $this->assertEquals(100000, EfuseHardware::RESET_DELAY_US); // 100ms in microseconds
    }

    public function testFppDirectoryConstants(): void
    {
        $this->assertEquals('/home/fpp/media/tmp', EfuseHardware::FPP_TMP_DIR);
        $this->assertEquals('/home/fpp/media/config', EfuseHardware::FPP_CONFIG_DIR);
        $this->assertEquals('/opt/fpp/capes', EfuseHardware::FPP_CAPES_DIR);
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
        $this->assertArrayHasKey('ports', $result);
        $this->assertArrayHasKey('details', $result);

        $this->assertIsBool($result['supported']);
        $this->assertIsString($result['type']);
        $this->assertIsInt($result['ports']);
        $this->assertIsArray($result['details']);
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

        $validTypes = ['none', 'cape', 'smart_receiver'];
        $this->assertContains($result['type'], $validTypes);
    }

    public function testDetectHardwarePortsIsNonNegative(): void
    {
        $result = $this->hardware->detectHardware();

        $this->assertGreaterThanOrEqual(0, $result['ports']);
    }

    // =========================================================================
    // Public Method Existence Tests
    // =========================================================================

    public function testPublicMethodsExist(): void
    {
        $reflection = new \ReflectionClass(EfuseHardware::class);

        // Verify key public methods exist
        $expectedMethods = [
            'getInstance',
            'detectHardware',
            'readEfuseData',
            'getFppdPortsCached',
            'countEfusePortsFromFppd',
            'getEfuseCapablePortNames',
            'getPortCurrentSummary',
            'calculateTotalCurrent',
            'getHardwareSummary',
            'clearHardwareCache',
            'togglePort',
            'resetPort',
            'setAllPorts',
            'resetAllTrippedFuses',
            'getControlCapabilities',
            'getPortStatus',
            'getTrippedFuses',
            'getSummary',
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

    public function testGetSummaryIsAliasForHardwareSummary(): void
    {
        $summary1 = $this->hardware->getHardwareSummary();
        $summary2 = $this->hardware->getSummary();

        // Both should return same structure
        $this->assertEquals(array_keys($summary1), array_keys($summary2));
    }

    public function testGetHardwareSummaryUnsupportedStructure(): void
    {
        $result = $this->hardware->getHardwareSummary();

        // If not supported, should have specific keys
        if (!$result['supported']) {
            $this->assertArrayHasKey('message', $result);
            $this->assertArrayHasKey('typeLabel', $result);
            $this->assertEquals('None', $result['typeLabel']);
        }
    }

    public function testGetHardwareSummarySupportedStructure(): void
    {
        $result = $this->hardware->getHardwareSummary();

        // Always assert basic structure
        $this->assertArrayHasKey('supported', $result);

        // If supported, should have additional keys
        if ($result['supported']) {
            $this->assertArrayHasKey('type', $result);
            $this->assertArrayHasKey('typeLabel', $result);
            $this->assertArrayHasKey('ports', $result);
            $this->assertArrayHasKey('details', $result);
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
    // Current Calculation Tests
    // =========================================================================

    public function testCalculateTotalCurrentWithEmptyArray(): void
    {
        $result = $this->hardware->calculateTotalCurrent([]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('totalMa', $result);
        $this->assertArrayHasKey('totalAmps', $result);
        $this->assertArrayHasKey('portCount', $result);
        $this->assertArrayHasKey('activePortCount', $result);

        $this->assertEquals(0, $result['totalMa']);
        $this->assertEquals(0.0, $result['totalAmps']);
        $this->assertEquals(0, $result['activePortCount']);
    }

    public function testCalculateTotalCurrentWithSampleData(): void
    {
        $readings = [
            'Port 1' => 2500,
            'Port 2' => 3000,
            'Port 3' => 1500,
        ];

        $result = $this->hardware->calculateTotalCurrent($readings);

        $this->assertIsArray($result);
        $this->assertEquals(7000, $result['totalMa']);
        $this->assertEquals(7.0, $result['totalAmps']);
        $this->assertEquals(3, $result['activePortCount']);
    }

    public function testCalculateTotalCurrentIgnoresTotalKey(): void
    {
        $readings = [
            'Port 1' => 1000,
            'Port 2' => 2000,
            '_total' => 9999  // Should be ignored
        ];

        $result = $this->hardware->calculateTotalCurrent($readings);

        $this->assertEquals(3000, $result['totalMa']);
    }

    public function testCalculateTotalCurrentWithZeroValues(): void
    {
        $readings = [
            'Port 1' => 0,
            'Port 2' => 0,
            'Port 3' => 0,
        ];

        $result = $this->hardware->calculateTotalCurrent($readings);

        $this->assertEquals(0, $result['totalMa']);
        $this->assertEquals(0, $result['activePortCount']);
    }

    public function testCalculateTotalCurrentWithMixedValues(): void
    {
        $readings = [
            'Port 1' => 1000,
            'Port 2' => 0,
            'Port 3' => 2000,
            'Port 4' => 0,
        ];

        $result = $this->hardware->calculateTotalCurrent($readings);

        $this->assertEquals(3000, $result['totalMa']);
        $this->assertEquals(2, $result['activePortCount']);
    }

    public function testCalculateTotalCurrentRoundsCorrectly(): void
    {
        $readings = [
            'Port 1' => 1234,
            'Port 2' => 5678,
        ];

        $result = $this->hardware->calculateTotalCurrent($readings);

        $this->assertEquals(6912, $result['totalMa']);
        $this->assertEquals(6.91, $result['totalAmps']); // Should round to 2 decimal places
    }

    /**
     * @dataProvider currentCalculationProvider
     */
    public function testCalculateTotalCurrentDataProvider(array $readings, int $expectedMa, float $expectedAmps, int $expectedActive): void
    {
        $result = $this->hardware->calculateTotalCurrent($readings);

        $this->assertEquals($expectedMa, $result['totalMa']);
        $this->assertEquals($expectedAmps, $result['totalAmps']);
        $this->assertEquals($expectedActive, $result['activePortCount']);
    }

    public static function currentCalculationProvider(): array
    {
        return [
            'empty' => [[], 0, 0.0, 0],
            'single port' => [['Port 1' => 1000], 1000, 1.0, 1],
            'multiple ports' => [
                ['Port 1' => 1000, 'Port 2' => 2000, 'Port 3' => 3000],
                6000, 6.0, 3
            ],
            'with zeros' => [
                ['Port 1' => 1000, 'Port 2' => 0, 'Port 3' => 2000],
                3000, 3.0, 2
            ],
            'all zeros' => [
                ['Port 1' => 0, 'Port 2' => 0],
                0, 0.0, 0
            ],
            'large values' => [
                ['Port 1' => 5000, 'Port 2' => 5000, 'Port 3' => 5000],
                15000, 15.0, 3
            ],
            'fractional result' => [
                ['Port 1' => 1234],
                1234, 1.23, 1
            ],
            'with _total key ignored' => [
                ['Port 1' => 1000, '_total' => 9999],
                1000, 1.0, 1
            ]
        ];
    }

    public function testGetPortCurrentSummaryWithEmptyArray(): void
    {
        $result = $this->hardware->getPortCurrentSummary([]);

        $this->assertIsArray($result);
    }

    public function testGetPortCurrentSummaryReturnsExpectedFields(): void
    {
        // Get output config to know what ports are configured
        $outputConfig = \Watcher\Controllers\EfuseOutputConfig::getInstance()->getOutputConfig();

        if (empty($outputConfig['ports'])) {
            $this->markTestSkipped('No configured ports to test');
        }

        $portName = array_key_first($outputConfig['ports']);
        $readings = [$portName => 1500];

        $result = $this->hardware->getPortCurrentSummary($readings);

        $this->assertIsArray($result);
        $this->assertArrayHasKey($portName, $result);

        $portData = $result[$portName];

        // Core fields
        $this->assertArrayHasKey('name', $portData);
        $this->assertArrayHasKey('label', $portData);
        $this->assertArrayHasKey('currentMa', $portData);
        $this->assertArrayHasKey('currentA', $portData);
        $this->assertArrayHasKey('status', $portData);
        $this->assertArrayHasKey('portEnabled', $portData);
        $this->assertArrayHasKey('fuseTripped', $portData);
        $this->assertArrayHasKey('enabled', $portData);
        $this->assertArrayHasKey('pixelCount', $portData);

        // Expected current fields (CRITICAL - these were missing before fix)
        $this->assertArrayHasKey('expectedCurrentMa', $portData, 'expectedCurrentMa field is required for UI');
        $this->assertArrayHasKey('maxCurrentMa', $portData, 'maxCurrentMa field is required for UI');

        // Output config fields (needed for port detail panel)
        $this->assertArrayHasKey('protocol', $portData, 'protocol field is required for output config display');
        $this->assertArrayHasKey('brightness', $portData, 'brightness field is required for output config display');
        $this->assertArrayHasKey('colorOrder', $portData, 'colorOrder field is required for output config display');
        $this->assertArrayHasKey('description', $portData, 'description field is required for output config display');
    }

    public function testGetPortCurrentSummaryCurrentValues(): void
    {
        $outputConfig = \Watcher\Controllers\EfuseOutputConfig::getInstance()->getOutputConfig();

        if (empty($outputConfig['ports'])) {
            $this->markTestSkipped('No configured ports to test');
        }

        $portName = array_key_first($outputConfig['ports']);
        $readings = [$portName => 2500];

        $result = $this->hardware->getPortCurrentSummary($readings);
        $portData = $result[$portName];

        $this->assertEquals(2500, $portData['currentMa']);
        $this->assertEquals(2.5, $portData['currentA']);
    }

    public function testGetPortCurrentSummaryExpectedCurrentIsCalculated(): void
    {
        $outputConfig = \Watcher\Controllers\EfuseOutputConfig::getInstance()->getOutputConfig();

        if (empty($outputConfig['ports'])) {
            $this->markTestSkipped('No configured ports to test');
        }

        $portName = array_key_first($outputConfig['ports']);
        $portConfig = $outputConfig['ports'][$portName];

        // Skip if port has no pixels configured
        if (empty($portConfig['pixelCount'])) {
            $this->markTestSkipped('Port has no pixels configured');
        }

        $readings = [$portName => 1000];
        $result = $this->hardware->getPortCurrentSummary($readings);
        $portData = $result[$portName];

        // expectedCurrentMa should match the output config
        $this->assertEquals(
            $portConfig['expectedCurrentMa'],
            $portData['expectedCurrentMa'],
            'expectedCurrentMa should come from output config'
        );

        // maxCurrentMa should match the output config
        $this->assertEquals(
            $portConfig['maxCurrentMa'],
            $portData['maxCurrentMa'],
            'maxCurrentMa should come from output config'
        );
    }

    // =========================================================================
    // Control Capabilities Tests
    // =========================================================================

    public function testGetControlCapabilitiesReturnsArray(): void
    {
        $result = $this->hardware->getControlCapabilities();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('canToggle', $result);
        $this->assertArrayHasKey('canReset', $result);
        $this->assertArrayHasKey('canMasterControl', $result);
        $this->assertArrayHasKey('isSmartReceiver', $result);
        $this->assertArrayHasKey('hardwareType', $result);

        $this->assertTrue($result['success']);
        $this->assertIsBool($result['canToggle']);
        $this->assertIsBool($result['canReset']);
        $this->assertIsBool($result['canMasterControl']);
        $this->assertIsBool($result['isSmartReceiver']);
    }

    public function testGetControlCapabilitiesUnsupportedHardware(): void
    {
        $result = $this->hardware->getControlCapabilities();

        // If hardware is not supported, all controls should be false
        if (!$result['supported']) {
            $this->assertFalse($result['canToggle']);
            $this->assertFalse($result['canReset']);
            $this->assertFalse($result['canMasterControl']);
            $this->assertFalse($result['isSmartReceiver']);
            $this->assertEquals('none', $result['hardwareType']);
        }
    }

    public function testGetControlCapabilitiesSupportedHardware(): void
    {
        $result = $this->hardware->getControlCapabilities();

        // Always assert basic structure
        $this->assertArrayHasKey('supported', $result);

        // If hardware is supported, capabilities should be true
        if ($result['supported']) {
            $this->assertTrue($result['canToggle']);
            $this->assertTrue($result['canReset']);
            $this->assertTrue($result['canMasterControl']);
            $this->assertArrayHasKey('portCount', $result);
            $this->assertArrayHasKey('method', $result);
        }
    }

    // =========================================================================
    // Port Reading Tests
    // =========================================================================

    public function testReadEfuseDataReturnsArrayOrFalse(): void
    {
        $result = $this->hardware->readEfuseData();

        // Can return array (with data) or false (if no hardware)
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('ports', $result);
    }

    public function testReadEfuseDataStructure(): void
    {
        $result = $this->hardware->readEfuseData();

        if ($result['success']) {
            $this->assertArrayHasKey('timestamp', $result);
            $this->assertArrayHasKey('method', $result);
            $this->assertIsArray($result['ports']);
        } else {
            $this->assertArrayHasKey('error', $result);
        }
    }

    public function testCountEfusePortsFromFppdReturnsInt(): void
    {
        $result = $this->hardware->countEfusePortsFromFppd();

        $this->assertIsInt($result);
        $this->assertGreaterThanOrEqual(0, $result);
    }

    public function testGetEfuseCapablePortNamesReturnsArray(): void
    {
        $result = $this->hardware->getEfuseCapablePortNames();

        $this->assertIsArray($result);

        // If there are ports, they should be strings
        foreach ($result as $portName) {
            $this->assertIsString($portName);
        }
    }

    // =========================================================================
    // Tripped Fuse Tests
    // =========================================================================

    public function testGetTrippedFusesReturnsArray(): void
    {
        $result = $this->hardware->getTrippedFuses();

        $this->assertIsArray($result);

        // Each element should be a port name string
        foreach ($result as $portName) {
            $this->assertIsString($portName);
        }
    }

    // =========================================================================
    // Port Status Tests
    // =========================================================================

    public function testGetPortStatusWithInvalidPort(): void
    {
        $result = $this->hardware->getPortStatus('NonexistentPort999');

        // Should return null or empty status for invalid port
        $this->assertTrue(
            is_null($result) || is_array($result),
            'getPortStatus should return null or array'
        );
    }

    public function testGetPortStatusWithValidPortFormat(): void
    {
        // Port name format should be "Port X" or "Port X-Y"
        $result = $this->hardware->getPortStatus('Port 1');

        // Either null (port not found) or array with status
        $this->assertTrue(
            is_null($result) || is_array($result),
            'getPortStatus should return null or array'
        );

        if (is_array($result)) {
            $this->assertArrayHasKey('name', $result);
            $this->assertArrayHasKey('enabled', $result);
            $this->assertArrayHasKey('fuseTripped', $result);
            $this->assertArrayHasKey('currentMa', $result);
        }
    }

    public function testGetPortStatusWithSmartReceiverPort(): void
    {
        $result = $this->hardware->getPortStatus('Port 1-A');

        $this->assertTrue(
            is_null($result) || is_array($result),
            'getPortStatus should return null or array for smart receiver port'
        );

        if (is_array($result)) {
            $this->assertArrayHasKey('isSmartReceiver', $result);
        }
    }

    // =========================================================================
    // Port Control Tests - Validation
    // =========================================================================

    public function testTogglePortWithInvalidPortName(): void
    {
        $result = $this->hardware->togglePort('InvalidPortName');

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertEquals('Invalid port name format', $result['error']);
    }

    public function testTogglePortWithEmptyPortName(): void
    {
        $result = $this->hardware->togglePort('');

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertEquals('Invalid port name format', $result['error']);
    }

    public function testTogglePortWithValidPortNameFormat(): void
    {
        // Valid format is "Port X" or "Port X-Y"
        $result = $this->hardware->togglePort('Port 1', 'on');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);

        // May succeed or fail depending on hardware, but should not fail validation
        if (!$result['success']) {
            $this->assertNotEquals('Invalid port name format', $result['error'] ?? '');
        }
    }

    public function testTogglePortWithSmartReceiverFormat(): void
    {
        $result = $this->hardware->togglePort('Port 1-A', 'on');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);

        // May succeed or fail depending on hardware, but should not fail validation
        if (!$result['success']) {
            $this->assertNotEquals('Invalid port name format', $result['error'] ?? '');
        }
    }

    public function testTogglePortWithInvalidState(): void
    {
        $result = $this->hardware->togglePort('Port 1', 'invalid_state');

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid state', $result['error']);
    }

    /**
     * @dataProvider invalidPortNameProvider
     */
    public function testTogglePortRejectsInvalidPortNames(string $portName): void
    {
        $result = $this->hardware->togglePort($portName, 'on');

        $this->assertFalse($result['success']);
        $this->assertEquals('Invalid port name format', $result['error']);
    }

    public static function invalidPortNameProvider(): array
    {
        return [
            'empty string' => [''],
            'just Port' => ['Port'],
            'port lowercase' => ['port 1'],
            'no space' => ['Port1'],
            'wrong format' => ['P1'],
            'injection attempt' => ['Port 1; ls'],
            'special chars' => ['Port <script>'],
            'negative number' => ['Port -1'],
            'invalid subport' => ['Port 1-Z'],
            'too many subports' => ['Port 1-A-B'],
        ];
    }

    /**
     * @dataProvider validPortNameProvider
     */
    public function testTogglePortAcceptsValidPortNames(string $portName): void
    {
        $result = $this->hardware->togglePort($portName, 'on');

        // Should pass validation (may fail for other reasons)
        if (!$result['success']) {
            $this->assertNotEquals('Invalid port name format', $result['error'] ?? '');
        }
    }

    public static function validPortNameProvider(): array
    {
        return [
            'single digit' => ['Port 1'],
            'double digit' => ['Port 16'],
            'high number' => ['Port 48'],
            'smart receiver A' => ['Port 1-A'],
            'smart receiver B' => ['Port 2-B'],
            'smart receiver C' => ['Port 10-C'],
            'smart receiver D' => ['Port 16-D'],
            'smart receiver E' => ['Port 32-E'],
            'smart receiver F' => ['Port 48-F'],
        ];
    }

    public function testTogglePortStateNormalization(): void
    {
        // Test various truthy values (must be strings for the method signature)
        $truthyStates = ['on', 'On', 'ON', 'true', '1'];
        $falsyStates = ['off', 'Off', 'OFF', 'false', '0'];

        foreach ($truthyStates as $state) {
            $result = $this->hardware->togglePort('Port 1', $state);
            // Should not fail with invalid state error
            if (!$result['success']) {
                $this->assertNotEquals('Invalid state', substr($result['error'] ?? '', 0, 13));
            }
        }

        foreach ($falsyStates as $state) {
            $result = $this->hardware->togglePort('Port 1', $state);
            // Should not fail with invalid state error
            if (!$result['success']) {
                $this->assertNotEquals('Invalid state', substr($result['error'] ?? '', 0, 13));
            }
        }
    }

    // =========================================================================
    // Reset Port Tests
    // =========================================================================

    public function testResetPortWithInvalidPortName(): void
    {
        $result = $this->hardware->resetPort('InvalidPort');

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertEquals('Invalid port name format', $result['error']);
    }

    public function testResetPortWithValidPortName(): void
    {
        $result = $this->hardware->resetPort('Port 1');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);

        // May succeed or fail depending on hardware
        if (!$result['success']) {
            $this->assertNotEquals('Invalid port name format', $result['error'] ?? '');
        }
    }

    public function testResetPortReturnStructure(): void
    {
        $result = $this->hardware->resetPort('Port 1');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);

        if ($result['success']) {
            $this->assertArrayHasKey('port', $result);
            $this->assertArrayHasKey('message', $result);
        } else {
            $this->assertArrayHasKey('error', $result);
        }
    }

    // =========================================================================
    // Set All Ports Tests
    // =========================================================================

    public function testSetAllPortsWithInvalidState(): void
    {
        $result = $this->hardware->setAllPorts('invalid');

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid state', $result['error']);
    }

    public function testSetAllPortsWithValidStateOn(): void
    {
        $result = $this->hardware->setAllPorts('on');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);

        // May succeed or fail depending on hardware availability
    }

    public function testSetAllPortsWithValidStateOff(): void
    {
        $result = $this->hardware->setAllPorts('off');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    public function testSetAllPortsReturnStructure(): void
    {
        $result = $this->hardware->setAllPorts('on');

        $this->assertIsArray($result);

        if ($result['success']) {
            $this->assertArrayHasKey('state', $result);
            $this->assertArrayHasKey('portsAffected', $result);
            $this->assertArrayHasKey('totalPorts', $result);
            $this->assertArrayHasKey('message', $result);
        } else {
            $this->assertArrayHasKey('error', $result);
        }
    }

    // =========================================================================
    // Reset All Tripped Fuses Tests
    // =========================================================================

    public function testResetAllTrippedFusesReturnsArray(): void
    {
        $result = $this->hardware->resetAllTrippedFuses();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('resetCount', $result);
        $this->assertArrayHasKey('ports', $result);
        $this->assertArrayHasKey('message', $result);
    }

    public function testResetAllTrippedFusesNoTrippedFuses(): void
    {
        $result = $this->hardware->resetAllTrippedFuses();

        // If no fuses are tripped, should return success with 0 count
        $this->assertIsArray($result);
        $this->assertArrayHasKey('resetCount', $result);
        $this->assertIsInt($result['resetCount']);
        $this->assertGreaterThanOrEqual(0, $result['resetCount']);
    }

    // =========================================================================
    // Sample Data Structure Tests
    // =========================================================================

    public function testSampleEfuseReadingsFormat(): void
    {
        // Load fixture for eFuse readings
        $fixtureData = $this->loadJsonFixture('data/sample_metrics.json');
        $efuseReadings = $fixtureData['efuse_readings'];

        // Verify structure
        foreach ($efuseReadings as $portName => $portData) {
            $this->assertStringContainsString('Port', $portName);
            $this->assertArrayHasKey('current', $portData);
            $this->assertArrayHasKey('enabled', $portData);
            $this->assertArrayHasKey('tripped', $portData);

            $this->assertIsFloat($portData['current']);
            $this->assertIsBool($portData['enabled']);
            $this->assertIsBool($portData['tripped']);
        }
    }

    public function testCalculateTotalCurrentWithFixtureData(): void
    {
        $fixtureData = $this->loadJsonFixture('data/sample_metrics.json');
        $efuseReadings = $fixtureData['efuse_readings'];

        // Extract just the current values (mA) from the fixture data
        // Note: fixture uses float Amps, convert to mA
        $currentReadings = [];
        foreach ($efuseReadings as $portName => $port) {
            $currentReadings[$portName] = (int)($port['current'] * 1000);
        }

        $result = $this->hardware->calculateTotalCurrent($currentReadings);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('totalMa', $result);
        $this->assertArrayHasKey('totalAmps', $result);
        $this->assertArrayHasKey('activePortCount', $result);
    }

    // =========================================================================
    // FPP Cache Tests
    // =========================================================================

    public function testGetFppdPortsCachedReturnsArrayOrNull(): void
    {
        $result = $this->hardware->getFppdPortsCached();

        $this->assertTrue(
            is_array($result) || is_null($result),
            'getFppdPortsCached should return array or null'
        );
    }

    // =========================================================================
    // detectPlatform Tests (via TestableEfuseHardware)
    // =========================================================================

    public function testDetectPlatformReturnsValidString(): void
    {
        $instance = TestableEfuseHardware::getTestInstance();
        $result = $instance->testDetectPlatform();

        $this->assertIsString($result);

        // Should return one of the known platforms
        $validPlatforms = ['bbb', 'pb', 'pi'];
        $this->assertContains($result, $validPlatforms);
    }

    // =========================================================================
    // getChannelOutputConfig Tests (via TestableEfuseHardware)
    // =========================================================================

    public function testGetChannelOutputConfigReturnsNullOrArray(): void
    {
        $instance = TestableEfuseHardware::getTestInstance();
        $result = $instance->testGetChannelOutputConfig();

        $this->assertTrue(
            is_null($result) || is_array($result),
            'getChannelOutputConfig should return null or array'
        );

        if (is_array($result)) {
            $this->assertArrayHasKey('type', $result);
            $this->assertArrayHasKey('subType', $result);
        }
    }

    // =========================================================================
    // loadStringConfig Tests (via TestableEfuseHardware)
    // =========================================================================

    public function testLoadStringConfigWithNonexistentSubType(): void
    {
        $instance = TestableEfuseHardware::getTestInstance();
        $result = $instance->testLoadStringConfig('nonexistent-subtype-xyz');

        $this->assertNull($result);
    }

    public function testLoadStringConfigReturnsNullOrArray(): void
    {
        $instance = TestableEfuseHardware::getTestInstance();

        // Try a common cape type
        $result = $instance->testLoadStringConfig('F8-B');

        $this->assertTrue(
            is_null($result) || is_array($result),
            'loadStringConfig should return null or array'
        );

        if (is_array($result)) {
            $this->assertArrayHasKey('outputs', $result);
        }
    }

    // =========================================================================
    // Edge Cases
    // =========================================================================

    public function testTogglePortWithNullState(): void
    {
        // null state should toggle based on current state
        $result = $this->hardware->togglePort('Port 1', null);

        $this->assertIsArray($result);
        // Either succeeds or fails for valid reasons (not validation)
    }

    public function testCalculateTotalCurrentWithLargeValues(): void
    {
        $readings = [
            'Port 1' => 6000, // Max typical eFuse rating
            'Port 2' => 6000,
            'Port 3' => 6000,
            'Port 4' => 6000,
        ];

        $result = $this->hardware->calculateTotalCurrent($readings);

        $this->assertEquals(24000, $result['totalMa']);
        $this->assertEquals(24.0, $result['totalAmps']);
        $this->assertEquals(4, $result['activePortCount']);
    }

    public function testCalculateTotalCurrentWithSmallValues(): void
    {
        $readings = [
            'Port 1' => 1,
            'Port 2' => 2,
            'Port 3' => 3,
        ];

        $result = $this->hardware->calculateTotalCurrent($readings);

        $this->assertEquals(6, $result['totalMa']);
        $this->assertEquals(0.01, $result['totalAmps']); // Rounded
        $this->assertEquals(3, $result['activePortCount']);
    }

    // =========================================================================
    // Port Name Regex Tests
    // =========================================================================

    public function testPortNameRegexMatches(): void
    {
        // Test the regex pattern used in togglePort and resetPort
        $pattern = '/^Port \d+(-[A-F])?$/';

        $validNames = [
            'Port 1', 'Port 10', 'Port 16', 'Port 48',
            'Port 1-A', 'Port 1-B', 'Port 1-C', 'Port 1-D', 'Port 1-E', 'Port 1-F',
            'Port 16-A', 'Port 48-F'
        ];

        foreach ($validNames as $name) {
            $this->assertMatchesRegularExpression($pattern, $name, "{$name} should match");
        }

        $invalidNames = [
            '', 'Port', 'Port ', 'port 1', 'Port1', 'Port-1',
            'Port 1-', 'Port 1-G', 'Port 1-a', 'Port 1-AB',
            'Port 0x1', 'Port -1', 'Port1-A'
        ];

        foreach ($invalidNames as $name) {
            $this->assertDoesNotMatchRegularExpression($pattern, $name, "{$name} should not match");
        }
    }
}
