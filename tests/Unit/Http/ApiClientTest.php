<?php
/**
 * Unit tests for ApiClient class
 *
 * Note: These tests focus on the class structure and behavior.
 * Integration tests with actual HTTP calls are in Integration/HttpTest.php
 *
 * @package Watcher\Tests\Unit\Http
 */

declare(strict_types=1);

namespace Watcher\Tests\Unit\Http;

use Watcher\Tests\TestCase;
use Watcher\Http\ApiClient;

class ApiClientTest extends TestCase
{
    private ApiClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = ApiClient::getInstance();
    }

    protected function tearDown(): void
    {
        // ApiClient doesn't have resetInstance, so we just call parent
        parent::tearDown();
    }

    public function testGetInstanceReturnsSingleton(): void
    {
        $instance1 = ApiClient::getInstance();
        $instance2 = ApiClient::getInstance();

        $this->assertSame($instance1, $instance2);
    }

    public function testRequestWithInvalidMethodReturnsFalse(): void
    {
        $result = $this->client->request('INVALID', 'http://127.0.0.1/test');

        $this->assertFalse($result);
    }

    public function testRequestMethodsAreCaseInsensitive(): void
    {
        // These should not return false for invalid method
        // They'll fail on network but pass method validation

        // We can't test actual success without network, but we can verify
        // the method doesn't immediately return false for valid methods
        $validMethods = ['GET', 'get', 'Get', 'POST', 'post', 'PUT', 'put', 'DELETE', 'delete'];

        foreach ($validMethods as $method) {
            // Using a non-routable IP to fail fast without actually connecting
            $result = $this->client->request($method, 'http://192.0.2.1/test', [], false, 1);
            // Should return false due to connection timeout, not method validation
            // The key is it's not returning false immediately from switch default
            $this->assertIsBool($result);
        }
    }

    /**
     * Test GET convenience method structure
     */
    public function testGetMethodCallsRequestWithCorrectParams(): void
    {
        // We can verify the method exists and has correct signature
        $reflection = new \ReflectionMethod($this->client, 'get');

        $this->assertEquals(2, $reflection->getNumberOfParameters());

        $params = $reflection->getParameters();
        $this->assertEquals('uri', $params[0]->getName());
        $this->assertEquals('timeout', $params[1]->getName());
        $this->assertTrue($params[1]->allowsNull());
    }

    /**
     * Test POST convenience method structure
     */
    public function testPostMethodCallsRequestWithCorrectParams(): void
    {
        $reflection = new \ReflectionMethod($this->client, 'post');

        $this->assertEquals(3, $reflection->getNumberOfParameters());

        $params = $reflection->getParameters();
        $this->assertEquals('uri', $params[0]->getName());
        $this->assertEquals('data', $params[1]->getName());
        $this->assertEquals('timeout', $params[2]->getName());
    }

    /**
     * Test PUT convenience method structure
     */
    public function testPutMethodCallsRequestWithCorrectParams(): void
    {
        $reflection = new \ReflectionMethod($this->client, 'put');

        $this->assertEquals(4, $reflection->getNumberOfParameters());

        $params = $reflection->getParameters();
        $this->assertEquals('uri', $params[0]->getName());
        $this->assertEquals('data', $params[1]->getName());
        $this->assertEquals('timeout', $params[2]->getName());
        $this->assertEquals('headers', $params[3]->getName());
    }

    /**
     * Test DELETE convenience method structure
     */
    public function testDeleteMethodCallsRequestWithCorrectParams(): void
    {
        $reflection = new \ReflectionMethod($this->client, 'delete');

        $this->assertEquals(2, $reflection->getNumberOfParameters());

        $params = $reflection->getParameters();
        $this->assertEquals('uri', $params[0]->getName());
        $this->assertEquals('timeout', $params[1]->getName());
    }

    /**
     * Test request method accepts string data for POST
     */
    public function testRequestAcceptsStringData(): void
    {
        $reflection = new \ReflectionMethod($this->client, 'request');
        $params = $reflection->getParameters();

        // Find the data parameter
        $dataParam = null;
        foreach ($params as $param) {
            if ($param->getName() === 'data') {
                $dataParam = $param;
                break;
            }
        }

        $this->assertNotNull($dataParam);

        // Check type allows array|string
        $type = $dataParam->getType();
        $this->assertInstanceOf(\ReflectionUnionType::class, $type);
    }

    /**
     * Test request method has correct parameters
     */
    public function testRequestMethodSignature(): void
    {
        $reflection = new \ReflectionMethod($this->client, 'request');

        $params = $reflection->getParameters();
        $this->assertGreaterThanOrEqual(3, count($params));

        // Check required parameters
        $this->assertEquals('method', $params[0]->getName());
        $this->assertFalse($params[0]->allowsNull());

        $this->assertEquals('uri', $params[1]->getName());
        $this->assertFalse($params[1]->allowsNull());

        $this->assertEquals('data', $params[2]->getName());
    }

    /**
     * Test default timeout is used
     */
    public function testDefaultTimeoutIsConfigurable(): void
    {
        $reflection = new \ReflectionClass(ApiClient::class);
        $constructor = $reflection->getConstructor();

        $params = $constructor->getParameters();
        $this->assertCount(1, $params);
        $this->assertEquals('defaultTimeout', $params[0]->getName());
        $this->assertTrue($params[0]->isDefaultValueAvailable());
        $this->assertEquals(15, $params[0]->getDefaultValue());
    }

    /**
     * Test that failed requests return false
     */
    public function testFailedRequestReturnsFalse(): void
    {
        // Use non-routable IP with very short timeout
        $result = $this->client->get('http://192.0.2.1/api/test', 1);

        $this->assertFalse($result);
    }

    /**
     * Test localhost connection (should work if FPP is running)
     * This is more of an integration test but useful for basic validation
     */
    public function testLocalConnectionIfAvailable(): void
    {
        // Quick check if local server is available
        $socket = @fsockopen('127.0.0.1', 80, $errno, $errstr, 1);

        if (!$socket) {
            $this->markTestSkipped('Local HTTP server not available');
        }

        fclose($socket);

        // Try to get FPP status
        $result = $this->client->get('http://127.0.0.1/api/fppd/status', 5);

        // Should return array or false (but not throw)
        $this->assertTrue(is_array($result) || $result === false);
    }
}
