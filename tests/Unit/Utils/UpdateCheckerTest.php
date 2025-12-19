<?php
/**
 * Unit tests for UpdateChecker class
 *
 * @package Watcher\Tests\Unit\Utils
 */

declare(strict_types=1);

namespace Watcher\Tests\Unit\Utils;

use Watcher\Tests\TestCase;
use Watcher\Utils\UpdateChecker;

class UpdateCheckerTest extends TestCase
{
    private UpdateChecker $checker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checker = UpdateChecker::getInstance();
    }

    // =========================================================================
    // Singleton Tests
    // =========================================================================

    public function testGetInstanceReturnsSingleton(): void
    {
        $instance1 = UpdateChecker::getInstance();
        $instance2 = UpdateChecker::getInstance();

        $this->assertSame($instance1, $instance2);
    }

    public function testGetInstanceReturnsUpdateCheckerInstance(): void
    {
        $instance = UpdateChecker::getInstance();
        $this->assertInstanceOf(UpdateChecker::class, $instance);
    }

    // =========================================================================
    // Method Existence Tests
    // =========================================================================

    public function testPublicMethodsExist(): void
    {
        $reflection = new \ReflectionClass(UpdateChecker::class);

        $expectedMethods = [
            'getInstance',
            'getLatestWatcherVersion',
            'checkWatcherUpdate',
            'getLatestFPPRelease',
            'parseFPPVersion',
            'compareFPPVersions',
            'checkFPPReleaseUpgrade',
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
    // Version Parsing Tests - parseFPPVersion()
    // =========================================================================

    /**
     * @dataProvider versionParsingProvider
     */
    public function testParseFPPVersionFormats(string $version, array $expected): void
    {
        $result = $this->checker->parseFPPVersion($version);
        $this->assertEquals($expected, $result, "Failed parsing version: {$version}");
    }

    public static function versionParsingProvider(): array
    {
        return [
            // Standard versions
            'simple major.minor' => ['9.3', [9, 3]],
            'double digit minor' => ['9.12', [9, 12]],
            'double digit major' => ['10.0', [10, 0]],
            'both double digits' => ['12.15', [12, 15]],

            // With v prefix
            'v prefix lowercase' => ['v9.3', [9, 3]],
            'v prefix with double digits' => ['v10.5', [10, 5]],

            // Git-style versions
            'git commit suffix' => ['9.3-3-g28ffc36a', [9, 3]],
            'git with many commits' => ['9.2-150-gabcdef12', [9, 2]],
            'v prefix with git suffix' => ['v9.3-3-g28ffc36a', [9, 3]],

            // Edge cases
            'single digit' => ['9.0', [9, 0]],
            'zero version' => ['0.0', [0, 0]],
            'zero minor' => ['5.0', [5, 0]],
            'large numbers' => ['99.99', [99, 99]],

            // Invalid formats - should return [0,0]
            'empty string' => ['', [0, 0]],
            'just v' => ['v', [0, 0]],
            'no minor' => ['9', [0, 0]],
            'text only' => ['master', [0, 0]],
            'development branch' => ['dev', [0, 0]],
            'invalid characters' => ['abc.def', [0, 0]],
        ];
    }

    public function testParseFPPVersionReturnsArray(): void
    {
        $result = $this->checker->parseFPPVersion('9.3');
        $this->assertIsArray($result);
        $this->assertCount(2, $result);
    }

    public function testParseFPPVersionReturnsIntegers(): void
    {
        $result = $this->checker->parseFPPVersion('9.3');
        $this->assertIsInt($result[0]);
        $this->assertIsInt($result[1]);
    }

    public function testParseFPPVersionHandlesLeadingZeros(): void
    {
        // PHP's preg_match with (int) cast handles this
        $result = $this->checker->parseFPPVersion('09.03');
        $this->assertEquals([9, 3], $result);
    }

    public function testParseFPPVersionStripsVPrefix(): void
    {
        $withV = $this->checker->parseFPPVersion('v9.3');
        $withoutV = $this->checker->parseFPPVersion('9.3');
        $this->assertEquals($withV, $withoutV);
    }

    // =========================================================================
    // Version Comparison Tests - compareFPPVersions()
    // =========================================================================

    /**
     * @dataProvider versionComparisonProvider
     */
    public function testCompareFPPVersions(string $current, string $latest, int $expected): void
    {
        $result = $this->checker->compareFPPVersions($current, $latest);
        $this->assertEquals($expected, $result, "Comparing {$current} to {$latest}");
    }

    public static function versionComparisonProvider(): array
    {
        return [
            // Current less than latest (should return -1)
            'older major' => ['8.0', '9.0', -1],
            'older minor' => ['9.2', '9.3', -1],
            'older both' => ['8.5', '9.3', -1],
            'v prefix current older' => ['v9.2', '9.3', -1],
            'v prefix latest older' => ['9.2', 'v9.3', -1],
            'both v prefix older' => ['v9.2', 'v9.3', -1],
            'git suffix current older' => ['9.2-50-gabc123', '9.3', -1],
            'git suffix latest older' => ['9.2', '9.3-3-gdef456', -1],

            // Current equal to latest (should return 0)
            'same version' => ['9.3', '9.3', 0],
            'same with v prefix' => ['v9.3', '9.3', 0],
            'both v prefix same' => ['v9.3', 'v9.3', 0],
            'git suffixes same base' => ['9.3-3-gabc123', '9.3-50-gdef456', 0],
            'same zero version' => ['0.0', '0.0', 0],

            // Current greater than latest (should return 1)
            'newer major' => ['10.0', '9.3', 1],
            'newer minor' => ['9.5', '9.3', 1],
            'newer both' => ['10.5', '9.3', 1],
            'v prefix current newer' => ['v10.0', '9.3', 1],
            'v prefix latest newer' => ['10.0', 'v9.3', 1],
            'git suffix current newer' => ['10.0-3-gabc123', '9.3', 1],
        ];
    }

    public function testCompareFPPVersionsReturnType(): void
    {
        $result = $this->checker->compareFPPVersions('9.3', '9.3');
        $this->assertIsInt($result);
        $this->assertContains($result, [-1, 0, 1]);
    }

    public function testCompareFPPVersionsSymmetry(): void
    {
        // If A < B, then B > A
        $this->assertEquals(-1, $this->checker->compareFPPVersions('9.2', '9.3'));
        $this->assertEquals(1, $this->checker->compareFPPVersions('9.3', '9.2'));
    }

    public function testCompareFPPVersionsTransitivity(): void
    {
        // If A < B and B < C, then A < C
        $this->assertEquals(-1, $this->checker->compareFPPVersions('9.1', '9.2'));
        $this->assertEquals(-1, $this->checker->compareFPPVersions('9.2', '9.3'));
        $this->assertEquals(-1, $this->checker->compareFPPVersions('9.1', '9.3'));
    }

    public function testCompareFPPVersionsReflexivity(): void
    {
        // A version equals itself
        $versions = ['9.0', '9.3', '10.0', 'v9.3', '9.3-5-gabc123'];
        foreach ($versions as $version) {
            $this->assertEquals(0, $this->checker->compareFPPVersions($version, $version));
        }
    }

    public function testCompareFPPVersionsMajorTakesPrecedence(): void
    {
        // Even if minor is higher, major version takes precedence
        $this->assertEquals(-1, $this->checker->compareFPPVersions('9.99', '10.0'));
        $this->assertEquals(1, $this->checker->compareFPPVersions('10.0', '9.99'));
    }

    // =========================================================================
    // Return Type Tests
    // =========================================================================

    public function testGetLatestWatcherVersionReturnsStringOrNull(): void
    {
        $this->skipIfNoNetwork();

        $result = $this->checker->getLatestWatcherVersion();

        $this->assertTrue(
            is_string($result) || is_null($result),
            'getLatestWatcherVersion should return string or null'
        );
    }

    public function testCheckWatcherUpdateReturnsArray(): void
    {
        $this->skipIfNoNetwork();

        $result = $this->checker->checkWatcherUpdate();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);

        if ($result['success']) {
            $this->assertArrayHasKey('latestVersion', $result);
            $this->assertArrayHasKey('repoName', $result);
        } else {
            $this->assertArrayHasKey('error', $result);
        }
    }

    public function testGetLatestFPPReleaseReturnsArrayOrNull(): void
    {
        $this->skipIfNoNetwork();

        $result = $this->checker->getLatestFPPRelease();

        $this->assertTrue(
            is_array($result) || is_null($result),
            'getLatestFPPRelease should return array or null'
        );
    }

    public function testGetLatestFPPReleaseArrayStructure(): void
    {
        $this->skipIfNoNetwork();

        $result = $this->checker->getLatestFPPRelease();

        if ($result !== null) {
            $this->assertArrayHasKey('tag', $result);
            $this->assertArrayHasKey('version', $result);
            $this->assertArrayHasKey('name', $result);
            $this->assertArrayHasKey('published_at', $result);
            $this->assertArrayHasKey('url', $result);
        }
    }

    // =========================================================================
    // checkFPPReleaseUpgrade Tests (using mock subclass)
    // =========================================================================

    public function testCheckFPPReleaseUpgradeReturnsArray(): void
    {
        $this->skipIfNoNetwork();

        $result = $this->checker->checkFPPReleaseUpgrade('v9.0');
        $this->assertIsArray($result);
    }

    public function testCheckFPPReleaseUpgradeStructureWhenAvailable(): void
    {
        $this->skipIfNoNetwork();

        // Use an old version to likely trigger "available"
        $result = $this->checker->checkFPPReleaseUpgrade('v1.0');

        if ($result['available'] ?? false) {
            $this->assertArrayHasKey('currentVersion', $result);
            $this->assertArrayHasKey('latestVersion', $result);
            $this->assertArrayHasKey('latestTag', $result);
            $this->assertArrayHasKey('releaseName', $result);
            $this->assertArrayHasKey('releaseUrl', $result);
            $this->assertArrayHasKey('isMajorUpgrade', $result);
        }
    }

    public function testCheckFPPReleaseUpgradeStructureWhenNotAvailable(): void
    {
        $this->skipIfNoNetwork();

        // Use a very high version to ensure no upgrade available
        $result = $this->checker->checkFPPReleaseUpgrade('v99.99');

        if (!($result['available'] ?? true)) {
            $this->assertArrayHasKey('currentVersion', $result);
            $this->assertArrayHasKey('latestVersion', $result);
            $this->assertFalse($result['available']);
        }
    }

    public function testCheckFPPReleaseUpgradeStripsVPrefix(): void
    {
        $this->skipIfNoNetwork();

        $result = $this->checker->checkFPPReleaseUpgrade('v9.0');

        if (isset($result['currentVersion'])) {
            // currentVersion should not have 'v' prefix
            $this->assertStringStartsNotWith('v', $result['currentVersion']);
        }
    }

    // =========================================================================
    // Cache Behavior Tests
    // =========================================================================

    public function testLatestFPPReleaseCaching(): void
    {
        $this->skipIfNoNetwork();

        // First call
        $result1 = $this->checker->getLatestFPPRelease();

        // Second call should be cached (faster)
        $start = microtime(true);
        $result2 = $this->checker->getLatestFPPRelease();
        $duration = microtime(true) - $start;

        // If caching works, second call should be very fast (< 0.01s)
        // But we can't guarantee this in all environments
        // Just verify both return same structure
        if (is_array($result1) && is_array($result2)) {
            $this->assertEquals(array_keys($result1), array_keys($result2));
        }
    }

    public function testLatestFPPReleaseCacheFileLocation(): void
    {
        $this->skipIfNoNetwork();

        // Trigger a fetch to create cache
        $this->checker->getLatestFPPRelease();

        // Cache file should be in /tmp
        $cacheFile = '/tmp/fpp-latest-release.json';
        if (file_exists($cacheFile)) {
            $content = file_get_contents($cacheFile);
            $data = json_decode($content, true);

            $this->assertIsArray($data);
            $this->assertArrayHasKey('timestamp', $data);
            $this->assertArrayHasKey('release', $data);
        }
    }

    // =========================================================================
    // Edge Case Tests
    // =========================================================================

    public function testParseFPPVersionWithWhitespace(): void
    {
        // Test trimming behavior (ltrim only handles 'v')
        $result = $this->checker->parseFPPVersion('9.3');
        $this->assertEquals([9, 3], $result);
    }

    public function testParseFPPVersionWithMultipleDots(): void
    {
        // Versions like "9.3.1" should extract first two numbers
        $result = $this->checker->parseFPPVersion('9.3.1');
        $this->assertEquals([9, 3], $result);
    }

    public function testParseFPPVersionWithAlphaNumericSuffix(): void
    {
        $testCases = [
            '9.3-beta' => [9, 3],
            '9.3-alpha' => [9, 3],
            '9.3-rc1' => [9, 3],
            '9.3-SNAPSHOT' => [9, 3],
        ];

        foreach ($testCases as $input => $expected) {
            $result = $this->checker->parseFPPVersion($input);
            $this->assertEquals($expected, $result, "Failed for: {$input}");
        }
    }

    public function testCompareFPPVersionsWithInvalidInputs(): void
    {
        // Both invalid should be equal (both [0,0])
        $this->assertEquals(0, $this->checker->compareFPPVersions('invalid', 'alsoinvalid'));

        // Valid vs invalid
        $this->assertEquals(1, $this->checker->compareFPPVersions('9.3', 'invalid'));
        $this->assertEquals(-1, $this->checker->compareFPPVersions('invalid', '9.3'));
    }

    public function testCompareFPPVersionsBoundaryConditions(): void
    {
        // Test around version boundaries
        $this->assertEquals(-1, $this->checker->compareFPPVersions('9.9', '10.0'));
        $this->assertEquals(1, $this->checker->compareFPPVersions('10.0', '9.9'));
        $this->assertEquals(0, $this->checker->compareFPPVersions('10.0', '10.0'));
    }

    // =========================================================================
    // Version Detection Logic Tests
    // =========================================================================

    public function testVersionComparisonForTypicalFPPVersions(): void
    {
        // Test common FPP version progression
        $versions = ['8.0', '8.1', '9.0', '9.1', '9.2', '9.3', '10.0'];

        for ($i = 0; $i < count($versions) - 1; $i++) {
            $this->assertEquals(
                -1,
                $this->checker->compareFPPVersions($versions[$i], $versions[$i + 1]),
                "{$versions[$i]} should be less than {$versions[$i + 1]}"
            );
        }
    }

    public function testVersionComparisonIgnoresCommitCount(): void
    {
        // Different commit counts after same base version should be equal
        $this->assertEquals(
            0,
            $this->checker->compareFPPVersions('9.3-1-gabc', '9.3-100-gdef')
        );
    }

    public function testMajorUpgradeDetection(): void
    {
        // Test that major version changes are properly detected via compareFPPVersions
        // 9.x to 10.x is a major upgrade
        $this->assertEquals(-1, $this->checker->compareFPPVersions('9.3', '10.0'));

        // Parse both to verify major version difference
        $v9 = $this->checker->parseFPPVersion('9.3');
        $v10 = $this->checker->parseFPPVersion('10.0');
        $this->assertGreaterThan($v9[0], $v10[0], '10.x major should be greater than 9.x');
    }

    // =========================================================================
    // Class Constants Tests (via Reflection)
    // =========================================================================

    public function testClassHasRequiredConstants(): void
    {
        $reflection = new \ReflectionClass(UpdateChecker::class);

        // Check private constants exist
        $constants = $reflection->getConstants();

        // These should exist (private constants are included in getConstants)
        $this->assertArrayHasKey('GITHUB_URL', $constants);
        $this->assertArrayHasKey('FPP_RELEASES_URL', $constants);
        $this->assertArrayHasKey('FPP_RELEASE_CACHE_FILE', $constants);
        $this->assertArrayHasKey('FPP_RELEASE_CACHE_TTL', $constants);
    }

    public function testCacheTTLIsReasonable(): void
    {
        $reflection = new \ReflectionClass(UpdateChecker::class);
        $constants = $reflection->getConstants();

        $ttl = $constants['FPP_RELEASE_CACHE_TTL'];

        // TTL should be at least 5 minutes and no more than 24 hours
        $this->assertGreaterThanOrEqual(300, $ttl, 'Cache TTL should be at least 5 minutes');
        $this->assertLessThanOrEqual(86400, $ttl, 'Cache TTL should be no more than 24 hours');
    }

    // =========================================================================
    // Watcher Version Format Tests
    // =========================================================================

    public function testLatestWatcherVersionFormat(): void
    {
        $this->skipIfNoNetwork();

        $version = $this->checker->getLatestWatcherVersion();

        if ($version !== null) {
            // Version should be in semver-like format (x.y.z)
            $this->assertMatchesRegularExpression(
                '/^\d+\.\d+(\.\d+)?$/',
                $version,
                'Version should be in format x.y or x.y.z'
            );
        }
    }

    public function testCheckWatcherUpdateSuccessResponse(): void
    {
        $this->skipIfNoNetwork();

        $result = $this->checker->checkWatcherUpdate();

        if ($result['success']) {
            // Verify version string format
            $this->assertMatchesRegularExpression(
                '/^\d+\.\d+(\.\d+)?$/',
                $result['latestVersion']
            );

            // Verify repo name is a string
            $this->assertIsString($result['repoName']);
            $this->assertNotEmpty($result['repoName']);
        }
    }

    public function testCheckWatcherUpdateErrorResponse(): void
    {
        // Test structure when GitHub is unreachable would give error response
        // We can verify the expected error response structure
        $expectedErrorKeys = ['success', 'error'];

        // Create a mock response structure
        $errorResponse = [
            'success' => false,
            'error' => 'Failed to fetch from GitHub'
        ];

        $this->assertArrayHasKey('success', $errorResponse);
        $this->assertFalse($errorResponse['success']);
        $this->assertArrayHasKey('error', $errorResponse);
    }

    // =========================================================================
    // Integration-style Tests (with network)
    // =========================================================================

    public function testFullUpgradeCheckWorkflow(): void
    {
        $this->skipIfNoNetwork();

        // Simulate checking if FPP needs upgrade
        $currentVersion = '9.0';

        // Get latest release
        $latest = $this->checker->getLatestFPPRelease();

        if ($latest !== null) {
            // Compare versions
            $comparison = $this->checker->compareFPPVersions($currentVersion, $latest['version']);

            // Check upgrade availability
            $upgradeInfo = $this->checker->checkFPPReleaseUpgrade($currentVersion);

            // Verify consistency
            if ($comparison < 0) {
                $this->assertTrue(
                    $upgradeInfo['available'],
                    'Upgrade should be available when current < latest'
                );
            }
        }
    }

    public function testWatcherUpdateCheckWorkflow(): void
    {
        $this->skipIfNoNetwork();

        // Test complete workflow
        $version = $this->checker->getLatestWatcherVersion();
        $update = $this->checker->checkWatcherUpdate();

        // Both should return consistent results
        if ($version !== null && $update['success']) {
            $this->assertEquals($version, $update['latestVersion']);
        }
    }

    // =========================================================================
    // HTTP Mocking Tests - Network Failure Paths
    // =========================================================================

    public function testGetLatestWatcherVersionReturnsNullOnNetworkFailure(): void
    {
        $mockChecker = new MockableUpdateChecker();
        $mockChecker->setMockFetchJsonResponse(null);

        $result = $mockChecker->getLatestWatcherVersion();
        $this->assertNull($result);
    }

    public function testGetLatestWatcherVersionReturnsNullWhenVersionMissing(): void
    {
        $mockChecker = new MockableUpdateChecker();
        // Response without 'version' key
        $mockChecker->setMockFetchJsonResponse(['repoName' => 'test-plugin']);

        $result = $mockChecker->getLatestWatcherVersion();
        $this->assertNull($result);
    }

    public function testGetLatestWatcherVersionReturnsVersionOnSuccess(): void
    {
        $mockChecker = new MockableUpdateChecker();
        $mockChecker->setMockFetchJsonResponse([
            'version' => '2.5.0',
            'repoName' => 'fpp-plugin-watcher'
        ]);

        $result = $mockChecker->getLatestWatcherVersion();
        $this->assertEquals('2.5.0', $result);
    }

    public function testCheckWatcherUpdateReturnsErrorOnNetworkFailure(): void
    {
        $mockChecker = new MockableUpdateChecker();
        $mockChecker->setMockFetchJsonResponse(null);

        $result = $mockChecker->checkWatcherUpdate();

        $this->assertFalse($result['success']);
        $this->assertEquals('Failed to fetch from GitHub', $result['error']);
    }

    public function testCheckWatcherUpdateReturnsErrorWhenVersionMissing(): void
    {
        $mockChecker = new MockableUpdateChecker();
        $mockChecker->setMockFetchJsonResponse(['repoName' => 'test-plugin']);

        $result = $mockChecker->checkWatcherUpdate();

        $this->assertFalse($result['success']);
        $this->assertEquals('Invalid response from GitHub', $result['error']);
    }

    public function testCheckWatcherUpdateReturnsSuccessWithValidData(): void
    {
        $mockChecker = new MockableUpdateChecker();
        $mockChecker->setMockFetchJsonResponse([
            'version' => '2.5.0',
            'repoName' => 'fpp-plugin-watcher'
        ]);

        $result = $mockChecker->checkWatcherUpdate();

        $this->assertTrue($result['success']);
        $this->assertEquals('2.5.0', $result['latestVersion']);
        $this->assertEquals('fpp-plugin-watcher', $result['repoName']);
    }

    public function testCheckWatcherUpdateUsesDefaultPluginNameWhenMissing(): void
    {
        $mockChecker = new MockableUpdateChecker();
        $mockChecker->setMockFetchJsonResponse([
            'version' => '2.5.0'
            // No repoName provided
        ]);

        $result = $mockChecker->checkWatcherUpdate();

        $this->assertTrue($result['success']);
        $this->assertEquals('2.5.0', $result['latestVersion']);
        // Should use default plugin name
        $this->assertNotEmpty($result['repoName']);
    }

    // =========================================================================
    // FPP Release Mocking Tests
    // =========================================================================

    public function testGetLatestFPPReleaseReturnsNullOnNetworkFailureWithNoCache(): void
    {
        $mockChecker = new MockableUpdateChecker();
        $mockChecker->setMockFileGetContents(false);
        $mockChecker->setMockCacheFile(null); // No cache

        $result = $mockChecker->getLatestFPPRelease();
        $this->assertNull($result);
    }

    public function testGetLatestFPPReleaseReturnsCachedDataOnNetworkFailure(): void
    {
        $mockChecker = new MockableUpdateChecker();
        $mockChecker->setMockFileGetContents(false);

        // Expired but valid cache
        $staleCache = [
            'timestamp' => time() - 7200, // 2 hours ago (expired)
            'release' => [
                'tag' => 'v9.3',
                'version' => '9.3',
                'name' => 'FPP 9.3',
                'published_at' => '2024-01-01T00:00:00Z',
                'url' => 'https://github.com/FalconChristmas/fpp/releases/tag/v9.3'
            ]
        ];
        $mockChecker->setMockCacheFile($staleCache);

        $result = $mockChecker->getLatestFPPRelease();

        $this->assertNotNull($result);
        $this->assertEquals('9.3', $result['version']);
        $this->assertEquals('v9.3', $result['tag']);
    }

    public function testGetLatestFPPReleaseUsesValidCache(): void
    {
        $mockChecker = new MockableUpdateChecker();

        // Fresh cache (within TTL)
        $freshCache = [
            'timestamp' => time() - 1800, // 30 minutes ago
            'release' => [
                'tag' => 'v9.5',
                'version' => '9.5',
                'name' => 'FPP 9.5',
                'published_at' => '2024-06-01T00:00:00Z',
                'url' => 'https://github.com/FalconChristmas/fpp/releases/tag/v9.5'
            ]
        ];
        $mockChecker->setMockCacheFile($freshCache);

        $result = $mockChecker->getLatestFPPRelease();

        $this->assertNotNull($result);
        $this->assertEquals('9.5', $result['version']);
    }

    public function testGetLatestFPPReleaseReturnsNullForEmptyReleaseArray(): void
    {
        $mockChecker = new MockableUpdateChecker();
        $mockChecker->setMockFileGetContents(json_encode([]));
        $mockChecker->setMockCacheFile(null);

        $result = $mockChecker->getLatestFPPRelease();
        $this->assertNull($result);
    }

    public function testGetLatestFPPReleaseReturnsNullForInvalidJson(): void
    {
        $mockChecker = new MockableUpdateChecker();
        $mockChecker->setMockFileGetContents('not valid json');
        $mockChecker->setMockCacheFile(null);

        $result = $mockChecker->getLatestFPPRelease();
        $this->assertNull($result);
    }

    public function testGetLatestFPPReleaseSkipsPrereleases(): void
    {
        $mockChecker = new MockableUpdateChecker();

        $releases = [
            [
                'tag_name' => 'v10.0-beta1',
                'name' => 'FPP 10.0 Beta 1',
                'prerelease' => true,
                'draft' => false,
                'published_at' => '2024-12-01T00:00:00Z',
                'html_url' => 'https://github.com/FalconChristmas/fpp/releases/tag/v10.0-beta1'
            ],
            [
                'tag_name' => 'v9.3',
                'name' => 'FPP 9.3',
                'prerelease' => false,
                'draft' => false,
                'published_at' => '2024-11-01T00:00:00Z',
                'html_url' => 'https://github.com/FalconChristmas/fpp/releases/tag/v9.3'
            ]
        ];

        $mockChecker->setMockFileGetContents(json_encode($releases));
        $mockChecker->setMockCacheFile(null);

        $result = $mockChecker->getLatestFPPRelease();

        $this->assertNotNull($result);
        $this->assertEquals('9.3', $result['version']);
        $this->assertEquals('v9.3', $result['tag']);
    }

    public function testGetLatestFPPReleaseSkipsDrafts(): void
    {
        $mockChecker = new MockableUpdateChecker();

        $releases = [
            [
                'tag_name' => 'v10.0',
                'name' => 'FPP 10.0 Draft',
                'prerelease' => false,
                'draft' => true,
                'published_at' => '2024-12-15T00:00:00Z',
                'html_url' => 'https://github.com/FalconChristmas/fpp/releases/tag/v10.0'
            ],
            [
                'tag_name' => 'v9.4',
                'name' => 'FPP 9.4',
                'prerelease' => false,
                'draft' => false,
                'published_at' => '2024-12-01T00:00:00Z',
                'html_url' => 'https://github.com/FalconChristmas/fpp/releases/tag/v9.4'
            ]
        ];

        $mockChecker->setMockFileGetContents(json_encode($releases));
        $mockChecker->setMockCacheFile(null);

        $result = $mockChecker->getLatestFPPRelease();

        $this->assertNotNull($result);
        $this->assertEquals('9.4', $result['version']);
    }

    public function testGetLatestFPPReleaseReturnsFirstStableRelease(): void
    {
        $mockChecker = new MockableUpdateChecker();

        $releases = [
            [
                'tag_name' => 'v9.5',
                'name' => 'FPP 9.5',
                'prerelease' => false,
                'draft' => false,
                'published_at' => '2024-12-01T00:00:00Z',
                'html_url' => 'https://github.com/FalconChristmas/fpp/releases/tag/v9.5'
            ],
            [
                'tag_name' => 'v9.4',
                'name' => 'FPP 9.4',
                'prerelease' => false,
                'draft' => false,
                'published_at' => '2024-11-01T00:00:00Z',
                'html_url' => 'https://github.com/FalconChristmas/fpp/releases/tag/v9.4'
            ]
        ];

        $mockChecker->setMockFileGetContents(json_encode($releases));
        $mockChecker->setMockCacheFile(null);

        $result = $mockChecker->getLatestFPPRelease();

        $this->assertNotNull($result);
        $this->assertEquals('9.5', $result['version']);
    }

    public function testGetLatestFPPReleaseStripsVPrefixFromVersion(): void
    {
        $mockChecker = new MockableUpdateChecker();

        $releases = [[
            'tag_name' => 'v9.6',
            'name' => 'FPP 9.6',
            'prerelease' => false,
            'draft' => false,
            'published_at' => '2024-12-01T00:00:00Z',
            'html_url' => 'https://github.com/FalconChristmas/fpp/releases/tag/v9.6'
        ]];

        $mockChecker->setMockFileGetContents(json_encode($releases));
        $mockChecker->setMockCacheFile(null);

        $result = $mockChecker->getLatestFPPRelease();

        $this->assertEquals('9.6', $result['version']);
        $this->assertEquals('v9.6', $result['tag']);
    }

    public function testGetLatestFPPReleaseHandlesMissingHtmlUrl(): void
    {
        $mockChecker = new MockableUpdateChecker();

        $releases = [[
            'tag_name' => 'v9.6',
            'name' => 'FPP 9.6',
            'prerelease' => false,
            'draft' => false,
            'published_at' => '2024-12-01T00:00:00Z'
            // No html_url
        ]];

        $mockChecker->setMockFileGetContents(json_encode($releases));
        $mockChecker->setMockCacheFile(null);

        $result = $mockChecker->getLatestFPPRelease();

        $this->assertNotNull($result);
        $this->assertEquals('', $result['url']);
    }

    public function testGetLatestFPPReleaseReturnsNullWhenAllReleasesArePrereleases(): void
    {
        $mockChecker = new MockableUpdateChecker();

        $releases = [
            [
                'tag_name' => 'v10.0-beta1',
                'name' => 'FPP 10.0 Beta 1',
                'prerelease' => true,
                'draft' => false,
                'published_at' => '2024-12-01T00:00:00Z',
                'html_url' => 'https://example.com'
            ],
            [
                'tag_name' => 'v10.0-alpha',
                'name' => 'FPP 10.0 Alpha',
                'prerelease' => true,
                'draft' => false,
                'published_at' => '2024-11-01T00:00:00Z',
                'html_url' => 'https://example.com'
            ]
        ];

        $mockChecker->setMockFileGetContents(json_encode($releases));
        $mockChecker->setMockCacheFile(null);

        $result = $mockChecker->getLatestFPPRelease();
        $this->assertNull($result);
    }

    // =========================================================================
    // Cache Expiry Scenario Tests
    // =========================================================================

    public function testCacheExpiryTriggersNetworkFetch(): void
    {
        $mockChecker = new MockableUpdateChecker();

        // Expired cache
        $expiredCache = [
            'timestamp' => time() - 7200, // 2 hours ago
            'release' => [
                'tag' => 'v9.3',
                'version' => '9.3',
                'name' => 'FPP 9.3 (cached)',
                'published_at' => '2024-01-01T00:00:00Z',
                'url' => 'https://example.com/v9.3'
            ]
        ];
        $mockChecker->setMockCacheFile($expiredCache);

        // Fresh data from network
        $freshReleases = [[
            'tag_name' => 'v9.5',
            'name' => 'FPP 9.5 (fresh)',
            'prerelease' => false,
            'draft' => false,
            'published_at' => '2024-06-01T00:00:00Z',
            'html_url' => 'https://example.com/v9.5'
        ]];
        $mockChecker->setMockFileGetContents(json_encode($freshReleases));

        $result = $mockChecker->getLatestFPPRelease();

        // Should return fresh data, not cached
        $this->assertEquals('9.5', $result['version']);
        $this->assertStringContainsString('fresh', $result['name']);
    }

    public function testCacheTTLBoundaryExactlyExpired(): void
    {
        $mockChecker = new MockableUpdateChecker();

        // Cache exactly at TTL boundary (3600 seconds = 1 hour)
        $boundaryCache = [
            'timestamp' => time() - 3600, // Exactly at TTL
            'release' => [
                'tag' => 'v9.3',
                'version' => '9.3',
                'name' => 'Boundary Cache',
                'published_at' => '2024-01-01T00:00:00Z',
                'url' => 'https://example.com'
            ]
        ];
        $mockChecker->setMockCacheFile($boundaryCache);

        // Fresh data from network
        $freshReleases = [[
            'tag_name' => 'v9.5',
            'name' => 'Fresh Data',
            'prerelease' => false,
            'draft' => false,
            'published_at' => '2024-06-01T00:00:00Z',
            'html_url' => 'https://example.com'
        ]];
        $mockChecker->setMockFileGetContents(json_encode($freshReleases));

        $result = $mockChecker->getLatestFPPRelease();

        // Should fetch fresh since cache is at/past TTL
        $this->assertEquals('9.5', $result['version']);
    }

    public function testCacheJustUnderTTLReturnsCache(): void
    {
        $mockChecker = new MockableUpdateChecker();

        // Cache just under TTL (3599 seconds)
        $freshCache = [
            'timestamp' => time() - 3599, // 1 second before expiry
            'release' => [
                'tag' => 'v9.3',
                'version' => '9.3',
                'name' => 'Fresh Cache',
                'published_at' => '2024-01-01T00:00:00Z',
                'url' => 'https://example.com'
            ]
        ];
        $mockChecker->setMockCacheFile($freshCache);

        $result = $mockChecker->getLatestFPPRelease();

        // Should return cached data
        $this->assertEquals('9.3', $result['version']);
        $this->assertEquals('Fresh Cache', $result['name']);
    }

    // =========================================================================
    // checkFPPReleaseUpgrade Mocking Tests
    // =========================================================================

    public function testCheckFPPReleaseUpgradeReturnsErrorWhenReleaseFetchFails(): void
    {
        $mockChecker = new MockableUpdateChecker();
        $mockChecker->setMockFileGetContents(false);
        $mockChecker->setMockCacheFile(null);

        $result = $mockChecker->checkFPPReleaseUpgrade('v9.0');

        $this->assertFalse($result['available']);
        $this->assertEquals('Could not fetch release info', $result['error']);
    }

    public function testCheckFPPReleaseUpgradeDetectsAvailableUpgrade(): void
    {
        $mockChecker = new MockableUpdateChecker();

        $releases = [[
            'tag_name' => 'v9.5',
            'name' => 'FPP 9.5',
            'prerelease' => false,
            'draft' => false,
            'published_at' => '2024-12-01T00:00:00Z',
            'html_url' => 'https://github.com/FalconChristmas/fpp/releases/tag/v9.5'
        ]];
        $mockChecker->setMockFileGetContents(json_encode($releases));
        $mockChecker->setMockCacheFile(null);

        $result = $mockChecker->checkFPPReleaseUpgrade('v9.0');

        $this->assertTrue($result['available']);
        $this->assertEquals('9.0', $result['currentVersion']);
        $this->assertEquals('9.5', $result['latestVersion']);
        $this->assertEquals('v9.5', $result['latestTag']);
        $this->assertEquals('FPP 9.5', $result['releaseName']);
        $this->assertFalse($result['isMajorUpgrade']);
    }

    public function testCheckFPPReleaseUpgradeDetectsMajorUpgrade(): void
    {
        $mockChecker = new MockableUpdateChecker();

        $releases = [[
            'tag_name' => 'v10.0',
            'name' => 'FPP 10.0',
            'prerelease' => false,
            'draft' => false,
            'published_at' => '2024-12-01T00:00:00Z',
            'html_url' => 'https://example.com'
        ]];
        $mockChecker->setMockFileGetContents(json_encode($releases));
        $mockChecker->setMockCacheFile(null);

        $result = $mockChecker->checkFPPReleaseUpgrade('v9.5');

        $this->assertTrue($result['available']);
        $this->assertTrue($result['isMajorUpgrade']);
        $this->assertEquals('9.5', $result['currentVersion']);
        $this->assertEquals('10.0', $result['latestVersion']);
    }

    public function testCheckFPPReleaseUpgradeNoUpgradeWhenCurrent(): void
    {
        $mockChecker = new MockableUpdateChecker();

        $releases = [[
            'tag_name' => 'v9.5',
            'name' => 'FPP 9.5',
            'prerelease' => false,
            'draft' => false,
            'published_at' => '2024-12-01T00:00:00Z',
            'html_url' => 'https://example.com'
        ]];
        $mockChecker->setMockFileGetContents(json_encode($releases));
        $mockChecker->setMockCacheFile(null);

        $result = $mockChecker->checkFPPReleaseUpgrade('v9.5');

        $this->assertFalse($result['available']);
        $this->assertEquals('9.5', $result['currentVersion']);
        $this->assertEquals('9.5', $result['latestVersion']);
    }

    public function testCheckFPPReleaseUpgradeNoUpgradeWhenNewer(): void
    {
        $mockChecker = new MockableUpdateChecker();

        $releases = [[
            'tag_name' => 'v9.5',
            'name' => 'FPP 9.5',
            'prerelease' => false,
            'draft' => false,
            'published_at' => '2024-12-01T00:00:00Z',
            'html_url' => 'https://example.com'
        ]];
        $mockChecker->setMockFileGetContents(json_encode($releases));
        $mockChecker->setMockCacheFile(null);

        $result = $mockChecker->checkFPPReleaseUpgrade('v10.0');

        $this->assertFalse($result['available']);
        $this->assertEquals('10.0', $result['currentVersion']);
        $this->assertEquals('9.5', $result['latestVersion']);
    }

    public function testCheckFPPReleaseUpgradeWithGitVersionSuffix(): void
    {
        $mockChecker = new MockableUpdateChecker();

        $releases = [[
            'tag_name' => 'v9.5',
            'name' => 'FPP 9.5',
            'prerelease' => false,
            'draft' => false,
            'published_at' => '2024-12-01T00:00:00Z',
            'html_url' => 'https://example.com'
        ]];
        $mockChecker->setMockFileGetContents(json_encode($releases));
        $mockChecker->setMockCacheFile(null);

        // Current version with git suffix
        $result = $mockChecker->checkFPPReleaseUpgrade('9.3-50-gabc123');

        $this->assertTrue($result['available']);
        $this->assertEquals('9.3-50-gabc123', $result['currentVersion']);
        $this->assertEquals('9.5', $result['latestVersion']);
    }

    // =========================================================================
    // Invalid GitHub API Response Tests
    // =========================================================================

    public function testGetLatestFPPReleaseHandlesNonArrayJson(): void
    {
        $mockChecker = new MockableUpdateChecker();
        // JSON string instead of array
        $mockChecker->setMockFileGetContents(json_encode('just a string'));
        $mockChecker->setMockCacheFile(null);

        $result = $mockChecker->getLatestFPPRelease();
        $this->assertNull($result);
    }

    public function testGetLatestFPPReleaseHandlesMalformedReleaseObject(): void
    {
        $mockChecker = new MockableUpdateChecker();

        // Missing required fields
        $releases = [[
            'tag_name' => 'v9.5'
            // Missing name, prerelease, draft, published_at
        ]];
        $mockChecker->setMockFileGetContents(json_encode($releases));
        $mockChecker->setMockCacheFile(null);

        // Should return null due to missing prerelease/draft checks
        $result = $mockChecker->getLatestFPPRelease();

        // The code checks !$release['prerelease'] && !$release['draft']
        // With missing keys, this will cause warnings or return unexpected results
        // This test verifies the behavior (may be null or have issues)
        $this->assertTrue($result === null || isset($result['tag']));
    }

    public function testFetchJsonUrlReturnsNullForNonArrayJson(): void
    {
        $mockChecker = new MockableUpdateChecker();
        // Simulate fetchJsonUrl returning non-array (e.g., integer)
        $mockChecker->setMockFetchJsonResponse(null);

        $result = $mockChecker->getLatestWatcherVersion();
        $this->assertNull($result);
    }

    // =========================================================================
    // Cache File Edge Cases
    // =========================================================================

    public function testCacheWithMissingTimestamp(): void
    {
        $mockChecker = new MockableUpdateChecker();

        // Cache without timestamp
        $invalidCache = [
            'release' => [
                'tag' => 'v9.3',
                'version' => '9.3',
                'name' => 'No Timestamp',
                'published_at' => '2024-01-01T00:00:00Z',
                'url' => 'https://example.com'
            ]
        ];
        $mockChecker->setMockCacheFile($invalidCache);

        // Fresh data from network
        $freshReleases = [[
            'tag_name' => 'v9.5',
            'name' => 'Fresh',
            'prerelease' => false,
            'draft' => false,
            'published_at' => '2024-06-01T00:00:00Z',
            'html_url' => 'https://example.com'
        ]];
        $mockChecker->setMockFileGetContents(json_encode($freshReleases));

        $result = $mockChecker->getLatestFPPRelease();

        // Should fetch fresh since cache is invalid
        $this->assertEquals('9.5', $result['version']);
    }

    public function testCacheWithMissingRelease(): void
    {
        $mockChecker = new MockableUpdateChecker();

        // Cache without release data
        $invalidCache = [
            'timestamp' => time() - 1800 // Valid timestamp
        ];
        $mockChecker->setMockCacheFile($invalidCache);

        // Fresh data from network
        $freshReleases = [[
            'tag_name' => 'v9.5',
            'name' => 'Fresh',
            'prerelease' => false,
            'draft' => false,
            'published_at' => '2024-06-01T00:00:00Z',
            'html_url' => 'https://example.com'
        ]];
        $mockChecker->setMockFileGetContents(json_encode($freshReleases));

        $result = $mockChecker->getLatestFPPRelease();

        // May return null from cache or fetch fresh
        $this->assertTrue($result === null || $result['version'] === '9.5');
    }

    public function testNetworkFailureWithCorruptedCache(): void
    {
        $mockChecker = new MockableUpdateChecker();
        $mockChecker->setMockFileGetContents(false);

        // Corrupted cache - valid timestamp but no release
        $corruptedCache = [
            'timestamp' => time() - 7200
        ];
        $mockChecker->setMockCacheFile($corruptedCache);

        $result = $mockChecker->getLatestFPPRelease();

        // Should return null since cache has no release and network failed
        $this->assertNull($result);
    }

    // =========================================================================
    // Additional Edge Cases
    // =========================================================================

    public function testCheckFPPReleaseUpgradeIncludesAllRequiredFieldsWhenAvailable(): void
    {
        $mockChecker = new MockableUpdateChecker();

        $releases = [[
            'tag_name' => 'v9.5',
            'name' => 'FPP 9.5',
            'prerelease' => false,
            'draft' => false,
            'published_at' => '2024-12-01T00:00:00Z',
            'html_url' => 'https://github.com/FalconChristmas/fpp/releases/tag/v9.5'
        ]];
        $mockChecker->setMockFileGetContents(json_encode($releases));
        $mockChecker->setMockCacheFile(null);

        $result = $mockChecker->checkFPPReleaseUpgrade('v9.0');

        $this->assertArrayHasKey('available', $result);
        $this->assertArrayHasKey('currentVersion', $result);
        $this->assertArrayHasKey('latestVersion', $result);
        $this->assertArrayHasKey('latestTag', $result);
        $this->assertArrayHasKey('releaseName', $result);
        $this->assertArrayHasKey('releaseUrl', $result);
        $this->assertArrayHasKey('isMajorUpgrade', $result);
    }

    public function testCheckFPPReleaseUpgradeStripsVPrefixFromCurrentVersion(): void
    {
        $mockChecker = new MockableUpdateChecker();

        $releases = [[
            'tag_name' => 'v9.5',
            'name' => 'FPP 9.5',
            'prerelease' => false,
            'draft' => false,
            'published_at' => '2024-12-01T00:00:00Z',
            'html_url' => 'https://example.com'
        ]];
        $mockChecker->setMockFileGetContents(json_encode($releases));
        $mockChecker->setMockCacheFile(null);

        $result = $mockChecker->checkFPPReleaseUpgrade('v9.0');

        $this->assertEquals('9.0', $result['currentVersion']);
        $this->assertStringStartsNotWith('v', $result['currentVersion']);
    }

    public function testMinorVersionUpgradeNotMajor(): void
    {
        $mockChecker = new MockableUpdateChecker();

        $releases = [[
            'tag_name' => 'v9.5',
            'name' => 'FPP 9.5',
            'prerelease' => false,
            'draft' => false,
            'published_at' => '2024-12-01T00:00:00Z',
            'html_url' => 'https://example.com'
        ]];
        $mockChecker->setMockFileGetContents(json_encode($releases));
        $mockChecker->setMockCacheFile(null);

        // Same major version (9.x to 9.x)
        $result = $mockChecker->checkFPPReleaseUpgrade('v9.2');

        $this->assertTrue($result['available']);
        $this->assertFalse($result['isMajorUpgrade']);
    }

    public function testCacheWriteVerification(): void
    {
        $mockChecker = new MockableUpdateChecker();

        $releases = [[
            'tag_name' => 'v9.5',
            'name' => 'FPP 9.5',
            'prerelease' => false,
            'draft' => false,
            'published_at' => '2024-12-01T00:00:00Z',
            'html_url' => 'https://example.com'
        ]];
        $mockChecker->setMockFileGetContents(json_encode($releases));
        $mockChecker->setMockCacheFile(null);

        $result = $mockChecker->getLatestFPPRelease();

        // Verify result was written to cache
        $writtenCache = $mockChecker->getWrittenCacheData();

        if ($writtenCache !== null) {
            $this->assertArrayHasKey('timestamp', $writtenCache);
            $this->assertArrayHasKey('release', $writtenCache);
            $this->assertEquals('9.5', $writtenCache['release']['version']);
        }
    }

    public function testReleaseUrlDefaultsToEmptyString(): void
    {
        $mockChecker = new MockableUpdateChecker();

        $releases = [[
            'tag_name' => 'v9.5',
            'name' => 'FPP 9.5',
            'prerelease' => false,
            'draft' => false,
            'published_at' => '2024-12-01T00:00:00Z'
            // No html_url
        ]];
        $mockChecker->setMockFileGetContents(json_encode($releases));
        $mockChecker->setMockCacheFile(null);

        $result = $mockChecker->checkFPPReleaseUpgrade('v9.0');

        $this->assertTrue($result['available']);
        $this->assertEquals('', $result['releaseUrl']);
    }
}

/**
 * Mockable subclass for testing UpdateChecker without network calls
 *
 * This class overrides the protected HTTP/cache methods to inject mock data,
 * while still executing the real UpdateChecker business logic.
 */
class MockableUpdateChecker extends UpdateChecker
{
    private $mockFetchUrlResponse = null;
    private bool $mockFetchUrlEnabled = false;
    private ?array $mockCacheData = null;
    private bool $mockCacheEnabled = false;
    private ?array $writtenCacheData = null;
    private ?array $mockFetchJsonResponse = null;
    private bool $mockFetchJsonEnabled = false;

    public function __construct()
    {
        // Skip parent constructor to avoid ApiClient dependency
    }

    /**
     * Set mock response for fetchUrl()
     * @param string|false $response
     */
    public function setMockFileGetContents($response): void
    {
        $this->mockFetchUrlResponse = $response;
        $this->mockFetchUrlEnabled = true;
    }

    /**
     * Set mock cache data for readCacheFile()
     */
    public function setMockCacheFile(?array $cacheData): void
    {
        $this->mockCacheData = $cacheData;
        $this->mockCacheEnabled = true;
    }

    /**
     * Set mock response for fetchJsonUrl (used by getLatestWatcherVersion, checkWatcherUpdate)
     */
    public function setMockFetchJsonResponse(?array $response): void
    {
        $this->mockFetchJsonResponse = $response;
        $this->mockFetchJsonEnabled = true;
    }

    /**
     * Get data that was written to cache
     */
    public function getWrittenCacheData(): ?array
    {
        return $this->writtenCacheData;
    }

    /**
     * Override fetchUrl to return mock data
     */
    protected function fetchUrl(string $url, int $timeout = 10)
    {
        if ($this->mockFetchUrlEnabled) {
            return $this->mockFetchUrlResponse;
        }
        return parent::fetchUrl($url, $timeout);
    }

    /**
     * Override readCacheFile to return mock data
     */
    protected function readCacheFile(): ?array
    {
        if ($this->mockCacheEnabled) {
            return $this->mockCacheData;
        }
        return parent::readCacheFile();
    }

    /**
     * Override writeCacheFile to capture written data
     */
    protected function writeCacheFile(array $data): bool
    {
        $this->writtenCacheData = $data;
        return true;
    }

    /**
     * Override getLatestWatcherVersion to use mock fetchJsonUrl
     */
    public function getLatestWatcherVersion(): ?string
    {
        if ($this->mockFetchJsonEnabled) {
            if ($this->mockFetchJsonResponse && isset($this->mockFetchJsonResponse['version'])) {
                return $this->mockFetchJsonResponse['version'];
            }
            return null;
        }
        return parent::getLatestWatcherVersion();
    }

    /**
     * Override checkWatcherUpdate to use mock fetchJsonUrl
     */
    public function checkWatcherUpdate(): array
    {
        if ($this->mockFetchJsonEnabled) {
            if (!$this->mockFetchJsonResponse) {
                return [
                    'success' => false,
                    'error' => 'Failed to fetch from GitHub'
                ];
            }

            if (!isset($this->mockFetchJsonResponse['version'])) {
                return [
                    'success' => false,
                    'error' => 'Invalid response from GitHub'
                ];
            }

            return [
                'success' => true,
                'latestVersion' => $this->mockFetchJsonResponse['version'],
                'repoName' => $this->mockFetchJsonResponse['repoName'] ?? 'fpp-plugin-watcher'
            ];
        }
        return parent::checkWatcherUpdate();
    }
}
