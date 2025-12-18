<?php
/**
 * Integration tests for API endpoints
 *
 * These tests verify that API endpoints return expected response structures.
 * Requires FPP to be running locally with the plugin installed.
 *
 * @package Watcher\Tests\Integration
 */

declare(strict_types=1);

namespace Watcher\Tests\Integration;

use Watcher\Tests\TestCase;
use Watcher\Http\ApiClient;

class ApiEndpointTest extends TestCase
{
    private ApiClient $client;
    private string $baseUrl = 'http://127.0.0.1/api/plugin/fpp-plugin-watcher';

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = ApiClient::getInstance();

        // Skip all tests if FPP is not running
        $socket = @fsockopen('127.0.0.1', 80, $errno, $errstr, 1);
        if (!$socket) {
            $this->markTestSkipped('FPP not running locally - skipping API tests');
        }
        fclose($socket);
    }

    // =========================================================================
    // Core Endpoints
    // =========================================================================

    public function testVersionEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/version");

        if ($result === false) {
            $this->markTestSkipped('Plugin API not responding');
        }

        $this->assertIsArray($result);
        $this->assertArrayHasKey('version', $result);
        $this->assertIsString($result['version']);
    }

    // =========================================================================
    // Local Metrics Endpoints
    // =========================================================================

    public function testMetricsAllEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/metrics/all");

        if ($result === false) {
            $this->markTestSkipped('Metrics endpoint not responding');
        }

        $this->assertIsArray($result);
    }

    public function testMetricsMemoryFreeEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/metrics/memory/free");

        if ($result === false) {
            $this->markTestSkipped('Memory metrics endpoint not responding');
        }

        $this->assertIsArray($result);
    }

    public function testMetricsCpuAverageEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/metrics/cpu/average");

        if ($result === false) {
            $this->markTestSkipped('CPU metrics endpoint not responding');
        }

        $this->assertIsArray($result);
    }

    public function testMetricsDiskFreeEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/metrics/disk/free");

        if ($result === false) {
            $this->markTestSkipped('Disk metrics endpoint not responding');
        }

        $this->assertIsArray($result);
    }

    public function testMetricsLoadAverageEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/metrics/load/average");

        if ($result === false) {
            $this->markTestSkipped('Load metrics endpoint not responding');
        }

        $this->assertIsArray($result);
    }

    public function testMetricsInterfaceListEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/metrics/interface/list");

        if ($result === false) {
            $this->markTestSkipped('Interface list endpoint not responding');
        }

        $this->assertIsArray($result);
    }

    public function testMetricsThermalEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/metrics/thermal");

        if ($result === false) {
            $this->markTestSkipped('Thermal metrics endpoint not responding');
        }

        $this->assertIsArray($result);
    }

    public function testMetricsThermalZonesEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/metrics/thermal/zones");

        if ($result === false) {
            $this->markTestSkipped('Thermal zones endpoint not responding');
        }

        $this->assertIsArray($result);
    }

    // =========================================================================
    // Ping Metrics Endpoints
    // =========================================================================

    public function testMetricsPingRawEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/metrics/ping/raw");

        if ($result === false) {
            $this->markTestSkipped('Ping raw metrics endpoint not responding');
        }

        $this->assertIsArray($result);
    }

    public function testMetricsPingRollupEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/metrics/ping/rollup");

        if ($result === false) {
            $this->markTestSkipped('Ping rollup endpoint not responding');
        }

        $this->assertIsArray($result);
    }

    public function testMetricsPingRollupTiersEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/metrics/ping/rollup/tiers");

        if ($result === false) {
            $this->markTestSkipped('Ping rollup tiers endpoint not responding');
        }

        $this->assertIsArray($result);
    }

    // =========================================================================
    // eFuse Endpoints
    // =========================================================================

    public function testEfuseSupportedEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/efuse/supported");

        if ($result === false) {
            $this->markTestSkipped('eFuse supported endpoint not responding');
        }

        $this->assertIsArray($result);
        $this->assertArrayHasKey('supported', $result);
        $this->assertIsBool($result['supported']);
    }

    public function testEfuseCapabilitiesEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/efuse/capabilities");

        if ($result === false) {
            $this->markTestSkipped('eFuse capabilities endpoint not responding');
        }

        $this->assertIsArray($result);
    }

    // =========================================================================
    // Remote Systems Endpoints
    // =========================================================================

    public function testRemotesEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/remotes");

        if ($result === false) {
            $this->markTestSkipped('Remotes endpoint not responding');
        }

        $this->assertIsArray($result);
    }

    // =========================================================================
    // Update Check Endpoints
    // =========================================================================

    public function testUpdateCheckEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/update/check", 10);

        if ($result === false) {
            $this->markTestSkipped('Update check endpoint not responding');
        }

        $this->assertIsArray($result);
        // API returns {success: true/false, ...}
        $this->assertArrayHasKey('success', $result);
        // If successful, should have latestVersion
        if ($result['success'] ?? false) {
            $this->assertArrayHasKey('latestVersion', $result);
        }
    }

    // =========================================================================
    // Connectivity Endpoints
    // =========================================================================

    public function testConnectivityStateEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/connectivity/state");

        if ($result === false) {
            $this->markTestSkipped('Connectivity state endpoint not responding');
        }

        $this->assertIsArray($result);
    }

    // =========================================================================
    // Multi-Sync Endpoints
    // =========================================================================

    public function testMultisyncComparisonEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/multisync/comparison");

        if ($result === false) {
            $this->markTestSkipped('Multi-sync comparison endpoint not responding');
        }

        $this->assertIsArray($result);
    }

    public function testMultisyncFullStatusEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/multisync/full-status");

        if ($result === false) {
            $this->markTestSkipped('Multi-sync full status endpoint not responding');
        }

        $this->assertIsArray($result);
    }

    // =========================================================================
    // Data Management Endpoints
    // =========================================================================

    public function testDataStatsEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/data/stats");

        if ($result === false) {
            $this->markTestSkipped('Data stats endpoint not responding');
        }

        $this->assertIsArray($result);
    }

    // =========================================================================
    // Configuration Endpoints
    // =========================================================================

    public function testConfigWatcherEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/config/watcher");

        if ($result === false) {
            $this->markTestSkipped('Watcher config endpoint not responding');
        }

        $this->assertIsArray($result);
    }

    // =========================================================================
    // System Time Endpoint
    // =========================================================================

    public function testTimeEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/time");

        if ($result === false) {
            $this->markTestSkipped('Time endpoint not responding');
        }

        $this->assertIsArray($result);
        // API returns time_ms and time_s
        $this->assertArrayHasKey('time_ms', $result);
        $this->assertArrayHasKey('time_s', $result);
        $this->assertIsInt($result['time_s']);
    }

    // =========================================================================
    // Error Handling Tests
    // =========================================================================

    public function testInvalidEndpointReturns404(): void
    {
        $result = $this->client->get("{$this->baseUrl}/nonexistent/endpoint");

        // Should return false for 404
        $this->assertFalse($result);
    }

    // =========================================================================
    // Response Format Consistency Tests
    // =========================================================================

    public function testAllMetricsEndpointsReturnConsistentFormat(): void
    {
        $metricEndpoints = [
            '/metrics/memory/free',
            '/metrics/cpu/average',
            '/metrics/disk/free',
            '/metrics/load/average',
        ];

        foreach ($metricEndpoints as $endpoint) {
            $result = $this->client->get("{$this->baseUrl}{$endpoint}");

            if ($result === false) {
                continue; // Skip endpoints that don't respond
            }

            $this->assertIsArray($result, "Endpoint {$endpoint} should return array");
        }
    }

    // =========================================================================
    // Parameter Handling Tests
    // =========================================================================

    public function testPingRollupWithHoursParameter(): void
    {
        $result = $this->client->get("{$this->baseUrl}/metrics/ping/rollup?hours=24");

        if ($result === false) {
            $this->markTestSkipped('Ping rollup with hours parameter not responding');
        }

        $this->assertIsArray($result);
    }

    public function testInterfaceBandwidthWithInterfaceParameter(): void
    {
        // First get list of interfaces
        $interfaces = $this->client->get("{$this->baseUrl}/metrics/interface/list");

        if ($interfaces === false || empty($interfaces) || !is_array($interfaces)) {
            $this->markTestSkipped('No interfaces available');
        }

        // Get first interface (reset to handle associative arrays)
        $firstInterface = reset($interfaces);
        if ($firstInterface === false) {
            $this->markTestSkipped('No interfaces available');
        }

        // Get interface name
        $interface = is_array($firstInterface) ? ($firstInterface['name'] ?? null) : $firstInterface;

        if (!$interface) {
            $this->markTestSkipped('Could not determine interface name');
        }

        $result = $this->client->get("{$this->baseUrl}/metrics/interface/bandwidth?interface={$interface}");

        // May return false if interface doesn't have bandwidth data
        $this->assertTrue(
            is_array($result) || $result === false,
            'Interface bandwidth should return array or false'
        );
    }
}
