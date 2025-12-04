<?php
// Function to make calls to the FPP API
function apiCall($method, $uri, $data = [], $returnResponse = false, $timeout = 15, $headers = null) {

    $ch = curl_init($uri);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

    $method = strtoupper($method);

    // Handle GET requests
    if ($method === 'GET') {
        // Don't set Content-Type for GET requests - FPP API doesn't handle it well
        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_URL, $uri);
        }
    }
    // Handle POST requests
    elseif ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers ?: ['Content-Type: application/json']);
        if (!empty($data)) {
            settype($data, "string");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
    }
    // Handle PUT requests
    elseif ($method === 'PUT') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers ?: ['Content-Type: application/json']);
        if (!empty($data)) {
            if (is_array($data)) {
                $data = json_encode($data);
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
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
        #logMessage("API Request sent successfully via FPP API"); \\ make this debug in future
        #logMessage("API Response (HTTP $httpCode): $response"); \\ make this debug in future

        // Return response data if requested
        if ($returnResponse) {
            $decoded = json_decode($response, true);
            return ($decoded !== null) ? $decoded : $response;
        }
        return true;
    } else {
        logMessage("API Request failed via FPP API");
        logMessage("API Response (HTTP $httpCode): $response");
        return false;
    }
}
?>