<?php
/**
 * eFuse Hardware Detection and Reading Library
 *
 * Detects compatible eFuse hardware and provides methods to read current values.
 * Supports:
 * - BBB capes with eFuse (PB16, etc.)
 * - I2C ADC for current sensing (ADS7828)
 * - Falcon smart receivers
 *
 * @package fpp-plugin-watcher
 */

include_once __DIR__ . '/../core/watcherCommon.php';

// Cache for hardware detection results
$_efuseHardwareCache = null;
$_efuseHardwareCacheTime = 0;
define('EFUSE_HARDWARE_CACHE_TTL', 300); // 5 minutes

/**
 * Detect eFuse-capable hardware on this system
 *
 * @return array ['supported' => bool, 'type' => string, 'ports' => int, 'details' => array]
 */
function detectEfuseHardware() {
    global $_efuseHardwareCache, $_efuseHardwareCacheTime;

    // Return cached result if fresh
    if ($_efuseHardwareCache !== null && (time() - $_efuseHardwareCacheTime) < EFUSE_HARDWARE_CACHE_TTL) {
        return $_efuseHardwareCache;
    }

    $result = [
        'supported' => false,
        'type' => 'none',
        'ports' => 0,
        'details' => []
    ];

    // Check in order of priority

    // 1. Check for BBB capes with eFuse support
    $capeResult = detectBBBCapeEfuse();
    if ($capeResult['supported']) {
        $_efuseHardwareCache = $capeResult;
        $_efuseHardwareCacheTime = time();
        return $capeResult;
    }

    // 2. Check for I2C ADC (ADS7828) for current sensing
    $i2cResult = detectI2CAdc();
    if ($i2cResult['supported']) {
        $_efuseHardwareCache = $i2cResult;
        $_efuseHardwareCacheTime = time();
        return $i2cResult;
    }

    // 3. Check for Falcon smart receivers via channel output config
    $falconResult = detectFalconSmartReceivers();
    if ($falconResult['supported']) {
        $_efuseHardwareCache = $falconResult;
        $_efuseHardwareCacheTime = time();
        return $falconResult;
    }

    // No compatible hardware found
    $_efuseHardwareCache = $result;
    $_efuseHardwareCacheTime = time();
    return $result;
}

/**
 * Check for BBB capes with eFuse support
 *
 * @return array Detection result
 */
function detectBBBCapeEfuse() {
    $result = [
        'supported' => false,
        'type' => 'bbb_cape',
        'ports' => 0,
        'details' => []
    ];

    // Check if we're on a BeagleBone
    if (!file_exists('/proc/device-tree/model')) {
        return $result;
    }

    $model = @file_get_contents('/proc/device-tree/model');
    if (stripos($model, 'BeagleBone') === false) {
        return $result;
    }

    // Check for FPP cape configuration files
    $capeDir = '/opt/fpp/capes';
    if (!is_dir($capeDir)) {
        return $result;
    }

    // Look for cape JSON files that indicate eFuse capability
    $capeFiles = glob($capeDir . '/*.json');
    foreach ($capeFiles as $capeFile) {
        $capeData = @json_decode(file_get_contents($capeFile), true);
        if (!$capeData) {
            continue;
        }

        // Check for eFuse/current monitoring capability indicators
        // PB16 and similar capes with eFuse have specific identifiers
        $capeName = $capeData['name'] ?? '';
        $hasEfuse = false;
        $ports = 0;

        // Known capes with eFuse support
        if (preg_match('/PB(\d+)/i', $capeName, $matches)) {
            $ports = intval($matches[1]);
            // PB16, PB8, etc. have eFuse monitoring
            if ($ports > 0 && isset($capeData['outputs'])) {
                $hasEfuse = true;
            }
        }

        // Check for explicit eFuse indicator in cape config
        if (isset($capeData['efuse']) || isset($capeData['currentMonitoring'])) {
            $hasEfuse = true;
            if (isset($capeData['outputs']) && is_array($capeData['outputs'])) {
                $ports = count($capeData['outputs']);
            }
        }

        if ($hasEfuse && $ports > 0) {
            $result['supported'] = true;
            $result['ports'] = $ports;
            $result['details'] = [
                'cape' => $capeName,
                'capeFile' => basename($capeFile),
                'method' => 'sysfs'
            ];
            return $result;
        }
    }

    // Check for IIO devices (ADC on BBB)
    $iioDevices = glob('/sys/bus/iio/devices/iio:device*/name');
    foreach ($iioDevices as $nameFile) {
        $name = trim(@file_get_contents($nameFile) ?: '');
        // TI ADC on BBB for current sensing
        if (strpos($name, 'TI-am335x-adc') !== false || strpos($name, 'adc') !== false) {
            $deviceDir = dirname($nameFile);
            $channels = glob($deviceDir . '/in_voltage*_raw');
            if (count($channels) > 0) {
                $result['supported'] = true;
                $result['ports'] = count($channels);
                $result['details'] = [
                    'device' => $deviceDir,
                    'channels' => count($channels),
                    'method' => 'iio'
                ];
                return $result;
            }
        }
    }

    return $result;
}

/**
 * Check for I2C ADC (ADS7828) for current sensing
 *
 * @return array Detection result
 */
function detectI2CAdc() {
    $result = [
        'supported' => false,
        'type' => 'i2c_adc',
        'ports' => 0,
        'details' => []
    ];

    // ADS7828 addresses are 0x48-0x4B
    $adsAddresses = ['0x48', '0x49', '0x4a', '0x4b'];
    $i2cBuses = [1, 2]; // Common I2C buses

    foreach ($i2cBuses as $bus) {
        if (!file_exists("/dev/i2c-{$bus}")) {
            continue;
        }

        // Use i2cdetect to find ADS7828
        $output = [];
        exec("i2cdetect -y {$bus} 2>/dev/null", $output);

        $detectedAddresses = [];
        foreach ($output as $line) {
            foreach ($adsAddresses as $addr) {
                // i2cdetect shows addresses in the format "48" (without 0x)
                $shortAddr = substr($addr, 2);
                if (strpos($line, $shortAddr) !== false && strpos($line, '--') === false) {
                    // Verify this is actually an ADS7828 by trying to read from it
                    $testOutput = [];
                    exec("i2cget -y {$bus} {$addr} 0x00 2>/dev/null", $testOutput, $retval);
                    if ($retval === 0) {
                        $detectedAddresses[] = $addr;
                    }
                }
            }
        }

        if (!empty($detectedAddresses)) {
            // ADS7828 has 8 channels per chip
            $result['supported'] = true;
            $result['ports'] = count($detectedAddresses) * 8;
            $result['details'] = [
                'bus' => $bus,
                'addresses' => $detectedAddresses,
                'channelsPerChip' => 8,
                'method' => 'i2c'
            ];
            return $result;
        }
    }

    return $result;
}

/**
 * Check for Falcon smart receivers with current monitoring
 *
 * @return array Detection result
 */
function detectFalconSmartReceivers() {
    $result = [
        'supported' => false,
        'type' => 'falcon_smart',
        'ports' => 0,
        'details' => []
    ];

    // Query FPP's channel output configuration
    $outputConfig = apiCall('GET', 'http://127.0.0.1/api/channel/output', [], true, 5);

    if (!$outputConfig || !isset($outputConfig['channelOutputs'])) {
        return $result;
    }

    $smartReceivers = [];

    foreach ($outputConfig['channelOutputs'] as $output) {
        $type = $output['type'] ?? '';

        // Look for smart receiver configurations (Falcon V5+)
        if (stripos($type, 'BBB48String') !== false ||
            stripos($type, 'F48') !== false ||
            stripos($type, 'FalconV5') !== false) {

            // Check for smart receiver ports
            $outputs = $output['outputs'] ?? [];
            foreach ($outputs as $port) {
                $smartRemote = $port['smartRemote'] ?? 0;
                if ($smartRemote > 0) {
                    $smartReceivers[$smartRemote] = [
                        'dial' => $smartRemote,
                        'ports' => isset($port['virtualStrings']) ? count($port['virtualStrings']) : 6
                    ];
                }
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
            'method' => 'falcon_api'
        ];
    }

    return $result;
}

/**
 * Read current eFuse port data
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

    switch ($method) {
        case 'sysfs':
            return readEfuseFromSysfs($hardware['details']);

        case 'iio':
            return readEfuseFromIIO($hardware['details']);

        case 'i2c':
            return readEfuseFromI2C($hardware['details']);

        case 'falcon_api':
            return readEfuseFromFalconAPI($hardware['details']);

        default:
            return [
                'success' => false,
                'error' => "Unknown read method: {$method}",
                'ports' => []
            ];
    }
}

/**
 * Read eFuse data from sysfs (BBB capes)
 *
 * @param array $details Hardware details
 * @return array Port data
 */
function readEfuseFromSysfs($details) {
    $ports = [];

    // Check for hwmon interface
    $hwmonDirs = glob('/sys/class/hwmon/hwmon*/');
    foreach ($hwmonDirs as $hwmonDir) {
        $currFiles = glob($hwmonDir . 'curr*_input');
        foreach ($currFiles as $i => $currFile) {
            $value = @file_get_contents($currFile);
            if ($value !== false) {
                // hwmon values are typically in milliamps
                $mA = intval(trim($value));
                $portName = 'Port' . ($i + 1);
                if ($mA > 0) { // Only store non-zero values
                    $ports[$portName] = $mA;
                }
            }
        }
    }

    return [
        'success' => true,
        'timestamp' => time(),
        'ports' => $ports,
        'method' => 'sysfs'
    ];
}

/**
 * Read eFuse data from IIO devices (BBB ADC)
 *
 * @param array $details Hardware details
 * @return array Port data
 */
function readEfuseFromIIO($details) {
    $ports = [];
    $deviceDir = $details['device'] ?? '';

    if (empty($deviceDir) || !is_dir($deviceDir)) {
        return [
            'success' => false,
            'error' => 'IIO device directory not found',
            'ports' => []
        ];
    }

    $channels = glob($deviceDir . '/in_voltage*_raw');
    foreach ($channels as $channel) {
        // Extract channel number from filename
        if (preg_match('/in_voltage(\d+)_raw/', $channel, $matches)) {
            $channelNum = intval($matches[1]);
            $rawValue = intval(trim(@file_get_contents($channel) ?: '0'));

            // Get scale if available
            $scaleFile = $deviceDir . "/in_voltage{$channelNum}_scale";
            $scale = file_exists($scaleFile) ? floatval(trim(@file_get_contents($scaleFile))) : 1.0;

            // Convert to mA (assuming typical current sense resistor setup)
            // This may need calibration based on actual hardware
            $voltage = $rawValue * $scale;
            $mA = intval($voltage * 1000); // Simplified conversion

            $portName = 'Port' . ($channelNum + 1);
            if ($mA > 0) {
                $ports[$portName] = $mA;
            }
        }
    }

    return [
        'success' => true,
        'timestamp' => time(),
        'ports' => $ports,
        'method' => 'iio'
    ];
}

/**
 * Read eFuse data from I2C ADC (ADS7828)
 *
 * @param array $details Hardware details
 * @return array Port data
 */
function readEfuseFromI2C($details) {
    $ports = [];
    $bus = $details['bus'] ?? 1;
    $addresses = $details['addresses'] ?? [];

    // ADS7828 channel command bytes (single-ended, internal ref off, AD on)
    $channelCmds = [0x00, 0x40, 0x10, 0x50, 0x20, 0x60, 0x30, 0x70];

    $portIndex = 1;
    foreach ($addresses as $address) {
        foreach ($channelCmds as $channel => $cmd) {
            // Read from ADS7828
            $cmdHex = sprintf('0x%02x', $cmd);
            $output = [];
            exec("i2cget -y {$bus} {$address} {$cmdHex} w 2>/dev/null", $output, $retval);

            if ($retval === 0 && !empty($output[0])) {
                // ADS7828 returns 12-bit value
                $rawValue = hexdec($output[0]);
                // Swap bytes (I2C word read is little-endian)
                $rawValue = (($rawValue & 0xFF) << 8) | (($rawValue >> 8) & 0xFF);
                $rawValue = $rawValue >> 4; // 12-bit value

                // Convert to mA (assuming 3.3V reference, 0.1 ohm sense resistor)
                // Adjust these values based on actual hardware
                $voltage = ($rawValue / 4095.0) * 3.3;
                $mA = intval(($voltage / 0.1) * 1000); // V/R * 1000 = mA

                $portName = 'Port' . $portIndex;
                if ($mA > 0) {
                    $ports[$portName] = $mA;
                }
            }
            $portIndex++;
        }
    }

    return [
        'success' => true,
        'timestamp' => time(),
        'ports' => $ports,
        'method' => 'i2c'
    ];
}

/**
 * Read eFuse data from Falcon smart receivers via API
 *
 * @param array $details Hardware details
 * @return array Port data
 */
function readEfuseFromFalconAPI($details) {
    $ports = [];

    // Falcon smart receivers report current via the FPP plugin system
    // Check for output monitor shared memory or socket
    $shmFiles = glob('/dev/shm/fpp*');

    foreach ($shmFiles as $shmFile) {
        if (strpos($shmFile, 'output') !== false || strpos($shmFile, 'current') !== false) {
            // Read from shared memory if available
            $data = @file_get_contents($shmFile);
            if ($data !== false) {
                $parsed = @json_decode($data, true);
                if (is_array($parsed)) {
                    foreach ($parsed as $portName => $value) {
                        if (is_numeric($value) && $value > 0) {
                            $ports[$portName] = intval($value);
                        }
                    }
                }
            }
        }
    }

    // Fallback: Try to read from FPP's output monitor endpoint if available
    if (empty($ports)) {
        $monitorData = apiCall('GET', 'http://127.0.0.1/api/fppd/e131stats', [], true, 2);
        if ($monitorData && isset($monitorData['ports'])) {
            foreach ($monitorData['ports'] as $port) {
                $name = $port['name'] ?? ('Port' . ($port['port'] ?? 0));
                $current = $port['current'] ?? 0;
                if ($current > 0) {
                    $ports[$name] = intval($current);
                }
            }
        }
    }

    return [
        'success' => true,
        'timestamp' => time(),
        'ports' => $ports,
        'method' => 'falcon_api'
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
            'message' => 'No compatible eFuse hardware detected'
        ];
    }

    $typeLabels = [
        'bbb_cape' => 'BeagleBone Cape',
        'i2c_adc' => 'I2C Current Sensor',
        'falcon_smart' => 'Falcon Smart Receiver'
    ];

    $methodLabels = [
        'sysfs' => 'Linux sysfs',
        'iio' => 'IIO subsystem',
        'i2c' => 'I2C direct',
        'falcon_api' => 'Falcon API'
    ];

    return [
        'supported' => true,
        'type' => $hardware['type'],
        'typeLabel' => $typeLabels[$hardware['type']] ?? $hardware['type'],
        'ports' => $hardware['ports'],
        'method' => $hardware['details']['method'] ?? 'unknown',
        'methodLabel' => $methodLabels[$hardware['details']['method'] ?? ''] ?? 'Unknown',
        'details' => $hardware['details']
    ];
}

/**
 * Clear the hardware detection cache (useful after config changes)
 */
function clearEfuseHardwareCache() {
    global $_efuseHardwareCache, $_efuseHardwareCacheTime;
    $_efuseHardwareCache = null;
    $_efuseHardwareCacheTime = 0;
}
?>
