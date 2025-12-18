<?php
declare(strict_types=1);

namespace Watcher\Http;

/**
 * Handler for parallel HTTP requests using curl_multi
 */
class CurlMultiHandler
{
    private \CurlMultiHandle $multiHandle;
    /** @var array<string, \CurlHandle> */
    private array $handles = [];
    private int $defaultTimeout;

    public function __construct(int $defaultTimeout = 5)
    {
        $this->multiHandle = curl_multi_init();
        $this->defaultTimeout = $defaultTimeout;
    }

    /**
     * Add a request to the batch
     */
    public function addRequest(string $key, string $url, ?int $timeout = null, ?array $headers = null): self
    {
        $timeout ??= $this->defaultTimeout;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers ?? ['Accept: application/json']
        ]);

        curl_multi_add_handle($this->multiHandle, $ch);
        $this->handles[$key] = $ch;

        return $this;
    }

    /**
     * Get the underlying curl handle for a key (for custom options)
     */
    public function getHandle(string $key): ?\CurlHandle
    {
        return $this->handles[$key] ?? null;
    }

    /**
     * Execute all requests and return results
     *
     * @return array<string, array{success: bool, data: mixed, http_code: int, error: string|null, response_time: float}>
     */
    public function execute(): array
    {
        // Execute all requests
        do {
            $status = curl_multi_exec($this->multiHandle, $active);
            if ($active) {
                curl_multi_select($this->multiHandle);
            }
        } while ($active && $status === CURLM_OK);

        // Collect results
        $results = [];
        foreach ($this->handles as $key => $ch) {
            $response = curl_multi_getcontent($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
            $error = curl_error($ch);

            $success = empty($error) && $httpCode >= 200 && $httpCode < 300;
            $data = null;

            if ($success && $response) {
                $decoded = json_decode($response, true);
                $data = ($decoded !== null) ? $decoded : $response;
            }

            $results[$key] = [
                'success' => $success,
                'data' => $data,
                'raw_response' => $response,
                'http_code' => $httpCode,
                'response_time' => round($totalTime * 1000, 1),
                'error' => $error ?: null
            ];

            curl_multi_remove_handle($this->multiHandle, $ch);
            curl_close($ch);
        }

        $this->handles = [];
        return $results;
    }

    /**
     * Get the multi handle for direct access
     */
    public function getMultiHandle(): \CurlMultiHandle
    {
        return $this->multiHandle;
    }

    /**
     * Clean up
     */
    public function __destruct()
    {
        curl_multi_close($this->multiHandle);
    }
}
