<?php
include_once __DIR__ . '/lib/watcherCommon.php';
include_once WATCHERPLUGINDIR . '/lib/metrics.php';
include_once WATCHERPLUGINDIR . '/lib/pingMetricsRollup.php';
include_once WATCHERPLUGINDIR . '/lib/multiSyncPingMetrics.php';
include_once WATCHERPLUGINDIR . '/lib/falconController.php';
include_once WATCHERPLUGINDIR . '/lib/config.php';
include_once WATCHERPLUGINDIR . '/lib/updateCheck.php';
include_once WATCHERPLUGINDIR . '/lib/remoteControl.php';
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

    // FPP upgrade endpoint (streaming)
    $ep = array(
        'method' => 'POST',
        'endpoint' => 'remote/fpp/upgrade',
        'callback' => 'fpppluginWatcherRemoteFPPUpgrade');
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

// POST /api/plugin/fpp-plugin-watcher/remote/fpp/upgrade
// Streams the FPP upgrade output from a remote host
function fpppluginWatcherRemoteFPPUpgrade() {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['host'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Missing host parameter']);
        return;
    }

    streamRemoteFPPUpgrade(trim($input['host']));
}
?>