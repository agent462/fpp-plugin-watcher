<?php
/**
 * Unit tests for ClockDrift class
 */

declare(strict_types=1);

namespace Watcher\Tests\Unit\MultiSync;

use Watcher\Tests\TestCase;
use Watcher\MultiSync\ClockDrift;

/**
 * Mock helper for simulating clock drift measurements
 * This doesn't extend ClockDrift since it has a private constructor
 */
class MockClockDriftHelper
{
    /** @var array|null Mock responses for each address */
    private ?array $mockResponses = null;

    /** @var array|null Mock HTTP codes for each address */
    private ?array $mockHttpCodes = null;

    /** @var array|null Mock total times for each address */
    private ?array $mockTotalTimes = null;

    /**
     * Set mock response data for testing
     */
    public function setMockResponses(array $responses, array $httpCodes = [], array $totalTimes = []): void
    {
        $this->mockResponses = $responses;
        $this->mockHttpCodes = $httpCodes;
        $this->mockTotalTimes = $totalTimes;
    }

    /**
     * Simulate measureClockDrift with mock data
     * This replicates the logic from ClockDrift::measureClockDrift() for testing
     */
    public function measureClockDrift(array $remoteSystems, int $numSamples = 3, int $timeout = 2): array
    {
        if (empty($remoteSystems)) {
            return ['success' => true, 'hosts' => [], 'message' => 'No remote systems'];
        }

        $bestMeasurements = [];
        $batchEndTime = microtime(true) * 1000;

        foreach ($remoteSystems as $system) {
            $address = $system['address'];

            $response = $this->mockResponses[$address] ?? null;
            $httpCode = $this->mockHttpCodes[$address] ?? ($response !== null ? 200 : 0);
            $totalTime = $this->mockTotalTimes[$address] ?? 0.05;

            if ($httpCode !== 200 || !$response) {
                $bestMeasurements[$address] = [
                    'hostname' => $system['hostname'],
                    'online' => $httpCode > 0,
                    'hasPlugin' => false,
                    'drift_ms' => null,
                    'rtt_ms' => null
                ];
                continue;
            }

            $data = json_decode($response, true);
            if (!$data || !isset($data['time_ms'])) {
                continue;
            }

            $rtt = $totalTime * 1000;
            $remoteTime = $data['time_ms'];
            $estimatedLocalMidpoint = $batchEndTime - ($rtt / 2);
            $drift = $remoteTime - $estimatedLocalMidpoint;

            $bestMeasurements[$address] = [
                'hostname' => $system['hostname'],
                'online' => true,
                'hasPlugin' => true,
                'drift_ms' => (int)round($drift),
                'rtt_ms' => round($rtt, 1),
                'samples' => $numSamples
            ];
        }

        $hosts = [];
        foreach ($bestMeasurements as $address => $measurement) {
            $hosts[] = array_merge(['address' => $address], $measurement);
        }

        $drifts = array_filter(array_column($hosts, 'drift_ms'), function($v) { return $v !== null; });
        $summary = [
            'hostsChecked' => count($hosts),
            'hostsWithPlugin' => count(array_filter($hosts, function($h) { return $h['hasPlugin']; })),
            'avgDrift' => count($drifts) > 0 ? (int)round(array_sum($drifts) / count($drifts)) : null,
            'maxDrift' => count($drifts) > 0 ? max(array_map('abs', $drifts)) : null
        ];

        return [
            'success' => true,
            'hosts' => $hosts,
            'summary' => $summary
        ];
    }

    /**
     * Simulate measureSingleHost with mock data
     */
    public function measureSingleHost(string $address, string $hostname = '', int $numSamples = 3, int $timeout = 2): array
    {
        $remoteSystems = [
            ['address' => $address, 'hostname' => $hostname ?: $address]
        ];

        $result = $this->measureClockDrift($remoteSystems, $numSamples, $timeout);

        if (!empty($result['hosts'])) {
            return [
                'success' => true,
                'host' => $result['hosts'][0]
            ];
        }

        return [
            'success' => false,
            'error' => 'Failed to measure clock drift'
        ];
    }
}

class ClockDriftTest extends TestCase
{
    private ClockDrift $clockDrift;
    private MockClockDriftHelper $mockHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clockDrift = ClockDrift::getInstance();
        $this->mockHelper = new MockClockDriftHelper();
    }

    // ========================================
    // Singleton Pattern Tests
    // ========================================

    public function testGetInstanceReturnsSameInstance(): void
    {
        $instance1 = ClockDrift::getInstance();
        $instance2 = ClockDrift::getInstance();

        $this->assertSame($instance1, $instance2);
    }

    public function testGetInstanceReturnsClockDriftInstance(): void
    {
        $instance = ClockDrift::getInstance();

        $this->assertInstanceOf(ClockDrift::class, $instance);
    }

    // ========================================
    // getDriftStatus() Tests
    // ========================================

    /**
     * @dataProvider driftStatusProvider
     */
    public function testGetDriftStatusReturnsCorrectStatus(int $driftMs, string $expectedStatus): void
    {
        $status = ClockDrift::getDriftStatus($driftMs);

        $this->assertEquals($expectedStatus, $status);
    }

    public static function driftStatusProvider(): array
    {
        return [
            // Excellent: <= 50ms
            'zero drift' => [0, 'excellent'],
            '25ms positive' => [25, 'excellent'],
            '50ms positive' => [50, 'excellent'],
            '25ms negative' => [-25, 'excellent'],
            '50ms negative' => [-50, 'excellent'],

            // Good: 51-100ms
            '51ms positive' => [51, 'good'],
            '75ms positive' => [75, 'good'],
            '100ms positive' => [100, 'good'],
            '75ms negative' => [-75, 'good'],
            '100ms negative' => [-100, 'good'],

            // Fair: 101-500ms
            '101ms positive' => [101, 'fair'],
            '250ms positive' => [250, 'fair'],
            '500ms positive' => [500, 'fair'],
            '250ms negative' => [-250, 'fair'],
            '500ms negative' => [-500, 'fair'],

            // Poor: > 500ms
            '501ms positive' => [501, 'poor'],
            '1000ms positive' => [1000, 'poor'],
            '5000ms positive' => [5000, 'poor'],
            '501ms negative' => [-501, 'poor'],
            '1000ms negative' => [-1000, 'poor'],
        ];
    }

    public function testGetDriftStatusBoundaryAt50(): void
    {
        $this->assertEquals('excellent', ClockDrift::getDriftStatus(50));
        $this->assertEquals('good', ClockDrift::getDriftStatus(51));
    }

    public function testGetDriftStatusBoundaryAt100(): void
    {
        $this->assertEquals('good', ClockDrift::getDriftStatus(100));
        $this->assertEquals('fair', ClockDrift::getDriftStatus(101));
    }

    public function testGetDriftStatusBoundaryAt500(): void
    {
        $this->assertEquals('fair', ClockDrift::getDriftStatus(500));
        $this->assertEquals('poor', ClockDrift::getDriftStatus(501));
    }

    public function testGetDriftStatusNegativeBoundaries(): void
    {
        // Negative values should use absolute value
        $this->assertEquals('excellent', ClockDrift::getDriftStatus(-50));
        $this->assertEquals('good', ClockDrift::getDriftStatus(-51));
        $this->assertEquals('good', ClockDrift::getDriftStatus(-100));
        $this->assertEquals('fair', ClockDrift::getDriftStatus(-101));
        $this->assertEquals('fair', ClockDrift::getDriftStatus(-500));
        $this->assertEquals('poor', ClockDrift::getDriftStatus(-501));
    }

    // ========================================
    // formatDrift() Tests
    // ========================================

    /**
     * @dataProvider formatDriftProvider
     */
    public function testFormatDriftReturnsCorrectFormat(int $driftMs, string $expected): void
    {
        $formatted = ClockDrift::formatDrift($driftMs);

        $this->assertEquals($expected, $formatted);
    }

    public static function formatDriftProvider(): array
    {
        return [
            // Milliseconds (< 1000ms)
            'zero' => [0, '0ms ahead'],
            'small positive' => [50, '50ms ahead'],
            'small negative' => [-50, '50ms behind'],
            '500ms positive' => [500, '500ms ahead'],
            '500ms negative' => [-500, '500ms behind'],
            '999ms positive' => [999, '999ms ahead'],
            '999ms negative' => [-999, '999ms behind'],

            // Seconds (>= 1000ms)
            '1000ms = 1s' => [1000, '1s ahead'],
            '1000ms negative' => [-1000, '1s behind'],
            '1500ms = 1.5s' => [1500, '1.5s ahead'],
            '1500ms negative' => [-1500, '1.5s behind'],
            '2000ms = 2s' => [2000, '2s ahead'],
            '5000ms = 5s' => [5000, '5s ahead'],
            '10000ms = 10s' => [10000, '10s ahead'],
            '60000ms = 60s' => [60000, '60s ahead'],
        ];
    }

    public function testFormatDriftBoundaryAt1000(): void
    {
        // 999ms should show as ms
        $this->assertEquals('999ms ahead', ClockDrift::formatDrift(999));

        // 1000ms should show as seconds
        $this->assertEquals('1s ahead', ClockDrift::formatDrift(1000));
    }

    public function testFormatDriftDirections(): void
    {
        // Positive = ahead
        $this->assertStringContainsString('ahead', ClockDrift::formatDrift(100));

        // Negative = behind
        $this->assertStringContainsString('behind', ClockDrift::formatDrift(-100));

        // Zero = ahead
        $this->assertStringContainsString('ahead', ClockDrift::formatDrift(0));
    }

    public function testFormatDriftSecondsRounding(): void
    {
        // Test rounding to 1 decimal place
        $this->assertEquals('1.1s ahead', ClockDrift::formatDrift(1050));
        $this->assertEquals('1.1s ahead', ClockDrift::formatDrift(1149));
        $this->assertEquals('1.2s ahead', ClockDrift::formatDrift(1150));
        $this->assertEquals('2.5s behind', ClockDrift::formatDrift(-2500));
    }

    // ========================================
    // measureClockDrift() Tests with Real Instance
    // ========================================

    public function testMeasureClockDriftWithEmptyRemoteSystems(): void
    {
        $result = $this->clockDrift->measureClockDrift([]);

        $this->assertTrue($result['success']);
        $this->assertEquals([], $result['hosts']);
        $this->assertEquals('No remote systems', $result['message']);
    }

    // ========================================
    // Mock Helper Tests (testing measurement logic)
    // ========================================

    public function testMockMeasureClockDriftWithSuccessfulResponse(): void
    {
        $currentTime = microtime(true) * 1000;

        $this->mockHelper->setMockResponses(
            ['192.168.1.100' => json_encode(['time_ms' => $currentTime])],
            ['192.168.1.100' => 200],
            ['192.168.1.100' => 0.05]
        );

        $remoteSystems = [
            ['address' => '192.168.1.100', 'hostname' => 'player1']
        ];

        $result = $this->mockHelper->measureClockDrift($remoteSystems, 3, 2);

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['hosts']);

        $host = $result['hosts'][0];
        $this->assertEquals('192.168.1.100', $host['address']);
        $this->assertEquals('player1', $host['hostname']);
        $this->assertTrue($host['online']);
        $this->assertTrue($host['hasPlugin']);
        $this->assertNotNull($host['drift_ms']);
        $this->assertNotNull($host['rtt_ms']);
    }

    public function testMockMeasureClockDriftWithFailedResponse(): void
    {
        $this->mockHelper->setMockResponses(
            ['192.168.1.100' => null],
            ['192.168.1.100' => 0],
            ['192.168.1.100' => 0]
        );

        $remoteSystems = [
            ['address' => '192.168.1.100', 'hostname' => 'player1']
        ];

        $result = $this->mockHelper->measureClockDrift($remoteSystems, 3, 2);

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['hosts']);

        $host = $result['hosts'][0];
        $this->assertEquals('192.168.1.100', $host['address']);
        $this->assertFalse($host['online']);
        $this->assertFalse($host['hasPlugin']);
        $this->assertNull($host['drift_ms']);
        $this->assertNull($host['rtt_ms']);
    }

    public function testMockMeasureClockDriftWithHttpError(): void
    {
        $this->mockHelper->setMockResponses(
            ['192.168.1.100' => 'Not Found'],
            ['192.168.1.100' => 404],
            ['192.168.1.100' => 0.05]
        );

        $remoteSystems = [
            ['address' => '192.168.1.100', 'hostname' => 'player1']
        ];

        $result = $this->mockHelper->measureClockDrift($remoteSystems, 3, 2);

        $host = $result['hosts'][0];
        $this->assertTrue($host['online']); // HTTP code > 0 means online
        $this->assertFalse($host['hasPlugin']); // But no plugin installed
    }

    public function testMockMeasureClockDriftWithInvalidJson(): void
    {
        $this->mockHelper->setMockResponses(
            ['192.168.1.100' => 'invalid json'],
            ['192.168.1.100' => 200],
            ['192.168.1.100' => 0.05]
        );

        $remoteSystems = [
            ['address' => '192.168.1.100', 'hostname' => 'player1']
        ];

        $result = $this->mockHelper->measureClockDrift($remoteSystems, 3, 2);

        // Invalid JSON should result in no entry being added
        $this->assertTrue($result['success']);
        $this->assertCount(0, $result['hosts']);
    }

    public function testMockMeasureClockDriftWithMissingTimeMs(): void
    {
        $this->mockHelper->setMockResponses(
            ['192.168.1.100' => json_encode(['other_field' => 123])],
            ['192.168.1.100' => 200],
            ['192.168.1.100' => 0.05]
        );

        $remoteSystems = [
            ['address' => '192.168.1.100', 'hostname' => 'player1']
        ];

        $result = $this->mockHelper->measureClockDrift($remoteSystems, 3, 2);

        // Missing time_ms should skip the entry
        $this->assertCount(0, $result['hosts']);
    }

    public function testMockMeasureClockDriftWithMultipleHosts(): void
    {
        $currentTime = microtime(true) * 1000;

        $this->mockHelper->setMockResponses(
            [
                '192.168.1.100' => json_encode(['time_ms' => $currentTime + 100]),
                '192.168.1.101' => json_encode(['time_ms' => $currentTime - 50]),
                '192.168.1.102' => null
            ],
            [
                '192.168.1.100' => 200,
                '192.168.1.101' => 200,
                '192.168.1.102' => 0
            ],
            [
                '192.168.1.100' => 0.05,
                '192.168.1.101' => 0.03,
                '192.168.1.102' => 0
            ]
        );

        $remoteSystems = [
            ['address' => '192.168.1.100', 'hostname' => 'player1'],
            ['address' => '192.168.1.101', 'hostname' => 'player2'],
            ['address' => '192.168.1.102', 'hostname' => 'player3']
        ];

        $result = $this->mockHelper->measureClockDrift($remoteSystems, 3, 2);

        $this->assertTrue($result['success']);
        $this->assertCount(3, $result['hosts']);

        // Verify summary statistics
        $this->assertArrayHasKey('summary', $result);
        $this->assertEquals(3, $result['summary']['hostsChecked']);
        $this->assertEquals(2, $result['summary']['hostsWithPlugin']);
        $this->assertNotNull($result['summary']['avgDrift']);
        $this->assertNotNull($result['summary']['maxDrift']);
    }

    public function testMockMeasureClockDriftSummaryWithNoSuccessfulMeasurements(): void
    {
        $this->mockHelper->setMockResponses(
            ['192.168.1.100' => null],
            ['192.168.1.100' => 0],
            ['192.168.1.100' => 0]
        );

        $remoteSystems = [
            ['address' => '192.168.1.100', 'hostname' => 'player1']
        ];

        $result = $this->mockHelper->measureClockDrift($remoteSystems, 3, 2);

        $this->assertNull($result['summary']['avgDrift']);
        $this->assertNull($result['summary']['maxDrift']);
    }

    // ========================================
    // measureSingleHost() Tests with Mocks
    // ========================================

    public function testMockMeasureSingleHostWithSuccess(): void
    {
        $currentTime = microtime(true) * 1000;

        $this->mockHelper->setMockResponses(
            ['192.168.1.100' => json_encode(['time_ms' => $currentTime])],
            ['192.168.1.100' => 200],
            ['192.168.1.100' => 0.05]
        );

        $result = $this->mockHelper->measureSingleHost('192.168.1.100', 'player1');

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('host', $result);
        $this->assertEquals('192.168.1.100', $result['host']['address']);
        $this->assertEquals('player1', $result['host']['hostname']);
    }

    public function testMockMeasureSingleHostUsesAddressAsHostnameWhenEmpty(): void
    {
        $currentTime = microtime(true) * 1000;

        $this->mockHelper->setMockResponses(
            ['192.168.1.100' => json_encode(['time_ms' => $currentTime])],
            ['192.168.1.100' => 200],
            ['192.168.1.100' => 0.05]
        );

        $result = $this->mockHelper->measureSingleHost('192.168.1.100');

        $this->assertEquals('192.168.1.100', $result['host']['hostname']);
    }

    public function testMockMeasureSingleHostWithFailure(): void
    {
        $this->mockHelper->setMockResponses(
            ['192.168.1.100' => 'invalid'],
            ['192.168.1.100' => 200],
            ['192.168.1.100' => 0.05]
        );

        $result = $this->mockHelper->measureSingleHost('192.168.1.100', 'player1');

        $this->assertFalse($result['success']);
        $this->assertEquals('Failed to measure clock drift', $result['error']);
    }

    // ========================================
    // Integration Tests (with localhost)
    // ========================================

    public function testMeasureClockDriftWithLocalhost(): void
    {
        // Quick test with localhost - should fail fast if no plugin
        $remoteSystems = [
            ['address' => '127.0.0.1', 'hostname' => 'localhost']
        ];

        $result = $this->clockDrift->measureClockDrift($remoteSystems, 1, 1);

        // Should always return success even if no hosts respond
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('hosts', $result);
        $this->assertArrayHasKey('summary', $result);
    }

    public function testMeasureSingleHostWithLocalhostTimeout(): void
    {
        // Test with localhost - will either succeed or timeout quickly
        $result = $this->clockDrift->measureSingleHost('127.0.0.1', 'localhost', 1, 1);

        // Result should have expected structure regardless of success/failure
        $this->assertArrayHasKey('success', $result);
        if ($result['success']) {
            $this->assertArrayHasKey('host', $result);
        } else {
            $this->assertArrayHasKey('error', $result);
        }
    }

    // ========================================
    // Edge Cases with Mocks
    // ========================================

    public function testMockMeasureClockDriftWithDuplicateAddresses(): void
    {
        $currentTime = microtime(true) * 1000;

        $this->mockHelper->setMockResponses(
            ['192.168.1.100' => json_encode(['time_ms' => $currentTime])],
            ['192.168.1.100' => 200],
            ['192.168.1.100' => 0.05]
        );

        // Same address twice with different hostnames
        $remoteSystems = [
            ['address' => '192.168.1.100', 'hostname' => 'player1'],
            ['address' => '192.168.1.100', 'hostname' => 'player1-duplicate']
        ];

        $result = $this->mockHelper->measureClockDrift($remoteSystems, 1, 2);

        // Should only have one entry since addresses are deduplicated
        $this->assertCount(1, $result['hosts']);
    }

    public function testMockMeasureClockDriftLargePositiveDrift(): void
    {
        // Remote clock is way ahead
        $currentTime = microtime(true) * 1000;

        $this->mockHelper->setMockResponses(
            ['192.168.1.100' => json_encode(['time_ms' => $currentTime + 60000])], // 1 minute ahead
            ['192.168.1.100' => 200],
            ['192.168.1.100' => 0.05]
        );

        $remoteSystems = [
            ['address' => '192.168.1.100', 'hostname' => 'player1']
        ];

        $result = $this->mockHelper->measureClockDrift($remoteSystems, 1, 2);

        $this->assertGreaterThan(55000, $result['hosts'][0]['drift_ms']);
    }

    public function testMockMeasureClockDriftLargeNegativeDrift(): void
    {
        // Remote clock is way behind
        $currentTime = microtime(true) * 1000;

        $this->mockHelper->setMockResponses(
            ['192.168.1.100' => json_encode(['time_ms' => $currentTime - 60000])], // 1 minute behind
            ['192.168.1.100' => 200],
            ['192.168.1.100' => 0.05]
        );

        $remoteSystems = [
            ['address' => '192.168.1.100', 'hostname' => 'player1']
        ];

        $result = $this->mockHelper->measureClockDrift($remoteSystems, 1, 2);

        $this->assertLessThan(-55000, $result['hosts'][0]['drift_ms']);
    }

    public function testMockMeasureClockDriftWithHighRtt(): void
    {
        $currentTime = microtime(true) * 1000;

        $this->mockHelper->setMockResponses(
            ['192.168.1.100' => json_encode(['time_ms' => $currentTime])],
            ['192.168.1.100' => 200],
            ['192.168.1.100' => 0.5] // 500ms RTT
        );

        $remoteSystems = [
            ['address' => '192.168.1.100', 'hostname' => 'player1']
        ];

        $result = $this->mockHelper->measureClockDrift($remoteSystems, 1, 2);

        $this->assertEquals(500.0, $result['hosts'][0]['rtt_ms']);
    }

    public function testMockMeasureClockDriftRttRounding(): void
    {
        $currentTime = microtime(true) * 1000;

        $this->mockHelper->setMockResponses(
            ['192.168.1.100' => json_encode(['time_ms' => $currentTime])],
            ['192.168.1.100' => 200],
            ['192.168.1.100' => 0.0567] // Should round to 56.7
        );

        $remoteSystems = [
            ['address' => '192.168.1.100', 'hostname' => 'player1']
        ];

        $result = $this->mockHelper->measureClockDrift($remoteSystems, 1, 2);

        $this->assertEquals(56.7, $result['hosts'][0]['rtt_ms']);
    }

    public function testMockMeasureClockDriftDriftRounding(): void
    {
        $currentTime = microtime(true) * 1000;

        $this->mockHelper->setMockResponses(
            ['192.168.1.100' => json_encode(['time_ms' => $currentTime + 123.456])],
            ['192.168.1.100' => 200],
            ['192.168.1.100' => 0.05]
        );

        $remoteSystems = [
            ['address' => '192.168.1.100', 'hostname' => 'player1']
        ];

        $result = $this->mockHelper->measureClockDrift($remoteSystems, 1, 2);

        // Drift should be rounded to integer
        $this->assertIsInt($result['hosts'][0]['drift_ms']);
    }

    // ========================================
    // Summary Statistics Tests
    // ========================================

    public function testMockSummaryAvgDriftCalculation(): void
    {
        $currentTime = microtime(true) * 1000;

        $this->mockHelper->setMockResponses(
            [
                '192.168.1.100' => json_encode(['time_ms' => $currentTime + 100]),
                '192.168.1.101' => json_encode(['time_ms' => $currentTime + 200])
            ],
            [
                '192.168.1.100' => 200,
                '192.168.1.101' => 200
            ],
            [
                '192.168.1.100' => 0.05,
                '192.168.1.101' => 0.05
            ]
        );

        $remoteSystems = [
            ['address' => '192.168.1.100', 'hostname' => 'player1'],
            ['address' => '192.168.1.101', 'hostname' => 'player2']
        ];

        $result = $this->mockHelper->measureClockDrift($remoteSystems, 1, 2);

        // Average should be calculated correctly
        $this->assertArrayHasKey('avgDrift', $result['summary']);
        $this->assertIsInt($result['summary']['avgDrift']);
    }

    public function testMockSummaryMaxDriftUsesAbsoluteValues(): void
    {
        $currentTime = microtime(true) * 1000;

        $this->mockHelper->setMockResponses(
            [
                '192.168.1.100' => json_encode(['time_ms' => $currentTime + 100]),
                '192.168.1.101' => json_encode(['time_ms' => $currentTime - 200]) // -200 should have higher abs
            ],
            [
                '192.168.1.100' => 200,
                '192.168.1.101' => 200
            ],
            [
                '192.168.1.100' => 0.05,
                '192.168.1.101' => 0.05
            ]
        );

        $remoteSystems = [
            ['address' => '192.168.1.100', 'hostname' => 'player1'],
            ['address' => '192.168.1.101', 'hostname' => 'player2']
        ];

        $result = $this->mockHelper->measureClockDrift($remoteSystems, 1, 2);

        // Max should use absolute values
        $this->assertGreaterThan(150, $result['summary']['maxDrift']);
    }

    // ========================================
    // Public Methods Existence Tests
    // ========================================

    public function testPublicMethodsExist(): void
    {
        $reflection = new \ReflectionClass(ClockDrift::class);

        $expectedMethods = [
            'getInstance',
            'measureClockDrift',
            'measureSingleHost',
            'getDriftStatus',
            'formatDrift'
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

    public function testStaticMethodsAreStatic(): void
    {
        $reflection = new \ReflectionClass(ClockDrift::class);

        $staticMethods = ['getInstance', 'getDriftStatus', 'formatDrift'];

        foreach ($staticMethods as $methodName) {
            $method = $reflection->getMethod($methodName);
            $this->assertTrue(
                $method->isStatic(),
                "Method {$methodName} should be static"
            );
        }
    }
}
