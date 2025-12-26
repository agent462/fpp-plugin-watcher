<?php
declare(strict_types=1);

namespace Watcher\Core;

/**
 * Daemon lock management with stale lock detection
 *
 * Provides file-based locking with PID tracking to prevent multiple
 * instances of background daemons from running simultaneously.
 *
 * Features:
 * - Uses flock() for atomic locking
 * - PID tracking for stale lock detection
 * - Automatic cleanup of locks from dead processes
 *
 * @package Watcher\Core
 * @since 1.0.0
 */
class DaemonLock
{
    private const LOCK_DIR = '/tmp';
    private const LOCK_PREFIX = 'fpp-watcher-';

    /**
     * Acquire a daemon lock with stale lock detection
     *
     * Uses flock() for locking with PID tracking for stale detection.
     * If a lock file exists but the process is dead, automatically clears it.
     *
     * @param string $daemonName Short name for the daemon (used in lock filename and logs)
     * @param string|null $logFile Optional log file path for messages (uses main log if null)
     * @return resource|false File handle on success (keep open for daemon lifetime), false on failure
     *
     * Usage:
     *   $lockFp = DaemonLock::acquire('efuse-collector', '/path/to/log');
     *   if (!$lockFp) exit(1);
     *   // ... daemon main loop ...
     *   DaemonLock::release($lockFp, 'efuse-collector');
     */
    public static function acquire(string $daemonName, ?string $logFile = null)
    {
        $lockFile = self::getLockFilePath($daemonName);
        $logger = Logger::getInstance();

        $lockFp = @fopen($lockFile, 'c');
        if (!$lockFp) {
            $logger->info("[{$daemonName}] Failed to open lock file: $lockFile", $logFile);
            return false;
        }

        if (flock($lockFp, LOCK_EX | LOCK_NB)) {
            // Got the lock - write our PID
            ftruncate($lockFp, 0);
            fwrite($lockFp, (string)getmypid());
            fflush($lockFp);
            return $lockFp;
        }

        // Lock failed - check if holding process is still alive
        $stalePid = @file_get_contents($lockFile);
        if ($stalePid && is_numeric(trim($stalePid))) {
            $stalePid = (int)trim($stalePid);

            // posix_kill with signal 0 tests process existence without sending a signal
            if (!posix_kill($stalePid, 0)) {
                // Process doesn't exist - lock is stale
                $logger->info("[{$daemonName}] Detected stale lock from PID $stalePid (process no longer exists). Clearing stale lock...", $logFile);
                @fclose($lockFp);
                @unlink($lockFile);

                // Try again with fresh file
                $lockFp = @fopen($lockFile, 'c');
                if ($lockFp && flock($lockFp, LOCK_EX | LOCK_NB)) {
                    ftruncate($lockFp, 0);
                    fwrite($lockFp, (string)getmypid());
                    fflush($lockFp);
                    $logger->info("[{$daemonName}] Successfully acquired lock after clearing stale lock.", $logFile);
                    return $lockFp;
                }

                $logger->info("[{$daemonName}] Failed to acquire lock even after clearing stale lock. Exiting.", $logFile);
                return false;
            }

            // Process is alive
            $logger->info("[{$daemonName}] Another instance (PID $stalePid) is already running. Exiting.", $logFile);
        } else {
            $logger->info("[{$daemonName}] Another instance is already running. Exiting.", $logFile);
        }

        @fclose($lockFp);
        return false;
    }

    /**
     * Release a daemon lock acquired with acquire()
     *
     * @param resource $lockFp File handle from acquire()
     * @param string $daemonName Daemon name (must match acquire call)
     */
    public static function release($lockFp, string $daemonName): void
    {
        $lockFile = self::getLockFilePath($daemonName);

        if ($lockFp) {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
        }
        @unlink($lockFile);
    }

    /**
     * Check if a daemon is currently running
     *
     * @param string $daemonName Daemon name to check
     * @return bool True if daemon is running, false otherwise
     */
    public static function isRunning(string $daemonName): bool
    {
        $lockFile = self::getLockFilePath($daemonName);

        if (!file_exists($lockFile)) {
            return false;
        }

        $pid = self::getPid($daemonName);
        if ($pid === null) {
            return false;
        }

        // Check if process is actually running
        return posix_kill($pid, 0);
    }

    /**
     * Get the PID of a running daemon
     *
     * @param string $daemonName Daemon name to check
     * @return int|null PID if found and valid, null otherwise
     */
    public static function getPid(string $daemonName): ?int
    {
        $lockFile = self::getLockFilePath($daemonName);

        if (!file_exists($lockFile)) {
            return null;
        }

        $content = @file_get_contents($lockFile);
        if ($content === false || !is_numeric(trim($content))) {
            return null;
        }

        return (int)trim($content);
    }

    /**
     * Get the lock file path for a daemon
     *
     * @param string $daemonName Daemon name
     * @return string Full path to lock file
     */
    private static function getLockFilePath(string $daemonName): string
    {
        return self::LOCK_DIR . '/' . self::LOCK_PREFIX . $daemonName . '.lock';
    }
}
