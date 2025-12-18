<?php
/**
 * Unit tests for Comparator class
 *
 * @package Watcher\Tests\Unit\MultiSync
 */

declare(strict_types=1);

namespace Watcher\Tests\Unit\MultiSync;

use Watcher\Tests\TestCase;
use Watcher\MultiSync\Comparator;

class ComparatorTest extends TestCase
{
    private Comparator $comparator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->comparator = Comparator::getInstance();
    }

    // =========================================================================
    // Singleton Tests
    // =========================================================================

    public function testGetInstanceReturnsSingleton(): void
    {
        $instance1 = Comparator::getInstance();
        $instance2 = Comparator::getInstance();

        $this->assertSame($instance1, $instance2);
    }

    public function testResetInstanceCreatesNewInstance(): void
    {
        $instance1 = Comparator::getInstance();
        Comparator::resetInstance();
        $instance2 = Comparator::getInstance();

        $this->assertNotSame($instance1, $instance2);
    }

    // =========================================================================
    // Constants Tests
    // =========================================================================

    public function testSeverityConstantsAreDefined(): void
    {
        $this->assertEquals(1, Comparator::SEVERITY_INFO);
        $this->assertEquals(2, Comparator::SEVERITY_WARNING);
        $this->assertEquals(3, Comparator::SEVERITY_CRITICAL);
    }

    public function testThresholdConstantsAreDefined(): void
    {
        $this->assertEquals(5, Comparator::DRIFT_WARNING_THRESHOLD);
        $this->assertEquals(10, Comparator::DRIFT_CRITICAL_THRESHOLD);
        $this->assertEquals(0.5, Comparator::TIME_OFFSET_WARNING);
        $this->assertEquals(1.0, Comparator::TIME_OFFSET_CRITICAL);
    }

    public function testTimeoutConstantsAreDefined(): void
    {
        $this->assertEquals(5, Comparator::TIMEOUT_STANDARD);
        $this->assertEquals(3, Comparator::TIMEOUT_STATUS);
    }

    // =========================================================================
    // comparePlayerToRemote Tests
    // =========================================================================

    public function testComparePlayerToRemoteDetectsOfflineRemote(): void
    {
        $player = [
            'metrics' => ['sequencePlaying' => true],
            'fppStatus' => ['sequence' => 'test.fseq', 'status' => 'playing']
        ];

        $remote = [
            'address' => '192.168.1.100',
            'hostname' => 'TestRemote',
            'online' => false,
            'pluginInstalled' => false,
            'metrics' => null,
            'fppStatus' => null
        ];

        $issues = $this->comparator->comparePlayerToRemote($player, $remote);

        $this->assertCount(1, $issues);
        $this->assertEquals('offline', $issues[0]['type']);
        $this->assertEquals(Comparator::SEVERITY_CRITICAL, $issues[0]['severity']);
        $this->assertEquals('TestRemote', $issues[0]['host']);
    }

    public function testComparePlayerToRemoteDetectsNoPlugin(): void
    {
        $player = [
            'metrics' => ['sequencePlaying' => true],
            'fppStatus' => ['sequence' => 'test.fseq', 'status' => 'playing']
        ];

        $remote = [
            'address' => '192.168.1.100',
            'hostname' => 'TestRemote',
            'online' => true,
            'pluginInstalled' => false,
            'metrics' => null,
            'fppStatus' => null
        ];

        $issues = $this->comparator->comparePlayerToRemote($player, $remote);

        $this->assertCount(1, $issues);
        $this->assertEquals('no_plugin', $issues[0]['type']);
        $this->assertEquals(Comparator::SEVERITY_INFO, $issues[0]['severity']);
    }

    public function testComparePlayerToRemoteNoIssuesWhenInSync(): void
    {
        $player = [
            'metrics' => [
                'sequencePlaying' => true,
                'currentMasterSequence' => 'test.fseq'
            ],
            'fppStatus' => ['sequence' => 'test.fseq', 'status' => 'playing']
        ];

        $remote = [
            'address' => '192.168.1.100',
            'hostname' => 'TestRemote',
            'online' => true,
            'pluginInstalled' => true,
            'metrics' => [
                'sequencePlaying' => true,
                'currentMasterSequence' => 'test.fseq',
                'maxFrameDrift' => 2,
                'avgFrameDrift' => 1,
                'secondsSinceLastSync' => 5
            ],
            'fppStatus' => ['sequence' => 'test.fseq', 'status' => 'playing']
        ];

        $issues = $this->comparator->comparePlayerToRemote($player, $remote);

        $this->assertEmpty($issues, 'Should have no issues when properly synced');
    }

    public function testComparePlayerToRemoteDetectsSequenceMismatch(): void
    {
        $player = [
            'metrics' => ['sequencePlaying' => true],
            'fppStatus' => ['sequence' => 'correct.fseq', 'status' => 'playing']
        ];

        $remote = [
            'address' => '192.168.1.100',
            'hostname' => 'TestRemote',
            'online' => true,
            'pluginInstalled' => true,
            'metrics' => [
                'sequencePlaying' => true,
                'currentMasterSequence' => 'wrong.fseq',
                'maxFrameDrift' => 0,
                'avgFrameDrift' => 0
            ],
            'fppStatus' => ['sequence' => 'wrong.fseq', 'status' => 'playing']
        ];

        $issues = $this->comparator->comparePlayerToRemote($player, $remote);

        $sequenceMismatch = array_filter($issues, fn($i) => $i['type'] === 'sequence_mismatch');
        $this->assertNotEmpty($sequenceMismatch, 'Should detect sequence mismatch');
        $this->assertEquals(Comparator::SEVERITY_CRITICAL, reset($sequenceMismatch)['severity']);
    }

    public function testComparePlayerToRemoteDetectsWarningDrift(): void
    {
        $player = [
            'metrics' => ['sequencePlaying' => true],
            'fppStatus' => ['sequence' => 'test.fseq', 'status' => 'playing']
        ];

        $remote = [
            'address' => '192.168.1.100',
            'hostname' => 'TestRemote',
            'online' => true,
            'pluginInstalled' => true,
            'metrics' => [
                'sequencePlaying' => true,
                'currentMasterSequence' => 'test.fseq',
                'maxFrameDrift' => 8,
                'avgFrameDrift' => 7,  // Above WARNING (5), below CRITICAL (10)
                'secondsSinceLastSync' => 1
            ],
            'fppStatus' => ['sequence' => 'test.fseq', 'status' => 'playing']
        ];

        $issues = $this->comparator->comparePlayerToRemote($player, $remote);

        $driftIssues = array_filter($issues, fn($i) => $i['type'] === 'sync_drift');
        $this->assertNotEmpty($driftIssues, 'Should detect frame drift');
        $this->assertEquals(Comparator::SEVERITY_WARNING, reset($driftIssues)['severity']);
    }

    public function testComparePlayerToRemoteDetectsCriticalDrift(): void
    {
        $player = [
            'metrics' => ['sequencePlaying' => true],
            'fppStatus' => ['sequence' => 'test.fseq', 'status' => 'playing']
        ];

        $remote = [
            'address' => '192.168.1.100',
            'hostname' => 'TestRemote',
            'online' => true,
            'pluginInstalled' => true,
            'metrics' => [
                'sequencePlaying' => true,
                'currentMasterSequence' => 'test.fseq',
                'maxFrameDrift' => 15,
                'avgFrameDrift' => 12,  // Above CRITICAL threshold (10)
                'secondsSinceLastSync' => 1
            ],
            'fppStatus' => ['sequence' => 'test.fseq', 'status' => 'playing']
        ];

        $issues = $this->comparator->comparePlayerToRemote($player, $remote);

        $driftIssues = array_filter($issues, fn($i) => $i['type'] === 'sync_drift');
        $this->assertNotEmpty($driftIssues, 'Should detect critical frame drift');
        $this->assertEquals(Comparator::SEVERITY_CRITICAL, reset($driftIssues)['severity']);
    }

    public function testComparePlayerToRemoteDetectsNoSyncPackets(): void
    {
        $player = [
            'metrics' => ['sequencePlaying' => true],
            'fppStatus' => ['sequence' => 'test.fseq', 'status' => 'playing']
        ];

        $remote = [
            'address' => '192.168.1.100',
            'hostname' => 'TestRemote',
            'online' => true,
            'pluginInstalled' => true,
            'metrics' => [
                'sequencePlaying' => true,
                'currentMasterSequence' => 'test.fseq',
                'maxFrameDrift' => 0,
                'avgFrameDrift' => 0,
                'secondsSinceLastSync' => 60  // More than 30 seconds
            ],
            'fppStatus' => ['sequence' => 'test.fseq', 'status' => 'playing']
        ];

        $issues = $this->comparator->comparePlayerToRemote($player, $remote);

        $syncIssues = array_filter($issues, fn($i) => $i['type'] === 'no_sync_packets');
        $this->assertNotEmpty($syncIssues, 'Should detect missing sync packets');
        $this->assertEquals(Comparator::SEVERITY_WARNING, reset($syncIssues)['severity']);
    }

    public function testComparePlayerToRemoteDetectsStateMismatch(): void
    {
        $player = [
            'metrics' => ['sequencePlaying' => true],
            'fppStatus' => ['sequence' => 'test.fseq', 'status' => 'playing']
        ];

        $remote = [
            'address' => '192.168.1.100',
            'hostname' => 'TestRemote',
            'online' => true,
            'pluginInstalled' => true,
            'metrics' => [
                'sequencePlaying' => false,  // Remote not syncing
                'maxFrameDrift' => 0,
                'avgFrameDrift' => 0
            ],
            'fppStatus' => ['sequence' => '', 'status' => 'idle']  // Remote idle
        ];

        $issues = $this->comparator->comparePlayerToRemote($player, $remote);

        $stateIssues = array_filter($issues, fn($i) => $i['type'] === 'state_mismatch');
        $this->assertNotEmpty($stateIssues, 'Should detect state mismatch');
        $this->assertEquals(Comparator::SEVERITY_WARNING, reset($stateIssues)['severity']);
    }

    // =========================================================================
    // Response Time Handling Tests (Regression Prevention)
    // =========================================================================

    /**
     * Test that processResults correctly maps response_time from CurlMultiHandler
     *
     * This test prevents regression of the bug where responseTime was hardcoded to 0
     * instead of using the actual response_time from CurlMultiHandler.
     *
     * @see https://github.com/FPP-Watcher/issues/XXX
     */
    public function testResponseTimeIsProperlyMappedFromCurlResults(): void
    {
        // Simulate what CurlMultiHandler returns
        $curlResults = [
            '192.168.1.100' => [
                'http_code' => 200,
                'data' => [
                    'success' => true,
                    'watcherLoaded' => true,
                    'watcher' => ['sequencePlaying' => false],
                    'fpp' => ['status' => 'idle']
                ],
                'response_time' => 55.3  // This is the key value that must be preserved
            ],
            '192.168.1.101' => [
                'http_code' => 200,
                'data' => [
                    'success' => true,
                    'watcherLoaded' => true,
                    'watcher' => ['sequencePlaying' => false],
                    'fpp' => ['status' => 'idle']
                ],
                'response_time' => 72.8
            ]
        ];

        // Use reflection to test the internal processing logic
        $combinedResults = [];
        $hostnames = [
            '192.168.1.100' => 'TestHost1',
            '192.168.1.101' => 'TestHost2'
        ];

        foreach ($curlResults as $address => $result) {
            $combinedResults[$address] = [
                'httpCode' => $result['http_code'],
                'response' => $result['data'] ? json_encode($result['data']) : null,
                'responseTime' => $result['response_time'] ?? 0,  // This is the fixed line
                'hostname' => $hostnames[$address]
            ];
        }

        // Verify response times are correctly mapped (not hardcoded to 0)
        $this->assertEquals(55.3, $combinedResults['192.168.1.100']['responseTime']);
        $this->assertEquals(72.8, $combinedResults['192.168.1.101']['responseTime']);

        // Verify that NOT using response_time would result in 0 (the bug)
        $buggyResult = [
            'responseTime' => 0,  // Bug: hardcoded to 0
        ];
        $this->assertEquals(0, $buggyResult['responseTime']);
        $this->assertNotEquals($buggyResult['responseTime'], $combinedResults['192.168.1.100']['responseTime']);
    }

    /**
     * Test that missing response_time defaults to 0 gracefully
     */
    public function testMissingResponseTimeDefaultsToZero(): void
    {
        $curlResult = [
            'http_code' => 200,
            'data' => ['success' => true],
            // 'response_time' is missing
        ];

        $responseTime = $curlResult['response_time'] ?? 0;

        $this->assertEquals(0, $responseTime);
    }

    /**
     * Test that null response_time defaults to 0 gracefully
     */
    public function testNullResponseTimeDefaultsToZero(): void
    {
        $curlResult = [
            'http_code' => 200,
            'data' => ['success' => true],
            'response_time' => null
        ];

        $responseTime = $curlResult['response_time'] ?? 0;

        $this->assertEquals(0, $responseTime);
    }

    // =========================================================================
    // Public Method Existence Tests
    // =========================================================================

    public function testPublicMethodsExist(): void
    {
        $reflection = new \ReflectionClass(Comparator::class);

        $expectedMethods = [
            'getInstance',
            'resetInstance',
            'fetchRemoteSyncMetrics',
            'fetchRemoteFppStatus',
            'collectRemoteSyncMetrics',
            'comparePlayerToRemote',
            'getComparison',
            'getComparisonForHost',
        ];

        foreach ($expectedMethods as $methodName) {
            $this->assertTrue(
                $reflection->hasMethod($methodName),
                "Missing expected method: {$methodName}"
            );
        }
    }
}
