<?php
include_once "/opt/fpp/www/common.php";

global $settings;

define("WATCHERVERSION", 'v1.1.0');
define("WATCHERPLUGINNAME", 'fpp-plugin-watcher');
define("WATCHERPLUGINDIR", $settings['pluginDirectory']."/".WATCHERPLUGINNAME."/");
define("WATCHERCONFIGFILELOCATION", $settings['configDirectory']."/plugin.".WATCHERPLUGINNAME);
define("WATCHERLOGFILE", $settings['logDirectory']."/".WATCHERPLUGINNAME.".log");
define("WATCHERPINGMETRICSFILE", $settings['logDirectory']."/".WATCHERPLUGINNAME."-ping-metrics.log");
define("WATCHERFPPUSER", 'fpp');
define("WATCHERFPPGROUP", 'fpp');
define("WATCHERDEFAULTSETTINGS",
    array(
        'enabled' => false,
        'checkInterval' => 20,
        'maxFailures' => 3,
        'networkAdapter' => 'eth0',
        'testHosts' => '8.8.8.8,1.1.1.1',
        'metricsRotationInterval' => 1800)
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
?>
