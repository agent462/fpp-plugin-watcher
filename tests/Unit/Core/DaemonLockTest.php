<?php
/**
 * Unit tests for DaemonLock class
 *
 * @package Watcher\Tests\Unit\Core
 */

declare(strict_types=1);

namespace Watcher\Tests\Unit\Core;

use Watcher\Tests\TestCase;
use Watcher\Core\DaemonLock;

class DaemonLockTest extends TestCase
{
    private const TEST_DAEMON_NAME = 'phpunit-test-daemon';

    protected function setUp(): void
    {
        parent::setUp();
        // Ensure no stale locks from previous test runs
        $this->cleanupTestLock();
    }

    protected function tearDown(): void
    {
        // Always cleanup test locks
        $this->cleanupTestLock();
        parent::tearDown();
    }

    /**
     * Remove any test lock files
     */
    private function cleanupTestLock(): void
    {
        $lockFile = '/tmp/fpp-watcher-' . self::TEST_DAEMON_NAME . '.lock';
        if (file_exists($lockFile)) {
            @unlink($lockFile);
        }
    }

    // =========================================================================
    // Acquire Tests
    // =========================================================================

    public function testAcquireReturnsFileHandle(): void
    {
        $lockFp = DaemonLock::acquire(self::TEST_DAEMON_NAME);

        $this->assertNotFalse($lockFp);
        $this->assertIsResource($lockFp);

        // Cleanup
        DaemonLock::release($lockFp, self::TEST_DAEMON_NAME);
    }

    public function testAcquireCreatesLockFile(): void
    {
        $lockFile = '/tmp/fpp-watcher-' . self::TEST_DAEMON_NAME . '.lock';

        $lockFp = DaemonLock::acquire(self::TEST_DAEMON_NAME);

        $this->assertFileExists($lockFile);

        // Cleanup
        DaemonLock::release($lockFp, self::TEST_DAEMON_NAME);
    }

    public function testAcquireWritesPidToLockFile(): void
    {
        $lockFile = '/tmp/fpp-watcher-' . self::TEST_DAEMON_NAME . '.lock';

        $lockFp = DaemonLock::acquire(self::TEST_DAEMON_NAME);
        $lockContent = file_get_contents($lockFile);

        $this->assertEquals((string)getmypid(), trim($lockContent));

        // Cleanup
        DaemonLock::release($lockFp, self::TEST_DAEMON_NAME);
    }

    public function testAcquireFailsWhenAlreadyLocked(): void
    {
        // Acquire first lock
        $lockFp1 = DaemonLock::acquire(self::TEST_DAEMON_NAME);
        $this->assertNotFalse($lockFp1);

        // Try to acquire second lock - should fail
        $lockFp2 = DaemonLock::acquire(self::TEST_DAEMON_NAME);
        $this->assertFalse($lockFp2);

        // Cleanup
        DaemonLock::release($lockFp1, self::TEST_DAEMON_NAME);
    }

    public function testAcquireWithLogFile(): void
    {
        $logFile = $this->testTmpDir . '/daemon.log';

        $lockFp = DaemonLock::acquire(self::TEST_DAEMON_NAME, $logFile);

        $this->assertNotFalse($lockFp);

        // Cleanup
        DaemonLock::release($lockFp, self::TEST_DAEMON_NAME);
    }

    // =========================================================================
    // Release Tests
    // =========================================================================

    public function testReleaseRemovesLockFile(): void
    {
        $lockFile = '/tmp/fpp-watcher-' . self::TEST_DAEMON_NAME . '.lock';

        $lockFp = DaemonLock::acquire(self::TEST_DAEMON_NAME);
        $this->assertFileExists($lockFile);

        DaemonLock::release($lockFp, self::TEST_DAEMON_NAME);
        $this->assertFileDoesNotExist($lockFile);
    }

    public function testReleaseAllowsReacquisition(): void
    {
        $lockFp1 = DaemonLock::acquire(self::TEST_DAEMON_NAME);
        DaemonLock::release($lockFp1, self::TEST_DAEMON_NAME);

        // Should be able to acquire again
        $lockFp2 = DaemonLock::acquire(self::TEST_DAEMON_NAME);
        $this->assertNotFalse($lockFp2);

        DaemonLock::release($lockFp2, self::TEST_DAEMON_NAME);
    }

    // =========================================================================
    // IsRunning Tests
    // =========================================================================

    public function testIsRunningReturnsTrueWhenLocked(): void
    {
        $lockFp = DaemonLock::acquire(self::TEST_DAEMON_NAME);

        $this->assertTrue(DaemonLock::isRunning(self::TEST_DAEMON_NAME));

        DaemonLock::release($lockFp, self::TEST_DAEMON_NAME);
    }

    public function testIsRunningReturnsFalseWhenNotLocked(): void
    {
        $this->assertFalse(DaemonLock::isRunning(self::TEST_DAEMON_NAME));
    }

    public function testIsRunningReturnsFalseAfterRelease(): void
    {
        $lockFp = DaemonLock::acquire(self::TEST_DAEMON_NAME);
        DaemonLock::release($lockFp, self::TEST_DAEMON_NAME);

        $this->assertFalse(DaemonLock::isRunning(self::TEST_DAEMON_NAME));
    }

    public function testIsRunningReturnsFalseForNonexistentDaemon(): void
    {
        $this->assertFalse(DaemonLock::isRunning('definitely-not-running-daemon'));
    }

    // =========================================================================
    // GetPid Tests
    // =========================================================================

    public function testGetPidReturnsCurrentPid(): void
    {
        $lockFp = DaemonLock::acquire(self::TEST_DAEMON_NAME);

        $pid = DaemonLock::getPid(self::TEST_DAEMON_NAME);
        $this->assertEquals(getmypid(), $pid);

        DaemonLock::release($lockFp, self::TEST_DAEMON_NAME);
    }

    public function testGetPidReturnsNullWhenNotLocked(): void
    {
        $pid = DaemonLock::getPid(self::TEST_DAEMON_NAME);

        $this->assertNull($pid);
    }

    public function testGetPidReturnsNullAfterRelease(): void
    {
        $lockFp = DaemonLock::acquire(self::TEST_DAEMON_NAME);
        DaemonLock::release($lockFp, self::TEST_DAEMON_NAME);

        $pid = DaemonLock::getPid(self::TEST_DAEMON_NAME);
        $this->assertNull($pid);
    }

    public function testGetPidReturnsNullForInvalidContent(): void
    {
        $lockFile = '/tmp/fpp-watcher-' . self::TEST_DAEMON_NAME . '.lock';

        // Create lock file with invalid content
        file_put_contents($lockFile, 'not-a-pid');

        $pid = DaemonLock::getPid(self::TEST_DAEMON_NAME);
        $this->assertNull($pid);
    }

    public function testGetPidReturnsNullForEmptyFile(): void
    {
        $lockFile = '/tmp/fpp-watcher-' . self::TEST_DAEMON_NAME . '.lock';

        // Create empty lock file
        file_put_contents($lockFile, '');

        $pid = DaemonLock::getPid(self::TEST_DAEMON_NAME);
        $this->assertNull($pid);
    }

    // =========================================================================
    // Lock File Path Tests
    // =========================================================================

    public function testLockFileUsesCorrectNamingConvention(): void
    {
        $testNames = ['test', 'my-daemon', 'efuse-collector'];

        foreach ($testNames as $name) {
            $expectedPath = "/tmp/fpp-watcher-{$name}.lock";

            $lockFp = DaemonLock::acquire($name);
            $this->assertFileExists($expectedPath, "Lock file not created at expected path for daemon: $name");

            DaemonLock::release($lockFp, $name);
            $this->assertFileDoesNotExist($expectedPath);
        }
    }

    // =========================================================================
    // Stale Lock Detection Tests
    // =========================================================================

    public function testStaleLocksFromDeadProcessesAreCleared(): void
    {
        $lockFile = '/tmp/fpp-watcher-' . self::TEST_DAEMON_NAME . '.lock';

        // Create a lock file with a PID that definitely doesn't exist
        // Use a very high PID number that's unlikely to be in use
        $fakePid = 999999;
        file_put_contents($lockFile, (string)$fakePid);

        // The acquire should detect the stale lock and clear it
        $lockFp = DaemonLock::acquire(self::TEST_DAEMON_NAME);

        // Should have acquired the lock successfully
        $this->assertNotFalse($lockFp);

        // Lock file should now contain our PID
        $currentPid = file_get_contents($lockFile);
        $this->assertEquals((string)getmypid(), trim($currentPid));

        DaemonLock::release($lockFp, self::TEST_DAEMON_NAME);
    }

    // =========================================================================
    // Multiple Daemon Tests
    // =========================================================================

    public function testMultipleDifferentDaemonsCanRunSimultaneously(): void
    {
        $daemon1 = 'test-daemon-1';
        $daemon2 = 'test-daemon-2';

        $lockFp1 = DaemonLock::acquire($daemon1);
        $lockFp2 = DaemonLock::acquire($daemon2);

        $this->assertNotFalse($lockFp1);
        $this->assertNotFalse($lockFp2);
        $this->assertTrue(DaemonLock::isRunning($daemon1));
        $this->assertTrue(DaemonLock::isRunning($daemon2));

        DaemonLock::release($lockFp1, $daemon1);
        DaemonLock::release($lockFp2, $daemon2);
    }

    // =========================================================================
    // Edge Cases
    // =========================================================================

    public function testAcquireWithSpecialCharactersInName(): void
    {
        $daemonName = 'test_daemon-123';

        $lockFp = DaemonLock::acquire($daemonName);
        $this->assertNotFalse($lockFp);

        $expectedFile = "/tmp/fpp-watcher-{$daemonName}.lock";
        $this->assertFileExists($expectedFile);

        DaemonLock::release($lockFp, $daemonName);
    }

    public function testReleaseWithNullHandleDoesNotCrash(): void
    {
        // This should not throw an exception
        DaemonLock::release(null, self::TEST_DAEMON_NAME);

        // Just verify we got here without error
        $this->assertTrue(true);
    }
}
