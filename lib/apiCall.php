#!/usr/bin/php
<?php
// Function to make calls to the FPP API
function apiCall($method, $uri, $data = []) {
    logMessage("Attempting to make the api call to: $uri");
        
    $ch = curl_init($uri);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json'
    ]);

    // Handle GET requests
    if (strtoupper($method) === 'GET') {
        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_URL, $uri);
        }
    } 
    // Handle POST requests
    elseif (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if (!empty($data)) {
            settype($data, "string");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data); // Can be array or URL-encoded string
        }
    } else {
        // Handle other methods if needed, or throw an error
        curl_close($ch);
        return false; // Unsupported method
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        logMessage("cURL Error: $curlError");
        return false;
    }
    
    if ($httpCode >= 200 && $httpCode < 300) {
        logMessage("API Request sent successfully via FPP API");
        logMessage("API Response (HTTP $httpCode): $response");
        return true;
    } else {
        logMessage("API Request failed via FPP API");
        logMessage("API Response (HTTP $httpCode): $response");
        return false;
    }
}
?>