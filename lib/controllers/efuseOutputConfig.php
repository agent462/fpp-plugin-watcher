<?php
/**
 * eFuse Output Configuration Library
 *
 * Maps FPP channel output configuration to eFuse ports and provides
 * expected current calculations based on pixel count and protocol.
 *
 * @package fpp-plugin-watcher
 */

include_once __DIR__ . '/../core/watcherCommon.php';

// Typical per-pixel current (mA) at full white by protocol
// WS2811 assumes 12V (~0.5W = 42mA), others assume 5V (~0.3W = 60mA)
define('EFUSE_PIXEL_CURRENT_ESTIMATES', [
    'ws2811'  => 42,   // 12V: ~0.5W / 12V = 42mA
    'ws2812'  => 60,   // 5V: 20mA per color x 3
    'ws2812b' => 60,
    'sk6812'  => 60,
    'sk6812w' => 80,   // RGBW - 4 channels
    'apa102'  => 60,
    'apa104'  => 60,
    'tm1814'  => 80,   // RGBW - 4 channels
    'tm1829'  => 60,
    'ucs8903' => 60,
    'ucs8904' => 80,   // RGBW
    'default' => 50    // Conservative middle estimate
]);

// Maximum eFuse rating in mA
define('EFUSE_MAX_CURRENT_MA', 6000);

// Cache for output config
$_efuseOutputConfigCache = null;
$_efuseOutputConfigCacheTime = 0;
define('EFUSE_OUTPUT_CONFIG_CACHE_TTL', 60); // 1 minute

/**
 * Extract virtual string configuration from a port config
 *
 * @param array $portConfig Port configuration array
 * @param bool $usePortFallback Use port-level config as fallback when no virtual strings
 * @return array ['totalPixels' => int, 'protocol' => string, 'brightness' => int, 'descriptions' => array]
 */
function extractVirtualStringConfig($portConfig, $usePortFallback = false) {
    $virtualStrings = $portConfig['virtualStrings'] ?? [];
    $totalPixels = 0;
    $protocol = 'ws2811';
    $brightness = 100;
    $descriptions = [];

    if (!empty($virtualStrings)) {
        foreach ($virtualStrings as $vs) {
            $totalPixels += intval($vs['pixelCount'] ?? 0);
            if (!empty($vs['description'])) {
                $descriptions[] = $vs['description'];
            }
        }
        // Use protocol and brightness from first virtual string
        $protocol = strtolower($virtualStrings[0]['protocol'] ?? 'ws2811');
        $brightness = intval($virtualStrings[0]['brightness'] ?? 100);
    } elseif ($usePortFallback) {
        // Fallback to port-level config
        $totalPixels = intval($portConfig['pixelCount'] ?? 0);
        $protocol = strtolower($portConfig['protocol'] ?? 'ws2811');
        $brightness = intval($portConfig['brightness'] ?? 100);
        if (!empty($portConfig['description'])) {
            $descriptions[] = $portConfig['description'];
        }
    }

    return [
        'totalPixels' => $totalPixels,
        'protocol' => $protocol,
        'brightness' => $brightness,
        'descriptions' => $descriptions
    ];
}

/**
 * Get FPP channel output configuration for all ports
 * Only returns ports that have actual eFuse/current monitoring capability
 *
 * @param bool $forceRefresh Force cache refresh
 * @return array Port configuration with output details
 */
function getEfuseOutputConfig($forceRefresh = false) {
    global $_efuseOutputConfigCache, $_efuseOutputConfigCacheTime;

    // Return cached result if fresh
    if (!$forceRefresh && $_efuseOutputConfigCache !== null &&
        (time() - $_efuseOutputConfigCacheTime) < EFUSE_OUTPUT_CONFIG_CACHE_TTL) {
        return $_efuseOutputConfigCache;
    }

    $result = [
        'success' => true,
        'ports' => [],
        'totalPorts' => 0,
        'timestamp' => time()
    ];

    // Get list of ports that have actual eFuse/current monitoring
    $efuseCapablePorts = getEfuseCapablePortNames();

    // Get pixel string outputs
    $pixelOutputs = apiCall('GET', 'http://127.0.0.1/api/channel/output/co-pixelStrings', [], true, 5);

    if ($pixelOutputs && isset($pixelOutputs['channelOutputs'])) {
        foreach ($pixelOutputs['channelOutputs'] as $output) {
            $outputType = $output['type'] ?? '';
            $outputs = $output['outputs'] ?? [];

            foreach ($outputs as $portIndex => $portConfig) {
                $portNumber = ($portConfig['portNumber'] ?? $portIndex) + 1;
                $portName = 'Port ' . $portNumber;  // Match fppd/ports format: "Port 1"

                // Skip if this port doesn't have eFuse/current monitoring capability
                if (!empty($efuseCapablePorts) && !in_array($portName, $efuseCapablePorts)) {
                    continue;
                }

                // Extract virtual string configuration (with fallback to port-level config)
                $vsConfig = extractVirtualStringConfig($portConfig, true);
                $virtualStrings = $portConfig['virtualStrings'] ?? [];

                // Calculate expected current
                $expectedCurrent = estimatePortCurrent($vsConfig['totalPixels'], $vsConfig['protocol']);

                $result['ports'][$portName] = [
                    'portNumber' => $portNumber,
                    'portName' => $portName,
                    'outputType' => $outputType,
                    'protocol' => $vsConfig['protocol'],
                    'brightness' => $vsConfig['brightness'],
                    'pixelCount' => $vsConfig['totalPixels'],
                    'startChannel' => intval($portConfig['startChannel'] ?? $virtualStrings[0]['startChannel'] ?? 0),
                    'colorOrder' => $portConfig['colorOrder'] ?? $virtualStrings[0]['colorOrder'] ?? 'RGB',
                    'description' => implode(', ', $vsConfig['descriptions']),
                    'expectedCurrentMa' => $expectedCurrent['typical'],
                    'maxCurrentMa' => $expectedCurrent['max'],
                    'enabled' => !empty($vsConfig['totalPixels'])
                ];

                $result['totalPorts']++;
            }
        }
    }

    // Also check for BBB-specific outputs (co-bbbStrings is the correct endpoint for BBB/PB devices)
    $bbbOutputs = apiCall('GET', 'http://127.0.0.1/api/channel/output/co-bbbStrings', [], true, 5);

    if ($bbbOutputs && isset($bbbOutputs['channelOutputs'])) {
        foreach ($bbbOutputs['channelOutputs'] as $output) {
            $type = $output['type'] ?? '';

            // Handle BBB48String, BBShiftString (K16-Max, PB2, etc.) and similar
            if (stripos($type, 'BB') !== false || stripos($type, 'PB') !== false || stripos($type, 'Shift') !== false) {
                $outputs = $output['outputs'] ?? [];

                foreach ($outputs as $portIndex => $portConfig) {
                    $portNumber = intval($portConfig['portNumber'] ?? $portIndex) + 1;
                    $portName = 'Port ' . $portNumber;  // Match fppd/ports format: "Port 1"

                    // Skip if already processed
                    if (isset($result['ports'][$portName])) {
                        continue;
                    }

                    // Skip if this port doesn't have eFuse/current monitoring capability
                    if (!empty($efuseCapablePorts) && !in_array($portName, $efuseCapablePorts)) {
                        continue;
                    }

                    // Extract virtual string configuration (no port-level fallback for BBB)
                    $vsConfig = extractVirtualStringConfig($portConfig, false);
                    $virtualStrings = $portConfig['virtualStrings'] ?? [];

                    $expectedCurrent = estimatePortCurrent($vsConfig['totalPixels'], $vsConfig['protocol']);

                    $result['ports'][$portName] = [
                        'portNumber' => $portNumber,
                        'portName' => $portName,
                        'outputType' => $type,
                        'protocol' => $vsConfig['protocol'],
                        'brightness' => $vsConfig['brightness'],
                        'pixelCount' => $vsConfig['totalPixels'],
                        'startChannel' => intval($virtualStrings[0]['startChannel'] ?? 0),
                        'colorOrder' => $virtualStrings[0]['colorOrder'] ?? 'RGB',
                        'description' => implode(', ', $vsConfig['descriptions']),
                        'expectedCurrentMa' => $expectedCurrent['typical'],
                        'maxCurrentMa' => $expectedCurrent['max'],
                        'enabled' => !empty($vsConfig['totalPixels'])
                    ];

                    $result['totalPorts']++;
                }
            }
        }
    }

    // Sort ports by number (handle "Port 1" format with space)
    uksort($result['ports'], function($a, $b) {
        return intval(substr($a, 5)) - intval(substr($b, 5));
    });

    $_efuseOutputConfigCache = $result;
    $_efuseOutputConfigCacheTime = time();

    return $result;
}

/**
 * Estimate expected current for a port based on pixel count and protocol
 *
 * Based on real-world measurements (496 12V WS2811 pixels @ 0.5W each):
 * - 30% brightness: 1.21A, 60% brightness: 1.22A, 90% brightness: 1.24A
 * - With 42mA max per pixel: 1,210 / (496 × 42) = ~6% of theoretical max
 * - Brightness changes have minimal impact on current draw
 *
 * @param int $pixelCount Number of pixels on this port
 * @param string $protocol LED protocol (ws2811, sk6812, etc.)
 * @return array ['typical' => mA, 'max' => mA, 'perPixel' => mA]
 */
function estimatePortCurrent($pixelCount, $protocol = 'ws2811') {
    $protocol = strtolower($protocol);
    $perPixel = EFUSE_PIXEL_CURRENT_ESTIMATES[$protocol] ?? EFUSE_PIXEL_CURRENT_ESTIMATES['default'];

    // Maximum theoretical current (all pixels full white)
    $maxCurrent = $pixelCount * $perPixel;

    // Typical show usage is ~6% of theoretical max based on real-world measurements
    $typicalCurrent = intval($maxCurrent * 0.06);

    return [
        'typical' => $typicalCurrent,
        'max' => $maxCurrent,
        'perPixel' => $perPixel
    ];
}

/**
 * Get output configuration for a specific port
 *
 * @param string $portName Port name (e.g., "Port1")
 * @return array|null Port configuration or null if not found
 */
function getPortOutputConfig($portName) {
    $config = getEfuseOutputConfig();

    if (!$config['success'] || !isset($config['ports'][$portName])) {
        return null;
    }

    return $config['ports'][$portName];
}

/**
 * Get summary of all ports with their expected vs actual current
 *
 * @param array $currentReadings Current eFuse readings [portName => mA]
 * @return array Port summaries with status indicators
 */
function getPortCurrentSummary($currentReadings) {
    $config = getEfuseOutputConfig();
    $summary = [];

    // Get fuse status from fppd/ports for enabled/tripped info
    $fuseStatus = getPortFuseStatus();

    foreach ($config['ports'] as $portName => $portConfig) {
        $currentMa = $currentReadings[$portName] ?? 0;
        $expectedMa = $portConfig['expectedCurrentMa'];
        $maxMa = $portConfig['maxCurrentMa'];

        // Get fuse/enabled status from fppd/ports
        $portFuseInfo = $fuseStatus[$portName] ?? [];
        $fuseTripped = $portFuseInfo['fuseTripped'] ?? false;
        $portEnabled = $portFuseInfo['enabled'] ?? true;

        // Calculate status
        $status = 'normal';
        $statusMessage = '';

        if ($fuseTripped) {
            $status = 'tripped';
            $statusMessage = 'Fuse tripped';
        } elseif (!$portEnabled) {
            $status = 'disabled';
            $statusMessage = 'Port disabled';
        } elseif ($currentMa > EFUSE_MAX_CURRENT_MA) {
            $status = 'critical';
            $statusMessage = 'Exceeds eFuse limit';
        } elseif ($currentMa > $maxMa * 0.9) {
            $status = 'warning';
            $statusMessage = 'Near maximum capacity';
        } elseif ($currentMa > $maxMa * 0.5 && $currentMa > $expectedMa * 3) {
            $status = 'high';
            $statusMessage = 'Higher than expected';
        } elseif ($portConfig['enabled'] && $currentMa == 0 && $expectedMa > 0) {
            $status = 'inactive';
            $statusMessage = 'No current detected';
        }

        // Calculate percentage of max
        $percentOfMax = $maxMa > 0 ? round(($currentMa / $maxMa) * 100, 1) : 0;
        $percentOfEfuse = round(($currentMa / EFUSE_MAX_CURRENT_MA) * 100, 1);

        $summary[$portName] = array_merge($portConfig, [
            'currentMa' => $currentMa,
            'status' => $status,
            'statusMessage' => $statusMessage,
            'percentOfMax' => $percentOfMax,
            'percentOfEfuse' => $percentOfEfuse,
            'fuseTripped' => $fuseTripped,
            'portEnabled' => $portEnabled
        ]);
    }

    return $summary;
}

/**
 * Get fuse status for all ports from fppd/ports API
 *
 * @return array [portName => ['enabled' => bool, 'fuseTripped' => bool]]
 */
function getPortFuseStatus() {
    $result = [];

    $portsData = @file_get_contents('http://127.0.0.1/api/fppd/ports');
    if ($portsData === false) {
        return $result;
    }

    $portsList = @json_decode($portsData, true);
    if (!is_array($portsList)) {
        return $result;
    }

    foreach ($portsList as $port) {
        $name = $port['name'] ?? '';
        if (empty($name)) continue;

        // Check for smart receiver ports
        if (isset($port['smartReceivers']) && $port['smartReceivers']) {
            foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $sub) {
                if (isset($port[$sub])) {
                    $subName = $name . '-' . $sub;
                    $result[$subName] = [
                        'enabled' => $port[$sub]['fuseOn'] ?? $port[$sub]['enabled'] ?? false,
                        'fuseTripped' => $port[$sub]['fuseBlown'] ?? false
                    ];
                }
            }
        } else {
            // Standard port - status === false means tripped
            $result[$name] = [
                'enabled' => $port['enabled'] ?? false,
                'fuseTripped' => isset($port['status']) ? ($port['status'] === false) : false
            ];
        }
    }

    return $result;
}

/**
 * Calculate total current across all ports
 *
 * @param array $currentReadings Current eFuse readings [portName => mA]
 * @return array ['total' => mA, 'totalAmps' => float, 'portCount' => int, 'activePortCount' => int]
 */
function calculateTotalCurrent($currentReadings) {
    $total = 0;
    $activeCount = 0;

    foreach ($currentReadings as $portName => $mA) {
        $total += $mA;
        if ($mA > 0) {
            $activeCount++;
        }
    }

    return [
        'total' => $total,
        'totalAmps' => round($total / 1000, 2),
        'portCount' => count($currentReadings),
        'activePortCount' => $activeCount
    ];
}

/**
 * Clear the output config cache
 */
function clearEfuseOutputConfigCache() {
    global $_efuseOutputConfigCache, $_efuseOutputConfigCacheTime;
    $_efuseOutputConfigCache = null;
    $_efuseOutputConfigCacheTime = 0;
}
?>
