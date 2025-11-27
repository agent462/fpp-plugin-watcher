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

/**
 * Falcon Controller Product Codes
 */
define('FALCON_F4V2_PRODUCT_CODE', 1);
define('FALCON_F16V2_PRODUCT_CODE', 2);
define('FALCON_F4V3_PRODUCT_CODE', 3);
define('FALCON_F16V3_PRODUCT_CODE', 5);
define('FALCON_F48_PRODUCT_CODE', 6);

/**
 * Falcon Controller Modes (from strings.xml cm attribute)
 * These values differ from status.xml's m field
 */
define('FALCON_MODE_E131', 0);
define('FALCON_MODE_ZCPP', 16);
define('FALCON_MODE_DDP', 64);
define('FALCON_MODE_ARTNET', 128);

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

        $response = $this->httpGet('/status.xml');
        if ($response === false) {
            return false;
        }

        $xml = $this->parseXml($response);
        if ($xml === false) {
            return false;
        }

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
        $status['mode_name'] = $this->getModeName($status['controller_mode']);

        // Cache the result
        $this->statusCache = $status;
        $this->statusCacheTime = time();

        return $status;
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
            FALCON_F4V2_PRODUCT_CODE => 'F4V2',
            FALCON_F16V2_PRODUCT_CODE => 'F16V2',
            FALCON_F4V3_PRODUCT_CODE => 'F4V3',
            FALCON_F16V3_PRODUCT_CODE => 'F16V3',
            FALCON_F48_PRODUCT_CODE => 'F48',
        ];
        return $models[$productCode] ?? "Unknown ($productCode)";
    }

    /**
     * Get mode name from mode code
     *
     * @param int $modeCode Mode code
     * @return string Mode name
     */
    public function getModeName($modeCode)
    {
        // Known controller modes from strings.xml cm attribute
        $modes = [
            FALCON_MODE_E131 => 'E1.31',
            FALCON_MODE_ZCPP => 'ZCPP',
            FALCON_MODE_DDP => 'DDP',
            FALCON_MODE_ARTNET => 'ArtNet',
        ];

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
     *
     * @return array|false String data or false on error
     */
    public function getStrings()
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
     *
     * @param int $testMode Test mode (1-10, see controller docs)
     * @return bool Success
     */
    public function enableTest($testMode = 5)
    {
        $data = [
            't' => 1,        // Enable test
            'tm' => $testMode,
        ];

        $response = $this->httpPost('/test.htm', $data);
        return $response !== false;
    }

    /**
     * Disable test mode
     *
     * @return bool Success
     */
    public function disableTest()
    {
        $data = [
            't' => 0,        // Disable test
        ];

        $response = $this->httpPost('/test.htm', $data);
        return $response !== false;
    }

    /**
     * Get test mode status
     *
     * @return array|false Test status or false on error
     */
    public function getTestStatus()
    {
        $strings = $this->getStrings();
        if ($strings === false) {
            return false;
        }

        return [
            'enabled' => $strings['test_enabled'] === 1,
            'mode' => $strings['test_mode'],
        ];
    }

    // ==================== PLAYER CONTROL (Standalone Mode) ====================

    /**
     * Get player status (for standalone/master/remote modes)
     *
     * @return array|false Player status or false on error
     */
    public function getPlayerStatus()
    {
        $response = $this->httpGet('/playerstatus.xml');
        if ($response === false) {
            return false;
        }

        $xml = $this->parseXml($response);
        if ($xml === false) {
            return false;
        }

        // Player status format varies - return raw parsed data
        return [
            'xml' => $xml,
            'raw' => $response,
        ];
    }

    /**
     * Get available playlists
     *
     * @return array|false Playlists or false on error
     */
    public function getPlaylists()
    {
        $response = $this->httpGet('/playlists.xml');
        if ($response === false) {
            return false;
        }

        $xml = $this->parseXml($response);
        if ($xml === false) {
            return false;
        }

        $playlists = [];
        if ($xml->pl) {
            foreach ($xml->pl as $pl) {
                $playlists[] = [
                    'index' => (int)$pl['i'],
                    'name' => (string)$pl['n'],
                ];
            }
        }

        return $playlists;
    }

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
     * Play a playlist entry
     *
     * @param int $entryIndex Entry index to play
     * @return bool Success
     */
    public function playEntry($entryIndex)
    {
        return $this->sendCommand('p', ['i' => $entryIndex]);
    }

    /**
     * Stop playback immediately
     *
     * @return bool Success
     */
    public function stopNow()
    {
        return $this->sendCommand('s');
    }

    /**
     * Stop playback gracefully (finish current item)
     *
     * @return bool Success
     */
    public function stopGracefully()
    {
        return $this->sendCommand('g');
    }

    /**
     * Set volume
     *
     * @param int $volume Volume level (0-100)
     * @return bool Success
     */
    public function setVolume($volume)
    {
        $volume = max(0, min(100, $volume));
        return $this->sendCommand('v', ['v' => $volume]);
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
     * Reboot the controller
     * Note: Not all firmware versions support this
     *
     * @return bool Success
     */
    public function reboot()
    {
        return $this->sendCommand('r');
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
            'board' => $status['temperature1'],
            'cpu' => $status['temperature2'],
            'aux' => $status['temperature3'],
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

    /**
     * Static method to discover Falcon controllers on a subnet
     *
     * @param string $subnet Subnet base (e.g., "192.168.1")
     * @param int $startIp Start IP (default 1)
     * @param int $endIp End IP (default 254)
     * @param int $timeout Timeout per host in seconds (default 1)
     * @return array Array of discovered controllers
     */
    public static function discover($subnet, $startIp = 1, $endIp = 254, $timeout = 1)
    {
        $discovered = [];

        for ($i = $startIp; $i <= $endIp; $i++) {
            $ip = "{$subnet}.{$i}";
            $controller = new self($ip, 80, $timeout);

            if ($controller->isReachable()) {
                $status = $controller->getStatus();
                if ($status !== false) {
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
}

/**
 * Helper function to create a FalconController instance
 *
 * @param string $host Controller IP address or hostname
 * @param int $port HTTP port (default 80)
 * @param int $timeout Connection timeout in seconds (default 5)
 * @return FalconController
 */
function createFalconController($host, $port = 80, $timeout = 5)
{
    return new FalconController($host, $port, $timeout);
}
