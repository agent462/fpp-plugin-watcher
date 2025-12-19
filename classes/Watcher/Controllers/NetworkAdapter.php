<?php
declare(strict_types=1);

namespace Watcher\Controllers;

use Watcher\Core\Logger;
use Watcher\Http\ApiClient;

/**
 * Network Adapter Controller
 *
 * Provides methods for resetting network adapters using various methods
 * appropriate for the interface type (ethernet vs wifi).
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
