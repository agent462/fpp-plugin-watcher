<?php
/**
 * Update Check Helper Functions
 *
 * Provides functions for checking plugin updates from GitHub.
 */

define('WATCHER_GITHUB_URL', 'https://raw.githubusercontent.com/agent462/fpp-plugin-watcher/main/pluginInfo.json');

/**
 * Get the latest Watcher plugin version from GitHub
 *
 * @return string|null The latest version string, or null on failure
 */
function getLatestWatcherVersion() {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, WATCHER_GITHUB_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'FPP-Plugin-Watcher');

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        return null;
    }

    $githubInfo = json_decode($response, true);
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
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, WATCHER_GITHUB_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'FPP-Plugin-Watcher');

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        return [
            'success' => false,
            'error' => $error ?: "Failed to fetch from GitHub (HTTP $httpCode)"
        ];
    }

    $remoteInfo = json_decode($response, true);
    if (!$remoteInfo || !isset($remoteInfo['version'])) {
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
