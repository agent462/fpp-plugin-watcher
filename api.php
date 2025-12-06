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

function getEndpointsfpppluginwatcher() {
    $result = array();

    $ep = array(
        'method' => 'GET',
        'endpoint' => 'version',
        'callback' => 'fpppluginWatcherVersion');
    array_push($result, $ep);

    $ep = array(
        'method' => 'GET',
        'endpoint' => 'metrics/ping/raw',
        'callback' => 'fpppluginWatcherPingRaw');
    array_push($result, $ep);

    $ep = array(
        'method' => 'GET',
        'endpoint' => 'metrics/memory/free',
        'callback' => 'fpppluginWatcherMemoryFree');
    array_push($result, $ep);

    $ep = array(
        'method' => 'GET',
        'endpoint' => 'metrics/disk/free',
        'callback' => 'fpppluginWatcherDiskFree');
    array_push($result, $ep);

    $ep = array(
        'method' => 'GET',
        'endpoint' => 'metrics/cpu/average',
        'callback' => 'fpppluginWatcherCPUAverage');
    array_push($result, $ep);

    $ep = array(
        'method' => 'GET',
        'endpoint' => 'metrics/load/average',
        'callback' => 'fpppluginWatcherLoadAverage');
    array_push($result, $ep);

    $ep = array(
        'method' => 'GET',
        'endpoint' => 'metrics/interface/bandwidth',
        'callback' => 'fpppluginWatcherInterfaceBandwidth');
    array_push($result, $ep);

    $ep = array(
        'method' => 'GET',
        'endpoint' => 'metrics/interface/list',
        'callback' => 'fpppluginWatcherInterfaceList');
    array_push($result, $ep);

    $ep = array(
        'method' => 'GET',
        'endpoint' => 'metrics/ping/rollup',
        'callback' => 'fpppluginWatcherPingRollup');
    array_push($result, $ep);

    $ep = array(
        'method' => 'GET',
        'endpoint' => 'metrics/ping/rollup/tiers',
        'callback' => 'fpppluginWatcherPingRollupTiers');
    array_push($result, $ep);

    $ep = array(
        'method' => 'GET',
        'endpoint' => 'metrics/ping/rollup/:tier',
        'callback' => 'fpppluginWatcherPingRollupTier');
    array_push($result, $ep);

    // Multi-sync ping metrics endpoints
    $ep = array(
        'method' => 'GET',
        'endpoint' => 'metrics/multisync/ping/raw',
        'callback' => 'fpppluginWatcherMultiSyncPingRaw');
    array_push($result, $ep);

    $ep = array(
        'method' => 'GET',
        'endpoint' => 'metrics/multisync/ping/rollup',
        'callback' => 'fpppluginWatcherMultiSyncPingRollup');
    array_push($result, $ep);

    $ep = array(
        'method' => 'GET',
        'endpoint' => 'metrics/multisync/ping/rollup/tiers',
        'callback' => 'fpppluginWatcherMultiSyncPingRollupTiers');
    array_push($result, $ep);

    $ep = array(
        'method' => 'GET',
        'endpoint' => 'metrics/multisync/hosts',
        'callback' => 'fpppluginWatcherMultiSyncHosts');
    array_push($result, $ep);

    $ep = array(
        'method' => 'GET',
        'endpoint' => 'metrics/thermal',
        'callback' => 'fpppluginWatcherThermal');
    array_push($result, $ep);

    $ep = array(
        'method' => 'GET',
        'endpoint' => 'metrics/thermal/zones',
        'callback' => 'fpppluginWatcherThermalZones');
    array_push($result, $ep);

    $ep = array(
        'method' => 'GET',
        'endpoint' => 'metrics/wireless',
        'callback' => 'fpppluginWatcherWireless');
    array_push($result, $ep);

    $ep = array(
        'method' => 'GET',
        'endpoint' => 'metrics/wireless/interfaces',
        'callback' => 'fpppluginWatcherWirelessInterfaces');
    array_push($result, $ep);

    $ep = array(
        'method' => 'GET',
        'endpoint' => 'metrics/all',
        'callback' => 'fpppluginWatcherMetricsAll');
    array_push($result, $ep);

    // Falcon Controller endpoints
    $ep = array(
        'method' => 'GET',
        'endpoint' => 'falcon/status',
        'callback' => 'fpppluginWatcherFalconStatus');
    array_push($result, $ep);

    $ep = array(
        'method' => 'POST',
        'endpoint' => 'falcon/config',
        'callback' => 'fpppluginWatcherFalconConfigSave');
    array_push($result, $ep);

    $ep = array(
        'method' => 'GET',
        'endpoint' => 'falcon/config',
        'callback' => 'fpppluginWatcherFalconConfigGet');
    array_push($result, $ep);

    $ep = array(
        'method' => 'POST',
        'endpoint' => 'falcon/test',
        'callback' => 'fpppluginWatcherFalconTest');
    array_push($result, $ep);

    $ep = array(
        'method' => 'POST',
        'endpoint' => 'falcon/reboot',
        'callback' => 'fpppluginWatcherFalconReboot');
    array_push($result, $ep);

    $ep = array(
        'method' => 'GET',
        'endpoint' => 'falcon/discover',
        'callback' => 'fpppluginWatcherFalconDiscover');
    array_push($result, $ep);

    // Remote control proxy endpoints
    $ep = array(
        'method' => 'GET',
        'endpoint' => 'remote/status',
        'callback' => 'fpppluginWatcherRemoteStatus');
    array_push($result, $ep);

    $ep = array(
        'method' => 'POST',
        'endpoint' => 'remote/command',
        'callback' => 'fpppluginWatcherRemoteCommand');
    array_push($result, $ep);

    $ep = array(
        'method' => 'POST',
        'endpoint' => 'remote/restart',
        'callback' => 'fpppluginWatcherRemoteRestart');
    array_push($result, $ep);

    $ep = array(
        'method' => 'POST',
        'endpoint' => 'remote/reboot',
        'callback' => 'fpppluginWatcherRemoteReboot');
    array_push($result, $ep);

    $ep = array(
        'method' => 'POST',
        'endpoint' => 'remote/upgrade',
        'callback' => 'fpppluginWatcherRemoteUpgrade');
    array_push($result, $ep);

    $ep = array(
        'method' => 'GET',
        'endpoint' => 'remote/plugins',
        'callback' => 'fpppluginWatcherRemotePlugins');
    array_push($result, $ep);

    $ep = array(
        'method' => 'GET',
        'endpoint' => 'remote/plugins/updates',
        'callback' => 'fpppluginWatcherRemotePluginUpdates');
    array_push($result, $ep);

    $ep = array(
        'method' => 'GET',
        'endpoint' => 'remote/playback/sync',
        'callback' => 'fpppluginWatcherRemotePlaybackSync');
    array_push($result, $ep);

    // Plugin update endpoints
    $ep = array(
        'method' => 'GET',
        'endpoint' => 'update/check',
        'callback' => 'fpppluginWatcherUpdateCheck');
    array_push($result, $ep);

    // FPP release upgrade check
    $ep = array(
        'method' => 'GET',
        'endpoint' => 'fpp/release',
        'callback' => 'fpppluginWatcherFPPRelease');
    array_push($result, $ep);

    // Local plugin updates check (for localhost card)
    $ep = array(
        'method' => 'GET',
        'endpoint' => 'plugins/updates',
        'callback' => 'fpppluginWatcherLocalPluginUpdates');
    array_push($result, $ep);

    // FPP upgrade endpoint (streaming)
    $ep = array(
        'method' => 'POST',
        'endpoint' => 'remote/fpp/upgrade',
        'callback' => 'fpppluginWatcherRemoteFPPUpgrade');
    array_push($result, $ep);

    // Connectivity state endpoints (local)
    $ep = array(
        'method' => 'GET',
        'endpoint' => 'connectivity/state',
        'callback' => 'fpppluginWatcherConnectivityState');
    array_push($result, $ep);

    $ep = array(
        'method' => 'POST',
        'endpoint' => 'connectivity/state/clear',
        'callback' => 'fpppluginWatcherConnectivityStateClear');
    array_push($result, $ep);

    // Connectivity state endpoints (remote proxy)
    $ep = array(
        'method' => 'GET',
        'endpoint' => 'remote/connectivity/state',
        'callback' => 'fpppluginWatcherRemoteConnectivityState');
    array_push($result, $ep);

    $ep = array(
        'method' => 'POST',
        'endpoint' => 'remote/connectivity/state/clear',
        'callback' => 'fpppluginWatcherRemoteConnectivityStateClear');
    array_push($result, $ep);

    // Output configuration discrepancy check
    $ep = array(
        'method' => 'GET',
        'endpoint' => 'outputs/discrepancies',
        'callback' => 'fpppluginWatcherOutputDiscrepancies');
    array_push($result, $ep);

    // Quick ping check for multiple hosts (used for bridge nodes)
    $ep = array(
        'method' => 'GET',
        'endpoint' => 'ping/check',
        'callback' => 'fpppluginWatcherPingCheck');
    array_push($result, $ep);

    // MQTT event monitoring endpoints
    $ep = array(
        'method' => 'GET',
        'endpoint' => 'mqtt/events',
        'callback' => 'fpppluginWatcherMqttEvents');
    array_push($result, $ep);

    $ep = array(
        'method' => 'GET',
        'endpoint' => 'mqtt/stats',
        'callback' => 'fpppluginWatcherMqttStats');
    array_push($result, $ep);

    $ep = array(
        'method' => 'GET',
        'endpoint' => 'mqtt/hosts',
        'callback' => 'fpppluginWatcherMqttHosts');
    array_push($result, $ep);

    // MultiSync comparison endpoints (player vs remote sync state)
    $ep = array(
        'method' => 'GET',
        'endpoint' => 'multisync/comparison',
        'callback' => 'fpppluginWatcherMultiSyncComparison');
    array_push($result, $ep);

    $ep = array(
        'method' => 'GET',
        'endpoint' => 'multisync/comparison/host',
        'callback' => 'fpppluginWatcherMultiSyncComparisonHost');
    array_push($result, $ep);

    // Network quality metrics endpoints
    $ep = array(
        'method' => 'GET',
        'endpoint' => 'metrics/network-quality/current',
        'callback' => 'fpppluginWatcherNetworkQualityCurrent');
    array_push($result, $ep);

    $ep = array(
        'method' => 'GET',
        'endpoint' => 'metrics/network-quality/history',
        'callback' => 'fpppluginWatcherNetworkQualityHistory');
    array_push($result, $ep);

    $ep = array(
        'method' => 'GET',
        'endpoint' => 'metrics/network-quality/host',
        'callback' => 'fpppluginWatcherNetworkQualityHost');
    array_push($result, $ep);

    $ep = array(
        'method' => 'POST',
        'endpoint' => 'metrics/network-quality/collect',
        'callback' => 'fpppluginWatcherNetworkQualityCollect');
    array_push($result, $ep);

    return $result;
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
    $hoursBack = isset($_GET['hours']) ? intval($_GET['hours']) : 24;
    $result = getPingMetrics($hoursBack);
    /** @disregard P1010 */
    return json($result);
}

// GET /api/plugin/fpp-plugin-watcher/metrics/memory/free
function fpppluginwatcherMemoryFree() {
    $hoursBack = isset($_GET['hours']) ? intval($_GET['hours']) : 24;
    $result = getMemoryFreeMetrics($hoursBack);
    /** @disregard P1010 */
    return json($result);
}

// GET /api/plugin/fpp-plugin-watcher/metrics/disk/free
function fpppluginwatcherDiskFree() {
    $hoursBack = isset($_GET['hours']) ? intval($_GET['hours']) : 24;
    $result = getDiskFreeMetrics($hoursBack);
    /** @disregard P1010 */
    return json($result);
}

// GET /api/plugin/fpp-plugin-watcher/metrics/cpu/average
function fpppluginwatcherCPUAverage() {
    $hoursBack = isset($_GET['hours']) ? intval($_GET['hours']) : 24;
    $result = getCPUAverageMetrics($hoursBack);
    /** @disregard P1010 */
    return json($result);
}

// GET /api/plugin/fpp-plugin-watcher/metrics/load/average
function fpppluginwatcherLoadAverage() {
    $hoursBack = isset($_GET['hours']) ? intval($_GET['hours']) : 24;
    $result = getLoadAverageMetrics($hoursBack);
    /** @disregard P1010 */
    return json($result);
}

// GET /api/plugin/fpp-plugin-watcher/metrics/interface/bandwidth
function fpppluginwatcherInterfaceBandwidth() {
    $interface = isset($_GET['interface']) ? $_GET['interface'] : 'eth0';
    $hoursBack = isset($_GET['hours']) ? intval($_GET['hours']) : 24;
    $result = getInterfaceBandwidthMetrics($interface, $hoursBack);
    /** @disregard P1010 */
    return json($result);
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
// Returns ping metrics rollup with automatic tier selection based on time range
function fpppluginwatcherPingRollup() {
    $hoursBack = isset($_GET['hours']) ? intval($_GET['hours']) : 24;
    $result = getPingMetricsRollup($hoursBack);
    /** @disregard P1010 */
    return json($result);
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
// Returns ping metrics for a specific rollup tier
function fpppluginwatcherPingRollupTier() {
    global $urlParts;

    // Extract tier from URL (e.g., /metrics/ping/rollup/1min)
    $tier = isset($urlParts[5]) ? $urlParts[5] : '1min';

    $hoursBack = isset($_GET['hours']) ? intval($_GET['hours']) : 24;
    $endTime = time();
    $startTime = $endTime - ($hoursBack * 3600);

    $result = readRollupData($tier, $startTime, $endTime);
    /** @disregard P1010 */
    return json($result);
}

// GET /api/plugin/fpp-plugin-watcher/metrics/multisync/ping/raw
// Returns raw multi-sync ping metrics
function fpppluginWatcherMultiSyncPingRaw() {
    $hoursBack = isset($_GET['hours']) ? intval($_GET['hours']) : 24;
    $hostname = isset($_GET['hostname']) ? $_GET['hostname'] : null;
    $result = getRawMultiSyncPingMetrics($hoursBack, $hostname);
    /** @disregard P1010 */
    return json($result);
}

// GET /api/plugin/fpp-plugin-watcher/metrics/multisync/ping/rollup
// Returns multi-sync ping metrics rollup with automatic tier selection
function fpppluginWatcherMultiSyncPingRollup() {
    $hoursBack = isset($_GET['hours']) ? intval($_GET['hours']) : 24;
    $hostname = isset($_GET['hostname']) ? $_GET['hostname'] : null;

    $result = getMultiSyncPingMetrics($hoursBack, $hostname);
    /** @disregard P1010 */
    return json($result);
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
    $hoursBack = isset($_GET['hours']) ? intval($_GET['hours']) : 24;
    $result = getThermalMetrics($hoursBack);
    /** @disregard P1010 */
    return json($result);
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
    $hoursBack = isset($_GET['hours']) ? intval($_GET['hours']) : 24;
    $result = getWirelessMetrics($hoursBack);
    /** @disregard P1010 */
    return json($result);
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
// Returns all metrics in a single response for better performance
function fpppluginwatcherMetricsAll() {
    $hoursBack = isset($_GET['hours']) ? intval($_GET['hours']) : 24;

    $result = [
        'success' => true,
        'hours' => $hoursBack,
        'cpu' => getCPUAverageMetrics($hoursBack),
        'memory' => getMemoryFreeMetrics($hoursBack),
        'disk' => getDiskFreeMetrics($hoursBack),
        'load' => getLoadAverageMetrics($hoursBack),
        'thermal' => getThermalMetrics($hoursBack),
        'wireless' => getWirelessMetrics($hoursBack),
        'ping' => getPingMetricsRollup($hoursBack)
    ];

    /** @disregard P1010 */
    return json($result);
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

// GET /api/plugin/fpp-plugin-watcher/remote/status?host=192.168.x.x
// Proxy to fetch status from a remote FPP instance
function fpppluginWatcherRemoteStatus() {
    $host = isset($_GET['host']) ? trim($_GET['host']) : '';

    if (empty($host)) {
        /** @disregard P1010 */
        return json(['success' => false, 'error' => 'Missing host parameter']);
    }

    /** @disregard P1010 */
    return json(getRemoteStatus($host));
}

// POST /api/plugin/fpp-plugin-watcher/remote/command
// Proxy to send a command to a remote FPP instance
function fpppluginWatcherRemoteCommand() {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['host'])) {
        /** @disregard P1010 */
        return json(['success' => false, 'error' => 'Missing host parameter']);
    }

    if (!isset($input['command'])) {
        /** @disregard P1010 */
        return json(['success' => false, 'error' => 'Missing command parameter']);
    }

    $result = sendRemoteCommand(
        trim($input['host']),
        $input['command'],
        $input['args'] ?? [],
        $input['multisyncCommand'] ?? false,
        $input['multisyncHosts'] ?? ''
    );

    /** @disregard P1010 */
    return json($result);
}

// POST /api/plugin/fpp-plugin-watcher/remote/restart
// Proxy to restart fppd on a remote FPP instance
function fpppluginWatcherRemoteRestart() {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['host'])) {
        /** @disregard P1010 */
        return json(['success' => false, 'error' => 'Missing host parameter']);
    }

    /** @disregard P1010 */
    return json(restartRemoteFPPD(trim($input['host'])));
}

// POST /api/plugin/fpp-plugin-watcher/remote/reboot
// Proxy to reboot a remote FPP instance
function fpppluginWatcherRemoteReboot() {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['host'])) {
        /** @disregard P1010 */
        return json(['success' => false, 'error' => 'Missing host parameter']);
    }

    /** @disregard P1010 */
    return json(rebootRemoteFPP(trim($input['host'])));
}

// POST /api/plugin/fpp-plugin-watcher/remote/upgrade
// Proxy to upgrade any plugin on a remote FPP instance
function fpppluginWatcherRemoteUpgrade() {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['host'])) {
        /** @disregard P1010 */
        return json(['success' => false, 'error' => 'Missing host parameter']);
    }

    $plugin = isset($input['plugin']) ? trim($input['plugin']) : null;

    /** @disregard P1010 */
    return json(upgradeRemotePlugin(trim($input['host']), $plugin));
}

// GET /api/plugin/fpp-plugin-watcher/remote/plugins?host=x
// Get list of installed plugins on a remote FPP instance
function fpppluginWatcherRemotePlugins() {
    $host = isset($_GET['host']) ? trim($_GET['host']) : '';

    if (empty($host)) {
        /** @disregard P1010 */
        return json(['success' => false, 'error' => 'Missing host parameter']);
    }

    /** @disregard P1010 */
    return json(getRemotePlugins($host));
}

// GET /api/plugin/fpp-plugin-watcher/remote/plugins/updates?host=x
// Check for updates on all installed plugins on a remote FPP instance
function fpppluginWatcherRemotePluginUpdates() {
    $host = isset($_GET['host']) ? trim($_GET['host']) : '';

    if (empty($host)) {
        /** @disregard P1010 */
        return json(['success' => false, 'error' => 'Missing host parameter']);
    }

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
// Proxy to fetch connectivity state from a remote FPP instance
function fpppluginWatcherRemoteConnectivityState() {
    $host = isset($_GET['host']) ? trim($_GET['host']) : '';

    if (empty($host)) {
        /** @disregard P1010 */
        return json(['success' => false, 'error' => 'Missing host parameter']);
    }

    $result = apiCall('GET', "http://{$host}/api/plugin/fpp-plugin-watcher/connectivity/state", [], true, 5);

    if ($result === false) {
        /** @disregard P1010 */
        return json(['success' => false, 'error' => 'Failed to fetch connectivity state from remote host']);
    }

    /** @disregard P1010 */
    return json($result);
}

// POST /api/plugin/fpp-plugin-watcher/remote/connectivity/state/clear
// Proxy to clear connectivity state on a remote FPP instance
function fpppluginWatcherRemoteConnectivityStateClear() {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['host'])) {
        /** @disregard P1010 */
        return json(['success' => false, 'error' => 'Missing host parameter']);
    }

    $host = trim($input['host']);
    $result = apiCall('POST', "http://{$host}/api/plugin/fpp-plugin-watcher/connectivity/state/clear", [], true, 10);

    if ($result === false) {
        /** @disregard P1010 */
        return json(['success' => false, 'error' => 'Failed to clear connectivity state on remote host']);
    }

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
// Returns MQTT events with optional filters
function fpppluginWatcherMqttEvents() {
    $hoursBack = isset($_GET['hours']) ? intval($_GET['hours']) : 24;
    $hostname = isset($_GET['hostname']) ? trim($_GET['hostname']) : null;
    $eventType = isset($_GET['type']) ? trim($_GET['type']) : null;

    $result = getMqttEvents($hoursBack, $hostname ?: null, $eventType ?: null);
    /** @disregard P1010 */
    return json($result);
}

// GET /api/plugin/fpp-plugin-watcher/mqtt/stats
// Returns aggregated MQTT event statistics
function fpppluginWatcherMqttStats() {
    $hoursBack = isset($_GET['hours']) ? intval($_GET['hours']) : 24;

    $result = getMqttEventStats($hoursBack);
    /** @disregard P1010 */
    return json($result);
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
// Quick ping check for multiple hosts (single packet, 1 second timeout)
function fpppluginWatcherPingCheck() {
    $ips = isset($_GET['ips']) ? $_GET['ips'] : [];

    if (!is_array($ips) || empty($ips)) {
        /** @disregard P1010 */
        return json(['success' => false, 'error' => 'Missing ips[] parameter']);
    }

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
// Compare player sync state with a specific remote
function fpppluginWatcherMultiSyncComparisonHost() {
    $address = isset($_GET['address']) ? trim($_GET['address']) : '';

    if (empty($address)) {
        /** @disregard P1010 */
        return json(['success' => false, 'error' => 'Missing address parameter']);
    }

    if (!filter_var($address, FILTER_VALIDATE_IP)) {
        /** @disregard P1010 */
        return json(['success' => false, 'error' => 'Invalid IP address']);
    }

    $result = getSyncComparisonForHost($address);
    /** @disregard P1010 */
    return json($result);
}

// GET /api/plugin/fpp-plugin-watcher/metrics/network-quality/current
// Returns current network quality status for all remotes
function fpppluginWatcherNetworkQualityCurrent() {
    $result = getNetworkQualityStatus();
    /** @disregard P1010 */
    return json($result);
}

// GET /api/plugin/fpp-plugin-watcher/metrics/network-quality/history?hours=X&hostname=Y
// Returns network quality history for charting
function fpppluginWatcherNetworkQualityHistory() {
    $hoursBack = isset($_GET['hours']) ? intval($_GET['hours']) : 6;
    $hostname = isset($_GET['hostname']) ? trim($_GET['hostname']) : null;

    $result = getNetworkQualityHistory($hoursBack, $hostname ?: null);
    /** @disregard P1010 */
    return json($result);
}

// GET /api/plugin/fpp-plugin-watcher/metrics/network-quality/host?address=X
// Returns network quality metrics for a specific host
function fpppluginWatcherNetworkQualityHost() {
    $address = isset($_GET['address']) ? trim($_GET['address']) : '';

    if (empty($address)) {
        /** @disregard P1010 */
        return json(['success' => false, 'error' => 'Missing address parameter']);
    }

    // Get all metrics and filter by address
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

    if ($hostData === null) {
        /** @disregard P1010 */
        return json([
            'success' => true,
            'host' => null,
            'message' => 'No data found for this host'
        ]);
    }

    /** @disregard P1010 */
    return json([
        'success' => true,
        'host' => $hostData,
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
