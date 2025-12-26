<?php
/**
 * Test Constants and Mock FPP Settings
 *
 * Provides mock values for FPP settings during testing.
 */

declare(strict_types=1);

// Mock FPP user/group constants if not already defined
if (!defined('WATCHERFPPUSER')) {
    define('WATCHERFPPUSER', 'fpp');
}
if (!defined('WATCHERFPPGROUP')) {
    define('WATCHERFPPGROUP', 'fpp');
}

// Mock plugin constants if not already defined
if (!defined('WATCHERPLUGINNAME')) {
    define('WATCHERPLUGINNAME', 'fpp-plugin-watcher');
}
if (!defined('WATCHERVERSION')) {
    define('WATCHERVERSION', '1.0.0-test');
}
if (!defined('WATCHERPLUGINDIR')) {
    define('WATCHERPLUGINDIR', WATCHER_PLUGIN_DIR);
}

// Mock directory paths for testing
if (!defined('WATCHERDATADIR')) {
    define('WATCHERDATADIR', WATCHER_TEST_TMP_DIR . '/data');
}
if (!defined('WATCHERPINGDIR')) {
    define('WATCHERPINGDIR', WATCHER_TEST_TMP_DIR . '/data/ping');
}
if (!defined('WATCHERMULTISYNCPINGDIR')) {
    define('WATCHERMULTISYNCPINGDIR', WATCHER_TEST_TMP_DIR . '/data/multisync-ping');
}
if (!defined('WATCHERNETWORKQUALITYDIR')) {
    define('WATCHERNETWORKQUALITYDIR', WATCHER_TEST_TMP_DIR . '/data/network-quality');
}
if (!defined('WATCHERMQTTDIR')) {
    define('WATCHERMQTTDIR', WATCHER_TEST_TMP_DIR . '/data/mqtt');
}
if (!defined('WATCHERCONNECTIVITYDIR')) {
    define('WATCHERCONNECTIVITYDIR', WATCHER_TEST_TMP_DIR . '/data/connectivity');
}
if (!defined('WATCHEREFUSEDIR')) {
    define('WATCHEREFUSEDIR', WATCHER_TEST_TMP_DIR . '/data/efuse');
}

// eFuse file paths
if (!defined('WATCHEREFUSERAWFILE')) {
    define('WATCHEREFUSERAWFILE', WATCHER_TEST_TMP_DIR . '/data/efuse/raw.log');
}
if (!defined('WATCHEREFUSEROLLUPSTATEFILE')) {
    define('WATCHEREFUSEROLLUPSTATEFILE', WATCHER_TEST_TMP_DIR . '/data/efuse/rollup-state.json');
}

// Mock file paths
if (!defined('WATCHERLOGFILE')) {
    define('WATCHERLOGFILE', WATCHER_TEST_TMP_DIR . '/logs/watcher.log');
}
if (!defined('WATCHERPINGMETRICSFILE')) {
    define('WATCHERPINGMETRICSFILE', WATCHER_TEST_TMP_DIR . '/data/ping/metrics.log');
}
if (!defined('WATCHERMULTISYNCPINGMETRICSFILE')) {
    define('WATCHERMULTISYNCPINGMETRICSFILE', WATCHER_TEST_TMP_DIR . '/data/multisync-ping/metrics.log');
}
if (!defined('WATCHERCONFIGFILELOCATION')) {
    define('WATCHERCONFIGFILELOCATION', WATCHER_TEST_TMP_DIR . '/config/plugin.fpp-plugin-watcher');
}
if (!defined('WATCHERCOLLECTDRRDDIR')) {
    define('WATCHERCOLLECTDRRDDIR', WATCHER_TEST_TMP_DIR . '/data/collectd/rrd');
}

// Mock FPP settings array
$GLOBALS['testSettings'] = [
    'HostName' => 'fpptest',
    'HostDescription' => 'Test FPP Instance',
    'Platform' => 'Raspberry Pi',
    'Variant' => 'Pi 4',
    'mediaDirectory' => WATCHER_TEST_TMP_DIR,
    'configDirectory' => WATCHER_TEST_TMP_DIR . '/config',
    'pluginDirectory' => WATCHER_TEST_TMP_DIR . '/plugins',
    'logDirectory' => WATCHER_TEST_TMP_DIR . '/logs',
    'fppMode' => 'player',
    'MultiSyncEnabled' => '1',
];

// Helper function to get test settings
function getTestSettings(): array
{
    return $GLOBALS['testSettings'];
}

// Create necessary test directories
$testDirs = [
    WATCHER_TEST_TMP_DIR . '/data',
    WATCHER_TEST_TMP_DIR . '/data/ping',
    WATCHER_TEST_TMP_DIR . '/data/ping/rollups',
    WATCHER_TEST_TMP_DIR . '/data/multisync-ping',
    WATCHER_TEST_TMP_DIR . '/data/multisync-ping/rollups',
    WATCHER_TEST_TMP_DIR . '/data/network-quality',
    WATCHER_TEST_TMP_DIR . '/data/mqtt',
    WATCHER_TEST_TMP_DIR . '/data/connectivity',
    WATCHER_TEST_TMP_DIR . '/data/efuse',
    WATCHER_TEST_TMP_DIR . '/data/efuse/rollups',
    WATCHER_TEST_TMP_DIR . '/data/collectd',
    WATCHER_TEST_TMP_DIR . '/data/collectd/rrd',
    WATCHER_TEST_TMP_DIR . '/data/collectd/rrd/fpplocal',
    WATCHER_TEST_TMP_DIR . '/config',
    WATCHER_TEST_TMP_DIR . '/logs',
    WATCHER_TEST_TMP_DIR . '/plugins',
];

foreach ($testDirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// Mock readPluginConfig function for testing
// Use $GLOBALS['testPluginConfig'] to override config values in tests
if (!function_exists('readPluginConfig')) {
    /**
     * Mock readPluginConfig() for testing
     * Returns test configuration from $GLOBALS['testPluginConfig'] if set,
     * otherwise returns default test values
     */
    function readPluginConfig($forceReload = false): array
    {
        // Return test config override if set
        if (isset($GLOBALS['testPluginConfig'])) {
            return $GLOBALS['testPluginConfig'];
        }

        // Default test configuration
        return [
            'connectivityCheckEnabled' => false,
            'checkInterval' => 20,
            'maxFailures' => 3,
            'networkAdapter' => 'default',
            'testHosts' => ['8.8.8.8', '1.1.1.1'],
            'metricsRotationInterval' => 1800,
            'collectdEnabled' => true,
            'multiSyncMetricsEnabled' => false,
            'multiSyncPingEnabled' => false,
            'multiSyncPingInterval' => 60,
            'falconMonitorEnabled' => false,
            'controlUIEnabled' => true,
            'mqttMonitorEnabled' => false,
            'mqttRetentionDays' => 60,
            'issueCheckOutputs' => true,
            'issueCheckSequences' => true,
            'efuseMonitorEnabled' => false,
            'efuseCollectionInterval' => 5,
            'efuseRetentionDays' => 7,
        ];
    }
}

// Mock ensureDataDirectories function for testing
if (!function_exists('ensureDataDirectories')) {
    /**
     * Mock ensureDataDirectories() for testing
     * Creates test data directories if they don't exist
     */
    function ensureDataDirectories(): void
    {
        $dirs = [
            WATCHER_TEST_TMP_DIR . '/data',
            WATCHER_TEST_TMP_DIR . '/data/ping',
            WATCHER_TEST_TMP_DIR . '/data/multisync-ping',
            WATCHER_TEST_TMP_DIR . '/data/efuse',
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }
    }
}

// Define helper functions needed for testing (simplified versions from watcherCommon.php)
if (!function_exists('sortByTimestamp')) {
    /**
     * Sort an array by timestamp field (ascending order)
     *
     * @param array $array Array to sort (modified in-place)
     * @param string $field Field name containing timestamp (default: 'timestamp')
     */
    function sortByTimestamp(&$array, $field = 'timestamp') {
        usort($array, function($a, $b) use ($field) {
            return ($a[$field] ?? 0) - ($b[$field] ?? 0);
        });
    }
}

if (!function_exists('readJsonLinesFile')) {
    /**
     * Read a JSON-lines log file with optional filtering
     *
     * @param string $file Path to the file
     * @param int $sinceTimestamp Only return entries newer than this (default: 0)
     * @param callable|null $filterFn Optional filter function(entry) => bool
     * @param bool $sort Whether to sort results by timestamp (default: true)
     * @param string $timestampField Field name containing timestamp (default: 'timestamp')
     * @return array Array of parsed entries
     */
    function readJsonLinesFile($file, $sinceTimestamp = 0, $filterFn = null, $sort = true, $timestampField = 'timestamp') {
        if (!file_exists($file)) {
            return [];
        }

        $entries = [];
        $fp = fopen($file, 'r');

        if (!$fp) {
            return [];
        }

        // Build regex pattern for timestamp extraction (avoids json_decode on old entries)
        $tsPattern = '/"' . preg_quote($timestampField, '/') . '"\s*:\s*(\d+)/';

        if (flock($fp, LOCK_SH)) {
            while (($line = fgets($fp)) !== false) {
                // When filtering by timestamp, extract it via regex first to skip old entries
                if ($sinceTimestamp > 0) {
                    if (!preg_match($tsPattern, $line, $tsMatch)) {
                        continue;
                    }
                    $entryTimestamp = (int)$tsMatch[1];
                    if ($entryTimestamp <= $sinceTimestamp) {
                        continue;
                    }
                }

                // Parse log format: [datetime] {json}
                if (preg_match('/\[.*?\]\s+(.+)$/', $line, $matches)) {
                    $jsonData = trim($matches[1]);
                    $entry = json_decode($jsonData, true);

                    if ($entry && isset($entry[$timestampField])) {
                        // Apply custom filter if provided
                        if ($filterFn !== null && !$filterFn($entry)) {
                            continue;
                        }
                        $entries[] = $entry;
                    }
                }
            }
            flock($fp, LOCK_UN);
        }

        fclose($fp);

        if ($sort && !empty($entries)) {
            sortByTimestamp($entries, $timestampField);
        }

        return $entries;
    }
}

// Mock json() function for API tests if not already defined
if (!function_exists('json')) {
    /**
     * Mock json() function that FPP provides
     * In tests, we just return the data as-is for assertion
     */
    function json($data)
    {
        // Store the last response for test assertions
        $GLOBALS['lastJsonResponse'] = $data;
        return $data;
    }
}

// Mock params() function for route parameters if not already defined
if (!function_exists('params')) {
    /**
     * Mock params() function that FPP provides for route parameters
     */
    function params($name)
    {
        return $GLOBALS['routeParams'][$name] ?? null;
    }
}
