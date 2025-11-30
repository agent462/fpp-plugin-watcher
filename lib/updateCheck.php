<?php
/**
 * Update Check Helper Functions
 *
 * Provides functions for checking plugin updates from GitHub.
 */

include_once __DIR__ . '/watcherCommon.php';

define('WATCHER_GITHUB_URL', 'https://raw.githubusercontent.com/agent462/fpp-plugin-watcher/main/pluginInfo.json');

/**
 * Get the latest Watcher plugin version from GitHub
 *
 * @return string|null The latest version string, or null on failure
 */
function getLatestWatcherVersion() {
    $githubInfo = fetchJsonUrl(WATCHER_GITHUB_URL, 5);

    if ($githubInfo && isset($githubInfo['version'])) {
        return $githubInfo['version'];
    }

    return null;
}

/**
 * Check for Watcher plugin updates from GitHub
 *
 * @return array Result with success status and version info
 */
function checkWatcherUpdate() {
    $remoteInfo = fetchJsonUrl(WATCHER_GITHUB_URL, 10);

    if (!$remoteInfo) {
        return [
            'success' => false,
            'error' => 'Failed to fetch from GitHub'
        ];
    }

    if (!isset($remoteInfo['version'])) {
        return [
            'success' => false,
            'error' => 'Invalid response from GitHub'
        ];
    }

    return [
        'success' => true,
        'latestVersion' => $remoteInfo['version'],
        'repoName' => $remoteInfo['repoName'] ?? WATCHERPLUGINNAME
    ];
}
?>
