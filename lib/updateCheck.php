<?php
/**
 * Update Check Helper Functions
 *
 * Provides functions for checking plugin updates from GitHub.
 */

include_once __DIR__ . '/watcherCommon.php';

define('WATCHER_GITHUB_URL', 'https://raw.githubusercontent.com/agent462/fpp-plugin-watcher/main/pluginInfo.json');
define('FPP_RELEASES_URL', 'https://api.github.com/repos/FalconChristmas/fpp/releases?per_page=10');
define('FPP_RELEASE_CACHE_FILE', '/tmp/fpp-latest-release.json');
define('FPP_RELEASE_CACHE_TTL', 3600); // Cache for 1 hour

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

/**
 * Get the latest stable FPP release from GitHub
 * Uses caching to avoid hitting GitHub API rate limits
 *
 * @return array|null Latest release info or null on failure
 */
function getLatestFPPRelease() {
    // Check cache first
    if (file_exists(FPP_RELEASE_CACHE_FILE)) {
        $cacheData = json_decode(file_get_contents(FPP_RELEASE_CACHE_FILE), true);
        if ($cacheData && isset($cacheData['timestamp']) &&
            (time() - $cacheData['timestamp']) < FPP_RELEASE_CACHE_TTL) {
            return $cacheData['release'];
        }
    }

    // Fetch from GitHub
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: FPP-Watcher-Plugin\r\n",
            'timeout' => 10
        ]
    ]);

    $response = @file_get_contents(FPP_RELEASES_URL, false, $ctx);
    if ($response === false) {
        // Return cached data if available, even if expired
        if (isset($cacheData['release'])) {
            return $cacheData['release'];
        }
        return null;
    }

    $releases = json_decode($response, true);
    if (!is_array($releases) || empty($releases)) {
        return null;
    }

    // Find the latest stable (non-prerelease, non-draft) release
    $latestRelease = null;
    foreach ($releases as $release) {
        if (!$release['prerelease'] && !$release['draft']) {
            $latestRelease = [
                'tag' => $release['tag_name'],
                'version' => ltrim($release['tag_name'], 'v'),
                'name' => $release['name'],
                'published_at' => $release['published_at'],
                'url' => $release['html_url'] ?? ''
            ];
            break;
        }
    }

    // Cache the result
    if ($latestRelease) {
        $cacheData = [
            'timestamp' => time(),
            'release' => $latestRelease
        ];
        @file_put_contents(FPP_RELEASE_CACHE_FILE, json_encode($cacheData));
    }

    return $latestRelease;
}

/**
 * Parse FPP version string into comparable parts
 *
 * @param string $version Version string like "9.3", "9.3-3-g28ffc36a", "v9.2"
 * @return array [major, minor] as floats
 */
function parseFPPVersion($version) {
    $version = ltrim($version, 'v');
    // Extract major.minor from strings like "9.3-3-g28ffc36a" or "9.3"
    if (preg_match('/^(\d+)\.(\d+)/', $version, $matches)) {
        return [(int)$matches[1], (int)$matches[2]];
    }
    return [0, 0];
}

/**
 * Compare two FPP versions
 *
 * @param string $current Current version
 * @param string $latest Latest version
 * @return int -1 if current < latest, 0 if equal, 1 if current > latest
 */
function compareFPPVersions($current, $latest) {
    $currentParts = parseFPPVersion($current);
    $latestParts = parseFPPVersion($latest);

    if ($currentParts[0] < $latestParts[0]) return -1;
    if ($currentParts[0] > $latestParts[0]) return 1;
    if ($currentParts[1] < $latestParts[1]) return -1;
    if ($currentParts[1] > $latestParts[1]) return 1;
    return 0;
}

/**
 * Check if a newer FPP release is available
 *
 * @param string $currentBranch Current FPP branch (e.g., "v9.2")
 * @return array Result with upgrade info
 */
function checkFPPReleaseUpgrade($currentBranch) {
    $latestRelease = getLatestFPPRelease();

    if (!$latestRelease) {
        return [
            'available' => false,
            'error' => 'Could not fetch release info'
        ];
    }

    $currentParts = parseFPPVersion($currentBranch);
    $latestParts = parseFPPVersion($latestRelease['version']);
    $comparison = compareFPPVersions($currentBranch, $latestRelease['version']);

    if ($comparison < 0) {
        // Check if this is a major version jump (e.g., v9.x to v10.x)
        $isMajorUpgrade = $latestParts[0] > $currentParts[0];

        return [
            'available' => true,
            'currentVersion' => ltrim($currentBranch, 'v'),
            'latestVersion' => $latestRelease['version'],
            'latestTag' => $latestRelease['tag'],
            'releaseName' => $latestRelease['name'],
            'releaseUrl' => $latestRelease['url'],
            'isMajorUpgrade' => $isMajorUpgrade
        ];
    }

    return [
        'available' => false,
        'currentVersion' => ltrim($currentBranch, 'v'),
        'latestVersion' => $latestRelease['version']
    ];
}
?>
