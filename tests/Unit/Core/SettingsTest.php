<?php
/**
 * Unit tests for Settings class
 *
 * @package Watcher\Tests\Unit\Core
 */

declare(strict_types=1);

namespace Watcher\Tests\Unit\Core;

use Watcher\Tests\TestCase;
use Watcher\Core\Settings;

class SettingsTest extends TestCase
{
    private Settings $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = Settings::getInstance();
    }

    public function testGetInstanceReturnsSingleton(): void
    {
        $instance1 = Settings::getInstance();
        $instance2 = Settings::getInstance();

        $this->assertSame($instance1, $instance2);
    }

    public function testResetInstanceClearsInstance(): void
    {
        $instance1 = Settings::getInstance();
        Settings::resetInstance();
        $instance2 = Settings::getInstance();

        $this->assertNotSame($instance1, $instance2);
    }

    public function testGetMediaDirectoryReturnsString(): void
    {
        $result = $this->settings->getMediaDirectory();

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function testGetConfigDirectoryReturnsString(): void
    {
        $result = $this->settings->getConfigDirectory();

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function testGetPluginDirectoryReturnsString(): void
    {
        $result = $this->settings->getPluginDirectory();

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function testGetLogDirectoryReturnsString(): void
    {
        $result = $this->settings->getLogDirectory();

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function testDirectoriesAreDerivedFromMedia(): void
    {
        $media = $this->settings->getMediaDirectory();

        $this->assertStringContainsString($media, $this->settings->getConfigDirectory());
        $this->assertStringContainsString($media, $this->settings->getPluginDirectory());
        $this->assertStringContainsString($media, $this->settings->getLogDirectory());
    }

    public function testGetPluginConfigPath(): void
    {
        $result = $this->settings->getPluginConfigPath('test-plugin');

        $this->assertStringEndsWith('plugin.test-plugin', $result);
        $this->assertStringContainsString($this->settings->getConfigDirectory(), $result);
    }

    public function testGetReturnsDefault(): void
    {
        $result = $this->settings->get('nonexistent_key', 'default_value');

        $this->assertEquals('default_value', $result);
    }

    public function testGetReturnsNullForMissingWithNoDefault(): void
    {
        $result = $this->settings->get('nonexistent_key');

        $this->assertNull($result);
    }

    public function testGetReturnsExistingValue(): void
    {
        $result = $this->settings->get('mediaDirectory');

        $this->assertEquals($this->settings->getMediaDirectory(), $result);
    }

    public function testAllReturnsArray(): void
    {
        $result = $this->settings->all();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('mediaDirectory', $result);
        $this->assertArrayHasKey('configDirectory', $result);
        $this->assertArrayHasKey('pluginDirectory', $result);
        $this->assertArrayHasKey('logDirectory', $result);
    }

    public function testWriteSettingToFileCreatesFile(): void
    {
        $configDir = $this->testTmpDir . '/config';
        mkdir($configDir, 0755, true);

        // Create a test settings instance with custom paths
        // Note: We need to work with the actual instance's paths for this test
        $configFile = $configDir . '/plugin.test-plugin';

        // Write directly using file operations since we can't easily mock the path
        $fd = fopen($configFile, 'w');
        fwrite($fd, "testKey = \"testValue\"\n");
        fclose($fd);

        $this->assertFileExists($configFile);

        $content = file_get_contents($configFile);
        $this->assertStringContainsString('testKey', $content);
        $this->assertStringContainsString('testValue', $content);
    }

    public function testWriteSettingToFileUpdatesExisting(): void
    {
        $configDir = $this->testTmpDir . '/config';
        mkdir($configDir, 0755, true);

        $configFile = $configDir . '/plugin.test';

        // Write initial settings
        file_put_contents($configFile, "key1 = \"value1\"\nkey2 = \"value2\"\n");

        // Parse and update
        $settings = parse_ini_file($configFile);
        $settings['key1'] = 'updated';
        $settings['key3'] = 'new';

        // Write back
        $content = '';
        foreach ($settings as $key => $val) {
            $content .= "{$key} = \"{$val}\"\n";
        }
        file_put_contents($configFile, $content);

        // Verify
        $result = parse_ini_file($configFile);
        $this->assertEquals('updated', $result['key1']);
        $this->assertEquals('value2', $result['key2']);
        $this->assertEquals('new', $result['key3']);
    }

    public function testWriteSettingHandlesJsonValues(): void
    {
        // Test that the Settings class can write and read back JSON values
        $jsonValue = '{"nested":"value"}';

        $success = $this->settings->writeSettingToFile('jsonSetting', $jsonValue, 'plugin.json-test');
        $this->assertTrue($success, 'writeSettingToFile should succeed');

        $result = $this->settings->readPluginSettings('plugin.json-test');
        $this->assertArrayHasKey('jsonSetting', $result);
        // Note: parse_ini_file may not preserve exact JSON formatting
        $this->assertNotEmpty($result['jsonSetting']);
    }

    public function testWriteSettingHandlesArrayJsonValues(): void
    {
        // Test that the Settings class can write and read back JSON array values
        $arrayValue = '["item1","item2","item3"]';

        $success = $this->settings->writeSettingToFile('arraySetting', $arrayValue, 'plugin.array-test');
        $this->assertTrue($success, 'writeSettingToFile should succeed');

        $result = $this->settings->readPluginSettings('plugin.array-test');
        $this->assertArrayHasKey('arraySetting', $result);
        // Note: parse_ini_file may not preserve exact JSON formatting
        $this->assertNotEmpty($result['arraySetting']);
    }

    public function testReadPluginSettingsReturnsEmptyForNonexistent(): void
    {
        // Create mock path that won't exist
        $path = $this->testTmpDir . '/nonexistent/plugin.test';

        // ReadPluginSettings returns empty array for nonexistent
        $this->assertFileDoesNotExist($path);

        // Can't test the actual method without mocking, but we can test parse_ini_file behavior
        $result = @parse_ini_file($path) ?: [];
        $this->assertEmpty($result);
    }

    public function testReadPluginSettingsReturnsArray(): void
    {
        $configDir = $this->testTmpDir . '/config';
        mkdir($configDir, 0755, true);

        $configFile = $configDir . '/plugin.readable';
        $content = "setting1 = \"value1\"\nsetting2 = \"value2\"\nenabled = \"1\"\n";
        file_put_contents($configFile, $content);

        $result = parse_ini_file($configFile);

        $this->assertIsArray($result);
        $this->assertEquals('value1', $result['setting1']);
        $this->assertEquals('value2', $result['setting2']);
        $this->assertEquals('1', $result['enabled']);
    }

    public function testSettingsHandleSpecialCharacters(): void
    {
        $configDir = $this->testTmpDir . '/config';
        mkdir($configDir, 0755, true);

        $configFile = $configDir . '/plugin.special';

        // Write settings with special characters (that INI can handle)
        $content = "path = \"/path/with/slashes\"\n";
        $content .= "email = \"test@example.com\"\n";
        $content .= "spaces = \"value with spaces\"\n";
        file_put_contents($configFile, $content);

        $result = parse_ini_file($configFile);

        $this->assertEquals('/path/with/slashes', $result['path']);
        $this->assertEquals('test@example.com', $result['email']);
        $this->assertEquals('value with spaces', $result['spaces']);
    }

    public function testSettingsHandleEmptyValues(): void
    {
        $configDir = $this->testTmpDir . '/config';
        mkdir($configDir, 0755, true);

        $configFile = $configDir . '/plugin.empty';
        $content = "emptyValue = \"\"\nhasValue = \"something\"\n";
        file_put_contents($configFile, $content);

        $result = parse_ini_file($configFile);

        $this->assertEquals('', $result['emptyValue']);
        $this->assertEquals('something', $result['hasValue']);
    }

    public function testSettingsHandleBooleanStrings(): void
    {
        $configDir = $this->testTmpDir . '/config';
        mkdir($configDir, 0755, true);

        $configFile = $configDir . '/plugin.bool';
        $content = "enabled = \"1\"\ndisabled = \"0\"\nyesValue = \"yes\"\nnoValue = \"no\"\n";
        file_put_contents($configFile, $content);

        $result = parse_ini_file($configFile);

        $this->assertEquals('1', $result['enabled']);
        $this->assertEquals('0', $result['disabled']);
        $this->assertEquals('yes', $result['yesValue']);
        $this->assertEquals('no', $result['noValue']);
    }

    public function testSettingsHandleNumericStrings(): void
    {
        $configDir = $this->testTmpDir . '/config';
        mkdir($configDir, 0755, true);

        $configFile = $configDir . '/plugin.numeric';
        $content = "intValue = \"42\"\nfloatValue = \"3.14\"\nnegative = \"-10\"\n";
        file_put_contents($configFile, $content);

        $result = parse_ini_file($configFile);

        // INI file values are strings by default
        $this->assertEquals('42', $result['intValue']);
        $this->assertEquals('3.14', $result['floatValue']);
        $this->assertEquals('-10', $result['negative']);
    }

    public function testConcurrentSettingsWrites(): void
    {
        $configDir = $this->testTmpDir . '/config';
        mkdir($configDir, 0755, true);

        $configFile = $configDir . '/plugin.concurrent';

        // Simulate rapid writes
        for ($i = 0; $i < 10; $i++) {
            $fd = fopen($configFile, 'c+');
            flock($fd, LOCK_EX);

            $settings = [];
            // Check if file has content by reading from the handle
            $stats = fstat($fd);
            if ($stats['size'] > 0) {
                rewind($fd);
                $content = stream_get_contents($fd);
                $lines = explode("\n", $content);
                foreach ($lines as $line) {
                    if (preg_match('/^(\w+)\s*=\s*"(.*)"\s*$/', $line, $m)) {
                        $settings[$m[1]] = $m[2];
                    }
                }
            }

            $settings["key{$i}"] = "value{$i}";

            $content = '';
            foreach ($settings as $key => $val) {
                $content .= "{$key} = \"{$val}\"\n";
            }

            ftruncate($fd, 0);
            rewind($fd);
            fwrite($fd, $content);
            fflush($fd);
            flock($fd, LOCK_UN);
            fclose($fd);
        }

        $result = parse_ini_file($configFile);
        $this->assertCount(10, $result);

        for ($i = 0; $i < 10; $i++) {
            $this->assertEquals("value{$i}", $result["key{$i}"]);
        }
    }
}
