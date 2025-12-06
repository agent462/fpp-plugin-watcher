#!/usr/bin/php
<?php
/**
 * migrateData.php - One-time migration of metrics data to plugin-data directory
 *
 * Moves existing data files from /home/fpp/media/logs/ to
 * /home/fpp/media/plugin-data/fpp-plugin-watcher/ subdirectories.
 *
 * This script is idempotent - it checks for a migration marker file and
 * only runs once per installation.
 */

include_once __DIR__ . '/../lib/core/watcherCommon.php';

// Check if migration has already been completed
if (file_exists(WATCHERMIGRATIONMARKER)) {
    exit(0);
}

logMessage("Starting data migration to plugin-data directory");

// Ensure all data directories exist first
ensureDataDirectories();

// Old location (logs directory)
$oldDir = WATCHERLOGDIR;

// Migration mapping: old filename => [new directory, new filename]
$migrations = [
    // Ping metrics
    'fpp-plugin-watcher-ping-metrics.log' => [WATCHERPINGDIR, 'raw.log'],
    'fpp-plugin-watcher-ping-rollup-state.json' => [WATCHERPINGDIR, 'rollup-state.json'],
    'fpp-plugin-watcher-ping-1min.log' => [WATCHERPINGDIR, '1min.log'],
    'fpp-plugin-watcher-ping-5min.log' => [WATCHERPINGDIR, '5min.log'],
    'fpp-plugin-watcher-ping-30min.log' => [WATCHERPINGDIR, '30min.log'],
    'fpp-plugin-watcher-ping-2hour.log' => [WATCHERPINGDIR, '2hour.log'],

    // Multi-sync ping metrics
    'fpp-plugin-watcher-multisync-ping-metrics.log' => [WATCHERMULTISYNCPINGDIR, 'raw.log'],
    'fpp-plugin-watcher-multisync-rollup-state.json' => [WATCHERMULTISYNCPINGDIR, 'rollup-state.json'],
    'fpp-plugin-watcher-multisync-ping-1min.log' => [WATCHERMULTISYNCPINGDIR, '1min.log'],
    'fpp-plugin-watcher-multisync-ping-5min.log' => [WATCHERMULTISYNCPINGDIR, '5min.log'],
    'fpp-plugin-watcher-multisync-ping-30min.log' => [WATCHERMULTISYNCPINGDIR, '30min.log'],
    'fpp-plugin-watcher-multisync-ping-2hour.log' => [WATCHERMULTISYNCPINGDIR, '2hour.log'],

    // Network quality metrics
    'fpp-plugin-watcher-network-quality.log' => [WATCHERNETWORKQUALITYDIR, 'raw.log'],
    'fpp-plugin-watcher-network-quality-rollup-state.json' => [WATCHERNETWORKQUALITYDIR, 'rollup-state.json'],
    'fpp-plugin-watcher-network-quality-1min.log' => [WATCHERNETWORKQUALITYDIR, '1min.log'],
    'fpp-plugin-watcher-network-quality-5min.log' => [WATCHERNETWORKQUALITYDIR, '5min.log'],
    'fpp-plugin-watcher-network-quality-30min.log' => [WATCHERNETWORKQUALITYDIR, '30min.log'],
    'fpp-plugin-watcher-network-quality-2hour.log' => [WATCHERNETWORKQUALITYDIR, '2hour.log'],

    // MQTT events
    'fpp-plugin-watcher-mqtt-events.log' => [WATCHERMQTTDIR, 'events.log'],

    // Connectivity state
    'fpp-plugin-watcher-reset-state.json' => [WATCHERCONNECTIVITYDIR, 'reset-state.json']
];

$migrated = 0;
$skipped = 0;
$errors = 0;

foreach ($migrations as $oldFilename => $destination) {
    $oldPath = $oldDir . '/' . $oldFilename;
    $newDir = $destination[0];
    $newFilename = $destination[1];
    $newPath = $newDir . '/' . $newFilename;

    // Skip if old file doesn't exist
    if (!file_exists($oldPath)) {
        $skipped++;
        continue;
    }

    // Skip if new file already exists (shouldn't happen, but be safe)
    if (file_exists($newPath)) {
        logMessage("Migration: Skipping $oldFilename - destination already exists");
        $skipped++;
        continue;
    }

    // Ensure destination directory exists
    if (!is_dir($newDir)) {
        @mkdir($newDir, 0755, true);
        ensureFppOwnership($newDir);
    }

    // Move the file (rename is atomic on same filesystem)
    if (@rename($oldPath, $newPath)) {
        ensureFppOwnership($newPath);
        logMessage("Migration: Moved $oldFilename -> $newFilename");
        $migrated++;
    } else {
        // Fall back to copy+delete if rename fails (different filesystems)
        if (@copy($oldPath, $newPath)) {
            ensureFppOwnership($newPath);
            @unlink($oldPath);
            logMessage("Migration: Copied $oldFilename -> $newFilename");
            $migrated++;
        } else {
            logMessage("Migration: ERROR - Failed to move $oldFilename");
            $errors++;
        }
    }
}

// Create migration marker file
$markerContent = json_encode([
    'migrated_at' => date('Y-m-d H:i:s'),
    'files_migrated' => $migrated,
    'files_skipped' => $skipped,
    'errors' => $errors
], JSON_PRETTY_PRINT);

if (@file_put_contents(WATCHERMIGRATIONMARKER, $markerContent)) {
    ensureFppOwnership(WATCHERMIGRATIONMARKER);
}

logMessage("Data migration complete: $migrated migrated, $skipped skipped, $errors errors");

exit($errors > 0 ? 1 : 0);
?>
