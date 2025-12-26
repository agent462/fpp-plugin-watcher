<?php
// Load class autoloader for Watcher classes
require_once __DIR__ . '/../../classes/autoload.php';

use Watcher\Core\Settings;
use Watcher\Core\Logger;
use Watcher\Core\FileManager;
use Watcher\Core\DataStorage;
use Watcher\Http\ApiClient;

// API timeout constants (in seconds)
define("WATCHER_TIMEOUT_STATUS", 2);    // Quick status checks (fppd/status, playback sync)
define("WATCHER_TIMEOUT_STANDARD", 2);  // Standard API requests (info, version, plugins)
define("WATCHER_TIMEOUT_LONG", 5);     // Longer operations (metrics/all, state changes)

// Get settings from the Settings singleton
$_watcherSettings = Settings::getInstance();

$_watcherPluginInfo = @json_decode(file_get_contents(__DIR__ . '/../../pluginInfo.json'), true); // Parse version from pluginInfo.json so it's one source of truth
define("WATCHERPLUGINNAME", 'fpp-plugin-watcher');
define("WATCHERVERSION", 'v' . ($_watcherPluginInfo['version'] ?? '0.0.0'));
define("WATCHERPLUGINDIR", $_watcherSettings->getPluginDirectory()."/".WATCHERPLUGINNAME."/");
define("WATCHERCONFIGFILELOCATION", $_watcherSettings->getConfigDirectory()."/plugin.".WATCHERPLUGINNAME);
define("WATCHERLOGDIR", $_watcherSettings->getLogDirectory());
define("WATCHERLOGFILE", WATCHERLOGDIR."/".WATCHERPLUGINNAME.".log");
define("WATCHERFPPUSER", 'fpp');
define("WATCHERFPPGROUP", 'fpp');

// Data directory for metrics storage (plugin-data location)
define("WATCHERDATADIR", $_watcherSettings->getMediaDirectory()."/plugin-data/".WATCHERPLUGINNAME);
define("WATCHERPINGDIR", WATCHERDATADIR."/ping");
define("WATCHERMULTISYNCPINGDIR", WATCHERDATADIR."/multisync-ping");
define("WATCHERNETWORKQUALITYDIR", WATCHERDATADIR."/network-quality");
define("WATCHERMQTTDIR", WATCHERDATADIR."/mqtt");
define("WATCHERCONNECTIVITYDIR", WATCHERDATADIR."/connectivity");
define("WATCHEREFUSEDIR", WATCHERDATADIR."/efuse");
define("WATCHERCOLLECTDRRDDIR", WATCHERDATADIR."/collectd/rrd");

// Data file paths (now in plugin-data subdirectories)
define("WATCHERPINGMETRICSFILE", WATCHERPINGDIR."/raw.log");
define("WATCHERMULTISYNCPINGMETRICSFILE", WATCHERMULTISYNCPINGDIR."/raw.log");
define("WATCHERRESETSTATEFILE", WATCHERCONNECTIVITYDIR."/reset-state.json");
define("WATCHERMQTTEVENTSFILE", WATCHERMQTTDIR."/events.log");
define("WATCHERMIGRATIONMARKER", WATCHERDATADIR."/.migration-complete");

// eFuse metrics file paths
define("WATCHEREFUSERAWFILE", WATCHEREFUSEDIR."/raw.log");
define("WATCHEREFUSEROLLUPFILE", WATCHEREFUSEDIR."/1min.log");
define("WATCHEREFUSEROLLUPSTATEFILE", WATCHEREFUSEDIR."/rollup-state.json");
define("WATCHEREFUSECONFIGFILE", WATCHEREFUSEDIR."/config.json");

// Default plugin settings
define("WATCHERDEFAULTSETTINGS",
    array(
        'connectivityCheckEnabled' => false,
        'checkInterval' => 20,
        'maxFailures' => 3,
        'networkAdapter' => 'default',
        'testHosts' => '8.8.8.8,1.1.1.1',
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
        'issueCheckOutputHostsNotInSync' => true,
        'efuseMonitorEnabled' => true,
        'efuseCollectionInterval' => 5,   // seconds (1-60)
        'efuseRetentionDays' => 14)        // days (1-90)
        );

// Settings that require FPP restart when changed
// Most connectivity settings support hot-reload (every 60 seconds by daemon)
define("WATCHERSETTINGSRESTARTREQUIRED",
    array(
        'connectivityCheckEnabled' => true,   // Daemon exits gracefully if disabled, but need it to start daemon
        'checkInterval' => false,             // Hot-reloadable
        'maxFailures' => false,               // Hot-reloadable
        'networkAdapter' => false,            // Hot-reloadable
        'testHosts' => false,                 // Hot-reloadable
        'collectdEnabled' => true,            // Service managed in postStart.sh
        'multiSyncMetricsEnabled' => false,   // UI visibility only
        'multiSyncPingEnabled' => false,      // Hot-reloadable
        'multiSyncPingInterval' => false,     // Hot-reloadable
        'falconMonitorEnabled' => false,      // UI visibility only
        'controlUIEnabled' => false,          // UI visibility only
        'mqttMonitorEnabled' => true,         // MQTT subscriber started/stopped
        'mqttRetentionDays' => false,         // Cleanup schedule, no restart needed
        'issueCheckOutputs' => false,         // UI feature only
        'issueCheckSequences' => false,       // UI feature only
        'issueCheckOutputHostsNotInSync' => false, // UI feature only
        'efuseMonitorEnabled' => true,        // Daemon started/stopped in postStart.sh
        'efuseCollectionInterval' => false,   // Hot-reloadable
        'efuseRetentionDays' => false         // Hot-reloadable
    ));

// eFuse collector constants
// Collection configuration
define('EFUSE_ROLLUP_INTERVAL', 60);        // Process rollups every 60 seconds
define('EFUSE_CONFIG_CHECK_INTERVAL', 60);  // Check config every 60 seconds
define('EFUSE_MAX_AMPERAGE', 6000);         // Max 6A per port in mA

// Error handling configuration
define('EFUSE_MAX_BACKOFF_SECONDS', 60);    // Max backoff when fppd unavailable
define('EFUSE_ERROR_LOG_INTERVAL', 60);     // Only log errors every N seconds to avoid spam

// Log file for eFuse collector
define('EFUSE_LOG_FILE', WATCHERLOGDIR . '/fpp-plugin-watcher-efuse.log');

// Ensure data directories exist
DataStorage::getInstance()->ensureDirectories();

// -------------------------------------------------------------------------
// Functions removed in Phase 3 migration (2024-12-23):
// -------------------------------------------------------------------------
// Network detection (migrated to Controllers\NetworkAdapter):
// - fetchWatcherNetworkInterfaces() - Use NetworkAdapter::getInstance()->getAllInterfaces()
// - detectActiveNetworkInterface() - Use NetworkAdapter::getInstance()->detectActiveInterface()
// - detectGatewayForInterface() - Use NetworkAdapter::getInstance()->detectGateway()
// - pingHost() - Use NetworkAdapter::ping()
// - validateHost() - Use NetworkAdapter::validateHost()
//
// Reset state management (migrated to Controllers\NetworkAdapter):
// - readResetState() - Use NetworkAdapter::getInstance()->getResetState()
// - writeResetState() - Use NetworkAdapter::getInstance()->setResetState()
// - clearResetState() - Use NetworkAdapter::getInstance()->clearResetState()
// - restartConnectivityDaemon() - Use NetworkAdapter::getInstance()->restartConnectivityDaemon()
//
// FPP mode and multi-sync (migrated to MultiSync\SyncStatus):
// - isPlayerMode() - Use SyncStatus::getInstance()->isPlayerMode()
// - isRemoteModeWithMultiSync() - Use SyncStatus::getInstance()->isRemoteMode()
// - getMultiSyncRemoteSystems() - Use SyncStatus::getInstance()->getRemoteSystems()
//
// -------------------------------------------------------------------------
// Functions removed in Phase 1 migration:
// - sortByTimestamp() - Inline in FileManager::readJsonLinesFile()
// - readJsonLinesFile() - Use FileManager::getInstance()->readJsonLinesFile()
// - fetchJsonUrl() - Use ApiClient::getInstance()->get()
//
// Functions removed in Phase 2 migration:
// - ensureDataDirectories() - Use DataStorage::getInstance()->ensureDirectories()
// - getDataCategories() - Use DataStorage::getInstance()->getCategories()
// - getDataDirectoryStats() - Use DataStorage::getInstance()->getStats()
// - clearDataCategory() - Use DataStorage::getInstance()->clearCategory()
// - clearDataFile() - Use DataStorage::getInstance()->clearFile()
// - tailDataFile() - Use DataStorage::getInstance()->tailFile()
// - acquireDaemonLock() - Use DaemonLock::acquire()
// - releaseDaemonLock() - Use DaemonLock::release()
?>
