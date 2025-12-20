<?php
declare(strict_types=1);

namespace Watcher\Controllers;

use Watcher\Core\Logger;

/**
 * Falcon Controller API Library
 *
 * PHP library for interacting with Falcon pixel controllers (F4, F16, F48, etc.)
 * over HTTP. These controllers expose an XML-based API for status monitoring
 * and configuration.
 */
class FalconController
{
    // V2/V3 Controllers (XML API)
    public const F4V2_PRODUCT_CODE = 1;
    public const F16V2_PRODUCT_CODE = 2;
    public const F4V3_PRODUCT_CODE = 3;
    public const F16V3_PRODUCT_CODE = 5;
    public const F48_PRODUCT_CODE = 7;

    // V4/V5 Controllers (JSON API)
    public const F16V4_PRODUCT_CODE = 128;
    public const F48V4_PRODUCT_CODE = 129;
    public const F16V5_PRODUCT_CODE = 130;
    public const F48V5_PRODUCT_CODE = 131;
    public const F32V5_PRODUCT_CODE = 132;

    // Controller Modes (from strings.xml cm attribute)
    public const MODE_E131 = 0;
    public const MODE_ZCPP = 16;
    public const MODE_DDP = 64;
    public const MODE_ARTNET = 128;

    // V4/V5 Operating Modes (from JSON API O field)
    public const V4_MODE_E131_ARTNET = 0;
    public const V4_MODE_ZCPP = 1;
    public const V4_MODE_DDP = 2;
    public const V4_MODE_FPP_REMOTE = 3;
    public const V4_MODE_FPP_MASTER = 4;
    public const V4_MODE_FPP_PLAYER = 5;

    private string $host;
    private int $port;
    private int $timeout;
    private ?string $lastError = null;
    private ?array $statusCache = null;
    private int $statusCacheTime = 0;
    private int $cacheTTL;
    private ?bool $isV4 = null;

    /**
     * Constructor
     *
     * @param string $host Controller IP address or hostname
     * @param int $port HTTP port (default 80)
     * @param int $timeout Connection timeout in seconds (default 5)
     * @param int $cacheTTL Status cache TTL in seconds (default 5)
     */
    public function __construct(string $host, int $port = 80, int $timeout = 5, int $cacheTTL = 5)
    {
        $this->host = $host;
        $this->port = $port;
        $this->timeout = $timeout;
        $this->cacheTTL = $cacheTTL;
    }

    /**
     * Get the base URL for the controller
     */
    private function getBaseUrl(): string
    {
        return "http://{$this->host}:{$this->port}";
    }

    /**
     * Make an HTTP GET request
     *
     * @param string $endpoint API endpoint path
     * @return string|false Response body or false on error
     */
    private function httpGet(string $endpoint): string|false
    {
        $url = $this->getBaseUrl() . $endpoint;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => ['Accept: application/xml, text/xml, */*']
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || !empty($error)) {
            $this->lastError = "HTTP GET failed: $error";
            return false;
        }

        if ($httpCode !== 200) {
            $this->lastError = "HTTP GET returned status $httpCode";
            return false;
        }

        return $response;
    }

    /**
     * Make an HTTP POST request
     *
     * @param string $endpoint API endpoint path
     * @param array|string $data POST data
     * @return string|false Response body or false on error
     */
    private function httpPost(string $endpoint, array|string $data): string|false
    {
        $url = $this->getBaseUrl() . $endpoint;

        if (is_array($data)) {
            $data = http_build_query($data);
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/xml, text/xml, text/html, */*'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || !empty($error)) {
            $this->lastError = "HTTP POST failed: $error";
            return false;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->lastError = "HTTP POST returned status $httpCode";
            return false;
        }

        return $response;
    }

    /**
     * Make an HTTP POST request with JSON body (for V4/V5 API)
     *
     * @param string $endpoint API endpoint path
     * @param array $data Data to encode as JSON
     * @return array|false Decoded JSON response or false on error
     */
    private function httpPostJson(string $endpoint, array $data): array|false
    {
        $url = $this->getBaseUrl() . $endpoint;
        $jsonData = json_encode($data);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonData,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || !empty($error)) {
            $this->lastError = "HTTP POST JSON failed: $error";
            return false;
        }

        if ($httpCode !== 200) {
            $this->lastError = "HTTP POST JSON returned status $httpCode";
            return false;
        }

        $decoded = json_decode($response, true);
        if ($decoded === null) {
            $this->lastError = "JSON decode failed";
            return false;
        }

        // Check API response code
        if (isset($decoded['R']) && $decoded['R'] !== 200) {
            $this->lastError = $decoded['E'] ?? "API error code {$decoded['R']}";
            return false;
        }

        return $decoded;
    }

    /**
     * Query V4/V5 API
     *
     * @param string $method API method (ST, SP, IN, etc.)
     * @param int $batch Batch number (default 0)
     * @return array|false Response parameters or false on error
     */
    private function queryV4Api(string $method, int $batch = 0): array|false
    {
        $request = [
            'T' => 'Q',
            'M' => $method,
            'B' => $batch,
            'E' => 0,
            'I' => 0,
            'P' => new \stdClass()
        ];

        $response = $this->httpPostJson('/api', $request);
        if ($response === false) {
            return false;
        }

        return $response['P'] ?? [];
    }

    /**
     * Set V4/V5 API value
     *
     * @param string $method API method
     * @param array $params Parameters to set
     * @return array|false Response or false on error
     */
    private function setV4Api(string $method, array $params): array|false
    {
        $request = [
            'T' => 'S',
            'M' => $method,
            'B' => 0,
            'E' => 0,
            'I' => 0,
            'P' => $params
        ];

        return $this->httpPostJson('/api', $request);
    }

    /**
     * Check if this is a V4/V5 controller (product code >= 128)
     *
     * @param int|null $productCode Optional product code to check
     * @return bool True if V4/V5
     */
    public function isV4Controller(?int $productCode = null): bool
    {
        if ($productCode !== null) {
            return $productCode >= 128;
        }

        if ($this->isV4 !== null) {
            return $this->isV4;
        }

        // Detect by checking status.xml product code
        $response = $this->httpGet('/status.xml');
        if ($response !== false) {
            $xml = $this->parseXml($response);
            if ($xml !== false && isset($xml->p)) {
                $this->isV4 = ((int)$xml->p >= 128);
                return $this->isV4;
            }
        }

        return false;
    }

    /**
     * Parse XML response into SimpleXMLElement
     *
     * @param string $xml XML string
     * @return \SimpleXMLElement|false Parsed XML or false on error
     */
    private function parseXml(string $xml): \SimpleXMLElement|false
    {
        libxml_use_internal_errors(true);
        $parsed = simplexml_load_string($xml);

        if ($parsed === false) {
            $errors = libxml_get_errors();
            $errorMsg = !empty($errors) ? $errors[0]->message : 'Unknown XML error';
            $this->lastError = "XML parse error: $errorMsg";
            libxml_clear_errors();
            return false;
        }

        return $parsed;
    }

    /**
     * Get the last error message
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Check if controller is reachable
     */
    public function isReachable(): bool
    {
        $response = $this->httpGet('/status.xml');
        return $response !== false;
    }

    // ==================== STATUS METHODS ====================

    /**
     * Get controller status (cached)
     * Auto-detects V4/V5 controllers and uses appropriate API
     *
     * @param bool $forceRefresh Force cache refresh
     * @return array|false Status data or false on error
     */
    public function getStatus(bool $forceRefresh = false): array|false
    {
        // Check cache
        if (!$forceRefresh && $this->statusCache !== null &&
            (time() - $this->statusCacheTime) < $this->cacheTTL) {
            return $this->statusCache;
        }

        // First get basic status.xml to detect controller type
        $response = $this->httpGet('/status.xml');
        if ($response === false) {
            return false;
        }

        $xml = $this->parseXml($response);
        if ($xml === false) {
            return false;
        }

        $productCode = (int)$xml->p;

        // V4/V5 controllers (product code >= 128) use JSON API
        if ($this->isV4Controller($productCode)) {
            $this->isV4 = true;
            return $this->getStatusV4($xml);
        }

        // V2/V3 controllers use XML API
        $this->isV4 = false;
        return $this->getStatusV2V3($xml);
    }

    /**
     * Get status for V2/V3 controllers using XML API
     *
     * @param \SimpleXMLElement $xml Parsed status.xml
     * @return array|false Status data or false on error
     */
    private function getStatusV2V3(\SimpleXMLElement $xml): array|false
    {
        $status = [
            'firmware_version' => (string)$xml->fv,
            'name' => trim((string)$xml->n),
            'product_code' => (int)$xml->p,
            'address_mode' => (int)$xml->a,
            'controller_mode' => (int)$xml->m,
            'num_ports' => (int)$xml->np,
            'num_strings' => (int)$xml->ns,
            'uptime' => (string)$xml->u,
            'time' => (string)$xml->t,
            'date' => (string)$xml->d,
            'temperature1' => (float)$xml->t1,
            'temperature2' => (float)$xml->t2,
            'temperature3' => (float)$xml->t3,
            'voltage1' => (string)$xml->v1,
            'voltage2' => (string)$xml->v2,
            'fan_speed' => (int)$xml->f,
            'zcpp_frames' => (int)$xml->zf,
            'zcpp_sequence' => (int)$xml->zs,
            'pixels_bank0' => (int)$xml->k0,
            'pixels_bank1' => (int)$xml->k1,
            'pixels_bank2' => (int)$xml->k2,
            'is_v4' => false,
        ];

        // Fetch the actual controller mode from strings.xml (cm attribute)
        $stringsResponse = $this->httpGet('/strings.xml');
        if ($stringsResponse !== false) {
            $stringsXml = $this->parseXml($stringsResponse);
            if ($stringsXml !== false && isset($stringsXml['cm'])) {
                $status['controller_mode'] = (int)$stringsXml['cm'];
            }
        }

        // Add derived info
        $status['model'] = self::getModelName($status['product_code']);
        $status['mode_name'] = $this->getModeName($status['controller_mode'], false);

        // Cache the result
        $this->statusCache = $status;
        $this->statusCacheTime = time();

        return $status;
    }

    /**
     * Get status for V4/V5 controllers using JSON API
     *
     * @param \SimpleXMLElement $xml Parsed status.xml (for basic info)
     * @return array|false Status data or false on error
     */
    private function getStatusV4(\SimpleXMLElement $xml): array|false
    {
        // Get comprehensive status from JSON API
        $v4Status = $this->queryV4Api('ST');
        if ($v4Status === false) {
            // Fall back to basic XML info if JSON API fails
            return $this->getStatusV4FromXml($xml);
        }

        // Map V4 API fields to standard status format
        $status = [
            'firmware_version' => $v4Status['V'] ?? (string)$xml->fv,
            'name' => $v4Status['N'] ?? trim((string)$xml->n),
            'product_code' => (int)$xml->p,
            'address_mode' => $v4Status['A'] ?? 0,
            'controller_mode' => $v4Status['O'] ?? 0,
            'num_ports' => $v4Status['P'] ?? (int)$xml->np,
            'num_strings' => $v4Status['S'] ?? (int)$xml->ns,
            'uptime' => $this->formatUptime($v4Status['U'] ?? 0),
            'time' => $v4Status['TM'] ?? '',
            'date' => $v4Status['DT'] ?? '',
            // Temperatures are in tenths of degrees
            'temperature1' => ($v4Status['T1'] ?? 0) / 10,
            'temperature2' => ($v4Status['T2'] ?? 0) / 10,
            'temperature3' => ($v4Status['PT'] ?? 0) / 10, // Power temp as temp3
            // Voltages are in tenths of volts
            'voltage1' => sprintf('%.1fV', ($v4Status['V1'] ?? 0) / 10),
            'voltage2' => sprintf('%.1fV', ($v4Status['V2'] ?? 0) / 10),
            'fan_speed' => $v4Status['FN'] ?? 0,
            'zcpp_frames' => 0,
            'zcpp_sequence' => 0,
            'pixels_bank0' => (int)$xml->k0,
            'pixels_bank1' => (int)$xml->k1,
            'pixels_bank2' => (int)($xml->k2 ?? 0),
            'is_v4' => true,
            // V4-specific fields
            'board_mode' => $v4Status['B'] ?? 0,
            'mac_address' => $v4Status['C'] ?? '',
            'ip_address' => $v4Status['I'] ?? '',
            'subnet_mask' => $v4Status['K'] ?? '',
            'gateway' => $v4Status['G'] ?? '',
            'dns' => $v4Status['D'] ?? '',
            'universe_count' => $v4Status['UC'] ?? 0,
            'firmware_build' => $v4Status['FW'] ?? 0,
        ];

        // Add derived info
        $status['model'] = self::getModelName($status['product_code']);
        $status['mode_name'] = $this->getModeName($status['controller_mode'], true);

        // Cache the result
        $this->statusCache = $status;
        $this->statusCacheTime = time();

        return $status;
    }

    /**
     * Get basic V4 status from XML when JSON API fails
     *
     * @param \SimpleXMLElement $xml Parsed status.xml
     * @return array Status data
     */
    private function getStatusV4FromXml(\SimpleXMLElement $xml): array
    {
        $status = [
            'firmware_version' => (string)$xml->fv,
            'name' => trim((string)$xml->n),
            'product_code' => (int)$xml->p,
            'address_mode' => 0,
            'controller_mode' => 0,
            'num_ports' => (int)$xml->np,
            'num_strings' => (int)$xml->ns,
            'uptime' => '',
            'time' => '',
            'date' => '',
            'temperature1' => 0,
            'temperature2' => 0,
            'temperature3' => 0,
            'voltage1' => '',
            'voltage2' => '',
            'fan_speed' => 0,
            'zcpp_frames' => 0,
            'zcpp_sequence' => 0,
            'pixels_bank0' => (int)$xml->k0,
            'pixels_bank1' => (int)$xml->k1,
            'pixels_bank2' => (int)($xml->k2 ?? 0),
            'is_v4' => true,
        ];

        $status['model'] = self::getModelName($status['product_code']);
        $status['mode_name'] = 'Unknown';

        $this->statusCache = $status;
        $this->statusCacheTime = time();

        return $status;
    }

    /**
     * Format uptime seconds into human-readable string
     */
    private function formatUptime(int $seconds): string
    {
        if ($seconds <= 0) {
            return '';
        }

        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        $parts = [];
        if ($days > 0) $parts[] = "{$days}d";
        if ($hours > 0) $parts[] = "{$hours}h";
        if ($minutes > 0 || empty($parts)) $parts[] = "{$minutes}m";

        return implode(' ', $parts);
    }

    /**
     * Get model name from product code
     */
    public static function getModelName(int $productCode): string
    {
        $models = [
            // V2/V3 Controllers
            self::F4V2_PRODUCT_CODE => 'F4V2',
            self::F16V2_PRODUCT_CODE => 'F16V2',
            self::F4V3_PRODUCT_CODE => 'F4V3',
            self::F16V3_PRODUCT_CODE => 'F16V3',
            self::F48_PRODUCT_CODE => 'F48',
            // V4/V5 Controllers
            self::F16V4_PRODUCT_CODE => 'F16V4',
            self::F48V4_PRODUCT_CODE => 'F48V4',
            self::F16V5_PRODUCT_CODE => 'F16V5',
            self::F48V5_PRODUCT_CODE => 'F48V5',
            self::F32V5_PRODUCT_CODE => 'F32V5',
        ];
        return $models[$productCode] ?? "Unknown ($productCode)";
    }

    /**
     * Get mode name from mode code
     */
    public function getModeName(int $modeCode, bool $isV4 = false): string
    {
        if ($isV4) {
            // V4/V5 operating modes from JSON API O field
            $modes = [
                self::V4_MODE_E131_ARTNET => 'E1.31/ArtNet',
                self::V4_MODE_ZCPP => 'ZCPP',
                self::V4_MODE_DDP => 'DDP',
                self::V4_MODE_FPP_REMOTE => 'FPP Remote',
                self::V4_MODE_FPP_MASTER => 'FPP Master',
                self::V4_MODE_FPP_PLAYER => 'FPP Player',
            ];
        } else {
            // V2/V3 controller modes from strings.xml cm attribute
            $modes = [
                self::MODE_E131 => 'E1.31',
                self::MODE_ZCPP => 'ZCPP',
                self::MODE_DDP => 'DDP',
                self::MODE_ARTNET => 'ArtNet',
            ];
        }

        return $modes[$modeCode] ?? "Unknown ($modeCode)";
    }

    /**
     * Get system time from controller
     */
    public function getSystemTime(): array|false
    {
        $response = $this->httpGet('/systemtime.xml');
        if ($response === false) {
            return false;
        }

        $xml = $this->parseXml($response);
        if ($xml === false) {
            return false;
        }

        return [
            'time' => (string)$xml->st,
            'date' => (string)$xml->sd,
        ];
    }

    // ==================== CONFIGURATION METHODS ====================

    /**
     * Get full settings (universes, strings, serial outputs)
     */
    public function getSettings(): array|false
    {
        $response = $this->httpGet('/settings.xml');
        if ($response === false) {
            return false;
        }

        $xml = $this->parseXml($response);
        if ($xml === false) {
            return false;
        }

        return [
            'universes' => $this->parseUniverses($xml->universes),
            'strings' => $this->parseStrings($xml->strings),
            'serial_outputs' => $this->parseSerialOutputs($xml->serialoutputs),
        ];
    }

    /**
     * Get universe configuration
     */
    public function getUniverses(): array|false
    {
        $response = $this->httpGet('/universes.xml');
        if ($response === false) {
            return false;
        }

        $xml = $this->parseXml($response);
        if ($xml === false) {
            return false;
        }

        return [
            'count' => (int)$xml['c'],
            'product_code' => (int)$xml['p'],
            'universes' => $this->parseUniverses($xml),
        ];
    }

    /**
     * Parse universes XML element
     */
    private function parseUniverses(?\SimpleXMLElement $xml): array
    {
        $universes = [];
        if ($xml && $xml->un) {
            foreach ($xml->un as $un) {
                $universes[] = [
                    'universe' => (int)$un['u'],
                    'start_channel' => (int)$un['s'],
                    'size' => (int)$un['l'],
                    'type' => (int)$un['t'],
                ];
            }
        }
        return $universes;
    }

    /**
     * Get string port configuration
     * Auto-detects V4/V5 and uses appropriate API
     */
    public function getStrings(): array|false
    {
        if ($this->isV4 === null) {
            $this->isV4Controller();
        }

        if ($this->isV4) {
            return $this->getStringsV4();
        }

        return $this->getStringsV2V3();
    }

    /**
     * Get string port configuration for V2/V3 controllers
     */
    private function getStringsV2V3(): array|false
    {
        $response = $this->httpGet('/strings.xml');
        if ($response === false) {
            return false;
        }

        $xml = $this->parseXml($response);
        if ($xml === false) {
            return false;
        }

        return [
            'controller_mode' => (int)$xml['cm'],
            'direction' => (int)$xml['d'],
            'product_code' => (int)$xml['p'],
            'count' => (int)$xml['c'],
            'mode' => (int)$xml['m'],
            'address_mode' => (int)$xml['a'],
            'pixels_bank0' => (int)$xml['k0'],
            'pixels_bank1' => (int)$xml['k1'],
            'pixels_bank2' => (int)$xml['k2'],
            'test_enabled' => (int)$xml['t'],
            'test_mode' => (int)$xml['tm'],
            'strings' => $this->parseStrings($xml),
            'is_v4' => false,
        ];
    }

    /**
     * Get string port configuration for V4/V5 controllers using JSON API
     */
    private function getStringsV4(): array|false
    {
        $spData = $this->queryV4Api('SP');
        if ($spData === false) {
            return false;
        }

        $strings = [];
        if (isset($spData['A']) && is_array($spData['A'])) {
            foreach ($spData['A'] as $port) {
                $strings[] = [
                    'description' => $port['nm'] ?? '',
                    'port' => $port['p'] ?? 0,
                    'universe' => $port['u'] ?? 0,
                    'universe_start' => $port['sc'] ?? 0,
                    'absolute_start' => $port['s'] ?? 0,
                    'pixel_count' => $port['n'] ?? 0,
                    'group_count' => $port['gp'] ?? 1,
                    'protocol' => $port['l'] ?? 0,
                    'direction' => $port['r'] ?? 0,
                    'color_order' => $port['o'] ?? 0,
                    'null_pixels' => $port['ns'] ?? 0,
                    'zig_zag' => $port['z'] ?? 0,
                    'brightness' => $port['b'] ?? 100,
                    'brightness_limit' => $port['bl'] ?? 0,
                    'gamma' => $port['g'] ?? 10,
                    'smart_remote' => $port['v'] ?? 0,
                    'smart_remote_id' => 0,
                    'enabled' => 1,
                    // V4-specific fields
                    'current' => $port['a'] ?? 0,
                    'fuse_status' => $port['f'] ?? 0,
                ];
            }
        }

        // Get status for additional info
        $status = $this->getStatus();
        $productCode = $status['product_code'] ?? 0;

        return [
            'controller_mode' => $status['controller_mode'] ?? 0,
            'direction' => 0,
            'product_code' => $productCode,
            'count' => count($strings),
            'mode' => 0,
            'address_mode' => $status['address_mode'] ?? 0,
            'pixels_bank0' => $status['pixels_bank0'] ?? 0,
            'pixels_bank1' => $status['pixels_bank1'] ?? 0,
            'pixels_bank2' => $status['pixels_bank2'] ?? 0,
            'test_enabled' => 0,
            'test_mode' => 0,
            'strings' => $strings,
            'is_v4' => true,
        ];
    }

    /**
     * Parse strings XML element
     */
    private function parseStrings(?\SimpleXMLElement $xml): array
    {
        $strings = [];
        if ($xml && $xml->vs) {
            foreach ($xml->vs as $vs) {
                $strings[] = [
                    'description' => (string)$vs['y'],
                    'port' => (int)$vs['p'],
                    'universe' => (int)$vs['u'],
                    'universe_start' => (int)$vs['us'],
                    'absolute_start' => (int)$vs['s'],
                    'pixel_count' => (int)$vs['c'],
                    'group_count' => (int)$vs['g'],
                    'protocol' => (int)$vs['t'],
                    'direction' => (int)$vs['d'],
                    'color_order' => (int)$vs['o'],
                    'null_pixels' => (int)$vs['n'],
                    'zig_zag' => (int)$vs['z'],
                    'brightness' => (int)$vs['b'],
                    'brightness_limit' => (int)$vs['bl'],
                    'gamma' => (int)$vs['ga'],
                    'smart_remote' => (int)$vs['sr'],
                    'smart_remote_id' => (int)$vs['si'],
                    'enabled' => (int)$vs['e'],
                ];
            }
        }
        return $strings;
    }

    /**
     * Get serial output configuration
     */
    public function getSerialOutputs(): array|false
    {
        $response = $this->httpGet('/serialsettings.xml');
        if ($response === false) {
            return false;
        }

        $xml = $this->parseXml($response);
        if ($xml === false) {
            return false;
        }

        return [
            'product_code' => (int)$xml['p'],
            'mode' => (int)$xml['m'],
            'address_mode' => (int)$xml['a'],
            'outputs' => $this->parseSerialOutputs($xml),
        ];
    }

    /**
     * Parse serial outputs XML element
     */
    private function parseSerialOutputs(?\SimpleXMLElement $xml): array
    {
        $outputs = [];
        if ($xml && $xml->so) {
            foreach ($xml->so as $so) {
                $outputs[] = [
                    'type' => (int)$so['t'],
                    'baud' => (int)$so['b'],
                    'stop_bits' => (int)$so['sb'],
                    'universe' => (int)$so['u'],
                    'universe_start' => (int)$so['us'],
                    'absolute_start' => (int)$so['s'],
                    'num_channels' => (int)$so['m'],
                    'gamma' => (int)$so['g'],
                    'index' => (int)$so['i'],
                    'enabled' => (int)$so['e'],
                ];
            }
        }
        return $outputs;
    }

    // ==================== PACKET STATISTICS ====================

    /**
     * Get packet statistics per universe
     */
    public function getPacketStats(): array|false
    {
        $response = $this->httpGet('/packet.xml');
        if ($response === false) {
            return false;
        }

        $xml = $this->parseXml($response);
        if ($xml === false) {
            return false;
        }

        $packets = [];
        if ($xml->p) {
            $universe = 1;
            foreach ($xml->p as $p) {
                $packets[$universe] = (int)$p;
                $universe++;
            }
        }

        return $packets;
    }

    /**
     * Get DDP packet data
     */
    public function getDdpData(): array|false
    {
        $response = $this->httpGet('/ddpdata.xml');
        if ($response === false) {
            return false;
        }

        $xml = $this->parseXml($response);
        if ($xml === false) {
            return false;
        }

        return [
            'count' => (int)$xml['c'],
            'product_code' => (int)$xml['p'],
        ];
    }

    // ==================== TEST MODE ====================

    /**
     * Enable test mode
     * Auto-detects V4/V5 and uses appropriate API
     */
    public function enableTest(int $testMode = 5, bool $allPorts = true): bool
    {
        if ($this->isV4 === null) {
            $this->isV4Controller();
        }

        if ($this->isV4) {
            return $this->enableTestV4($testMode, $allPorts);
        }

        return $this->enableTestV2V3($testMode, $allPorts);
    }

    /**
     * Enable test mode for V2/V3 controllers
     */
    private function enableTestV2V3(int $testMode, bool $allPorts): bool
    {
        $data = [
            't' => 1,
            'm' => $testMode,
        ];

        if ($allPorts) {
            $strings = $this->getStringsV2V3();
            if ($strings !== false && !empty($strings['strings'])) {
                foreach ($strings['strings'] as $index => $string) {
                    $data['e' . $index] = 1;
                }
            }

            $serial = $this->getSerialOutputs();
            if ($serial !== false && !empty($serial['outputs'])) {
                foreach ($serial['outputs'] as $index => $output) {
                    $data['s' . $index] = 1;
                }
            }
        }

        $response = $this->httpPost('/test.htm', $data);
        return $response !== false;
    }

    /**
     * Build port array for V4/V5 test mode API
     */
    private function buildV4PortArray(int $numPorts): array
    {
        $portArray = [];
        for ($i = 0; $i < $numPorts; $i++) {
            $portArray[] = [
                'P' => $i,
                'R' => 0,
                'S' => 0,
            ];
        }
        return $portArray;
    }

    /**
     * Build and send V4/V5 test mode API request
     */
    private function sendV4TestRequest(array $params, int $numPorts): bool
    {
        $request = [
            'T' => 'S',
            'M' => 'TS',
            'B' => 0,
            'E' => $numPorts,
            'I' => 0,
            'P' => $params,
        ];

        $response = $this->httpPostJson('/api', $request);
        if ($response === false) {
            return false;
        }

        if (isset($response['F']) && $response['F'] !== 1) {
            $this->lastError = "Test mode request incomplete - try again";
            return false;
        }

        return true;
    }

    /**
     * Enable test mode for V4/V5 controllers using JSON API
     */
    private function enableTestV4(int $testMode, bool $allPorts): bool
    {
        $status = $this->getStatus();
        $numPorts = $status['num_ports'] ?? 32;

        $testModeY = max(1, min(7, $testMode));

        $params = [
            'E' => 'Y',
            'Y' => $testModeY,
            'S' => 20,
            'C' => 1073741824,
            'D' => 'Y',
            'A' => $this->buildV4PortArray($numPorts),
        ];

        return $this->sendV4TestRequest($params, $numPorts);
    }

    /**
     * Disable test mode
     * Auto-detects V4/V5 and uses appropriate API
     */
    public function disableTest(): bool
    {
        if ($this->isV4 === null) {
            $this->isV4Controller();
        }

        if ($this->isV4) {
            return $this->disableTestV4();
        }

        return $this->disableTestV2V3();
    }

    /**
     * Disable test mode for V2/V3 controllers
     */
    private function disableTestV2V3(): bool
    {
        $data = [
            't' => 0,
            'm' => 0,
        ];

        $strings = $this->getStringsV2V3();
        if ($strings !== false && !empty($strings['strings'])) {
            foreach ($strings['strings'] as $index => $string) {
                $data['e' . $index] = 0;
            }
        }

        $serial = $this->getSerialOutputs();
        if ($serial !== false && !empty($serial['outputs'])) {
            foreach ($serial['outputs'] as $index => $output) {
                $data['s' . $index] = 0;
            }
        }

        $response = $this->httpPost('/test.htm', $data);
        return $response !== false;
    }

    /**
     * Disable test mode for V4/V5 controllers
     */
    private function disableTestV4(): bool
    {
        $status = $this->getStatus();
        $numPorts = $status['num_ports'] ?? 32;

        $params = [
            'E' => 'N',
            'Y' => 1,
            'S' => 20,
            'C' => 0,
            'D' => 'Y',
            'A' => $this->buildV4PortArray($numPorts),
        ];

        return $this->sendV4TestRequest($params, $numPorts);
    }

    /**
     * Get test mode status
     * Auto-detects V4/V5 and uses appropriate API
     */
    public function getTestStatus(): array|false
    {
        if ($this->isV4 === null) {
            $this->isV4Controller();
        }

        if ($this->isV4) {
            return $this->getTestStatusV4();
        }

        $strings = $this->getStringsV2V3();
        if ($strings === false) {
            return false;
        }

        return [
            'enabled' => $strings['test_enabled'] === 1,
            'mode' => $strings['test_mode'],
        ];
    }

    /**
     * Get test mode status for V4/V5 controllers
     */
    private function getTestStatusV4(): array
    {
        $v4Status = $this->queryV4Api('ST');

        if ($v4Status === false) {
            return [
                'enabled' => false,
                'mode' => 0,
                'is_v4' => true,
                'note' => 'Unable to query test status',
            ];
        }

        $tsValue = $v4Status['TS'] ?? 0;

        return [
            'enabled' => $tsValue > 0,
            'mode' => $tsValue,
            'is_v4' => true,
        ];
    }

    // ==================== NETWORK CONFIGURATION ====================

    /**
     * Get network configuration (parses config.htm hidden fields)
     */
    public function getNetworkConfig(): array|false
    {
        $response = $this->httpGet('/config.htm');
        if ($response === false) {
            return false;
        }

        $config = [];

        $patterns = [
            'controller_mode' => '/id="m"\s+value\s*=\s*"(\d+)"/',
            'product_code' => '/id="p"\s+value\s*=\s*"(\d+)"/',
            'dhcp_enabled' => '/id="d"\s+value\s*=\s*"([^"]*)"/',
            'wireless_ip' => '/id="i"\s+value\s*=\s*"([^"]*)"/',
            'wireless_gateway' => '/id="g"\s+value\s*=\s*"([^"]*)"/',
            'wireless_subnet' => '/id="s"\s+value\s*=\s*"([^"]*)"/',
            'wireless_dns1' => '/id="d1"\s+value\s*=\s*"([^"]*)"/',
            'wireless_dns2' => '/id="d2"\s+value\s*=\s*"([^"]*)"/',
            'wireless_ssid' => '/id="ss"\s+value\s*=\s*"([^"]*)"/',
        ];

        foreach ($patterns as $key => $pattern) {
            if (preg_match($pattern, $response, $matches)) {
                $config[$key] = $matches[1];
            }
        }

        if (preg_match('/name="mac"\s+value="([^"]+)"/', $response, $matches)) {
            $config['mac_address'] = $matches[1];
        }

        return $config;
    }

    // ==================== UTILITY METHODS ====================

    /**
     * Send a command to the controller
     */
    public function sendCommand(string $command, array $params = []): bool
    {
        $data = array_merge(['c' => $command], $params);
        $response = $this->httpPost('/command.htm', $data);
        return $response !== false;
    }

    /**
     * Reboot the controller
     * Auto-detects V4/V5 and uses appropriate API
     */
    public function reboot(): bool
    {
        if ($this->isV4 === null) {
            $this->isV4Controller();
        }

        if ($this->isV4) {
            return $this->rebootV4();
        }

        return $this->rebootV2V3();
    }

    /**
     * Reboot V2/V3 controller using config.htm "Save and Reboot"
     */
    private function rebootV2V3(): bool
    {
        $configHtml = $this->httpGet('/config.htm');
        if ($configHtml === false) {
            $this->lastError = "Failed to get config page";
            return false;
        }

        $data = [];
        $patterns = [
            'mac' => '/name="mac"\s+value="([^"]*)"/',
            'host' => '/name="host"\s+value="([^"]*)"/',
            'ip' => '/name="ip"\s+value="([^"]*)"/',
            'gw' => '/name="gw"\s+value="([^"]*)"/',
            'sub' => '/name="sub"\s+value="([^"]*)"/',
            'dns1' => '/name="dns1"\s+value="([^"]*)"/',
            'dns2' => '/name="dns2"\s+value="([^"]*)"/',
        ];

        foreach ($patterns as $name => $pattern) {
            if (preg_match($pattern, $configHtml, $m)) {
                $data[$name] = trim($m[1]);
            }
        }

        if (preg_match('/name="dhcp"[^>]*checked/', $configHtml)) {
            $data['dhcp'] = '1';
        }

        if (empty($data['ip']) || empty($data['mac'])) {
            $this->lastError = "Failed to parse network config";
            return false;
        }

        $url = $this->getBaseUrl() . '/config.htm';
        $postData = http_build_query($data);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($httpCode >= 200 && $httpCode < 400);
    }

    /**
     * Reboot V4/V5 controller using JSON API
     */
    private function rebootV4(): bool
    {
        $result = $this->setV4Api('RB', ['R' => 1]);

        if ($result !== false) {
            return true;
        }

        $this->lastError = "V4/V5 reboot not fully implemented - use controller web UI";
        return false;
    }

    /**
     * Get comprehensive controller info
     */
    public function getInfo(): array|false
    {
        $status = $this->getStatus(true);
        if ($status === false) {
            return false;
        }

        return [
            'host' => $this->host,
            'port' => $this->port,
            'status' => $status,
            'reachable' => true,
        ];
    }

    /**
     * Get temperature readings
     */
    public function getTemperatures(): array|false
    {
        $status = $this->getStatus();
        if ($status === false) {
            return false;
        }

        return [
            'cpu' => $status['temperature1'],
            'temp1' => $status['temperature2'],
            'temp2' => $status['temperature3'],
        ];
    }

    /**
     * Get voltage readings
     */
    public function getVoltages(): array|false
    {
        $status = $this->getStatus();
        if ($status === false) {
            return false;
        }

        return [
            'v1' => $status['voltage1'],
            'v2' => $status['voltage2'],
        ];
    }

    /**
     * Get pixel counts by bank
     */
    public function getPixelCounts(): array|false
    {
        $status = $this->getStatus();
        if ($status === false) {
            return false;
        }

        return [
            'bank0' => $status['pixels_bank0'],
            'bank1' => $status['pixels_bank1'],
            'bank2' => $status['pixels_bank2'],
            'total' => $status['pixels_bank0'] + $status['pixels_bank1'] + $status['pixels_bank2'],
        ];
    }

    // ==================== STATIC UTILITY METHODS ====================

    /**
     * Validate if a string is a valid host (IP address or hostname)
     */
    public static function isValidHost(string $host): bool
    {
        // Reject empty strings and strings with only whitespace
        if (trim($host) === '') {
            return false;
        }

        // Reject if contains whitespace
        if (preg_match('/\s/', $host)) {
            return false;
        }

        // Check for valid IP (IPv4 or IPv6)
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return true;
        }

        // Reject if it looks like an invalid IP format
        // (e.g., '192.168.1.', '1.1.1', '256.256.256.256', '01.01.01.01')
        if (preg_match('/^[\d.]+$/', $host)) {
            // If it's all digits and dots but failed FILTER_VALIDATE_IP, it's invalid
            return false;
        }

        // Check for valid hostname
        // Must start/end with alphanumeric, can contain hyphens in the middle
        // Can have multiple labels separated by dots
        return (bool)preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*$/', $host);
    }

    /**
     * Validate if a string is a valid subnet format (e.g., "192.168.1")
     */
    public static function isValidSubnet(string $subnet): bool
    {
        // Must match basic format: three octets separated by dots
        if (!preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}$/', $subnet)) {
            return false;
        }

        // Validate each octet is 0-255
        $octets = explode('.', $subnet);
        foreach ($octets as $octet) {
            $value = (int)$octet;
            if ($value < 0 || $value > 255) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get status for multiple controllers from a comma-separated hosts string
     *
     * @param string $hostsString Comma-separated list of hosts
     * @param int $timeout Connection timeout per host (default 3)
     * @return array Array with 'controllers', 'count', and 'online' keys
     */
    public static function getMultiStatus(string $hostsString, int $timeout = 3): array
    {
        if (empty($hostsString)) {
            return [
                'controllers' => [],
                'count' => 0,
                'online' => 0
            ];
        }

        $hosts = array_filter(array_map('trim', explode(',', $hostsString)));
        $controllers = [];

        foreach ($hosts as $host) {
            $controllerData = [
                'host' => $host,
                'online' => false,
                'status' => null,
                'error' => null
            ];

            try {
                $controller = new self($host, 80, $timeout);

                if ($controller->isReachable()) {
                    $status = $controller->getStatus();
                    if ($status !== false) {
                        $controllerData['online'] = true;
                        $controllerData['status'] = $status;

                        $testStatus = $controller->getTestStatus();
                        if ($testStatus !== false) {
                            $controllerData['testMode'] = $testStatus;
                        }
                    } else {
                        $controllerData['error'] = $controller->getLastError() ?: 'Failed to get status';
                    }
                } else {
                    $controllerData['error'] = $controller->getLastError() ?: 'Controller not reachable';
                }
            } catch (\Exception $e) {
                $controllerData['error'] = $e->getMessage();
            }

            $controllers[] = $controllerData;
        }

        return [
            'controllers' => $controllers,
            'count' => count($controllers),
            'online' => count(array_filter($controllers, fn($c) => $c['online']))
        ];
    }

    /**
     * Auto-detect subnet from FPP's network settings
     */
    public static function autoDetectSubnet(): ?string
    {
        $interfaces = @file_get_contents('http://127.0.0.1/api/network/interface');
        if ($interfaces) {
            $ifData = json_decode($interfaces, true);
            if ($ifData && is_array($ifData)) {
                foreach ($ifData as $iface) {
                    if (!empty($iface['IP']) && $iface['IP'] !== '127.0.0.1') {
                        $parts = explode('.', $iface['IP']);
                        if (count($parts) === 4) {
                            return $parts[0] . '.' . $parts[1] . '.' . $parts[2];
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Static method to discover Falcon controllers on a subnet
     * Uses parallel HTTP requests for fast scanning
     */
    public static function discover(string $subnet, int $startIp = 1, int $endIp = 254, float $timeout = 0.5, int $batchSize = 15): array
    {
        $discovered = [];
        $allIps = range($startIp, $endIp);
        $chunks = array_chunk($allIps, $batchSize);

        foreach ($chunks as $chunk) {
            $respondingIps = self::parallelProbe($subnet, $chunk, $timeout);

            foreach ($respondingIps as $ip => $xmlResponse) {
                $status = self::parseStatusXml($xmlResponse);
                if ($status !== false &&
                    !empty($status['model']) &&
                    !empty($status['firmware_version']) &&
                    strpos($status['model'], 'Unknown') === false) {
                    $discovered[] = [
                        'ip' => $ip,
                        'name' => $status['name'],
                        'model' => $status['model'],
                        'firmware' => $status['firmware_version'],
                    ];
                }
            }
        }

        return $discovered;
    }

    /**
     * Probe multiple IPs in parallel using curl_multi
     */
    private static function parallelProbe(string $subnet, array $ipSuffixes, float $timeout): array
    {
        $multiHandle = curl_multi_init();
        $handles = [];

        foreach ($ipSuffixes as $suffix) {
            $ip = "{$subnet}.{$suffix}";
            $ch = curl_init("http://{$ip}/status.xml");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS => (int)($timeout * 1000),
                CURLOPT_CONNECTTIMEOUT_MS => (int)($timeout * 1000),
                CURLOPT_HTTPHEADER => ['Accept: application/xml, text/xml, */*'],
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            curl_multi_add_handle($multiHandle, $ch);
            $handles[$ip] = $ch;
        }

        do {
            $status = curl_multi_exec($multiHandle, $active);
            if ($active) {
                curl_multi_select($multiHandle, 0.1);
            }
        } while ($active && $status === CURLM_OK);

        $results = [];
        foreach ($handles as $ip => $ch) {
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($httpCode === 200) {
                $response = curl_multi_getcontent($ch);
                if ($response) {
                    $results[$ip] = $response;
                }
            }
            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
        }

        curl_multi_close($multiHandle);
        return $results;
    }

    /**
     * Parse status.xml response into status array
     */
    private static function parseStatusXml(string $xml): array|false
    {
        libxml_use_internal_errors(true);
        $parsed = simplexml_load_string($xml);

        if ($parsed === false) {
            libxml_clear_errors();
            return false;
        }

        $productCode = (int)$parsed->p;

        return [
            'firmware_version' => (string)$parsed->fv,
            'name' => trim((string)$parsed->n),
            'product_code' => $productCode,
            'model' => self::getModelName($productCode),
        ];
    }

    // ==================== EFUSE CONTROL (F16V5 ONLY) ====================

    /**
     * Check if this controller has eFuse support
     * Only F16V5 controllers have eFuse hardware
     */
    public function hasEfuseSupport(?int $productCode = null): bool
    {
        if ($productCode === null) {
            $status = $this->getStatus();
            $productCode = $status['product_code'] ?? 0;
        }

        return $productCode === self::F16V5_PRODUCT_CODE;
    }

    /**
     * Set all fuses on or off (F16V5 only)
     * Uses FT (Fuse Toggle) API command
     *
     * @param bool $enabled True to enable fuses, false to disable
     * @return bool Success
     */
    public function setAllFuses(bool $enabled): bool
    {
        if (!$this->hasEfuseSupport()) {
            $this->lastError = 'Fuse control only supported on F16V5 controllers';
            return false;
        }

        $result = $this->setV4Api('FT', ['T' => $enabled ? 1 : 0]);
        return $result !== false;
    }

    /**
     * Reset all fuses (re-enable after trip)
     * Uses FR (Fuse Reset) API command
     *
     * @return bool Success
     */
    public function resetAllFuses(): bool
    {
        if (!$this->hasEfuseSupport()) {
            $this->lastError = 'Fuse control only supported on F16V5 controllers';
            return false;
        }

        // Use stdClass for empty object {} instead of empty array []
        $request = [
            'T' => 'S',
            'M' => 'FR',
            'B' => 0,
            'E' => 0,
            'I' => 0,
            'P' => new \stdClass()
        ];

        $response = $this->httpPostJson('/api', $request);
        return $response !== false;
    }

    /**
     * Reset a specific port's fuse (F16V5 only)
     * Uses TF (Toggle Fuse) API command
     *
     * @param int $port Port number (0-indexed)
     * @param int $receiver Receiver ID (0 = main board, 1-6 = Smart Receivers A-F)
     * @return bool Success
     */
    public function resetFuse(int $port, int $receiver = 0): bool
    {
        if (!$this->hasEfuseSupport()) {
            $this->lastError = 'Fuse control only supported on F16V5 controllers';
            return false;
        }

        $result = $this->setV4Api('TF', ['P' => $port, 'R' => $receiver]);
        return $result !== false;
    }

    /**
     * Get eFuse summary for this controller
     * Returns port-level current and fuse status data
     */
    public function getEfuseSummary(): array
    {
        if (!$this->hasEfuseSupport()) {
            return ['supported' => false];
        }

        $strings = $this->getStrings();
        if ($strings === false) {
            return ['supported' => false, 'error' => $this->lastError];
        }

        $ports = [];
        $fuseablePorts = 0;
        $activePorts = 0;
        $trippedPorts = 0;
        $totalCurrentMa = 0;

        foreach ($strings['strings'] ?? [] as $string) {
            $fuseStatus = $string['fuse_status'] ?? -1;
            $currentMa = $string['current'] ?? 0;
            $hasFuse = $fuseStatus >= 0;

            $ports[] = [
                'port' => $string['port'] ?? 0,
                'name' => $string['description'] ?? '',
                'current_ma' => $currentMa,
                'fuse_status' => $this->mapFuseStatus($fuseStatus),
                'has_fuse' => $hasFuse,
                'pixel_count' => $string['pixel_count'] ?? 0,
                'brightness' => $string['brightness'] ?? 100,
            ];

            if ($hasFuse) {
                $fuseablePorts++;
                $totalCurrentMa += $currentMa;
                if ($currentMa > 0) {
                    $activePorts++;
                }
                if ($fuseStatus > 0) {
                    $trippedPorts++;
                }
            }
        }

        return [
            'supported' => true,
            'total_ports' => count($ports),
            'fuseable_ports' => $fuseablePorts,
            'active_ports' => $activePorts,
            'tripped_ports' => $trippedPorts,
            'total_current_ma' => $totalCurrentMa,
            'total_current_a' => round($totalCurrentMa / 1000, 2),
            'ports' => $ports,
        ];
    }

    /**
     * Map fuse status code to human-readable status
     */
    private function mapFuseStatus(int $status): string
    {
        return match($status) {
            -1 => 'none',
            0 => 'ok',
            default => 'tripped',
        };
    }
}
