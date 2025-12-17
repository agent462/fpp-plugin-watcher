<?php
/**
 * Falcon Controller API Library
 *
 * PHP library for interacting with Falcon pixel controllers (F4, F16, F48, etc.)
 * over HTTP. These controllers expose an XML-based API for status monitoring
 * and configuration.
 *
 * @package fpp-plugin-watcher
 * @author Watcher Plugin
 * @version 1.0.0
 */

include_once __DIR__ . '/../core/watcherCommon.php';

/**
 * Falcon Controller Product Codes
 */
// V2/V3 Controllers (XML API)
define('FALCON_F4V2_PRODUCT_CODE', 1);
define('FALCON_F16V2_PRODUCT_CODE', 2);
define('FALCON_F4V3_PRODUCT_CODE', 3);
define('FALCON_F16V3_PRODUCT_CODE', 5);
define('FALCON_F48_PRODUCT_CODE', 7);

// V4/V5 Controllers (JSON API)
define('FALCON_F16V4_PRODUCT_CODE', 128);
define('FALCON_F48V4_PRODUCT_CODE', 129);
define('FALCON_F16V5_PRODUCT_CODE', 130);
define('FALCON_F48V5_PRODUCT_CODE', 131);
define('FALCON_F32V5_PRODUCT_CODE', 132);

/**
 * Falcon Controller Modes (from strings.xml cm attribute)
 * These values differ from status.xml's m field
 */
define('FALCON_MODE_E131', 0);
define('FALCON_MODE_ZCPP', 16);
define('FALCON_MODE_DDP', 64);
define('FALCON_MODE_ARTNET', 128);

/**
 * V4/V5 Operating Modes (from JSON API O field)
 */
define('FALCON_V4_MODE_E131_ARTNET', 0);
define('FALCON_V4_MODE_ZCPP', 1);
define('FALCON_V4_MODE_DDP', 2);
define('FALCON_V4_MODE_FPP_REMOTE', 3);
define('FALCON_V4_MODE_FPP_MASTER', 4);
define('FALCON_V4_MODE_FPP_PLAYER', 5);

/**
 * Class FalconController
 *
 * Provides methods to interact with Falcon pixel controllers via HTTP API
 */
class FalconController
{
    /** @var string Controller IP address or hostname */
    private $host;

    /** @var int HTTP port (default 80) */
    private $port;

    /** @var int Connection timeout in seconds */
    private $timeout;

    /** @var string|null Last error message */
    private $lastError;

    /** @var array Cached status data */
    private $statusCache;

    /** @var int Cache timestamp */
    private $statusCacheTime;

    /** @var int Cache TTL in seconds */
    private $cacheTTL;

    /** @var bool|null Whether this is a V4/V5 controller (null = unknown) */
    private $isV4 = null;

    /**
     * Constructor
     *
     * @param string $host Controller IP address or hostname
     * @param int $port HTTP port (default 80)
     * @param int $timeout Connection timeout in seconds (default 5)
     * @param int $cacheTTL Status cache TTL in seconds (default 5)
     */
    public function __construct($host, $port = 80, $timeout = 5, $cacheTTL = 5)
    {
        $this->host = $host;
        $this->port = $port;
        $this->timeout = $timeout;
        $this->cacheTTL = $cacheTTL;
        $this->lastError = null;
        $this->statusCache = null;
        $this->statusCacheTime = 0;
    }

    /**
     * Get the base URL for the controller
     *
     * @return string Base URL
     */
    private function getBaseUrl()
    {
        return "http://{$this->host}:{$this->port}";
    }

    /**
     * Make an HTTP GET request
     *
     * @param string $endpoint API endpoint path
     * @return string|false Response body or false on error
     */
    private function httpGet($endpoint)
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
    private function httpPost($endpoint, $data)
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
    private function httpPostJson($endpoint, $data)
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
    private function queryV4Api($method, $batch = 0)
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
    private function setV4Api($method, $params)
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
    public function isV4Controller($productCode = null)
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
     * @return SimpleXMLElement|false Parsed XML or false on error
     */
    private function parseXml($xml)
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
     *
     * @return string|null Last error message or null if no error
     */
    public function getLastError()
    {
        return $this->lastError;
    }

    /**
     * Check if controller is reachable
     *
     * @return bool True if controller responds
     */
    public function isReachable()
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
    public function getStatus($forceRefresh = false)
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
     * @param SimpleXMLElement $xml Parsed status.xml
     * @return array|false Status data or false on error
     */
    private function getStatusV2V3($xml)
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
        // status.xml's <m> field doesn't reflect DDP/ArtNet modes correctly
        $stringsResponse = $this->httpGet('/strings.xml');
        if ($stringsResponse !== false) {
            $stringsXml = $this->parseXml($stringsResponse);
            if ($stringsXml !== false && isset($stringsXml['cm'])) {
                $status['controller_mode'] = (int)$stringsXml['cm'];
            }
        }

        // Add derived info
        $status['model'] = $this->getModelName($status['product_code']);
        $status['mode_name'] = $this->getModeName($status['controller_mode'], false);

        // Cache the result
        $this->statusCache = $status;
        $this->statusCacheTime = time();

        return $status;
    }

    /**
     * Get status for V4/V5 controllers using JSON API
     *
     * @param SimpleXMLElement $xml Parsed status.xml (for basic info)
     * @return array|false Status data or false on error
     */
    private function getStatusV4($xml)
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
        $status['model'] = $this->getModelName($status['product_code']);
        $status['mode_name'] = $this->getModeName($status['controller_mode'], true);

        // Cache the result
        $this->statusCache = $status;
        $this->statusCacheTime = time();

        return $status;
    }

    /**
     * Get basic V4 status from XML when JSON API fails
     *
     * @param SimpleXMLElement $xml Parsed status.xml
     * @return array Status data
     */
    private function getStatusV4FromXml($xml)
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

        $status['model'] = $this->getModelName($status['product_code']);
        $status['mode_name'] = 'Unknown';

        $this->statusCache = $status;
        $this->statusCacheTime = time();

        return $status;
    }

    /**
     * Format uptime seconds into human-readable string
     *
     * @param int $seconds Uptime in seconds
     * @return string Formatted uptime
     */
    private function formatUptime($seconds)
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
     *
     * @param int $productCode Product code
     * @return string Model name
     */
    public function getModelName($productCode)
    {
        $models = [
            // V2/V3 Controllers
            FALCON_F4V2_PRODUCT_CODE => 'F4V2',
            FALCON_F16V2_PRODUCT_CODE => 'F16V2',
            FALCON_F4V3_PRODUCT_CODE => 'F4V3',
            FALCON_F16V3_PRODUCT_CODE => 'F16V3',
            FALCON_F48_PRODUCT_CODE => 'F48',
            // V4/V5 Controllers
            FALCON_F16V4_PRODUCT_CODE => 'F16V4',
            FALCON_F48V4_PRODUCT_CODE => 'F48V4',
            FALCON_F16V5_PRODUCT_CODE => 'F16V5',
            FALCON_F48V5_PRODUCT_CODE => 'F48V5',
            FALCON_F32V5_PRODUCT_CODE => 'F32V5',
        ];
        return $models[$productCode] ?? "Unknown ($productCode)";
    }

    /**
     * Get mode name from mode code
     *
     * @param int $modeCode Mode code
     * @param bool $isV4 Whether this is a V4/V5 controller
     * @return string Mode name
     */
    public function getModeName($modeCode, $isV4 = false)
    {
        if ($isV4) {
            // V4/V5 operating modes from JSON API O field
            $modes = [
                FALCON_V4_MODE_E131_ARTNET => 'E1.31/ArtNet',
                FALCON_V4_MODE_ZCPP => 'ZCPP',
                FALCON_V4_MODE_DDP => 'DDP',
                FALCON_V4_MODE_FPP_REMOTE => 'FPP Remote',
                FALCON_V4_MODE_FPP_MASTER => 'FPP Master',
                FALCON_V4_MODE_FPP_PLAYER => 'FPP Player',
            ];
        } else {
            // V2/V3 controller modes from strings.xml cm attribute
            $modes = [
                FALCON_MODE_E131 => 'E1.31',
                FALCON_MODE_ZCPP => 'ZCPP',
                FALCON_MODE_DDP => 'DDP',
                FALCON_MODE_ARTNET => 'ArtNet',
            ];
        }

        return $modes[$modeCode] ?? "Unknown ($modeCode)";
    }

    /**
     * Get system time from controller
     *
     * @return array|false Time data or false on error
     */
    public function getSystemTime()
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
     *
     * @return array|false Settings data or false on error
     */
    public function getSettings()
    {
        $response = $this->httpGet('/settings.xml');
        if ($response === false) {
            return false;
        }

        $xml = $this->parseXml($response);
        if ($xml === false) {
            return false;
        }

        $settings = [
            'universes' => $this->parseUniverses($xml->universes),
            'strings' => $this->parseStrings($xml->strings),
            'serial_outputs' => $this->parseSerialOutputs($xml->serialoutputs),
        ];

        return $settings;
    }

    /**
     * Get universe configuration
     *
     * @return array|false Universe data or false on error
     */
    public function getUniverses()
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
     *
     * @param SimpleXMLElement $xml Universes element
     * @return array Parsed universes
     */
    private function parseUniverses($xml)
    {
        $universes = [];
        if ($xml && $xml->un) {
            foreach ($xml->un as $un) {
                $universes[] = [
                    'universe' => (int)$un['u'],
                    'start_channel' => (int)$un['s'],
                    'size' => (int)$un['l'],
                    'type' => (int)$un['t'],  // 0=E1.31, 1=ArtNet
                ];
            }
        }
        return $universes;
    }

    /**
     * Get string port configuration
     * Auto-detects V4/V5 and uses appropriate API
     *
     * @return array|false String data or false on error
     */
    public function getStrings()
    {
        // Detect controller version if not already known
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
     *
     * @return array|false String data or false on error
     */
    private function getStringsV2V3()
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
     *
     * @return array|false String data or false on error
     */
    private function getStringsV4()
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
            'test_enabled' => 0, // V4 test status retrieved separately
            'test_mode' => 0,
            'strings' => $strings,
            'is_v4' => true,
        ];
    }

    /**
     * Parse strings XML element
     *
     * @param SimpleXMLElement $xml Strings element
     * @return array Parsed strings
     */
    private function parseStrings($xml)
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
     *
     * @return array|false Serial output data or false on error
     */
    public function getSerialOutputs()
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
     *
     * @param SimpleXMLElement $xml Serial outputs element
     * @return array Parsed serial outputs
     */
    private function parseSerialOutputs($xml)
    {
        $outputs = [];
        if ($xml && $xml->so) {
            foreach ($xml->so as $so) {
                $outputs[] = [
                    'type' => (int)$so['t'],       // 0=DMX, 1=Pixelnet, 2=Renard
                    'baud' => (int)$so['b'],       // Baud rate code
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
     *
     * @return array|false Packet counts or false on error
     */
    public function getPacketStats()
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
     *
     * @return array|false DDP data or false on error
     */
    public function getDdpData()
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
     *
     * @param int $testMode Test mode (0-7: 0=RGBW, 1=Red Ramp, 2=Green Ramp, 3=Blue Ramp, 4=White Ramp, 5=Color Wash, 6=White, 7=Chase)
     * @param bool $allPorts Enable test on all ports (default true)
     * @return bool Success
     */
    public function enableTest($testMode = 5, $allPorts = true)
    {
        // Detect controller version if not already known
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
     *
     * @param int $testMode Test mode pattern
     * @param bool $allPorts Enable on all ports
     * @return bool Success
     */
    private function enableTestV2V3($testMode, $allPorts)
    {
        $data = [
            't' => 1,        // Enable test
            'm' => $testMode, // Test mode pattern
        ];

        if ($allPorts) {
            // Get string configuration to find all ports
            $strings = $this->getStringsV2V3();
            if ($strings !== false && !empty($strings['strings'])) {
                // Enable each string port (e{index}=1)
                foreach ($strings['strings'] as $index => $string) {
                    $data['e' . $index] = 1;
                }
            }

            // Get serial outputs configuration
            $serial = $this->getSerialOutputs();
            if ($serial !== false && !empty($serial['outputs'])) {
                // Enable each serial port (s{port}=1)
                foreach ($serial['outputs'] as $index => $output) {
                    $data['s' . $index] = 1;
                }
            }
        }

        $response = $this->httpPost('/test.htm', $data);
        return $response !== false;
    }

    /**
     * Enable test mode for V4/V5 controllers using JSON API
     *
     * API format discovered from test.html web interface:
     * - P.E: "Y" to enable, "N" to disable
     * - P.Y: Test mode type (1=RGBW, 2=Red, 3=Green, 4=Blue, 5=White, 6=Color Wash, etc.)
     * - P.S: Speed (default 20)
     * - P.C: Color value (packed RGBW or mode-specific)
     * - P.D: Display flag ("Y")
     * - P.A: Array of per-port settings with P (port), R (row), S (state)
     *
     * @param int $testMode Test mode pattern (1-7)
     * @param bool $allPorts Enable on all ports
     * @return bool Success
     */
    private function enableTestV4($testMode, $allPorts)
    {
        // Get number of ports from status
        $status = $this->getStatus();
        $numPorts = $status['num_ports'] ?? 32;

        // Build per-port array - enable all ports by default
        $portArray = [];
        for ($i = 0; $i < $numPorts; $i++) {
            $portArray[] = [
                'P' => $i,  // Port number
                'R' => 0,   // Row (for matrix displays)
                'S' => 0,   // State (0 = enabled for test)
            ];
        }

        // Map test mode to Y value
        // V4/V5 test modes: 1=RGBW, 2=Red Ramp, 3=Green Ramp, 4=Blue Ramp, 5=White Ramp, 6=Color Wash, 7=Chase
        $testModeY = max(1, min(7, $testMode));

        // Build request parameters
        $params = [
            'E' => 'Y',           // Enable test mode
            'Y' => $testModeY,    // Test mode type
            'S' => 20,            // Speed
            'C' => 1073741824,    // Color value (default from web UI)
            'D' => 'Y',           // Display flag
            'A' => $portArray,    // Per-port settings
        ];

        // Build the full API request
        $request = [
            'T' => 'S',           // Set operation
            'M' => 'TS',          // Test Settings method
            'B' => 0,             // Batch number
            'E' => $numPorts,     // Expected count (number of ports)
            'I' => 0,             // Index
            'P' => $params,       // Parameters
        ];

        $response = $this->httpPostJson('/api', $request);
        if ($response === false) {
            return false;
        }

        // Check if request was complete (F=1 means final batch)
        if (isset($response['F']) && $response['F'] !== 1) {
            $this->lastError = "Test mode request incomplete - try again";
            return false;
        }

        return true;
    }

    /**
     * Disable test mode
     * Auto-detects V4/V5 and uses appropriate API
     *
     * @return bool Success
     */
    public function disableTest()
    {
        // Detect controller version if not already known
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
     *
     * @return bool Success
     */
    private function disableTestV2V3()
    {
        $data = [
            't' => 0,        // Disable test
            'm' => 0,        // Reset mode
        ];

        // Get string configuration to disable all ports
        $strings = $this->getStringsV2V3();
        if ($strings !== false && !empty($strings['strings'])) {
            foreach ($strings['strings'] as $index => $string) {
                $data['e' . $index] = 0;
            }
        }

        // Get serial outputs configuration
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
     *
     * Uses same API format as enable but with E: "N" to disable
     *
     * @return bool Success
     */
    private function disableTestV4()
    {
        // Get number of ports from status
        $status = $this->getStatus();
        $numPorts = $status['num_ports'] ?? 32;

        // Build per-port array
        $portArray = [];
        for ($i = 0; $i < $numPorts; $i++) {
            $portArray[] = [
                'P' => $i,
                'R' => 0,
                'S' => 0,
            ];
        }

        // Build request parameters - same as enable but E: "N"
        $params = [
            'E' => 'N',           // Disable test mode
            'Y' => 1,             // Test mode type (doesn't matter when disabling)
            'S' => 20,            // Speed
            'C' => 0,             // Color value
            'D' => 'Y',           // Display flag
            'A' => $portArray,    // Per-port settings
        ];

        // Build the full API request
        $request = [
            'T' => 'S',           // Set operation
            'M' => 'TS',          // Test Settings method
            'B' => 0,             // Batch number
            'E' => $numPorts,     // Expected count
            'I' => 0,             // Index
            'P' => $params,       // Parameters
        ];

        $response = $this->httpPostJson('/api', $request);
        if ($response === false) {
            return false;
        }

        // Check if request was complete (F=1 means final batch)
        if (isset($response['F']) && $response['F'] !== 1) {
            $this->lastError = "Test mode disable request incomplete - try again";
            return false;
        }

        return true;
    }

    /**
     * Get test mode status
     * Auto-detects V4/V5 and uses appropriate API
     *
     * @return array|false Test status or false on error
     */
    public function getTestStatus()
    {
        // Detect controller version if not already known
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
     *
     * The TS field in the ST (status) query response indicates test mode:
     * - TS: 0 = test mode disabled
     * - TS: 1-7 = test mode enabled with that mode number
     *
     * @return array Test status
     */
    private function getTestStatusV4()
    {
        // Query status to get TS field
        $v4Status = $this->queryV4Api('ST');

        if ($v4Status === false) {
            // Return unknown status if query fails
            return [
                'enabled' => false,
                'mode' => 0,
                'is_v4' => true,
                'note' => 'Unable to query test status',
            ];
        }

        // TS field: 0 = disabled, 1-7 = test mode number
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
     * Note: This is a best-effort parse of the HTML form
     *
     * @return array|false Network config or false on error
     */
    public function getNetworkConfig()
    {
        $response = $this->httpGet('/config.htm');
        if ($response === false) {
            return false;
        }

        $config = [];

        // Parse hidden input fields
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

        // Parse MAC address from form
        if (preg_match('/name="mac"\s+value="([^"]+)"/', $response, $matches)) {
            $config['mac_address'] = $matches[1];
        }

        return $config;
    }

    // ==================== UTILITY METHODS ====================

    /**
     * Send a command to the controller
     *
     * @param string $command Command name
     * @param array $params Command parameters
     * @return bool Success
     */
    public function sendCommand($command, $params = [])
    {
        $data = array_merge(['c' => $command], $params);
        $response = $this->httpPost('/command.htm', $data);
        return $response !== false;
    }

    /**
     * Reboot the controller
     * Auto-detects V4/V5 and uses appropriate API
     *
     * @return bool Success
     */
    public function reboot()
    {
        // Detect controller version if not already known
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
     *
     * @return bool Success
     */
    private function rebootV2V3()
    {
        // Get current network config from config.htm
        $configHtml = $this->httpGet('/config.htm');
        if ($configHtml === false) {
            $this->lastError = "Failed to get config page";
            return false;
        }

        // Extract current network values from hidden fields and form inputs
        $data = [];

        // Extract values from the form - we need to preserve current settings
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

        // Check if DHCP is enabled (checkbox has 'checked' attribute)
        if (preg_match('/name="dhcp"[^>]*checked/', $configHtml)) {
            $data['dhcp'] = '1';
        }

        // Verify we got the essential fields
        if (empty($data['ip']) || empty($data['mac'])) {
            $this->lastError = "Failed to parse network config";
            return false;
        }

        // POST to config.htm triggers "Save and Reboot"
        // Use custom POST that accepts 302 redirect as success (controller redirects after save)
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
            CURLOPT_FOLLOWLOCATION => false,  // Don't follow redirect
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 200, 302 (redirect after save), or 303 are all success
        return ($httpCode >= 200 && $httpCode < 400);
    }

    /**
     * Reboot V4/V5 controller using JSON API
     *
     * @return bool Success
     */
    private function rebootV4()
    {
        // V4/V5 controllers can be rebooted via the RB (Reboot) API command
        // or by setting RW (Reboot When) flag in status
        $result = $this->setV4Api('RB', ['R' => 1]);

        if ($result !== false) {
            return true;
        }

        // If RB doesn't work, try setting RW flag via NE (Network/Ethernet) API
        $this->lastError = "V4/V5 reboot not fully implemented - use controller web UI";
        return false;
    }

    /**
     * Get comprehensive controller info
     *
     * @return array|false Controller info or false on error
     */
    public function getInfo()
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
     *
     * @return array|false Temperature data or false on error
     */
    public function getTemperatures()
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
     *
     * @return array|false Voltage data or false on error
     */
    public function getVoltages()
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
     *
     * @return array|false Pixel counts or false on error
     */
    public function getPixelCounts()
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
     *
     * @param string $host Host to validate
     * @return bool True if valid
     */
    public static function isValidHost($host)
    {
        return validateHost($host);
    }

    /**
     * Validate if a string is a valid subnet format (e.g., "192.168.1")
     *
     * @param string $subnet Subnet to validate
     * @return bool True if valid
     */
    public static function isValidSubnet($subnet)
    {
        return (bool)preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}$/', $subnet);
    }

    /**
     * Get status for multiple controllers from a comma-separated hosts string
     *
     * @param string $hostsString Comma-separated list of hosts
     * @param int $timeout Connection timeout per host (default 3)
     * @return array Array with 'controllers', 'count', and 'online' keys
     */
    public static function getMultiStatus($hostsString, $timeout = 3)
    {
        if (empty($hostsString)) {
            return [
                'controllers' => [],
                'count' => 0,
                'online' => 0
            ];
        }

        // Parse comma-separated hosts
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

                        // Get test mode status
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
            } catch (Exception $e) {
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
     *
     * @return string|null The subnet (e.g., "192.168.1") or null if unable to detect
     */
    public static function autoDetectSubnet()
    {
        // Try to auto-detect from FPP's network settings
        $interfaces = @file_get_contents('http://127.0.0.1/api/network/interface');
        if ($interfaces) {
            $ifData = json_decode($interfaces, true);
            if ($ifData && is_array($ifData)) {
                foreach ($ifData as $iface) {
                    if (!empty($iface['IP']) && $iface['IP'] !== '127.0.0.1') {
                        // Extract subnet from IP (e.g., 192.168.1.100 -> 192.168.1)
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
     *
     * @param string $subnet Subnet base (e.g., "192.168.1")
     * @param int $startIp Start IP (default 1)
     * @param int $endIp End IP (default 254)
     * @param float $timeout Timeout per host in seconds (default 0.5)
     * @param int $batchSize Number of concurrent requests (default 15)
     * @return array Array of discovered controllers
     */
    public static function discover($subnet, $startIp = 1, $endIp = 254, $timeout = 0.5, $batchSize = 15)
    {
        $discovered = [];
        $allIps = range($startIp, $endIp);
        $chunks = array_chunk($allIps, $batchSize);

        foreach ($chunks as $chunk) {
            $respondingIps = self::parallelProbe($subnet, $chunk, $timeout);

            // For each responding IP, get full status to validate it's a Falcon controller
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
     *
     * @param string $subnet Subnet base
     * @param array $ipSuffixes Array of IP suffixes to probe
     * @param float $timeout Timeout in seconds
     * @return array Associative array of IP => XML response for responding hosts
     */
    private static function parallelProbe($subnet, $ipSuffixes, $timeout)
    {
        $multiHandle = curl_multi_init();
        $handles = [];

        // Create all request handles
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

        // Execute all requests in parallel
        do {
            $status = curl_multi_exec($multiHandle, $active);
            if ($active) {
                curl_multi_select($multiHandle, 0.1);
            }
        } while ($active && $status === CURLM_OK);

        // Collect successful responses
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
     *
     * @param string $xml XML string from status.xml
     * @return array|false Parsed status or false on error
     */
    private static function parseStatusXml($xml)
    {
        libxml_use_internal_errors(true);
        $parsed = simplexml_load_string($xml);

        if ($parsed === false) {
            libxml_clear_errors();
            return false;
        }

        $productCode = (int)$parsed->p;
        $models = [
            // V2/V3 Controllers
            FALCON_F4V2_PRODUCT_CODE => 'F4V2',
            FALCON_F16V2_PRODUCT_CODE => 'F16V2',
            FALCON_F4V3_PRODUCT_CODE => 'F4V3',
            FALCON_F16V3_PRODUCT_CODE => 'F16V3',
            FALCON_F48_PRODUCT_CODE => 'F48',
            // V4/V5 Controllers
            FALCON_F16V4_PRODUCT_CODE => 'F16V4',
            FALCON_F48V4_PRODUCT_CODE => 'F48V4',
            FALCON_F16V5_PRODUCT_CODE => 'F16V5',
            FALCON_F48V5_PRODUCT_CODE => 'F48V5',
            FALCON_F32V5_PRODUCT_CODE => 'F32V5',
        ];

        return [
            'firmware_version' => (string)$parsed->fv,
            'name' => trim((string)$parsed->n),
            'product_code' => $productCode,
            'model' => $models[$productCode] ?? "Unknown ($productCode)",
        ];
    }
}
