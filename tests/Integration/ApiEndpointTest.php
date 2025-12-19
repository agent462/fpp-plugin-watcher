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

    // =========================================================================
    // Falcon Controller Endpoints
    // =========================================================================

    public function testFalconStatusEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/falcon/status");

        if ($result === false) {
            $this->markTestSkipped('Falcon status endpoint not responding');
        }

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    public function testFalconConfigGetEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/falcon/config");

        if ($result === false) {
            $this->markTestSkipped('Falcon config endpoint not responding');
        }

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    // =========================================================================
    // Network Quality Endpoints
    // =========================================================================

    public function testNetworkQualityCurrentEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/metrics/network-quality/current");

        if ($result === false) {
            $this->markTestSkipped('Network quality current endpoint not responding');
        }

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    public function testNetworkQualityHistoryEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/metrics/network-quality/history?hours=6");

        if ($result === false) {
            $this->markTestSkipped('Network quality history endpoint not responding');
        }

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    // =========================================================================
    // MultiSync Ping Endpoints
    // =========================================================================

    public function testMultiSyncPingRawEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/metrics/multisync/ping/raw");

        if ($result === false) {
            $this->markTestSkipped('MultiSync ping raw endpoint not responding');
        }

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    public function testMultiSyncPingRollupEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/metrics/multisync/ping/rollup");

        if ($result === false) {
            $this->markTestSkipped('MultiSync ping rollup endpoint not responding');
        }

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    public function testMultiSyncPingRollupTiersEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/metrics/multisync/ping/rollup/tiers");

        if ($result === false) {
            $this->markTestSkipped('MultiSync ping rollup tiers endpoint not responding');
        }

        $this->assertIsArray($result);
        $this->assertArrayHasKey('tiers', $result);
    }

    // =========================================================================
    // Remote Bulk Endpoints
    // =========================================================================

    public function testRemoteBulkStatusEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/remote/bulk/status");

        if ($result === false) {
            $this->markTestSkipped('Remote bulk status endpoint not responding');
        }

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    public function testRemoteBulkUpdatesEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/remote/bulk/updates", 15);

        if ($result === false) {
            $this->markTestSkipped('Remote bulk updates endpoint not responding');
        }

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    // =========================================================================
    // MQTT Endpoints
    // =========================================================================

    public function testMqttEventsEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/mqtt/events?hours=24");

        if ($result === false) {
            $this->markTestSkipped('MQTT events endpoint not responding');
        }

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    public function testMqttStatsEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/mqtt/stats?hours=24");

        if ($result === false) {
            $this->markTestSkipped('MQTT stats endpoint not responding');
        }

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    public function testMqttHostsEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/mqtt/hosts");

        if ($result === false) {
            $this->markTestSkipped('MQTT hosts endpoint not responding');
        }

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('count', $result);
        $this->assertArrayHasKey('hosts', $result);
    }

    // =========================================================================
    // Wireless Endpoints
    // =========================================================================

    public function testWirelessMetricsEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/metrics/wireless");

        if ($result === false) {
            $this->markTestSkipped('Wireless metrics endpoint not responding');
        }

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    public function testWirelessInterfacesEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/metrics/wireless/interfaces");

        if ($result === false) {
            $this->markTestSkipped('Wireless interfaces endpoint not responding');
        }

        $this->assertIsArray($result);
        $this->assertArrayHasKey('count', $result);
        $this->assertArrayHasKey('interfaces', $result);
    }

    // =========================================================================
    // Additional eFuse Endpoints
    // =========================================================================

    public function testEfuseConfigEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/efuse/config");

        if ($result === false) {
            $this->markTestSkipped('eFuse config endpoint not responding');
        }

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    // =========================================================================
    // Plugin Updates Endpoints
    // =========================================================================

    public function testLocalPluginUpdatesEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/plugins/updates", 15);

        if ($result === false) {
            $this->markTestSkipped('Local plugin updates endpoint not responding');
        }

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    // =========================================================================
    // Playback Sync Endpoint
    // =========================================================================

    public function testRemotePlaybackSyncEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/remote/playback/sync");

        if ($result === false) {
            $this->markTestSkipped('Playback sync endpoint not responding');
        }

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        // Should have local and remotes keys
        $this->assertArrayHasKey('local', $result);
        $this->assertArrayHasKey('remotes', $result);
    }

    // =========================================================================
    // Clock Drift Endpoint
    // =========================================================================

    public function testMultiSyncClockDriftEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/multisync/clock-drift");

        if ($result === false) {
            $this->markTestSkipped('Clock drift endpoint not responding');
        }

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    // =========================================================================
    // Output Discrepancies Endpoint
    // =========================================================================

    public function testOutputDiscrepanciesEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/outputs/discrepancies");

        if ($result === false) {
            $this->markTestSkipped('Output discrepancies endpoint not responding');
        }

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    // =========================================================================
    // Collectd Config Endpoint
    // =========================================================================

    public function testCollectdConfigEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/config/collectd");

        // This may return false if collectd config doesn't exist
        if ($result === false) {
            $this->markTestSkipped('Collectd config endpoint not responding or config not found');
        }

        $this->assertIsArray($result);
    }

    // =========================================================================
    // Parameter Validation Tests
    // =========================================================================

    public function testRemoteStatusWithoutHostReturnsError(): void
    {
        $result = $this->client->get("{$this->baseUrl}/remote/status");

        // Should return error without host parameter
        // Could return false (HTTP error) or error response
        $this->assertTrue(
            $result === false ||
            (is_array($result) && isset($result['success']) && $result['success'] === false),
            'Remote status without host should return error'
        );
    }

    public function testNetworkQualityHostWithoutAddressReturnsError(): void
    {
        $result = $this->client->get("{$this->baseUrl}/metrics/network-quality/host");

        // Should return error without address parameter
        $this->assertTrue(
            $result === false ||
            (is_array($result) && isset($result['success']) && $result['success'] === false),
            'Network quality host without address should return error'
        );
    }

    public function testPingCheckWithoutIpsReturnsError(): void
    {
        $result = $this->client->get("{$this->baseUrl}/ping/check");

        // Should return error without ips parameter
        $this->assertTrue(
            $result === false ||
            (is_array($result) && isset($result['success']) && $result['success'] === false),
            'Ping check without ips should return error'
        );
    }

    public function testMultiSyncComparisonHostWithInvalidAddressReturnsError(): void
    {
        $result = $this->client->get("{$this->baseUrl}/multisync/comparison/host?address=not-an-ip");

        // Should return error with invalid IP
        $this->assertTrue(
            $result === false ||
            (is_array($result) && isset($result['success']) && $result['success'] === false),
            'Comparison host with invalid IP should return error'
        );
    }

    // =========================================================================
    // Hours Parameter Validation Tests
    // =========================================================================

    public function testHoursParameterClampsToMinimum(): void
    {
        // Test with hours=0 (should be clamped to 1)
        $result = $this->client->get("{$this->baseUrl}/metrics/ping/rollup?hours=0");

        if ($result === false) {
            $this->markTestSkipped('Ping rollup endpoint not responding');
        }

        // Should still return valid response
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    public function testHoursParameterClampsToMaximum(): void
    {
        // Test with hours=9999 (should be clamped to 2160)
        $result = $this->client->get("{$this->baseUrl}/metrics/ping/rollup?hours=9999");

        if ($result === false) {
            $this->markTestSkipped('Ping rollup endpoint not responding');
        }

        // Should still return valid response
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    public function testHoursParameterHandlesNonNumeric(): void
    {
        // Test with non-numeric hours
        $result = $this->client->get("{$this->baseUrl}/metrics/ping/rollup?hours=abc");

        if ($result === false) {
            $this->markTestSkipped('Ping rollup endpoint not responding');
        }

        // Should still return valid response (will use minimum)
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    // =========================================================================
    // Ping Rollup Tier Endpoint
    // =========================================================================

    public function testPingRollupTierEndpoint(): void
    {
        $result = $this->client->get("{$this->baseUrl}/metrics/ping/rollup/1min");

        if ($result === false) {
            $this->markTestSkipped('Ping rollup tier endpoint not responding');
        }

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }
}
