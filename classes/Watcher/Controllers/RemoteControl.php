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
     * Check if an IP address is a multicast address (224.0.0.0 - 239.255.255.255)
     */
    private function isMulticastAddress(string $ip): bool
    {
        $parts = explode('.', $ip);
        if (count($parts) !== 4) {
            return false;
        }
        $firstOctet = (int)$parts[0];
        return $firstOctet >= 224 && $firstOctet <= 239;
    }

    /**
     * Check if an IP address is a broadcast address
     */
    private function isBroadcastAddress(string $ip): bool
    {
        if ($ip === '255.255.255.255') {
            return true;
        }
        $parts = explode('.', $ip);
        if (count($parts) !== 4) {
            return false;
        }
        return $parts[3] === '255';
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
     * Get output configuration discrepancies
     * Checks for issues like outputs to remote-mode systems and missing sequences
     */
    public function getOutputDiscrepancies(): array
    {
        $cacheFile = WATCHERLOGDIR . '/watcher-discrepancies-cache.json';
        $cacheMaxAge = 60; // seconds

        // Read config to check which issue checks are enabled
        $config = readPluginConfig();
        $checkOutputs = !empty($config['issueCheckOutputs']);
        $checkSequences = !empty($config['issueCheckSequences']);
        $checkOutputHostsNotInSync = !empty($config['issueCheckOutputHostsNotInSync']);

        // If all checks are disabled, return empty result (no cache needed)
        if (!$checkOutputs && !$checkSequences && !$checkOutputHostsNotInSync) {
            return [
                'success' => true,
                'discrepancies' => [],
                'remoteCount' => 0
            ];
        }

        // Check cache
        if (file_exists($cacheFile)) {
            $cacheAge = time() - filemtime($cacheFile);
            if ($cacheAge < $cacheMaxAge) {
                $cached = json_decode(file_get_contents($cacheFile), true);
                if ($cached && isset($cached['success'])) {
                    // Filter cached results based on current config
                    if (isset($cached['discrepancies']) && is_array($cached['discrepancies'])) {
                        $cached['discrepancies'] = array_values(array_filter($cached['discrepancies'], function($d) use ($checkOutputs, $checkSequences, $checkOutputHostsNotInSync) {
                            if ($d['type'] === 'output_to_remote' && !$checkOutputs) return false;
                            if ($d['type'] === 'missing_sequences' && !$checkSequences) return false;
                            if ($d['type'] === 'output_host_not_in_sync' && !$checkOutputHostsNotInSync) return false;
                            return true;
                        }));
                    }
                    return $cached;
                }
            }
        }

        $discrepancies = [];

        // Get remote systems (player/remote only - for Check 1)
        $remoteSystems = getMultiSyncRemoteSystems();
        $remotesByIP = [];
        foreach ($remoteSystems as $remote) {
            if (!empty($remote['address'])) {
                $remotesByIP[$remote['address']] = $remote;
            }
        }

        // Get ALL multisync systems including bridges (for Check 3)
        // This includes all discovered systems regardless of mode
        $allMultiSyncIPs = [];
        if ($checkOutputHostsNotInSync) {
            $allSystems = $this->apiClient->get('http://127.0.0.1/api/fppd/multiSyncSystems', WATCHER_TIMEOUT_STANDARD);
            if ($allSystems && isset($allSystems['systems']) && is_array($allSystems['systems'])) {
                foreach ($allSystems['systems'] as $system) {
                    // Skip local system
                    if (!empty($system['local'])) {
                        continue;
                    }
                    if (!empty($system['address'])) {
                        $allMultiSyncIPs[$system['address']] = $system;
                    }
                }
            }
        }

        // =========================================================================
        // Check 1 & 3: Output configuration issues (shared output data)
        // =========================================================================
        $activeOutputsByIP = [];  // For Check 1: only active outputs
        $allOutputsByIP = [];     // For Check 3: all configured outputs
        if ($checkOutputs || $checkOutputHostsNotInSync) {
            $outputsData = $this->apiClient->get('http://127.0.0.1/api/channel/output/universeOutputs', WATCHER_TIMEOUT_STANDARD);

            if ($outputsData && isset($outputsData['channelOutputs'])) {
                // Build maps of outputs per remote IP
                foreach ($outputsData['channelOutputs'] as $outputGroup) {
                    if (!isset($outputGroup['universes']) || !is_array($outputGroup['universes'])) {
                        continue;
                    }
                    foreach ($outputGroup['universes'] as $universe) {
                        $address = $universe['address'] ?? '';
                        $active = ($universe['active'] ?? 0) == 1;
                        if (empty($address) || !filter_var($address, FILTER_VALIDATE_IP)) {
                            continue;
                        }
                        // All outputs for Check 3 (host not in sync)
                        if (!isset($allOutputsByIP[$address])) {
                            $allOutputsByIP[$address] = [];
                        }
                        $allOutputsByIP[$address][] = $universe;

                        // Only active outputs for Check 1 (output to remote mode)
                        if ($active) {
                            if (!isset($activeOutputsByIP[$address])) {
                                $activeOutputsByIP[$address] = [];
                            }
                            $activeOutputsByIP[$address][] = $universe;
                        }
                    }
                }

                // Check 1: Active outputs to systems in remote mode
                if ($checkOutputs) {
                    foreach ($activeOutputsByIP as $ip => $outputs) {
                        if (!isset($remotesByIP[$ip])) {
                            continue;
                        }
                        $remote = $remotesByIP[$ip];
                        $remoteMode = $remote['fppModeString'] ?? '';
                        $hostname = $remote['hostname'] ?? $ip;

                        if ($remoteMode === 'remote') {
                            foreach ($outputs as $output) {
                                $discrepancies[] = [
                                    'type' => 'output_to_remote',
                                    'severity' => 'warning',
                                    'address' => $ip,
                                    'hostname' => $hostname,
                                    'description' => $output['description'] ?? '',
                                    'startChannel' => $output['startChannel'] ?? 0,
                                    'channelCount' => $output['channelCount'] ?? 0,
                                    'message' => "Output enabled to {$hostname} but it's in remote mode (receives via multisync)"
                                ];
                            }
                        }
                    }
                }

                // Check 3: Outputs targeting hosts not in MultiSync (including bridges)
                // Uses ALL outputs, not just active ones - catch offline hosts even if disabled
                if ($checkOutputHostsNotInSync) {
                    foreach ($allOutputsByIP as $ip => $outputs) {
                        // Skip if this IP is in ANY multisync system (player, remote, or bridge)
                        if (isset($allMultiSyncIPs[$ip])) {
                            continue;
                        }

                        // Skip multicast addresses (224.x.x.x - 239.x.x.x)
                        if ($this->isMulticastAddress($ip)) {
                            continue;
                        }

                        // Skip broadcast addresses
                        if ($this->isBroadcastAddress($ip)) {
                            continue;
                        }

                        // Skip localhost
                        if ($ip === '127.0.0.1') {
                            continue;
                        }

                        // Ping the host to verify it's actually offline before flagging
                        $pingResult = pingHost($ip, null, 1);
                        if ($pingResult['success']) {
                            // Host responds to ping - it's online, just not in multisync
                            // This could be a non-FPP device (Falcon controller, etc.) - skip
                            continue;
                        }

                        // Host doesn't respond - flag each universe
                        foreach ($outputs as $output) {
                            $discrepancies[] = [
                                'type' => 'output_host_not_in_sync',
                                'severity' => 'warning',
                                'address' => $ip,
                                'hostname' => null,
                                'description' => $output['description'] ?? '',
                                'startChannel' => $output['startChannel'] ?? 0,
                                'channelCount' => $output['channelCount'] ?? 0,
                                'message' => "Output configured to {$ip} but host not responding (offline or unreachable)"
                            ];
                        }
                    }
                }
            }
        }

        // =========================================================================
        // Check 2: Missing sequences on remote systems
        // =========================================================================
        if ($checkSequences) {
            $localSequences = $this->apiClient->get('http://127.0.0.1/api/sequence', WATCHER_TIMEOUT_STANDARD);

            if ($localSequences && is_array($localSequences) && count($localSequences) > 0 && count($remoteSystems) > 0) {
                // Build set of local sequence filenames
                $localSeqSet = [];
                foreach ($localSequences as $seq) {
                    $name = is_array($seq) ? ($seq['Name'] ?? '') : $seq;
                    if (!empty($name)) {
                        $localSeqSet[$name] = true;
                    }
                }

                // Fetch sequences from remotes in parallel using CurlMultiHandler
                $handler = new CurlMultiHandler(WATCHER_TIMEOUT_STATUS);
                $hostnames = [];

                foreach ($remoteSystems as $system) {
                    $address = $system['address'];
                    $handler->addRequest($address, "http://{$address}/api/sequence");
                    $hostnames[$address] = $system['hostname'];
                }

                $results = $handler->execute();

                // Collect results and check for missing sequences
                foreach ($results as $address => $result) {
                    if (!$result['success'] || !$result['data']) {
                        continue; // Skip offline/unreachable remotes
                    }

                    $remoteSequences = $result['data'];
                    if (!is_array($remoteSequences)) {
                        continue;
                    }

                    // Build set of remote sequence filenames
                    $remoteSeqSet = [];
                    foreach ($remoteSequences as $seq) {
                        $name = is_array($seq) ? ($seq['Name'] ?? '') : $seq;
                        if (!empty($name)) {
                            $remoteSeqSet[$name] = true;
                        }
                    }

                    // Find sequences on player that are missing from this remote
                    $missingSequences = [];
                    foreach ($localSeqSet as $seqName => $_) {
                        if (!isset($remoteSeqSet[$seqName])) {
                            $missingSequences[] = $seqName;
                        }
                    }

                    if (count($missingSequences) > 0) {
                        $hostname = $hostnames[$address] ?: $address;
                        $count = count($missingSequences);
                        $discrepancies[] = [
                            'type' => 'missing_sequences',
                            'severity' => 'warning',
                            'address' => $address,
                            'hostname' => $hostname,
                            'sequences' => $missingSequences,
                            'message' => "{$count} sequence" . ($count > 1 ? 's' : '') . " missing from {$hostname}"
                        ];
                    }
                }
            }
        }

        $result = [
            'success' => true,
            'discrepancies' => $discrepancies,
            'remoteCount' => count($remoteSystems)
        ];

        // Cache the result
        file_put_contents($cacheFile, json_encode($result));
        ensureFppOwnership($cacheFile);

        return $result;
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
