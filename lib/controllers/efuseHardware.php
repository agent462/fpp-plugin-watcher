<?php
/**
 * eFuse Hardware Detection and Reading Library
 *
 * Detects compatible eFuse hardware and provides methods to read current values.
 * Supports:
 * - BBB/PB capes with eFuse (PB2, PB16, F8-PB, etc.)
 * - Pi hats with current sensing
 * - Falcon smart receivers
 *
 * @package fpp-plugin-watcher
 */

include_once __DIR__ . '/../core/watcherCommon.php';

// Cache for hardware detection results (file-based for persistence across requests)
define('EFUSE_HARDWARE_CACHE_TTL', 3600); // 1 hour - hardware doesn't change often
define('EFUSE_HARDWARE_CACHE_FILE', WATCHEREFUSEDIR . '/hardware-cache.json');

// Hardware timing constants
define('EFUSE_RESET_DELAY_US', 100000); // 100ms delay for fuse reset (hardware-dependent)

// FPP config file locations
define('FPP_TMP_DIR', '/home/fpp/media/tmp');
define('FPP_CONFIG_DIR', '/home/fpp/media/config');
define('FPP_CAPES_DIR', '/opt/fpp/capes');

/**
 * Detect eFuse-capable hardware on this system
 * Uses file-based caching and ONLY reads config files - never calls APIs or i2cdetect
 *
 * @param bool $forceRefresh Force a fresh detection, ignoring cache
 * @return array ['supported' => bool, 'type' => string, 'ports' => int, 'details' => array]
 */
function detectEfuseHardware($forceRefresh = false) {
    // Try to read from file cache first (fast path)
    if (!$forceRefresh && file_exists(EFUSE_HARDWARE_CACHE_FILE)) {
        $cacheData = @json_decode(file_get_contents(EFUSE_HARDWARE_CACHE_FILE), true);
        if ($cacheData && isset($cacheData['timestamp']) && isset($cacheData['result'])) {
            if ((time() - $cacheData['timestamp']) < EFUSE_HARDWARE_CACHE_TTL) {
                return $cacheData['result'];
            }
        }
    }

    $result = [
        'supported' => false,
        'type' => 'none',
        'ports' => 0,
        'details' => []
    ];

    // Check in order of priority (all are fast file reads)

    // 1. Check for BBB/PB/Pi capes with eFuse support via cape-info.json + string configs
    $capeResult = detectCapeEfuse();
    if ($capeResult['supported']) {
        saveEfuseHardwareCache($capeResult);
        return $capeResult;
    }

    // 2. Check for smart receivers via channel output config files (no API)
    $smartResult = detectSmartReceiversFromConfig();
    if ($smartResult['supported']) {
        saveEfuseHardwareCache($smartResult);
        return $smartResult;
    }

    // No hardware found - cache this result too
    saveEfuseHardwareCache($result);
    return $result;
}

/**
 * Save hardware detection result to file cache
 */
function saveEfuseHardwareCache($result) {
    // Ensure directory exists
    if (!is_dir(WATCHEREFUSEDIR)) {
        @mkdir(WATCHEREFUSEDIR, 0755, true);
    }

    $cacheData = [
        'timestamp' => time(),
        'result' => $result
    ];

    @file_put_contents(EFUSE_HARDWARE_CACHE_FILE, json_encode($cacheData));
    @chown(EFUSE_HARDWARE_CACHE_FILE, 'fpp');
    @chgrp(EFUSE_HARDWARE_CACHE_FILE, 'fpp');
}

/**
 * Detect cape-based eFuse support by reading FPP config files
 * Checks cape-info.json "provides" array and string config files for eFuse pin definitions
 *
 * @return array Detection result
 */
function detectCapeEfuse() {
    $result = [
        'supported' => false,
        'type' => 'cape',
        'ports' => 0,
        'details' => []
    ];

    // Step 1: Check for cape-info.json (FPP extracts this from EEPROM on boot)
    $capeInfoFile = FPP_TMP_DIR . '/cape-info.json';
    $capeInfo = null;

    if (file_exists($capeInfoFile)) {
        $capeInfo = @json_decode(file_get_contents($capeInfoFile), true);
    }

    // Step 2: Check if cape explicitly provides currentMonitoring
    // This is the most reliable indicator - cape declares its capabilities
    $providesCurrentMonitoring = false;
    if ($capeInfo && isset($capeInfo['provides']) && is_array($capeInfo['provides'])) {
        $providesCurrentMonitoring = in_array('currentMonitoring', $capeInfo['provides']);
    }

    // Step 3: Get the subType from channel output config
    $channelOutputInfo = getChannelOutputConfig();

    // If cape provides currentMonitoring but we have no channel config, still report support
    if ($providesCurrentMonitoring && !$channelOutputInfo) {
        $capeName = $capeInfo['name'] ?? $capeInfo['id'] ?? 'Unknown Cape';
        // Count actual eFuse-capable ports from fppd/ports
        $portCount = countEfusePortsFromFppd();
        if ($portCount === 0) {
            $portCount = 16; // Default for current monitoring capes
        }
        $result['supported'] = true;
        $result['type'] = 'cape';
        $result['ports'] = $portCount;
        $result['details'] = [
            'cape' => $capeName,
            'subType' => $capeInfo['id'] ?? '',
            'outputType' => 'unknown',
            'hasCurrentMonitor' => true,
            'method' => 'cape_provides'
        ];
        return $result;
    }

    if (!$channelOutputInfo) {
        return $result;
    }

    $subType = $channelOutputInfo['subType'] ?? '';
    $outputType = $channelOutputInfo['type'] ?? '';
    $configuredPorts = $channelOutputInfo['outputCount'] ?? 0;

    // If cape provides currentMonitoring, we're done - count eFuse ports from fppd/ports
    if ($providesCurrentMonitoring) {
        $capeName = $capeInfo['name'] ?? $subType;
        // Count actual eFuse-capable ports from fppd/ports (ports with 'ma' field)
        $portCount = countEfusePortsFromFppd();
        if ($portCount === 0) {
            $portCount = 16; // Default for current monitoring capes
        }

        $result['supported'] = true;
        $result['type'] = 'cape';
        $result['ports'] = $portCount;
        $result['details'] = [
            'cape' => $capeName,
            'subType' => $subType,
            'outputType' => $outputType,
            'hasCurrentMonitor' => true,
            'method' => 'cape_provides'
        ];
        return $result;
    }

    // Fallback: Check string config for eFuse pins (for capes without "provides" field)
    if (empty($subType)) {
        return $result;
    }

    $stringConfig = loadStringConfig($subType);
    if (!$stringConfig) {
        return $result;
    }

    // Check if any outputs have eFuse pins, current monitors, or enable pins
    $outputs = $stringConfig['outputs'] ?? [];
    $portsWithEfuse = 0;
    $portsWithEnable = 0;
    $hasCurrentMonitor = false;

    foreach ($outputs as $output) {
        if (isset($output['eFusePin']) || isset($output['currentMonitor'])) {
            $portsWithEfuse++;
            if (isset($output['currentMonitor'])) {
                $hasCurrentMonitor = true;
            }
        }
        if (isset($output['enablePin'])) {
            $portsWithEnable++;
        }
    }

    // Use the larger of efuse ports or enable ports
    $detectedPorts = max($portsWithEfuse, $portsWithEnable);
    if ($detectedPorts == 0) {
        $detectedPorts = count($outputs);
    }

    if ($detectedPorts > 0) {
        $capeName = $capeInfo['name'] ?? $stringConfig['name'] ?? $subType;

        $result['supported'] = true;
        $result['type'] = 'cape';
        $result['ports'] = $detectedPorts;
        $result['details'] = [
            'cape' => $capeName,
            'subType' => $subType,
            'outputType' => $outputType,
            'portsWithEfuse' => $portsWithEfuse,
            'hasCurrentMonitor' => $hasCurrentMonitor,
            'method' => $hasCurrentMonitor ? 'current_monitor' : 'efuse_pin'
        ];
    }

    return $result;
}

/**
 * Get channel output configuration from config files
 *
 * @return array|null Channel output info or null if not found
 */
function getChannelOutputConfig() {
    // Check for BBB strings config
    $configFiles = [
        FPP_CONFIG_DIR . '/co-bbbStrings.json',
        FPP_CONFIG_DIR . '/co-pixelStrings.json',
        FPP_CONFIG_DIR . '/channeloutputs.json'
    ];

    foreach ($configFiles as $configFile) {
        if (!file_exists($configFile)) {
            continue;
        }

        $config = @json_decode(file_get_contents($configFile), true);
        if (!$config || !isset($config['channelOutputs'])) {
            continue;
        }

        foreach ($config['channelOutputs'] as $output) {
            $type = $output['type'] ?? '';

            // Look for string output types that might have eFuse
            // BBShiftString = K16-Max, PB2, etc. with shift register outputs
            if (in_array($type, ['BBB48String', 'BBBSerial', 'BBShiftString', 'RPIWS281X', 'DPIPixels', 'spixels'])) {
                return [
                    'type' => $type,
                    'subType' => $output['subType'] ?? '',
                    'outputCount' => $output['outputCount'] ?? count($output['outputs'] ?? []),
                    'pinoutVersion' => $output['pinoutVersion'] ?? '1.x',
                    'configFile' => basename($configFile)
                ];
            }
        }
    }

    return null;
}

/**
 * Load string configuration for a cape subType
 *
 * @param string $subType The cape subType name
 * @return array|null String config or null if not found
 */
function loadStringConfig($subType) {
    // Priority 1: Check /home/fpp/media/tmp/strings/ (extracted from EEPROM)
    $tmpFile = FPP_TMP_DIR . '/strings/' . $subType . '.json';
    if (file_exists($tmpFile)) {
        $config = @json_decode(file_get_contents($tmpFile), true);
        if ($config) {
            return $config;
        }
    }

    // Priority 2: Check platform-specific capes directory
    $platform = detectPlatform();
    $platformDir = FPP_CAPES_DIR . '/' . $platform . '/strings/' . $subType . '.json';
    if (file_exists($platformDir)) {
        $config = @json_decode(file_get_contents($platformDir), true);
        if ($config) {
            return $config;
        }
    }

    // Priority 3: Check other capes directories
    $capeDirs = ['bbb', 'pb', 'pi', 'virtual'];
    foreach ($capeDirs as $dir) {
        $file = FPP_CAPES_DIR . '/' . $dir . '/strings/' . $subType . '.json';
        if (file_exists($file)) {
            $config = @json_decode(file_get_contents($file), true);
            if ($config) {
                return $config;
            }
        }
    }

    return null;
}

/**
 * Detect platform type
 *
 * @return string Platform directory name
 */
function detectPlatform() {
    if (file_exists('/proc/device-tree/model')) {
        $model = @file_get_contents('/proc/device-tree/model');
        if (stripos($model, 'PocketBeagle') !== false) {
            return 'pb';
        }
        if (stripos($model, 'BeagleBone') !== false) {
            return 'bbb';
        }
        if (stripos($model, 'Raspberry') !== false) {
            return 'pi';
        }
    }
    return 'pi'; // Default to pi
}

/**
 * Detect smart receivers from channel output config files (no API calls)
 *
 * @return array Detection result
 */
function detectSmartReceiversFromConfig() {
    $result = [
        'supported' => false,
        'type' => 'smart_receiver',
        'ports' => 0,
        'details' => []
    ];

    // Check channel output config files
    $configFiles = [
        FPP_CONFIG_DIR . '/co-bbbStrings.json',
        FPP_CONFIG_DIR . '/co-pixelStrings.json'
    ];

    foreach ($configFiles as $configFile) {
        if (!file_exists($configFile)) {
            continue;
        }

        $config = @json_decode(file_get_contents($configFile), true);
        if (!$config || !isset($config['channelOutputs'])) {
            continue;
        }

        $smartReceivers = [];

        foreach ($config['channelOutputs'] as $output) {
            $type = $output['type'] ?? '';

            // Look for string output types that support smart receivers
            if (!in_array($type, ['BBB48String', 'RPIWS281X', 'DPIPixels'])) {
                continue;
            }

            $outputs = $output['outputs'] ?? [];
            foreach ($outputs as $portConfig) {
                $smartRemote = $portConfig['smartRemote'] ?? 0;
                if ($smartRemote > 0) {
                    // Count virtual strings as ports on this smart receiver
                    $virtualStrings = $portConfig['virtualStrings'] ?? [];
                    $activeStrings = 0;
                    foreach ($virtualStrings as $vs) {
                        if (($vs['pixelCount'] ?? 0) > 0) {
                            $activeStrings++;
                        }
                    }

                    if (!isset($smartReceivers[$smartRemote])) {
                        $smartReceivers[$smartRemote] = [
                            'dial' => $smartRemote,
                            'ports' => 0
                        ];
                    }
                    $smartReceivers[$smartRemote]['ports'] += max(1, $activeStrings);
                }
            }
        }

        if (!empty($smartReceivers)) {
            $totalPorts = 0;
            foreach ($smartReceivers as $sr) {
                $totalPorts += $sr['ports'];
            }

            $result['supported'] = true;
            $result['ports'] = $totalPorts;
            $result['details'] = [
                'receivers' => array_values($smartReceivers),
                'count' => count($smartReceivers),
                'method' => 'smart_receiver'
            ];
            return $result;
        }
    }

    return $result;
}

/**
 * Read current eFuse port data
 * For actual current values, we need to read from FPP's fppd/ports endpoint
 * since that's where the real-time current data comes from
 *
 * @return array ['success' => bool, 'timestamp' => int, 'ports' => [portName => mA value]]
 */
function readEfuseData() {
    $hardware = detectEfuseHardware();

    if (!$hardware['supported']) {
        return [
            'success' => false,
            'error' => 'No compatible eFuse hardware detected',
            'ports' => []
        ];
    }

    $method = $hardware['details']['method'] ?? 'unknown';

    // For reading actual current values, we use fppd/ports as it's the only
    // reliable source of real-time current data from FPP's OutputMonitor
    return readEfuseFromFppdPorts();
}

/**
 * Get fppd/ports data with in-request caching
 * Avoids duplicate API calls within the same request
 *
 * @return array|null Ports list or null on error
 */
function getFppdPortsCached() {
    static $cachedPorts = null;
    static $cacheChecked = false;

    if ($cacheChecked) {
        return $cachedPorts;
    }

    $cacheChecked = true;
    $portsData = @file_get_contents('http://127.0.0.1/api/fppd/ports');
    if ($portsData === false) {
        return null;
    }

    $portsList = @json_decode($portsData, true);
    if (!is_array($portsList)) {
        return null;
    }

    $cachedPorts = $portsList;
    return $cachedPorts;
}

/**
 * Count eFuse-capable ports from fppd/ports endpoint
 * Ports with 'ma' field are eFuse-capable
 *
 * @return int Number of eFuse-capable ports
 */
function countEfusePortsFromFppd() {
    $portNames = getEfuseCapablePortNames();
    return count($portNames);
}

/**
 * Get list of port names that have eFuse/current monitoring capability
 * Based on fppd/ports response - only ports with 'ma' field or smart receivers with A-F data
 *
 * @return array List of port names (e.g., ['Port 1', 'Port 2', ...])
 */
function getEfuseCapablePortNames() {
    $portsList = getFppdPortsCached();
    if ($portsList === null) {
        return [];
    }

    $capablePorts = [];
    foreach ($portsList as $port) {
        $name = $port['name'] ?? '';
        if (empty($name)) {
            continue;
        }

        // Check for smart receiver ports - only include if they have actual A-F data
        if (isset($port['smartReceivers']) && $port['smartReceivers']) {
            $hasSubportData = false;
            foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $sub) {
                if (isset($port[$sub]) && isset($port[$sub]['ma'])) {
                    $hasSubportData = true;
                    break;
                }
            }
            if ($hasSubportData) {
                $capablePorts[] = $name;
            }
        } else {
            // Standard port - include if it has 'ma' field (current monitoring)
            if (isset($port['ma'])) {
                $capablePorts[] = $name;
            }
        }
    }

    return $capablePorts;
}

/**
 * Read eFuse data from FPP's fppd/ports endpoint
 * This is the reliable source for real-time current values
 * Always fetches fresh data (no caching) for accurate real-time readings
 *
 * @return array Port data
 */
function readEfuseFromFppdPorts() {
    $ports = [];

    // Always fetch fresh data for real-time current readings
    // Don't use getFppdPortsCached() here - that cache is for short web requests,
    // not for long-running daemons that need fresh data each poll
    $portsData = @file_get_contents('http://127.0.0.1/api/fppd/ports');
    if ($portsData === false) {
        return [
            'success' => false,
            'error' => 'Could not connect to fppd',
            'ports' => []
        ];
    }

    $portsList = @json_decode($portsData, true);
    if (!is_array($portsList)) {
        return [
            'success' => false,
            'error' => 'Invalid response from fppd',
            'ports' => []
        ];
    }

    foreach ($portsList as $port) {
        $name = $port['name'] ?? '';
        if (empty($name)) {
            continue;
        }

        // Check for smart receiver ports (A, B, C, D, E, F subports)
        if (isset($port['smartReceivers']) && $port['smartReceivers']) {
            foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $sub) {
                if (isset($port[$sub]) && isset($port[$sub]['ma'])) {
                    $subName = $name . '-' . $sub;
                    $ma = intval($port[$sub]['ma']);
                    if ($ma > 0) {
                        $ports[$subName] = $ma;
                    }
                }
            }
        } else {
            // Standard port
            $ma = $port['ma'] ?? 0;
            if ($ma > 0) {
                $ports[$name] = intval($ma);
            }
        }
    }

    return [
        'success' => true,
        'timestamp' => time(),
        'ports' => $ports,
        'method' => 'fppd_ports'
    ];
}

/**
 * Get hardware detection summary for display
 *
 * @return array Summary information
 */
function getEfuseHardwareSummary() {
    $hardware = detectEfuseHardware();

    if (!$hardware['supported']) {
        return [
            'supported' => false,
            'message' => 'No compatible eFuse hardware detected',
            'typeLabel' => 'None'
        ];
    }

    // Build human-readable type label
    $typeLabel = $hardware['details']['cape'] ?? ucfirst($hardware['type']);

    return [
        'supported' => true,
        'type' => $hardware['type'],
        'typeLabel' => $typeLabel,
        'ports' => $hardware['ports'],
        'details' => $hardware['details']
    ];
}

/**
 * Clear the hardware detection cache (useful after config changes)
 */
function clearEfuseHardwareCache() {
    if (file_exists(EFUSE_HARDWARE_CACHE_FILE)) {
        @unlink(EFUSE_HARDWARE_CACHE_FILE);
    }
}

// =============================================================================
// EFUSE CONTROL FUNCTIONS
// =============================================================================

/**
 * Toggle a port on/off using FPP's command API
 *
 * @param string $portName Port name (e.g., "Port 1")
 * @param string|null $state "on", "off", or null to toggle
 * @return array ['success' => bool, 'newState' => string, 'message' => string]
 */
function toggleEfusePort($portName, $state = null) {
    // Validate port name
    if (empty($portName) || !preg_match('/^Port \d+(-[A-F])?$/', $portName)) {
        return [
            'success' => false,
            'error' => 'Invalid port name format'
        ];
    }

    // If no state specified, get current state and toggle
    if ($state === null) {
        $currentStatus = getPortStatus($portName);
        if ($currentStatus === null) {
            return [
                'success' => false,
                'error' => 'Could not determine current port state'
            ];
        }
        $state = $currentStatus['enabled'] ? 'off' : 'on';
    }

    // Normalize state - handle booleans and strings
    if ($state === true || $state === 'true' || $state === '1' || $state === 1) {
        $state = 'on';
    } elseif ($state === false || $state === 'false' || $state === '0' || $state === 0) {
        $state = 'off';
    } else {
        $state = strtolower($state);
    }

    if (!in_array($state, ['on', 'off'])) {
        return [
            'success' => false,
            'error' => 'Invalid state. Use "on" or "off"'
        ];
    }

    // Call FPP command API with JSON body
    $url = "http://127.0.0.1/api/command";
    $postData = json_encode([
        'command' => 'Set Port Status',
        'args' => [$portName, $state === 'on']
    ]);

    $response = @file_get_contents($url, false, stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'content' => $postData,
            'timeout' => 5
        ]
    ]));

    if ($response === false) {
        return [
            'success' => false,
            'error' => 'Failed to communicate with FPP'
        ];
    }

    // FPP returns "OK" on success, not JSON
    if (trim($response) !== 'OK') {
        return [
            'success' => false,
            'error' => $response ?: 'Command failed'
        ];
    }

    logEfuseControl('toggle', $portName, $state);

    return [
        'success' => true,
        'port' => $portName,
        'newState' => $state,
        'message' => "{$portName} " . ($state === 'on' ? 'enabled' : 'disabled')
    ];
}

/**
 * Reset a tripped fuse by toggling the port off then on
 *
 * @param string $portName Port name (e.g., "Port 1")
 * @return array ['success' => bool, 'message' => string]
 */
function resetEfusePort($portName) {
    // Validate port name
    if (empty($portName) || !preg_match('/^Port \d+(-[A-F])?$/', $portName)) {
        return [
            'success' => false,
            'error' => 'Invalid port name format'
        ];
    }

    // Check if port is actually tripped
    $status = getPortStatus($portName);
    if ($status === null) {
        return [
            'success' => false,
            'error' => 'Could not get port status'
        ];
    }

    if (!isset($status['fuseTripped']) || !$status['fuseTripped']) {
        return [
            'success' => true,
            'port' => $portName,
            'message' => 'Fuse is not tripped, no reset needed'
        ];
    }

    // Reset by turning off then on
    $offResult = toggleEfusePort($portName, 'off');
    if (!$offResult['success']) {
        return $offResult;
    }

    // Brief delay to allow fuse to reset
    usleep(EFUSE_RESET_DELAY_US);

    $onResult = toggleEfusePort($portName, 'on');
    if (!$onResult['success']) {
        return $onResult;
    }

    logEfuseControl('reset', $portName, 'success');

    return [
        'success' => true,
        'port' => $portName,
        'message' => "{$portName} fuse reset"
    ];
}

/**
 * Set all ports on or off (master control)
 *
 * @param string $state "on" or "off"
 * @return array ['success' => bool, 'portsAffected' => int, 'message' => string]
 */
function setAllEfusePorts($state) {
    $state = strtolower($state);
    if (!in_array($state, ['on', 'off'])) {
        return [
            'success' => false,
            'error' => 'Invalid state. Use "on" or "off"'
        ];
    }

    // Get all eFuse-capable port names
    $portNames = getEfuseCapablePortNames();
    if (empty($portNames)) {
        return [
            'success' => false,
            'error' => 'No eFuse-capable ports found'
        ];
    }

    $successCount = 0;
    $errors = [];

    foreach ($portNames as $portName) {
        $result = toggleEfusePort($portName, $state);
        if ($result['success']) {
            $successCount++;
        } else {
            $errors[] = "{$portName}: " . ($result['error'] ?? 'Unknown error');
        }
    }

    logEfuseControl('master', 'all', $state . " ({$successCount}/" . count($portNames) . ")");

    if ($successCount === 0) {
        return [
            'success' => false,
            'error' => 'Failed to control any ports',
            'details' => $errors
        ];
    }

    return [
        'success' => true,
        'state' => $state,
        'portsAffected' => $successCount,
        'totalPorts' => count($portNames),
        'errors' => $errors,
        'message' => "All ports " . ($state === 'on' ? 'enabled' : 'disabled') .
            ($successCount < count($portNames) ? " ({$successCount}/" . count($portNames) . " succeeded)" : '')
    ];
}

/**
 * Reset all tripped fuses
 *
 * @return array ['success' => bool, 'resetCount' => int, 'ports' => array, 'message' => string]
 */
function resetAllTrippedFuses() {
    // Reuse getTrippedFuses() to find all tripped ports
    $trippedPorts = getTrippedFuses();

    if (empty($trippedPorts)) {
        return [
            'success' => true,
            'resetCount' => 0,
            'ports' => [],
            'message' => 'No tripped fuses found'
        ];
    }

    $resetPorts = [];
    $errors = [];

    foreach ($trippedPorts as $portName) {
        $result = resetEfusePort($portName);
        if ($result['success']) {
            $resetPorts[] = $portName;
        } else {
            $errors[] = "{$portName}: " . ($result['error'] ?? 'Unknown error');
        }
    }

    logEfuseControl('reset-all', implode(',', $resetPorts), count($resetPorts) . ' reset');

    return [
        'success' => count($resetPorts) > 0,
        'resetCount' => count($resetPorts),
        'ports' => $resetPorts,
        'errors' => $errors,
        'message' => count($resetPorts) > 0
            ? "Reset " . count($resetPorts) . " tripped fuse" . (count($resetPorts) !== 1 ? 's' : '')
            : 'Failed to reset any fuses'
    ];
}

/**
 * Get control capabilities for current hardware
 *
 * @return array Capabilities info
 */
function getEfuseControlCapabilities() {
    $hardware = detectEfuseHardware();

    if (!$hardware['supported']) {
        return [
            'success' => true,
            'supported' => false,
            'canToggle' => false,
            'canReset' => false,
            'canMasterControl' => false,
            'isSmartReceiver' => false,
            'hardwareType' => 'none'
        ];
    }

    $isSmartReceiver = $hardware['type'] === 'smart_receiver';
    $method = $hardware['details']['method'] ?? 'unknown';

    return [
        'success' => true,
        'supported' => true,
        'canToggle' => true,
        'canReset' => true,
        'canMasterControl' => true,
        'isSmartReceiver' => $isSmartReceiver,
        'hardwareType' => $hardware['details']['cape'] ?? $hardware['type'],
        'portCount' => $hardware['ports'],
        'method' => $method
    ];
}

/**
 * Get status for a specific port
 *
 * @param string $portName Port name
 * @return array|null Port status or null if not found
 */
function getPortStatus($portName) {
    $portsData = @file_get_contents('http://127.0.0.1/api/fppd/ports');
    if ($portsData === false) {
        return null;
    }

    $portsList = @json_decode($portsData, true);
    if (!is_array($portsList)) {
        return null;
    }

    // Check for smart receiver sub-port (e.g., "Port 1-A")
    $isSubPort = preg_match('/^(Port \d+)-([A-F])$/', $portName, $matches);

    foreach ($portsList as $port) {
        $name = $port['name'] ?? '';

        if ($isSubPort) {
            // Looking for smart receiver sub-port
            if ($name === $matches[1] && isset($port['smartReceivers']) && $port['smartReceivers']) {
                $sub = $matches[2];
                if (isset($port[$sub])) {
                    return [
                        'name' => $portName,
                        'enabled' => $port[$sub]['fuseOn'] ?? $port[$sub]['enabled'] ?? false,
                        'fuseTripped' => $port[$sub]['fuseBlown'] ?? false,
                        'currentMa' => $port[$sub]['ma'] ?? $port[$sub]['current'] ?? 0,
                        'isSmartReceiver' => true
                    ];
                }
            }
        } else {
            // Standard port
            if ($name === $portName) {
                return [
                    'name' => $portName,
                    'enabled' => $port['enabled'] ?? false,
                    'fuseTripped' => isset($port['status']) && $port['status'] === false,
                    'currentMa' => $port['ma'] ?? 0,
                    'isSmartReceiver' => false
                ];
            }
        }
    }

    return null;
}

/**
 * Get all tripped fuses
 *
 * @return array List of tripped port names
 */
function getTrippedFuses() {
    $tripped = [];
    $portsData = @file_get_contents('http://127.0.0.1/api/fppd/ports');
    if ($portsData === false) {
        return $tripped;
    }

    $portsList = @json_decode($portsData, true);
    if (!is_array($portsList)) {
        return $tripped;
    }

    foreach ($portsList as $port) {
        $name = $port['name'] ?? '';
        if (empty($name)) continue;

        if (isset($port['smartReceivers']) && $port['smartReceivers']) {
            foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $sub) {
                if (isset($port[$sub]) && isset($port[$sub]['fuseBlown']) && $port[$sub]['fuseBlown']) {
                    $tripped[] = $name . '-' . $sub;
                }
            }
        } else {
            if (isset($port['status']) && $port['status'] === false) {
                $tripped[] = $name;
            }
        }
    }

    return $tripped;
}

/**
 * Log eFuse control action
 *
 * @param string $action Action type (toggle, reset, master, reset-all)
 * @param string $target Target port or "all"
 * @param string $result Result description
 */
function logEfuseControl($action, $target, $result) {
    $message = sprintf("CONTROL: %s on %s - %s", strtoupper($action), $target, $result);
    logMessage($message, 'efuse');
}
?>
