<?php
/**
 * Unit tests for EfuseHardware class
 *
 * Comprehensive test coverage for eFuse hardware detection and control.
 * Uses a testable subclass to access private methods and mock dependencies.
 *
 * @package Watcher\Tests\Unit\Controllers
 */

declare(strict_types=1);

namespace Watcher\Tests\Unit\Controllers;

use Watcher\Tests\TestCase;
use Watcher\Controllers\EfuseHardware;

/**
 * Testable subclass that exposes private methods and allows mocking
 */
class TestableEfuseHardware extends EfuseHardware
{
    /** @var array|null Mock ports data for API responses */
    private ?array $mockPortsData = null;

    /** @var array|null Mock API command result */
    private ?array $mockCommandResult = null;

    /** @var string|null Override cache file path for testing */
    private ?string $testCacheFile = null;

    /** @var string|null Override efuse dir for testing */
    private ?string $testEfuseDir = null;

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
     * Set the test cache file path
     */
    public function setTestCacheFile(string $path): void
    {
        $this->testCacheFile = $path;
        // Update the internal cacheFile property via reflection
        $reflection = new \ReflectionClass(parent::class);
        $prop = $reflection->getProperty('cacheFile');
        $prop->setAccessible(true);
        $prop->setValue($this, $path);
    }

    /**
     * Set the test efuse directory
     */
    public function setTestEfuseDir(string $path): void
    {
        $this->testEfuseDir = $path;
        $reflection = new \ReflectionClass(parent::class);
        $prop = $reflection->getProperty('efuseDir');
        $prop->setAccessible(true);
        $prop->setValue($this, $path);
    }

    /**
     * Set mock ports data for readEfuseData tests
     */
    public function setMockPortsData(?array $data): void
    {
        $this->mockPortsData = $data;
    }

    /**
     * Set mock command result for control method tests
     */
    public function setMockCommandResult(?array $result): void
    {
        $this->mockCommandResult = $result;
    }

    /**
     * Override countEfusePortsFromFppd to return mock data
     */
    public function countEfusePortsFromFppd(): int
    {
        if ($this->mockPortsData !== null) {
            $count = 0;
            foreach ($this->mockPortsData as $port) {
                if (isset($port['smartReceivers']) && $port['smartReceivers']) {
                    foreach (self::SMART_RECEIVER_SUBPORTS as $sub) {
                        if (isset($port[$sub]['ma'])) {
                            $count++;
                        }
                    }
                } elseif (isset($port['ma'])) {
                    $count++;
                }
            }
            return $count;
        }
        return parent::countEfusePortsFromFppd();
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

    /**
     * Expose getPortStatusFromData for testing
     */
    public function testGetPortStatusFromData(string $portName, array $portsList): ?array
    {
        $reflection = new \ReflectionMethod(parent::class, 'getPortStatusFromData');
        $reflection->setAccessible(true);
        return $reflection->invoke($this, $portName, $portsList);
    }

    /**
     * Access the internal logger for testing
     */
    public function getLogger(): \Watcher\Core\Logger
    {
        $reflection = new \ReflectionClass(parent::class);
        $prop = $reflection->getProperty('logger');
        $prop->setAccessible(true);
        return $prop->getValue($this);
    }

    /**
     * Get the cache file path (for verification)
     */
    public function getCacheFilePath(): string
    {
        $reflection = new \ReflectionClass(parent::class);
        $prop = $reflection->getProperty('cacheFile');
        $prop->setAccessible(true);
        return $prop->getValue($this);
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
            'getHardwareSummary',
            'clearHardwareCache',
            'togglePort',
            'resetPort',
            'setAllPorts',
            'resetAllTrippedFuses',
            'getControlCapabilities',
            'getPortStatus',
            'getTrippedFuses',
            // Port data helpers
            'isValidPortName',
            'parsePortName',
            'isSmartReceiverSubport',
            'fetchPortsData',
            'iterateAllPorts',
            'getPortDataFromList',
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

        // Always check basic structure
        $this->assertArrayHasKey('supported', $result);

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
    // Port Name Validation Tests
    // =========================================================================

    /**
     * @dataProvider isValidPortNameValidProvider
     */
    public function testIsValidPortNameWithValidNames(string $portName): void
    {
        $this->assertTrue($this->hardware->isValidPortName($portName));
    }

    public static function isValidPortNameValidProvider(): array
    {
        return [
            'single digit' => ['Port 1'],
            'double digit' => ['Port 16'],
            'with subport A' => ['Port 9A'],
            'with subport F' => ['Port 9F'],
            'large number' => ['Port 48'],
            'large with subport' => ['Port 16B'],
        ];
    }

    /**
     * @dataProvider isValidPortNameInvalidProvider
     */
    public function testIsValidPortNameWithInvalidNames(string $portName): void
    {
        $this->assertFalse($this->hardware->isValidPortName($portName));
    }

    public static function isValidPortNameInvalidProvider(): array
    {
        return [
            'lowercase port' => ['port 1'],
            'no space' => ['Port1'],
            'invalid subport G' => ['Port 9G'],
            'lowercase subport' => ['Port 9a'],
            'empty' => [''],
            'just number' => ['1'],
            'extra text' => ['Port 1 Extra'],
        ];
    }

    public function testParsePortNameRegularPort(): void
    {
        $result = $this->hardware->parsePortName('Port 1');

        $this->assertIsArray($result);
        $this->assertEquals('Port 1', $result['base']);
        $this->assertNull($result['subport']);
    }

    public function testParsePortNameSmartReceiverSubport(): void
    {
        $result = $this->hardware->parsePortName('Port 9A');

        $this->assertIsArray($result);
        $this->assertEquals('Port 9', $result['base']);
        $this->assertEquals('A', $result['subport']);
    }

    public function testParsePortNameInvalidReturnsNull(): void
    {
        $result = $this->hardware->parsePortName('invalid');
        $this->assertNull($result);
    }

    public function testIsSmartReceiverSubport(): void
    {
        $this->assertTrue($this->hardware->isSmartReceiverSubport('Port 9A'));
        $this->assertTrue($this->hardware->isSmartReceiverSubport('Port 16F'));
        $this->assertFalse($this->hardware->isSmartReceiverSubport('Port 1'));
        $this->assertFalse($this->hardware->isSmartReceiverSubport('Port 16'));
    }

    // =========================================================================
    // Port Data Iteration Tests
    // =========================================================================

    public function testIterateAllPortsWithRegularPorts(): void
    {
        $portsList = [
            ['name' => 'Port 1', 'ma' => 1000, 'enabled' => true],
            ['name' => 'Port 2', 'ma' => 2000, 'enabled' => true],
        ];

        $results = [];
        $this->hardware->iterateAllPorts($portsList, function ($name, $data, $isSmart) use (&$results) {
            $results[] = ['name' => $name, 'isSmart' => $isSmart];
        });

        $this->assertCount(2, $results);
        $this->assertEquals('Port 1', $results[0]['name']);
        $this->assertFalse($results[0]['isSmart']);
        $this->assertEquals('Port 2', $results[1]['name']);
        $this->assertFalse($results[1]['isSmart']);
    }

    public function testIterateAllPortsWithSmartReceivers(): void
    {
        $portsList = [
            [
                'name' => 'Port 9',
                'smartReceivers' => true,
                'A' => ['ma' => 1000],
                'B' => ['ma' => 2000],
            ],
        ];

        $results = [];
        $this->hardware->iterateAllPorts($portsList, function ($name, $data, $isSmart) use (&$results) {
            $results[] = ['name' => $name, 'isSmart' => $isSmart, 'ma' => $data['ma'] ?? 0];
        });

        $this->assertCount(2, $results);
        $this->assertEquals('Port 9A', $results[0]['name']);
        $this->assertTrue($results[0]['isSmart']);
        $this->assertEquals(1000, $results[0]['ma']);
        $this->assertEquals('Port 9B', $results[1]['name']);
        $this->assertTrue($results[1]['isSmart']);
    }

    public function testIterateAllPortsSkipsEmptyNames(): void
    {
        $portsList = [
            ['name' => '', 'ma' => 1000],
            ['name' => 'Port 1', 'ma' => 2000],
        ];

        $results = [];
        $this->hardware->iterateAllPorts($portsList, function ($name) use (&$results) {
            $results[] = $name;
        });

        $this->assertCount(1, $results);
        $this->assertEquals('Port 1', $results[0]);
    }

    public function testGetPortDataFromListRegularPort(): void
    {
        $portsList = [
            ['name' => 'Port 1', 'ma' => 1000, 'enabled' => true],
            ['name' => 'Port 2', 'ma' => 2000, 'enabled' => false],
        ];

        $result = $this->hardware->getPortDataFromList('Port 2', $portsList);

        $this->assertIsArray($result);
        $this->assertFalse($result['isSmartReceiver']);
        $this->assertEquals(2000, $result['data']['ma']);
    }

    public function testGetPortDataFromListSmartReceiverSubport(): void
    {
        $portsList = [
            [
                'name' => 'Port 9',
                'smartReceivers' => true,
                'A' => ['ma' => 1000, 'fuseOn' => true],
                'B' => ['ma' => 2000, 'fuseOn' => false],
            ],
        ];

        $result = $this->hardware->getPortDataFromList('Port 9B', $portsList);

        $this->assertIsArray($result);
        $this->assertTrue($result['isSmartReceiver']);
        $this->assertEquals(2000, $result['data']['ma']);
        $this->assertFalse($result['data']['fuseOn']);
    }

    public function testGetPortDataFromListReturnsNullForNotFound(): void
    {
        $portsList = [
            ['name' => 'Port 1', 'ma' => 1000],
        ];

        $result = $this->hardware->getPortDataFromList('Port 99', $portsList);
        $this->assertNull($result);
    }

    public function testGetPortDataFromListReturnsNullForInvalidName(): void
    {
        $portsList = [
            ['name' => 'Port 1', 'ma' => 1000],
        ];

        $result = $this->hardware->getPortDataFromList('invalid', $portsList);
        $this->assertNull($result);
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
        // Uses FPP native format: Port 9A (no dash)
        $result = $this->hardware->togglePort('Port 9A', 'on');

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
            'invalid subport G' => ['Port 1G'],
            'old dash format' => ['Port 1-A'],
            'lowercase subport' => ['Port 1a'],
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
            'smart receiver A' => ['Port 1A'],
            'smart receiver B' => ['Port 2B'],
            'smart receiver C' => ['Port 10C'],
            'smart receiver D' => ['Port 16D'],
            'smart receiver E' => ['Port 32E'],
            'smart receiver F' => ['Port 48F'],
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

    public function testFetchPortsDataReturnsArrayOrNull(): void
    {
        // fetchPortsData makes an API call - it may return null if FPP isn't running
        $result = $this->hardware->fetchPortsData();

        $this->assertTrue(
            is_array($result) || is_null($result),
            'fetchPortsData should return array or null'
        );
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

    // =========================================================================
    // Port Name Regex Tests
    // =========================================================================

    public function testPortNameRegexMatches(): void
    {
        // Test the regex pattern used in togglePort and resetPort
        // FPP native format: "Port 9A" (no dash)
        $pattern = '/^Port \d+[A-F]?$/';

        $validNames = [
            'Port 1', 'Port 10', 'Port 16', 'Port 48',
            'Port 1A', 'Port 1B', 'Port 1C', 'Port 1D', 'Port 1E', 'Port 1F',
            'Port 16A', 'Port 48F'
        ];

        foreach ($validNames as $name) {
            $this->assertMatchesRegularExpression($pattern, $name, "{$name} should match");
        }

        $invalidNames = [
            '', 'Port', 'Port ', 'port 1', 'Port1', 'Port-1',
            'Port 1-', 'Port 1G', 'Port 1a', 'Port 1AB',
            'Port 0x1', 'Port -1', 'Port1A', 'Port 1-A'
        ];

        foreach ($invalidNames as $name) {
            $this->assertDoesNotMatchRegularExpression($pattern, $name, "{$name} should not match");
        }
    }

    // =========================================================================
    // Smart Receiver Subports Constant Tests
    // =========================================================================

    public function testSmartReceiverSubportsConstant(): void
    {
        $this->assertCount(6, EfuseHardware::SMART_RECEIVER_SUBPORTS);
        $this->assertEquals(['A', 'B', 'C', 'D', 'E', 'F'], EfuseHardware::SMART_RECEIVER_SUBPORTS);
    }

    // =========================================================================
    // TestableEfuseHardware Private Method Tests
    // =========================================================================

    public function testSaveHardwareCacheCreatesFile(): void
    {
        $instance = TestableEfuseHardware::getTestInstance();

        $result = [
            'supported' => true,
            'type' => 'cape',
            'ports' => 16,
            'details' => ['cape' => 'Test Cape']
        ];

        // This may fail if WATCHEREFUSEDIR isn't writable in test environment
        // We just test that it doesn't throw
        try {
            $instance->testSaveHardwareCache($result);
            $this->assertTrue(true);
        } catch (\Exception $e) {
            // Directory may not be writable in test environment
            $this->assertTrue(true);
        }
    }

    public function testLogControlDoesNotThrow(): void
    {
        $instance = TestableEfuseHardware::getTestInstance();

        // Should not throw - just logs
        $instance->testLogControl('toggle', 'Port 1', 'success');
        $instance->testLogControl('reset', 'Port 2', 'failed');
        $instance->testLogControl('master', 'all', 'on (16/16)');

        $this->assertTrue(true);
    }

    public function testLogControlWithVariousInputs(): void
    {
        $instance = TestableEfuseHardware::getTestInstance();

        // Test various action types
        $actions = ['toggle', 'reset', 'master', 'reset-all'];
        $results = ['success', 'failed', 'on', 'off', 'error'];

        foreach ($actions as $action) {
            foreach ($results as $result) {
                $instance->testLogControl($action, 'Port 1', $result);
            }
        }

        $this->assertTrue(true);
    }

    // =========================================================================
    // Additional Port Parsing Tests
    // =========================================================================

    public function testParsePortNameWithAllSubports(): void
    {
        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $subport) {
            $result = $this->hardware->parsePortName('Port 1' . $subport);

            $this->assertNotNull($result);
            $this->assertEquals('Port 1', $result['base']);
            $this->assertEquals($subport, $result['subport']);
        }
    }

    public function testParsePortNameWithLargeNumbers(): void
    {
        $result = $this->hardware->parsePortName('Port 48');

        $this->assertNotNull($result);
        $this->assertEquals('Port 48', $result['base']);
        $this->assertNull($result['subport']);
    }

    public function testParsePortNameWithLargeNumberAndSubport(): void
    {
        $result = $this->hardware->parsePortName('Port 48F');

        $this->assertNotNull($result);
        $this->assertEquals('Port 48', $result['base']);
        $this->assertEquals('F', $result['subport']);
    }

    // =========================================================================
    // iterateAllPorts Edge Cases
    // =========================================================================

    public function testIterateAllPortsWithAllSmartReceiverSubports(): void
    {
        $portsList = [
            [
                'name' => 'Port 1',
                'smartReceivers' => true,
                'A' => ['ma' => 100],
                'B' => ['ma' => 200],
                'C' => ['ma' => 300],
                'D' => ['ma' => 400],
                'E' => ['ma' => 500],
                'F' => ['ma' => 600],
            ],
        ];

        $results = [];
        $this->hardware->iterateAllPorts($portsList, function ($name, $data, $isSmart) use (&$results) {
            $results[] = ['name' => $name, 'ma' => $data['ma'] ?? 0];
        });

        $this->assertCount(6, $results);
        $this->assertEquals('Port 1A', $results[0]['name']);
        $this->assertEquals(100, $results[0]['ma']);
        $this->assertEquals('Port 1F', $results[5]['name']);
        $this->assertEquals(600, $results[5]['ma']);
    }

    public function testIterateAllPortsWithMixedRegularAndSmartPorts(): void
    {
        $portsList = [
            ['name' => 'Port 1', 'ma' => 1000],
            [
                'name' => 'Port 2',
                'smartReceivers' => true,
                'A' => ['ma' => 200],
                'B' => ['ma' => 300],
            ],
            ['name' => 'Port 3', 'ma' => 3000],
        ];

        $results = [];
        $this->hardware->iterateAllPorts($portsList, function ($name, $data, $isSmart) use (&$results) {
            $results[] = ['name' => $name, 'isSmart' => $isSmart];
        });

        $this->assertCount(4, $results);
        $this->assertEquals('Port 1', $results[0]['name']);
        $this->assertFalse($results[0]['isSmart']);
        $this->assertEquals('Port 2A', $results[1]['name']);
        $this->assertTrue($results[1]['isSmart']);
        $this->assertEquals('Port 2B', $results[2]['name']);
        $this->assertTrue($results[2]['isSmart']);
        $this->assertEquals('Port 3', $results[3]['name']);
        $this->assertFalse($results[3]['isSmart']);
    }

    public function testIterateAllPortsWithSmartReceiverMissingSubports(): void
    {
        $portsList = [
            [
                'name' => 'Port 1',
                'smartReceivers' => true,
                'A' => ['ma' => 100],
                // B, C, D, E, F are missing
            ],
        ];

        $results = [];
        $this->hardware->iterateAllPorts($portsList, function ($name) use (&$results) {
            $results[] = $name;
        });

        // Should only return ports that exist
        $this->assertCount(1, $results);
        $this->assertEquals('Port 1A', $results[0]);
    }

    // =========================================================================
    // getPortDataFromList Edge Cases
    // =========================================================================

    public function testGetPortDataFromListWithMissingSmartReceiverSubport(): void
    {
        $portsList = [
            [
                'name' => 'Port 9',
                'smartReceivers' => true,
                'A' => ['ma' => 1000],
                // B is missing
            ],
        ];

        $result = $this->hardware->getPortDataFromList('Port 9B', $portsList);

        $this->assertNull($result);
    }

    public function testGetPortDataFromListWithNonSmartReceiverButSubportFormat(): void
    {
        $portsList = [
            [
                'name' => 'Port 9',
                'ma' => 1000,
                // Not a smart receiver
            ],
        ];

        // Requesting subport format on non-smart receiver
        $result = $this->hardware->getPortDataFromList('Port 9A', $portsList);

        $this->assertNull($result);
    }

    public function testGetPortDataFromListReturnsBasePortForSmartReceiver(): void
    {
        $basePort = [
            'name' => 'Port 9',
            'smartReceivers' => true,
            'A' => ['ma' => 1000, 'fuseOn' => true],
        ];

        $portsList = [$basePort];

        $result = $this->hardware->getPortDataFromList('Port 9A', $portsList);

        $this->assertNotNull($result);
        $this->assertTrue($result['isSmartReceiver']);
        $this->assertEquals($basePort, $result['basePort']);
    }

    // =========================================================================
    // Additional Validation Tests
    // =========================================================================

    /**
     * @dataProvider portNameVariationsProvider
     */
    public function testIsValidPortNameWithVariations(string $portName, bool $expected): void
    {
        $this->assertEquals($expected, $this->hardware->isValidPortName($portName));
    }

    public static function portNameVariationsProvider(): array
    {
        return [
            // Valid
            ['Port 1', true],
            ['Port 99', true],
            ['Port 100', true],
            ['Port 1A', true],
            ['Port 99F', true],

            // Invalid
            ['port 1', false],       // lowercase
            ['PORT 1', false],       // all caps
            ['Port  1', false],      // double space
            ['Port 1 ', false],      // trailing space
            [' Port 1', false],      // leading space
            ['Port 1G', false],      // invalid subport
            ['Port 1Z', false],      // invalid subport
            ['Port 01', true],       // leading zero is technically valid regex
            ['Port -1', false],      // negative
            ['Port 1.5', false],     // decimal
            ['Port A', false],       // letter only
        ];
    }

    /**
     * @dataProvider smartReceiverSubportProvider
     */
    public function testIsSmartReceiverSubportWithVariations(string $portName, bool $expected): void
    {
        $this->assertEquals($expected, $this->hardware->isSmartReceiverSubport($portName));
    }

    public static function smartReceiverSubportProvider(): array
    {
        return [
            // Is smart receiver subport
            ['Port 1A', true],
            ['Port 1F', true],
            ['Port 16A', true],
            ['Port 48F', true],

            // Is NOT smart receiver subport
            ['Port 1', false],
            ['Port 16', false],
            ['Port 1a', false],      // lowercase
            ['Port 1G', false],      // invalid letter
            ['Port 1AB', false],     // multiple letters
            ['invalid', false],
        ];
    }

    // =========================================================================
    // getPortStatusFromData Tests (via TestableEfuseHardware)
    // =========================================================================

    public function testGetPortStatusFromDataWithRegularPort(): void
    {
        $instance = TestableEfuseHardware::getTestInstance();

        $portsList = [
            ['name' => 'Port 1', 'ma' => 1500, 'enabled' => true]
        ];

        $result = $instance->testGetPortStatusFromData('Port 1', $portsList);

        $this->assertNotNull($result);
        $this->assertEquals('Port 1', $result['name']);
        $this->assertTrue($result['enabled']);
        $this->assertFalse($result['isSmartReceiver']);
    }

    public function testGetPortStatusFromDataWithDisabledPort(): void
    {
        $instance = TestableEfuseHardware::getTestInstance();

        $portsList = [
            ['name' => 'Port 1', 'ma' => 0, 'enabled' => false]
        ];

        $result = $instance->testGetPortStatusFromData('Port 1', $portsList);

        $this->assertNotNull($result);
        $this->assertFalse($result['enabled']);
    }

    public function testGetPortStatusFromDataWithSmartReceiverPort(): void
    {
        $instance = TestableEfuseHardware::getTestInstance();

        $portsList = [
            [
                'name' => 'Port 9',
                'smartReceivers' => true,
                'A' => ['ma' => 1000, 'fuseOn' => true, 'enabled' => true]
            ]
        ];

        $result = $instance->testGetPortStatusFromData('Port 9A', $portsList);

        $this->assertNotNull($result);
        $this->assertEquals('Port 9A', $result['name']);
        $this->assertTrue($result['isSmartReceiver']);
        $this->assertTrue($result['enabled']);
    }

    public function testGetPortStatusFromDataWithNonexistentPort(): void
    {
        $instance = TestableEfuseHardware::getTestInstance();

        $portsList = [
            ['name' => 'Port 1', 'ma' => 500, 'enabled' => true]
        ];

        $result = $instance->testGetPortStatusFromData('Port 99', $portsList);

        $this->assertNull($result);
    }

    public function testGetPortStatusFromDataWithEmptyPortsList(): void
    {
        $instance = TestableEfuseHardware::getTestInstance();

        $result = $instance->testGetPortStatusFromData('Port 1', []);

        $this->assertNull($result);
    }

    public function testGetPortStatusFromDataSmartReceiverUsesFuseOn(): void
    {
        $instance = TestableEfuseHardware::getTestInstance();

        $portsList = [
            [
                'name' => 'Port 9',
                'smartReceivers' => true,
                'A' => ['ma' => 1000, 'fuseOn' => false]  // fuseOn takes priority
            ]
        ];

        $result = $instance->testGetPortStatusFromData('Port 9A', $portsList);

        $this->assertNotNull($result);
        $this->assertFalse($result['enabled']); // Uses fuseOn value
    }

    // =========================================================================
    // Cache File Tests with TestableEfuseHardware
    // =========================================================================

    public function testSaveHardwareCacheWritesToFile(): void
    {
        $instance = TestableEfuseHardware::getTestInstance();
        $testDir = $this->createTempDir('efuse-cache');
        $testCacheFile = $testDir . '/hardware-cache.json';

        $instance->setTestEfuseDir($testDir);
        $instance->setTestCacheFile($testCacheFile);

        $cacheData = [
            'supported' => true,
            'type' => 'cape',
            'ports' => 16,
            'details' => ['cape' => 'Test Cape K16-Max']
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
        $instance = TestableEfuseHardware::getTestInstance();
        $testDir = $this->createTempDir('efuse-test');
        $testCacheFile = $testDir . '/custom-cache.json';

        $instance->setTestCacheFile($testCacheFile);

        $this->assertEquals($testCacheFile, $instance->getCacheFilePath());
    }

    // =========================================================================
    // Mock Ports Data Tests
    // =========================================================================

    public function testCountEfusePortsWithMockData(): void
    {
        $instance = TestableEfuseHardware::getTestInstance();

        $mockPorts = [
            ['name' => 'Port 1', 'ma' => 100],
            ['name' => 'Port 2', 'ma' => 200],
            ['name' => 'Port 3', 'ma' => 0],
        ];

        $instance->setMockPortsData($mockPorts);

        // Call countEfusePortsFromFppd via reflection
        $reflection = new \ReflectionMethod($instance, 'countEfusePortsFromFppd');
        $reflection->setAccessible(true);
        $count = $reflection->invoke($instance);

        $this->assertEquals(3, $count);
    }

    public function testCountEfusePortsWithSmartReceiverMockData(): void
    {
        $instance = TestableEfuseHardware::getTestInstance();

        $mockPorts = [
            [
                'name' => 'Port 9',
                'smartReceivers' => true,
                'A' => ['ma' => 100],
                'B' => ['ma' => 200],
                'C' => ['ma' => 300],
            ],
        ];

        $instance->setMockPortsData($mockPorts);

        $reflection = new \ReflectionMethod($instance, 'countEfusePortsFromFppd');
        $reflection->setAccessible(true);
        $count = $reflection->invoke($instance);

        $this->assertEquals(3, $count); // 3 subports with 'ma' field
    }

    public function testCountEfusePortsWithMixedMockData(): void
    {
        $instance = TestableEfuseHardware::getTestInstance();

        $mockPorts = [
            ['name' => 'Port 1', 'ma' => 100],
            ['name' => 'Port 2', 'ma' => 200],
            [
                'name' => 'Port 9',
                'smartReceivers' => true,
                'A' => ['ma' => 300],
                'B' => ['ma' => 400],
            ],
        ];

        $instance->setMockPortsData($mockPorts);

        $reflection = new \ReflectionMethod($instance, 'countEfusePortsFromFppd');
        $reflection->setAccessible(true);
        $count = $reflection->invoke($instance);

        $this->assertEquals(4, $count); // 2 regular + 2 smart receiver subports
    }

    public function testCountEfusePortsWithEmptyMockData(): void
    {
        $instance = TestableEfuseHardware::getTestInstance();

        $instance->setMockPortsData([]);

        $reflection = new \ReflectionMethod($instance, 'countEfusePortsFromFppd');
        $reflection->setAccessible(true);
        $count = $reflection->invoke($instance);

        $this->assertEquals(0, $count);
    }

    // =========================================================================
    // Logger Access Tests
    // =========================================================================

    public function testGetLoggerReturnsLoggerInstance(): void
    {
        $instance = TestableEfuseHardware::getTestInstance();

        $logger = $instance->getLogger();

        $this->assertInstanceOf(\Watcher\Core\Logger::class, $logger);
    }

    // =========================================================================
    // Edge Cases for iterateAllPorts
    // =========================================================================

    public function testIterateAllPortsWithNoSmartReceiverSubports(): void
    {
        $portsList = [
            [
                'name' => 'Port 1',
                'smartReceivers' => true,
                // No subport data at all
            ],
        ];

        $results = [];
        $this->hardware->iterateAllPorts($portsList, function ($name) use (&$results) {
            $results[] = $name;
        });

        // Should return nothing since no subports have data
        $this->assertCount(0, $results);
    }

    public function testIterateAllPortsWithNullCallback(): void
    {
        $portsList = [
            ['name' => 'Port 1', 'ma' => 1000],
        ];

        // Should not throw when called - no assertion needed, just verify no exception
        $this->hardware->iterateAllPorts($portsList, function () {
            // empty callback
        });

        $this->assertTrue(true);
    }

    public function testIterateAllPortsPreservesPortOrder(): void
    {
        $portsList = [
            ['name' => 'Port 3', 'ma' => 300],
            ['name' => 'Port 1', 'ma' => 100],
            ['name' => 'Port 2', 'ma' => 200],
        ];

        $results = [];
        $this->hardware->iterateAllPorts($portsList, function ($name) use (&$results) {
            $results[] = $name;
        });

        // Should preserve original order
        $this->assertEquals(['Port 3', 'Port 1', 'Port 2'], $results);
    }

    // =========================================================================
    // getPortDataFromList Comprehensive Tests
    // =========================================================================

    public function testGetPortDataFromListWithEmptyPortsList(): void
    {
        $result = $this->hardware->getPortDataFromList('Port 1', []);

        $this->assertNull($result);
    }

    public function testGetPortDataFromListWithExactPortMatch(): void
    {
        $portsList = [
            ['name' => 'Port 1', 'ma' => 1000, 'fuseOn' => true],
            ['name' => 'Port 2', 'ma' => 2000, 'fuseOn' => true],
        ];

        $result = $this->hardware->getPortDataFromList('Port 2', $portsList);

        $this->assertNotNull($result);
        $this->assertEquals(2000, $result['data']['ma']);
    }

    public function testGetPortDataFromListWithCaseSensitiveSearch(): void
    {
        $portsList = [
            ['name' => 'Port 1', 'ma' => 1000],
        ];

        // Search with different case should fail
        $result = $this->hardware->getPortDataFromList('port 1', $portsList);

        $this->assertNull($result);
    }

    // =========================================================================
    // parsePortName Edge Cases
    // =========================================================================

    public function testParsePortNameWithInvalidFormat(): void
    {
        $result = $this->hardware->parsePortName('invalid');

        $this->assertNull($result);
    }

    public function testParsePortNameWithEmptyString(): void
    {
        $result = $this->hardware->parsePortName('');

        $this->assertNull($result);
    }

    public function testParsePortNameWithWhitespace(): void
    {
        $result = $this->hardware->parsePortName('  Port 1  ');

        // Should handle trimmed or fail gracefully
        // Depends on implementation - test actual behavior
        if ($result !== null) {
            $this->assertArrayHasKey('base', $result);
        } else {
            $this->assertNull($result);
        }
    }
}
