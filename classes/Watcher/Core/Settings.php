<?php
declare(strict_types=1);

namespace Watcher\Core;

/**
 * FPP Settings access
 *
 * Provides access to FPP directory paths and plugin settings.
 * Computes paths using the same logic as FPP's config.php for portability.
 *
 * @package Watcher\Core
 * @since 1.0.0
 */
class Settings
{
    private static ?self $instance = null;

    /** @var array<string, mixed> Computed settings */
    private array $settings = [];

    private bool $loaded = false;

    /**
     * Private constructor - use getInstance()
     */
    private function __construct()
    {
        $this->loadSettings();
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
     * Load settings from FPP config
     */
    private function loadSettings(): void
    {
        if ($this->loaded) {
            return;
        }

        // Try to use global $settings if already loaded by FPP
        global $settings;
        if (isset($settings) && !empty($settings['mediaDirectory'])) {
            $this->settings = $settings;
            $this->loaded = true;
            return;
        }

        // Compute mediaDirectory using same logic as /opt/fpp/www/config.php
        $mediaRootFile = '/opt/fpp/www/media_root.txt';
        if (file_exists($mediaRootFile)) {
            $mediaDirectory = trim(file_get_contents($mediaRootFile));
        } elseif (is_dir('/home/fpp')) {
            $mediaDirectory = '/home/fpp/media';
        } else {
            $mediaDirectory = sys_get_temp_dir() . '/fpp';
        }

        // Derive all paths from mediaDirectory (same as config.php)
        $this->settings = [
            'mediaDirectory'  => $mediaDirectory,
            'pluginDirectory' => $mediaDirectory . '/plugins',
            'configDirectory' => $mediaDirectory . '/config',
            'logDirectory'    => $mediaDirectory . '/logs',
        ];

        $this->loaded = true;
    }

    /**
     * Get a setting by key
     *
     * @param string $key Setting key
     * @param mixed $default Default value if key not found
     * @return mixed Setting value or default
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    /**
     * Get media directory path
     */
    public function getMediaDirectory(): string
    {
        return $this->settings['mediaDirectory'];
    }

    /**
     * Get config directory path
     */
    public function getConfigDirectory(): string
    {
        return $this->settings['configDirectory'];
    }

    /**
     * Get plugin directory path
     */
    public function getPluginDirectory(): string
    {
        return $this->settings['pluginDirectory'];
    }

    /**
     * Get log directory path
     */
    public function getLogDirectory(): string
    {
        return $this->settings['logDirectory'];
    }

    /**
     * Get plugin-specific config file path
     *
     * @param string $pluginName Plugin name
     * @return string Path to plugin config file
     */
    public function getPluginConfigPath(string $pluginName): string
    {
        return $this->getConfigDirectory() . '/plugin.' . $pluginName;
    }

    /**
     * Get all settings as array
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->settings;
    }

    /**
     * Write a setting to a plugin config file
     *
     * Uses INI-style format compatible with FPP's parse_ini_file().
     *
     * @param string $settingName Setting key
     * @param string $value Setting value
     * @param string $plugin Plugin name
     * @return bool Success status
     */
    public function writeSettingToFile(string $settingName, string $value, string $plugin): bool
    {
        $configFile = $this->getPluginConfigPath($plugin);

        // Ensure directory exists
        $dir = dirname($configFile);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true)) {
                return false;
            }
        }

        $fd = @fopen($configFile, 'c+');
        if (!$fd) {
            return false;
        }

        flock($fd, LOCK_EX);

        // Read existing settings
        $tmpSettings = [];
        if (file_exists($configFile) && filesize($configFile) > 0) {
            $tmpSettings = @parse_ini_file($configFile) ?: [];
        }

        // Update the setting
        $tmpSettings[$settingName] = $value;

        // Write back all settings
        $content = '';
        foreach ($tmpSettings as $key => $val) {
            // Handle JSON values (don't double-quote objects/arrays)
            if (is_string($val) && (
                (str_starts_with($val, '{') && str_ends_with($val, '}')) ||
                (str_starts_with($val, '[') && str_ends_with($val, ']'))
            )) {
                $content .= "{$key} = {$val}\n";
            } else {
                $content .= "{$key} = \"{$val}\"\n";
            }
        }

        ftruncate($fd, 0);
        rewind($fd);
        fwrite($fd, $content);
        fflush($fd);
        flock($fd, LOCK_UN);
        fclose($fd);

        // Ensure proper ownership
        FileManager::getInstance()->ensureFppOwnership($configFile);

        return true;
    }

    /**
     * Read all settings from a plugin config file
     *
     * @param string $plugin Plugin name
     * @return array<string, string> Settings array
     */
    public function readPluginSettings(string $plugin): array
    {
        $configFile = $this->getPluginConfigPath($plugin);

        if (!file_exists($configFile)) {
            return [];
        }

        return @parse_ini_file($configFile) ?: [];
    }
}
