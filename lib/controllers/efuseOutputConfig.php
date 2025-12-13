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

                // Get virtual strings for this port (each can have different settings)
                $virtualStrings = $portConfig['virtualStrings'] ?? [];
                $totalPixels = 0;
                $protocol = 'ws2811'; // default
                $brightness = 100; // default
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
                } else {
                    // Fallback to port-level config
                    $totalPixels = intval($portConfig['pixelCount'] ?? 0);
                    $protocol = strtolower($portConfig['protocol'] ?? 'ws2811');
                    $brightness = intval($portConfig['brightness'] ?? 100);
                    if (!empty($portConfig['description'])) {
                        $descriptions[] = $portConfig['description'];
                    }
                }

                // Calculate expected current
                $expectedCurrent = estimatePortCurrent($totalPixels, $protocol);

                $result['ports'][$portName] = [
                    'portNumber' => $portNumber,
                    'portName' => $portName,
                    'outputType' => $outputType,
                    'protocol' => $protocol,
                    'brightness' => $brightness,
                    'pixelCount' => $totalPixels,
                    'startChannel' => intval($portConfig['startChannel'] ?? $virtualStrings[0]['startChannel'] ?? 0),
                    'colorOrder' => $portConfig['colorOrder'] ?? $virtualStrings[0]['colorOrder'] ?? 'RGB',
                    'description' => implode(', ', $descriptions),
                    'expectedCurrentMa' => $expectedCurrent['typical'],
                    'maxCurrentMa' => $expectedCurrent['max'],
                    'enabled' => !empty($totalPixels)
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

                    $virtualStrings = $portConfig['virtualStrings'] ?? [];
                    $totalPixels = 0;
                    $protocol = 'ws2811';
                    $brightness = 100;
                    $descriptions = [];

                    foreach ($virtualStrings as $vs) {
                        $totalPixels += intval($vs['pixelCount'] ?? 0);
                        if (!empty($vs['description'])) {
                            $descriptions[] = $vs['description'];
                        }
                    }

                    if (!empty($virtualStrings)) {
                        $protocol = strtolower($virtualStrings[0]['protocol'] ?? 'ws2811');
                        $brightness = intval($virtualStrings[0]['brightness'] ?? 100);
                    }

                    $expectedCurrent = estimatePortCurrent($totalPixels, $protocol);

                    $result['ports'][$portName] = [
                        'portNumber' => $portNumber,
                        'portName' => $portName,
                        'outputType' => $type,
                        'protocol' => $protocol,
                        'brightness' => $brightness,
                        'pixelCount' => $totalPixels,
                        'startChannel' => intval($virtualStrings[0]['startChannel'] ?? 0),
                        'colorOrder' => $virtualStrings[0]['colorOrder'] ?? 'RGB',
                        'description' => implode(', ', $descriptions),
                        'expectedCurrentMa' => $expectedCurrent['typical'],
                        'maxCurrentMa' => $expectedCurrent['max'],
                        'enabled' => !empty($totalPixels)
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

    foreach ($config['ports'] as $portName => $portConfig) {
        $currentMa = $currentReadings[$portName] ?? 0;
        $expectedMa = $portConfig['expectedCurrentMa'];
        $maxMa = $portConfig['maxCurrentMa'];

        // Calculate status
        $status = 'normal';
        $statusMessage = '';

        if ($currentMa > EFUSE_MAX_CURRENT_MA) {
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
            'percentOfEfuse' => $percentOfEfuse
        ]);
    }

    return $summary;
}

/**
 * Get color for current reading based on amperage
 *
 * @param int $currentMa Current in milliamps
 * @return string CSS color value
 */
function getEfuseCurrentColor($currentMa) {
    // 0-6A gradient
    if ($currentMa <= 0) {
        return '#1a1a2e'; // Dark (off/zero)
    } elseif ($currentMa < 500) {
        return '#16213e'; // Cool blue
    } elseif ($currentMa < 1000) {
        return '#1e5128'; // Green
    } elseif ($currentMa < 2000) {
        return '#4e9f3d'; // Light green
    } elseif ($currentMa < 3000) {
        return '#ffc107'; // Yellow (warning)
    } elseif ($currentMa < 4000) {
        return '#fd7e14'; // Orange
    } elseif ($currentMa < 5000) {
        return '#dc3545'; // Red (high)
    } else {
        return '#c82333'; // Dark red (max/critical)
    }
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
