<?php
declare(strict_types=1);

namespace Watcher\Controllers;

use Watcher\Core\Logger;
use Watcher\Core\FileManager;

/**
 * Voltage Hardware Detection and Reading
 *
 * Detects Raspberry Pi hardware and reads all available voltage rails via vcgencmd.
 * Supports both legacy measure_volts (Pi 4 and earlier) and pmic_read_adc (Pi 5).
 * Only supported on Raspberry Pi devices.
 */
class VoltageHardware
{
    public const HARDWARE_CACHE_TTL = 3600; // 1 hour

    // Legacy voltage rails (Pi 4 and earlier)
    public const LEGACY_VOLTAGE_RAILS = ['core', 'sdram_c', 'sdram_i', 'sdram_p'];

    // All PMIC voltage rails (Pi 5)
    public const PMIC_VOLTAGE_RAILS = [
        'EXT5V_V' => '5V Input',          // Power supply input (critical for undervoltage)
        'VDD_CORE_V' => 'Core',           // GPU/CPU core voltage
        'HDMI_V' => 'HDMI',               // HDMI power rail
        '3V7_WL_SW_V' => '3.7V Wireless', // Wireless/Bluetooth power
        '3V3_SYS_V' => '3.3V System',     // Main 3.3V rail
        '3V3_DAC_V' => '3.3V DAC',        // DAC voltage
        '3V3_ADC_V' => '3.3V ADC',        // ADC voltage
        '1V8_SYS_V' => '1.8V System',     // 1.8V system rail
        '1V1_SYS_V' => '1.1V System',     // 1.1V system rail
        'DDR_VDD2_V' => 'DDR VDD2',       // DDR memory voltage
        'DDR_VDDQ_V' => 'DDR VDDQ',       // DDR Q voltage
        '0V8_SW_V' => '0.8V Switched',    // 0.8V switched rail
        '0V8_AON_V' => '0.8V Always-On',  // 0.8V always-on rail
    ];

    private static ?self $instance = null;
    private Logger $logger;
    private FileManager $fileManager;
    private string $voltageDir;
    private string $cacheFile;

    private function __construct()
    {
        $this->logger = Logger::getInstance();
        $this->fileManager = FileManager::getInstance();
        $this->voltageDir = defined('WATCHERVOLTAGEDIR') ? WATCHERVOLTAGEDIR : '/home/fpp/media/plugindata/fpp-plugin-watcher/voltage';
        $this->cacheFile = $this->voltageDir . '/hardware-cache.json';
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Detect if voltage monitoring is available on this system
     * Uses file-based caching for performance
     */
    public function detectHardware(bool $forceRefresh = false): array
    {
        // Try to read from file cache first (fast path) - uses file locking via FileManager
        if (!$forceRefresh) {
            $cacheData = $this->fileManager->readJsonFile($this->cacheFile);
            if ($cacheData && isset($cacheData['timestamp']) && isset($cacheData['result'])) {
                if ((time() - $cacheData['timestamp']) < self::HARDWARE_CACHE_TTL) {
                    return $cacheData['result'];
                }
            }
        }

        $result = [
            'supported' => false,
            'type' => 'none',
            'method' => null,
            'hasPmic' => false,
            'availableRails' => []
        ];

        // Check if this is a Raspberry Pi
        if (!$this->isRaspberryPi()) {
            $this->saveHardwareCache($result);
            return $result;
        }

        // Check if vcgencmd is available
        if (!$this->isVcgencmdAvailable()) {
            $this->saveHardwareCache($result);
            return $result;
        }

        // Test if we can actually read voltage
        $testReading = $this->executeVcgencmd('measure_volts core');
        if ($testReading === null) {
            $this->saveHardwareCache($result);
            return $result;
        }

        // Check if PMIC is available (Pi 5)
        $hasPmic = $this->hasPmicSupport();

        // Determine available rails
        $availableRails = $hasPmic ? array_keys(self::PMIC_VOLTAGE_RAILS) : self::LEGACY_VOLTAGE_RAILS;

        $result = [
            'supported' => true,
            'type' => 'rpi',
            'method' => $hasPmic ? 'pmic' : 'vcgencmd',
            'hasPmic' => $hasPmic,
            'availableRails' => $availableRails
        ];

        $this->saveHardwareCache($result);
        return $result;
    }

    /**
     * Check if PMIC readings are available (Pi 5)
     */
    private function hasPmicSupport(): bool
    {
        $output = $this->executeVcgencmdMultiline('pmic_read_adc');
        return $output !== null && strpos($output, 'VDD_CORE_V') !== false;
    }

    /**
     * Check if running on a Raspberry Pi
     */
    private function isRaspberryPi(): bool
    {
        if (!file_exists('/proc/device-tree/model')) {
            return false;
        }

        $model = @file_get_contents('/proc/device-tree/model');
        return $model !== false && stripos($model, 'Raspberry') !== false;
    }

    /**
     * Check if vcgencmd is available
     */
    private function isVcgencmdAvailable(): bool
    {
        exec('which vcgencmd 2>/dev/null', $output, $returnCode);
        return $returnCode === 0 && !empty($output);
    }

    /**
     * Execute vcgencmd command and return output (first line only)
     */
    private function executeVcgencmd(string $command): ?string
    {
        $fullCommand = "vcgencmd {$command} 2>/dev/null";
        exec($fullCommand, $output, $returnCode);

        if ($returnCode !== 0 || empty($output)) {
            return null;
        }

        return trim($output[0]);
    }

    /**
     * Execute vcgencmd command and return full multi-line output
     */
    private function executeVcgencmdMultiline(string $command): ?string
    {
        $fullCommand = "vcgencmd {$command} 2>/dev/null";
        exec($fullCommand, $output, $returnCode);

        if ($returnCode !== 0 || empty($output)) {
            return null;
        }

        return implode("\n", $output);
    }

    /**
     * Save hardware detection result to file cache (uses file locking via FileManager)
     */
    private function saveHardwareCache(array $result): void
    {
        $this->fileManager->ensureDirectory($this->voltageDir);

        $cacheData = [
            'timestamp' => time(),
            'result' => $result
        ];

        $this->fileManager->writeJsonFile($this->cacheFile, $cacheData, 0);
    }

    /**
     * Read current core voltage (legacy single-value method for compatibility)
     *
     * @return array ['success' => bool, 'voltage' => float, 'error' => string|null]
     */
    public function readVoltage(): array
    {
        $hardware = $this->detectHardware();

        if (!$hardware['supported']) {
            return [
                'success' => false,
                'voltage' => null,
                'error' => 'Voltage monitoring not supported on this platform'
            ];
        }

        // For Pi 5, read from PMIC; for older Pis, use measure_volts
        if ($hardware['hasPmic']) {
            $allVoltages = $this->readAllVoltages();
            if (!$allVoltages['success']) {
                return [
                    'success' => false,
                    'voltage' => null,
                    'error' => $allVoltages['error']
                ];
            }
            // Return core voltage for backwards compatibility
            return [
                'success' => true,
                'voltage' => $allVoltages['voltages']['VDD_CORE_V'] ?? 0,
                'error' => null
            ];
        }

        $output = $this->executeVcgencmd('measure_volts core');

        if ($output === null) {
            return [
                'success' => false,
                'voltage' => null,
                'error' => 'Failed to execute vcgencmd'
            ];
        }

        // Parse output: "volt=1.2375V"
        if (!preg_match('/volt=([0-9.]+)V/', $output, $matches)) {
            return [
                'success' => false,
                'voltage' => null,
                'error' => 'Failed to parse voltage output: ' . $output
            ];
        }

        return [
            'success' => true,
            'voltage' => (float)$matches[1],
            'error' => null
        ];
    }

    /**
     * Read all available voltage rails
     *
     * @return array ['success' => bool, 'voltages' => array, 'labels' => array, 'error' => string|null]
     */
    public function readAllVoltages(): array
    {
        $hardware = $this->detectHardware();

        if (!$hardware['supported']) {
            return [
                'success' => false,
                'voltages' => [],
                'labels' => [],
                'error' => 'Voltage monitoring not supported on this platform'
            ];
        }

        if ($hardware['hasPmic']) {
            return $this->readPmicVoltages();
        } else {
            return $this->readLegacyVoltages();
        }
    }

    /**
     * Read voltages from PMIC (Pi 5)
     */
    private function readPmicVoltages(): array
    {
        $output = $this->executeVcgencmdMultiline('pmic_read_adc');

        if ($output === null) {
            return [
                'success' => false,
                'voltages' => [],
                'labels' => [],
                'error' => 'Failed to execute pmic_read_adc'
            ];
        }

        $voltages = [];
        $labels = [];

        // Parse PMIC output - format: "  VDD_CORE_V volt(15)=0.87677570V"
        foreach (self::PMIC_VOLTAGE_RAILS as $railKey => $railLabel) {
            // Match the rail voltage value
            $pattern = '/' . preg_quote($railKey, '/') . '\s+volt\(\d+\)=([0-9.]+)V/';
            if (preg_match($pattern, $output, $matches)) {
                $voltages[$railKey] = round((float)$matches[1], 4);
                $labels[$railKey] = $railLabel;
            }
        }

        if (empty($voltages)) {
            return [
                'success' => false,
                'voltages' => [],
                'labels' => [],
                'error' => 'Failed to parse PMIC voltage output'
            ];
        }

        return [
            'success' => true,
            'voltages' => $voltages,
            'labels' => $labels,
            'error' => null
        ];
    }

    /**
     * Read voltages using legacy measure_volts (Pi 4 and earlier)
     */
    private function readLegacyVoltages(): array
    {
        $voltages = [];
        $labels = [
            'core' => 'Core',
            'sdram_c' => 'SDRAM Core',
            'sdram_i' => 'SDRAM I/O',
            'sdram_p' => 'SDRAM PHY'
        ];
        $returnLabels = [];

        foreach (self::LEGACY_VOLTAGE_RAILS as $rail) {
            $output = $this->executeVcgencmd("measure_volts $rail");

            if ($output !== null && preg_match('/volt=([0-9.]+)V/', $output, $matches)) {
                $voltages[$rail] = round((float)$matches[1], 4);
                $returnLabels[$rail] = $labels[$rail];
            }
        }

        if (empty($voltages)) {
            return [
                'success' => false,
                'voltages' => [],
                'labels' => [],
                'error' => 'Failed to read any voltage rails'
            ];
        }

        return [
            'success' => true,
            'voltages' => $voltages,
            'labels' => $returnLabels,
            'error' => null
        ];
    }

    /**
     * Get human-readable labels for voltage rails
     */
    public function getRailLabels(): array
    {
        $hardware = $this->detectHardware();

        if ($hardware['hasPmic']) {
            return self::PMIC_VOLTAGE_RAILS;
        }

        return [
            'core' => 'Core',
            'sdram_c' => 'SDRAM Core',
            'sdram_i' => 'SDRAM I/O',
            'sdram_p' => 'SDRAM PHY'
        ];
    }

    /**
     * Get throttle status from vcgencmd get_throttled
     *
     * Throttle bits (from https://www.raspberrypi.com/documentation/computers/os.html):
     * Bit  Meaning
     * 0    Under-voltage detected
     * 1    Arm frequency capped
     * 2    Currently throttled
     * 3    Soft temperature limit active
     * 16   Under-voltage has occurred
     * 17   Arm frequency capping has occurred
     * 18   Throttling has occurred
     * 19   Soft temperature limit has occurred
     *
     * @return array ['success' => bool, 'throttled' => bool, 'flags' => string, 'undervoltage' => bool, 'details' => array]
     */
    public function getThrottleStatus(): array
    {
        $hardware = $this->detectHardware();

        if (!$hardware['supported']) {
            return [
                'success' => false,
                'throttled' => false,
                'flags' => '0x0',
                'undervoltage' => false,
                'details' => []
            ];
        }

        $output = $this->executeVcgencmd('get_throttled');

        if ($output === null) {
            return [
                'success' => false,
                'throttled' => false,
                'flags' => '0x0',
                'undervoltage' => false,
                'details' => []
            ];
        }

        // Parse output: "throttled=0x0"
        if (!preg_match('/throttled=(0x[0-9a-fA-F]+)/', $output, $matches)) {
            return [
                'success' => false,
                'throttled' => false,
                'flags' => '0x0',
                'undervoltage' => false,
                'details' => []
            ];
        }

        $flags = $matches[1];
        $value = hexdec($flags);

        // Decode throttle bits
        $details = [
            'undervoltage_now' => (bool)($value & 0x1),
            'freq_capped_now' => (bool)($value & 0x2),
            'throttled_now' => (bool)($value & 0x4),
            'temp_limit_now' => (bool)($value & 0x8),
            'undervoltage_occurred' => (bool)($value & 0x10000),
            'freq_capped_occurred' => (bool)($value & 0x20000),
            'throttled_occurred' => (bool)($value & 0x40000),
            'temp_limit_occurred' => (bool)($value & 0x80000),
        ];

        $isThrottled = ($value & 0xF) !== 0;  // Any current throttle condition
        $undervoltage = $details['undervoltage_now'] || $details['undervoltage_occurred'];

        return [
            'success' => true,
            'throttled' => $isThrottled,
            'flags' => $flags,
            'undervoltage' => $undervoltage,
            'details' => $details
        ];
    }

    /**
     * Get hardware detection summary for display
     */
    public function getHardwareSummary(): array
    {
        $hardware = $this->detectHardware();

        if (!$hardware['supported']) {
            return [
                'supported' => false,
                'message' => 'Voltage monitoring not available (requires Raspberry Pi)',
                'typeLabel' => 'Not Supported'
            ];
        }

        return [
            'supported' => true,
            'type' => $hardware['type'],
            'typeLabel' => 'Raspberry Pi',
            'method' => $hardware['method']
        ];
    }

    /**
     * Clear the hardware cache
     */
    public function clearHardwareCache(): void
    {
        if (file_exists($this->cacheFile)) {
            @unlink($this->cacheFile);
        }
    }
}
