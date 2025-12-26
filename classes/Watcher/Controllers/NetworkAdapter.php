<?php
declare(strict_types=1);

namespace Watcher\Controllers;

use Watcher\Core\Logger;
use Watcher\Core\FileManager;
use Watcher\Http\ApiClient;

/**
 * Network Adapter Controller
 *
 * Provides methods for:
 * - Resetting network adapters using various methods
 * - Network interface detection and gateway discovery
 * - Connectivity reset state management
 * - Connectivity daemon control
 */
class NetworkAdapter
{
    private static ?self $instance = null;
    private Logger $logger;
    private ApiClient $apiClient;

    private function __construct()
    {
        $this->logger = Logger::getInstance();
        $this->apiClient = ApiClient::getInstance();
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    // -------------------------------------------------------------------------
    // Network Interface Detection
    // -------------------------------------------------------------------------

    /**
     * Detect the best active network interface
     *
     * Scores interfaces based on: usable IPv4, global scope, UP state, carrier
     * @return string Interface name (e.g., 'eth0', 'wlan0')
     */
    public function detectActiveInterface(): string
    {
        $interfaces = $this->getAllInterfaces();

        if (empty($interfaces) || !is_array($interfaces)) {
            $this->logger->info("Network interface detection: API call failed or returned invalid data, using fallback");
            return 'eth0';
        }

        // Handle FPP API response wrapping
        if (isset($interfaces['interfaces']) && is_array($interfaces['interfaces'])) {
            $interfaces = $interfaces['interfaces'];
        } elseif (isset($interfaces['data']) && is_array($interfaces['data'])) {
            $interfaces = $interfaces['data'];
        }

        $bestInterface = null;
        $bestScore = -1;

        foreach ($interfaces as $interface) {
            $ifname = $interface['ifname'] ?? null;
            if (!$ifname) {
                continue;
            }

            $addrInfo = $interface['addr_info'] ?? [];
            if (!is_array($addrInfo)) {
                $addrInfo = [];
            }

            $ipv4Candidates = [];
            foreach ($addrInfo as $addr) {
                if (($addr['family'] ?? '') === 'inet' && !empty($addr['local'])) {
                    $scope = $addr['scope'] ?? 'global';
                    $ip = $addr['local'];

                    // Skip link-local IPv4 addresses (169.254.x.x)
                    if (str_starts_with($ip, '169.254.')) {
                        continue;
                    }
                    $ipv4Candidates[] = ['ip' => $ip, 'scope' => $scope];
                }
            }

            if (empty($ipv4Candidates)) {
                $this->logger->info("Network interface detection: Skipping '$ifname' (no usable IPv4 in addr_info)");
                continue;
            }

            $operState = strtoupper($interface['operstate'] ?? '');
            $flags = $interface['flags'] ?? [];
            if (!is_array($flags)) {
                $flags = [];
            }
            $isUp = ($operState === 'UP') || in_array('LOWER_UP', $flags, true) || in_array('RUNNING', $flags, true);
            $hasCarrier = !in_array('NO-CARRIER', $flags, true);

            // Score: usable IPv4 (3), global scope bonus (1), UP (2), has carrier (1)
            $score = 3 + ($isUp ? 2 : 0) + ($hasCarrier ? 1 : 0);

            foreach ($ipv4Candidates as $candidate) {
                if ($candidate['scope'] === 'global') {
                    $score += 1;
                    break;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestInterface = $ifname;
            }

            $candidateIps = array_map(function ($c) {
                return $c['ip'] . ($c['scope'] !== 'global' ? " ({$c['scope']})" : '');
            }, $ipv4Candidates);
            $stateSummary = "state={$operState}, flags=" . implode(',', $flags);
            $this->logger->info("Network interface detection: Candidate '$ifname' with IP(s): " . implode(', ', $candidateIps) . " | $stateSummary | score=$score");
        }

        if ($bestInterface) {
            $this->logger->info("Network interface detection: Selected interface '$bestInterface' (score $bestScore)");
            return $bestInterface;
        }

        $this->logger->info("Network interface detection: No interface with IPv4 found, using fallback 'eth0'");
        return 'eth0';
    }

    /**
     * Detect the gateway IP for a specific interface
     *
     * @param string $interface Network interface name
     * @return string|null Gateway IP address or null if not found/unreachable
     */
    public function detectGateway(string $interface): ?string
    {
        if (empty($interface)) {
            return null;
        }

        $routesOutput = [];
        $gateway = null;

        // Prefer the gateway bound to the detected interface
        exec("ip -4 route show default dev " . escapeshellarg($interface) . " 2>/dev/null", $routesOutput);

        // Fall back to any default route if none found for the interface
        if (empty($routesOutput)) {
            exec("ip -4 route show default 2>/dev/null", $routesOutput);
        }

        foreach ($routesOutput as $line) {
            if (preg_match('/default via ([0-9.]+)/', $line, $matches)) {
                $gateway = $matches[1];
                break;
            }
        }

        if (!$gateway) {
            $this->logger->info("Gateway detection: No default route found for interface '$interface'");
            return null;
        }

        // Confirm the gateway is reachable before suggesting it
        $pingResult = self::ping($gateway, $interface, 1);

        if (!$pingResult['success']) {
            $this->logger->info("Gateway detection: Found gateway '$gateway' for interface '$interface' but ping failed");
            return null;
        }

        $this->logger->info("Gateway detection: Found reachable gateway '$gateway' for interface '$interface'");
        return $gateway;
    }

    /**
     * Ping a host and return result with latency
     *
     * @param string $address IP address or hostname to ping
     * @param string|null $interface Network interface to use (null for system default)
     * @param int $timeout Timeout in seconds (default: 1)
     * @return array{success: bool, latency: float|null, output: array}
     */
    public static function ping(string $address, ?string $interface = null, int $timeout = 1): array
    {
        $output = [];
        $returnVar = 0;

        $cmd = 'ping';
        if ($interface !== null && !empty($interface)) {
            $cmd .= ' -I ' . escapeshellarg($interface);
        }
        $cmd .= ' -c 1 -W ' . intval($timeout) . ' ' . escapeshellarg($address) . ' 2>&1';

        exec($cmd, $output, $returnVar);

        $result = [
            'success' => ($returnVar === 0),
            'latency' => null,
            'output' => $output
        ];

        if ($returnVar === 0) {
            foreach ($output as $line) {
                if (preg_match('/time=([0-9.]+)\s*ms/', $line, $matches)) {
                    $result['latency'] = floatval($matches[1]);
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Validate a host string (IP address or hostname)
     *
     * @param string $host The host to validate
     * @return bool True if valid, false otherwise
     */
    public static function validateHost(string $host): bool
    {
        if (empty($host)) {
            return false;
        }
        // Valid if it's an IP address
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return true;
        }
        // Valid if it matches hostname pattern (alphanumeric, dash, dot)
        if (preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*$/', $host)) {
            return true;
        }
        return false;
    }

    // -------------------------------------------------------------------------
    // Reset State Management
    // -------------------------------------------------------------------------

    /**
     * Read the network adapter reset state
     *
     * @return array|null State array or null if no state file exists
     */
    public function getResetState(): ?array
    {
        $stateFile = $this->getResetStateFile();

        if (!file_exists($stateFile)) {
            return null;
        }

        $content = @file_get_contents($stateFile);
        if ($content === false) {
            return null;
        }

        $state = json_decode($content, true);
        return is_array($state) ? $state : null;
    }

    /**
     * Write the network adapter reset state
     *
     * @param string $adapter Adapter name that was reset
     * @param string $reason Reason for the reset
     * @return bool Success status
     */
    public function setResetState(string $adapter, string $reason = 'Max failures reached'): bool
    {
        $state = [
            'hasResetAdapter' => true,
            'resetTimestamp' => time(),
            'adapter' => $adapter,
            'reason' => $reason
        ];

        $stateFile = $this->getResetStateFile();
        $result = @file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));

        if ($result !== false) {
            FileManager::getInstance()->ensureFppOwnership($stateFile);
        }

        return $result !== false;
    }

    /**
     * Clear the network adapter reset state
     *
     * @return bool Success status
     */
    public function clearResetState(): bool
    {
        $stateFile = $this->getResetStateFile();

        if (file_exists($stateFile)) {
            return @unlink($stateFile);
        }
        return true;
    }

    /**
     * Get the reset state file path
     */
    private function getResetStateFile(): string
    {
        return defined('WATCHERRESETSTATEFILE')
            ? WATCHERRESETSTATEFILE
            : '/home/fpp/media/plugin-data/fpp-plugin-watcher/connectivity/reset-state.json';
    }

    // -------------------------------------------------------------------------
    // Connectivity Daemon Management
    // -------------------------------------------------------------------------

    /**
     * Restart the connectivity check daemon
     *
     * Kills existing process and starts a new one
     * @return bool Always returns true
     */
    public function restartConnectivityDaemon(): bool
    {
        // Kill existing connectivity check process
        exec("pkill -f 'connectivityCheck.php'", $output, $returnVar);

        // Small delay to ensure process is terminated
        usleep(500000); // 0.5 seconds

        // Start new daemon in background
        $pluginDir = defined('WATCHERPLUGINDIR')
            ? WATCHERPLUGINDIR
            : '/home/fpp/media/plugins/fpp-plugin-watcher/';
        $cmd = '/usr/bin/php ' . $pluginDir . 'connectivityCheck.php > /dev/null 2>&1 &';
        exec($cmd, $output, $returnVar);

        $this->logger->info("Connectivity daemon restarted");

        return true;
    }

    /**
     * Reset network adapter using the most effective method for the interface type
     *
     * @param string $adapter The interface name (e.g., eth0, wlan0)
     * @return bool Success status
     */
    public function resetAdapter(string $adapter): bool
    {
        $this->logger->info("Attempting to reset network adapter: $adapter");

        // Validate adapter name (security: prevent command injection)
        if (!preg_match('/^[a-z]+[0-9]+$/', $adapter)) {
            $this->logger->error("Invalid adapter name: $adapter");
            return false;
        }

        $isWifi = str_starts_with($adapter, 'wl');

        if ($isWifi) {
            return $this->resetWifiAdapter($adapter);
        } else {
            return $this->resetEthernetAdapter($adapter);
        }
    }

    /**
     * Reset WiFi adapter by restarting wpa_supplicant and bouncing interface
     */
    public function resetWifiAdapter(string $adapter): bool
    {
        $this->logger->info("Resetting WiFi adapter: $adapter");

        // Method 1: Try wpa_cli reassociate first (least disruptive)
        exec("/usr/sbin/wpa_cli -i $adapter reassociate 2>&1", $output, $rc);
        if ($rc === 0) {
            $this->logger->info("wpa_cli reassociate sent for $adapter");
            sleep(5);
            return true;
        }

        // Method 2: Restart wpa_supplicant service
        $this->logger->info("wpa_cli failed, restarting wpa_supplicant service");
        exec("/usr/bin/systemctl restart wpa_supplicant@$adapter.service 2>&1", $output, $rc);
        if ($rc === 0) {
            $this->logger->info("wpa_supplicant service restarted for $adapter");
            sleep(10);
            return true;
        }

        // Method 3: Bounce the interface
        $this->logger->info("Service restart failed, bouncing interface");
        return $this->bounceInterface($adapter);
    }

    /**
     * Reset Ethernet adapter by bouncing the interface
     */
    public function resetEthernetAdapter(string $adapter): bool
    {
        $this->logger->info("Resetting Ethernet adapter: $adapter");

        // Method 1: Use networkctl reconfigure (cleanest for systemd-networkd)
        exec("/usr/bin/networkctl reconfigure $adapter 2>&1", $output, $rc);
        if ($rc === 0) {
            $this->logger->info("networkctl reconfigure completed for $adapter");
            sleep(5);
            return true;
        }

        // Method 2: Bounce the interface
        $this->logger->info("networkctl failed, bouncing interface");
        return $this->bounceInterface($adapter);
    }

    /**
     * Bounce interface down/up (works for both eth and wlan)
     */
    public function bounceInterface(string $adapter): bool
    {
        exec("/usr/sbin/ip link set $adapter down 2>&1", $output, $rc1);
        sleep(2);
        exec("/usr/sbin/ip link set $adapter up 2>&1", $output, $rc2);

        if ($rc1 === 0 && $rc2 === 0) {
            $this->logger->info("Interface $adapter bounced successfully");
            sleep(10);
            return true;
        }

        // Fallback: Try FPP API as last resort
        $this->logger->info("Interface bounce failed, trying FPP API");
        return $this->resetViaFppApi($adapter);
    }

    /**
     * Original FPP API method as fallback
     */
    public function resetViaFppApi(string $adapter): bool
    {
        $apiUrl = "http://127.0.0.1/api/network/interface/$adapter/apply";
        $result = $this->apiClient->post($apiUrl, [], 30);

        if ($result !== false) {
            $this->logger->info("FPP API reset command sent for $adapter");
            sleep(10);
            return true;
        }

        $this->logger->error("All reset methods failed for $adapter");
        return false;
    }

    /**
     * Get network interface information
     */
    public function getInterfaceInfo(string $adapter): ?array
    {
        $result = $this->apiClient->get("http://127.0.0.1/api/network/interface/$adapter");
        return $result ?: null;
    }

    /**
     * Get all network interfaces
     */
    public function getAllInterfaces(): ?array
    {
        $result = $this->apiClient->get("http://127.0.0.1/api/network/interface");
        return $result ?: null;
    }
}
