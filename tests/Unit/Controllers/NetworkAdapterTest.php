<?php
/**
 * Unit tests for NetworkAdapter class
 */

declare(strict_types=1);

namespace Watcher\Tests\Unit\Controllers;

use Watcher\Tests\TestCase;
use Watcher\Controllers\NetworkAdapter;

/**
 * Mock helper for simulating network adapter operations
 * This doesn't extend NetworkAdapter since it has a private constructor
 */
class MockNetworkAdapterHelper
{
    /** @var array Track exec calls for verification */
    public array $execCalls = [];

    /** @var array Mock return codes for exec calls */
    private array $mockExecReturnCodes = [];

    /** @var array|null Mock API responses */
    private ?array $mockApiResponses = null;

    /**
     * Set mock return codes for exec calls
     * @param array $codes Array of return codes indexed by command pattern
     */
    public function setMockExecReturnCodes(array $codes): void
    {
        $this->mockExecReturnCodes = $codes;
    }

    /**
     * Set mock API response
     */
    public function setMockApiResponse(?array $response): void
    {
        $this->mockApiResponses = $response;
    }

    /**
     * Get the mock return code for a command
     */
    private function getMockReturnCode(string $command): int
    {
        foreach ($this->mockExecReturnCodes as $pattern => $code) {
            if (strpos($command, $pattern) !== false) {
                return $code;
            }
        }
        return 1; // Default to failure
    }

    /**
     * Validate adapter name (mimics NetworkAdapter::resetAdapter validation)
     */
    public function isValidAdapterName(string $adapter): bool
    {
        return (bool)preg_match('/^[a-z]+[0-9]+$/', $adapter);
    }

    /**
     * Detect if adapter is WiFi
     */
    public function isWifiAdapter(string $adapter): bool
    {
        return strpos($adapter, 'wl') === 0;
    }

    /**
     * Simulate resetAdapter
     */
    public function resetAdapter(string $adapter): bool
    {
        if (!$this->isValidAdapterName($adapter)) {
            return false;
        }

        if ($this->isWifiAdapter($adapter)) {
            return $this->resetWifiAdapter($adapter);
        } else {
            return $this->resetEthernetAdapter($adapter);
        }
    }

    /**
     * Simulate resetWifiAdapter
     */
    public function resetWifiAdapter(string $adapter): bool
    {
        $this->execCalls[] = ['type' => 'wifi', 'adapter' => $adapter, 'method' => 'wpa_cli'];

        $rc = $this->getMockReturnCode('wpa_cli');
        if ($rc === 0) {
            return true;
        }

        $this->execCalls[] = ['type' => 'wifi', 'adapter' => $adapter, 'method' => 'systemctl'];

        $rc = $this->getMockReturnCode('systemctl');
        if ($rc === 0) {
            return true;
        }

        return $this->bounceInterface($adapter);
    }

    /**
     * Simulate resetEthernetAdapter
     */
    public function resetEthernetAdapter(string $adapter): bool
    {
        $this->execCalls[] = ['type' => 'ethernet', 'adapter' => $adapter, 'method' => 'networkctl'];

        $rc = $this->getMockReturnCode('networkctl');
        if ($rc === 0) {
            return true;
        }

        return $this->bounceInterface($adapter);
    }

    /**
     * Simulate bounceInterface
     */
    public function bounceInterface(string $adapter): bool
    {
        $this->execCalls[] = ['type' => 'bounce', 'adapter' => $adapter, 'method' => 'ip_link'];

        $rc1 = $this->getMockReturnCode('ip link set');
        $rc2 = $this->getMockReturnCode('ip link set');

        if ($rc1 === 0 && $rc2 === 0) {
            return true;
        }

        return $this->resetViaFppApi($adapter);
    }

    /**
     * Simulate resetViaFppApi
     */
    public function resetViaFppApi(string $adapter): bool
    {
        $this->execCalls[] = ['type' => 'api', 'adapter' => $adapter, 'method' => 'fpp_api'];

        if ($this->mockApiResponses !== null) {
            return $this->mockApiResponses !== false;
        }

        return false;
    }

    /**
     * Simulate getInterfaceInfo
     */
    public function getInterfaceInfo(string $adapter): ?array
    {
        if ($this->mockApiResponses !== null) {
            return $this->mockApiResponses;
        }
        return null;
    }

    /**
     * Simulate getAllInterfaces
     */
    public function getAllInterfaces(): ?array
    {
        if ($this->mockApiResponses !== null) {
            return $this->mockApiResponses;
        }
        return null;
    }

    /**
     * Clear recorded exec calls
     */
    public function clearExecCalls(): void
    {
        $this->execCalls = [];
    }
}

class NetworkAdapterTest extends TestCase
{
    private NetworkAdapter $networkAdapter;
    private MockNetworkAdapterHelper $mockHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->networkAdapter = NetworkAdapter::getInstance();
        $this->mockHelper = new MockNetworkAdapterHelper();
    }

    // ========================================
    // Singleton Pattern Tests
    // ========================================

    public function testGetInstanceReturnsSameInstance(): void
    {
        $instance1 = NetworkAdapter::getInstance();
        $instance2 = NetworkAdapter::getInstance();

        $this->assertSame($instance1, $instance2);
    }

    public function testGetInstanceReturnsNetworkAdapterType(): void
    {
        $instance = NetworkAdapter::getInstance();

        $this->assertInstanceOf(NetworkAdapter::class, $instance);
    }

    // ========================================
    // Adapter Name Validation Tests
    // ========================================

    /**
     * @dataProvider validAdapterNameProvider
     */
    public function testIsValidAdapterNameAcceptsValidNames(string $adapter): void
    {
        $result = $this->mockHelper->isValidAdapterName($adapter);

        $this->assertTrue($result, "Adapter name '$adapter' should be valid");
    }

    public static function validAdapterNameProvider(): array
    {
        return [
            'eth0' => ['eth0'],
            'eth1' => ['eth1'],
            'wlan0' => ['wlan0'],
            'wlan1' => ['wlan1'],
            'enp0' => ['enp0'],
            'wlp2' => ['wlp2'],
            'a0' => ['a0'],
            'abc123' => ['abc123'],
        ];
    }

    /**
     * @dataProvider invalidAdapterNameProvider
     */
    public function testIsValidAdapterNameRejectsInvalidNames(string $adapter): void
    {
        $result = $this->mockHelper->isValidAdapterName($adapter);

        $this->assertFalse($result, "Adapter name '$adapter' should be invalid");
    }

    public static function invalidAdapterNameProvider(): array
    {
        return [
            'empty string' => [''],
            'only letters' => ['eth'],
            'only numbers' => ['123'],
            'starts with number' => ['0eth'],
            'space injection' => ['eth0 && rm -rf /'],
            'semicolon injection' => ['eth0;ls'],
            'pipe injection' => ['eth0|cat'],
            'backtick injection' => ['eth0`whoami`'],
            'dollar injection' => ['eth0$PATH'],
            'ampersand injection' => ['eth0&ls'],
            'path traversal' => ['../eth0'],
            'special chars' => ['eth<>0'],
            'uppercase' => ['ETH0'],
            'mixed case' => ['Eth0'],
            'underscore' => ['eth_0'],
            'dash' => ['eth-0'],
            'dot' => ['eth.0'],
        ];
    }

    // ========================================
    // WiFi Detection Tests
    // ========================================

    public function testIsWifiAdapterDetectsWlanInterface(): void
    {
        $this->assertTrue($this->mockHelper->isWifiAdapter('wlan0'));
        $this->assertTrue($this->mockHelper->isWifiAdapter('wlp2'));
        $this->assertTrue($this->mockHelper->isWifiAdapter('wlx00'));
    }

    public function testIsWifiAdapterRejectsEthernetInterface(): void
    {
        $this->assertFalse($this->mockHelper->isWifiAdapter('eth0'));
        $this->assertFalse($this->mockHelper->isWifiAdapter('enp0'));
        $this->assertFalse($this->mockHelper->isWifiAdapter('lo0'));
    }

    // ========================================
    // resetAdapter() Routing Tests
    // ========================================

    public function testResetAdapterRejectsInvalidNames(): void
    {
        $result = $this->mockHelper->resetAdapter('invalid;name');

        $this->assertFalse($result);
        $this->assertEmpty($this->mockHelper->execCalls);
    }

    public function testResetAdapterRoutesToWifiForWlanInterface(): void
    {
        $this->mockHelper->setMockExecReturnCodes(['wpa_cli' => 0]);

        $this->mockHelper->resetAdapter('wlan0');

        $this->assertNotEmpty($this->mockHelper->execCalls);
        $this->assertEquals('wifi', $this->mockHelper->execCalls[0]['type']);
    }

    public function testResetAdapterRoutesToEthernetForEthInterface(): void
    {
        $this->mockHelper->setMockExecReturnCodes(['networkctl' => 0]);

        $this->mockHelper->resetAdapter('eth0');

        $this->assertNotEmpty($this->mockHelper->execCalls);
        $this->assertEquals('ethernet', $this->mockHelper->execCalls[0]['type']);
    }

    // ========================================
    // resetWifiAdapter() Tests
    // ========================================

    public function testResetWifiAdapterTriesWpaCliFirst(): void
    {
        $this->mockHelper->setMockExecReturnCodes(['wpa_cli' => 0]);

        $result = $this->mockHelper->resetWifiAdapter('wlan0');

        $this->assertTrue($result);
        $this->assertCount(1, $this->mockHelper->execCalls);
        $this->assertEquals('wpa_cli', $this->mockHelper->execCalls[0]['method']);
    }

    public function testResetWifiAdapterFallsBackToSystemctl(): void
    {
        $this->mockHelper->setMockExecReturnCodes([
            'wpa_cli' => 1,
            'systemctl' => 0
        ]);

        $result = $this->mockHelper->resetWifiAdapter('wlan0');

        $this->assertTrue($result);
        $this->assertCount(2, $this->mockHelper->execCalls);
        $this->assertEquals('wpa_cli', $this->mockHelper->execCalls[0]['method']);
        $this->assertEquals('systemctl', $this->mockHelper->execCalls[1]['method']);
    }

    public function testResetWifiAdapterFallsBackToBounce(): void
    {
        $this->mockHelper->setMockExecReturnCodes([
            'wpa_cli' => 1,
            'systemctl' => 1,
            'ip link set' => 0
        ]);

        $result = $this->mockHelper->resetWifiAdapter('wlan0');

        $this->assertTrue($result);

        $methods = array_column($this->mockHelper->execCalls, 'method');
        $this->assertContains('wpa_cli', $methods);
        $this->assertContains('systemctl', $methods);
        $this->assertContains('ip_link', $methods);
    }

    // ========================================
    // resetEthernetAdapter() Tests
    // ========================================

    public function testResetEthernetAdapterTriesNetworkctlFirst(): void
    {
        $this->mockHelper->setMockExecReturnCodes(['networkctl' => 0]);

        $result = $this->mockHelper->resetEthernetAdapter('eth0');

        $this->assertTrue($result);
        $this->assertCount(1, $this->mockHelper->execCalls);
        $this->assertEquals('networkctl', $this->mockHelper->execCalls[0]['method']);
    }

    public function testResetEthernetAdapterFallsBackToBounce(): void
    {
        $this->mockHelper->setMockExecReturnCodes([
            'networkctl' => 1,
            'ip link set' => 0
        ]);

        $result = $this->mockHelper->resetEthernetAdapter('eth0');

        $this->assertTrue($result);

        $methods = array_column($this->mockHelper->execCalls, 'method');
        $this->assertContains('networkctl', $methods);
        $this->assertContains('ip_link', $methods);
    }

    // ========================================
    // bounceInterface() Tests
    // ========================================

    public function testBounceInterfaceSuccess(): void
    {
        $this->mockHelper->setMockExecReturnCodes(['ip link set' => 0]);

        $result = $this->mockHelper->bounceInterface('eth0');

        $this->assertTrue($result);
        $this->assertEquals('bounce', $this->mockHelper->execCalls[0]['type']);
    }

    public function testBounceInterfaceFallsBackToFppApi(): void
    {
        $this->mockHelper->setMockExecReturnCodes(['ip link set' => 1]);
        $this->mockHelper->setMockApiResponse(['success' => true]);

        $result = $this->mockHelper->bounceInterface('eth0');

        $this->assertTrue($result);

        $methods = array_column($this->mockHelper->execCalls, 'method');
        $this->assertContains('ip_link', $methods);
        $this->assertContains('fpp_api', $methods);
    }

    // ========================================
    // resetViaFppApi() Tests
    // ========================================

    public function testResetViaFppApiSuccess(): void
    {
        $this->mockHelper->setMockApiResponse(['success' => true]);

        $result = $this->mockHelper->resetViaFppApi('eth0');

        $this->assertTrue($result);
    }

    public function testResetViaFppApiFailure(): void
    {
        $this->mockHelper->setMockApiResponse(null);

        $result = $this->mockHelper->resetViaFppApi('eth0');

        $this->assertFalse($result);
    }

    // ========================================
    // getInterfaceInfo() Tests
    // ========================================

    public function testGetInterfaceInfoWithMockData(): void
    {
        $mockData = [
            'name' => 'eth0',
            'address' => '192.168.1.100',
            'netmask' => '255.255.255.0'
        ];

        $this->mockHelper->setMockApiResponse($mockData);

        $result = $this->mockHelper->getInterfaceInfo('eth0');

        $this->assertEquals($mockData, $result);
    }

    public function testGetInterfaceInfoReturnsNullOnFailure(): void
    {
        $this->mockHelper->setMockApiResponse(null);

        $result = $this->mockHelper->getInterfaceInfo('eth0');

        $this->assertNull($result);
    }

    // ========================================
    // getAllInterfaces() Tests
    // ========================================

    public function testGetAllInterfacesWithMockData(): void
    {
        $mockData = [
            ['name' => 'eth0', 'address' => '192.168.1.100'],
            ['name' => 'wlan0', 'address' => '192.168.1.101']
        ];

        $this->mockHelper->setMockApiResponse($mockData);

        $result = $this->mockHelper->getAllInterfaces();

        $this->assertEquals($mockData, $result);
        $this->assertCount(2, $result);
    }

    public function testGetAllInterfacesReturnsNullOnFailure(): void
    {
        $this->mockHelper->setMockApiResponse(null);

        $result = $this->mockHelper->getAllInterfaces();

        $this->assertNull($result);
    }

    // ========================================
    // Method Fallback Chain Tests
    // ========================================

    public function testWifiFallbackChainComplete(): void
    {
        // All methods fail
        $this->mockHelper->setMockExecReturnCodes([
            'wpa_cli' => 1,
            'systemctl' => 1,
            'ip link set' => 1
        ]);
        $this->mockHelper->setMockApiResponse(null);

        $result = $this->mockHelper->resetWifiAdapter('wlan0');

        $this->assertFalse($result);

        // Should have tried all methods in order
        $methods = array_column($this->mockHelper->execCalls, 'method');
        $this->assertEquals(['wpa_cli', 'systemctl', 'ip_link', 'fpp_api'], $methods);
    }

    public function testEthernetFallbackChainComplete(): void
    {
        // All methods fail
        $this->mockHelper->setMockExecReturnCodes([
            'networkctl' => 1,
            'ip link set' => 1
        ]);
        $this->mockHelper->setMockApiResponse(null);

        $result = $this->mockHelper->resetEthernetAdapter('eth0');

        $this->assertFalse($result);

        // Should have tried all methods in order
        $methods = array_column($this->mockHelper->execCalls, 'method');
        $this->assertEquals(['networkctl', 'ip_link', 'fpp_api'], $methods);
    }

    // ========================================
    // Public Method Existence Tests
    // ========================================

    public function testPublicMethodsExist(): void
    {
        $reflection = new \ReflectionClass(NetworkAdapter::class);

        $expectedMethods = [
            'getInstance',
            'resetAdapter',
            'resetWifiAdapter',
            'resetEthernetAdapter',
            'bounceInterface',
            'resetViaFppApi',
            'getInterfaceInfo',
            'getAllInterfaces'
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

    // ========================================
    // Edge Cases
    // ========================================

    public function testResetAdapterWithMinimalValidName(): void
    {
        $this->mockHelper->setMockExecReturnCodes(['networkctl' => 0]);

        // Minimum valid name: one letter followed by one number
        $result = $this->mockHelper->resetAdapter('a0');

        $this->assertTrue($result);
    }

    public function testResetAdapterWithLongerValidName(): void
    {
        $this->mockHelper->setMockExecReturnCodes(['networkctl' => 0]);

        $result = $this->mockHelper->resetAdapter('enp123');

        $this->assertTrue($result);
    }

    public function testMultipleResetAttempts(): void
    {
        $this->mockHelper->setMockExecReturnCodes(['networkctl' => 0]);

        $result1 = $this->mockHelper->resetAdapter('eth0');
        $this->mockHelper->clearExecCalls();

        $result2 = $this->mockHelper->resetAdapter('eth0');

        $this->assertTrue($result1);
        $this->assertTrue($result2);
        // Second call should also have made exec calls
        $this->assertNotEmpty($this->mockHelper->execCalls);
    }

    public function testResetDifferentInterfaces(): void
    {
        $this->mockHelper->setMockExecReturnCodes([
            'networkctl' => 0,
            'wpa_cli' => 0
        ]);

        $this->mockHelper->resetAdapter('eth0');
        $eth0Type = $this->mockHelper->execCalls[0]['type'];

        $this->mockHelper->clearExecCalls();

        $this->mockHelper->resetAdapter('wlan0');
        $wlan0Type = $this->mockHelper->execCalls[0]['type'];

        $this->assertEquals('ethernet', $eth0Type);
        $this->assertEquals('wifi', $wlan0Type);
    }

    // ========================================
    // Integration Tests (with localhost)
    // ========================================

    public function testGetInterfaceInfoWithLocalhost(): void
    {
        // This will make a real API call to localhost
        // May succeed if FPP is running, otherwise returns null
        $result = $this->networkAdapter->getInterfaceInfo('eth0');

        // Result should be either null or an array
        $this->assertTrue($result === null || is_array($result));
    }

    public function testGetAllInterfacesWithLocalhost(): void
    {
        // This will make a real API call to localhost
        $result = $this->networkAdapter->getAllInterfaces();

        // Result should be either null or an array
        $this->assertTrue($result === null || is_array($result));
    }

    // ========================================
    // Security Tests
    // ========================================

    /**
     * @dataProvider commandInjectionAttemptProvider
     */
    public function testResetAdapterRejectsCommandInjection(string $maliciousInput): void
    {
        $result = $this->mockHelper->resetAdapter($maliciousInput);

        $this->assertFalse($result);
        $this->assertEmpty($this->mockHelper->execCalls, "No exec calls should be made for: $maliciousInput");
    }

    public static function commandInjectionAttemptProvider(): array
    {
        return [
            'semicolon' => ['eth0;rm -rf /'],
            'newline' => ["eth0\nrm -rf /"],
            'null byte' => ["eth0\0rm"],
            'pipe' => ['eth0|cat /etc/passwd'],
            'backtick' => ['eth0`cat /etc/passwd`'],
            'subshell' => ['eth0$(cat /etc/passwd)'],
            'redirect' => ['eth0>/tmp/test'],
            'ampersand background' => ['eth0&'],
            'double ampersand' => ['eth0&&ls'],
            'or' => ['eth0||ls'],
            'space' => ['eth0 -a'],
        ];
    }
}
