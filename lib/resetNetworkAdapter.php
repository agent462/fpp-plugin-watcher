<?php
include_once __DIR__ . "/apiCall.php";

/**
 * Reset network adapter using the most effective method for the interface type
 * @param string $adapter The interface name (e.g., eth0, wlan0)
 * @return bool Success status
 */
function resetNetworkAdapter($adapter) {
    logMessage("Attempting to reset network adapter: $adapter");

    // Validate adapter name (security: prevent command injection)
    if (!preg_match('/^[a-z]+[0-9]+$/', $adapter)) {
        logMessage("Invalid adapter name: $adapter");
        return false;
    }

    $isWifi = (strpos($adapter, 'wl') === 0);

    if ($isWifi) {
        return resetWifiAdapter($adapter);
    } else {
        return resetEthernetAdapter($adapter);
    }
}

/**
 * Reset WiFi adapter by restarting wpa_supplicant and bouncing interface
 */
function resetWifiAdapter($adapter) {
    logMessage("Resetting WiFi adapter: $adapter");

    // Method 1: Try wpa_cli reassociate first (least disruptive)
    exec("/usr/sbin/wpa_cli -i $adapter reassociate 2>&1", $output, $rc);
    if ($rc === 0) {
        logMessage("wpa_cli reassociate sent for $adapter");
        sleep(5);
        return true;
    }

    // Method 2: Restart wpa_supplicant service
    logMessage("wpa_cli failed, restarting wpa_supplicant service");
    exec("/usr/bin/systemctl restart wpa_supplicant@$adapter.service 2>&1", $output, $rc);
    if ($rc === 0) {
        logMessage("wpa_supplicant service restarted for $adapter");
        sleep(10);
        return true;
    }

    // Method 3: Bounce the interface
    logMessage("Service restart failed, bouncing interface");
    return bounceInterface($adapter);
}

/**
 * Reset Ethernet adapter by bouncing the interface
 */
function resetEthernetAdapter($adapter) {
    logMessage("Resetting Ethernet adapter: $adapter");

    // Method 1: Use networkctl reconfigure (cleanest for systemd-networkd)
    exec("/usr/bin/networkctl reconfigure $adapter 2>&1", $output, $rc);
    if ($rc === 0) {
        logMessage("networkctl reconfigure completed for $adapter");
        sleep(5);
        return true;
    }

    // Method 2: Bounce the interface
    logMessage("networkctl failed, bouncing interface");
    return bounceInterface($adapter);
}

/**
 * Bounce interface down/up (works for both eth and wlan)
 */
function bounceInterface($adapter) {
    exec("/usr/sbin/ip link set $adapter down 2>&1", $output, $rc1);
    sleep(2);
    exec("/usr/sbin/ip link set $adapter up 2>&1", $output, $rc2);

    if ($rc1 === 0 && $rc2 === 0) {
        logMessage("Interface $adapter bounced successfully");
        sleep(10);
        return true;
    }

    // Fallback: Try FPP API as last resort
    logMessage("Interface bounce failed, trying FPP API");
    return resetViaFppApi($adapter);
}

/**
 * Original FPP API method as fallback
 */
function resetViaFppApi($adapter) {
    $apiUrl = "http://127.0.0.1/api/network/interface/$adapter/apply";
    $result = apiCall('POST', $apiUrl, [], false, 30);

    if ($result) {
        logMessage("FPP API reset command sent for $adapter");
        sleep(10);
        return true;
    }

    logMessage("All reset methods failed for $adapter");
    return false;
}
?>
