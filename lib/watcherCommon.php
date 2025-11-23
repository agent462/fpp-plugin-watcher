<?php
include_once "/opt/fpp/www/common.php";

global $settings;

define("WATCHERPLUGINNAME", 'fpp-plugin-watcher');
define("WATCHERVERSION", 'v1.1.0');

define("WATCHERPLUGINDIR", $settings['pluginDirectory']."/".WATCHERPLUGINNAME."/");
define("WATCHERCONFIGFILELOCATION", $settings['configDirectory']."/plugin.".WATCHERPLUGINNAME);
define("WATCHERLOGFILE", $settings['logDirectory']."/".WATCHERPLUGINNAME.".log");
define("WATCHERPINGMETRICSFILE", $settings['logDirectory']."/".WATCHERPLUGINNAME."-ping-metrics.log");
define("WATCHERFPPUSER", 'fpp');
define("WATCHERFPPGROUP", 'fpp');
define("WATCHERDEFAULTSETTINGS",
    array(
        'connectivityCheckEnabled' => false,
        'checkInterval' => 20,
        'maxFailures' => 3,
        'networkAdapter' => 'default',
        'testHosts' => '8.8.8.8,1.1.1.1',
        'metricsRotationInterval' => 1800,
        'collectdEnabled' => false)
        );

// Ensure plugin-created files are owned by the FPP user/group for web access
function ensureFppOwnership($path) {
    if (!$path || !file_exists($path)) {
        return;
    }

    @chown($path, WATCHERFPPUSER);
    @chgrp($path, WATCHERFPPGROUP);
}

// Function to log messages
function logMessage($message, $file = WATCHERLOGFILE) {
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message\n";

    // Serialize writes to avoid interleaving across processes
    $fp = @fopen($file, 'a');
    if ($fp) {
        if (flock($fp, LOCK_EX)) {
            fwrite($fp, $logEntry);
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }

    ensureFppOwnership($file);
}

// Function to fetch network interfaces from FPP API
function fetchWatcherNetworkInterfaces() {
    include_once __DIR__ . "/apiCall.php";

    $result = apiCall('GET', 'http://127.0.0.1/api/network/interface', [], true);

    if ($result === false || !is_array($result)) {
        logMessage("Failed to retrieve network interfaces from FPP API");
        return [];
    }

    return $result;
}

// Function to detect active network interface
function detectActiveNetworkInterface() {
    $interfaces = fetchWatcherNetworkInterfaces();

    // If API call failed or no interfaces returned
    if (empty($interfaces)) {
        logMessage("Network interface detection: API call failed, using fallback 'eth0'");
        return 'eth0';
    }

    // Look for first interface with an active IP address
    foreach ($interfaces as $interface) {
        if (isset($interface['addr_info']) && is_array($interface['addr_info']) && !empty($interface['addr_info'])) {
            $ifname = $interface['ifname'];
            logMessage("Network interface detection: Found active interface '$ifname' with IP address");
            return $ifname;
        }
    }

    // No interface found with IP, fallback to eth0
    logMessage("Network interface detection: No interface with IP found, using fallback 'eth0'");
    return 'eth0';
}
?>
