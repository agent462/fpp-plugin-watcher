<?php
/**
 * Unit tests for RemoteControl class
 *
 * @package Watcher\Tests\Unit\Controllers
 */

declare(strict_types=1);

namespace Watcher\Tests\Unit\Controllers;

use Watcher\Tests\TestCase;
use Watcher\Controllers\RemoteControl;

class RemoteControlTest extends TestCase
{
    private RemoteControl $remoteControl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->remoteControl = RemoteControl::getInstance();
    }

    // =========================================================================
    // Singleton Tests
    // =========================================================================

    public function testGetInstanceReturnsSingleton(): void
    {
        $instance1 = RemoteControl::getInstance();
        $instance2 = RemoteControl::getInstance();

        $this->assertSame($instance1, $instance2);
    }

    public function testGetInstanceReturnsRemoteControlType(): void
    {
        $instance = RemoteControl::getInstance();

        $this->assertInstanceOf(RemoteControl::class, $instance);
    }

    // =========================================================================
    // Constants Tests
    // =========================================================================

    public function testTimeoutConstants(): void
    {
        $this->assertEquals(5, RemoteControl::TIMEOUT_STANDARD);
        $this->assertEquals(30, RemoteControl::TIMEOUT_LONG);
        $this->assertEquals(3, RemoteControl::TIMEOUT_STATUS);
    }

    // =========================================================================
    // extractStatusFields Tests
    // =========================================================================

    public function testExtractStatusFieldsWithCompleteData(): void
    {
        $fppStatus = [
            'platform' => 'Raspberry Pi',
            'branch' => 'master',
            'mode_name' => 'player',
            'status_name' => 'playing',
            'rebootFlag' => 0,
            'restartFlag' => 0
        ];

        $result = $this->remoteControl->extractStatusFields($fppStatus);

        $this->assertEquals('Raspberry Pi', $result['platform']);
        $this->assertEquals('master', $result['branch']);
        $this->assertEquals('player', $result['mode_name']);
        $this->assertEquals('playing', $result['status_name']);
        $this->assertEquals(0, $result['rebootFlag']);
        $this->assertEquals(0, $result['restartFlag']);
    }

    public function testExtractStatusFieldsWithEmptyData(): void
    {
        $result = $this->remoteControl->extractStatusFields([]);

        $this->assertEquals('--', $result['platform']);
        $this->assertEquals('--', $result['branch']);
        $this->assertEquals('--', $result['mode_name']);
        $this->assertEquals('idle', $result['status_name']);
        $this->assertEquals(0, $result['rebootFlag']);
        $this->assertEquals(0, $result['restartFlag']);
    }

    public function testExtractStatusFieldsWithPartialData(): void
    {
        $fppStatus = [
            'platform' => 'BeagleBone',
            'status_name' => 'testing'
        ];

        $result = $this->remoteControl->extractStatusFields($fppStatus);

        $this->assertEquals('BeagleBone', $result['platform']);
        $this->assertEquals('--', $result['branch']);
        $this->assertEquals('--', $result['mode_name']);
        $this->assertEquals('testing', $result['status_name']);
        $this->assertEquals(0, $result['rebootFlag']);
        $this->assertEquals(0, $result['restartFlag']);
    }

    public function testExtractStatusFieldsWithFlags(): void
    {
        $fppStatus = [
            'platform' => 'Pi',
            'rebootFlag' => 1,
            'restartFlag' => 1
        ];

        $result = $this->remoteControl->extractStatusFields($fppStatus);

        $this->assertEquals(1, $result['rebootFlag']);
        $this->assertEquals(1, $result['restartFlag']);
    }

    /**
     * @dataProvider statusFieldsProvider
     */
    public function testExtractStatusFieldsDataProvider(array $input, array $expected): void
    {
        $result = $this->remoteControl->extractStatusFields($input);

        foreach ($expected as $key => $value) {
            $this->assertArrayHasKey($key, $result);
            $this->assertEquals($value, $result[$key], "Field '{$key}' mismatch");
        }
    }

    public static function statusFieldsProvider(): array
    {
        return [
            'complete data' => [
                [
                    'platform' => 'Raspberry Pi 4',
                    'branch' => 'v7.0',
                    'mode_name' => 'master',
                    'status_name' => 'running',
                    'rebootFlag' => 1,
                    'restartFlag' => 0
                ],
                [
                    'platform' => 'Raspberry Pi 4',
                    'branch' => 'v7.0',
                    'mode_name' => 'master',
                    'status_name' => 'running',
                    'rebootFlag' => 1,
                    'restartFlag' => 0
                ]
            ],
            'empty array uses defaults' => [
                [],
                [
                    'platform' => '--',
                    'branch' => '--',
                    'mode_name' => '--',
                    'status_name' => 'idle',
                    'rebootFlag' => 0,
                    'restartFlag' => 0
                ]
            ],
            'only platform provided' => [
                ['platform' => 'BBB'],
                [
                    'platform' => 'BBB',
                    'branch' => '--',
                    'mode_name' => '--',
                    'status_name' => 'idle'
                ]
            ],
            'null values treated as missing' => [
                ['platform' => null, 'branch' => null],
                ['platform' => '--', 'branch' => '--']
            ]
        ];
    }

    // =========================================================================
    // checkPluginForUpdate Tests
    // =========================================================================

    public function testCheckPluginForUpdateWithNoUpdate(): void
    {
        $pluginInfo = [
            'name' => 'TestPlugin',
            'version' => '1.0.0',
            'updatesAvailable' => false
        ];

        $result = $this->remoteControl->checkPluginForUpdate($pluginInfo, 'test-plugin');

        $this->assertNull($result);
    }

    public function testCheckPluginForUpdateWithFppUpdateFlag(): void
    {
        $pluginInfo = [
            'name' => 'TestPlugin',
            'version' => '1.0.0',
            'updatesAvailable' => true
        ];

        $result = $this->remoteControl->checkPluginForUpdate($pluginInfo, 'test-plugin');

        $this->assertNotNull($result);
        $this->assertEquals('test-plugin', $result['repoName']);
        $this->assertEquals('TestPlugin', $result['name']);
        $this->assertEquals('1.0.0', $result['installedVersion']);
        $this->assertTrue($result['updatesAvailable']);
    }

    public function testCheckPluginForUpdateWatcherWithNewerVersion(): void
    {
        $pluginInfo = [
            'name' => 'Watcher',
            'version' => '1.0.0'
        ];

        $result = $this->remoteControl->checkPluginForUpdate(
            $pluginInfo,
            'fpp-plugin-watcher',
            '2.0.0'
        );

        $this->assertNotNull($result);
        $this->assertEquals('fpp-plugin-watcher', $result['repoName']);
        $this->assertEquals('1.0.0', $result['installedVersion']);
        $this->assertEquals('2.0.0', $result['latestVersion']);
        $this->assertTrue($result['updatesAvailable']);
    }

    public function testCheckPluginForUpdateWatcherWithSameVersion(): void
    {
        $pluginInfo = [
            'name' => 'Watcher',
            'version' => '1.0.0'
        ];

        $result = $this->remoteControl->checkPluginForUpdate(
            $pluginInfo,
            'fpp-plugin-watcher',
            '1.0.0'
        );

        $this->assertNull($result);
    }

    public function testCheckPluginForUpdateWatcherWithOlderVersion(): void
    {
        $pluginInfo = [
            'name' => 'Watcher',
            'version' => '2.0.0'
        ];

        $result = $this->remoteControl->checkPluginForUpdate(
            $pluginInfo,
            'fpp-plugin-watcher',
            '1.0.0'
        );

        // Installed version is newer, so no update needed
        $this->assertNull($result);
    }

    public function testCheckPluginForUpdateWithMissingVersionField(): void
    {
        $pluginInfo = [
            'name' => 'TestPlugin'
        ];

        $result = $this->remoteControl->checkPluginForUpdate($pluginInfo, 'test-plugin');

        $this->assertNull($result);
    }

    public function testCheckPluginForUpdateWithMissingNameField(): void
    {
        $pluginInfo = [
            'version' => '1.0.0',
            'updatesAvailable' => true
        ];

        $result = $this->remoteControl->checkPluginForUpdate($pluginInfo, 'test-plugin');

        $this->assertNotNull($result);
        $this->assertEquals('test-plugin', $result['name']); // Falls back to repoName
    }

    /**
     * @dataProvider pluginUpdateProvider
     */
    public function testCheckPluginForUpdateDataProvider(
        array $pluginInfo,
        string $repoName,
        ?string $latestWatcherVersion,
        ?array $expected
    ): void {
        $result = $this->remoteControl->checkPluginForUpdate($pluginInfo, $repoName, $latestWatcherVersion);

        if ($expected === null) {
            $this->assertNull($result);
        } else {
            $this->assertNotNull($result);
            foreach ($expected as $key => $value) {
                $this->assertArrayHasKey($key, $result);
                $this->assertEquals($value, $result[$key]);
            }
        }
    }

    public static function pluginUpdateProvider(): array
    {
        return [
            'no update available' => [
                ['name' => 'Plugin', 'version' => '1.0.0', 'updatesAvailable' => false],
                'test-plugin',
                null,
                null
            ],
            'FPP update flag set' => [
                ['name' => 'Plugin', 'version' => '1.0.0', 'updatesAvailable' => true],
                'test-plugin',
                null,
                ['repoName' => 'test-plugin', 'name' => 'Plugin', 'updatesAvailable' => true]
            ],
            'watcher newer version' => [
                ['name' => 'Watcher', 'version' => '1.0.0'],
                'fpp-plugin-watcher',
                '2.0.0',
                ['repoName' => 'fpp-plugin-watcher', 'latestVersion' => '2.0.0']
            ],
            'watcher same version' => [
                ['name' => 'Watcher', 'version' => '1.0.0'],
                'fpp-plugin-watcher',
                '1.0.0',
                null
            ],
            'watcher older remote' => [
                ['name' => 'Watcher', 'version' => '2.0.0'],
                'fpp-plugin-watcher',
                '1.5.0',
                null
            ],
            'watcher no remote version' => [
                ['name' => 'Watcher', 'version' => '1.0.0'],
                'fpp-plugin-watcher',
                null,
                null
            ],
            'watcher with FPP flag and newer version' => [
                ['name' => 'Watcher', 'version' => '1.0.0', 'updatesAvailable' => true],
                'fpp-plugin-watcher',
                '2.0.0',
                ['repoName' => 'fpp-plugin-watcher', 'latestVersion' => '2.0.0', 'updatesAvailable' => true]
            ],
            'non-watcher plugin with latest version param (should ignore)' => [
                ['name' => 'Other', 'version' => '1.0.0'],
                'other-plugin',
                '2.0.0',
                null
            ],
            'semantic version comparison' => [
                ['name' => 'Watcher', 'version' => '1.2.3'],
                'fpp-plugin-watcher',
                '1.2.4',
                ['repoName' => 'fpp-plugin-watcher', 'latestVersion' => '1.2.4']
            ],
            'pre-release version' => [
                ['name' => 'Watcher', 'version' => '1.0.0-beta'],
                'fpp-plugin-watcher',
                '1.0.0',
                ['repoName' => 'fpp-plugin-watcher']
            ]
        ];
    }

    // =========================================================================
    // callApi Validation Tests
    // =========================================================================

    public function testCallApiWithInvalidHost(): void
    {
        $result = $this->remoteControl->callApi('invalid host with spaces', 'GET', '/api/test');

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertEquals('Invalid host format', $result['error']);
        $this->assertEquals('invalid host with spaces', $result['host']);
    }

    public function testCallApiWithInjectionAttempt(): void
    {
        $result = $this->remoteControl->callApi('host;ls', 'GET', '/api/test');

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertEquals('Invalid host format', $result['error']);
    }

    public function testCallApiWithEmptyHost(): void
    {
        $result = $this->remoteControl->callApi('', 'GET', '/api/test');

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertEquals('Invalid host format', $result['error']);
    }

    public function testCallApiResultStructure(): void
    {
        // Use localhost with invalid path - fails fast
        $result = $this->remoteControl->callApi('127.0.0.1', 'GET', '/nonexistent/path/99999', [], 1);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('host', $result);
        $this->assertIsBool($result['success']);
    }

    // =========================================================================
    // getBulkStatus Tests
    // =========================================================================

    public function testGetBulkStatusWithEmptyArray(): void
    {
        $result = $this->remoteControl->getBulkStatus([]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertEquals([], $result['hosts']);
    }

    public function testGetBulkStatusReturnsExpectedStructure(): void
    {
        $remoteSystems = [
            ['address' => '127.0.0.1', 'hostname' => 'localhost']
        ];

        $result = $this->remoteControl->getBulkStatus($remoteSystems);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('hosts', $result);
        $this->assertIsArray($result['hosts']);
    }

    // =========================================================================
    // getBulkUpdates Tests
    // =========================================================================

    public function testGetBulkUpdatesWithEmptyArray(): void
    {
        $result = $this->remoteControl->getBulkUpdates([]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertEquals([], $result['hosts']);
    }

    public function testGetBulkUpdatesReturnsExpectedStructure(): void
    {
        $remoteSystems = [
            ['address' => '127.0.0.1', 'hostname' => 'localhost']
        ];

        $result = $this->remoteControl->getBulkUpdates($remoteSystems, '1.0.0');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('hosts', $result);
    }

    // =========================================================================
    // Method Existence Tests
    // =========================================================================

    public function testPublicMethodsExist(): void
    {
        $reflection = new \ReflectionClass(RemoteControl::class);

        $expectedMethods = [
            'getInstance',
            'extractStatusFields',
            'checkPluginForUpdate',
            'callApi',
            'getStatus',
            'sendCommand',
            'sendSimpleAction',
            'restartFPPD',
            'reboot',
            'upgradePlugin',
            'getPlugins',
            'checkPluginUpdates',
            'streamFPPUpgrade',
            'streamWatcherUpgrade',
            'getBulkStatus',
            'getOutputDiscrepancies',
            'getBulkUpdates'
        ];

        foreach ($expectedMethods as $methodName) {
            $this->assertTrue(
                $reflection->hasMethod($methodName),
                "Missing expected method: {$methodName}"
            );

            $method = $reflection->getMethod($methodName);
            $this->assertTrue(
                $method->isPublic(),
                "Method {$methodName} should be public"
            );
        }
    }

    // =========================================================================
    // upgradePlugin Validation Tests
    // =========================================================================

    public function testUpgradePluginWithInvalidPluginName(): void
    {
        $result = $this->remoteControl->upgradePlugin('127.0.0.1', 'invalid;plugin');

        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid plugin name', $result['error']);
    }

    /**
     * @dataProvider invalidPluginNameProvider
     */
    public function testUpgradePluginRejectsInvalidNames(string $pluginName): void
    {
        $result = $this->remoteControl->upgradePlugin('127.0.0.1', $pluginName);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid plugin name', $result['error']);
    }

    public static function invalidPluginNameProvider(): array
    {
        return [
            'semicolon injection' => ['plugin;ls'],
            'pipe injection' => ['plugin|cat'],
            'space' => ['plugin name'],
            'slash' => ['plugin/path'],
            'backslash' => ['plugin\\path'],
            'special chars' => ['plugin<script>'],
            'ampersand' => ['plugin&rm'],
            'dollar sign' => ['plugin$var'],
        ];
    }
}
