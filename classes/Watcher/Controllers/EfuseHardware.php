<?php
declare(strict_types=1);

namespace Watcher\Controllers;

use Watcher\Core\Logger;
use Watcher\Core\FileManager;

/**
 * eFuse Hardware Detection and Reading
 *
 * Detects compatible eFuse hardware and provides methods to read current values.
 * Supports:
 * - BBB/PB capes with eFuse (PB2, PB16, F8-PB, etc.)
 * - Pi hats with current sensing
 * - Falcon smart receivers
 */
class EfuseHardware
{
    public const HARDWARE_CACHE_TTL = 3600; // 1 hour
    public const RESET_DELAY_US = 100000; // 100ms delay for fuse reset

    // FPP directories
    public const FPP_TMP_DIR = '/home/fpp/media/tmp';
    public const FPP_CONFIG_DIR = '/home/fpp/media/config';
    public const FPP_CAPES_DIR = '/opt/fpp/capes';

    private static ?self $instance = null;
    private Logger $logger;
    private FileManager $fileManager;
    private string $efuseDir;
    private string $cacheFile;
    private ?array $cachedHardware = null;

    private function __construct()
    {
        $this->logger = Logger::getInstance();
        $this->fileManager = FileManager::getInstance();
        $this->efuseDir = '/home/fpp/media/logs/watcher-efuse';
        $this->cacheFile = $this->efuseDir . '/hardware-cache.json';
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Detect eFuse-capable hardware on this system
     * Uses file-based caching and ONLY reads config files - never calls APIs or i2cdetect
     */
    public function detectHardware(bool $forceRefresh = false): array
    {
        // Try to read from file cache first (fast path)
        if (!$forceRefresh && file_exists($this->cacheFile)) {
            $cacheData = @json_decode(file_get_contents($this->cacheFile), true);
            if ($cacheData && isset($cacheData['timestamp']) && isset($cacheData['result'])) {
                if ((time() - $cacheData['timestamp']) < self::HARDWARE_CACHE_TTL) {
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
        $capeResult = $this->detectCapeEfuse();
        if ($capeResult['supported']) {
            $this->saveHardwareCache($capeResult);
            return $capeResult;
        }

        // 2. Check for smart receivers via channel output config files (no API)
        $smartResult = $this->detectSmartReceiversFromConfig();
        if ($smartResult['supported']) {
            $this->saveHardwareCache($smartResult);
            return $smartResult;
        }

        // No hardware found - cache this result too
        $this->saveHardwareCache($result);
        return $result;
    }

    /**
     * Save hardware detection result to file cache
     */
    private function saveHardwareCache(array $result): void
    {
        if (!is_dir($this->efuseDir)) {
            @mkdir($this->efuseDir, 0755, true);
        }

        $cacheData = [
            'timestamp' => time(),
            'result' => $result
        ];

        @file_put_contents($this->cacheFile, json_encode($cacheData));
        $this->fileManager->ensureFppOwnership($this->cacheFile);
    }

    /**
     * Detect cape-based eFuse support by reading FPP config files
     */
    private function detectCapeEfuse(): array
    {
        $result = [
            'supported' => false,
            'type' => 'cape',
            'ports' => 0,
            'details' => []
        ];

        // Step 1: Check for cape-info.json
        $capeInfoFile = self::FPP_TMP_DIR . '/cape-info.json';
        $capeInfo = null;

        if (file_exists($capeInfoFile)) {
            $capeInfo = @json_decode(file_get_contents($capeInfoFile), true);
        }

        // Step 2: Check if cape explicitly provides currentMonitoring
        $providesCurrentMonitoring = false;
        if ($capeInfo && isset($capeInfo['provides']) && is_array($capeInfo['provides'])) {
            $providesCurrentMonitoring = in_array('currentMonitoring', $capeInfo['provides']);
        }

        // Step 3: Get the subType from channel output config
        $channelOutputInfo = $this->getChannelOutputConfig();

        // If cape provides currentMonitoring but we have no channel config, still report support
        if ($providesCurrentMonitoring && !$channelOutputInfo) {
            $capeName = $capeInfo['name'] ?? $capeInfo['id'] ?? 'Unknown Cape';
            $portCount = $this->countEfusePortsFromFppd();
            if ($portCount === 0) {
                $portCount = 16;
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

        // If cape provides currentMonitoring, we're done
        if ($providesCurrentMonitoring) {
            $capeName = $capeInfo['name'] ?? $subType;
            $portCount = $this->countEfusePortsFromFppd();
            if ($portCount === 0) {
                $portCount = 16;
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

        // Fallback: Check string config for eFuse pins
        if (empty($subType)) {
            return $result;
        }

        $stringConfig = $this->loadStringConfig($subType);
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
     */
    private function getChannelOutputConfig(): ?array
    {
        $configFiles = [
            self::FPP_CONFIG_DIR . '/co-bbbStrings.json',
            self::FPP_CONFIG_DIR . '/co-pixelStrings.json',
            self::FPP_CONFIG_DIR . '/channeloutputs.json'
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
     */
    private function loadStringConfig(string $subType): ?array
    {
        // Priority 1: Check /home/fpp/media/tmp/strings/
        $tmpFile = self::FPP_TMP_DIR . '/strings/' . $subType . '.json';
        if (file_exists($tmpFile)) {
            $config = @json_decode(file_get_contents($tmpFile), true);
            if ($config) {
                return $config;
            }
        }

        // Priority 2: Check platform-specific capes directory
        $platform = $this->detectPlatform();
        $platformDir = self::FPP_CAPES_DIR . '/' . $platform . '/strings/' . $subType . '.json';
        if (file_exists($platformDir)) {
            $config = @json_decode(file_get_contents($platformDir), true);
            if ($config) {
                return $config;
            }
        }

        // Priority 3: Check other capes directories
        $capeDirs = ['bbb', 'pb', 'pi', 'virtual'];
        foreach ($capeDirs as $dir) {
            $file = self::FPP_CAPES_DIR . '/' . $dir . '/strings/' . $subType . '.json';
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
     */
    private function detectPlatform(): string
    {
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
        return 'pi';
    }

    /**
     * Detect smart receivers from channel output config files
     */
    private function detectSmartReceiversFromConfig(): array
    {
        $result = [
            'supported' => false,
            'type' => 'smart_receiver',
            'ports' => 0,
            'details' => []
        ];

        $configFiles = [
            self::FPP_CONFIG_DIR . '/co-bbbStrings.json',
            self::FPP_CONFIG_DIR . '/co-pixelStrings.json'
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

                if (!in_array($type, ['BBB48String', 'RPIWS281X', 'DPIPixels'])) {
                    continue;
                }

                $outputs = $output['outputs'] ?? [];
                foreach ($outputs as $portConfig) {
                    $smartRemote = $portConfig['smartRemote'] ?? 0;
                    if ($smartRemote > 0) {
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
     */
    public function readEfuseData(): array
    {
        $hardware = $this->detectHardware();

        if (!$hardware['supported']) {
            return [
                'success' => false,
                'error' => 'No compatible eFuse hardware detected',
                'ports' => []
            ];
        }

        return $this->readEfuseFromFppdPorts();
    }

    /**
     * Get fppd/ports data with in-request caching
     */
    public function getFppdPortsCached(): ?array
    {
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
     */
    public function countEfusePortsFromFppd(): int
    {
        $portNames = $this->getEfuseCapablePortNames();
        return count($portNames);
    }

    /**
     * Get list of port names that have eFuse/current monitoring capability
     */
    public function getEfuseCapablePortNames(): array
    {
        $portsList = $this->getFppdPortsCached();
        if ($portsList === null) {
            return [];
        }

        $capablePorts = [];
        foreach ($portsList as $port) {
            $name = $port['name'] ?? '';
            if (empty($name)) {
                continue;
            }

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
                if (isset($port['ma'])) {
                    $capablePorts[] = $name;
                }
            }
        }

        return $capablePorts;
    }

    /**
     * Read eFuse data from FPP's fppd/ports endpoint
     */
    private function readEfuseFromFppdPorts(): array
    {
        $ports = [];

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
     */
    public function getHardwareSummary(): array
    {
        $hardware = $this->detectHardware();

        if (!$hardware['supported']) {
            return [
                'supported' => false,
                'message' => 'No compatible eFuse hardware detected',
                'typeLabel' => 'None'
            ];
        }

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
     * Clear the hardware detection cache
     */
    public function clearHardwareCache(): void
    {
        if (file_exists($this->cacheFile)) {
            @unlink($this->cacheFile);
        }
    }

    // =========================================================================
    // EFUSE CONTROL FUNCTIONS
    // =========================================================================

    /**
     * Toggle a port on/off using FPP's command API
     */
    public function togglePort(string $portName, ?string $state = null): array
    {
        // Validate port name
        if (empty($portName) || !preg_match('/^Port \d+(-[A-F])?$/', $portName)) {
            return [
                'success' => false,
                'error' => 'Invalid port name format'
            ];
        }

        // If no state specified, get current state and toggle
        if ($state === null) {
            $currentStatus = $this->getPortStatus($portName);
            if ($currentStatus === null) {
                return [
                    'success' => false,
                    'error' => 'Could not determine current port state'
                ];
            }
            $state = $currentStatus['enabled'] ? 'off' : 'on';
        }

        // Normalize state
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

        if (trim($response) !== 'OK') {
            return [
                'success' => false,
                'error' => $response ?: 'Command failed'
            ];
        }

        $this->logControl('toggle', $portName, $state);

        return [
            'success' => true,
            'port' => $portName,
            'newState' => $state,
            'message' => "{$portName} " . ($state === 'on' ? 'enabled' : 'disabled')
        ];
    }

    /**
     * Reset a tripped fuse by toggling the port off then on
     */
    public function resetPort(string $portName): array
    {
        if (empty($portName) || !preg_match('/^Port \d+(-[A-F])?$/', $portName)) {
            return [
                'success' => false,
                'error' => 'Invalid port name format'
            ];
        }

        $status = $this->getPortStatus($portName);
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

        $offResult = $this->togglePort($portName, 'off');
        if (!$offResult['success']) {
            return $offResult;
        }

        usleep(self::RESET_DELAY_US);

        $onResult = $this->togglePort($portName, 'on');
        if (!$onResult['success']) {
            return $onResult;
        }

        $this->logControl('reset', $portName, 'success');

        return [
            'success' => true,
            'port' => $portName,
            'message' => "{$portName} fuse reset"
        ];
    }

    /**
     * Set all ports on or off (master control)
     */
    public function setAllPorts(string $state): array
    {
        $state = strtolower($state);
        if (!in_array($state, ['on', 'off'])) {
            return [
                'success' => false,
                'error' => 'Invalid state. Use "on" or "off"'
            ];
        }

        $portNames = $this->getEfuseCapablePortNames();
        if (empty($portNames)) {
            return [
                'success' => false,
                'error' => 'No eFuse-capable ports found'
            ];
        }

        $successCount = 0;
        $errors = [];

        foreach ($portNames as $portName) {
            $result = $this->togglePort($portName, $state);
            if ($result['success']) {
                $successCount++;
            } else {
                $errors[] = "{$portName}: " . ($result['error'] ?? 'Unknown error');
            }
        }

        $this->logControl('master', 'all', $state . " ({$successCount}/" . count($portNames) . ")");

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
     */
    public function resetAllTrippedFuses(): array
    {
        $trippedPorts = $this->getTrippedFuses();

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
            $result = $this->resetPort($portName);
            if ($result['success']) {
                $resetPorts[] = $portName;
            } else {
                $errors[] = "{$portName}: " . ($result['error'] ?? 'Unknown error');
            }
        }

        $this->logControl('reset-all', implode(',', $resetPorts), count($resetPorts) . ' reset');

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
     */
    public function getControlCapabilities(): array
    {
        $hardware = $this->detectHardware();

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
     */
    public function getPortStatus(string $portName): ?array
    {
        $portsData = @file_get_contents('http://127.0.0.1/api/fppd/ports');
        if ($portsData === false) {
            return null;
        }

        $portsList = @json_decode($portsData, true);
        if (!is_array($portsList)) {
            return null;
        }

        $isSubPort = preg_match('/^(Port \d+)-([A-F])$/', $portName, $matches);

        foreach ($portsList as $port) {
            $name = $port['name'] ?? '';

            if ($isSubPort) {
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
     */
    public function getTrippedFuses(): array
    {
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
     */
    private function logControl(string $action, string $target, string $result): void
    {
        $message = sprintf("CONTROL: %s on %s - %s", strtoupper($action), $target, $result);
        $this->logger->info($message);
    }

    /**
     * Get port current summary with labels and status
     * Returns ALL configured ports, with current readings merged in
     *
     * @param array $currentReadings Port data [portName => mA value] (only non-zero values)
     * @return array Port summary with labels, current, and status for ALL ports
     */
    public function getPortCurrentSummary(array $currentReadings): array
    {
        $outputConfig = \Watcher\Controllers\EfuseOutputConfig::getInstance()->getOutputConfig();
        $summary = [];

        // Iterate over all configured ports, not just ones with current readings
        foreach ($outputConfig['ports'] as $portName => $portConfig) {
            $mA = $currentReadings[$portName] ?? 0;
            $label = $portConfig['label'] ?? $portConfig['description'] ?? $portName;

            $summary[$portName] = [
                'name' => $portName,
                'label' => $label,
                'currentMa' => $mA,
                'currentA' => round($mA / 1000, 2),
                'status' => $this->getPortStatus($portName),
                'enabled' => $portConfig['enabled'] ?? false,
                'pixelCount' => $portConfig['pixelCount'] ?? 0
            ];
        }

        return $summary;
    }

    /**
     * Calculate total current from all ports
     *
     * @param array $currentReadings Port data [portName => mA value] (only non-zero values)
     * @return array Total current values
     */
    public function calculateTotalCurrent(array $currentReadings): array
    {
        $outputConfig = \Watcher\Controllers\EfuseOutputConfig::getInstance()->getOutputConfig();
        $totalMa = 0;
        $activePorts = 0;

        // Sum current from readings
        foreach ($currentReadings as $portName => $mA) {
            if ($portName === '_total') {
                continue;
            }
            $totalMa += $mA;
            if ($mA > 0) {
                $activePorts++;
            }
        }

        return [
            'totalMa' => $totalMa,
            'totalA' => round($totalMa / 1000, 2),
            'portCount' => count($outputConfig['ports']),
            'activePorts' => $activePorts
        ];
    }

    /**
     * Alias for getHardwareSummary for backward compatibility
     */
    public function getSummary(): array
    {
        return $this->getHardwareSummary();
    }
}
