<?php
declare(strict_types=1);

namespace Watcher\Core;

/**
 * Centralized file operations with proper locking
 *
 * Handles JSON/JSON-lines file operations, ownership management,
 * and directory operations with proper file locking for concurrent access.
 *
 * @package Watcher\Core
 * @since 1.0.0
 */
class FileManager
{
    private static ?self $instance = null;
    private Logger $logger;

    /** @var array<string, bool> Track files/dirs whose ownership has been verified this session */
    private array $ownershipVerified = [];

    /**
     * Private constructor - use getInstance()
     */
    private function __construct()
    {
        $this->logger = Logger::getInstance();
    }

    /**
     * Get singleton instance
     */
    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Reset singleton instance (useful for testing)
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    /**
     * Parse a JSON line that may be in new format (pure JSON) or legacy format ([datetime] {json})
     *
     * @param string $line The line to parse
     * @return array|null Parsed JSON array or null if parsing failed
     */
    public static function parseJsonLine(string $line): ?array
    {
        $line = trim($line);
        if (empty($line)) {
            return null;
        }

        // Fast path: line starts with JSON
        if ($line[0] === '{') {
            $entry = json_decode($line, true);
            return is_array($entry) ? $entry : null;
        }

        // Legacy format with datetime prefix - extract JSON portion
        if (preg_match('/\{.*\}/', $line, $matches)) {
            $entry = json_decode($matches[0], true);
            return is_array($entry) ? $entry : null;
        }

        return null;
    }

    /**
     * Read JSON lines file with optional timestamp filtering
     *
     * Optimized for large files: uses regex pre-filtering to skip old entries
     * without expensive JSON parsing.
     *
     * @param string $path Path to the file
     * @param int $sinceTimestamp Only return entries newer than this (default: 0)
     * @param callable|null $filterFn Optional filter function(entry) => bool
     * @param bool $sort Whether to sort results by timestamp (default: true)
     * @param string $timestampField Field name containing timestamp (default: 'timestamp')
     * @return array Array of parsed entries
     */
    public function readJsonLinesFile(
        string $path,
        int $sinceTimestamp = 0,
        ?callable $filterFn = null,
        bool $sort = true,
        string $timestampField = 'timestamp'
    ): array {
        if (!file_exists($path)) {
            return [];
        }

        $entries = [];
        $fp = @fopen($path, 'r');

        if (!$fp) {
            return [];
        }

        // Build regex pattern for timestamp extraction (avoids json_decode on old entries)
        $tsPattern = '/"' . preg_quote($timestampField, '/') . '"\s*:\s*(\d+)/';

        if (!flock($fp, LOCK_SH)) {
            fclose($fp);
            return [];
        }

        while (($line = fgets($fp)) !== false) {
            // When filtering by timestamp, extract it via regex first to skip old entries
            // without the overhead of json_decode
            if ($sinceTimestamp > 0) {
                if (!preg_match($tsPattern, $line, $tsMatch)) {
                    continue;
                }
                $entryTimestamp = (int)$tsMatch[1];
                if ($entryTimestamp <= $sinceTimestamp) {
                    continue;
                }
            }

            // Parse JSON - handle both new format (pure JSON) and legacy format ([datetime] {json})
            $entry = self::parseJsonLine($line);

            if ($entry && isset($entry[$timestampField])) {
                // Apply custom filter if provided
                if ($filterFn !== null && !$filterFn($entry)) {
                    continue;
                }
                $entries[] = $entry;
            }
        }

        flock($fp, LOCK_UN);
        fclose($fp);

        if ($sort && !empty($entries)) {
            usort($entries, fn($a, $b) => ($a[$timestampField] ?? 0) <=> ($b[$timestampField] ?? 0));
        }

        return $entries;
    }

    /**
     * Write entries to a JSON lines file
     *
     * Each entry is written as: [datetime] {json}
     *
     * @param string $path Path to the file
     * @param array $entries Array of entries to write
     * @param bool $append Whether to append (true) or overwrite (false)
     * @return bool Success status
     */
    public function writeJsonLinesFile(string $path, array $entries, bool $append = true): bool
    {
        if (empty($entries)) {
            return true;
        }

        $mode = $append ? 'a' : 'w';
        $fp = @fopen($path, $mode);

        if (!$fp) {
            $this->logger->error("Failed to open file for writing: {$path}");
            return false;
        }

        $success = false;
        if (flock($fp, LOCK_EX)) {
            foreach ($entries as $entry) {
                $jsonData = json_encode($entry);
                fwrite($fp, "{$jsonData}\n");
            }
            fflush($fp);
            flock($fp, LOCK_UN);
            $success = true;
        }

        fclose($fp);
        $this->ensureFppOwnership($path);

        return $success;
    }

    /**
     * Read JSON file with shared lock
     *
     * @param string $path Path to the file
     * @return array|null Decoded JSON or null on failure
     */
    public function readJsonFile(string $path): ?array
    {
        if (!file_exists($path)) {
            return null;
        }

        $fp = @fopen($path, 'r');
        if (!$fp) {
            return null;
        }

        if (!flock($fp, LOCK_SH)) {
            fclose($fp);
            return null;
        }

        $content = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if ($content === false || $content === '') {
            return null;
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Write JSON file with exclusive lock
     *
     * @param string $path Path to the file
     * @param array $data Data to write
     * @param int $flags JSON encoding flags (default: JSON_PRETTY_PRINT)
     * @return bool Success status
     */
    public function writeJsonFile(string $path, array $data, int $flags = JSON_PRETTY_PRINT): bool
    {
        $fp = @fopen($path, 'c');
        if (!$fp) {
            return false;
        }

        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return false;
        }

        ftruncate($fp, 0);
        fwrite($fp, json_encode($data, $flags));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        $this->ensureFppOwnership($path);
        return true;
    }

    /**
     * Ensure file/directory is owned by fpp:fpp
     *
     * Only checks ownership once per path per session for efficiency.
     *
     * @param string $path Path to file or directory
     * @param bool $force Force ownership check even if already verified
     * @return bool Success status
     */
    public function ensureFppOwnership(string $path, bool $force = false): bool
    {
        if (!$path || !file_exists($path)) {
            return false;
        }

        // Skip if already verified this session (unless forced)
        if (!$force && isset($this->ownershipVerified[$path])) {
            return true;
        }

        // Use constants if available (from watcherCommon.php)
        $fppUser = defined('WATCHERFPPUSER') ? WATCHERFPPUSER : 'fpp';
        $fppGroup = defined('WATCHERFPPGROUP') ? WATCHERFPPGROUP : 'fpp';

        @chown($path, $fppUser);
        @chgrp($path, $fppGroup);

        $this->ownershipVerified[$path] = true;
        return true;
    }

    /**
     * Get directory size recursively
     *
     * @param string $directory Directory path
     * @return array ['size' => int, 'count' => int]
     */
    public function getDirectorySizeRecursive(string $directory): array
    {
        $size = 0;
        $count = 0;

        if (!is_dir($directory)) {
            return ['size' => 0, 'count' => 0];
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                    $count++;
                }
            }
        } catch (\Exception $e) {
            $this->logger->error("Failed to calculate directory size: {$directory} - {$e->getMessage()}");
        }

        return ['size' => $size, 'count' => $count];
    }

    /**
     * Clear directory contents recursively (keeps directory structure)
     *
     * @param string $directory Directory path
     * @return array ['deleted' => int, 'errors' => array]
     */
    public function clearDirectoryRecursive(string $directory): array
    {
        $deleted = 0;
        $errors = [];

        if (!is_dir($directory)) {
            return ['deleted' => 0, 'errors' => []];
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $filePath = $file->getPathname();
                    if (@unlink($filePath)) {
                        $deleted++;
                    } else {
                        $errors[] = "Failed to delete: " . $file->getFilename();
                    }
                }
            }
        } catch (\Exception $e) {
            $errors[] = "Iterator error: {$e->getMessage()}";
        }

        return ['deleted' => $deleted, 'errors' => $errors];
    }

    /**
     * Ensure a directory exists with proper ownership
     *
     * @param string $directory Directory path
     * @param int $permissions Directory permissions (default: 0755)
     * @return bool Success status
     */
    public function ensureDirectory(string $directory, int $permissions = 0755): bool
    {
        if (is_dir($directory)) {
            return true;
        }

        if (!@mkdir($directory, $permissions, true)) {
            $this->logger->error("Failed to create directory: {$directory}");
            return false;
        }

        $this->ensureFppOwnership($directory);
        return true;
    }

    /**
     * Read gzip-compressed JSON lines file
     *
     * Used for compressed rollup tier files (30min, 2hour).
     * Supports the same JSON-first parsing as regular JSON lines.
     *
     * @param string $path Path to the gzip file
     * @param int $sinceTimestamp Only return entries newer than this (default: 0)
     * @param callable|null $filterFn Optional filter function(entry) => bool
     * @param bool $sort Whether to sort results by timestamp (default: true)
     * @param string $timestampField Field name containing timestamp (default: 'timestamp')
     * @return array Array of parsed entries
     */
    public function readGzipJsonLines(
        string $path,
        int $sinceTimestamp = 0,
        ?callable $filterFn = null,
        bool $sort = true,
        string $timestampField = 'timestamp'
    ): array {
        if (!file_exists($path)) {
            return [];
        }

        $fp = @gzopen($path, 'r');
        if (!$fp) {
            return [];
        }

        $entries = [];

        while (($line = gzgets($fp)) !== false) {
            $entry = self::parseJsonLine($line);

            if ($entry && isset($entry[$timestampField])) {
                // Filter by timestamp if specified
                if ($sinceTimestamp > 0 && $entry[$timestampField] <= $sinceTimestamp) {
                    continue;
                }

                // Apply custom filter if provided
                if ($filterFn !== null && !$filterFn($entry)) {
                    continue;
                }

                $entries[] = $entry;
            }
        }

        gzclose($fp);

        if ($sort && !empty($entries)) {
            usort($entries, fn($a, $b) => ($a[$timestampField] ?? 0) <=> ($b[$timestampField] ?? 0));
        }

        return $entries;
    }

    /**
     * Append entries to a gzip-compressed JSON lines file
     *
     * Used for compressed rollup tier files (30min, 2hour).
     * Since gzip doesn't support true append, this reads existing entries,
     * merges them with new entries, and rewrites the file.
     *
     * @param string $path Path to the gzip file
     * @param array $entries Array of entries to append
     * @param int $compressionLevel Gzip compression level 1-9 (default: 6)
     * @return bool Success status
     */
    public function appendGzipJsonLines(string $path, array $entries, int $compressionLevel = 6): bool
    {
        if (empty($entries)) {
            return true;
        }

        // Read existing entries if file exists
        $existingEntries = file_exists($path) ? $this->readGzipJsonLines($path) : [];

        // Merge with new entries
        $allEntries = array_merge($existingEntries, $entries);

        // Write all entries to gzip file
        $fp = @gzopen($path, "w{$compressionLevel}");
        if (!$fp) {
            $this->logger->error("Failed to open gzip file for writing: {$path}");
            return false;
        }

        foreach ($allEntries as $entry) {
            gzwrite($fp, json_encode($entry) . "\n");
        }

        gzclose($fp);
        $this->ensureFppOwnership($path);

        return true;
    }

    /**
     * Gzip compress a file in place
     *
     * Reads the source file, writes to a .gz file, and optionally removes the original.
     * Used for compressing backup/archive files that are rarely accessed.
     *
     * @param string $sourcePath Path to the source file
     * @param bool $removeOriginal Whether to remove the original file after compression (default: true)
     * @param int $compressionLevel Gzip compression level 1-9 (default: 6)
     * @return bool Success status
     */
    public function gzipFile(string $sourcePath, bool $removeOriginal = true, int $compressionLevel = 6): bool
    {
        if (!file_exists($sourcePath)) {
            return false;
        }

        $gzPath = $sourcePath . '.gz';

        // Read source file
        $content = @file_get_contents($sourcePath);
        if ($content === false) {
            $this->logger->error("Failed to read file for gzip compression: {$sourcePath}");
            return false;
        }

        // Write compressed file
        $fp = @gzopen($gzPath, "w{$compressionLevel}");
        if (!$fp) {
            $this->logger->error("Failed to create gzip file: {$gzPath}");
            return false;
        }

        gzwrite($fp, $content);
        gzclose($fp);

        $this->ensureFppOwnership($gzPath);

        // Remove original if requested
        if ($removeOriginal) {
            @unlink($sourcePath);
        }

        return true;
    }

    /**
     * Read the last N lines of a file efficiently
     *
     * Uses system tail command for performance on large files.
     *
     * @param string $path Path to the file
     * @param int $lines Number of lines to return
     * @return array ['success' => bool, 'content' => string, 'lines' => int, 'error' => string|null]
     */
    public function tailFile(string $path, int $lines = 100): array
    {
        if (!file_exists($path)) {
            return ['success' => false, 'content' => '', 'lines' => 0, 'error' => 'File not found'];
        }

        if (!is_file($path) || !is_readable($path)) {
            return ['success' => false, 'content' => '', 'lines' => 0, 'error' => 'Cannot read file'];
        }

        // Use tail command for efficiency on large files
        $output = [];
        $returnVar = 0;
        exec('tail -n ' . intval($lines) . ' ' . escapeshellarg($path) . ' 2>&1', $output, $returnVar);

        if ($returnVar !== 0) {
            // Fallback to PHP if tail fails
            $content = @file_get_contents($path);
            if ($content === false) {
                return ['success' => false, 'content' => '', 'lines' => 0, 'error' => 'Failed to read file'];
            }
            $allLines = explode("\n", $content);
            $output = array_slice($allLines, -$lines);
        }

        return [
            'success' => true,
            'content' => implode("\n", $output),
            'lines' => count($output),
            'error' => null
        ];
    }
}
