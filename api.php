<?php
include_once __DIR__ . '/lib/watcherCommon.php';
include_once __DIR__ . '/lib/metrics.php';
include_once __DIR__ . '/lib/pingMetricsRollup.php';
include_once WATCHERPLUGINDIR . 'lib/falconController.php';
include_once WATCHERPLUGINDIR . 'lib/config.php';
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
    // Ensure FalconController class is loaded
    if (!class_exists('FalconController')) {
        require_once WATCHERPLUGINDIR . 'lib/falconController.php';
    }

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

    // Parse comma-separated hosts
    $hosts = array_filter(array_map('trim', explode(',', $hostsString)));
    $controllers = [];

    foreach ($hosts as $host) {
        $controllerData = [
            'host' => $host,
            'online' => false,
            'status' => null,
            'error' => null
        ];

        try {
            $controller = new FalconController($host, 80, 3);

            if ($controller->isReachable()) {
                $status = $controller->getStatus();
                if ($status !== false) {
                    $controllerData['online'] = true;
                    $controllerData['status'] = $status;
                } else {
                    $controllerData['error'] = $controller->getLastError() ?: 'Failed to get status';
                }
            } else {
                $controllerData['error'] = $controller->getLastError() ?: 'Controller not reachable';
            }
        } catch (Exception $e) {
            $controllerData['error'] = $e->getMessage();
        }

        $controllers[] = $controllerData;
    }

    /** @disregard P1010 */
    return json([
        'success' => true,
        'controllers' => $controllers,
        'count' => count($controllers),
        'online' => count(array_filter($controllers, fn($c) => $c['online']))
    ]);
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

    // Validate hosts - should be comma-separated IP addresses or hostnames
    if (!empty($hosts)) {
        $hostList = array_map('trim', explode(',', $hosts));
        foreach ($hostList as $host) {
            // Basic validation - allow IP addresses and hostnames
            if (!filter_var($host, FILTER_VALIDATE_IP) &&
                !preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*$/', $host)) {
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
?>