#!/usr/bin/php
<?php
// Function to reset network adapter using FPP API
function resetNetworkAdapter($adapter) {
    logMessage("Attempting to reset network adapter using FPP API: $adapter");
    
    $apiUrl = "http://127.0.0.1/api/network/interface/$adapter/apply";
    
    $ch = curl_init($apiUrl);
    if ($ch === false) {
        logMessage("ERROR: Failed to initialize cURL");
        return false;
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        logMessage("cURL Error: $curlError");
        return false;
    }
    
    if ($httpCode >= 200 && $httpCode < 300) {
        logMessage("Network adapter reset command sent successfully via FPP API");
        logMessage("API Response (HTTP $httpCode): $response");
        sleep(10);
        return true;
    } else {
        logMessage("Failed to reset network adapter via FPP API (HTTP $httpCode)");
        logMessage("API Response: $response");
        return false;
    }
}
?>