<?php
declare(strict_types=1);

namespace Watcher\Controllers;

use Watcher\Http\ApiClient;

/**
 * eFuse Output Configuration
 *
 * Maps FPP channel output configuration to eFuse ports and provides
 * expected current calculations based on pixel count and protocol.
 */
class EfuseOutputConfig
{
    // Typical per-pixel current (mA) at full white by protocol
    public const PIXEL_CURRENT_ESTIMATES = [
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
    ];

    // Maximum eFuse rating in mA
    public const MAX_CURRENT_MA = 6000;

    // Cache TTL
    public const OUTPUT_CONFIG_CACHE_TTL = 60; // 1 minute

    private static ?self $instance = null;
    private ApiClient $apiClient;
    private EfuseHardware $efuseHardware;
    private ?array $configCache = null;
    private int $configCacheTime = 0;

    private function __construct()
    {
        $this->apiClient = ApiClient::getInstance();
        $this->efuseHardware = EfuseHardware::getInstance();
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Extract virtual string configuration from a port config
     */
    public function extractVirtualStringConfig(array $portConfig, bool $usePortFallback = false): array
    {
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
            $protocol = strtolower($virtualStrings[0]['protocol'] ?? 'ws2811');
            $brightness = intval($virtualStrings[0]['brightness'] ?? 100);
        } elseif ($usePortFallback) {
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
     */
    public function getOutputConfig(bool $forceRefresh = false): array
    {
        // Return cached result if fresh
        if (!$forceRefresh && $this->configCache !== null &&
            (time() - $this->configCacheTime) < self::OUTPUT_CONFIG_CACHE_TTL) {
            return $this->configCache;
        }

        $result = [
            'success' => true,
            'ports' => [],
            'totalPorts' => 0,
            'timestamp' => time()
        ];

        // Get list of ports that have actual eFuse/current monitoring
        $efuseCapablePorts = $this->efuseHardware->getEfuseCapablePortNames();

        // Get pixel string outputs
        $pixelOutputs = $this->apiClient->get('http://127.0.0.1/api/channel/output/co-pixelStrings', 5);

        if ($pixelOutputs && isset($pixelOutputs['channelOutputs'])) {
            foreach ($pixelOutputs['channelOutputs'] as $output) {
                $outputType = $output['type'] ?? '';
                $outputs = $output['outputs'] ?? [];

                foreach ($outputs as $portIndex => $portConfig) {
                    $portNumber = ($portConfig['portNumber'] ?? $portIndex) + 1;
                    $portName = 'Port ' . $portNumber;

                    // Skip if this port doesn't have eFuse/current monitoring capability
                    if (!empty($efuseCapablePorts) && !in_array($portName, $efuseCapablePorts)) {
                        continue;
                    }

                    $vsConfig = $this->extractVirtualStringConfig($portConfig, true);
                    $virtualStrings = $portConfig['virtualStrings'] ?? [];
                    $expectedCurrent = $this->estimatePortCurrent($vsConfig['totalPixels'], $vsConfig['protocol']);

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

        // Also check for BBB-specific outputs
        $bbbOutputs = $this->apiClient->get('http://127.0.0.1/api/channel/output/co-bbbStrings', 5);

        if ($bbbOutputs && isset($bbbOutputs['channelOutputs'])) {
            foreach ($bbbOutputs['channelOutputs'] as $output) {
                $type = $output['type'] ?? '';

                if (stripos($type, 'BB') !== false || stripos($type, 'PB') !== false || stripos($type, 'Shift') !== false) {
                    $outputs = $output['outputs'] ?? [];

                    foreach ($outputs as $portIndex => $portConfig) {
                        $portNumber = intval($portConfig['portNumber'] ?? $portIndex) + 1;
                        $portName = 'Port ' . $portNumber;

                        if (isset($result['ports'][$portName])) {
                            continue;
                        }

                        if (!empty($efuseCapablePorts) && !in_array($portName, $efuseCapablePorts)) {
                            continue;
                        }

                        $vsConfig = $this->extractVirtualStringConfig($portConfig, false);
                        $virtualStrings = $portConfig['virtualStrings'] ?? [];
                        $expectedCurrent = $this->estimatePortCurrent($vsConfig['totalPixels'], $vsConfig['protocol']);

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

        // Sort ports by number
        uksort($result['ports'], function($a, $b) {
            return intval(substr($a, 5)) - intval(substr($b, 5));
        });

        $this->configCache = $result;
        $this->configCacheTime = time();

        return $result;
    }

    /**
     * Estimate expected current for a port based on pixel count and protocol
     */
    public function estimatePortCurrent(int $pixelCount, string $protocol = 'ws2811'): array
    {
        $protocol = strtolower($protocol);
        $perPixel = self::PIXEL_CURRENT_ESTIMATES[$protocol] ?? self::PIXEL_CURRENT_ESTIMATES['default'];

        $maxCurrent = $pixelCount * $perPixel;
        $typicalCurrent = intval($maxCurrent * 0.06);

        return [
            'typical' => $typicalCurrent,
            'max' => $maxCurrent,
            'perPixel' => $perPixel
        ];
    }

    /**
     * Get output configuration for a specific port
     */
    public function getPortOutputConfig(string $portName): ?array
    {
        $config = $this->getOutputConfig();

        if (!$config['success'] || !isset($config['ports'][$portName])) {
            return null;
        }

        return $config['ports'][$portName];
    }

    /**
     * Get summary of all ports with their expected vs actual current
     */
    public function getPortCurrentSummary(array $currentReadings): array
    {
        $config = $this->getOutputConfig();
        $summary = [];

        $fuseStatus = $this->getPortFuseStatus();

        foreach ($config['ports'] as $portName => $portConfig) {
            $currentMa = $currentReadings[$portName] ?? 0;
            $expectedMa = $portConfig['expectedCurrentMa'];
            $maxMa = $portConfig['maxCurrentMa'];

            $portFuseInfo = $fuseStatus[$portName] ?? [];
            $fuseTripped = $portFuseInfo['fuseTripped'] ?? false;
            $portEnabled = $portFuseInfo['enabled'] ?? true;

            $status = 'normal';
            $statusMessage = '';

            if ($fuseTripped) {
                $status = 'tripped';
                $statusMessage = 'Fuse tripped';
            } elseif (!$portEnabled) {
                $status = 'disabled';
                $statusMessage = 'Port disabled';
            } elseif ($currentMa > self::MAX_CURRENT_MA) {
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

            $percentOfMax = $maxMa > 0 ? round(($currentMa / $maxMa) * 100, 1) : 0;
            $percentOfEfuse = round(($currentMa / self::MAX_CURRENT_MA) * 100, 1);

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
     */
    public function getPortFuseStatus(): array
    {
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
     */
    public function calculateTotalCurrent(array $currentReadings): array
    {
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
    public function clearCache(): void
    {
        $this->configCache = null;
        $this->configCacheTime = 0;
    }
}
