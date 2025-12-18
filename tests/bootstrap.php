<?php
/**
 * PHPUnit Test Bootstrap
 *
 * Sets up the testing environment for fpp-plugin-watcher tests.
 */

declare(strict_types=1);

// Define test mode constant
define('WATCHER_TEST_MODE', true);

// Get plugin base directory
define('WATCHER_PLUGIN_DIR', dirname(__DIR__));
define('WATCHER_TEST_DIR', __DIR__);
define('WATCHER_TEST_DATA_DIR', __DIR__ . '/Fixtures/data');
define('WATCHER_TEST_TMP_DIR', sys_get_temp_dir() . '/watcher-tests-' . getmypid());

// Create test temporary directory
if (!is_dir(WATCHER_TEST_TMP_DIR)) {
    mkdir(WATCHER_TEST_TMP_DIR, 0755, true);
}

// Load composer autoloader if available
$composerAutoload = WATCHER_PLUGIN_DIR . '/vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}

// Load plugin autoloader
require_once WATCHER_PLUGIN_DIR . '/classes/autoload.php';

// Load test constants (mock FPP settings)
require_once __DIR__ . '/Fixtures/test_constants.php';

// Load base test case
require_once __DIR__ . '/TestCase.php';

// Register shutdown handler to clean up temp directory
register_shutdown_function(function () {
    if (is_dir(WATCHER_TEST_TMP_DIR)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(WATCHER_TEST_TMP_DIR, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir(WATCHER_TEST_TMP_DIR);
    }
});
