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
if (!defined('WATCHEREFUSEDIR')) {
    define('WATCHEREFUSEDIR', WATCHER_TEST_TMP_DIR . '/data/efuse');
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
    WATCHER_TEST_TMP_DIR . '/data/efuse',
    WATCHER_TEST_TMP_DIR . '/data/efuse/rollups',
    WATCHER_TEST_TMP_DIR . '/config',
    WATCHER_TEST_TMP_DIR . '/logs',
    WATCHER_TEST_TMP_DIR . '/plugins',
];

foreach ($testDirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}
