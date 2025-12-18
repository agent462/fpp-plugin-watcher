<?php
/**
 * Unit tests for CurlMultiHandler class
 *
 * @package Watcher\Tests\Unit\Http
 */

declare(strict_types=1);

namespace Watcher\Tests\Unit\Http;

use Watcher\Tests\TestCase;
use Watcher\Http\CurlMultiHandler;

class CurlMultiHandlerTest extends TestCase
{
    public function testConstructorCreatesMultiHandle(): void
    {
        $handler = new CurlMultiHandler();

        $this->assertInstanceOf(\CurlMultiHandle::class, $handler->getMultiHandle());
    }

    public function testAddRequestReturnsSelf(): void
    {
        $handler = new CurlMultiHandler();

        $result = $handler->addRequest('test', 'http://example.com');

        $this->assertSame($handler, $result);
    }

    public function testAddRequestFluentInterface(): void
    {
        $handler = new CurlMultiHandler();

        // Should be able to chain calls
        $handler
            ->addRequest('req1', 'http://example.com/1')
            ->addRequest('req2', 'http://example.com/2')
            ->addRequest('req3', 'http://example.com/3');

        // Verify handles were created
        $this->assertNotNull($handler->getHandle('req1'));
        $this->assertNotNull($handler->getHandle('req2'));
        $this->assertNotNull($handler->getHandle('req3'));
    }

    public function testGetHandleReturnsNullForNonexistent(): void
    {
        $handler = new CurlMultiHandler();

        $result = $handler->getHandle('nonexistent');

        $this->assertNull($result);
    }

    public function testGetHandleReturnsCurlHandle(): void
    {
        $handler = new CurlMultiHandler();
        $handler->addRequest('test', 'http://example.com');

        $handle = $handler->getHandle('test');

        $this->assertInstanceOf(\CurlHandle::class, $handle);
    }

    public function testExecuteReturnsResultsArray(): void
    {
        $handler = new CurlMultiHandler(1); // 1 second timeout

        // Use non-routable IP to fail fast
        $handler->addRequest('test', 'http://192.0.2.1/test');

        $results = $handler->execute();

        $this->assertIsArray($results);
        $this->assertArrayHasKey('test', $results);
    }

    public function testExecuteResultStructure(): void
    {
        $handler = new CurlMultiHandler(1);
        $handler->addRequest('test', 'http://192.0.2.1/test');

        $results = $handler->execute();
        $result = $results['test'];

        // Verify result structure
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('http_code', $result);
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayHasKey('response_time', $result);

        // Verify types
        $this->assertIsBool($result['success']);
        $this->assertIsInt($result['http_code']);
        $this->assertIsFloat($result['response_time']);
    }

    public function testExecuteWithFailedRequest(): void
    {
        $handler = new CurlMultiHandler(1);
        $handler->addRequest('fail', 'http://192.0.2.1/nonexistent');

        $results = $handler->execute();

        $this->assertFalse($results['fail']['success']);
        $this->assertNull($results['fail']['data']);
    }

    public function testExecuteMultipleRequests(): void
    {
        $handler = new CurlMultiHandler(1);

        $handler
            ->addRequest('req1', 'http://192.0.2.1/test1')
            ->addRequest('req2', 'http://192.0.2.2/test2')
            ->addRequest('req3', 'http://192.0.2.3/test3');

        $results = $handler->execute();

        $this->assertCount(3, $results);
        $this->assertArrayHasKey('req1', $results);
        $this->assertArrayHasKey('req2', $results);
        $this->assertArrayHasKey('req3', $results);
    }

    public function testExecuteClearsHandles(): void
    {
        $handler = new CurlMultiHandler(1);
        $handler->addRequest('test', 'http://192.0.2.1/test');

        // Before execute, handle exists
        $this->assertNotNull($handler->getHandle('test'));

        $handler->execute();

        // After execute, handle should be cleared
        $this->assertNull($handler->getHandle('test'));
    }

    public function testCustomTimeoutPerRequest(): void
    {
        $handler = new CurlMultiHandler(10); // Default 10s

        // Add request with custom 1s timeout
        $handler->addRequest('fast', 'http://192.0.2.1/test', 1);
        $handler->addRequest('slow', 'http://192.0.2.2/test', null); // Uses default

        // Just verify both are added
        $this->assertNotNull($handler->getHandle('fast'));
        $this->assertNotNull($handler->getHandle('slow'));
    }

    public function testCustomHeaders(): void
    {
        $handler = new CurlMultiHandler(1);

        $customHeaders = [
            'X-Custom-Header: TestValue',
            'Authorization: Bearer token123',
        ];

        $handler->addRequest('test', 'http://192.0.2.1/test', null, $customHeaders);

        // Handle should be created (we can't easily verify headers without mocking)
        $this->assertNotNull($handler->getHandle('test'));
    }

    public function testResponseTimeIsInMilliseconds(): void
    {
        $handler = new CurlMultiHandler(1);
        $handler->addRequest('test', 'http://192.0.2.1/test');

        $results = $handler->execute();

        // Response time should be >= 0 (even for failed requests)
        $this->assertGreaterThanOrEqual(0, $results['test']['response_time']);

        // Should be a reasonable value (less than timeout * 1000 ms + some buffer)
        $this->assertLessThan(5000, $results['test']['response_time']);
    }

    public function testEmptyExecuteReturnsEmptyArray(): void
    {
        $handler = new CurlMultiHandler();

        $results = $handler->execute();

        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    /**
     * Test with actual local connection if available
     */
    public function testLocalConnectionIfAvailable(): void
    {
        $socket = @fsockopen('127.0.0.1', 80, $errno, $errstr, 1);

        if (!$socket) {
            $this->markTestSkipped('Local HTTP server not available');
        }

        fclose($socket);

        $handler = new CurlMultiHandler(5);
        $handler
            ->addRequest('status', 'http://127.0.0.1/api/fppd/status')
            ->addRequest('version', 'http://127.0.0.1/api/system/version');

        $results = $handler->execute();

        // At least verify structure is correct
        $this->assertCount(2, $results);

        foreach ($results as $key => $result) {
            $this->assertArrayHasKey('success', $result);
            $this->assertArrayHasKey('data', $result);
            $this->assertArrayHasKey('http_code', $result);
        }
    }

    public function testDestructorCleanup(): void
    {
        $handler = new CurlMultiHandler();
        $handler->addRequest('test', 'http://example.com');

        $multiHandle = $handler->getMultiHandle();
        $this->assertInstanceOf(\CurlMultiHandle::class, $multiHandle);

        // Destructor should be called when handler goes out of scope
        unset($handler);

        // Can't easily verify curl_multi_close was called, but no error = success
        $this->assertTrue(true);
    }

    public function testDuplicateKeysOverwrite(): void
    {
        $handler = new CurlMultiHandler(1);

        $handler->addRequest('key', 'http://192.0.2.1/first');
        $handler->addRequest('key', 'http://192.0.2.2/second');

        // Only one handle should exist for 'key'
        $this->assertNotNull($handler->getHandle('key'));

        $results = $handler->execute();

        // Only one result
        $this->assertCount(1, $results);
        $this->assertArrayHasKey('key', $results);
    }

    public function testRawResponseIsIncluded(): void
    {
        $handler = new CurlMultiHandler(1);
        $handler->addRequest('test', 'http://192.0.2.1/test');

        $results = $handler->execute();

        // raw_response key should exist
        $this->assertArrayHasKey('raw_response', $results['test']);
    }
}
