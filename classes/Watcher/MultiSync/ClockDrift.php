<?php
declare(strict_types=1);

namespace Watcher\MultiSync;

/**
 * Clock Drift Measurement
 *
 * Uses NTP-style measurement with multiple samples, selecting lowest RTT for accuracy.
 */
class ClockDrift
{
    private static ?self $instance = null;

    private function __construct() {}

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Measure clock drift between local system and all multi-sync remotes
     *
     * @param array $remoteSystems Array of remote systems with 'address' and 'hostname' keys
     * @param int $numSamples Number of measurement rounds (default 3)
     * @param int $timeout Request timeout in seconds (default 2)
     * @return array Clock drift results for all hosts
     */
    public function measureClockDrift(array $remoteSystems, int $numSamples = 3, int $timeout = 2): array
    {
        if (empty($remoteSystems)) {
            return ['success' => true, 'hosts' => [], 'message' => 'No remote systems'];
        }

        // Store best measurement (lowest RTT) for each host
        $bestMeasurements = [];

        for ($round = 0; $round < $numSamples; $round++) {
            // Small delay between rounds to avoid burst congestion
            if ($round > 0) {
                usleep(50000); // 50ms
            }

            $mh = curl_multi_init();
            $handles = [];

            foreach ($remoteSystems as $system) {
                $ch = curl_init("http://{$system['address']}/api/plugin/fpp-plugin-watcher/time");
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_CONNECTTIMEOUT => $timeout,
                    CURLOPT_HTTPHEADER => ['Accept: application/json'],
                    CURLOPT_FRESH_CONNECT => true,
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[$system['address']] = [
                    'handle' => $ch,
                    'hostname' => $system['hostname']
                ];
            }

            // Execute all requests in parallel
            do {
                $status = curl_multi_exec($mh, $active);
                if ($active) curl_multi_select($mh);
            } while ($active && $status === CURLM_OK);

            // Capture batch completion time
            $batchEndTime = microtime(true) * 1000;

            // Collect results from this round
            foreach ($handles as $address => $info) {
                $ch = $info['handle'];
                $response = curl_multi_getcontent($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);

                // Skip failed requests
                if ($httpCode !== 200 || !$response) {
                    if (!isset($bestMeasurements[$address])) {
                        $bestMeasurements[$address] = [
                            'hostname' => $info['hostname'],
                            'online' => $httpCode > 0,
                            'hasPlugin' => false,
                            'drift_ms' => null,
                            'rtt_ms' => null
                        ];
                    }
                    continue;
                }

                $data = json_decode($response, true);
                if (!$data || !isset($data['time_ms'])) {
                    continue;
                }

                $rtt = $totalTime * 1000;
                $remoteTime = $data['time_ms'];

                // Calculate drift using NTP-style offset
                $estimatedLocalMidpoint = $batchEndTime - ($rtt / 2);
                $drift = $remoteTime - $estimatedLocalMidpoint;

                // Keep measurement with lowest RTT (most accurate)
                if (!isset($bestMeasurements[$address]) ||
                    $bestMeasurements[$address]['rtt_ms'] === null ||
                    $rtt < $bestMeasurements[$address]['rtt_ms']) {
                    $bestMeasurements[$address] = [
                        'hostname' => $info['hostname'],
                        'online' => true,
                        'hasPlugin' => true,
                        'drift_ms' => round($drift),
                        'rtt_ms' => round($rtt, 1),
                        'samples' => ($bestMeasurements[$address]['samples'] ?? 0) + 1
                    ];
                } else {
                    $bestMeasurements[$address]['samples'] = ($bestMeasurements[$address]['samples'] ?? 0) + 1;
                }
            }
            curl_multi_close($mh);
        }

        // Convert to array format
        $hosts = [];
        foreach ($bestMeasurements as $address => $measurement) {
            $hosts[] = array_merge(['address' => $address], $measurement);
        }

        // Calculate summary stats
        $drifts = array_filter(array_column($hosts, 'drift_ms'), function($v) { return $v !== null; });
        $summary = [
            'hostsChecked' => count($hosts),
            'hostsWithPlugin' => count(array_filter($hosts, function($h) { return $h['hasPlugin']; })),
            'avgDrift' => count($drifts) > 0 ? round(array_sum($drifts) / count($drifts)) : null,
            'maxDrift' => count($drifts) > 0 ? max(array_map('abs', $drifts)) : null
        ];

        return [
            'success' => true,
            'hosts' => $hosts,
            'summary' => $summary
        ];
    }

    /**
     * Measure clock drift for a single host
     */
    public function measureSingleHost(string $address, string $hostname = '', int $numSamples = 3, int $timeout = 2): array
    {
        $remoteSystems = [
            ['address' => $address, 'hostname' => $hostname ?: $address]
        ];

        $result = $this->measureClockDrift($remoteSystems, $numSamples, $timeout);

        if (!empty($result['hosts'])) {
            return [
                'success' => true,
                'host' => $result['hosts'][0]
            ];
        }

        return [
            'success' => false,
            'error' => 'Failed to measure clock drift'
        ];
    }

    /**
     * Get clock drift status classification
     */
    public static function getDriftStatus(int $driftMs): string
    {
        $absDrift = abs($driftMs);
        if ($absDrift <= 50) {
            return 'excellent';
        } elseif ($absDrift <= 100) {
            return 'good';
        } elseif ($absDrift <= 500) {
            return 'fair';
        } else {
            return 'poor';
        }
    }

    /**
     * Get human-readable drift description
     */
    public static function formatDrift(int $driftMs): string
    {
        $absDrift = abs($driftMs);
        $direction = $driftMs >= 0 ? 'ahead' : 'behind';

        if ($absDrift < 1000) {
            return "{$absDrift}ms {$direction}";
        } else {
            $seconds = round($absDrift / 1000, 1);
            return "{$seconds}s {$direction}";
        }
    }
}
