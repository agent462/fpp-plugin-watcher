<?php
/**
 * Watcher Plugin API
 *
 * API Response Contract:
 * ---------------------
 * All endpoints return JSON with consistent structure:
 *
 * Success responses (use apiSuccess()):
 *   - Direct data: {success: true, field1: value, field2: value, ...}
 *   - Wrapped proxy: {success: true, data: {...proxied_response...}}
 *
 * Error responses (use apiError()):
 *   - {success: false, error: "message"}
 *   - HTTP status codes: 400 (bad request), 404 (not found), 500 (server error)
 *
 * Guidelines:
 *   - Local/computed data: spread fields at top level (e.g., {success, version, count, items})
 *   - Proxied remote data: wrap in 'data' key to distinguish plugin wrapper from remote response
 *   - Always use apiSuccess() and apiError() helpers, never raw json()
 */

// Core
include_once __DIR__ . '/lib/core/watcherCommon.php';
include_once WATCHERPLUGINDIR . '/lib/core/config.php';

// Metrics
include_once WATCHERPLUGINDIR . '/lib/metrics/systemMetrics.php';
include_once WATCHERPLUGINDIR . '/lib/metrics/pingMetrics.php';
include_once WATCHERPLUGINDIR . '/lib/metrics/multiSyncPingMetrics.php';
include_once WATCHERPLUGINDIR . '/lib/metrics/networkQualityMetrics.php';
include_once WATCHERPLUGINDIR . '/lib/metrics/efuseMetrics.php';

// Multi-sync
include_once WATCHERPLUGINDIR . '/lib/multisync/syncStatus.php';
include_once WATCHERPLUGINDIR . '/lib/multisync/comparison.php';
include_once WATCHERPLUGINDIR . '/lib/multisync/clockDrift.php';

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
    $body = getJsonBody();
    return isset($body[$field]) ? $body[$field] : null;
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

/**
 * Return standardized API success response
 * @disregard P1010
 */
function apiSuccess($data = []) {
    if (is_array($data) && !isset($data['success'])) {
        $data['success'] = true;
    }
    return json($data);
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

        // Network quality metrics
        ['method' => 'GET', 'endpoint' => 'metrics/network-quality/current', 'callback' => 'fpppluginWatcherNetworkQualityCurrent'],
        ['method' => 'GET', 'endpoint' => 'metrics/network-quality/history', 'callback' => 'fpppluginWatcherNetworkQualityHistory'],
        ['method' => 'GET', 'endpoint' => 'metrics/network-quality/host', 'callback' => 'fpppluginWatcherNetworkQualityHost'],
        ['method' => 'POST', 'endpoint' => 'metrics/network-quality/collect', 'callback' => 'fpppluginWatcherNetworkQualityCollect'],

        // eFuse current monitoring
        ['method' => 'GET', 'endpoint' => 'efuse/supported', 'callback' => 'fpppluginWatcherEfuseSupported'],
        ['method' => 'GET', 'endpoint' => 'efuse/current', 'callback' => 'fpppluginWatcherEfuseCurrent'],
        ['method' => 'GET', 'endpoint' => 'efuse/history', 'callback' => 'fpppluginWatcherEfuseHistory'],
        ['method' => 'GET', 'endpoint' => 'efuse/heatmap', 'callback' => 'fpppluginWatcherEfuseHeatmap'],
        ['method' => 'GET', 'endpoint' => 'efuse/config', 'callback' => 'fpppluginWatcherEfuseConfig'],
        ['method' => 'GET', 'endpoint' => 'efuse/outputs', 'callback' => 'fpppluginWatcherEfuseOutputs'],
        ['method' => 'GET', 'endpoint' => 'efuse/capabilities', 'callback' => 'fpppluginWatcherEfuseCapabilities'],
        ['method' => 'POST', 'endpoint' => 'efuse/port/toggle', 'callback' => 'fpppluginWatcherEfusePortToggle'],
        ['method' => 'POST', 'endpoint' => 'efuse/port/reset', 'callback' => 'fpppluginWatcherEfusePortReset'],
        ['method' => 'POST', 'endpoint' => 'efuse/ports/master', 'callback' => 'fpppluginWatcherEfusePortsMaster'],
        ['method' => 'POST', 'endpoint' => 'efuse/ports/reset-all', 'callback' => 'fpppluginWatcherEfusePortsResetAll'],

        // Falcon controller
        ['method' => 'GET', 'endpoint' => 'falcon/status', 'callback' => 'fpppluginWatcherFalconStatus'],
        ['method' => 'GET', 'endpoint' => 'falcon/config', 'callback' => 'fpppluginWatcherFalconConfigGet'],
        ['method' => 'POST', 'endpoint' => 'falcon/config', 'callback' => 'fpppluginWatcherFalconConfigSave'],
        ['method' => 'POST', 'endpoint' => 'falcon/test', 'callback' => 'fpppluginWatcherFalconTest'],
        ['method' => 'POST', 'endpoint' => 'falcon/reboot', 'callback' => 'fpppluginWatcherFalconReboot'],
        ['method' => 'GET', 'endpoint' => 'falcon/discover', 'callback' => 'fpppluginWatcherFalconDiscover'],

        // Remote systems list (single source of truth for filtered remote list)
        ['method' => 'GET', 'endpoint' => 'remotes', 'callback' => 'fpppluginWatcherRemotes'],

        // Bulk remote endpoints (parallel fetch for all remotes)
        ['method' => 'GET', 'endpoint' => 'remote/bulk/status', 'callback' => 'fpppluginWatcherRemoteBulkStatus'],
        ['method' => 'GET', 'endpoint' => 'remote/bulk/updates', 'callback' => 'fpppluginWatcherRemoteBulkUpdates'],

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
        ['method' => 'POST', 'endpoint' => 'remote/watcher/upgrade', 'callback' => 'fpppluginWatcherRemoteWatcherUpgrade'],
        ['method' => 'GET', 'endpoint' => 'remote/connectivity/state', 'callback' => 'fpppluginWatcherRemoteConnectivityState'],
        ['method' => 'POST', 'endpoint' => 'remote/connectivity/state/clear', 'callback' => 'fpppluginWatcherRemoteConnectivityStateClear'],
        ['method' => 'GET', 'endpoint' => 'remote/version', 'callback' => 'fpppluginWatcherRemoteVersion'],
        ['method' => 'GET', 'endpoint' => 'remote/sysStatus', 'callback' => 'fpppluginWatcherRemoteSysStatus'],
        ['method' => 'GET', 'endpoint' => 'remote/sysInfo', 'callback' => 'fpppluginWatcherRemoteSysInfo'],
        ['method' => 'GET', 'endpoint' => 'remote/metrics/all', 'callback' => 'fpppluginWatcherRemoteMetricsAll'],
        ['method' => 'GET', 'endpoint' => 'remote/fppd/status', 'callback' => 'fpppluginWatcherRemoteFppdStatus'],

        // Plugin updates
        ['method' => 'GET', 'endpoint' => 'update/check', 'callback' => 'fpppluginWatcherUpdateCheck'],
        ['method' => 'GET', 'endpoint' => 'fpp/release', 'callback' => 'fpppluginWatcherFPPRelease'],
        ['method' => 'GET', 'endpoint' => 'plugins/updates', 'callback' => 'fpppluginWatcherLocalPluginUpdates'],

        // Connectivity state (local)
        ['method' => 'GET', 'endpoint' => 'connectivity/state', 'callback' => 'fpppluginWatcherConnectivityState'],
        ['method' => 'POST', 'endpoint' => 'connectivity/state/clear', 'callback' => 'fpppluginWatcherConnectivityStateClear'],
        ['method' => 'POST', 'endpoint' => 'connectivity/reload', 'callback' => 'fpppluginWatcherConnectivityReload'],

        // MultiSync comparison
        ['method' => 'GET', 'endpoint' => 'multisync/comparison', 'callback' => 'fpppluginWatcherMultiSyncComparison'],
        ['method' => 'GET', 'endpoint' => 'multisync/comparison/host', 'callback' => 'fpppluginWatcherMultiSyncComparisonHost'],
        ['method' => 'GET', 'endpoint' => 'multisync/clock-drift', 'callback' => 'fpppluginWatcherMultiSyncClockDrift'],
        ['method' => 'GET', 'endpoint' => 'multisync/full-status', 'callback' => 'fpppluginWatcherMultiSyncFullStatus'],

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

        // Advanced configuration
        ['method' => 'GET', 'endpoint' => 'config/collectd', 'callback' => 'fpppluginWatcherCollectdConfigGet'],
        ['method' => 'GET', 'endpoint' => 'config/watcher', 'callback' => 'fpppluginWatcherConfigGet'],
        ['method' => 'POST', 'endpoint' => 'config/watcher', 'callback' => 'fpppluginWatcherConfigSave'],
    ];
}

// GET /api/plugin/fpp-plugin-watcher/version
function fpppluginwatcherVersion() {
    return apiSuccess(['version' => WATCHERVERSION]);
}

// GET /api/plugin/fpp-plugin-watcher/metrics/ping/raw
function fpppluginWatcherPingRaw() {
    return apiSuccess(getPingMetrics(getHoursParam()));
}

// GET /api/plugin/fpp-plugin-watcher/metrics/memory/free
function fpppluginwatcherMemoryFree() {
    return apiSuccess(getMemoryFreeMetrics(getHoursParam()));
}

// GET /api/plugin/fpp-plugin-watcher/metrics/disk/free
function fpppluginwatcherDiskFree() {
    return apiSuccess(getDiskFreeMetrics(getHoursParam()));
}

// GET /api/plugin/fpp-plugin-watcher/metrics/cpu/average
function fpppluginwatcherCPUAverage() {
    return apiSuccess(getCPUAverageMetrics(getHoursParam()));
}

// GET /api/plugin/fpp-plugin-watcher/metrics/load/average
function fpppluginwatcherLoadAverage() {
    return apiSuccess(getLoadAverageMetrics(getHoursParam()));
}

// GET /api/plugin/fpp-plugin-watcher/metrics/interface/bandwidth
function fpppluginwatcherInterfaceBandwidth() {
    $interface = isset($_GET['interface']) ? $_GET['interface'] : 'eth0';
    if (!preg_match('/^[a-zA-Z0-9\-\.]+$/', $interface)) {
        return apiError('Invalid interface name');
    }
    return apiSuccess(getInterfaceBandwidthMetrics($interface, getHoursParam()));
}

// GET /api/plugin/fpp-plugin-watcher/metrics/interface/list
function fpppluginwatcherInterfaceList() {
    $interfaces = getNetworkInterfaces();
    return apiSuccess([
        'count' => count($interfaces),
        'interfaces' => $interfaces
    ]);
}

// GET /api/plugin/fpp-plugin-watcher/metrics/ping/rollup
function fpppluginwatcherPingRollup() {
    return apiSuccess(getPingMetricsRollup(getHoursParam()));
}

// GET /api/plugin/fpp-plugin-watcher/metrics/ping/rollup/tiers
function fpppluginwatcherPingRollupTiers() {
    return apiSuccess(['tiers' => getRollupTiersInfo()]);
}

// GET /api/plugin/fpp-plugin-watcher/metrics/ping/rollup/:tier
function fpppluginwatcherPingRollupTier() {
    global $urlParts;
    $tier = isset($urlParts[5]) ? $urlParts[5] : '1min';
    $hoursBack = getHoursParam();
    $endTime = time();
    $startTime = $endTime - ($hoursBack * 3600);
    return apiSuccess(readRollupData($tier, $startTime, $endTime));
}

// GET /api/plugin/fpp-plugin-watcher/metrics/multisync/ping/raw
function fpppluginWatcherMultiSyncPingRaw() {
    $hostname = isset($_GET['hostname']) ? $_GET['hostname'] : null;
    return apiSuccess(getRawMultiSyncPingMetrics(getHoursParam(), $hostname));
}

// GET /api/plugin/fpp-plugin-watcher/metrics/multisync/ping/rollup
function fpppluginWatcherMultiSyncPingRollup() {
    $hostname = isset($_GET['hostname']) ? $_GET['hostname'] : null;
    return apiSuccess(getMultiSyncPingMetrics(getHoursParam(), $hostname));
}

// GET /api/plugin/fpp-plugin-watcher/metrics/multisync/ping/rollup/tiers
function fpppluginWatcherMultiSyncPingRollupTiers() {
    return apiSuccess(['tiers' => getMultiSyncRollupTiersInfo()]);
}

// GET /api/plugin/fpp-plugin-watcher/metrics/thermal
function fpppluginwatcherThermal() {
    return apiSuccess(getThermalMetrics(getHoursParam()));
}

// GET /api/plugin/fpp-plugin-watcher/metrics/thermal/zones
function fpppluginwatcherThermalZones() {
    $zones = getThermalZones();
    return apiSuccess([
        'count' => count($zones),
        'zones' => $zones
    ]);
}

// GET /api/plugin/fpp-plugin-watcher/metrics/wireless
function fpppluginwatcherWireless() {
    return apiSuccess(getWirelessMetrics(getHoursParam()));
}

// GET /api/plugin/fpp-plugin-watcher/metrics/wireless/interfaces
function fpppluginwatcherWirelessInterfaces() {
    $interfaces = getWirelessInterfaces();
    return apiSuccess([
        'count' => count($interfaces),
        'interfaces' => $interfaces
    ]);
}

// GET /api/plugin/fpp-plugin-watcher/metrics/all
function fpppluginwatcherMetricsAll() {
    $hours = getHoursParam();
    return apiSuccess([
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
function fpppluginwatcherFalconStatus() {
    $config = readPluginConfig();
    $hostsString = isset($_GET['host']) ? $_GET['host'] : ($config['falconControllers'] ?? '');

    if (empty($hostsString)) {
        return apiSuccess([
            'controllers' => [],
            'message' => 'No Falcon controllers configured'
        ]);
    }

    return apiSuccess(FalconController::getMultiStatus($hostsString));
}

// POST /api/plugin/fpp-plugin-watcher/falcon/config
function fpppluginwatcherFalconConfigSave() {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['hosts'])) {
        return apiError('Missing hosts parameter');
    }

    $hosts = trim($input['hosts']);

    if (!empty($hosts)) {
        $hostList = array_map('trim', explode(',', $hosts));
        foreach ($hostList as $host) {
            if (!FalconController::isValidHost($host)) {
                return apiError("Invalid host: $host");
            }
        }
    }

    /** @disregard P1010 */
    WriteSettingToFile('falconControllers', $hosts, WATCHERPLUGINNAME);

    return apiSuccess([
        'message' => 'Configuration saved',
        'hosts' => $hosts
    ]);
}

// GET /api/plugin/fpp-plugin-watcher/falcon/config
function fpppluginwatcherFalconConfigGet() {
    $config = readPluginConfig();
    return apiSuccess(['hosts' => $config['falconControllers'] ?? '']);
}

// POST /api/plugin/fpp-plugin-watcher/falcon/test
function fpppluginwatcherFalconTest() {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['host'])) {
        return apiError('Missing host parameter');
    }

    $host = trim($input['host']);
    $enable = isset($input['enable']) ? (bool)$input['enable'] : true;
    $testMode = isset($input['testMode']) ? intval($input['testMode']) : 5;

    try {
        $controller = new FalconController($host, 80, 5);

        if (!$controller->isReachable()) {
            return apiError('Controller not reachable');
        }

        if ($enable) {
            $result = $controller->enableTest($testMode);
            $action = 'enabled';
        } else {
            $result = $controller->disableTest();
            $action = 'disabled';
        }

        if ($result) {
            return apiSuccess([
                'message' => "Test mode $action",
                'host' => $host,
                'testEnabled' => $enable,
                'testMode' => $enable ? $testMode : null
            ]);
        } else {
            return apiError($controller->getLastError() ?: 'Failed to change test mode');
        }
    } catch (Exception $e) {
        return apiError($e->getMessage());
    }
}

// POST /api/plugin/fpp-plugin-watcher/falcon/reboot
function fpppluginwatcherFalconReboot() {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['host'])) {
        return apiError('Missing host parameter');
    }

    $host = trim($input['host']);

    try {
        $controller = new FalconController($host, 80, 5);

        if (!$controller->isReachable()) {
            return apiError('Controller not reachable');
        }

        $result = $controller->reboot();

        if ($result) {
            return apiSuccess([
                'message' => 'Reboot command sent',
                'host' => $host
            ]);
        } else {
            return apiError($controller->getLastError() ?: 'Failed to send reboot command');
        }
    } catch (Exception $e) {
        return apiError($e->getMessage());
    }
}

// GET /api/plugin/fpp-plugin-watcher/falcon/discover
function fpppluginwatcherFalconDiscover() {
    $subnet = isset($_GET['subnet']) ? trim($_GET['subnet']) : FalconController::autoDetectSubnet();

    if (empty($subnet)) {
        return apiError('Could not determine subnet. Please provide subnet parameter (e.g., ?subnet=192.168.1)');
    }

    if (!FalconController::isValidSubnet($subnet)) {
        return apiError('Invalid subnet format. Expected format: 192.168.1');
    }

    $startIp = max(1, min(254, isset($_GET['start']) ? intval($_GET['start']) : 1));
    $endIp = max($startIp, min(254, isset($_GET['end']) ? intval($_GET['end']) : 254));
    $timeout = max(1, min(5, isset($_GET['timeout']) ? intval($_GET['timeout']) : 1));

    try {
        $discovered = FalconController::discover($subnet, $startIp, $endIp, $timeout);
        return apiSuccess([
            'subnet' => $subnet,
            'range' => ['start' => $startIp, 'end' => $endIp],
            'count' => count($discovered),
            'controllers' => $discovered
        ]);
    } catch (Exception $e) {
        return apiError($e->getMessage());
    }
}

// GET /api/plugin/fpp-plugin-watcher/remotes
function fpppluginWatcherRemotes() {
    return apiSuccess(['remotes' => getMultiSyncRemoteSystems()]);
}

// GET /api/plugin/fpp-plugin-watcher/remote/bulk/status
function fpppluginWatcherRemoteBulkStatus() {
    return apiSuccess(getBulkRemoteStatus());
}

// GET /api/plugin/fpp-plugin-watcher/remote/bulk/updates
function fpppluginWatcherRemoteBulkUpdates() {
    return apiSuccess(getBulkRemoteUpdates());
}

// GET /api/plugin/fpp-plugin-watcher/remote/status?host=x
function fpppluginWatcherRemoteStatus() {
    $host = getRequiredQueryParam('host');
    if (!$host) return apiError('Missing host parameter');
    return apiSuccess(getRemoteStatus($host));
}

// POST /api/plugin/fpp-plugin-watcher/remote/command
function fpppluginWatcherRemoteCommand() {
    $input = getJsonBody();
    if (!isset($input['host'])) return apiError('Missing host parameter');
    if (!isset($input['command'])) return apiError('Missing command parameter');
    return apiSuccess(sendRemoteCommand(
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
    return apiSuccess(restartRemoteFPPD(trim($input['host'])));
}

// POST /api/plugin/fpp-plugin-watcher/remote/reboot
function fpppluginWatcherRemoteReboot() {
    $input = getJsonBody();
    if (!isset($input['host'])) return apiError('Missing host parameter');
    return apiSuccess(rebootRemoteFPP(trim($input['host'])));
}

// POST /api/plugin/fpp-plugin-watcher/remote/upgrade
function fpppluginWatcherRemoteUpgrade() {
    $input = getJsonBody();
    if (!isset($input['host'])) return apiError('Missing host parameter');
    $plugin = isset($input['plugin']) ? trim($input['plugin']) : null;
    return apiSuccess(upgradeRemotePlugin(trim($input['host']), $plugin));
}

// GET /api/plugin/fpp-plugin-watcher/remote/plugins?host=x
function fpppluginWatcherRemotePlugins() {
    $host = getRequiredQueryParam('host');
    if (!$host) return apiError('Missing host parameter');
    return apiSuccess(getRemotePlugins($host));
}

// GET /api/plugin/fpp-plugin-watcher/remote/plugins/updates?host=x
function fpppluginWatcherRemotePluginUpdates() {
    $host = getRequiredQueryParam('host');
    if (!$host) return apiError('Missing host parameter');
    return apiSuccess(checkRemotePluginUpdates($host));
}

// GET /api/plugin/fpp-plugin-watcher/remote/playback/sync
function fpppluginWatcherRemotePlaybackSync() {
    $remoteSystems = getMultiSyncRemoteSystems();
    if (empty($remoteSystems)) {
        return apiSuccess(['local' => null, 'remotes' => []]);
    }

    $localStatus = apiCall('GET', 'http://127.0.0.1/api/fppd/status', [], true, WATCHER_TIMEOUT_STATUS);
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

    $mh = curl_multi_init();
    $handles = [];

    foreach ($remoteSystems as $system) {
        $ch = createCurlHandle("http://{$system['address']}/api/fppd/status", WATCHER_TIMEOUT_STATUS);
        curl_multi_add_handle($mh, $ch);
        $handles[$system['address']] = ['handle' => $ch, 'hostname' => $system['hostname']];
    }

    executeCurlMulti($mh);

    $remotes = [];
    foreach ($handles as $address => $info) {
        $ch = $info['handle'];
        $response = curl_multi_getcontent($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        cleanupCurlHandle($mh, $ch);

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

    return apiSuccess(['local' => $local, 'remotes' => $remotes]);
}

// GET /api/plugin/fpp-plugin-watcher/update/check
function fpppluginWatcherUpdateCheck() {
    return apiSuccess(checkWatcherUpdate());
}

// GET /api/plugin/fpp-plugin-watcher/fpp/release
function fpppluginWatcherFPPRelease() {
    $currentBranch = isset($_GET['branch']) ? trim($_GET['branch']) : null;

    if (empty($currentBranch)) {
        $systemInfo = apiCall('GET', 'http://127.0.0.1/api/system/info', [], true, WATCHER_TIMEOUT_STANDARD);
        if ($systemInfo && isset($systemInfo['Branch'])) {
            $currentBranch = $systemInfo['Branch'];
        }
    }

    if (empty($currentBranch)) {
        return apiError('Could not determine current FPP branch');
    }

    return apiSuccess(checkFPPReleaseUpgrade($currentBranch));
}

// GET /api/plugin/fpp-plugin-watcher/plugins/updates
function fpppluginWatcherLocalPluginUpdates() {
    return apiSuccess(checkRemotePluginUpdates('127.0.0.1'));
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

// POST /api/plugin/fpp-plugin-watcher/remote/watcher/upgrade
// Streams the Watcher plugin upgrade output from a remote host
function fpppluginWatcherRemoteWatcherUpgrade() {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['host'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Missing host parameter']);
        return;
    }

    streamRemoteWatcherUpgrade(trim($input['host']));
}

// GET /api/plugin/fpp-plugin-watcher/connectivity/state
function fpppluginWatcherConnectivityState() {
    $state = readResetState();

    if ($state === null) {
        return apiSuccess([
            'hasResetAdapter' => false,
            'message' => 'No reset has occurred'
        ]);
    }

    return apiSuccess([
        'hasResetAdapter' => true,
        'resetTimestamp' => $state['resetTimestamp'] ?? null,
        'resetTime' => isset($state['resetTimestamp']) ? date('Y-m-d H:i:s', $state['resetTimestamp']) : null,
        'adapter' => $state['adapter'] ?? null,
        'reason' => $state['reason'] ?? null
    ]);
}

// POST /api/plugin/fpp-plugin-watcher/connectivity/state/clear
function fpppluginWatcherConnectivityStateClear() {
    $cleared = clearResetState();

    if (!$cleared) {
        return apiError('Failed to clear reset state');
    }

    logMessage("Reset state cleared via API, restarting connectivity daemon");
    restartConnectivityDaemon();

    return apiSuccess(['message' => 'Reset state cleared and connectivity daemon restarted']);
}

// POST /api/plugin/fpp-plugin-watcher/connectivity/reload
// Triggers the connectivity daemon to reload its configuration immediately
function fpppluginWatcherConnectivityReload() {
    $configPath = WATCHERCONFIGFILELOCATION;

    if (!file_exists($configPath)) {
        return apiError('Configuration file not found');
    }

    // Touch the config file to update its mtime, triggering daemon reload
    $touched = touch($configPath);

    if (!$touched) {
        return apiError('Failed to trigger configuration reload');
    }

    logMessage("Configuration reload triggered via API");

    return apiSuccess([
        'message' => 'Configuration reload triggered. Daemon will reload within 60 seconds.',
        'configFile' => $configPath,
        'newMtime' => filemtime($configPath)
    ]);
}

// GET /api/plugin/fpp-plugin-watcher/remote/connectivity/state?host=x
function fpppluginWatcherRemoteConnectivityState() {
    $host = getRequiredQueryParam('host');
    if (!$host) return apiError('Missing host parameter');

    $result = apiCall('GET', "http://{$host}/api/plugin/fpp-plugin-watcher/connectivity/state", [], true, WATCHER_TIMEOUT_STANDARD);
    if ($result === false) return apiError('Failed to fetch connectivity state from remote host');
    return apiSuccess($result);
}

// POST /api/plugin/fpp-plugin-watcher/remote/connectivity/state/clear
function fpppluginWatcherRemoteConnectivityStateClear() {
    $input = getJsonBody();
    if (!isset($input['host'])) return apiError('Missing host parameter');

    $host = trim($input['host']);
    $result = apiCall('POST', "http://{$host}/api/plugin/fpp-plugin-watcher/connectivity/state/clear", [], true, WATCHER_TIMEOUT_LONG);
    if ($result === false) return apiError('Failed to clear connectivity state on remote host');
    return apiSuccess($result);
}

// GET /api/plugin/fpp-plugin-watcher/remote/version?host=x
function fpppluginWatcherRemoteVersion() {
    $host = getRequiredQueryParam('host');
    if (!$host) return apiError('Missing host parameter');

    $result = apiCall('GET', "http://{$host}/api/plugin/fpp-plugin-watcher/version", [], true, WATCHER_TIMEOUT_STANDARD);
    if ($result === false) {
        return apiError('Failed to fetch version from remote host');
    }
    return apiSuccess($result);
}

// GET /api/plugin/fpp-plugin-watcher/remote/sysStatus?host=x
function fpppluginWatcherRemoteSysStatus() {
    $host = getRequiredQueryParam('host');
    if (!$host) return apiError('Missing host parameter');

    $result = apiCall('GET', "http://{$host}/api/system/status", [], true, WATCHER_TIMEOUT_STANDARD);
    if ($result === false) {
        return apiError('Failed to fetch system status from remote host');
    }
    return apiSuccess(['data' => $result]);
}

// GET /api/plugin/fpp-plugin-watcher/remote/sysInfo?host=x
function fpppluginWatcherRemoteSysInfo() {
    $host = getRequiredQueryParam('host');
    if (!$host) return apiError('Missing host parameter');

    $result = apiCall('GET', "http://{$host}/api/system/info", [], true, WATCHER_TIMEOUT_STANDARD);
    if ($result === false) {
        return apiError('Failed to fetch system info from remote host');
    }
    return apiSuccess(['data' => $result]);
}

// GET /api/plugin/fpp-plugin-watcher/remote/metrics/all?host=x&hours=Y
function fpppluginWatcherRemoteMetricsAll() {
    $host = getRequiredQueryParam('host');
    if (!$host) return apiError('Missing host parameter');

    $hours = getHoursParam();
    $result = apiCall('GET', "http://{$host}/api/plugin/fpp-plugin-watcher/metrics/all?hours={$hours}", [], true, WATCHER_TIMEOUT_LONG);
    if ($result === false) {
        return apiError('Failed to fetch metrics from remote host');
    }
    return apiSuccess($result);
}

// GET /api/plugin/fpp-plugin-watcher/remote/fppd/status?host=x
function fpppluginWatcherRemoteFppdStatus() {
    $host = getRequiredQueryParam('host');
    if (!$host) return apiError('Missing host parameter');

    $result = apiCall('GET', "http://{$host}/api/fppd/status", [], true, WATCHER_TIMEOUT_STANDARD);
    if ($result === false) {
        return apiError('Failed to fetch fppd status from remote host');
    }
    return apiSuccess(['data' => $result]);
}

// GET /api/plugin/fpp-plugin-watcher/outputs/discrepancies
function fpppluginWatcherOutputDiscrepancies() {
    return apiSuccess(getOutputDiscrepancies());
}

// GET /api/plugin/fpp-plugin-watcher/mqtt/events
function fpppluginWatcherMqttEvents() {
    $hostname = isset($_GET['hostname']) ? trim($_GET['hostname']) : null;
    $eventType = isset($_GET['type']) ? trim($_GET['type']) : null;
    return apiSuccess(getMqttEvents(getHoursParam(), $hostname ?: null, $eventType ?: null));
}

// GET /api/plugin/fpp-plugin-watcher/mqtt/stats
function fpppluginWatcherMqttStats() {
    return apiSuccess(getMqttEventStats(getHoursParam()));
}

// GET /api/plugin/fpp-plugin-watcher/mqtt/hosts
function fpppluginWatcherMqttHosts() {
    $hosts = getMqttHostsList();
    return apiSuccess([
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

        $pingResult = pingHost($ip, null, 1);

        $results[$ip] = [
            'reachable' => $pingResult['success'],
            'latency' => $pingResult['latency']
        ];
    }

    return apiSuccess(['results' => $results]);
}

// GET /api/plugin/fpp-plugin-watcher/multisync/comparison
function fpppluginWatcherMultiSyncComparison() {
    return apiSuccess(getSyncComparison());
}

// GET /api/plugin/fpp-plugin-watcher/multisync/comparison/host?address=x.x.x.x
function fpppluginWatcherMultiSyncComparisonHost() {
    $address = getRequiredQueryParam('address');
    if (!$address) return apiError('Missing address parameter');
    if (!filter_var($address, FILTER_VALIDATE_IP)) return apiError('Invalid IP address');
    return apiSuccess(getSyncComparisonForHost($address));
}

// GET /api/plugin/fpp-plugin-watcher/time
function fpppluginWatcherTime() {
    return apiSuccess([
        'time_ms' => round(microtime(true) * 1000),
        'time_s' => time()
    ]);
}

// GET /api/plugin/fpp-plugin-watcher/multisync/clock-drift
function fpppluginWatcherMultiSyncClockDrift() {
    return apiSuccess(measureClockDrift());
}

// GET /api/plugin/fpp-plugin-watcher/multisync/full-status
function fpppluginWatcherMultiSyncFullStatus() {
    return apiSuccess(getFullSyncStatus());
}

// GET /api/plugin/fpp-plugin-watcher/metrics/network-quality/current
function fpppluginWatcherNetworkQualityCurrent() {
    return apiSuccess(getNetworkQualityStatus());
}

// GET /api/plugin/fpp-plugin-watcher/metrics/network-quality/history?hours=X&hostname=Y
function fpppluginWatcherNetworkQualityHistory() {
    $hostname = isset($_GET['hostname']) ? trim($_GET['hostname']) : null;
    return apiSuccess(getNetworkQualityHistory(getHoursParam(6), $hostname ?: null));
}

// GET /api/plugin/fpp-plugin-watcher/metrics/network-quality/host?address=X
function fpppluginWatcherNetworkQualityHost() {
    $address = getRequiredQueryParam('address');
    if (!$address) return apiError('Missing address parameter');

    $allStatus = getNetworkQualityStatus();
    if (isset($allStatus['success']) && !$allStatus['success']) {
        return apiSuccess($allStatus);
    }

    $hostData = null;
    foreach (($allStatus['hosts'] ?? []) as $host) {
        if (($host['address'] ?? '') === $address) {
            $hostData = $host;
            break;
        }
    }

    return apiSuccess([
        'host' => $hostData,
        'message' => $hostData === null ? 'No data found for this host' : null,
        'timestamp' => time()
    ]);
}

// POST /api/plugin/fpp-plugin-watcher/metrics/network-quality/collect
function fpppluginWatcherNetworkQualityCollect() {
    return apiSuccess(collectNetworkQualityMetrics());
}

// GET /api/plugin/fpp-plugin-watcher/data/stats
function fpppluginWatcherDataStats() {
    return apiSuccess(['categories' => getDataDirectoryStats()]);
}

// GET /api/plugin/fpp-plugin-watcher/data/:category/:filename/tail
function fpppluginWatcherDataTail() {
    $category = params('category');
    $filename = params('filename');
    $lines = isset($_GET['lines']) ? max(1, min(1000, intval($_GET['lines']))) : 100;

    if (empty($category) || empty($filename)) {
        return apiError('Category and filename required');
    }

    return apiSuccess(tailDataFile($category, $filename, $lines));
}

// DELETE /api/plugin/fpp-plugin-watcher/data/:category
function fpppluginWatcherDataClear() {
    $category = params('category');

    if (empty($category)) {
        return apiError('Category parameter is required');
    }

    return apiSuccess(clearDataCategory($category));
}

// DELETE /api/plugin/fpp-plugin-watcher/data/:category/:filename
function fpppluginWatcherDataClearFile() {
    $category = params('category');
    $filename = params('filename');

    if (empty($category) || empty($filename)) {
        return apiError('Category and filename parameters are required');
    }

    return apiSuccess(clearDataFile($category, $filename));
}

// GET /api/plugin/fpp-plugin-watcher/config/collectd
function fpppluginWatcherCollectdConfigGet() {
    $configPath = WATCHERPLUGINDIR . '/config/collectd.conf';

    if (!file_exists($configPath)) {
        return apiError('Configuration file not found', 404);
    }

    $content = file_get_contents($configPath);
    if ($content === false) {
        return apiError('Failed to read configuration file', 500);
    }

    return apiSuccess([
        'content' => $content,
        'path' => $configPath
    ]);
}

// GET /api/plugin/fpp-plugin-watcher/config/watcher
function fpppluginWatcherConfigGet() {
    $configPath = WATCHERCONFIGFILELOCATION;

    if (!file_exists($configPath)) {
        return apiSuccess([
            'content' => '',
            'path' => $configPath,
            'message' => 'Configuration file does not exist yet'
        ]);
    }

    $content = file_get_contents($configPath);
    if ($content === false) {
        return apiError('Failed to read configuration file', 500);
    }

    return apiSuccess([
        'content' => $content,
        'path' => $configPath
    ]);
}

// POST /api/plugin/fpp-plugin-watcher/config/watcher
function fpppluginWatcherConfigSave() {
    $input = getJsonBody();

    if (!isset($input['content'])) {
        return apiError('Missing content parameter');
    }

    $configPath = WATCHERCONFIGFILELOCATION;
    $content = $input['content'];

    $result = file_put_contents($configPath, $content);
    if ($result === false) {
        return apiError('Failed to write configuration file', 500);
    }

    ensureFppOwnership($configPath);
    logMessage("Watcher configuration updated via API");

    return apiSuccess([
        'message' => 'Configuration saved successfully. Some changes may require FPP restart.',
        'bytesWritten' => $result
    ]);
}

// --- eFuse Monitoring Endpoints ---

// GET /api/plugin/fpp-plugin-watcher/efuse/supported
function fpppluginWatcherEfuseSupported() {
    $hardware = detectEfuseHardware();
    return apiSuccess([
        'supported' => $hardware['supported'],
        'type' => $hardware['type'],
        'ports' => $hardware['ports'],
        'details' => $hardware['details']
    ]);
}

// GET /api/plugin/fpp-plugin-watcher/efuse/current
function fpppluginWatcherEfuseCurrent() {
    $hardware = detectEfuseHardware();
    if (!$hardware['supported']) {
        return apiError('No compatible eFuse hardware detected', 404);
    }

    return apiSuccess(getEfuseCurrentStatus());
}

// GET /api/plugin/fpp-plugin-watcher/efuse/history?port=Port1&hours=24
function fpppluginWatcherEfuseHistory() {
    $hardware = detectEfuseHardware();
    if (!$hardware['supported']) {
        return apiError('No compatible eFuse hardware detected', 404);
    }

    $port = getRequiredQueryParam('port');
    if (!$port) {
        return apiError('Missing port parameter');
    }

    return apiSuccess(getEfusePortHistory($port, getHoursParam()));
}

// GET /api/plugin/fpp-plugin-watcher/efuse/heatmap?hours=24
function fpppluginWatcherEfuseHeatmap() {
    $hardware = detectEfuseHardware();
    if (!$hardware['supported']) {
        return apiError('No compatible eFuse hardware detected', 404);
    }

    return apiSuccess(getEfuseHeatmapData(getHoursParam()));
}

// GET /api/plugin/fpp-plugin-watcher/efuse/config
function fpppluginWatcherEfuseConfig() {
    return apiSuccess(getEfuseHardwareSummary());
}

// GET /api/plugin/fpp-plugin-watcher/efuse/outputs
function fpppluginWatcherEfuseOutputs() {
    $hardware = detectEfuseHardware();
    if (!$hardware['supported']) {
        return apiError('No compatible eFuse hardware detected', 404);
    }

    return apiSuccess(getEfuseOutputConfig());
}

// GET /api/plugin/fpp-plugin-watcher/efuse/capabilities
function fpppluginWatcherEfuseCapabilities() {
    return apiSuccess(getEfuseControlCapabilities());
}

// POST /api/plugin/fpp-plugin-watcher/efuse/port/toggle
function fpppluginWatcherEfusePortToggle() {
    $hardware = detectEfuseHardware();
    if (!$hardware['supported']) {
        return apiError('No compatible eFuse hardware detected', 404);
    }

    $input = getJsonBody();
    $port = $input['port'] ?? null;
    $state = $input['state'] ?? null;

    if (empty($port)) {
        return apiError('Missing port parameter');
    }

    $result = toggleEfusePort($port, $state);
    if (!$result['success']) {
        return apiError($result['error'] ?? 'Toggle failed');
    }

    return apiSuccess($result);
}

// POST /api/plugin/fpp-plugin-watcher/efuse/port/reset
function fpppluginWatcherEfusePortReset() {
    $hardware = detectEfuseHardware();
    if (!$hardware['supported']) {
        return apiError('No compatible eFuse hardware detected', 404);
    }

    $input = getJsonBody();
    $port = $input['port'] ?? null;

    if (empty($port)) {
        return apiError('Missing port parameter');
    }

    $result = resetEfusePort($port);
    if (!$result['success']) {
        return apiError($result['error'] ?? 'Reset failed');
    }

    return apiSuccess($result);
}

// POST /api/plugin/fpp-plugin-watcher/efuse/ports/master
function fpppluginWatcherEfusePortsMaster() {
    $hardware = detectEfuseHardware();
    if (!$hardware['supported']) {
        return apiError('No compatible eFuse hardware detected', 404);
    }

    $input = getJsonBody();
    $state = $input['state'] ?? null;

    if (empty($state) || !in_array($state, ['on', 'off'])) {
        return apiError('Missing or invalid state parameter. Use "on" or "off"');
    }

    $result = setAllEfusePorts($state);
    if (!$result['success']) {
        return apiError($result['error'] ?? 'Master control failed');
    }

    return apiSuccess($result);
}

// POST /api/plugin/fpp-plugin-watcher/efuse/ports/reset-all
function fpppluginWatcherEfusePortsResetAll() {
    $hardware = detectEfuseHardware();
    if (!$hardware['supported']) {
        return apiError('No compatible eFuse hardware detected', 404);
    }

    $result = resetAllTrippedFuses();
    return apiSuccess($result);
}
