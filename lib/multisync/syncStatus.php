<?php
/**
 * multiSyncMetrics.php - Helper functions for multi-sync metrics from C++ plugin
 *
 * Provides PHP wrapper functions to interact with the WatcherMultiSync C++ plugin
 * API endpoints for UI dashboard display.
 */

include_once __DIR__ . '/../core/watcherCommon.php';
include_once __DIR__ . '/../core/apiCall.php';

// API base URL for C++ plugin endpoints
define('WATCHER_MULTISYNC_API_BASE', 'http://127.0.0.1/api/plugin-apis/fpp-plugin-watcher/multisync');

/**
 * Get multi-sync status from the C++ plugin
 *
 * @return array Status data or error
 */
function getMultiSyncStatus() {
    $response = apiCall('GET', WATCHER_MULTISYNC_API_BASE . '/status', [], true, 5);
    if ($response === null) {
        return ['error' => 'Failed to connect to multi-sync plugin API'];
    }
    return $response;
}

/**
 * Get all multi-sync metrics from the C++ plugin
 *
 * @return array Metrics data or error
 */
function getMultiSyncMetrics() {
    $response = apiCall('GET', WATCHER_MULTISYNC_API_BASE . '/metrics', [], true, 5);
    if ($response === null) {
        return ['error' => 'Failed to connect to multi-sync plugin API'];
    }
    return $response;
}

/**
 * Get active issues from the C++ plugin
 *
 * @return array Issues data or error
 */
function getMultiSyncIssues() {
    $response = apiCall('GET', WATCHER_MULTISYNC_API_BASE . '/issues', [], true, 5);
    if ($response === null) {
        return ['error' => 'Failed to connect to multi-sync plugin API'];
    }
    return $response;
}

/**
 * Get metrics for a specific host
 *
 * @param string $hostOrIp Hostname or IP address
 * @return array Host metrics or error
 */
function getMultiSyncHostMetrics($hostOrIp) {
    $response = apiCall('GET', WATCHER_MULTISYNC_API_BASE . '/host/' . urlencode($hostOrIp), [], true, 5);
    if ($response === null) {
        return ['error' => 'Failed to connect to multi-sync plugin API'];
    }
    return $response;
}

/**
 * Reset all multi-sync metrics
 *
 * @return array Response or error
 */
function resetMultiSyncMetrics() {
    $response = apiCall('POST', WATCHER_MULTISYNC_API_BASE . '/reset', [], true, 5);
    if ($response === null) {
        return ['error' => 'Failed to reset multi-sync metrics'];
    }
    return $response;
}

/**
 * Get FPP's built-in sync stats (from core FPP API)
 *
 * @return array FPP sync stats or error
 */
function getFppSyncStats() {
    $response = apiCall('GET', 'http://127.0.0.1/api/fppd/multiSyncStats', [], true, 5);
    if ($response === null) {
        return ['error' => 'Failed to get FPP sync stats'];
    }
    return $response;
}

/**
 * Get FPP multi-sync systems
 *
 * @param bool $localOnly Whether to get local systems only
 * @return array Systems data or error
 */
function getFppMultiSyncSystems($localOnly = false) {
    $url = 'http://127.0.0.1/api/fppd/multiSyncSystems';
    if ($localOnly) {
        $url .= '?localOnly=1';
    }
    $response = apiCall('GET', $url, [], true, 5);
    if ($response === null) {
        return ['error' => 'Failed to get FPP multi-sync systems'];
    }
    return $response;
}

/**
 * Format seconds since last seen into human-readable string
 *
 * @param int $seconds Seconds since last seen
 * @return string Formatted time string
 */
function formatTimeSince($seconds) {
    if ($seconds < 0) {
        return 'never';
    } elseif ($seconds < 60) {
        return $seconds . ' sec ago';
    } elseif ($seconds < 3600) {
        $min = floor($seconds / 60);
        return $min . ' min ago';
    } elseif ($seconds < 86400) {
        $hr = floor($seconds / 3600);
        return $hr . ' hr ago';
    } else {
        $days = floor($seconds / 86400);
        return $days . ' day(s) ago';
    }
}

/**
 * Get issue severity CSS class
 *
 * @param int $severity Severity level (1=info, 2=warning, 3=critical)
 * @return string CSS class name
 */
function getIssueSeverityClass($severity) {
    switch ($severity) {
        case 1:
            return 'watcher-issue-info';
        case 2:
            return 'watcher-issue-warning';
        case 3:
            return 'watcher-issue-critical';
        default:
            return 'watcher-issue-info';
    }
}

/**
 * Get issue severity label
 *
 * @param int $severity Severity level
 * @return string Severity label
 */
function getIssueSeverityLabel($severity) {
    switch ($severity) {
        case 1:
            return 'Info';
        case 2:
            return 'Warning';
        case 3:
            return 'Critical';
        default:
            return 'Unknown';
    }
}

/**
 * Check if the C++ plugin is loaded and responding
 *
 * @return bool True if plugin is responding
 */
function isMultiSyncPluginLoaded() {
    $status = getMultiSyncStatus();
    return !isset($status['error']);
}

/**
 * Get combined multi-sync data for dashboard display
 *
 * @return array Combined status, metrics, and issues
 */
function getMultiSyncDashboardData() {
    $data = [
        'status' => getMultiSyncStatus(),
        'metrics' => getMultiSyncMetrics(),
        'issues' => getMultiSyncIssues(),
        'fppSystems' => getFppMultiSyncSystems(),
        'fppStats' => getFppSyncStats()
    ];

    // Check for plugin load error
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
 *
 * This endpoint is designed for efficient polling from remote systems,
 * combining two API calls into one to reduce network overhead.
 *
 * @return array Combined watcher status and FPP status
 */
function getFullSyncStatus() {
    // Fetch both in parallel would be ideal, but PHP doesn't do that easily
    // So we fetch sequentially but it's still one HTTP call from the remote's perspective
    $watcherStatus = getMultiSyncStatus();
    $fppStatus = apiCall('GET', 'http://127.0.0.1/api/fppd/status', [], true, 2);

    $result = [
        'success' => true,
        'timestamp' => time(),
        'watcher' => $watcherStatus,
        'fpp' => null
    ];

    // Check if watcher plugin is loaded
    if (isset($watcherStatus['error'])) {
        $result['watcherLoaded'] = false;
    } else {
        $result['watcherLoaded'] = true;
    }

    // Add FPP status if available
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
