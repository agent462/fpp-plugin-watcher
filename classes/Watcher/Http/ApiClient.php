<?php
declare(strict_types=1);

namespace Watcher\Http;

use Watcher\Core\Logger;

/**
 * HTTP API client for FPP and remote systems
 */
class ApiClient
{
    private static ?self $instance = null;
    private Logger $logger;
    private int $defaultTimeout;

    private function __construct(int $defaultTimeout = 15)
    {
        $this->logger = Logger::getInstance();
        $this->defaultTimeout = $defaultTimeout;
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Make an HTTP request
     *
     * @param string $method HTTP method (GET, POST, PUT, DELETE)
     * @param string $uri Full URL
     * @param array|string $data Request data
     * @param bool $returnResponse Whether to return response data
     * @param int|null $timeout Request timeout in seconds
     * @param array|null $headers Custom headers
     * @return mixed Response data or bool
     */
    public function request(
        string $method,
        string $uri,
        array|string $data = [],
        bool $returnResponse = false,
        ?int $timeout = null,
        ?array $headers = null
    ): mixed {
        $timeout ??= $this->defaultTimeout;
        $method = strtoupper($method);

        $ch = curl_init($uri);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

        switch ($method) {
            case 'GET':
                // Don't set Content-Type for GET requests - FPP API doesn't handle it well
                break;

            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers ?? ['Content-Type: application/json']);
                if (!empty($data)) {
                    $postData = is_array($data) ? json_encode($data) : $data;
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
                }
                break;

            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers ?? ['Content-Type: application/json']);
                if (!empty($data)) {
                    $postData = is_array($data) ? json_encode($data) : $data;
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
                }
                break;

            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers ?? ['Content-Type: application/json']);
                break;

            default:
                curl_close($ch);
                return false;
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $this->logger->error("cURL Error: {$curlError}");
            return false;
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            if ($returnResponse) {
                $decoded = json_decode($response, true);
                return ($decoded !== null) ? $decoded : $response;
            }
            return true;
        }

        $this->logger->error("API Request failed: {$uri} (HTTP {$httpCode})");
        return false;
    }

    /**
     * Convenience method for GET requests
     */
    public function get(string $uri, ?int $timeout = null): mixed
    {
        return $this->request('GET', $uri, [], true, $timeout);
    }

    /**
     * Convenience method for POST requests
     */
    public function post(string $uri, array|string $data = [], ?int $timeout = null): mixed
    {
        return $this->request('POST', $uri, $data, true, $timeout);
    }

    /**
     * Convenience method for PUT requests
     */
    public function put(string $uri, array|string $data = [], ?int $timeout = null, ?array $headers = null): mixed
    {
        return $this->request('PUT', $uri, $data, true, $timeout, $headers);
    }

    /**
     * Convenience method for DELETE requests
     */
    public function delete(string $uri, ?int $timeout = null): mixed
    {
        return $this->request('DELETE', $uri, [], true, $timeout);
    }
}
