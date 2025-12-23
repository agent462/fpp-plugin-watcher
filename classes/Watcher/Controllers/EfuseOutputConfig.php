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
    private ?EfuseHardware $efuseHardware = null;
    private ?array $configCache = null;
    private int $configCacheTime = 0;

    private function __construct()
    {
        $this->apiClient = ApiClient::getInstance();
    }

    /**
     * Get EfuseHardware instance (lazy initialization for testability)
     */
    private function getEfuseHardware(): EfuseHardware
    {
        return $this->efuseHardware ??= EfuseHardware::getInstance();
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
     * Find matching eFuse capable ports for a base port name
     * Returns matching port names (e.g., "Port 9" -> ["Port 9A"] for smart receivers)
     */
    private function findMatchingEfusePorts(string $basePortName, array $efuseCapablePorts): array
    {
        // Exact match for regular ports
        if (in_array($basePortName, $efuseCapablePorts)) {
            return [$basePortName];
        }

        // Check for smart receiver subports (e.g., "Port 9" matches "Port 9A", "Port 9B")
        $matches = [];
        foreach ($efuseCapablePorts as $capablePort) {
            if (preg_match('/^' . preg_quote($basePortName, '/') . '[A-F]$/', $capablePort)) {
                $matches[] = $capablePort;
            }
        }

        return $matches;
    }

    /**
     * Process channel outputs and add to result
     */
    private function processChannelOutputs(
        array $channelOutputs,
        array $efuseCapablePorts,
        array &$result,
        bool $usePortFallback,
        ?callable $typeFilter = null
    ): void {
        foreach ($channelOutputs as $output) {
            $outputType = $output['type'] ?? '';

            // Apply type filter if provided
            if ($typeFilter !== null && !$typeFilter($outputType)) {
                continue;
            }

            $outputs = $output['outputs'] ?? [];

            foreach ($outputs as $portIndex => $portConfig) {
                $portNumber = intval($portConfig['portNumber'] ?? $portIndex) + 1;
                $basePortName = 'Port ' . $portNumber;

                // Find matching eFuse capable ports (handles smart receivers)
                $matchingPorts = $this->findMatchingEfusePorts($basePortName, $efuseCapablePorts);
                if (!empty($efuseCapablePorts) && empty($matchingPorts)) {
                    continue;
                }

                $vsConfig = $this->extractVirtualStringConfig($portConfig, $usePortFallback);
                $virtualStrings = $portConfig['virtualStrings'] ?? [];
                $expectedCurrent = $this->estimatePortCurrent($vsConfig['totalPixels'], $vsConfig['protocol']);

                // If no matching ports found (efuseCapablePorts is empty), use base name
                $portNames = !empty($matchingPorts) ? $matchingPorts : [$basePortName];

                foreach ($portNames as $portName) {
                    // Skip if already have this port
                    if (isset($result['ports'][$portName])) {
                        continue;
                    }

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
        $efuseCapablePorts = $this->getEfuseHardware()->getEfuseCapablePortNames();

        // Get pixel string outputs
        $pixelOutputs = $this->apiClient->get('http://127.0.0.1/api/channel/output/co-pixelStrings', 5);
        if ($pixelOutputs && isset($pixelOutputs['channelOutputs'])) {
            $this->processChannelOutputs($pixelOutputs['channelOutputs'], $efuseCapablePorts, $result, true);
        }

        // Also check for BBB-specific outputs
        $bbbOutputs = $this->apiClient->get('http://127.0.0.1/api/channel/output/co-bbbStrings', 5);
        if ($bbbOutputs && isset($bbbOutputs['channelOutputs'])) {
            $this->processChannelOutputs(
                $bbbOutputs['channelOutputs'],
                $efuseCapablePorts,
                $result,
                false,
                fn($type) => stripos($type, 'BB') !== false || stripos($type, 'PB') !== false || stripos($type, 'Shift') !== false
            );
        }

        // Sort ports by port number and subport letter
        uksort($result['ports'], function($a, $b) {
            preg_match('/^Port (\d+)([A-F])?$/', $a, $ma);
            preg_match('/^Port (\d+)([A-F])?$/', $b, $mb);
            $numA = intval($ma[1] ?? 0);
            $numB = intval($mb[1] ?? 0);
            if ($numA !== $numB) {
                return $numA - $numB;
            }
            return strcmp($ma[2] ?? '', $mb[2] ?? '');
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

    public function getPortFuseStatus(): array
    {
        $portsList = $this->getEfuseHardware()->fetchPortsData();
        if ($portsList === null) {
            return [];
        }

        $result = [];
        $this->getEfuseHardware()->iterateAllPorts($portsList, function (string $portName, array $portData, bool $isSmartReceiver) use (&$result) {
            // false for tripped fuses on both regular and smart receiver ports
            $fuseTripped = isset($portData['status']) && $portData['status'] === false;

            if ($isSmartReceiver) {
                $result[$portName] = [
                    'enabled' => $portData['fuseOn'] ?? $portData['enabled'] ?? false,
                    'fuseTripped' => $fuseTripped
                ];
            } else {
                $result[$portName] = [
                    'enabled' => $portData['enabled'] ?? false,
                    'fuseTripped' => $fuseTripped
                ];
            }
        });

        return $result;
    }

    public function calculateTotalCurrent(array $currentReadings): array
    {
        $totalMa = 0;
        $activeCount = 0;
        $portCount = 0;

        foreach ($currentReadings as $portName => $mA) {
            if ($portName === '_total') {
                continue;
            }
            $portCount++;
            $totalMa += $mA;
            if ($mA > 0) {
                $activeCount++;
            }
        }

        return [
            'totalMa' => $totalMa,
            'totalAmps' => round($totalMa / 1000, 2),
            'portCount' => $portCount,
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
