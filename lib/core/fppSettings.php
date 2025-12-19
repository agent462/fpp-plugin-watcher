<?php
/**
 * FPP Settings Shim
 *
 * Provides FPP $settings array and WriteSettingToFile() without requiring
 * /opt/fpp/www/common.php. This makes the plugin more portable and testable.
 *
 * IMPORTANT: Directory paths are COMPUTED, not read from /home/fpp/media/settings.
 * This replicates the logic from /opt/fpp/www/config.php lines 11-27 and 542-563.
 */

// Only define if not already defined (allows FPP's common.php to take precedence)
if (!isset($settings) || empty($settings['mediaDirectory'])) {
    $settings = $settings ?? [];

    // Compute mediaDirectory using same logic as /opt/fpp/www/config.php
    $mediaRootFile = '/opt/fpp/www/media_root.txt';
    if (file_exists($mediaRootFile)) {
        $mediaDirectory = trim(file_get_contents($mediaRootFile));
    } else if (is_dir('/home/fpp')) {
        $mediaDirectory = '/home/fpp/media';
    } else {
        $mediaDirectory = sys_get_temp_dir() . '/fpp';
    }

    // Derive all paths from mediaDirectory (same as config.php lines 144-155)
    $settings['mediaDirectory']  = $mediaDirectory;
    $settings['pluginDirectory'] = $mediaDirectory . '/plugins';
    $settings['configDirectory'] = $mediaDirectory . '/config';
    $settings['logDirectory']    = $mediaDirectory . '/logs';
}

/**
 * Write a setting to an INI-style config file
 * Drop-in replacement for FPP's WriteSettingToFile()
 *
 * @param string $settingName Setting key
 * @param mixed $value Setting value
 * @param string $plugin Plugin name (uses plugin config file) or empty for main settings
 */
if (!function_exists('WriteSettingToFile')) {
    function WriteSettingToFile($settingName, $value, $plugin = "") {
        global $settings;

        $filename = $plugin
            ? ($settings['configDirectory'] ?? '/home/fpp/media/config') . "/plugin." . $plugin
            : '/home/fpp/media/settings';

        // Ensure directory exists
        $dir = dirname($filename);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $fd = @fopen($filename, "c+");
        if (!$fd) {
            return false;
        }

        flock($fd, LOCK_EX);

        // Read existing settings
        $tmpSettings = [];
        if (file_exists($filename) && filesize($filename) > 0) {
            $tmpSettings = @parse_ini_file($filename) ?: [];
        }

        // Update the setting
        $tmpSettings[$settingName] = $value;

        // Write back all settings
        $content = "";
        foreach ($tmpSettings as $key => $val) {
            // Handle JSON values (don't double-quote objects/arrays)
            if (is_string($val) && (
                (str_starts_with($val, '{') && str_ends_with($val, '}')) ||
                (str_starts_with($val, '[') && str_ends_with($val, ']'))
            )) {
                $content .= $key . " = " . $val . "\n";
            } else {
                $content .= $key . ' = "' . $val . "\"\n";
            }
        }

        ftruncate($fd, 0);
        rewind($fd);
        fwrite($fd, $content);
        fflush($fd);
        flock($fd, LOCK_UN);
        fclose($fd);

        return true;
    }
}
