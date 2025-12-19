<?php
/**
 * Unit tests for FalconController class
 *
 * Comprehensive test coverage for Falcon pixel controller communication.
 * Uses TestableFalconController subclass to test private methods.
 *
 * @package Watcher\Tests\Unit\Controllers
 */

declare(strict_types=1);

namespace Watcher\Tests\Unit\Controllers;

use Watcher\Tests\TestCase;
use Watcher\Controllers\FalconController;

/**
 * Testable subclass that exposes private methods for testing
 */
class TestableFalconController extends FalconController
{
    /**
     * Expose formatUptime for testing
     */
    public function testFormatUptime(int $seconds): string
    {
        $reflection = new \ReflectionMethod(parent::class, 'formatUptime');
        $reflection->setAccessible(true);
        return $reflection->invoke($this, $seconds);
    }

    /**
     * Expose parseXml for testing
     */
    public function testParseXml(string $xml): \SimpleXMLElement|false
    {
        $reflection = new \ReflectionMethod(parent::class, 'parseXml');
        $reflection->setAccessible(true);
        return $reflection->invoke($this, $xml);
    }

    /**
     * Expose parseUniverses for testing
     */
    public function testParseUniverses(?\SimpleXMLElement $xml): array
    {
        $reflection = new \ReflectionMethod(parent::class, 'parseUniverses');
        $reflection->setAccessible(true);
        return $reflection->invoke($this, $xml);
    }

    /**
     * Expose parseStrings for testing
     */
    public function testParseStrings(?\SimpleXMLElement $xml): array
    {
        $reflection = new \ReflectionMethod(parent::class, 'parseStrings');
        $reflection->setAccessible(true);
        return $reflection->invoke($this, $xml);
    }

    /**
     * Expose parseSerialOutputs for testing
     */
    public function testParseSerialOutputs(?\SimpleXMLElement $xml): array
    {
        $reflection = new \ReflectionMethod(parent::class, 'parseSerialOutputs');
        $reflection->setAccessible(true);
        return $reflection->invoke($this, $xml);
    }

    /**
     * Expose getBaseUrl for testing
     */
    public function testGetBaseUrl(): string
    {
        $reflection = new \ReflectionMethod(parent::class, 'getBaseUrl');
        $reflection->setAccessible(true);
        return $reflection->invoke($this);
    }

    /**
     * Expose buildV4PortArray for testing
     */
    public function testBuildV4PortArray(int $numPorts): array
    {
        $reflection = new \ReflectionMethod(parent::class, 'buildV4PortArray');
        $reflection->setAccessible(true);
        return $reflection->invoke($this, $numPorts);
    }
}

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

    public function testGetModelNameForNegativeValue(): void
    {
        $result = FalconController::getModelName(-1);
        $this->assertEquals('Unknown (-1)', $result);
    }

    /**
     * @dataProvider modelNameProvider
     */
    public function testGetModelNameDataProvider(int $code, string $expected): void
    {
        $this->assertEquals($expected, FalconController::getModelName($code));
    }

    public static function modelNameProvider(): array
    {
        return [
            'F4V2' => [1, 'F4V2'],
            'F16V2' => [2, 'F16V2'],
            'F4V3' => [3, 'F4V3'],
            'F16V3' => [5, 'F16V3'],
            'F48' => [7, 'F48'],
            'F16V4' => [128, 'F16V4'],
            'F48V4' => [129, 'F48V4'],
            'F16V5' => [130, 'F16V5'],
            'F48V5' => [131, 'F48V5'],
            'F32V5' => [132, 'F32V5'],
            'Unknown code 4' => [4, 'Unknown (4)'],
            'Unknown code 6' => [6, 'Unknown (6)'],
            'Unknown code 100' => [100, 'Unknown (100)'],
            'Unknown code 127' => [127, 'Unknown (127)'],
            'Unknown code 133' => [133, 'Unknown (133)'],
        ];
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

    /**
     * @dataProvider validHostProvider
     */
    public function testIsValidHostWithValidInputs(string $host): void
    {
        $this->assertTrue(FalconController::isValidHost($host), "Host '{$host}' should be valid");
    }

    public static function validHostProvider(): array
    {
        return [
            'simple IP' => ['192.168.1.1'],
            'class A private' => ['10.0.0.1'],
            'class B private' => ['172.16.0.1'],
            'localhost IP' => ['127.0.0.1'],
            'zeros IP' => ['0.0.0.0'],
            'broadcast IP' => ['255.255.255.255'],
            'simple hostname' => ['falcon'],
            'hyphenated hostname' => ['falcon-controller'],
            'hostname with number' => ['falcon1'],
            'hostname starting with number' => ['1falcon'],
            'FQDN' => ['falcon.local'],
            'multi-level FQDN' => ['controller.network.local'],
            'short hostname' => ['a'],
            'long hostname label' => [str_repeat('a', 63)],
        ];
    }

    /**
     * @dataProvider invalidHostProvider
     */
    public function testIsValidHostWithInvalidInputs(string $host): void
    {
        $this->assertFalse(FalconController::isValidHost($host), "Host '{$host}' should be invalid");
    }

    public static function invalidHostProvider(): array
    {
        return [
            'empty string' => [''],
            'whitespace only' => [' '],
            'tab character' => ["\t"],
            'newline' => ["\n"],
            'host with space' => ['falcon host'],
            'incomplete IP' => ['192.168.1.'],
            'IP with leading dot' => ['.192.168.1.1'],
            'IP too many octets' => ['192.168.1.1.1'],
            'IP too few octets' => ['192.168.1'],
            'octet out of range' => ['256.1.1.1'],
            'leading zeros IP' => ['01.01.01.01'],
            'hostname with underscore' => ['falcon_controller'],
            'hostname with script injection' => ['<script>alert(1)</script>'],
            'hostname with semicolon' => ['host;ls'],
            'hostname with pipe' => ['host|cat'],
            'hostname with ampersand' => ['host&rm'],
            'hostname starting with hyphen' => ['-falcon'],
            'hostname ending with hyphen' => ['falcon-'],
            'hostname with consecutive hyphens at start' => ['--falcon'],
        ];
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

    /**
     * @dataProvider validSubnetProvider
     */
    public function testIsValidSubnetWithValidInputs(string $subnet): void
    {
        $this->assertTrue(FalconController::isValidSubnet($subnet), "Subnet '{$subnet}' should be valid");
    }

    public static function validSubnetProvider(): array
    {
        return [
            'class C typical' => ['192.168.1'],
            'class A private' => ['10.0.0'],
            'class B private' => ['172.16.0'],
            'all zeros' => ['0.0.0'],
            'all 255' => ['255.255.255'],
            'mixed values' => ['192.0.2'],
            'single digit octets' => ['1.1.1'],
        ];
    }

    /**
     * @dataProvider invalidSubnetProvider
     */
    public function testIsValidSubnetWithInvalidInputs(string $subnet): void
    {
        $this->assertFalse(FalconController::isValidSubnet($subnet), "Subnet '{$subnet}' should be invalid");
    }

    public static function invalidSubnetProvider(): array
    {
        return [
            'empty string' => [''],
            'two octets' => ['192.168'],
            'four octets' => ['192.168.1.1'],
            'octet out of range high' => ['256.168.1'],
            'octet out of range second' => ['192.256.1'],
            'octet out of range third' => ['192.168.256'],
            'negative octet' => ['-1.168.1'],
            'alphabetic' => ['abc.def.ghi'],
            'mixed alpha-numeric' => ['192.abc.1'],
            'with spaces' => ['192.168. 1'],
            'with trailing dot' => ['192.168.1.'],
            'with leading dot' => ['.192.168.1'],
        ];
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

    public function testConstructorWithAllParameters(): void
    {
        $controller = new FalconController('192.168.1.50', 8080, 10, 30);

        $this->assertInstanceOf(FalconController::class, $controller);
    }

    // =========================================================================
    // Base URL Tests
    // =========================================================================

    public function testGetBaseUrlDefaultPort(): void
    {
        $controller = new TestableFalconController('192.168.1.50');
        $this->assertEquals('http://192.168.1.50:80', $controller->testGetBaseUrl());
    }

    public function testGetBaseUrlCustomPort(): void
    {
        $controller = new TestableFalconController('192.168.1.50', 8080);
        $this->assertEquals('http://192.168.1.50:8080', $controller->testGetBaseUrl());
    }

    public function testGetBaseUrlWithHostname(): void
    {
        $controller = new TestableFalconController('falcon.local');
        $this->assertEquals('http://falcon.local:80', $controller->testGetBaseUrl());
    }

    // =========================================================================
    // Format Uptime Tests
    // =========================================================================

    public function testFormatUptimeZeroSeconds(): void
    {
        $controller = new TestableFalconController('192.168.1.1');
        $this->assertEquals('', $controller->testFormatUptime(0));
    }

    public function testFormatUptimeNegativeSeconds(): void
    {
        $controller = new TestableFalconController('192.168.1.1');
        $this->assertEquals('', $controller->testFormatUptime(-100));
    }

    public function testFormatUptimeMinutesOnly(): void
    {
        $controller = new TestableFalconController('192.168.1.1');
        $this->assertEquals('5m', $controller->testFormatUptime(300));
    }

    public function testFormatUptimeHoursAndMinutes(): void
    {
        $controller = new TestableFalconController('192.168.1.1');
        $this->assertEquals('2h 30m', $controller->testFormatUptime(9000)); // 2.5 hours
    }

    public function testFormatUptimeDaysHoursMinutes(): void
    {
        $controller = new TestableFalconController('192.168.1.1');
        $this->assertEquals('1d 5h 30m', $controller->testFormatUptime(106200)); // 1 day, 5.5 hours
    }

    public function testFormatUptimeDaysOnly(): void
    {
        $controller = new TestableFalconController('192.168.1.1');
        // 7 days exactly - implementation doesn't include trailing "0m" when 0
        $this->assertEquals('7d', $controller->testFormatUptime(604800));
    }

    public function testFormatUptimeOneMinute(): void
    {
        $controller = new TestableFalconController('192.168.1.1');
        $this->assertEquals('1m', $controller->testFormatUptime(60));
    }

    public function testFormatUptimeLessThanMinute(): void
    {
        $controller = new TestableFalconController('192.168.1.1');
        $this->assertEquals('0m', $controller->testFormatUptime(30));
    }

    /**
     * @dataProvider uptimeProvider
     */
    public function testFormatUptimeDataProvider(int $seconds, string $expected): void
    {
        $controller = new TestableFalconController('192.168.1.1');
        $this->assertEquals($expected, $controller->testFormatUptime($seconds));
    }

    public static function uptimeProvider(): array
    {
        return [
            'zero' => [0, ''],
            'negative' => [-1, ''],
            '30 seconds' => [30, '0m'],
            '1 minute' => [60, '1m'],
            '5 minutes' => [300, '5m'],
            '59 minutes' => [3540, '59m'],
            '1 hour exactly' => [3600, '1h'],
            '1 hour 30 minutes' => [5400, '1h 30m'],
            '2 hours' => [7200, '2h'],
            '23 hours 59 minutes' => [86340, '23h 59m'],
            '1 day exactly' => [86400, '1d'],
            '1 day 1 hour' => [90000, '1d 1h'],
            '1 day 1 hour 1 minute' => [90060, '1d 1h 1m'],
            '30 days' => [2592000, '30d'],
            '365 days' => [31536000, '365d'],
        ];
    }

    // =========================================================================
    // Mode Name Tests
    // =========================================================================

    public function testGetModeNameV2V3Modes(): void
    {
        $controller = new FalconController('192.168.1.1');

        $this->assertEquals('E1.31', $controller->getModeName(0, false));
        $this->assertEquals('ZCPP', $controller->getModeName(16, false));
        $this->assertEquals('DDP', $controller->getModeName(64, false));
        $this->assertEquals('ArtNet', $controller->getModeName(128, false));
    }

    public function testGetModeNameV4V5Modes(): void
    {
        $controller = new FalconController('192.168.1.1');

        $this->assertEquals('E1.31/ArtNet', $controller->getModeName(0, true));
        $this->assertEquals('ZCPP', $controller->getModeName(1, true));
        $this->assertEquals('DDP', $controller->getModeName(2, true));
        $this->assertEquals('FPP Remote', $controller->getModeName(3, true));
        $this->assertEquals('FPP Master', $controller->getModeName(4, true));
        $this->assertEquals('FPP Player', $controller->getModeName(5, true));
    }

    public function testGetModeNameUnknownV2V3(): void
    {
        $controller = new FalconController('192.168.1.1');
        $this->assertEquals('Unknown (99)', $controller->getModeName(99, false));
    }

    public function testGetModeNameUnknownV4V5(): void
    {
        $controller = new FalconController('192.168.1.1');
        $this->assertEquals('Unknown (99)', $controller->getModeName(99, true));
    }

    // =========================================================================
    // isV4Controller Tests
    // =========================================================================

    public function testIsV4ControllerWithV2V3ProductCodes(): void
    {
        $controller = new FalconController('192.168.1.1');

        $this->assertFalse($controller->isV4Controller(1));   // F4V2
        $this->assertFalse($controller->isV4Controller(2));   // F16V2
        $this->assertFalse($controller->isV4Controller(3));   // F4V3
        $this->assertFalse($controller->isV4Controller(5));   // F16V3
        $this->assertFalse($controller->isV4Controller(7));   // F48
        $this->assertFalse($controller->isV4Controller(127)); // Just below V4 threshold
    }

    public function testIsV4ControllerWithV4V5ProductCodes(): void
    {
        $controller = new FalconController('192.168.1.1');

        $this->assertTrue($controller->isV4Controller(128)); // F16V4 - first V4
        $this->assertTrue($controller->isV4Controller(129)); // F48V4
        $this->assertTrue($controller->isV4Controller(130)); // F16V5
        $this->assertTrue($controller->isV4Controller(131)); // F48V5
        $this->assertTrue($controller->isV4Controller(132)); // F32V5
        $this->assertTrue($controller->isV4Controller(255)); // Future V4+
    }

    public function testIsV4ControllerBoundary(): void
    {
        $controller = new FalconController('192.168.1.1');

        // 127 is the last non-V4 product code
        $this->assertFalse($controller->isV4Controller(127));
        // 128 is the first V4 product code
        $this->assertTrue($controller->isV4Controller(128));
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
    // XML Parsing Tests
    // =========================================================================

    public function testParseXmlValidXml(): void
    {
        $controller = new TestableFalconController('192.168.1.1');
        $xml = '<root><item>test</item></root>';

        $result = $controller->testParseXml($xml);

        $this->assertInstanceOf(\SimpleXMLElement::class, $result);
        $this->assertEquals('test', (string)$result->item);
    }

    public function testParseXmlInvalidXml(): void
    {
        $controller = new TestableFalconController('192.168.1.1');
        $xml = '<root><unclosed>';

        $result = $controller->testParseXml($xml);

        $this->assertFalse($result);
        $this->assertNotNull($controller->getLastError());
        $this->assertStringContainsString('XML parse error', $controller->getLastError());
    }

    public function testParseXmlEmptyString(): void
    {
        $controller = new TestableFalconController('192.168.1.1');
        $result = $controller->testParseXml('');

        $this->assertFalse($result);
    }

    public function testParseXmlStatusXml(): void
    {
        $controller = new TestableFalconController('192.168.1.1');
        $xml = '<?xml version="1.0"?><status><fv>1.50</fv><n>Falcon-F16V4</n><p>128</p></status>';

        $result = $controller->testParseXml($xml);

        $this->assertInstanceOf(\SimpleXMLElement::class, $result);
        $this->assertEquals('1.50', (string)$result->fv);
        $this->assertEquals('Falcon-F16V4', (string)$result->n);
        $this->assertEquals('128', (string)$result->p);
    }

    // =========================================================================
    // Parse Universes Tests
    // =========================================================================

    public function testParseUniversesNull(): void
    {
        $controller = new TestableFalconController('192.168.1.1');
        $result = $controller->testParseUniverses(null);

        $this->assertEquals([], $result);
    }

    public function testParseUniversesEmpty(): void
    {
        $controller = new TestableFalconController('192.168.1.1');
        $xml = simplexml_load_string('<universes></universes>');

        $result = $controller->testParseUniverses($xml);

        $this->assertEquals([], $result);
    }

    public function testParseUniversesWithData(): void
    {
        $controller = new TestableFalconController('192.168.1.1');
        $xmlStr = '<universes>
            <un u="1" s="1" l="512" t="0"/>
            <un u="2" s="513" l="512" t="0"/>
        </universes>';
        $xml = simplexml_load_string($xmlStr);

        $result = $controller->testParseUniverses($xml);

        $this->assertCount(2, $result);
        $this->assertEquals(1, $result[0]['universe']);
        $this->assertEquals(1, $result[0]['start_channel']);
        $this->assertEquals(512, $result[0]['size']);
        $this->assertEquals(0, $result[0]['type']);
        $this->assertEquals(2, $result[1]['universe']);
        $this->assertEquals(513, $result[1]['start_channel']);
    }

    // =========================================================================
    // Parse Strings Tests
    // =========================================================================

    public function testParseStringsNull(): void
    {
        $controller = new TestableFalconController('192.168.1.1');
        $result = $controller->testParseStrings(null);

        $this->assertEquals([], $result);
    }

    public function testParseStringsEmpty(): void
    {
        $controller = new TestableFalconController('192.168.1.1');
        $xml = simplexml_load_string('<strings></strings>');

        $result = $controller->testParseStrings($xml);

        $this->assertEquals([], $result);
    }

    public function testParseStringsWithData(): void
    {
        $controller = new TestableFalconController('192.168.1.1');
        $xmlStr = '<strings>
            <vs y="Front Roofline" p="1" u="1" us="1" s="1" c="100" g="1" t="0" d="0" o="0" n="0" z="0" b="100" bl="0" ga="10" sr="0" si="0" e="1"/>
            <vs y="Side Windows" p="2" u="2" us="1" s="101" c="50" g="1" t="0" d="0" o="0" n="0" z="0" b="100" bl="0" ga="10" sr="0" si="0" e="1"/>
        </strings>';
        $xml = simplexml_load_string($xmlStr);

        $result = $controller->testParseStrings($xml);

        $this->assertCount(2, $result);
        $this->assertEquals('Front Roofline', $result[0]['description']);
        $this->assertEquals(1, $result[0]['port']);
        $this->assertEquals(1, $result[0]['universe']);
        $this->assertEquals(100, $result[0]['pixel_count']);
        $this->assertEquals(1, $result[0]['enabled']);
        $this->assertEquals('Side Windows', $result[1]['description']);
        $this->assertEquals(2, $result[1]['port']);
    }

    // =========================================================================
    // Parse Serial Outputs Tests
    // =========================================================================

    public function testParseSerialOutputsNull(): void
    {
        $controller = new TestableFalconController('192.168.1.1');
        $result = $controller->testParseSerialOutputs(null);

        $this->assertEquals([], $result);
    }

    public function testParseSerialOutputsEmpty(): void
    {
        $controller = new TestableFalconController('192.168.1.1');
        $xml = simplexml_load_string('<serialoutputs></serialoutputs>');

        $result = $controller->testParseSerialOutputs($xml);

        $this->assertEquals([], $result);
    }

    public function testParseSerialOutputsWithData(): void
    {
        $controller = new TestableFalconController('192.168.1.1');
        $xmlStr = '<serialoutputs>
            <so t="0" b="250000" sb="2" u="1" us="1" s="1" m="512" g="10" i="0" e="1"/>
        </serialoutputs>';
        $xml = simplexml_load_string($xmlStr);

        $result = $controller->testParseSerialOutputs($xml);

        $this->assertCount(1, $result);
        $this->assertEquals(0, $result[0]['type']);
        $this->assertEquals(250000, $result[0]['baud']);
        $this->assertEquals(2, $result[0]['stop_bits']);
        $this->assertEquals(1, $result[0]['universe']);
        $this->assertEquals(512, $result[0]['num_channels']);
        $this->assertEquals(10, $result[0]['gamma']);
        $this->assertEquals(1, $result[0]['enabled']);
    }

    // =========================================================================
    // Build V4 Port Array Tests
    // =========================================================================

    public function testBuildV4PortArrayZeroPorts(): void
    {
        $controller = new TestableFalconController('192.168.1.1');
        $result = $controller->testBuildV4PortArray(0);

        $this->assertEquals([], $result);
    }

    public function testBuildV4PortArrayOnePorts(): void
    {
        $controller = new TestableFalconController('192.168.1.1');
        $result = $controller->testBuildV4PortArray(1);

        $this->assertCount(1, $result);
        $this->assertEquals(['P' => 0, 'R' => 0, 'S' => 0], $result[0]);
    }

    public function testBuildV4PortArrayMultiplePorts(): void
    {
        $controller = new TestableFalconController('192.168.1.1');
        $result = $controller->testBuildV4PortArray(4);

        $this->assertCount(4, $result);
        for ($i = 0; $i < 4; $i++) {
            $this->assertEquals($i, $result[$i]['P']);
            $this->assertEquals(0, $result[$i]['R']);
            $this->assertEquals(0, $result[$i]['S']);
        }
    }

    public function testBuildV4PortArrayTypical16Ports(): void
    {
        $controller = new TestableFalconController('192.168.1.1');
        $result = $controller->testBuildV4PortArray(16);

        $this->assertCount(16, $result);
        $this->assertEquals(0, $result[0]['P']);
        $this->assertEquals(15, $result[15]['P']);
    }

    public function testBuildV4PortArrayTypical48Ports(): void
    {
        $controller = new TestableFalconController('192.168.1.1');
        $result = $controller->testBuildV4PortArray(48);

        $this->assertCount(48, $result);
        $this->assertEquals(47, $result[47]['P']);
    }

    // =========================================================================
    // getMultiStatus Tests
    // =========================================================================

    public function testGetMultiStatusEmptyHosts(): void
    {
        $result = FalconController::getMultiStatus('');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('controllers', $result);
        $this->assertArrayHasKey('count', $result);
        $this->assertArrayHasKey('online', $result);
        $this->assertEquals([], $result['controllers']);
        $this->assertEquals(0, $result['count']);
        $this->assertEquals(0, $result['online']);
    }

    public function testGetMultiStatusStructure(): void
    {
        // Use localhost which will fail fast (no Falcon controller, but quick response)
        $result = FalconController::getMultiStatus('127.0.0.1', 1);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('controllers', $result);
        $this->assertArrayHasKey('count', $result);
        $this->assertArrayHasKey('online', $result);
        $this->assertEquals(1, $result['count']);
        $this->assertIsArray($result['controllers']);

        // Each controller entry should have expected keys
        $controller = $result['controllers'][0];
        $this->assertArrayHasKey('host', $controller);
        $this->assertArrayHasKey('online', $controller);
        $this->assertArrayHasKey('status', $controller);
        $this->assertArrayHasKey('error', $controller);
    }

    public function testGetMultiStatusMultipleHosts(): void
    {
        // Use localhost twice - tests parsing without network timeout
        $result = FalconController::getMultiStatus('127.0.0.1,127.0.0.1', 1);

        $this->assertEquals(2, $result['count']);
        $this->assertCount(2, $result['controllers']);
        $this->assertEquals('127.0.0.1', $result['controllers'][0]['host']);
        $this->assertEquals('127.0.0.1', $result['controllers'][1]['host']);
    }

    public function testGetMultiStatusTrimsWhitespace(): void
    {
        // Test whitespace trimming with localhost
        $result = FalconController::getMultiStatus(' 127.0.0.1 , 127.0.0.1 ', 1);

        $this->assertEquals(2, $result['count']);
        $this->assertEquals('127.0.0.1', $result['controllers'][0]['host']);
        $this->assertEquals('127.0.0.1', $result['controllers'][1]['host']);
    }

    public function testGetMultiStatusFiltersEmptyHosts(): void
    {
        $result = FalconController::getMultiStatus('127.0.0.1,,127.0.0.1,', 1);

        $this->assertEquals(2, $result['count']);
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

    // =========================================================================
    // getLastError Tests
    // =========================================================================

    public function testGetLastErrorInitiallyNull(): void
    {
        $controller = new FalconController('192.168.1.1');
        $this->assertNull($controller->getLastError());
    }

    public function testGetLastErrorAfterXmlParseFailure(): void
    {
        $controller = new TestableFalconController('192.168.1.1');
        $controller->testParseXml('<invalid>');

        $error = $controller->getLastError();
        $this->assertNotNull($error);
        $this->assertStringContainsString('XML parse error', $error);
    }

    // =========================================================================
    // Constants Tests
    // =========================================================================

    public function testProductCodeConstants(): void
    {
        // V2/V3 Controllers
        $this->assertEquals(1, FalconController::F4V2_PRODUCT_CODE);
        $this->assertEquals(2, FalconController::F16V2_PRODUCT_CODE);
        $this->assertEquals(3, FalconController::F4V3_PRODUCT_CODE);
        $this->assertEquals(5, FalconController::F16V3_PRODUCT_CODE);
        $this->assertEquals(7, FalconController::F48_PRODUCT_CODE);

        // V4/V5 Controllers
        $this->assertEquals(128, FalconController::F16V4_PRODUCT_CODE);
        $this->assertEquals(129, FalconController::F48V4_PRODUCT_CODE);
        $this->assertEquals(130, FalconController::F16V5_PRODUCT_CODE);
        $this->assertEquals(131, FalconController::F48V5_PRODUCT_CODE);
        $this->assertEquals(132, FalconController::F32V5_PRODUCT_CODE);
    }

    public function testModeConstants(): void
    {
        // V2/V3 Controller Modes
        $this->assertEquals(0, FalconController::MODE_E131);
        $this->assertEquals(16, FalconController::MODE_ZCPP);
        $this->assertEquals(64, FalconController::MODE_DDP);
        $this->assertEquals(128, FalconController::MODE_ARTNET);

        // V4/V5 Operating Modes
        $this->assertEquals(0, FalconController::V4_MODE_E131_ARTNET);
        $this->assertEquals(1, FalconController::V4_MODE_ZCPP);
        $this->assertEquals(2, FalconController::V4_MODE_DDP);
        $this->assertEquals(3, FalconController::V4_MODE_FPP_REMOTE);
        $this->assertEquals(4, FalconController::V4_MODE_FPP_MASTER);
        $this->assertEquals(5, FalconController::V4_MODE_FPP_PLAYER);
    }
}
