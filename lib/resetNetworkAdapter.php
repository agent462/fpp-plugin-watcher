<?php
include_once __DIR__ . "/apiCall.php";

// Function to reset network adapter using FPP API
function resetNetworkAdapter($adapter) {
    logMessage("Attempting to reset network adapter using FPP API: $adapter");

    $apiUrl = "http://127.0.0.1/api/network/interface/$adapter/apply";

    // Use common apiCall function with 30-second timeout
    $result = apiCall('POST', $apiUrl, [], false, 30);

    if ($result) {
        logMessage("Network adapter reset command sent successfully via FPP API");
        sleep(10);
        return true;
    } else {
        logMessage("Failed to reset network adapter via FPP API");
        return false;
    }
}
?>