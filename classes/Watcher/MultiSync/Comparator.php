<?php
declare(strict_types=1);

namespace Watcher\MultiSync;

use Watcher\Http\ApiClient;
use Watcher\Http\CurlMultiHandler;

/**
 * MultiSync Comparator
 *
 * Collects sync metrics from watcher C++ plugin on player and remotes,
 * compares states, and detects synchronization issues.
 */
class Comparator
{
    // Issue severity levels
    public const SEVERITY_INFO = 1;
    public const SEVERITY_WARNING = 2;
    public const SEVERITY_CRITICAL = 3;

    // Default thresholds
    public const DRIFT_WARNING_THRESHOLD = 5;      // frames
    public const DRIFT_CRITICAL_THRESHOLD = 10;    // frames
    public const TIME_OFFSET_WARNING = 0.5;        // seconds
    public const TIME_OFFSET_CRITICAL = 1.0;       // seconds

    public const TIMEOUT_STANDARD = 5;
    public const TIMEOUT_STATUS = 3;

    private static ?self $instance = null;
    private ApiClient $apiClient;
    private SyncStatus $syncStatus;

    private function __construct()
    {
        $this->apiClient = ApiClient::getInstance();
        $this->syncStatus = SyncStatus::getInstance();
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Reset singleton instance (useful for testing)
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    /**
     * Fetch sync metrics from a remote host's watcher plugin
     */
    public function fetchRemoteSyncMetrics(string $address, int $timeout = self::TIMEOUT_STANDARD): ?array
    {
        $url = "http://{$address}/api/plugin-apis/fpp-plugin-watcher/multisync/status";
        $response = $this->apiClient->get($url, $timeout);
        return $response ?: null;
    }

    /**
     * Fetch FPP status from a remote host
     */
    public function fetchRemoteFppStatus(string $address, int $timeout = self::TIMEOUT_STATUS): ?array
    {
        $url = "http://{$address}/api/fppd/status";
        $response = $this->apiClient->get($url, $timeout);
        return $response ?: null;
    }

    /**
     * Collect sync metrics from all remote systems in parallel
     */
    public function collectRemoteSyncMetrics(array $remoteSystems, int $timeout = self::TIMEOUT_STANDARD): array
    {
        if (empty($remoteSystems)) {
            return [];
        }

        $handler = new CurlMultiHandler($timeout);
        $hostnames = [];

        foreach ($remoteSystems as $system) {
            $address = $system['address'];
            $handler->addRequest($address, "http://{$address}/api/plugin/fpp-plugin-watcher/multisync/full-status");
            $hostnames[$address] = $system['hostname'];
        }

        $results = $handler->execute();

        $combinedResults = [];
        $needsFallback = [];

        foreach ($results as $address => $result) {
            $combinedResults[$address] = [
                'httpCode' => $result['http_code'],
                'response' => $result['data'] ? json_encode($result['data']) : null,
                'responseTime' => $result['response_time'] ?? 0,
                'hostname' => $hostnames[$address]
            ];

            if ($result['http_code'] === 404 || $result['http_code'] === 0) {
                $needsFallback[$address] = $hostnames[$address];
            }
        }

        // Fallback: fetch FPP status directly for systems without watcher plugin
        if (!empty($needsFallback)) {
            $handler2 = new CurlMultiHandler($timeout);

            foreach ($needsFallback as $address => $hostname) {
                $handler2->addRequest($address, "http://{$address}/api/fppd/status");
            }

            $fallbackResults = $handler2->execute();

            foreach ($fallbackResults as $address => $result) {
                $combinedResults[$address]['fallbackFpp'] = [
                    'httpCode' => $result['http_code'],
                    'response' => $result['data'] ? json_encode($result['data']) : null
                ];
            }
        }

        // Process all results
        $processedResults = [];
        foreach ($combinedResults as $address => $data) {
            $result = [
                'address' => $address,
                'hostname' => $data['hostname'],
                'responseTime' => $data['responseTime'],
                'pluginInstalled' => false,
                'online' => false,
                'metrics' => null,
                'fppStatus' => null,
                'error' => null
            ];

            if ($data['httpCode'] === 200 && $data['response']) {
                $combined = json_decode($data['response'], true);
                if ($combined && isset($combined['success']) && $combined['success']) {
                    $result['online'] = true;
                    $result['pluginInstalled'] = $combined['watcherLoaded'] ?? false;

                    if (isset($combined['watcher']) && !isset($combined['watcher']['error'])) {
                        $result['metrics'] = $combined['watcher'];
                    }

                    if (isset($combined['fpp'])) {
                        $result['fppStatus'] = $combined['fpp'];
                    }
                } else {
                    $result['error'] = $combined['error'] ?? 'Invalid response';
                }
            } elseif ($data['httpCode'] === 404) {
                $result['error'] = 'Watcher plugin not installed';

                $fallback = $data['fallbackFpp'] ?? null;
                if ($fallback && $fallback['httpCode'] === 200 && $fallback['response']) {
                    $fppData = json_decode($fallback['response'], true);
                    if ($fppData) {
                        $result['online'] = true;
                        $result['fppStatus'] = [
                            'status' => $fppData['status_name'] ?? 'unknown',
                            'sequence' => $fppData['current_sequence'] ?? '',
                            'secondsPlayed' => floatval($fppData['seconds_played'] ?? 0),
                            'secondsRemaining' => floatval($fppData['seconds_remaining'] ?? 0)
                        ];
                    }
                }
            } elseif ($data['httpCode'] === 0) {
                $fallback = $data['fallbackFpp'] ?? null;
                if ($fallback && $fallback['httpCode'] === 200) {
                    $fppData = json_decode($fallback['response'], true);
                    if ($fppData) {
                        $result['online'] = true;
                        $result['fppStatus'] = [
                            'status' => $fppData['status_name'] ?? 'unknown',
                            'sequence' => $fppData['current_sequence'] ?? '',
                            'secondsPlayed' => floatval($fppData['seconds_played'] ?? 0),
                            'secondsRemaining' => floatval($fppData['seconds_remaining'] ?? 0)
                        ];
                    }
                } else {
                    $result['error'] = 'Connection failed';
                }
            } else {
                $result['error'] = "HTTP {$data['httpCode']}";
            }

            $processedResults[$address] = $result;
        }

        return $processedResults;
    }

    /**
     * Compare player status with a remote's status
     */
    public function comparePlayerToRemote(array $player, array $remote): array
    {
        $issues = [];
        $hostname = $remote['hostname'] ?? $remote['address'];

        if (!$remote['online']) {
            $issues[] = [
                'type' => 'offline',
                'severity' => self::SEVERITY_CRITICAL,
                'host' => $hostname,
                'description' => 'Remote is offline or unreachable'
            ];
            return $issues;
        }

        if (!$remote['pluginInstalled']) {
            $issues[] = [
                'type' => 'no_plugin',
                'severity' => self::SEVERITY_INFO,
                'host' => $hostname,
                'description' => 'Watcher plugin not installed on remote'
            ];
            return $issues;
        }

        $remoteMetrics = $remote['metrics'] ?? [];
        $playerMetrics = $player['metrics'] ?? [];
        $remoteFppStatus = $remote['fppStatus'] ?? null;
        $playerFppStatus = $player['fppStatus'] ?? null;

        $playerSeq = $playerFppStatus['sequence'] ?? ($playerMetrics['currentMasterSequence'] ?? '');
        $remoteSyncSeq = $remoteMetrics['currentMasterSequence'] ?? '';
        $remoteActualSeq = $remoteFppStatus['sequence'] ?? '';
        $remoteActualStatus = $remoteFppStatus['status'] ?? '';

        $playerPlaying = $playerMetrics['sequencePlaying'] ?? false;
        $remoteSyncPlaying = $remoteMetrics['sequencePlaying'] ?? false;
        $remoteActuallyPlaying = ($remoteActualStatus === 'playing');

        // Check for missing sequence
        if ($playerPlaying && $remoteSyncPlaying && !$remoteActuallyPlaying && $remoteFppStatus !== null) {
            $issues[] = [
                'type' => 'missing_sequence',
                'severity' => self::SEVERITY_CRITICAL,
                'host' => $hostname,
                'description' => "Missing sequence file: {$remoteSyncSeq}",
                'expected' => $playerSeq,
                'actual' => $remoteActualSeq ?: '(not playing)'
            ];
            return $issues;
        }

        // Compare what's actually playing
        if ($playerPlaying && !empty($playerSeq) && !empty($remoteActualSeq) && $playerSeq !== $remoteActualSeq) {
            $issues[] = [
                'type' => 'sequence_mismatch',
                'severity' => self::SEVERITY_CRITICAL,
                'host' => $hostname,
                'description' => "Playing different sequence",
                'expected' => $playerSeq,
                'actual' => $remoteActualSeq
            ];
        }

        // Compare playback state
        $effectiveRemotePlaying = $remoteFppStatus !== null ? $remoteActuallyPlaying : $remoteSyncPlaying;

        if ($playerPlaying !== $effectiveRemotePlaying) {
            $issues[] = [
                'type' => 'state_mismatch',
                'severity' => self::SEVERITY_WARNING,
                'host' => $hostname,
                'description' => $playerPlaying ? 'Remote not playing' : 'Remote playing but player idle',
                'expected' => $playerPlaying ? 'playing' : 'stopped',
                'actual' => $effectiveRemotePlaying ? 'playing' : 'stopped'
            ];
        }

        // Check frame drift
        $maxDrift = abs($remoteMetrics['maxFrameDrift'] ?? 0);
        $avgDrift = abs($remoteMetrics['avgFrameDrift'] ?? 0);
        $avgDriftRounded = round($avgDrift, 1);

        if ($avgDrift > self::DRIFT_CRITICAL_THRESHOLD) {
            $issues[] = [
                'type' => 'sync_drift',
                'severity' => self::SEVERITY_CRITICAL,
                'host' => $hostname,
                'description' => "High average frame drift: {$avgDriftRounded} frames",
                'maxDrift' => $maxDrift,
                'avgDrift' => $avgDriftRounded
            ];
        } elseif ($avgDrift > self::DRIFT_WARNING_THRESHOLD) {
            $issues[] = [
                'type' => 'sync_drift',
                'severity' => self::SEVERITY_WARNING,
                'host' => $hostname,
                'description' => "Average frame drift: {$avgDriftRounded} frames",
                'maxDrift' => $maxDrift,
                'avgDrift' => $avgDriftRounded
            ];
        }

        // Check time since last sync
        $secondsSinceSync = $remoteMetrics['secondsSinceLastSync'] ?? -1;
        if ($secondsSinceSync > 30 && $playerPlaying) {
            $issues[] = [
                'type' => 'no_sync_packets',
                'severity' => self::SEVERITY_WARNING,
                'host' => $hostname,
                'description' => "No sync packets received for {$secondsSinceSync}s",
                'secondsSinceSync' => $secondsSinceSync
            ];
        }

        return $issues;
    }

    /**
     * Get comprehensive sync comparison data
     */
    public function getComparison(array $remoteSystems): array
    {
        $startTime = microtime(true);

        // Get local/player metrics
        $localMetrics = $this->syncStatus->getStatus();
        $localFppStatus = $this->apiClient->get('http://127.0.0.1/api/fppd/status', self::TIMEOUT_STATUS);

        $player = [
            'hostname' => $localFppStatus['host_name'] ?? 'Local',
            'mode' => $localFppStatus['mode_name'] ?? 'unknown',
            'online' => true,
            'pluginInstalled' => !isset($localMetrics['error']),
            'metrics' => $localMetrics,
            'fppStatus' => [
                'status' => $localFppStatus['status_name'] ?? 'unknown',
                'sequence' => $localFppStatus['current_sequence'] ?? '',
                'currentFrame' => intval($localFppStatus['current_frame'] ?? 0),
                'secondsPlayed' => floatval($localFppStatus['seconds_played'] ?? 0)
            ]
        ];

        // Collect metrics from all remotes in parallel
        $remoteResults = $this->collectRemoteSyncMetrics($remoteSystems);

        // Compare player to each remote and collect issues
        $allIssues = [];
        $remotes = [];

        foreach ($remoteResults as $address => $remote) {
            $issues = $this->comparePlayerToRemote($player, $remote);
            $allIssues = array_merge($allIssues, $issues);

            $remote['issues'] = $issues;
            $remote['issueCount'] = count($issues);
            $remote['hasIssues'] = count($issues) > 0;
            $remote['maxSeverity'] = 0;
            foreach ($issues as $issue) {
                $remote['maxSeverity'] = max($remote['maxSeverity'], $issue['severity']);
            }

            $remotes[] = $remote;
        }

        // Sort remotes by IP
        usort($remotes, function($a, $b) {
            return ip2long($a['address'] ?? '0.0.0.0') - ip2long($b['address'] ?? '0.0.0.0');
        });

        // Calculate overall health
        $healthStatus = 'healthy';
        $maxSeverity = 0;
        foreach ($allIssues as $issue) {
            $maxSeverity = max($maxSeverity, $issue['severity']);
        }

        if ($maxSeverity >= self::SEVERITY_CRITICAL) {
            $healthStatus = 'critical';
        } elseif ($maxSeverity >= self::SEVERITY_WARNING) {
            $healthStatus = 'warning';
        }

        // Count stats
        $onlineCount = 0;
        $pluginInstalledCount = 0;
        foreach ($remotes as $r) {
            if ($r['online']) $onlineCount++;
            if ($r['pluginInstalled']) $pluginInstalledCount++;
        }

        $elapsed = round((microtime(true) - $startTime) * 1000, 1);

        return [
            'success' => true,
            'timestamp' => time(),
            'elapsed_ms' => $elapsed,
            'player' => $player,
            'remotes' => $remotes,
            'issues' => $allIssues,
            'summary' => [
                'healthStatus' => $healthStatus,
                'totalRemotes' => count($remotes),
                'onlineCount' => $onlineCount,
                'pluginInstalledCount' => $pluginInstalledCount,
                'issueCount' => count($allIssues),
                'criticalCount' => count(array_filter($allIssues, fn($i) => $i['severity'] === self::SEVERITY_CRITICAL)),
                'warningCount' => count(array_filter($allIssues, fn($i) => $i['severity'] === self::SEVERITY_WARNING)),
                'infoCount' => count(array_filter($allIssues, fn($i) => $i['severity'] === self::SEVERITY_INFO))
            ]
        ];
    }

    /**
     * Get comparison data for a specific remote
     */
    public function getComparisonForHost(string $address): array
    {
        $localMetrics = $this->syncStatus->getStatus();
        $localFppStatus = $this->apiClient->get('http://127.0.0.1/api/fppd/status', self::TIMEOUT_STATUS);

        $player = [
            'hostname' => $localFppStatus['host_name'] ?? 'Local',
            'mode' => $localFppStatus['mode_name'] ?? 'unknown',
            'online' => true,
            'pluginInstalled' => !isset($localMetrics['error']),
            'metrics' => $localMetrics
        ];

        $remoteMetrics = $this->fetchRemoteSyncMetrics($address);
        $remoteFppStatus = $this->fetchRemoteFppStatus($address);

        $remote = [
            'address' => $address,
            'hostname' => $remoteFppStatus['host_name'] ?? $address,
            'online' => $remoteMetrics !== null || $remoteFppStatus !== null,
            'pluginInstalled' => $remoteMetrics !== null && !isset($remoteMetrics['error']),
            'metrics' => $remoteMetrics,
            'fppStatus' => $remoteFppStatus ? [
                'status' => $remoteFppStatus['status_name'] ?? 'unknown',
                'sequence' => $remoteFppStatus['current_sequence'] ?? '',
                'secondsPlayed' => floatval($remoteFppStatus['seconds_played'] ?? 0)
            ] : null
        ];

        $issues = $this->comparePlayerToRemote($player, $remote);

        return [
            'success' => true,
            'timestamp' => time(),
            'player' => $player,
            'remote' => $remote,
            'issues' => $issues,
            'issueCount' => count($issues)
        ];
    }
}
