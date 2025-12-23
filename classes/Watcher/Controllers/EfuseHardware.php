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

    // Smart receiver subport letters
    public const SMART_RECEIVER_SUBPORTS = ['A', 'B', 'C', 'D', 'E', 'F'];

    // FPP directories
    public const FPP_TMP_DIR = '/home/fpp/media/tmp';
    public const FPP_CONFIG_DIR = '/home/fpp/media/config';
    public const FPP_CAPES_DIR = '/opt/fpp/capes';

    private static ?self $instance = null;
    private Logger $logger;
    private FileManager $fileManager;
    private string $efuseDir;
    private string $cacheFile;

    private function __construct()
    {
        $this->logger = Logger::getInstance();
        $this->fileManager = FileManager::getInstance();
        $this->efuseDir = WATCHEREFUSEDIR;
        $this->cacheFile = $this->efuseDir . '/hardware-cache.json';
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Detect eFuse-capable hardware on this system
     * Uses file-based caching and ONLY reads config files
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

        // 1. Check for BBB/PB/Pi capes with eFuse support via cape-info.json + string configs
        $capeResult = $this->detectCapeEfuse();
        if ($capeResult['supported']) {
            $this->saveHardwareCache($capeResult);
            return $capeResult;
        }

        // 2. Check for smart receivers via channel output config files
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

        // Check for cape-info.json
        $capeInfoFile = self::FPP_TMP_DIR . '/cape-info.json';
        $capeInfo = null;

        if (file_exists($capeInfoFile)) {
            $capeInfo = @json_decode(file_get_contents($capeInfoFile), true);
        }

        // Check if cape explicitly provides currentMonitoring
        $providesCurrentMonitoring = false;
        if ($capeInfo && isset($capeInfo['provides']) && is_array($capeInfo['provides'])) {
            $providesCurrentMonitoring = in_array('currentMonitoring', $capeInfo['provides']);
        }

        // Get the subType from channel output config
        $channelOutputInfo = $this->getChannelOutputConfig();
        $subType = $channelOutputInfo['subType'] ?? '';
        $outputType = $channelOutputInfo['type'] ?? '';

        // If cape provides currentMonitoring, build result
        if ($providesCurrentMonitoring) {
            $capeName = $capeInfo['name'] ?? ($channelOutputInfo ? $subType : ($capeInfo['id'] ?? 'Unknown Cape'));
            $portCount = $this->countEfusePortsFromFppd();
            if ($portCount === 0) {
                $portCount = 16;
            }

            return [
                'supported' => true,
                'type' => 'cape',
                'ports' => $portCount,
                'details' => [
                    'cape' => $capeName,
                    'subType' => $channelOutputInfo ? $subType : ($capeInfo['id'] ?? ''),
                    'outputType' => $channelOutputInfo ? $outputType : 'unknown',
                    'hasCurrentMonitor' => true,
                    'method' => 'cape_provides'
                ]
            ];
        }

        if (!$channelOutputInfo) {
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

    public function countEfusePortsFromFppd(): int
    {
        $portNames = $this->getEfuseCapablePortNames();
        return count($portNames);
    }

    /**
     * Uses FPP's native naming: "Port 1" for regular, "Port 9A" for smart receiver subports
     */
    public function getEfuseCapablePortNames(): array
    {
        $portsList = $this->getFppdPortsCached();
        if ($portsList === null) {
            return [];
        }

        $capablePorts = [];
        $this->iterateAllPorts($portsList, function (string $portName, array $portData, bool $isSmartReceiver) use (&$capablePorts) {
            if (isset($portData['ma'])) {
                $capablePorts[] = $portName;
            }
        });

        return $capablePorts;
    }

    /**
     * Uses FPP's native naming: "Port 1" for regular, "Port 9A" for smart receiver subports
     */
    private function readEfuseFromFppdPorts(): array
    {
        $portsList = $this->fetchPortsData();
        if ($portsList === null) {
            return [
                'success' => false,
                'error' => 'Could not connect to fppd',
                'ports' => []
            ];
        }

        $ports = [];
        $this->iterateAllPorts($portsList, function (string $portName, array $portData, bool $isSmartReceiver) use (&$ports) {
            $ma = intval($portData['ma'] ?? 0);
            if ($ma > 0) {
                $ports[$portName] = $ma;
            }
        });

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

    public function clearHardwareCache(): void
    {
        if (file_exists($this->cacheFile)) {
            @unlink($this->cacheFile);
        }
    }

    // =========================================================================
    // PORT DATA HELPERS
    // =========================================================================

    /**
     * Accepts: "Port 1", "Port 16", "Port 9A", etc.
     */
    public function isValidPortName(string $portName): bool
    {
        return (bool) preg_match('/^Port \d+[A-F]?$/', $portName);
    }

    /**
     * Parse port name into components
     * Returns ['base' => 'Port 9', 'subport' => 'A'] or ['base' => 'Port 1', 'subport' => null]
     */
    public function parsePortName(string $portName): ?array
    {
        if (!preg_match('/^(Port \d+)([A-F])?$/', $portName, $matches)) {
            return null;
        }
        return [
            'base' => $matches[1],
            'subport' => $matches[2] ?? null
        ];
    }

    public function isSmartReceiverSubport(string $portName): bool
    {
        return (bool) preg_match('/^Port \d+[A-F]$/', $portName);
    }

    /**
     * Fetch fresh ports data from fppd API; bypasses cache
     */
    public function fetchPortsData(): ?array
    {
        $portsData = @file_get_contents('http://127.0.0.1/api/fppd/ports');
        if ($portsData === false) {
            return null;
        }
        $portsList = @json_decode($portsData, true);
        return is_array($portsList) ? $portsList : null;
    }

    /**
     * Callback receives: (string $portName, array $portData, bool $isSmartReceiver)
     */
    public function iterateAllPorts(array $portsList, callable $callback): void
    {
        foreach ($portsList as $port) {
            $name = $port['name'] ?? '';
            if (empty($name)) {
                continue;
            }

            if (isset($port['smartReceivers']) && $port['smartReceivers']) {
                // Smart receiver: iterate subports
                foreach (self::SMART_RECEIVER_SUBPORTS as $sub) {
                    if (isset($port[$sub])) {
                        $callback($name . $sub, $port[$sub], true);
                    }
                }
            } else {
                // Regular port
                $callback($name, $port, false);
            }
        }
    }

    /**
     * Get port data from a ports list for a specific port name
     * Handles both regular ports and smart receiver subports
     */
    public function getPortDataFromList(string $portName, array $portsList): ?array
    {
        $parsed = $this->parsePortName($portName);
        if ($parsed === null) {
            return null;
        }

        foreach ($portsList as $port) {
            $name = $port['name'] ?? '';

            if ($parsed['subport'] !== null) {
                // Smart receiver subport
                if ($name === $parsed['base'] && isset($port['smartReceivers']) && $port['smartReceivers']) {
                    if (isset($port[$parsed['subport']])) {
                        return [
                            'data' => $port[$parsed['subport']],
                            'isSmartReceiver' => true,
                            'basePort' => $port
                        ];
                    }
                }
            } else {
                // Regular port
                if ($name === $portName) {
                    return [
                        'data' => $port,
                        'isSmartReceiver' => false,
                        'basePort' => null
                    ];
                }
            }
        }

        return null;
    }

    // =========================================================================
    // EFUSE CONTROL FUNCTIONS
    // =========================================================================

    /**
     * Send port command with retry logic for smart receivers
     *
     * @param string $portName Port name (e.g., "Port 9A" or "Port 1")
     * @param bool $targetEnabled Target state (true = on, false = off)
     * @param int $maxRetries Maximum retry attempts
     * @param int $settleTimeUs Microseconds to wait after command before verification
     * @return array Result with success, verified state, and retry count
     */
    private function sendPortCommandWithRetry(
        string $portName,
        bool $targetEnabled,
        int $maxRetries = 3,
        int $settleTimeUs = 500000
    ): array {
        $url = "http://127.0.0.1/api/command";

        $postData = json_encode([
            'command' => 'Set Port Status',
            'args' => [$portName, $targetEnabled]
        ]);

        $lastError = null;
        $retryCount = 0;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $retryCount = $attempt;

            $response = @file_get_contents($url, false, stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => 'Content-Type: application/json',
                    'content' => $postData,
                    'timeout' => 5
                ]
            ]));

            if ($response === false) {
                $lastError = 'Failed to communicate with FPP';
                continue;
            }

            if (trim($response) !== 'OK') {
                $lastError = $response ?: 'Command failed';
                continue;
            }

            // Wait for hardware to settle
            usleep($settleTimeUs);

            // Verify state changed
            $currentStatus = $this->getPortStatus($portName);
            if ($currentStatus !== null && $currentStatus['enabled'] === $targetEnabled) {
                return [
                    'success' => true,
                    'verified' => true,
                    'retries' => $retryCount
                ];
            }

            // State didn't change, retry
            $lastError = 'State verification failed';
            usleep(250000); // 250ms delay before retry
        }

        return [
            'success' => false,
            'verified' => false,
            'retries' => $retryCount,
            'error' => $lastError ?? 'Max retries exceeded'
        ];
    }

    /**
     * Toggle a port on/off using FPP's command API
     */
    public function togglePort(string $portName, ?string $state = null): array
    {
        if (empty($portName) || !$this->isValidPortName($portName)) {
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

        $state = strtolower($state);
        if ($state === 'true' || $state === '1') {
            $state = 'on';
        } elseif ($state === 'false' || $state === '0') {
            $state = 'off';
        }

        if (!in_array($state, ['on', 'off'])) {
            return [
                'success' => false,
                'error' => 'Invalid state. Use "on" or "off"'
            ];
        }

        $targetEnabled = ($state === 'on');
        // Use 5 retries and 750ms settle time for reliability with smart receivers
        $result = $this->sendPortCommandWithRetry($portName, $targetEnabled, 5, 750000);

        if (!$result['success']) {
            return [
                'success' => false,
                'error' => $result['error'] ?? 'Toggle failed after retries'
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
        if (empty($portName) || !$this->isValidPortName($portName)) {
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
        $targetEnabled = ($state === 'on');

        if (!in_array($state, ['on', 'off'])) {
            return [
                'success' => false,
                'error' => 'Invalid state. Use "on" or "off"'
            ];
        }

        // Fetch ports data once and extract capable port names
        $portsList = $this->fetchPortsData();
        if ($portsList === null) {
            return [
                'success' => false,
                'error' => 'Could not connect to fppd'
            ];
        }

        $portNames = [];
        $this->iterateAllPorts($portsList, function (string $portName, array $portData, bool $isSmartReceiver) use (&$portNames) {
            if (isset($portData['ma'])) {
                $portNames[] = $portName;
            }
        });

        if (empty($portNames)) {
            return [
                'success' => false,
                'error' => 'No eFuse-capable ports found'
            ];
        }

        $url = "http://127.0.0.1/api/command";
        $maxRetries = 5;
        $errors = [];

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            // Get current state of all ports
            $currentPortsData = @file_get_contents('http://127.0.0.1/api/fppd/ports');
            if ($currentPortsData === false) {
                continue;
            }
            $currentPorts = @json_decode($currentPortsData, true);
            if (!is_array($currentPorts)) {
                continue;
            }

            // Find ports that need to be changed
            $portsToChange = [];
            foreach ($portNames as $portName) {
                $status = $this->getPortStatusFromData($portName, $currentPorts);
                if ($status === null || $status['enabled'] !== $targetEnabled) {
                    $portsToChange[] = $portName;
                }
            }

            // If all ports are in the target state, we're done
            if (empty($portsToChange)) {
                break;
            }

            // Send commands for ports that need changing
            foreach ($portsToChange as $portName) {
                $postData = json_encode([
                    'command' => 'Set Port Status',
                    'args' => [$portName, $targetEnabled]
                ]);

                @file_get_contents($url, false, stream_context_create([
                    'http' => [
                        'method' => 'POST',
                        'header' => 'Content-Type: application/json',
                        'content' => $postData,
                        'timeout' => 5
                    ]
                ]));

                // Delay between commands - smart receivers need extra time to process
                usleep(250000); // 250ms delay
            }

            // Wait for hardware to settle before checking state
            usleep(750000); // 750ms
        }

        // Final verification - count successes and failures
        $finalPortsData = @file_get_contents('http://127.0.0.1/api/fppd/ports');
        $finalPorts = $finalPortsData ? @json_decode($finalPortsData, true) : [];
        $successCount = 0;

        foreach ($portNames as $portName) {
            $status = $this->getPortStatusFromData($portName, $finalPorts ?: []);
            if ($status !== null && $status['enabled'] === $targetEnabled) {
                $successCount++;
            } else {
                $errors[] = "{$portName}: Failed to " . ($targetEnabled ? 'enable' : 'disable');
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
     * Get port status from already-fetched ports data (avoids API call)
     * Handles FPP native format: "Port 1" or "Port 9A"
     */
    private function getPortStatusFromData(string $portName, array $portsList): ?array
    {
        $portInfo = $this->getPortDataFromList($portName, $portsList);
        if ($portInfo === null) {
            return null;
        }

        $data = $portInfo['data'];
        if ($portInfo['isSmartReceiver']) {
            return [
                'name' => $portName,
                'enabled' => $data['fuseOn'] ?? $data['enabled'] ?? false,
                'isSmartReceiver' => true
            ];
        }

        return [
            'name' => $portName,
            'enabled' => $data['enabled'] ?? false,
            'isSmartReceiver' => false
        ];
    }

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
        $portsList = $this->fetchPortsData();
        if ($portsList === null) {
            return null;
        }

        $portInfo = $this->getPortDataFromList($portName, $portsList);
        if ($portInfo === null) {
            return null;
        }

        $data = $portInfo['data'];
        // false for tripped fuses on both regular and smart receiver ports
        $fuseTripped = isset($data['status']) && $data['status'] === false;

        if ($portInfo['isSmartReceiver']) {
            return [
                'name' => $portName,
                'enabled' => $data['fuseOn'] ?? $data['enabled'] ?? false,
                'fuseTripped' => $fuseTripped,
                'currentMa' => $data['ma'] ?? $data['current'] ?? 0,
                'isSmartReceiver' => true
            ];
        }

        return [
            'name' => $portName,
            'enabled' => $data['enabled'] ?? false,
            'fuseTripped' => $fuseTripped,
            'currentMa' => $data['ma'] ?? 0,
            'isSmartReceiver' => false
        ];
    }

    public function getTrippedFuses(): array
    {
        $portsList = $this->fetchPortsData();
        if ($portsList === null) {
            return [];
        }

        $tripped = [];
        $this->iterateAllPorts($portsList, function (string $portName, array $portData, bool $isSmartReceiver) use (&$tripped) {
            // FPP uses status: false for tripped fuses on both regular and smart receiver ports
            if (isset($portData['status']) && $portData['status'] === false) {
                $tripped[] = $portName;
            }
        });

        return $tripped;
    }

    private function logControl(string $action, string $target, string $result): void
    {
        $message = sprintf("CONTROL: %s on %s - %s", strtoupper($action), $target, $result);
        $this->logger->info($message);
    }

}
