<?php
declare(strict_types=1);

namespace Watcher\Core;

/**
 * Plugin data directory management
 *
 * Centralizes all data directory operations for the plugin:
 * - Directory creation and ownership
 * - Category-based file organization
 * - Statistics collection
 * - Safe file/category deletion
 *
 * @package Watcher\Core
 * @since 1.0.0
 */
class DataStorage
{
    private static ?self $instance = null;
    private FileManager $fileManager;
    private Logger $logger;

    /** @var array<string, array> Category definitions (populated from constants) */
    private array $categories;

    /**
     * Private constructor - use getInstance()
     */
    private function __construct()
    {
        $this->fileManager = FileManager::getInstance();
        $this->logger = Logger::getInstance();
        $this->initializeCategories();
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
     * Initialize category definitions from constants
     */
    private function initializeCategories(): void
    {
        // Define categories using constants from watcherCommon.php
        // These constants must be defined before DataStorage is used
        $this->categories = [
            'ping' => [
                'name' => 'Ping Metrics',
                'dir' => defined('WATCHERPINGDIR') ? WATCHERPINGDIR : '',
                'description' => 'Connectivity ping history and rollups'
            ],
            'multisync-ping' => [
                'name' => 'Multi-Sync Ping',
                'dir' => defined('WATCHERMULTISYNCPINGDIR') ? WATCHERMULTISYNCPINGDIR : '',
                'description' => 'Multi-sync host ping history and rollups',
                'playerOnly' => true
            ],
            'network-quality' => [
                'name' => 'Network Quality',
                'dir' => defined('WATCHERNETWORKQUALITYDIR') ? WATCHERNETWORKQUALITYDIR : '',
                'description' => 'Network quality metrics (latency, jitter, packet loss)',
                'playerOnly' => true
            ],
            'mqtt' => [
                'name' => 'MQTT Events',
                'dir' => defined('WATCHERMQTTDIR') ? WATCHERMQTTDIR : '',
                'description' => 'MQTT event history',
                'playerOnly' => true
            ],
            'connectivity' => [
                'name' => 'Connectivity State',
                'dir' => defined('WATCHERCONNECTIVITYDIR') ? WATCHERCONNECTIVITYDIR : '',
                'description' => 'Network adapter reset state'
            ],
            'collectd' => [
                'name' => 'Collectd RRD Data',
                'dir' => defined('WATCHERCOLLECTDRRDDIR') ? WATCHERCOLLECTDRRDDIR : '',
                'description' => 'System metrics collected by collectd (CPU, memory, disk, etc.)',
                'showFiles' => false,
                'recursive' => true,
                'warning' => 'Collectd uses fixed-size RRD files. Deleting these files will not free storage space unless collectd is disabled first.'
            ],
            'efuse' => [
                'name' => 'eFuse Metrics',
                'dir' => defined('WATCHEREFUSEDIR') ? WATCHEREFUSEDIR : '',
                'description' => 'eFuse current monitoring history and rollups',
                'playerOnly' => false
            ]
        ];
    }

    /**
     * Ensure all data directories exist with proper ownership
     */
    public function ensureDirectories(): void
    {
        // First ensure base data directory
        if (defined('WATCHERDATADIR')) {
            $this->fileManager->ensureDirectory(WATCHERDATADIR);
        }

        // Then ensure all category directories
        foreach ($this->categories as $category) {
            $dir = $category['dir'];
            if (!empty($dir)) {
                $this->fileManager->ensureDirectory($dir);
            }
        }
    }

    /**
     * Get data category definitions
     *
     * @return array<string, array> Category definitions with keys:
     *   - name: Display name
     *   - dir: Directory path
     *   - description: Category description
     *   - showFiles: Whether to list individual files (default: true)
     *   - recursive: Whether to process directory recursively (default: false)
     *   - warning: Optional warning message
     *   - playerOnly: Whether this category is only for player mode (default: false)
     */
    public function getCategories(): array
    {
        return $this->categories;
    }

    /**
     * Get statistics for all data directories
     *
     * @return array<string, array> Category stats with keys:
     *   - name: Display name
     *   - description: Category description
     *   - files: Array of file info (if showFiles is true)
     *   - totalSize: Total size in bytes
     *   - fileCount: Number of files
     *   - showFiles: Whether individual files are listed
     *   - recursive: Whether this is a recursive directory
     *   - warning: Optional warning message
     *   - playerOnly: Whether this is player-only
     */
    public function getStats(): array
    {
        $stats = [];

        foreach ($this->categories as $key => $category) {
            $dir = $category['dir'];
            $showFiles = $category['showFiles'] ?? true;
            $categoryStats = [
                'name' => $category['name'],
                'description' => $category['description'],
                'files' => [],
                'totalSize' => 0,
                'fileCount' => 0,
                'showFiles' => $showFiles,
                'recursive' => $category['recursive'] ?? false,
                'warning' => $category['warning'] ?? null,
                'playerOnly' => $category['playerOnly'] ?? false
            ];

            if (is_dir($dir)) {
                if ($showFiles) {
                    // Standard behavior: list individual files
                    $files = scandir($dir);
                    foreach ($files as $file) {
                        if ($file === '.' || $file === '..') {
                            continue;
                        }
                        $filePath = $dir . '/' . $file;
                        if (is_file($filePath)) {
                            $size = filesize($filePath);
                            $categoryStats['files'][] = [
                                'name' => $file,
                                'size' => $size,
                                'modified' => filemtime($filePath)
                            ];
                            $categoryStats['totalSize'] += $size;
                            $categoryStats['fileCount']++;
                        }
                    }
                } else {
                    // For directories like collectd: calculate total size recursively
                    $sizeInfo = $this->fileManager->getDirectorySizeRecursive($dir);
                    $categoryStats['totalSize'] = $sizeInfo['size'];
                    $categoryStats['fileCount'] = $sizeInfo['count'];
                }
            }

            $stats[$key] = $categoryStats;
        }

        return $stats;
    }

    /**
     * Clear all data files in a specific category
     *
     * @param string $category Category key (ping, multisync-ping, etc.)
     * @return array ['success' => bool, 'deleted' => int, 'errors' => array]
     */
    public function clearCategory(string $category): array
    {
        if (!isset($this->categories[$category])) {
            return ['success' => false, 'deleted' => 0, 'errors' => ['Invalid category']];
        }

        $dir = $this->categories[$category]['dir'];
        $recursive = $this->categories[$category]['recursive'] ?? false;
        $deleted = 0;
        $errors = [];

        if (!is_dir($dir)) {
            return ['success' => true, 'deleted' => 0, 'errors' => []];
        }

        if ($recursive) {
            // Recursive deletion for directories like collectd
            $result = $this->fileManager->clearDirectoryRecursive($dir);
            $deleted = $result['deleted'];
            $errors = $result['errors'];
        } else {
            // Standard flat directory deletion
            $files = scandir($dir);
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                $filePath = $dir . '/' . $file;
                if (is_file($filePath)) {
                    if (@unlink($filePath)) {
                        $deleted++;
                    } else {
                        $errors[] = "Failed to delete: $file";
                    }
                }
            }
        }

        $this->logger->info("Cleared data category '$category': deleted $deleted files" . (count($errors) > 0 ? ", errors: " . implode(', ', $errors) : ''));

        return [
            'success' => count($errors) === 0,
            'deleted' => $deleted,
            'errors' => $errors
        ];
    }

    /**
     * Delete a specific file within a category
     *
     * @param string $category Category key
     * @param string $filename Filename to delete (basename only, no path)
     * @return array ['success' => bool, 'error' => string|null]
     */
    public function clearFile(string $category, string $filename): array
    {
        if (!isset($this->categories[$category])) {
            return ['success' => false, 'error' => 'Invalid category'];
        }

        // Sanitize filename - only allow basename, no directory traversal
        $filename = basename($filename);
        if (empty($filename) || $filename === '.' || $filename === '..') {
            return ['success' => false, 'error' => 'Invalid filename'];
        }

        $dir = $this->categories[$category]['dir'];
        $filePath = $dir . '/' . $filename;

        if (!file_exists($filePath)) {
            return ['success' => false, 'error' => 'File not found'];
        }

        if (!is_file($filePath)) {
            return ['success' => false, 'error' => 'Not a file'];
        }

        if (@unlink($filePath)) {
            $this->logger->info("Deleted file '$filename' from category '$category'");
            return ['success' => true, 'error' => null];
        }

        return ['success' => false, 'error' => 'Failed to delete file'];
    }

    /**
     * Get the last N lines of a file in a category
     *
     * @param string $category Category key
     * @param string $filename Filename
     * @param int $lines Number of lines to return
     * @return array ['success' => bool, 'content' => string, 'lines' => int, 'error' => string|null, 'filename' => string, 'category' => string]
     */
    public function tailFile(string $category, string $filename, int $lines = 100): array
    {
        if (!isset($this->categories[$category])) {
            return [
                'success' => false,
                'content' => '',
                'lines' => 0,
                'error' => 'Invalid category',
                'filename' => $filename,
                'category' => $category
            ];
        }

        // Sanitize filename
        $filename = basename($filename);
        if (empty($filename) || $filename === '.' || $filename === '..') {
            return [
                'success' => false,
                'content' => '',
                'lines' => 0,
                'error' => 'Invalid filename',
                'filename' => $filename,
                'category' => $category
            ];
        }

        $dir = $this->categories[$category]['dir'];
        $filePath = $dir . '/' . $filename;

        // Delegate to FileManager for the actual file reading
        $result = $this->fileManager->tailFile($filePath, $lines);

        // Add category/filename metadata to the result
        $result['filename'] = $filename;
        $result['category'] = $category;

        return $result;
    }

    /**
     * Get a specific category's directory path
     *
     * @param string $category Category key
     * @return string|null Directory path or null if category not found
     */
    public function getCategoryDirectory(string $category): ?string
    {
        return $this->categories[$category]['dir'] ?? null;
    }

    /**
     * Check if a category exists
     *
     * @param string $category Category key
     * @return bool True if category exists
     */
    public function hasCategory(string $category): bool
    {
        return isset($this->categories[$category]);
    }
}
