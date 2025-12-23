<?php
declare(strict_types=1);

namespace Watcher\Core;

/**
 * Centralized logging for the Watcher plugin
 *
 * Provides file-based logging with proper locking and ownership handling.
 * Uses singleton pattern for shared access across the application.
 *
 * @package Watcher\Core
 * @since 1.0.0
 */
class Logger
{
    private static ?self $instance = null;
    private string $logFile;
    private bool $debugEnabled = false;

    /**
     * Private constructor - use getInstance()
     */
    private function __construct()
    {
        // Use existing constant from watcherCommon.php if available
        $this->logFile = defined('WATCHERLOGFILE')
            ? WATCHERLOGFILE
            : '/home/fpp/media/logs/fpp-plugin-watcher.log';
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
     * Set the log file path
     */
    public function setLogFile(string $path): void
    {
        $this->logFile = $path;
    }

    /**
     * Get the current log file path
     */
    public function getLogFile(): string
    {
        return $this->logFile;
    }

    /**
     * Enable or disable debug logging
     */
    public function setDebugEnabled(bool $enabled): void
    {
        $this->debugEnabled = $enabled;
    }

    /**
     * Check if debug logging is enabled
     */
    public function isDebugEnabled(): bool
    {
        return $this->debugEnabled;
    }

    /**
     * Log a message with level
     *
     * @param string $message The message to log
     * @param string $level Log level (INFO, WARNING, ERROR, DEBUG)
     * @param string|null $file Optional override for log file path
     */
    public function log(string $message, string $level = 'INFO', ?string $file = null): void
    {
        // Skip debug messages if debug is not enabled
        if ($level === 'DEBUG' && !$this->debugEnabled) {
            return;
        }

        $logFile = $file ?? $this->logFile;
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] [{$level}] {$message}\n";
        $fileExisted = file_exists($logFile);

        // Serialize writes to avoid interleaving across processes
        $fp = @fopen($logFile, 'a');
        if ($fp) {
            if (flock($fp, LOCK_EX)) {
                fwrite($fp, $logEntry);
                fflush($fp);
                flock($fp, LOCK_UN);
            }
            fclose($fp);
        }

        // Only set ownership when file is newly created (FileManager handles session caching)
        if (!$fileExisted) {
            FileManager::getInstance()->ensureFppOwnership($logFile);
        }
    }

    /**
     * Log an info message
     */
    public function info(string $message, ?string $file = null): void
    {
        $this->log($message, 'INFO', $file);
    }

    /**
     * Log an error message
     */
    public function error(string $message, ?string $file = null): void
    {
        $this->log($message, 'ERROR', $file);
    }

    /**
     * Log a warning message
     */
    public function warning(string $message, ?string $file = null): void
    {
        $this->log($message, 'WARNING', $file);
    }

    /**
     * Log a debug message (only if debug is enabled)
     */
    public function debug(string $message, ?string $file = null): void
    {
        $this->log($message, 'DEBUG', $file);
    }
}
