<?php
declare(strict_types=1);

namespace Watcher\Metrics;

use Watcher\Core\FileManager;
use Watcher\Core\Logger;

/**
 * Handles raw metrics file storage and rotation
 *
 * Migrated from lib/metrics/rollupBase.php
 */
class MetricsStorage
{
    private FileManager $fileManager;
    private Logger $logger;

    /** @var array<string, bool> Track ownership verification per file */
    private static array $ownershipVerified = [];

    public function __construct(?FileManager $fileManager = null, ?Logger $logger = null)
    {
        $this->fileManager = $fileManager ?? FileManager::getInstance();
        $this->logger = $logger ?? Logger::getInstance();
    }

    /**
     * Write batch of metric entries to file
     *
     * @param string $metricsFile Path to metrics file
     * @param array $entries Array of metric entries to write
     * @return bool Success
     */
    public function writeBatch(string $metricsFile, array $entries): bool
    {
        if (empty($entries)) {
            return true;
        }

        $fp = @fopen($metricsFile, 'a');
        if (!$fp) {
            return false;
        }

        $success = false;
        if (flock($fp, LOCK_EX)) {
            foreach ($entries as $entry) {
                $timestamp = date('Y-m-d H:i:s', $entry['timestamp'] ?? time());
                $jsonData = json_encode($entry);
                fwrite($fp, "[{$timestamp}] {$jsonData}\n");
            }
            fflush($fp);
            flock($fp, LOCK_UN);
            $success = true;
        }
        fclose($fp);

        // Check ownership once per batch
        if (!isset(self::$ownershipVerified[$metricsFile])) {
            $this->fileManager->ensureFppOwnership($metricsFile);
            self::$ownershipVerified[$metricsFile] = true;
        }

        return $success;
    }

    /**
     * Read metrics from file, optionally filtered by timestamp
     *
     * @param string $metricsFile Path to metrics file
     * @param int $sinceTimestamp Only return entries newer than this timestamp
     * @return array Array of metric entries
     */
    public function read(string $metricsFile, int $sinceTimestamp = 0): array
    {
        if (!file_exists($metricsFile)) {
            return [];
        }

        $entries = [];
        $fp = @fopen($metricsFile, 'r');

        if (!$fp) {
            return [];
        }

        if (!flock($fp, LOCK_SH)) {
            fclose($fp);
            return [];
        }

        while (($line = fgets($fp)) !== false) {
            // When filtering by timestamp, extract it via regex first to skip old entries
            // without the overhead of json_decode
            if ($sinceTimestamp > 0) {
                if (!preg_match('/"timestamp"\s*:\s*(\d+)/', $line, $tsMatch)) {
                    continue;
                }
                $entryTimestamp = (int)$tsMatch[1];
                if ($entryTimestamp <= $sinceTimestamp) {
                    continue;
                }
            }

            // Format: [datetime] {json}
            if (preg_match('/\[.*?\]\s+(.+)$/', $line, $matches)) {
                $jsonData = trim($matches[1]);
                $entry = json_decode($jsonData, true);

                if ($entry && isset($entry['timestamp'])) {
                    $entries[] = $entry;
                }
            }
        }

        flock($fp, LOCK_UN);
        fclose($fp);

        // Sort by timestamp
        usort($entries, fn($a, $b) => ($a['timestamp'] ?? 0) <=> ($b['timestamp'] ?? 0));

        return $entries;
    }

    /**
     * Rotate raw metrics file to remove entries older than retention period
     * Uses atomic file operations for safety
     *
     * @param string $metricsFile Path to metrics file
     * @param int $retentionSeconds Retention period in seconds
     * @param string $backupSuffix Suffix for backup file (default '.old')
     * @return array Result with 'purged' count and 'kept' count
     */
    public function rotate(string $metricsFile, int $retentionSeconds, string $backupSuffix = '.old'): array
    {
        $result = ['purged' => 0, 'kept' => 0];

        if (!file_exists($metricsFile)) {
            return $result;
        }

        $fp = fopen($metricsFile, 'c+');
        if (!$fp) {
            return $result;
        }

        // Take an exclusive lock so writes pause during rotation
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return $result;
        }

        // Read current metrics and keep only entries within retention period
        $cutoffTime = time() - $retentionSeconds;
        $recentMetrics = [];
        $purgedCount = 0;

        rewind($fp);
        while (($line = fgets($fp)) !== false) {
            // Extract timestamp directly with regex - faster than JSON decode
            // Format: [datetime] {"timestamp":1234567890,...}
            if (preg_match('/"timestamp"\s*:\s*(\d+)/', $line, $matches)) {
                $entryTimestamp = (int)$matches[1];
                if ($entryTimestamp >= $cutoffTime) {
                    $recentMetrics[] = $line;
                } else {
                    $purgedCount++;
                }
            }
        }

        // Only rewrite file if we actually purged entries
        if ($purgedCount === 0) {
            flock($fp, LOCK_UN);
            fclose($fp);
            $result['kept'] = count($recentMetrics);
            return $result;
        }

        // Write recent metrics to new file, rename old file to backup atomically
        $backupFile = $metricsFile . $backupSuffix;
        $tempFile = $metricsFile . '.tmp';

        // Write recent entries to temp file
        $tempFp = fopen($tempFile, 'w');
        if ($tempFp) {
            if (!empty($recentMetrics)) {
                fwrite($tempFp, implode('', $recentMetrics));
            }
            fclose($tempFp);

            // Atomic swap: old -> backup, temp -> current
            @unlink($backupFile);
            rename($metricsFile, $backupFile);
            rename($tempFile, $metricsFile);

            $this->logger->info("Metrics purge ({$metricsFile}): removed {$purgedCount} old entries, kept " . count($recentMetrics) . " recent entries.");

            $this->fileManager->ensureFppOwnership($metricsFile);
            $this->fileManager->ensureFppOwnership($backupFile);

            $result['purged'] = $purgedCount;
            $result['kept'] = count($recentMetrics);
        }

        flock($fp, LOCK_UN);
        fclose($fp);

        return $result;
    }
}
