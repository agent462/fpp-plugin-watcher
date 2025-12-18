<?php
declare(strict_types=1);

namespace Watcher\Controllers;

use Watcher\Http\ApiClient;
use Watcher\Http\CurlMultiHandler;

/**
 * Remote FPP Control
 *
 * Provides functions for proxying commands to remote FPP instances.
 */
class RemoteControl
{
    public const TIMEOUT_STANDARD = 5;
    public const TIMEOUT_LONG = 30;
    public const TIMEOUT_STATUS = 3;

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

    /**
     * Extract common status fields from fppd status response
     */
    public function extractStatusFields(array $fppStatus): array
    {
        return [
            'platform' => $fppStatus['platform'] ?? '--',
            'branch' => $fppStatus['branch'] ?? '--',
            'mode_name' => $fppStatus['mode_name'] ?? '--',
            'status_name' => $fppStatus['status_name'] ?? 'idle',
            'rebootFlag' => $fppStatus['rebootFlag'] ?? 0,
            'restartFlag' => $fppStatus['restartFlag'] ?? 0
        ];
    }

    /**
     * Check if a plugin has an available update
     */
    public function checkPluginForUpdate(array $pluginInfo, string $repoName, ?string $latestWatcherVersion = null): ?array
    {
        $hasUpdate = false;
        $installedVersion = $pluginInfo['version'] ?? 'unknown';
        $pluginName = $pluginInfo['name'] ?? $repoName;

        if (!empty($pluginInfo['updatesAvailable'])) {
            $hasUpdate = true;
        }

        if ($repoName === 'fpp-plugin-watcher' && $latestWatcherVersion && $installedVersion !== 'unknown') {
            if (version_compare($latestWatcherVersion, $installedVersion, '>')) {
                $hasUpdate = true;
            }
        }

        if (!$hasUpdate) {
            return null;
        }

        $updateInfo = [
            'repoName' => $repoName,
            'name' => $pluginName,
            'installedVersion' => $installedVersion,
            'updatesAvailable' => true
        ];

        if ($repoName === 'fpp-plugin-watcher' && $latestWatcherVersion) {
            $updateInfo['latestVersion'] = $latestWatcherVersion;
        }

        return $updateInfo;
    }

    /**
     * Validate host format
     */
    private function validateHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return true;
        }
        return (bool)preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*$/', $host);
    }

    /**
     * Call a remote FPP API endpoint with host validation
     */
    public function callApi(string $host, string $method, string $endpoint, mixed $data = [], int $timeout = self::TIMEOUT_LONG): array
    {
        if (!$this->validateHost($host)) {
            return [
                'success' => false,
                'error' => 'Invalid host format',
                'host' => $host
            ];
        }

        $url = "http://{$host}{$endpoint}";
        $result = $this->apiClient->request($method, $url, $data, true, $timeout);

        if ($result === false) {
            return [
                'success' => false,
                'error' => 'Failed to connect to remote host',
                'host' => $host
            ];
        }

        return [
            'success' => true,
            'data' => $result,
            'host' => $host
        ];
    }

    /**
     * Get status from a remote FPP instance
     */
    public function getStatus(string $host): array
    {
        $result = $this->callApi($host, 'GET', '/api/fppd/status', [], 5);
        if (!$result['success']) {
            return $result;
        }
        $fppStatus = $result['data'];
        $status = $this->extractStatusFields($fppStatus);

        // Fetch actual test mode status from dedicated endpoint
        $testModeResult = $this->callApi($host, 'GET', '/api/testmode', [], 5);
        $testMode = ['enabled' => 0];
        if ($testModeResult['success'] && isset($testModeResult['data']['enabled'])) {
            $testMode['enabled'] = $testModeResult['data']['enabled'] ? 1 : 0;
        }

        return [
            'success' => true,
            'host' => $host,
            'status' => $status,
            'testMode' => $testMode
        ];
    }

    /**
     * Send a command to a remote FPP instance
     */
    public function sendCommand(string $host, string $command, array $args = [], bool $multisyncCommand = false, string $multisyncHosts = ''): array
    {
        $commandData = json_encode([
            'command' => $command,
            'multisyncCommand' => $multisyncCommand,
            'multisyncHosts' => $multisyncHosts,
            'args' => $args
        ]);

        $result = $this->callApi($host, 'POST', '/api/command', $commandData, 10);
        if (!$result['success']) {
            $result['error'] = 'Failed to send command to remote host';
            return $result;
        }

        return [
            'success' => true,
            'host' => $host,
            'result' => $result['data']
        ];
    }

    /**
     * Send a simple GET action to a remote FPP instance
     */
    public function sendSimpleAction(string $host, string $endpoint, string $message): array
    {
        $result = $this->callApi($host, 'GET', $endpoint, [], 10);
        if (!$result['success']) {
            return $result;
        }

        return [
            'success' => true,
            'host' => $host,
            'message' => $message
        ];
    }

    /**
     * Restart fppd on a remote FPP instance
     */
    public function restartFPPD(string $host): array
    {
        return $this->sendSimpleAction($host, '/api/system/fppd/restart', 'Restart command sent');
    }

    /**
     * Reboot a remote FPP instance
     */
    public function reboot(string $host): array
    {
        return $this->sendSimpleAction($host, '/api/system/reboot', 'Reboot command sent');
    }

    /**
     * Upgrade a plugin on a remote FPP instance
     */
    public function upgradePlugin(string $host, ?string $plugin = null): array
    {
        if ($plugin === null) {
            $plugin = 'fpp-plugin-watcher';
        }

        if (!preg_match('/^[a-zA-Z0-9\-_]+$/', $plugin)) {
            return [
                'success' => false,
                'error' => 'Invalid plugin name format'
            ];
        }

        $result = $this->callApi($host, 'POST', "/api/plugin/{$plugin}/upgrade", [], 120);
        if (!$result['success']) {
            $result['error'] = 'Failed to trigger plugin upgrade on remote host';
            $result['plugin'] = $plugin;
            return $result;
        }

        return [
            'success' => true,
            'host' => $host,
            'plugin' => $plugin,
            'message' => 'Plugin upgrade initiated on remote host',
            'result' => $result['data']
        ];
    }

    /**
     * Get list of installed plugins on a remote FPP instance
     */
    public function getPlugins(string $host): array
    {
        $result = $this->callApi($host, 'GET', '/api/plugin', [], 10);
        if (!$result['success']) {
            $result['error'] = 'Failed to fetch plugins from remote host';
            return $result;
        }

        return [
            'success' => true,
            'host' => $host,
            'plugins' => $result['data']
        ];
    }

    /**
     * Check for plugin updates on a remote FPP instance
     */
    public function checkPluginUpdates(string $host, ?string $latestWatcherVersion = null): array
    {
        $pluginsResult = $this->getPlugins($host);
        if (!$pluginsResult['success']) {
            return $pluginsResult;
        }
        $pluginNames = $pluginsResult['plugins'];

        if (!is_array($pluginNames)) {
            return [
                'success' => false,
                'error' => 'Failed to fetch plugins from remote host',
                'host' => $host
            ];
        }

        $updatesAvailable = [];

        foreach ($pluginNames as $repoName) {
            if (!is_string($repoName)) continue;

            $pluginInfoUrl = "http://{$host}/api/plugin/{$repoName}";
            $pluginInfo = $this->apiClient->get($pluginInfoUrl, self::TIMEOUT_STANDARD);

            if (!$pluginInfo || !is_array($pluginInfo)) continue;

            $updateInfo = $this->checkPluginForUpdate($pluginInfo, $repoName, $latestWatcherVersion);
            if ($updateInfo) {
                $updatesAvailable[] = $updateInfo;
            }
        }

        return [
            'success' => true,
            'host' => $host,
            'totalPlugins' => count($pluginNames),
            'updatesAvailable' => $updatesAvailable
        ];
    }

    /**
     * Stream FPP upgrade output from a remote host
     */
    public function streamFPPUpgrade(string $host, ?string $targetVersion = null): void
    {
        if (!$this->validateHost($host)) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'Invalid host format'
            ]);
            return;
        }

        if ($targetVersion !== null) {
            $targetVersion = trim($targetVersion);
            if (!preg_match('/^v?\d+\.\d+/', $targetVersion)) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'error' => 'Invalid version format'
                ]);
                return;
            }
            if (!str_starts_with($targetVersion, 'v')) {
                $targetVersion = 'v' . $targetVersion;
            }
        }

        if (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        flush();

        if ($targetVersion !== null) {
            $upgradeUrl = "http://{$host}/upgradefpp.php?version=" . urlencode($targetVersion) . "&wrapped=1";
            echo "=== Starting FPP cross-version upgrade on {$host} to {$targetVersion} ===\n";
        } else {
            $upgradeUrl = "http://{$host}/manualUpdate.php?wrapped=1";
            echo "=== Starting FPP upgrade on {$host} ===\n";
        }
        flush();

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $upgradeUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 900);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'FPP-Plugin-Watcher');

        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) {
            echo $data;
            flush();
            return strlen($data);
        });

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($result === false || $httpCode !== 200) {
            echo "\n=== ERROR: Failed to complete upgrade (HTTP {$httpCode}): {$error} ===\n";
        } else {
            echo "\n=== FPP upgrade completed on {$host} ===\n";
        }

        flush();
    }

    /**
     * Stream Watcher plugin upgrade output in real-time
     */
    public function streamWatcherUpgrade(string $host): void
    {
        if (!$this->validateHost($host)) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'Invalid host format'
            ]);
            return;
        }

        if (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        flush();

        echo "=== Starting Watcher plugin upgrade on {$host} ===\n\n";
        flush();

        $upgradeUrl = "http://{$host}/api/plugin/fpp-plugin-watcher/upgrade?stream=true";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $upgradeUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'FPP-Plugin-Watcher');

        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) {
            echo $data;
            flush();
            return strlen($data);
        });

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($result === false || ($httpCode !== 200 && $httpCode !== 0)) {
            echo "\n=== ERROR: Failed to complete upgrade (HTTP {$httpCode}): {$error} ===\n";
        } else {
            echo "\n=== Watcher plugin upgrade completed on {$host} ===\n";
        }

        flush();
    }

    /**
     * Bulk fetch status data from all remote hosts in parallel
     */
    public function getBulkStatus(array $remoteSystems): array
    {
        if (empty($remoteSystems)) {
            return ['success' => true, 'hosts' => []];
        }

        $handler = new CurlMultiHandler(self::TIMEOUT_STANDARD);
        $requestMeta = [];

        foreach ($remoteSystems as $system) {
            $address = $system['address'];
            $hostname = $system['hostname'];

            $endpoints = [
                'fppd' => "http://{$address}/api/fppd/status",
                'sysStatus' => "http://{$address}/api/system/status",
                'connectivity' => "http://{$address}/api/plugin/fpp-plugin-watcher/connectivity/state",
                'testmode' => "http://{$address}/api/testmode"
            ];

            foreach ($endpoints as $endpointKey => $url) {
                $requestKey = "{$address}_{$endpointKey}";
                $handler->addRequest($requestKey, $url);
                $requestMeta[$requestKey] = [
                    'address' => $address,
                    'hostname' => $hostname,
                    'endpoint' => $endpointKey
                ];
            }
        }

        $results = $handler->execute();

        $hostData = [];
        foreach ($results as $requestKey => $result) {
            $meta = $requestMeta[$requestKey];
            $address = $meta['address'];
            $endpoint = $meta['endpoint'];

            if (!isset($hostData[$address])) {
                $hostData[$address] = [
                    'success' => true,
                    'hostname' => $meta['hostname'],
                    'status' => null,
                    'testMode' => null,
                    'sysStatus' => null,
                    'connectivity' => null
                ];
            }

            if ($result['success'] && $result['data']) {
                $data = $result['data'];
                switch ($endpoint) {
                    case 'fppd':
                        $hostData[$address]['status'] = $this->extractStatusFields($data);
                        break;
                    case 'sysStatus':
                        $hostData[$address]['sysStatus'] = $data;
                        break;
                    case 'connectivity':
                        $hostData[$address]['connectivity'] = $data;
                        break;
                    case 'testmode':
                        $hostData[$address]['testMode'] = [
                            'enabled' => !empty($data['enabled']) ? 1 : 0
                        ];
                        break;
                }
            } else {
                if ($endpoint === 'fppd') {
                    $hostData[$address]['success'] = false;
                    $hostData[$address]['error'] = 'Connection failed';
                }
            }
        }

        return ['success' => true, 'hosts' => $hostData];
    }

    /**
     * Bulk fetch update data from all remote hosts in parallel
     */
    public function getBulkUpdates(array $remoteSystems, ?string $latestWatcherVersion = null): array
    {
        if (empty($remoteSystems)) {
            return ['success' => true, 'hosts' => []];
        }

        $handler = new CurlMultiHandler(self::TIMEOUT_STANDARD);
        $requestMeta = [];

        foreach ($remoteSystems as $system) {
            $address = $system['address'];
            $hostname = $system['hostname'];

            $endpoints = [
                'version' => "http://{$address}/api/plugin/fpp-plugin-watcher/version",
                'plugins' => "http://{$address}/api/plugin"
            ];

            foreach ($endpoints as $endpointKey => $url) {
                $requestKey = "{$address}_{$endpointKey}";
                $handler->addRequest($requestKey, $url);
                $requestMeta[$requestKey] = [
                    'address' => $address,
                    'hostname' => $hostname,
                    'endpoint' => $endpointKey
                ];
            }
        }

        $results = $handler->execute();

        $hostData = [];
        $pluginLists = [];

        foreach ($results as $requestKey => $result) {
            $meta = $requestMeta[$requestKey];
            $address = $meta['address'];
            $endpoint = $meta['endpoint'];

            if (!isset($hostData[$address])) {
                $hostData[$address] = [
                    'success' => true,
                    'hostname' => $meta['hostname'],
                    'version' => null,
                    'updates' => []
                ];
            }

            if ($result['success'] && $result['data']) {
                $data = $result['data'];
                switch ($endpoint) {
                    case 'version':
                        $hostData[$address]['version'] = $data['version'] ?? null;
                        break;
                    case 'plugins':
                        if (is_array($data)) {
                            $pluginLists[$address] = $data;
                        }
                        break;
                }
            } else {
                if ($endpoint === 'version') {
                    $hostData[$address]['success'] = false;
                    $hostData[$address]['error'] = 'Connection failed';
                }
            }
        }

        // Second phase: fetch plugin details to check for updates
        if (!empty($pluginLists)) {
            $handler2 = new CurlMultiHandler(self::TIMEOUT_STANDARD);
            $requestMeta2 = [];

            foreach ($pluginLists as $address => $pluginNames) {
                foreach ($pluginNames as $repoName) {
                    if (!is_string($repoName)) continue;
                    $requestKey = "{$address}_{$repoName}";
                    $handler2->addRequest($requestKey, "http://{$address}/api/plugin/{$repoName}");
                    $requestMeta2[$requestKey] = [
                        'address' => $address,
                        'repoName' => $repoName
                    ];
                }
            }

            $results2 = $handler2->execute();

            foreach ($results2 as $requestKey => $result) {
                $meta = $requestMeta2[$requestKey];
                $address = $meta['address'];
                $repoName = $meta['repoName'];

                if ($result['success'] && $result['data']) {
                    $pluginInfo = $result['data'];
                    if (is_array($pluginInfo)) {
                        $updateInfo = $this->checkPluginForUpdate($pluginInfo, $repoName, $latestWatcherVersion);
                        if ($updateInfo) {
                            $hostData[$address]['updates'][] = $updateInfo;
                        }
                    }
                }
            }
        }

        return ['success' => true, 'hosts' => $hostData];
    }
}
