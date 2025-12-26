<?php
declare(strict_types=1);

namespace Watcher\MultiSync;

use Watcher\Core\Logger;
use Watcher\Http\ApiClient;

/**
 * MultiSync Status
 *
 * Provides PHP wrapper functions to interact with the WatcherMultiSync C++ plugin
 * API endpoints for UI dashboard display.
 *
 * Also provides:
 * - Remote systems list with filtering and deduplication
 * - FPP mode detection (player, remote)
 */
class SyncStatus
{
    public const API_BASE = 'http://127.0.0.1/api/plugin-apis/fpp-plugin-watcher/multisync';

    private static ?self $instance = null;
    private ApiClient $apiClient;

    private function __construct()
    {
        $this->apiClient = ApiClient::getInstance();
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    // -------------------------------------------------------------------------
    // FPP Mode Detection
    // -------------------------------------------------------------------------

    /**
     * Check if this FPP instance is running in player mode
     *
     * @return bool True if in player mode
     */
    public function isPlayerMode(): bool
    {
        $result = $this->apiClient->get('http://127.0.0.1/api/fppd/status', 5);

        if ($result === false || !isset($result['mode_name'])) {
            Logger::getInstance()->info("isPlayerMode: Failed to determine mode from FPP status");
            return false;
        }

        return $result['mode_name'] === 'player';
    }

    /**
     * Check if this FPP instance is running in remote mode
     *
     * @return bool True if in remote mode
     */
    public function isRemoteMode(): bool
    {
        $result = $this->apiClient->get('http://127.0.0.1/api/fppd/status', 5);

        if ($result === false || !isset($result['mode_name'])) {
            return false;
        }

        return $result['mode_name'] === 'remote';
    }

    /**
     * Get the current FPP mode name
     *
     * @return string|null Mode name (e.g., 'player', 'remote', 'bridge') or null if unknown
     */
    public function getModeName(): ?string
    {
        $result = $this->apiClient->get('http://127.0.0.1/api/fppd/status', 5);

        if ($result === false || !isset($result['mode_name'])) {
            return null;
        }

        return $result['mode_name'];
    }

    // -------------------------------------------------------------------------
    // Remote Systems
    // -------------------------------------------------------------------------

    /**
     * Fetch remote systems from multi-sync configuration
     *
     * Returns an array of remote systems with hostname, address, model, version.
     * - Filters out local systems
     * - Filters to player/remote modes only (excludes bridge)
     * - Deduplicates by hostname (prefers entries with UUID)
     * - Validates hostname is not empty
     * - Sorts by IP address
     *
     * @return array Array of remote system info
     */
    public function getRemoteSystems(): array
    {
        $multiSyncData = $this->apiClient->get('http://127.0.0.1/api/fppd/multiSyncSystems', 5);

        if (!$multiSyncData || !isset($multiSyncData['systems']) || !is_array($multiSyncData['systems'])) {
            return [];
        }

        $systemsByHostname = [];

        foreach ($multiSyncData['systems'] as $system) {
            // Skip local systems
            if (!empty($system['local'])) {
                continue;
            }

            // Only include player and remote mode systems (skip bridge, etc.)
            $mode = $system['fppModeString'] ?? '';
            if ($mode !== 'player' && $mode !== 'remote') {
                continue;
            }

            $hostname = $system['hostname'] ?? '';
            if (empty($hostname)) {
                continue;
            }

            // Dedupe by hostname, preferring entries with UUID
            if (!isset($systemsByHostname[$hostname])) {
                $systemsByHostname[$hostname] = $system;
            } elseif (!empty($system['uuid']) && empty($systemsByHostname[$hostname]['uuid'])) {
                // Replace with this one if it has UUID and current doesn't
                $systemsByHostname[$hostname] = $system;
            }
        }

        $remoteSystems = array_values($systemsByHostname);

        // Sort by IP address numerically
        usort($remoteSystems, function ($a, $b) {
            return ip2long($a['address'] ?? '0.0.0.0') - ip2long($b['address'] ?? '0.0.0.0');
        });

        return $remoteSystems;
    }

    // -------------------------------------------------------------------------
    // C++ Plugin API Methods
    // -------------------------------------------------------------------------

    public function getStatus(): array
    {
        $response = $this->apiClient->get(self::API_BASE . '/status', 5);
        if ($response === null || $response === false) {
            return ['error' => 'Failed to connect to multi-sync plugin API'];
        }
        return $response;
    }

    public function getMetrics(): array
    {
        $response = $this->apiClient->get(self::API_BASE . '/metrics', 5);
        if ($response === null || $response === false) {
            return ['error' => 'Failed to connect to multi-sync plugin API'];
        }
        return $response;
    }


    public function getIssues(): array
    {
        $response = $this->apiClient->get(self::API_BASE . '/issues', 5);
        if ($response === null || $response === false) {
            return ['error' => 'Failed to connect to multi-sync plugin API'];
        }
        return $response;
    }

    public function getHostMetrics(string $hostOrIp): array
    {
        $response = $this->apiClient->get(self::API_BASE . '/host/' . urlencode($hostOrIp), 5);
        if ($response === null || $response === false) {
            return ['error' => 'Failed to connect to multi-sync plugin API'];
        }
        return $response;
    }

    /**
     * Reset all multi-sync metrics
     */
    public function resetMetrics(): array
    {
        $response = $this->apiClient->post(self::API_BASE . '/reset', [], 5);
        if ($response === null || $response === false) {
            return ['error' => 'Failed to reset multi-sync metrics'];
        }
        return $response;
    }

    /**
     * Get FPP's built-in sync stats (from core FPP API)
     */
    public function getFppSyncStats(): array
    {
        $response = $this->apiClient->get('http://127.0.0.1/api/fppd/multiSyncStats', 5);
        if ($response === null || $response === false) {
            return ['error' => 'Failed to get FPP sync stats'];
        }
        return $response;
    }

    /**
     * Get FPP multi-sync systems
     */
    public function getFppSystems(bool $localOnly = false): array
    {
        $url = 'http://127.0.0.1/api/fppd/multiSyncSystems';
        if ($localOnly) {
            $url .= '?localOnly=1';
        }
        $response = $this->apiClient->get($url, 5);
        if ($response === null || $response === false) {
            return ['error' => 'Failed to get FPP multi-sync systems'];
        }
        return $response;
    }

    /**
     * Format seconds since last seen into human-readable string (short format)
     */
    public static function formatTimeSince(int $seconds): string
    {
        if ($seconds < 0) {
            return 'never';
        } elseif ($seconds < 60) {
            return $seconds . 's';
        } elseif ($seconds < 3600) {
            $min = floor($seconds / 60);
            return $min . 'm';
        } elseif ($seconds < 86400) {
            $hr = floor($seconds / 3600);
            return $hr . 'h';
        } else {
            $days = floor($seconds / 86400);
            return $days . 'd';
        }
    }

    public static function getIssueSeverityClass(int $severity): string
    {
        return match ($severity) {
            1 => 'watcher-issue-info',
            2 => 'watcher-issue-warning',
            3 => 'watcher-issue-critical',
            default => 'watcher-issue-info',
        };
    }

    public static function getIssueSeverityLabel(int $severity): string
    {
        return match ($severity) {
            1 => 'Info',
            2 => 'Warning',
            3 => 'Critical',
            default => 'Unknown',
        };
    }

    /**
     * Check if the C++ plugin is loaded and responding
     */
    public function isPluginLoaded(): bool
    {
        $status = $this->getStatus();
        return !isset($status['error']);
    }

    /**
     * Get combined multi-sync data for dashboard display
     */
    public function getDashboardData(): array
    {
        $data = [
            'status' => $this->getStatus(),
            'metrics' => $this->getMetrics(),
            'issues' => $this->getIssues(),
            'fppSystems' => $this->getFppSystems(),
            'fppStats' => $this->getFppSyncStats()
        ];

        if (isset($data['status']['error'])) {
            $data['pluginLoaded'] = false;
            $data['errorMessage'] = 'Multi-sync plugin not loaded. Restart FPP to load the C++ plugin.';
        } else {
            $data['pluginLoaded'] = true;
        }

        return $data;
    }

    /**
     * Get full sync status combining watcher plugin metrics and FPP status
     */
    public function getFullStatus(): array
    {
        $watcherStatus = $this->getStatus();
        $fppStatus = $this->apiClient->get('http://127.0.0.1/api/fppd/status', 2);

        $result = [
            'success' => true,
            'timestamp' => time(),
            'watcher' => $watcherStatus,
            'fpp' => null
        ];

        if (isset($watcherStatus['error'])) {
            $result['watcherLoaded'] = false;
        } else {
            $result['watcherLoaded'] = true;
        }

        if ($fppStatus !== false && $fppStatus !== null) {
            $result['fpp'] = [
                'status' => $fppStatus['status_name'] ?? 'unknown',
                'sequence' => $fppStatus['current_sequence'] ?? '',
                'currentFrame' => intval($fppStatus['current_frame'] ?? 0),
                'secondsPlayed' => floatval($fppStatus['seconds_played'] ?? 0),
                'secondsRemaining' => floatval($fppStatus['seconds_remaining'] ?? 0),
                'mode' => $fppStatus['mode_name'] ?? 'unknown',
                'hostname' => $fppStatus['host_name'] ?? ''
            ];
        }

        return $result;
    }
}
