<?php
// Core
include_once __DIR__ . '/lib/core/watcherCommon.php';
include_once WATCHERPLUGINDIR . '/lib/core/config.php';

// Metrics
include_once WATCHERPLUGINDIR . '/lib/metrics/systemMetrics.php';
include_once WATCHERPLUGINDIR . '/lib/metrics/pingMetrics.php';
include_once WATCHERPLUGINDIR . '/lib/metrics/multiSyncPingMetrics.php';
include_once WATCHERPLUGINDIR . '/lib/metrics/networkQualityMetrics.php';

// Multi-sync
include_once WATCHERPLUGINDIR . '/lib/multisync/syncStatus.php';
include_once WATCHERPLUGINDIR . '/lib/multisync/comparison.php';

// Controllers
include_once WATCHERPLUGINDIR . '/lib/controllers/falcon.php';
include_once WATCHERPLUGINDIR . '/lib/controllers/remoteControl.php';

// Utils
include_once WATCHERPLUGINDIR . '/lib/utils/updateCheck.php';
include_once WATCHERPLUGINDIR . '/lib/utils/mqttEvents.php';
/**
 * Returns the API endpoints for the fpp-plugin-watcher plugin
 */

// --- API Helper Functions ---

/**
 * Get hours parameter with validation (1-2160 range)
 */
function getHoursParam($default = 24) {
    return isset($_GET['hours']) ? max(1, min(2160, intval($_GET['hours']))) : $default;
}

/**
 * Get required query parameter, returns null if missing
 */
function getRequiredQueryParam($param) {
    $value = isset($_GET[$param]) ? trim($_GET[$param]) : '';
    return empty($value) ? null : $value;
}

/**
 * Get required JSON body field, returns null if missing
 */
function getRequiredJsonField($field) {
    static $jsonBody = null;
    if ($jsonBody === null) {
        $jsonBody = json_decode(file_get_contents('php://input'), true) ?: [];
    }
    return isset($jsonBody[$field]) ? $jsonBody[$field] : null;
}

/**
 * Get parsed JSON body
 */
function getJsonBody() {
    static $jsonBody = null;
    if ($jsonBody === null) {
        $jsonBody = json_decode(file_get_contents('php://input'), true) ?: [];
    }
    return $jsonBody;
}

/**
 * Return standardized API error response
 * @disregard P1010
 */
function apiError($message, $code = 400) {
    http_response_code($code);
    return json(['success' => false, 'error' => $message]);
}

// --- Endpoint Registration ---

function getEndpointsfpppluginwatcher() {
    return [
        // Core
        ['method' => 'GET', 'endpoint' => 'version', 'callback' => 'fpppluginWatcherVersion'],

        // Local metrics
        ['method' => 'GET', 'endpoint' => 'metrics/ping/raw', 'callback' => 'fpppluginWatcherPingRaw'],
        ['method' => 'GET', 'endpoint' => 'metrics/memory/free', 'callback' => 'fpppluginWatcherMemoryFree'],
        ['method' => 'GET', 'endpoint' => 'metrics/disk/free', 'callback' => 'fpppluginWatcherDiskFree'],
        ['method' => 'GET', 'endpoint' => 'metrics/cpu/average', 'callback' => 'fpppluginWatcherCPUAverage'],
        ['method' => 'GET', 'endpoint' => 'metrics/load/average', 'callback' => 'fpppluginWatcherLoadAverage'],
        ['method' => 'GET', 'endpoint' => 'metrics/interface/bandwidth', 'callback' => 'fpppluginWatcherInterfaceBandwidth'],
        ['method' => 'GET', 'endpoint' => 'metrics/interface/list', 'callback' => 'fpppluginWatcherInterfaceList'],
        ['method' => 'GET', 'endpoint' => 'metrics/ping/rollup', 'callback' => 'fpppluginWatcherPingRollup'],
        ['method' => 'GET', 'endpoint' => 'metrics/ping/rollup/tiers', 'callback' => 'fpppluginWatcherPingRollupTiers'],
        ['method' => 'GET', 'endpoint' => 'metrics/ping/rollup/:tier', 'callback' => 'fpppluginWatcherPingRollupTier'],
        ['method' => 'GET', 'endpoint' => 'metrics/thermal', 'callback' => 'fpppluginWatcherThermal'],
        ['method' => 'GET', 'endpoint' => 'metrics/thermal/zones', 'callback' => 'fpppluginWatcherThermalZones'],
        ['method' => 'GET', 'endpoint' => 'metrics/wireless', 'callback' => 'fpppluginWatcherWireless'],
        ['method' => 'GET', 'endpoint' => 'metrics/wireless/interfaces', 'callback' => 'fpppluginWatcherWirelessInterfaces'],
        ['method' => 'GET', 'endpoint' => 'metrics/all', 'callback' => 'fpppluginWatcherMetricsAll'],

        // Multi-sync ping metrics
        ['method' => 'GET', 'endpoint' => 'metrics/multisync/ping/raw', 'callback' => 'fpppluginWatcherMultiSyncPingRaw'],
        ['method' => 'GET', 'endpoint' => 'metrics/multisync/ping/rollup', 'callback' => 'fpppluginWatcherMultiSyncPingRollup'],
        ['method' => 'GET', 'endpoint' => 'metrics/multisync/ping/rollup/tiers', 'callback' => 'fpppluginWatcherMultiSyncPingRollupTiers'],
        ['method' => 'GET', 'endpoint' => 'metrics/multisync/hosts', 'callback' => 'fpppluginWatcherMultiSyncHosts'],

        // Network quality metrics
        ['method' => 'GET', 'endpoint' => 'metrics/network-quality/current', 'callback' => 'fpppluginWatcherNetworkQualityCurrent'],
        ['method' => 'GET', 'endpoint' => 'metrics/network-quality/history', 'callback' => 'fpppluginWatcherNetworkQualityHistory'],
        ['method' => 'GET', 'endpoint' => 'metrics/network-quality/host', 'callback' => 'fpppluginWatcherNetworkQualityHost'],
        ['method' => 'POST', 'endpoint' => 'metrics/network-quality/collect', 'callback' => 'fpppluginWatcherNetworkQualityCollect'],

        // Falcon controller
        ['method' => 'GET', 'endpoint' => 'falcon/status', 'callback' => 'fpppluginWatcherFalconStatus'],
        ['method' => 'GET', 'endpoint' => 'falcon/config', 'callback' => 'fpppluginWatcherFalconConfigGet'],
        ['method' => 'POST', 'endpoint' => 'falcon/config', 'callback' => 'fpppluginWatcherFalconConfigSave'],
        ['method' => 'POST', 'endpoint' => 'falcon/test', 'callback' => 'fpppluginWatcherFalconTest'],
        ['method' => 'POST', 'endpoint' => 'falcon/reboot', 'callback' => 'fpppluginWatcherFalconReboot'],
        ['method' => 'GET', 'endpoint' => 'falcon/discover', 'callback' => 'fpppluginWatcherFalconDiscover'],

        // Remote control proxy
        ['method' => 'GET', 'endpoint' => 'remote/status', 'callback' => 'fpppluginWatcherRemoteStatus'],
        ['method' => 'POST', 'endpoint' => 'remote/command', 'callback' => 'fpppluginWatcherRemoteCommand'],
        ['method' => 'POST', 'endpoint' => 'remote/restart', 'callback' => 'fpppluginWatcherRemoteRestart'],
        ['method' => 'POST', 'endpoint' => 'remote/reboot', 'callback' => 'fpppluginWatcherRemoteReboot'],
        ['method' => 'POST', 'endpoint' => 'remote/upgrade', 'callback' => 'fpppluginWatcherRemoteUpgrade'],
        ['method' => 'GET', 'endpoint' => 'remote/plugins', 'callback' => 'fpppluginWatcherRemotePlugins'],
        ['method' => 'GET', 'endpoint' => 'remote/plugins/updates', 'callback' => 'fpppluginWatcherRemotePluginUpdates'],
        ['method' => 'GET', 'endpoint' => 'remote/playback/sync', 'callback' => 'fpppluginWatcherRemotePlaybackSync'],
        ['method' => 'POST', 'endpoint' => 'remote/fpp/upgrade', 'callback' => 'fpppluginWatcherRemoteFPPUpgrade'],
        ['method' => 'GET', 'endpoint' => 'remote/connectivity/state', 'callback' => 'fpppluginWatcherRemoteConnectivityState'],
        ['method' => 'POST', 'endpoint' => 'remote/connectivity/state/clear', 'callback' => 'fpppluginWatcherRemoteConnectivityStateClear'],

        // Plugin updates
        ['method' => 'GET', 'endpoint' => 'update/check', 'callback' => 'fpppluginWatcherUpdateCheck'],
        ['method' => 'GET', 'endpoint' => 'fpp/release', 'callback' => 'fpppluginWatcherFPPRelease'],
        ['method' => 'GET', 'endpoint' => 'plugins/updates', 'callback' => 'fpppluginWatcherLocalPluginUpdates'],

        // Connectivity state (local)
        ['method' => 'GET', 'endpoint' => 'connectivity/state', 'callback' => 'fpppluginWatcherConnectivityState'],
        ['method' => 'POST', 'endpoint' => 'connectivity/state/clear', 'callback' => 'fpppluginWatcherConnectivityStateClear'],

        // MultiSync comparison
        ['method' => 'GET', 'endpoint' => 'multisync/comparison', 'callback' => 'fpppluginWatcherMultiSyncComparison'],
        ['method' => 'GET', 'endpoint' => 'multisync/comparison/host', 'callback' => 'fpppluginWatcherMultiSyncComparisonHost'],
        ['method' => 'GET', 'endpoint' => 'multisync/clock-drift', 'callback' => 'fpppluginWatcherMultiSyncClockDrift'],

        // System time
        ['method' => 'GET', 'endpoint' => 'time', 'callback' => 'fpppluginWatcherTime'],
        ['method' => 'GET', 'endpoint' => 'outputs/discrepancies', 'callback' => 'fpppluginWatcherOutputDiscrepancies'],

        // MQTT events
        ['method' => 'GET', 'endpoint' => 'mqtt/events', 'callback' => 'fpppluginWatcherMqttEvents'],
        ['method' => 'GET', 'endpoint' => 'mqtt/stats', 'callback' => 'fpppluginWatcherMqttStats'],
        ['method' => 'GET', 'endpoint' => 'mqtt/hosts', 'callback' => 'fpppluginWatcherMqttHosts'],

        // Utilities
        ['method' => 'GET', 'endpoint' => 'ping/check', 'callback' => 'fpppluginWatcherPingCheck'],

        // Data management
        ['method' => 'GET', 'endpoint' => 'data/stats', 'callback' => 'fpppluginWatcherDataStats'],
        ['method' => 'GET', 'endpoint' => 'data/:category/:filename/tail', 'callback' => 'fpppluginWatcherDataTail'],
        ['method' => 'DELETE', 'endpoint' => 'data/:category', 'callback' => 'fpppluginWatcherDataClear'],
        ['method' => 'DELETE', 'endpoint' => 'data/:category/:filename', 'callback' => 'fpppluginWatcherDataClearFile'],
    ];
}

// GET /api/plugin/fpp-plugin-watcher/version
function fpppluginwatcherVersion() {
    $result = array();
    $result['version'] = WATCHERVERSION;
    /** @disregard P1010 */
    return json($result);
}

// GET /api/plugin/fpp-plugin-watcher/metrics/ping/raw
function fpppluginWatcherPingRaw() {
    /** @disregard P1010 */
    return json(getPingMetrics(getHoursParam()));
}

// GET /api/plugin/fpp-plugin-watcher/metrics/memory/free
function fpppluginwatcherMemoryFree() {
    /** @disregard P1010 */
    return json(getMemoryFreeMetrics(getHoursParam()));
}

// GET /api/plugin/fpp-plugin-watcher/metrics/disk/free
function fpppluginwatcherDiskFree() {
    /** @disregard P1010 */
    return json(getDiskFreeMetrics(getHoursParam()));
}

// GET /api/plugin/fpp-plugin-watcher/metrics/cpu/average
function fpppluginwatcherCPUAverage() {
    /** @disregard P1010 */
    return json(getCPUAverageMetrics(getHoursParam()));
}

// GET /api/plugin/fpp-plugin-watcher/metrics/load/average
function fpppluginwatcherLoadAverage() {
    /** @disregard P1010 */
    return json(getLoadAverageMetrics(getHoursParam()));
}

// GET /api/plugin/fpp-plugin-watcher/metrics/interface/bandwidth
function fpppluginwatcherInterfaceBandwidth() {
    $interface = isset($_GET['interface']) ? $_GET['interface'] : 'eth0';
    if (!preg_match('/^[a-zA-Z0-9\-\.]+$/', $interface)) {
        return apiError('Invalid interface name');
    }
    /** @disregard P1010 */
    return json(getInterfaceBandwidthMetrics($interface, getHoursParam()));
}

// GET /api/plugin/fpp-plugin-watcher/metrics/interface/list
function fpppluginwatcherInterfaceList() {
    $interfaces = getNetworkInterfaces();
    $result = [
        'success' => true,
        'count' => count($interfaces),
        'interfaces' => $interfaces
    ];
    /** @disregard P1010 */
    return json($result);
}

// GET /api/plugin/fpp-plugin-watcher/metrics/ping/rollup
function fpppluginwatcherPingRollup() {
    /** @disregard P1010 */
    return json(getPingMetricsRollup(getHoursParam()));
}

// GET /api/plugin/fpp-plugin-watcher/metrics/ping/rollup/tiers
// Returns information about available rollup tiers
function fpppluginwatcherPingRollupTiers() {
    $tiers = getRollupTiersInfo();
    $result = [
        'success' => true,
        'tiers' => $tiers
    ];
    /** @disregard P1010 */
    return json($result);
}

// GET /api/plugin/fpp-plugin-watcher/metrics/ping/rollup/:tier
function fpppluginwatcherPingRollupTier() {
    global $urlParts;
    $tier = isset($urlParts[5]) ? $urlParts[5] : '1min';
    $hoursBack = getHoursParam();
    $endTime = time();
    $startTime = $endTime - ($hoursBack * 3600);
    /** @disregard P1010 */
    return json(readRollupData($tier, $startTime, $endTime));
}

// GET /api/plugin/fpp-plugin-watcher/metrics/multisync/ping/raw
function fpppluginWatcherMultiSyncPingRaw() {
    $hostname = isset($_GET['hostname']) ? $_GET['hostname'] : null;
    /** @disregard P1010 */
    return json(getRawMultiSyncPingMetrics(getHoursParam(), $hostname));
}

// GET /api/plugin/fpp-plugin-watcher/metrics/multisync/ping/rollup
function fpppluginWatcherMultiSyncPingRollup() {
    $hostname = isset($_GET['hostname']) ? $_GET['hostname'] : null;
    /** @disregard P1010 */
    return json(getMultiSyncPingMetrics(getHoursParam(), $hostname));
}

// GET /api/plugin/fpp-plugin-watcher/metrics/multisync/ping/rollup/tiers
// Returns information about available multi-sync rollup tiers
function fpppluginWatcherMultiSyncPingRollupTiers() {
    $tiers = getMultiSyncRollupTiersInfo();
    $result = [
        'success' => true,
        'tiers' => $tiers
    ];
    /** @disregard P1010 */
    return json($result);
}

// GET /api/plugin/fpp-plugin-watcher/metrics/multisync/hosts
// Returns list of unique hostnames from multi-sync metrics
function fpppluginWatcherMultiSyncHosts() {
    $hosts = getMultiSyncHostsList();
    $result = [
        'success' => true,
        'count' => count($hosts),
        'hosts' => $hosts
    ];

    /** @disregard P1010 */
    return json($result);
}

// GET /api/plugin/fpp-plugin-watcher/metrics/thermal
function fpppluginwatcherThermal() {
    /** @disregard P1010 */
    return json(getThermalMetrics(getHoursParam()));
}

// GET /api/plugin/fpp-plugin-watcher/metrics/thermal/zones
function fpppluginwatcherThermalZones() {
    $zones = getThermalZones();
    $result = [
        'success' => true,
        'count' => count($zones),
        'zones' => $zones
    ];
    /** @disregard P1010 */
    return json($result);
}

// GET /api/plugin/fpp-plugin-watcher/metrics/wireless
function fpppluginwatcherWireless() {
    /** @disregard P1010 */
    return json(getWirelessMetrics(getHoursParam()));
}

// GET /api/plugin/fpp-plugin-watcher/metrics/wireless/interfaces
function fpppluginwatcherWirelessInterfaces() {
    $interfaces = getWirelessInterfaces();
    $result = [
        'success' => true,
        'count' => count($interfaces),
        'interfaces' => $interfaces
    ];
    /** @disregard P1010 */
    return json($result);
}

// GET /api/plugin/fpp-plugin-watcher/metrics/all
function fpppluginwatcherMetricsAll() {
    $hours = getHoursParam();
    /** @disregard P1010 */
    return json([
        'success' => true,
        'hours' => $hours,
        'cpu' => getCPUAverageMetrics($hours),
        'memory' => getMemoryFreeMetrics($hours),
        'disk' => getDiskFreeMetrics($hours),
        'load' => getLoadAverageMetrics($hours),
        'thermal' => getThermalMetrics($hours),
        'wireless' => getWirelessMetrics($hours),
        'ping' => getPingMetricsRollup($hours)
    ]);
}

// GET /api/plugin/fpp-plugin-watcher/falcon/status
// Returns status of all configured Falcon controllers (or single if host param provided)
function fpppluginwatcherFalconStatus() {
    $config = readPluginConfig();
    $hostsString = isset($_GET['host']) ? $_GET['host'] : ($config['falconControllers'] ?? '');

    if (empty($hostsString)) {
        /** @disregard P1010 */
        return json([
            'success' => true,
            'controllers' => [],
            'message' => 'No Falcon controllers configured'
        ]);
    }

    $result = FalconController::getMultiStatus($hostsString);
    $result['success'] = true;

    /** @disregard P1010 */
    return json($result);
}

// POST /api/plugin/fpp-plugin-watcher/falcon/config
// Save Falcon controller configuration
function fpppluginwatcherFalconConfigSave() {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['hosts'])) {
        /** @disregard P1010 */
        return json([
            'success' => false,
            'error' => 'Missing hosts parameter'
        ]);
    }

    $hosts = trim($input['hosts']);

    // Validate hosts
    if (!empty($hosts)) {
        $hostList = array_map('trim', explode(',', $hosts));
        foreach ($hostList as $host) {
            if (!FalconController::isValidHost($host)) {
                /** @disregard P1010 */
                return json([
                    'success' => false,
                    'error' => "Invalid host: $host"
                ]);
            }
        }
    }

    // Save to config
    /** @disregard P1010 */
    WriteSettingToFile('falconControllers', $hosts, WATCHERPLUGINNAME);

    /** @disregard P1010 */
    return json([
        'success' => true,
        'message' => 'Configuration saved',
        'hosts' => $hosts
    ]);
}

// GET /api/plugin/fpp-plugin-watcher/falcon/config
// Get Falcon controller configuration
function fpppluginwatcherFalconConfigGet() {
    $config = readPluginConfig();

    /** @disregard P1010 */
    return json([
        'success' => true,
        'hosts' => $config['falconControllers'] ?? ''
    ]);
}

// POST /api/plugin/fpp-plugin-watcher/falcon/test
// Enable or disable test mode on a Falcon controller
function fpppluginwatcherFalconTest() {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['host'])) {
        /** @disregard P1010 */
        return json([
            'success' => false,
            'error' => 'Missing host parameter'
        ]);
    }

    $host = trim($input['host']);
    $enable = isset($input['enable']) ? (bool)$input['enable'] : true;
    $testMode = isset($input['testMode']) ? intval($input['testMode']) : 5;

    try {
        $controller = new FalconController($host, 80, 5);

        if (!$controller->isReachable()) {
            /** @disregard P1010 */
            return json([
                'success' => false,
                'error' => 'Controller not reachable'
            ]);
        }

        if ($enable) {
            $result = $controller->enableTest($testMode);
            $action = 'enabled';
        } else {
            $result = $controller->disableTest();
            $action = 'disabled';
        }

        if ($result) {
            /** @disregard P1010 */
            return json([
                'success' => true,
                'message' => "Test mode $action",
                'host' => $host,
                'testEnabled' => $enable,
                'testMode' => $enable ? $testMode : null
            ]);
        } else {
            /** @disregard P1010 */
            return json([
                'success' => false,
                'error' => $controller->getLastError() ?: 'Failed to change test mode'
            ]);
        }
    } catch (Exception $e) {
        /** @disregard P1010 */
        return json([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}

// POST /api/plugin/fpp-plugin-watcher/falcon/reboot
// Reboot a Falcon controller
function fpppluginwatcherFalconReboot() {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['host'])) {
        /** @disregard P1010 */
        return json([
            'success' => false,
            'error' => 'Missing host parameter'
        ]);
    }

    $host = trim($input['host']);

    try {
        $controller = new FalconController($host, 80, 5);

        if (!$controller->isReachable()) {
            /** @disregard P1010 */
            return json([
                'success' => false,
                'error' => 'Controller not reachable'
            ]);
        }

        $result = $controller->reboot();

        if ($result) {
            /** @disregard P1010 */
            return json([
                'success' => true,
                'message' => 'Reboot command sent',
                'host' => $host
            ]);
        } else {
            /** @disregard P1010 */
            return json([
                'success' => false,
                'error' => $controller->getLastError() ?: 'Failed to send reboot command'
            ]);
        }
    } catch (Exception $e) {
        /** @disregard P1010 */
        return json([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}

// GET /api/plugin/fpp-plugin-watcher/falcon/discover
// Discover Falcon controllers on a subnet
function fpppluginwatcherFalconDiscover() {
    $subnet = isset($_GET['subnet']) ? trim($_GET['subnet']) : FalconController::autoDetectSubnet();

    if (empty($subnet)) {
        /** @disregard P1010 */
        return json([
            'success' => false,
            'error' => 'Could not determine subnet. Please provide subnet parameter (e.g., ?subnet=192.168.1)'
        ]);
    }

    if (!FalconController::isValidSubnet($subnet)) {
        /** @disregard P1010 */
        return json([
            'success' => false,
            'error' => 'Invalid subnet format. Expected format: 192.168.1'
        ]);
    }

    $startIp = max(1, min(254, isset($_GET['start']) ? intval($_GET['start']) : 1));
    $endIp = max($startIp, min(254, isset($_GET['end']) ? intval($_GET['end']) : 254));
    $timeout = max(1, min(5, isset($_GET['timeout']) ? intval($_GET['timeout']) : 1));

    try {
        $discovered = FalconController::discover($subnet, $startIp, $endIp, $timeout);
        /** @disregard P1010 */
        return json([
            'success' => true,
            'subnet' => $subnet,
            'range' => ['start' => $startIp, 'end' => $endIp],
            'count' => count($discovered),
            'controllers' => $discovered
        ]);
    } catch (Exception $e) {
        /** @disregard P1010 */
        return json([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}

// GET /api/plugin/fpp-plugin-watcher/remote/status?host=x
function fpppluginWatcherRemoteStatus() {
    $host = getRequiredQueryParam('host');
    if (!$host) return apiError('Missing host parameter');
    /** @disregard P1010 */
    return json(getRemoteStatus($host));
}

// POST /api/plugin/fpp-plugin-watcher/remote/command
function fpppluginWatcherRemoteCommand() {
    $input = getJsonBody();
    if (!isset($input['host'])) return apiError('Missing host parameter');
    if (!isset($input['command'])) return apiError('Missing command parameter');
    /** @disregard P1010 */
    return json(sendRemoteCommand(
        trim($input['host']),
        $input['command'],
        $input['args'] ?? [],
        $input['multisyncCommand'] ?? false,
        $input['multisyncHosts'] ?? ''
    ));
}

// POST /api/plugin/fpp-plugin-watcher/remote/restart
function fpppluginWatcherRemoteRestart() {
    $input = getJsonBody();
    if (!isset($input['host'])) return apiError('Missing host parameter');
    /** @disregard P1010 */
    return json(restartRemoteFPPD(trim($input['host'])));
}

// POST /api/plugin/fpp-plugin-watcher/remote/reboot
function fpppluginWatcherRemoteReboot() {
    $input = getJsonBody();
    if (!isset($input['host'])) return apiError('Missing host parameter');
    /** @disregard P1010 */
    return json(rebootRemoteFPP(trim($input['host'])));
}

// POST /api/plugin/fpp-plugin-watcher/remote/upgrade
function fpppluginWatcherRemoteUpgrade() {
    $input = getJsonBody();
    if (!isset($input['host'])) return apiError('Missing host parameter');
    $plugin = isset($input['plugin']) ? trim($input['plugin']) : null;
    /** @disregard P1010 */
    return json(upgradeRemotePlugin(trim($input['host']), $plugin));
}

// GET /api/plugin/fpp-plugin-watcher/remote/plugins?host=x
function fpppluginWatcherRemotePlugins() {
    $host = getRequiredQueryParam('host');
    if (!$host) return apiError('Missing host parameter');
    /** @disregard P1010 */
    return json(getRemotePlugins($host));
}

// GET /api/plugin/fpp-plugin-watcher/remote/plugins/updates?host=x
function fpppluginWatcherRemotePluginUpdates() {
    $host = getRequiredQueryParam('host');
    if (!$host) return apiError('Missing host parameter');
    /** @disregard P1010 */
    return json(checkRemotePluginUpdates($host));
}

// GET /api/plugin/fpp-plugin-watcher/remote/playback/sync
// Batch fetch playback status from all multisync hosts (parallel with timeouts)
function fpppluginWatcherRemotePlaybackSync() {
    $remoteSystems = getMultiSyncRemoteSystems();
    if (empty($remoteSystems)) {
        /** @disregard P1010 */
        return json(['success' => true, 'local' => null, 'remotes' => []]);
    }

    // Fetch local status
    $localStatus = apiCall('GET', 'http://127.0.0.1/api/fppd/status', [], true, 2);
    $local = null;
    if ($localStatus !== false) {
        $local = [
            'hostname' => $localStatus['host_name'] ?? 'Local',
            'status' => $localStatus['status_name'] ?? 'unknown',
            'sequence' => $localStatus['current_sequence'] ?? '',
            'secondsPlayed' => floatval($localStatus['seconds_played'] ?? 0),
            'timeElapsed' => $localStatus['time_elapsed'] ?? '00:00',
            'mode' => $localStatus['mode_name'] ?? 'unknown'
        ];
    }

    // Parallel fetch from all remotes using curl_multi
    $mh = curl_multi_init();
    $handles = [];
    $timeout = 2;

    foreach ($remoteSystems as $system) {
        $ch = curl_init("http://{$system['address']}/api/fppd/status");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => ['Accept: application/json']
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$system['address']] = ['handle' => $ch, 'hostname' => $system['hostname']];
    }

    // Execute all requests in parallel
    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) curl_multi_select($mh);
    } while ($active && $status === CURLM_OK);

    // Collect results
    $remotes = [];
    foreach ($handles as $address => $info) {
        $ch = $info['handle'];
        $response = curl_multi_getcontent($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if ($data) {
                $remotes[] = [
                    'address' => $address,
                    'hostname' => $info['hostname'],
                    'status' => $data['status_name'] ?? 'unknown',
                    'sequence' => $data['current_sequence'] ?? '',
                    'secondsPlayed' => floatval($data['seconds_played'] ?? 0),
                    'timeElapsed' => $data['time_elapsed'] ?? '00:00',
                    'mode' => $data['mode_name'] ?? 'unknown'
                ];
                continue;
            }
        }
        // Offline/failed
        $remotes[] = [
            'address' => $address,
            'hostname' => $info['hostname'],
            'status' => 'offline',
            'sequence' => '',
            'secondsPlayed' => 0,
            'timeElapsed' => '--:--',
            'mode' => 'unknown'
        ];
    }
    curl_multi_close($mh);

    /** @disregard P1010 */
    return json(['success' => true, 'local' => $local, 'remotes' => $remotes]);
}

// GET /api/plugin/fpp-plugin-watcher/update/check
// Check GitHub for latest plugin version
function fpppluginWatcherUpdateCheck() {
    /** @disregard P1010 */
    return json(checkWatcherUpdate());
}

// GET /api/plugin/fpp-plugin-watcher/fpp/release
// Check GitHub for latest FPP release and compare against current branch
function fpppluginWatcherFPPRelease() {
    $currentBranch = isset($_GET['branch']) ? trim($_GET['branch']) : null;

    // If no branch provided, try to get it from local system
    if (empty($currentBranch)) {
        $systemInfo = apiCall('GET', 'http://127.0.0.1/api/system/info', [], true, 5);
        if ($systemInfo && isset($systemInfo['Branch'])) {
            $currentBranch = $systemInfo['Branch'];
        }
    }

    if (empty($currentBranch)) {
        /** @disregard P1010 */
        return json([
            'success' => false,
            'error' => 'Could not determine current FPP branch'
        ]);
    }

    $result = checkFPPReleaseUpgrade($currentBranch);
    $result['success'] = true;

    /** @disregard P1010 */
    return json($result);
}

// GET /api/plugin/fpp-plugin-watcher/plugins/updates
// Check for updates on locally installed plugins (for localhost card)
function fpppluginWatcherLocalPluginUpdates() {
    $result = checkRemotePluginUpdates('127.0.0.1');
    /** @disregard P1010 */
    return json($result);
}

// POST /api/plugin/fpp-plugin-watcher/remote/fpp/upgrade
// Streams the FPP upgrade output from a remote host
// Optional 'version' parameter for cross-version upgrades (e.g., "v9.3")
function fpppluginWatcherRemoteFPPUpgrade() {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['host'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Missing host parameter']);
        return;
    }

    $targetVersion = isset($input['version']) ? trim($input['version']) : null;
    streamRemoteFPPUpgrade(trim($input['host']), $targetVersion);
}

// GET /api/plugin/fpp-plugin-watcher/connectivity/state
// Returns the current connectivity check reset state
function fpppluginWatcherConnectivityState() {
    $state = readResetState();

    if ($state === null) {
        /** @disregard P1010 */
        return json([
            'success' => true,
            'hasResetAdapter' => false,
            'message' => 'No reset has occurred'
        ]);
    }

    /** @disregard P1010 */
    return json([
        'success' => true,
        'hasResetAdapter' => true,
        'resetTimestamp' => $state['resetTimestamp'] ?? null,
        'resetTime' => isset($state['resetTimestamp']) ? date('Y-m-d H:i:s', $state['resetTimestamp']) : null,
        'adapter' => $state['adapter'] ?? null,
        'reason' => $state['reason'] ?? null
    ]);
}

// POST /api/plugin/fpp-plugin-watcher/connectivity/state/clear
// Clears the reset state and restarts the connectivity daemon
function fpppluginWatcherConnectivityStateClear() {
    $cleared = clearResetState();

    if (!$cleared) {
        /** @disregard P1010 */
        return json([
            'success' => false,
            'error' => 'Failed to clear reset state'
        ]);
    }

    logMessage("Reset state cleared via API, restarting connectivity daemon");
    restartConnectivityDaemon();

    /** @disregard P1010 */
    return json([
        'success' => true,
        'message' => 'Reset state cleared and connectivity daemon restarted'
    ]);
}

// GET /api/plugin/fpp-plugin-watcher/remote/connectivity/state?host=x
function fpppluginWatcherRemoteConnectivityState() {
    $host = getRequiredQueryParam('host');
    if (!$host) return apiError('Missing host parameter');

    $result = apiCall('GET', "http://{$host}/api/plugin/fpp-plugin-watcher/connectivity/state", [], true, 5);
    if ($result === false) return apiError('Failed to fetch connectivity state from remote host');
    /** @disregard P1010 */
    return json($result);
}

// POST /api/plugin/fpp-plugin-watcher/remote/connectivity/state/clear
function fpppluginWatcherRemoteConnectivityStateClear() {
    $input = getJsonBody();
    if (!isset($input['host'])) return apiError('Missing host parameter');

    $host = trim($input['host']);
    $result = apiCall('POST', "http://{$host}/api/plugin/fpp-plugin-watcher/connectivity/state/clear", [], true, 10);
    if ($result === false) return apiError('Failed to clear connectivity state on remote host');
    /** @disregard P1010 */
    return json($result);
}

// GET /api/plugin/fpp-plugin-watcher/outputs/discrepancies
// Compare local universe outputs with remote systems
function fpppluginWatcherOutputDiscrepancies() {
    /** @disregard P1010 */
    return json(getOutputDiscrepancies());
}

// GET /api/plugin/fpp-plugin-watcher/mqtt/events
function fpppluginWatcherMqttEvents() {
    $hostname = isset($_GET['hostname']) ? trim($_GET['hostname']) : null;
    $eventType = isset($_GET['type']) ? trim($_GET['type']) : null;
    /** @disregard P1010 */
    return json(getMqttEvents(getHoursParam(), $hostname ?: null, $eventType ?: null));
}

// GET /api/plugin/fpp-plugin-watcher/mqtt/stats
function fpppluginWatcherMqttStats() {
    /** @disregard P1010 */
    return json(getMqttEventStats(getHoursParam()));
}

// GET /api/plugin/fpp-plugin-watcher/mqtt/hosts
// Returns list of unique hostnames from MQTT events
function fpppluginWatcherMqttHosts() {
    $hosts = getMqttHostsList();
    /** @disregard P1010 */
    return json([
        'success' => true,
        'count' => count($hosts),
        'hosts' => $hosts
    ]);
}

// GET /api/plugin/fpp-plugin-watcher/ping/check?ips[]=x.x.x.x&ips[]=y.y.y.y
function fpppluginWatcherPingCheck() {
    $ips = isset($_GET['ips']) ? $_GET['ips'] : [];
    if (!is_array($ips) || empty($ips)) return apiError('Missing ips[] parameter');
    if (count($ips) > 50) return apiError('Maximum 50 IPs allowed per request');

    $results = [];
    foreach ($ips as $ip) {
        $ip = trim($ip);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $results[$ip] = ['reachable' => false, 'error' => 'Invalid IP'];
            continue;
        }

        // Single ping with 1 second timeout
        $output = [];
        $returnCode = 0;
        exec("ping -c 1 -W 1 " . escapeshellarg($ip) . " 2>&1", $output, $returnCode);

        $results[$ip] = [
            'reachable' => ($returnCode === 0),
            'latency' => null
        ];

        // Extract latency if successful
        if ($returnCode === 0) {
            foreach ($output as $line) {
                if (preg_match('/time=([0-9.]+)\s*ms/', $line, $matches)) {
                    $results[$ip]['latency'] = floatval($matches[1]);
                    break;
                }
            }
        }
    }

    /** @disregard P1010 */
    return json(['success' => true, 'results' => $results]);
}

// GET /api/plugin/fpp-plugin-watcher/multisync/comparison
// Compare player sync state with all remote systems
function fpppluginWatcherMultiSyncComparison() {
    $result = getSyncComparison();
    /** @disregard P1010 */
    return json($result);
}

// GET /api/plugin/fpp-plugin-watcher/multisync/comparison/host?address=x.x.x.x
function fpppluginWatcherMultiSyncComparisonHost() {
    $address = getRequiredQueryParam('address');
    if (!$address) return apiError('Missing address parameter');
    if (!filter_var($address, FILTER_VALIDATE_IP)) return apiError('Invalid IP address');
    /** @disregard P1010 */
    return json(getSyncComparisonForHost($address));
}

// GET /api/plugin/fpp-plugin-watcher/time
// Returns current Unix timestamp in milliseconds for clock sync measurement
function fpppluginWatcherTime() {
    /** @disregard P1010 */
    return json([
        'success' => true,
        'time_ms' => round(microtime(true) * 1000),
        'time_s' => time()
    ]);
}

// GET /api/plugin/fpp-plugin-watcher/multisync/clock-drift
// Measures system clock drift between player and all remote systems
function fpppluginWatcherMultiSyncClockDrift() {
    $remoteSystems = getMultiSyncRemoteSystems();
    if (empty($remoteSystems)) {
        /** @disregard P1010 */
        return json(['success' => true, 'hosts' => [], 'message' => 'No remote systems']);
    }

    // Get local time at start
    $localTimeStart = microtime(true) * 1000;

    // Parallel fetch from all remotes using curl_multi
    $mh = curl_multi_init();
    $handles = [];
    $timeout = 2;

    foreach ($remoteSystems as $system) {
        $ch = curl_init("http://{$system['address']}/api/plugin/fpp-plugin-watcher/time");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => ['Accept: application/json']
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$system['address']] = [
            'handle' => $ch,
            'hostname' => $system['hostname'],
            'requestStart' => microtime(true) * 1000
        ];
    }

    // Execute all requests in parallel
    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) curl_multi_select($mh);
    } while ($active && $status === CURLM_OK);

    // Get local time at end (for RTT calculation)
    $localTimeEnd = microtime(true) * 1000;

    // Collect results
    $hosts = [];
    foreach ($handles as $address => $info) {
        $ch = $info['handle'];
        $response = curl_multi_getcontent($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);

        $hostResult = [
            'address' => $address,
            'hostname' => $info['hostname'],
            'online' => false,
            'hasPlugin' => false,
            'drift_ms' => null,
            'rtt_ms' => null
        ];

        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if ($data && isset($data['time_ms'])) {
                $hostResult['online'] = true;
                $hostResult['hasPlugin'] = true;

                // RTT in milliseconds
                $rtt = $totalTime * 1000;
                $hostResult['rtt_ms'] = round($rtt, 1);

                // Calculate drift: remote_time - local_time, adjusted for half RTT
                // Positive drift = remote is ahead, negative = remote is behind
                $remoteTime = $data['time_ms'];
                $localTimeMid = $info['requestStart'] + ($rtt / 2);
                $drift = $remoteTime - $localTimeMid;
                $hostResult['drift_ms'] = round($drift);
            }
        } elseif ($httpCode > 0) {
            // Got a response but not our time endpoint (plugin not installed)
            $hostResult['online'] = true;
            $hostResult['hasPlugin'] = false;
        }

        $hosts[] = $hostResult;
    }
    curl_multi_close($mh);

    // Calculate summary stats
    $drifts = array_filter(array_column($hosts, 'drift_ms'), function($v) { return $v !== null; });
    $summary = [
        'hostsChecked' => count($hosts),
        'hostsWithPlugin' => count(array_filter($hosts, function($h) { return $h['hasPlugin']; })),
        'avgDrift' => count($drifts) > 0 ? round(array_sum($drifts) / count($drifts)) : null,
        'maxDrift' => count($drifts) > 0 ? max(array_map('abs', $drifts)) : null
    ];

    /** @disregard P1010 */
    return json([
        'success' => true,
        'localTime' => round($localTimeStart),
        'hosts' => $hosts,
        'summary' => $summary
    ]);
}

// GET /api/plugin/fpp-plugin-watcher/metrics/network-quality/current
// Returns current network quality status for all remotes
function fpppluginWatcherNetworkQualityCurrent() {
    $result = getNetworkQualityStatus();
    /** @disregard P1010 */
    return json($result);
}

// GET /api/plugin/fpp-plugin-watcher/metrics/network-quality/history?hours=X&hostname=Y
function fpppluginWatcherNetworkQualityHistory() {
    $hostname = isset($_GET['hostname']) ? trim($_GET['hostname']) : null;
    /** @disregard P1010 */
    return json(getNetworkQualityHistory(getHoursParam(6), $hostname ?: null));
}

// GET /api/plugin/fpp-plugin-watcher/metrics/network-quality/host?address=X
function fpppluginWatcherNetworkQualityHost() {
    $address = getRequiredQueryParam('address');
    if (!$address) return apiError('Missing address parameter');

    $allStatus = getNetworkQualityStatus();
    if (!$allStatus['success']) {
        /** @disregard P1010 */
        return json($allStatus);
    }

    // Find the host by address
    $hostData = null;
    foreach ($allStatus['hosts'] as $host) {
        if (($host['address'] ?? '') === $address) {
            $hostData = $host;
            break;
        }
    }

    /** @disregard P1010 */
    return json([
        'success' => true,
        'host' => $hostData,
        'message' => $hostData === null ? 'No data found for this host' : null,
        'timestamp' => time()
    ]);
}

// POST /api/plugin/fpp-plugin-watcher/metrics/network-quality/collect
// Manually trigger network quality collection
function fpppluginWatcherNetworkQualityCollect() {
    $result = collectNetworkQualityMetrics();
    /** @disregard P1010 */
    return json($result);
}

// GET /api/plugin/fpp-plugin-watcher/data/stats
// Get statistics about stored data files
function fpppluginWatcherDataStats() {
    $stats = getDataDirectoryStats();
    /** @disregard P1010 */
    return json([
        'success' => true,
        'categories' => $stats
    ]);
}

// GET /api/plugin/fpp-plugin-watcher/data/:category/:filename/tail
// Get the last N lines of a file
function fpppluginWatcherDataTail() {
    $category = params('category');
    $filename = params('filename');
    $lines = isset($_GET['lines']) ? max(1, min(1000, intval($_GET['lines']))) : 100;

    if (empty($category) || empty($filename)) {
        /** @disregard P1010 */
        return json(['success' => false, 'error' => 'Category and filename required']);
    }

    $result = tailDataFile($category, $filename, $lines);
    /** @disregard P1010 */
    return json($result);
}

// DELETE /api/plugin/fpp-plugin-watcher/data/:category
// Clear all data files in a specific category
// URL: /api/plugin/fpp-plugin-watcher/data/{category}
function fpppluginWatcherDataClear() {
    // Use limonade's params() function to get route parameters
    $category = params('category');

    if (empty($category)) {
        /** @disregard P1010 */
        return json([
            'success' => false,
            'error' => 'Category parameter is required'
        ]);
    }

    $result = clearDataCategory($category);
    /** @disregard P1010 */
    return json($result);
}

// DELETE /api/plugin/fpp-plugin-watcher/data/:category/:filename
// Delete a specific file within a category
// URL: /api/plugin/fpp-plugin-watcher/data/{category}/{filename}
function fpppluginWatcherDataClearFile() {
    // Use limonade's params() function to get route parameters
    $category = params('category');
    $filename = params('filename');

    if (empty($category) || empty($filename)) {
        /** @disregard P1010 */
        return json([
            'success' => false,
            'error' => 'Category and filename parameters are required'
        ]);
    }

    $result = clearDataFile($category, $filename);
    /** @disregard P1010 */
    return json($result);
}
