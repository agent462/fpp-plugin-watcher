<?php
include_once "/opt/fpp/www/common.php";

global $settings;

define("WATCHERVERSION", 'v1.1.0');
define("WATCHERPLUGINNAME", 'fpp-plugin-watcher');
define("WATCHERPLUGINDIR", $settings['pluginDirectory']."/".WATCHERPLUGINNAME."/");
define("WATCHERCONFIGFILELOCATION", $settings['configDirectory']."/plugin.".WATCHERPLUGINNAME);
define("WATCHERLOGFILE", $settings['logDirectory']."/".WATCHERPLUGINNAME.".log");
define("WATCHERPINGMETRICSFILE", $settings['logDirectory']."/".WATCHERPLUGINNAME."-ping-metrics.log");
define("WATCHERDEFAULTSETTINGS",
    array(
        'enabled' => false,
        'checkInterval' => 20,
        'maxFailures' => 3,
        'networkAdapter' => 'eth0',
        'testHosts' => '8.8.8.8,1.1.1.1',
        'metricsRotationInterval' => 1800)
        );

// Function to log messages
function logMessage($message, $file = WATCHERLOGFILE) {
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message\n";
    file_put_contents($file, $logEntry, FILE_APPEND);
}
?>