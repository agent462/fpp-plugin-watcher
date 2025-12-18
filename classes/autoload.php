<?php
/**
 * Watcher Plugin PSR-4 Autoloader
 *
 * Automatically loads classes in the Watcher namespace.
 * No external dependencies (Composer not required).
 *
 * @package Watcher
 * @since 1.0.0
 */

spl_autoload_register(function ($class) {
    // Only handle Watcher namespace
    if (strpos($class, 'Watcher\\') !== 0) {
        return;
    }

    // Convert namespace to file path
    // Watcher\Http\ApiClient -> classes/Watcher/Http/ApiClient.php
    $file = __DIR__ . '/' . str_replace('\\', '/', $class) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});
