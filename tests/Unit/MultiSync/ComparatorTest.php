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

    public function testSeverityConstantsAreOrdered(): void
    {
        $this->assertLessThan(Comparator::SEVERITY_WARNING, Comparator::SEVERITY_INFO);
        $this->assertLessThan(Comparator::SEVERITY_CRITICAL, Comparator::SEVERITY_WARNING);
    }

    public function testThresholdConstantsAreDefined(): void
    {
        $this->assertEquals(5, Comparator::DRIFT_WARNING_THRESHOLD);
        $this->assertEquals(10, Comparator::DRIFT_CRITICAL_THRESHOLD);
        $this->assertEquals(0.5, Comparator::TIME_OFFSET_WARNING);
        $this->assertEquals(1.0, Comparator::TIME_OFFSET_CRITICAL);
    }

    public function testDriftThresholdsAreOrdered(): void
    {
        $this->assertLessThan(
            Comparator::DRIFT_CRITICAL_THRESHOLD,
            Comparator::DRIFT_WARNING_THRESHOLD
        );
    }

    public function testTimeOffsetThresholdsAreOrdered(): void
    {
        $this->assertLessThan(
            Comparator::TIME_OFFSET_CRITICAL,
            Comparator::TIME_OFFSET_WARNING
        );
    }

    public function testTimeoutConstantsAreDefined(): void
    {
        $this->assertEquals(5, Comparator::TIMEOUT_STANDARD);
        $this->assertEquals(3, Comparator::TIMEOUT_STATUS);
    }

    // =========================================================================
    // comparePlayerToRemote Tests - Offline/No Plugin
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

    public function testComparePlayerToRemoteUsesAddressIfNoHostname(): void
    {
        $player = [
            'metrics' => ['sequencePlaying' => true],
            'fppStatus' => ['sequence' => 'test.fseq']
        ];

        $remote = [
            'address' => '192.168.1.100',
            // No hostname key
            'online' => false,
            'pluginInstalled' => false,
            'metrics' => null,
            'fppStatus' => null
        ];

        $issues = $this->comparator->comparePlayerToRemote($player, $remote);

        $this->assertEquals('192.168.1.100', $issues[0]['host']);
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

    // =========================================================================
    // comparePlayerToRemote Tests - In Sync
    // =========================================================================

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

    public function testComparePlayerToRemoteNoIssuesWhenBothIdle(): void
    {
        $player = [
            'metrics' => ['sequencePlaying' => false],
            'fppStatus' => ['sequence' => '', 'status' => 'idle']
        ];

        $remote = [
            'address' => '192.168.1.100',
            'hostname' => 'TestRemote',
            'online' => true,
            'pluginInstalled' => true,
            'metrics' => [
                'sequencePlaying' => false,
                'maxFrameDrift' => 0,
                'avgFrameDrift' => 0
            ],
            'fppStatus' => ['sequence' => '', 'status' => 'idle']
        ];

        $issues = $this->comparator->comparePlayerToRemote($player, $remote);

        $this->assertEmpty($issues);
    }

    // =========================================================================
    // comparePlayerToRemote Tests - Sequence Mismatch
    // =========================================================================

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
        $issue = reset($sequenceMismatch);
        $this->assertEquals(Comparator::SEVERITY_CRITICAL, $issue['severity']);
        $this->assertEquals('correct.fseq', $issue['expected']);
        $this->assertEquals('wrong.fseq', $issue['actual']);
    }

    public function testComparePlayerToRemoteNoMismatchWhenEmptySequences(): void
    {
        $player = [
            'metrics' => ['sequencePlaying' => true],
            'fppStatus' => ['sequence' => '', 'status' => 'playing']
        ];

        $remote = [
            'address' => '192.168.1.100',
            'hostname' => 'TestRemote',
            'online' => true,
            'pluginInstalled' => true,
            'metrics' => [
                'sequencePlaying' => true,
                'maxFrameDrift' => 0,
                'avgFrameDrift' => 0
            ],
            'fppStatus' => ['sequence' => '', 'status' => 'playing']
        ];

        $issues = $this->comparator->comparePlayerToRemote($player, $remote);

        $sequenceMismatch = array_filter($issues, fn($i) => $i['type'] === 'sequence_mismatch');
        $this->assertEmpty($sequenceMismatch, 'Should not detect mismatch when sequences are empty');
    }

    // =========================================================================
    // comparePlayerToRemote Tests - Missing Sequence
    // =========================================================================

    public function testComparePlayerToRemoteDetectsMissingSequence(): void
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
                'avgFrameDrift' => 0
            ],
            'fppStatus' => ['sequence' => '', 'status' => 'idle'] // Remote thinks it should be playing but isn't
        ];

        $issues = $this->comparator->comparePlayerToRemote($player, $remote);

        $missingSeq = array_filter($issues, fn($i) => $i['type'] === 'missing_sequence');
        $this->assertNotEmpty($missingSeq, 'Should detect missing sequence');
        $issue = reset($missingSeq);
        $this->assertEquals(Comparator::SEVERITY_CRITICAL, $issue['severity']);
    }

    // =========================================================================
    // comparePlayerToRemote Tests - State Mismatch
    // =========================================================================

    public function testComparePlayerToRemoteDetectsStateMismatchPlayerPlayingRemoteIdle(): void
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
                'sequencePlaying' => false,
                'maxFrameDrift' => 0,
                'avgFrameDrift' => 0
            ],
            'fppStatus' => ['sequence' => '', 'status' => 'idle']
        ];

        $issues = $this->comparator->comparePlayerToRemote($player, $remote);

        $stateIssues = array_filter($issues, fn($i) => $i['type'] === 'state_mismatch');
        $this->assertNotEmpty($stateIssues, 'Should detect state mismatch');
        $issue = reset($stateIssues);
        $this->assertEquals(Comparator::SEVERITY_WARNING, $issue['severity']);
        $this->assertEquals('playing', $issue['expected']);
        $this->assertEquals('stopped', $issue['actual']);
    }

    public function testComparePlayerToRemoteDetectsStateMismatchPlayerIdleRemotePlaying(): void
    {
        $player = [
            'metrics' => ['sequencePlaying' => false],
            'fppStatus' => ['sequence' => '', 'status' => 'idle']
        ];

        $remote = [
            'address' => '192.168.1.100',
            'hostname' => 'TestRemote',
            'online' => true,
            'pluginInstalled' => true,
            'metrics' => [
                'sequencePlaying' => false,
                'maxFrameDrift' => 0,
                'avgFrameDrift' => 0
            ],
            'fppStatus' => ['sequence' => 'test.fseq', 'status' => 'playing']
        ];

        $issues = $this->comparator->comparePlayerToRemote($player, $remote);

        $stateIssues = array_filter($issues, fn($i) => $i['type'] === 'state_mismatch');
        $this->assertNotEmpty($stateIssues);
        $issue = reset($stateIssues);
        $this->assertEquals('stopped', $issue['expected']);
        $this->assertEquals('playing', $issue['actual']);
    }

    public function testComparePlayerToRemoteUsesMetricsIfNoFppStatus(): void
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
                'sequencePlaying' => false, // Sync says not playing
                'maxFrameDrift' => 0,
                'avgFrameDrift' => 0
            ],
            'fppStatus' => null // No FPP status available
        ];

        $issues = $this->comparator->comparePlayerToRemote($player, $remote);

        $stateIssues = array_filter($issues, fn($i) => $i['type'] === 'state_mismatch');
        $this->assertNotEmpty($stateIssues);
    }

    // =========================================================================
    // comparePlayerToRemote Tests - Frame Drift
    // =========================================================================

    public function testComparePlayerToRemoteNoDriftIssueWhenBelowThreshold(): void
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
                'maxFrameDrift' => 3,
                'avgFrameDrift' => 2, // Below WARNING threshold (5)
                'secondsSinceLastSync' => 1
            ],
            'fppStatus' => ['sequence' => 'test.fseq', 'status' => 'playing']
        ];

        $issues = $this->comparator->comparePlayerToRemote($player, $remote);

        $driftIssues = array_filter($issues, fn($i) => $i['type'] === 'sync_drift');
        $this->assertEmpty($driftIssues, 'Should not report drift below threshold');
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
                'avgFrameDrift' => 7, // Above WARNING (5), below CRITICAL (10)
                'secondsSinceLastSync' => 1
            ],
            'fppStatus' => ['sequence' => 'test.fseq', 'status' => 'playing']
        ];

        $issues = $this->comparator->comparePlayerToRemote($player, $remote);

        $driftIssues = array_filter($issues, fn($i) => $i['type'] === 'sync_drift');
        $this->assertNotEmpty($driftIssues, 'Should detect frame drift');
        $issue = reset($driftIssues);
        $this->assertEquals(Comparator::SEVERITY_WARNING, $issue['severity']);
        $this->assertEquals(8, $issue['maxDrift']);
        $this->assertEquals(7.0, $issue['avgDrift']);
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
                'avgFrameDrift' => 12, // Above CRITICAL threshold (10)
                'secondsSinceLastSync' => 1
            ],
            'fppStatus' => ['sequence' => 'test.fseq', 'status' => 'playing']
        ];

        $issues = $this->comparator->comparePlayerToRemote($player, $remote);

        $driftIssues = array_filter($issues, fn($i) => $i['type'] === 'sync_drift');
        $this->assertNotEmpty($driftIssues, 'Should detect critical frame drift');
        $issue = reset($driftIssues);
        $this->assertEquals(Comparator::SEVERITY_CRITICAL, $issue['severity']);
    }

    public function testComparePlayerToRemoteHandlesNegativeDrift(): void
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
                'maxFrameDrift' => -15, // Negative drift (remote ahead)
                'avgFrameDrift' => -12,
                'secondsSinceLastSync' => 1
            ],
            'fppStatus' => ['sequence' => 'test.fseq', 'status' => 'playing']
        ];

        $issues = $this->comparator->comparePlayerToRemote($player, $remote);

        // Should use absolute value for drift comparison
        $driftIssues = array_filter($issues, fn($i) => $i['type'] === 'sync_drift');
        $this->assertNotEmpty($driftIssues, 'Should detect negative drift as critical');
        $this->assertEquals(Comparator::SEVERITY_CRITICAL, reset($driftIssues)['severity']);
    }

    public function testComparePlayerToRemoteHandlesMissingDriftValues(): void
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
                // No maxFrameDrift or avgFrameDrift
                'secondsSinceLastSync' => 1
            ],
            'fppStatus' => ['sequence' => 'test.fseq', 'status' => 'playing']
        ];

        $issues = $this->comparator->comparePlayerToRemote($player, $remote);

        $driftIssues = array_filter($issues, fn($i) => $i['type'] === 'sync_drift');
        $this->assertEmpty($driftIssues, 'Should not report drift when values are missing');
    }

    public function testComparePlayerToRemoteDriftRoundsToOneTenth(): void
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
                'avgFrameDrift' => 7.567, // Should round to 7.6
                'secondsSinceLastSync' => 1
            ],
            'fppStatus' => ['sequence' => 'test.fseq', 'status' => 'playing']
        ];

        $issues = $this->comparator->comparePlayerToRemote($player, $remote);

        $driftIssues = array_filter($issues, fn($i) => $i['type'] === 'sync_drift');
        $this->assertNotEmpty($driftIssues);
        $this->assertEquals(7.6, reset($driftIssues)['avgDrift']);
    }

    // =========================================================================
    // comparePlayerToRemote Tests - Sync Packets
    // =========================================================================

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
                'secondsSinceLastSync' => 60 // More than 30 seconds
            ],
            'fppStatus' => ['sequence' => 'test.fseq', 'status' => 'playing']
        ];

        $issues = $this->comparator->comparePlayerToRemote($player, $remote);

        $syncIssues = array_filter($issues, fn($i) => $i['type'] === 'no_sync_packets');
        $this->assertNotEmpty($syncIssues, 'Should detect missing sync packets');
        $issue = reset($syncIssues);
        $this->assertEquals(Comparator::SEVERITY_WARNING, $issue['severity']);
        $this->assertEquals(60, $issue['secondsSinceSync']);
    }

    public function testComparePlayerToRemoteNoSyncIssueWhenPlayerIdle(): void
    {
        $player = [
            'metrics' => ['sequencePlaying' => false], // Player is idle
            'fppStatus' => ['sequence' => '', 'status' => 'idle']
        ];

        $remote = [
            'address' => '192.168.1.100',
            'hostname' => 'TestRemote',
            'online' => true,
            'pluginInstalled' => true,
            'metrics' => [
                'sequencePlaying' => false,
                'maxFrameDrift' => 0,
                'avgFrameDrift' => 0,
                'secondsSinceLastSync' => 120 // Old sync is OK when idle
            ],
            'fppStatus' => ['sequence' => '', 'status' => 'idle']
        ];

        $issues = $this->comparator->comparePlayerToRemote($player, $remote);

        $syncIssues = array_filter($issues, fn($i) => $i['type'] === 'no_sync_packets');
        $this->assertEmpty($syncIssues, 'Should not report sync issues when player is idle');
    }

    public function testComparePlayerToRemoteNoSyncIssueWhenRecentSync(): void
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
                'secondsSinceLastSync' => 5 // Recent sync
            ],
            'fppStatus' => ['sequence' => 'test.fseq', 'status' => 'playing']
        ];

        $issues = $this->comparator->comparePlayerToRemote($player, $remote);

        $syncIssues = array_filter($issues, fn($i) => $i['type'] === 'no_sync_packets');
        $this->assertEmpty($syncIssues);
    }

    public function testComparePlayerToRemoteHandlesNegativeSecondsSinceSync(): void
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
                'secondsSinceLastSync' => -1 // Indicates never synced
            ],
            'fppStatus' => ['sequence' => 'test.fseq', 'status' => 'playing']
        ];

        $issues = $this->comparator->comparePlayerToRemote($player, $remote);

        $syncIssues = array_filter($issues, fn($i) => $i['type'] === 'no_sync_packets');
        $this->assertEmpty($syncIssues, 'Should handle negative secondsSinceLastSync');
    }

    // =========================================================================
    // comparePlayerToRemote Tests - Multiple Issues
    // =========================================================================

    public function testComparePlayerToRemoteCanReturnMultipleIssues(): void
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
                'avgFrameDrift' => 12, // Critical drift
                'secondsSinceLastSync' => 60 // No sync packets
            ],
            'fppStatus' => ['sequence' => 'test.fseq', 'status' => 'playing']
        ];

        $issues = $this->comparator->comparePlayerToRemote($player, $remote);

        // Should have both drift and no_sync_packets issues
        $types = array_column($issues, 'type');
        $this->assertContains('sync_drift', $types);
        $this->assertContains('no_sync_packets', $types);
    }

    // =========================================================================
    // comparePlayerToRemote Tests - Edge Cases
    // =========================================================================

    public function testComparePlayerToRemoteHandlesEmptyMetrics(): void
    {
        $player = [
            'metrics' => [],
            'fppStatus' => []
        ];

        $remote = [
            'address' => '192.168.1.100',
            'hostname' => 'TestRemote',
            'online' => true,
            'pluginInstalled' => true,
            'metrics' => [],
            'fppStatus' => []
        ];

        // Should not throw exception
        $issues = $this->comparator->comparePlayerToRemote($player, $remote);
        $this->assertIsArray($issues);
    }

    public function testComparePlayerToRemoteHandlesNullMetrics(): void
    {
        $player = [
            'metrics' => null,
            'fppStatus' => null
        ];

        $remote = [
            'address' => '192.168.1.100',
            'hostname' => 'TestRemote',
            'online' => true,
            'pluginInstalled' => true,
            'metrics' => null,
            'fppStatus' => null
        ];

        // Should not throw exception
        $issues = $this->comparator->comparePlayerToRemote($player, $remote);
        $this->assertIsArray($issues);
    }

    // =========================================================================
    // Response Time Handling Tests (Regression Prevention)
    // =========================================================================

    /**
     * Test that processResults correctly maps response_time from CurlMultiHandler
     */
    public function testResponseTimeIsProperlyMappedFromCurlResults(): void
    {
        $curlResults = [
            '192.168.1.100' => [
                'http_code' => 200,
                'data' => [
                    'success' => true,
                    'watcherLoaded' => true,
                    'watcher' => ['sequencePlaying' => false],
                    'fpp' => ['status' => 'idle']
                ],
                'response_time' => 55.3
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

        $combinedResults = [];
        $hostnames = [
            '192.168.1.100' => 'TestHost1',
            '192.168.1.101' => 'TestHost2'
        ];

        foreach ($curlResults as $address => $result) {
            $combinedResults[$address] = [
                'httpCode' => $result['http_code'],
                'response' => $result['data'] ? json_encode($result['data']) : null,
                'responseTime' => $result['response_time'] ?? 0,
                'hostname' => $hostnames[$address]
            ];
        }

        $this->assertEquals(55.3, $combinedResults['192.168.1.100']['responseTime']);
        $this->assertEquals(72.8, $combinedResults['192.168.1.101']['responseTime']);
    }

    public function testMissingResponseTimeDefaultsToZero(): void
    {
        $curlResult = [
            'http_code' => 200,
            'data' => ['success' => true],
        ];

        $responseTime = $curlResult['response_time'] ?? 0;

        $this->assertEquals(0, $responseTime);
    }

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
    // collectRemoteSyncMetrics Tests
    // =========================================================================

    public function testCollectRemoteSyncMetricsWithEmptyArray(): void
    {
        $result = $this->comparator->collectRemoteSyncMetrics([]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
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

    // =========================================================================
    // Data Provider Tests
    // =========================================================================

    /**
     * @dataProvider driftSeverityProvider
     */
    public function testDriftSeverityLevels(float $avgDrift, int $expectedSeverity): void
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
                'maxFrameDrift' => $avgDrift,
                'avgFrameDrift' => $avgDrift,
                'secondsSinceLastSync' => 1
            ],
            'fppStatus' => ['sequence' => 'test.fseq', 'status' => 'playing']
        ];

        $issues = $this->comparator->comparePlayerToRemote($player, $remote);
        $driftIssues = array_filter($issues, fn($i) => $i['type'] === 'sync_drift');

        if ($expectedSeverity === 0) {
            $this->assertEmpty($driftIssues, "Drift of {$avgDrift} should not create an issue");
        } else {
            $this->assertNotEmpty($driftIssues, "Drift of {$avgDrift} should create an issue");
            $this->assertEquals($expectedSeverity, reset($driftIssues)['severity']);
        }
    }

    public static function driftSeverityProvider(): array
    {
        return [
            'no drift' => [0, 0],
            'low drift (2 frames)' => [2, 0],
            'below warning threshold (4.9 frames)' => [4.9, 0],
            'at warning threshold (5.1 frames)' => [5.1, Comparator::SEVERITY_WARNING],
            'warning level (7 frames)' => [7, Comparator::SEVERITY_WARNING],
            'below critical threshold (9.9 frames)' => [9.9, Comparator::SEVERITY_WARNING],
            'at critical threshold (10.1 frames)' => [10.1, Comparator::SEVERITY_CRITICAL],
            'critical level (15 frames)' => [15, Comparator::SEVERITY_CRITICAL],
            'extreme drift (100 frames)' => [100, Comparator::SEVERITY_CRITICAL],
        ];
    }

    /**
     * @dataProvider syncPacketThresholdProvider
     */
    public function testSyncPacketThresholds(int $secondsSinceSync, bool $shouldWarn): void
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
                'secondsSinceLastSync' => $secondsSinceSync
            ],
            'fppStatus' => ['sequence' => 'test.fseq', 'status' => 'playing']
        ];

        $issues = $this->comparator->comparePlayerToRemote($player, $remote);
        $syncIssues = array_filter($issues, fn($i) => $i['type'] === 'no_sync_packets');

        if ($shouldWarn) {
            $this->assertNotEmpty($syncIssues, "Should warn for {$secondsSinceSync} seconds since sync");
        } else {
            $this->assertEmpty($syncIssues, "Should not warn for {$secondsSinceSync} seconds since sync");
        }
    }

    public static function syncPacketThresholdProvider(): array
    {
        return [
            'very recent (1 second)' => [1, false],
            'recent (10 seconds)' => [10, false],
            'at threshold (30 seconds)' => [30, false],
            'just over threshold (31 seconds)' => [31, true],
            'well over threshold (60 seconds)' => [60, true],
            'very old (300 seconds)' => [300, true],
        ];
    }
}
