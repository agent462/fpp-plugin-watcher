<?php
/**
 * Unit tests for FalconController class
 *
 * Note: Most FalconController methods require network access to Falcon hardware.
 * These tests focus on the static utilities and validation methods.
 * Integration tests for actual hardware communication are in Integration/FalconTest.php
 *
 * @package Watcher\Tests\Unit\Controllers
 */

declare(strict_types=1);

namespace Watcher\Tests\Unit\Controllers;

use Watcher\Tests\TestCase;
use Watcher\Controllers\FalconController;

class FalconControllerTest extends TestCase
{
    // =========================================================================
    // Model Name Tests
    // =========================================================================

    public function testGetModelNameForV2V3Products(): void
    {
        $this->assertEquals('F4V2', FalconController::getModelName(1));
        $this->assertEquals('F16V2', FalconController::getModelName(2));
        $this->assertEquals('F4V3', FalconController::getModelName(3));
        $this->assertEquals('F16V3', FalconController::getModelName(5));
        $this->assertEquals('F48', FalconController::getModelName(7));
    }

    public function testGetModelNameForV4V5Products(): void
    {
        $this->assertEquals('F16V4', FalconController::getModelName(128));
        $this->assertEquals('F48V4', FalconController::getModelName(129));
        $this->assertEquals('F16V5', FalconController::getModelName(130));
        $this->assertEquals('F48V5', FalconController::getModelName(131));
        $this->assertEquals('F32V5', FalconController::getModelName(132));
    }

    public function testGetModelNameForUnknownProduct(): void
    {
        $result = FalconController::getModelName(999);
        $this->assertEquals('Unknown (999)', $result);
    }

    public function testGetModelNameForZero(): void
    {
        $result = FalconController::getModelName(0);
        $this->assertEquals('Unknown (0)', $result);
    }

    // =========================================================================
    // Host Validation Tests
    // =========================================================================

    public function testIsValidHostWithValidIPs(): void
    {
        $this->assertTrue(FalconController::isValidHost('192.168.1.1'));
        $this->assertTrue(FalconController::isValidHost('10.0.0.1'));
        $this->assertTrue(FalconController::isValidHost('172.16.0.1'));
        $this->assertTrue(FalconController::isValidHost('127.0.0.1'));
    }

    public function testIsValidHostWithValidHostnames(): void
    {
        $this->assertTrue(FalconController::isValidHost('falcon-controller'));
        $this->assertTrue(FalconController::isValidHost('falcon.local'));
        $this->assertTrue(FalconController::isValidHost('controller-01.network.local'));
    }

    public function testIsValidHostWithInvalidValues(): void
    {
        $this->assertFalse(FalconController::isValidHost(''));
        $this->assertFalse(FalconController::isValidHost(' '));
        $this->assertFalse(FalconController::isValidHost('192.168.1.'));
        $this->assertFalse(FalconController::isValidHost('256.256.256.256'));
        $this->assertFalse(FalconController::isValidHost('host with spaces'));
    }

    public function testIsValidHostWithSpecialCharacters(): void
    {
        $this->assertFalse(FalconController::isValidHost('host<script>'));
        $this->assertFalse(FalconController::isValidHost('host;ls'));
        $this->assertFalse(FalconController::isValidHost('host|cat /etc/passwd'));
    }

    // =========================================================================
    // Subnet Validation Tests
    // =========================================================================

    public function testIsValidSubnetWithValidSubnets(): void
    {
        $this->assertTrue(FalconController::isValidSubnet('192.168.1'));
        $this->assertTrue(FalconController::isValidSubnet('10.0.0'));
        $this->assertTrue(FalconController::isValidSubnet('172.16.0'));
    }

    public function testIsValidSubnetWithInvalidSubnets(): void
    {
        $this->assertFalse(FalconController::isValidSubnet(''));
        $this->assertFalse(FalconController::isValidSubnet('192.168'));
        $this->assertFalse(FalconController::isValidSubnet('192.168.1.1'));
        $this->assertFalse(FalconController::isValidSubnet('256.168.1'));
        $this->assertFalse(FalconController::isValidSubnet('abc.def.ghi'));
    }

    // =========================================================================
    // Constructor Tests
    // =========================================================================

    public function testConstructorWithValidHost(): void
    {
        $controller = new FalconController('192.168.1.50');

        // If no exception is thrown, the controller was created
        $this->assertInstanceOf(FalconController::class, $controller);
    }

    public function testConstructorWithCustomPort(): void
    {
        $controller = new FalconController('192.168.1.50', 8080);

        $this->assertInstanceOf(FalconController::class, $controller);
    }

    public function testConstructorWithCustomTimeout(): void
    {
        $controller = new FalconController('192.168.1.50', 80, 30);

        $this->assertInstanceOf(FalconController::class, $controller);
    }

    // =========================================================================
    // Product Code Detection Tests
    // =========================================================================

    public function testProductCodeRangesForV2V3(): void
    {
        // Product codes 1-7 are V2/V3
        $v2v3Codes = [1, 2, 3, 5, 7];

        foreach ($v2v3Codes as $code) {
            $modelName = FalconController::getModelName($code);
            $this->assertStringNotContainsString('Unknown', $modelName, "Code {$code} should be recognized");
            $this->assertMatchesRegularExpression('/V[23]$|F48$/', $modelName, "Code {$code} should be V2/V3");
        }
    }

    public function testProductCodeRangesForV4V5(): void
    {
        // Product codes 128+ are V4/V5
        $v4v5Codes = [128, 129, 130, 131, 132];

        foreach ($v4v5Codes as $code) {
            $modelName = FalconController::getModelName($code);
            $this->assertStringNotContainsString('Unknown', $modelName, "Code {$code} should be recognized");
            $this->assertMatchesRegularExpression('/V[45]$/', $modelName, "Code {$code} should be V4/V5");
        }
    }

    // =========================================================================
    // Static Utility Tests
    // =========================================================================

    public function testAutoDetectSubnetReturnsStringOrNull(): void
    {
        // This test verifies the method exists and returns expected type
        // Actual detection requires FPP network configuration
        $result = FalconController::autoDetectSubnet();

        $this->assertTrue(
            is_string($result) || is_null($result),
            'autoDetectSubnet should return string or null'
        );
    }

    // =========================================================================
    // Response Parsing Tests (using fixture data)
    // =========================================================================

    public function testParseV4StatusResponse(): void
    {
        // Load fixture for V4 status response
        $fixtureData = $this->loadJsonFixture('data/sample_metrics.json');
        $v4Status = $fixtureData['falcon_status_v4'];

        // Verify the fixture has expected fields
        $this->assertArrayHasKey('p', $v4Status); // Product code
        $this->assertArrayHasKey('v', $v4Status); // Version
        $this->assertArrayHasKey('n', $v4Status); // Name
        $this->assertArrayHasKey('i', $v4Status); // IP
        $this->assertArrayHasKey('t', $v4Status); // Temperatures
        $this->assertArrayHasKey('vt', $v4Status); // Voltages

        $this->assertEquals(128, $v4Status['p']);
        $this->assertEquals('F16V4', FalconController::getModelName($v4Status['p']));
    }

    public function testParseV3StatusResponse(): void
    {
        // Load fixture for V3 status response
        $fixtureData = $this->loadJsonFixture('data/sample_metrics.json');
        $v3Status = $fixtureData['falcon_status_v3'];

        // Verify the fixture has expected fields
        $this->assertArrayHasKey('p', $v3Status);
        $this->assertArrayHasKey('v', $v3Status);
        $this->assertArrayHasKey('n', $v3Status);

        $this->assertEquals(5, $v3Status['p']);
        $this->assertEquals('F16V3', FalconController::getModelName($v3Status['p']));
    }

    // =========================================================================
    // IP Address Edge Cases
    // =========================================================================

    public function testIsValidHostIPv4EdgeCases(): void
    {
        // Valid edge cases
        $this->assertTrue(FalconController::isValidHost('0.0.0.0'));
        $this->assertTrue(FalconController::isValidHost('255.255.255.255'));
        $this->assertTrue(FalconController::isValidHost('1.1.1.1'));

        // Invalid edge cases
        $this->assertFalse(FalconController::isValidHost('1.1.1'));
        $this->assertFalse(FalconController::isValidHost('1.1.1.1.1'));
        $this->assertFalse(FalconController::isValidHost('01.01.01.01')); // Leading zeros may be interpreted as octal
    }

    public function testIsValidHostWithNumericHostnames(): void
    {
        // Pure numeric hostnames should be treated as hostnames, not IPs
        $this->assertTrue(FalconController::isValidHost('falcon1'));
        $this->assertTrue(FalconController::isValidHost('1falcon'));
    }

    // =========================================================================
    // Timeout Configuration Tests
    // =========================================================================

    public function testDefaultTimeoutValues(): void
    {
        $reflection = new \ReflectionClass(FalconController::class);
        $constructor = $reflection->getConstructor();
        $params = $constructor->getParameters();

        // Find timeout parameter
        $timeoutParam = null;
        foreach ($params as $param) {
            if ($param->getName() === 'timeout') {
                $timeoutParam = $param;
                break;
            }
        }

        $this->assertNotNull($timeoutParam);
        $this->assertTrue($timeoutParam->isDefaultValueAvailable());
        $this->assertIsInt($timeoutParam->getDefaultValue());
    }

    // =========================================================================
    // Cache TTL Tests
    // =========================================================================

    public function testCacheTTLParameter(): void
    {
        $reflection = new \ReflectionClass(FalconController::class);
        $constructor = $reflection->getConstructor();
        $params = $constructor->getParameters();

        // Find cacheTTL parameter
        $cacheTTLParam = null;
        foreach ($params as $param) {
            if ($param->getName() === 'cacheTTL') {
                $cacheTTLParam = $param;
                break;
            }
        }

        $this->assertNotNull($cacheTTLParam);
        $this->assertTrue($cacheTTLParam->isDefaultValueAvailable());
    }
}
