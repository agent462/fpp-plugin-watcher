<?php
declare(strict_types=1);

namespace Watcher\Utils;

use Watcher\Http\ApiClient;

/**
 * Update Check Helper
 *
 * Provides functions for checking plugin updates from GitHub.
 */
class UpdateChecker
{
    private const GITHUB_URL = 'https://raw.githubusercontent.com/agent462/fpp-plugin-watcher/main/pluginInfo.json';
    private const FPP_RELEASES_URL = 'https://api.github.com/repos/FalconChristmas/fpp/releases?per_page=10';
    private const FPP_RELEASE_CACHE_FILE = '/tmp/fpp-latest-release.json';
    private const FPP_RELEASE_CACHE_TTL = 3600; // Cache for 1 hour

    private static ?self $instance = null;
    private ApiClient $apiClient;
    private string $pluginName;

    private function __construct()
    {
        $this->apiClient = ApiClient::getInstance();
        $this->pluginName = defined('WATCHERPLUGINNAME')
            ? WATCHERPLUGINNAME
            : 'fpp-plugin-watcher';
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Get the latest Watcher plugin version from GitHub
     *
     * @return string|null The latest version string, or null on failure
     */
    public function getLatestWatcherVersion(): ?string
    {
        $githubInfo = $this->fetchJsonUrl(self::GITHUB_URL, 5);

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
    public function checkWatcherUpdate(): array
    {
        $remoteInfo = $this->fetchJsonUrl(self::GITHUB_URL, 10);

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
            'repoName' => $remoteInfo['repoName'] ?? $this->pluginName
        ];
    }

    /**
     * Get the latest stable FPP release from GitHub
     * Uses caching to avoid hitting GitHub API rate limits
     *
     * @return array|null Latest release info or null on failure
     */
    public function getLatestFPPRelease(): ?array
    {
        // Check cache first
        $cacheData = $this->readCacheFile();
        if ($cacheData && isset($cacheData['timestamp']) &&
            (time() - $cacheData['timestamp']) < self::FPP_RELEASE_CACHE_TTL) {
            return $cacheData['release'] ?? null;
        }

        // Fetch from GitHub
        $response = $this->fetchUrl(self::FPP_RELEASES_URL, 10);
        if ($response === false) {
            // Return cached data if available, even if expired
            if ($cacheData && isset($cacheData['release'])) {
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
            if (!($release['prerelease'] ?? true) && !($release['draft'] ?? true)) {
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
            $this->writeCacheFile($cacheData);
        }

        return $latestRelease;
    }

    /**
     * Read the FPP release cache file
     *
     * @return array|null Cache data or null if not available
     */
    protected function readCacheFile(): ?array
    {
        if (!file_exists(self::FPP_RELEASE_CACHE_FILE)) {
            return null;
        }
        $content = @file_get_contents(self::FPP_RELEASE_CACHE_FILE);
        if ($content === false) {
            return null;
        }
        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Write the FPP release cache file
     *
     * @param array $data Cache data to write
     * @return bool True on success
     */
    protected function writeCacheFile(array $data): bool
    {
        return @file_put_contents(self::FPP_RELEASE_CACHE_FILE, json_encode($data)) !== false;
    }

    /**
     * Fetch a URL with timeout
     *
     * @param string $url URL to fetch
     * @param int $timeout Timeout in seconds
     * @return string|false Response body or false on failure
     */
    protected function fetchUrl(string $url, int $timeout = 10)
    {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: FPP-Watcher-Plugin\r\n",
                'timeout' => $timeout
            ]
        ]);

        return @file_get_contents($url, false, $ctx);
    }

    /**
     * Parse FPP version string into comparable parts
     *
     * @param string $version Version string like "9.3", "9.3-3-g28ffc36a", "v9.2"
     * @return array [major, minor] as integers
     */
    public function parseFPPVersion(string $version): array
    {
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
    public function compareFPPVersions(string $current, string $latest): int
    {
        $currentParts = $this->parseFPPVersion($current);
        $latestParts = $this->parseFPPVersion($latest);

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
    public function checkFPPReleaseUpgrade(string $currentBranch): array
    {
        $latestRelease = $this->getLatestFPPRelease();

        if (!$latestRelease) {
            return [
                'available' => false,
                'error' => 'Could not fetch release info'
            ];
        }

        $currentParts = $this->parseFPPVersion($currentBranch);
        $latestParts = $this->parseFPPVersion($latestRelease['version']);
        $comparison = $this->compareFPPVersions($currentBranch, $latestRelease['version']);

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

    /**
     * Fetch JSON from a URL
     *
     * @param string $url URL to fetch
     * @param int $timeout Timeout in seconds
     * @return array|null Decoded JSON or null on failure
     */
    private function fetchJsonUrl(string $url, int $timeout = 5): ?array
    {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: FPP-Watcher-Plugin\r\n",
                'timeout' => $timeout
            ]
        ]);

        $response = @file_get_contents($url, false, $ctx);
        if ($response === false) {
            return null;
        }

        $data = json_decode($response, true);
        return is_array($data) ? $data : null;
    }
}
