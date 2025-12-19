# FPP Plugin Watcher Test Suite

Comprehensive test suite for the fpp-plugin-watcher plugin.

## Structure

```
tests/
├── bootstrap.php           # Test environment setup
├── TestCase.php           # Base test case with utilities
├── phpunit.xml            # PHPUnit configuration
├── Unit/                  # Unit tests (isolated, fast)
│   ├── Core/              # Logger, FileManager, Settings
│   ├── Http/              # ApiClient, CurlMultiHandler
│   ├── Metrics/           # RollupProcessor, MetricsStorage, PingCollector, NetworkQualityCollector, SystemMetrics, EfuseCollector, MultiSyncPingCollector
│   ├── Controllers/       # FalconController, EfuseHardware, RemoteControl, NetworkAdapter
│   ├── MultiSync/         # SyncStatus, Comparator, ClockDrift
│   ├── UI/                # ViewHelpers
│   └── Utils/             # UpdateChecker, MqttEventLogger
├── Integration/           # Integration tests (require FPP)
│   ├── MetricsPipelineTest.php  # Full metrics workflow (8 tests)
│   └── ApiEndpointTest.php      # API endpoint validation (54 tests)
├── Fixtures/              # Test data and fixtures
│   ├── data/              # Sample JSON data
│   └── test_constants.php # Mock FPP constants
└── Mocks/                 # Mock objects (future use)
```

## Running Tests

### Prerequisites

**Recommended:** Use the included `./phpunit` wrapper script (PHPUnit 11.5.46 with PCOV enabled).

This project includes:
- `phpunit` - Wrapper script with PCOV enabled automatically
- `phpunit-11.phar` - PHPUnit 11.5.46 PHAR file

No additional installation needed! Just run `./phpunit` from the project root.

**Note:** If you download a different PHPUnit version, ensure PCOV is enabled for coverage:
```bash
php -d pcov.enabled=1 /path/to/phpunit --coverage-text
```

### Run All Tests

```bash
cd /home/fpp/media/plugins/fpp-plugin-watcher

# Using the PHPUnit wrapper (recommended - includes PCOV coverage)
./phpunit
```

### Run Specific Test Suites

```bash
# Unit tests only (fast, no FPP required)
./phpunit --testsuite Unit

# Integration tests only (requires running FPP)
./phpunit --testsuite Integration
```

### Run Specific Test Files

```bash
# Single test file
./phpunit tests/Unit/Core/LoggerTest.php

# Single test method
./phpunit --filter testGetInstanceReturnsSingleton tests/Unit/Core/LoggerTest.php
```

### Generate Coverage Report

```bash
# HTML report (recommended for detailed analysis)
./phpunit --coverage-html tests/coverage-html

# Text report (quick summary in terminal)
./phpunit --coverage-text

# Generate both
./phpunit --coverage-html tests/coverage-html --coverage-text

# View HTML report
xdg-open tests/coverage-html/index.html
```

**Note:** The `./phpunit` wrapper automatically enables PCOV for accurate coverage reporting. If you're using a different PHPUnit installation, ensure PCOV is enabled:
```bash
php -d pcov.enabled=1 /path/to/phpunit --coverage-html tests/coverage-html
```

## Test Categories

### Unit Tests

Unit tests are isolated and don't require FPP to be running. They test individual classes in isolation.

**What they test:**
- Class structure and method signatures
- Return types and data validation
- Algorithm correctness (jitter calculation, quality ratings)
- File operations with mock data
- State management

**Run time:** ~30 seconds for all 1205 unit tests

### Integration Tests

Integration tests require FPP to be running locally. They test complete workflows and API endpoints.

**What they test:**
- Complete metrics pipeline (write -> rollup -> read)
- API endpoint responses and structure
- File locking and concurrent access
- Real HTTP requests to local FPP

**Run time:** Slower (depends on network and FPP response)

## Writing New Tests

### Test File Naming

- Test files: `{ClassName}Test.php`
- Test methods: `test{WhatItTests}()`

### Base TestCase Utilities

The `Watcher\Tests\TestCase` class provides:

```php
// Temp file management
$path = $this->createTempFile('test.log', 'content');
$dir = $this->createTempDir('subdir');

// Fixtures
$data = $this->loadJsonFixture('data/sample_metrics.json');

// Assertions
$this->assertFileContainsString('expected', $file);
$this->assertJsonFileEquals(['key' => 'value'], $file);
$this->assertWithinPercent(100, $actual, 5); // 5% tolerance

// Skip conditions
$this->skipIfNoFpp();      // Skip if FPP not installed
$this->skipIfNoNetwork();  // Skip if no network
```

### Example Unit Test

```php
<?php
namespace Watcher\Tests\Unit\Core;

use Watcher\Tests\TestCase;
use Watcher\Core\Logger;

class LoggerTest extends TestCase
{
    private Logger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = Logger::getInstance();
        $this->logger->setLogFile($this->testTmpDir . '/test.log');
    }

    public function testInfoLogsMessage(): void
    {
        $this->logger->info('Test message');

        $this->assertFileContainsString('Test message', $this->logger->getLogFile());
    }
}
```

### Example Integration Test

```php
<?php
namespace Watcher\Tests\Integration;

use Watcher\Tests\TestCase;
use Watcher\Http\ApiClient;

class ApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Skip if FPP not running
        $socket = @fsockopen('127.0.0.1', 80, $errno, $errstr, 1);
        if (!$socket) {
            $this->markTestSkipped('FPP not running');
        }
        fclose($socket);
    }

    public function testVersionEndpoint(): void
    {
        $client = ApiClient::getInstance();
        $result = $client->get('http://127.0.0.1/api/plugin/fpp-plugin-watcher/version');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('version', $result);
    }
}
```

## Continuous Integration

Tests are designed to run in CI environments:

1. **Unit tests**: Run on every commit (no external dependencies)
2. **Integration tests**: Run on hardware or with FPP mocks

Example GitHub Actions workflow:

```yaml
name: Tests
on: [push, pull_request]

jobs:
  unit-tests:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          extensions: pcov
          coverage: pcov
      - name: Run unit tests
        run: ./phpunit --testsuite Unit
      - name: Run tests with coverage
        run: ./phpunit --testsuite Unit --coverage-text
```

## Fixtures

### Sample Data Files

- `data/sample_metrics.json` - Sample ping, eFuse, and Falcon controller data
- `data/fppd_status.json` - Mock FPP daemon status response

### Adding New Fixtures

1. Create JSON file in `tests/Fixtures/data/`
2. Load in tests: `$data = $this->loadJsonFixture('data/newfile.json');`

## Coverage Goals

| Component | Target Coverage |
|-----------|----------------|
| Core Classes | 90%+ |
| Metrics Classes | 85%+ |
| Controllers | 70%+ (hardware dependent) |
| Utils | 80%+ |
| HTTP/API | 75%+ |
| UI | 90%+ |
| MultiSync | 85%+ |

## Code Coverage Analysis

**Last Updated:** 2025-12-19
**PHPUnit Version:** 11.5.46 with PCOV 1.0.11
**Total Tests:** 1,318 tests (1,254 unit + 64 integration, including data provider expansions)
**Total Assertions:** 4,939

### Current Coverage Summary

| Metric | Current | Total | Percentage |
|--------|---------|-------|------------|
| **Classes** | 2 | 23 | 8.70% |
| **Methods** | 183 | 356 | 51.40% |
| **Lines** | 2,972 | 5,844 | **50.86%** |

**Overall Grade:** C+ (Target: ~75% average)

### Test File Statistics

| Test File | Test Methods | Focus Area |
|-----------|--------------|------------|
| RollupProcessorTest | 103 | Data aggregation, file locking, rotation |
| FalconControllerTest | 82 | Model mapping, XML parsing, validation |
| UpdateCheckerTest | 77 | Version parsing, cache behavior |
| EfuseHardwareTest | 66 | Port validation, current calculation |
| EfuseCollectorTest | 92 | Tier selection, bucket aggregation, rollup processing |
| MultiSyncPingCollectorTest | 60 | Multi-host jitter, state management |
| NetworkQualityCollectorTest | 58 | Jitter calculations, quality ratings |
| ApiEndpointTest | 54 | Integration API validation |
| SystemMetricsTest | 52 | RRD parsing, metric transformation |
| ViewHelpersTest | 48 | HTML escaping, CSS rendering |
| PingCollectorTest | 42 | Ping data collection, formatting |
| FileManagerTest | 38 | File operations, JSON handling |
| ClockDriftTest | 34 | Time synchronization, drift status |
| NetworkAdapterTest | 32 | Adapter validation, security |
| RemoteControlTest | 27 | Status extraction, bulk operations |
| SettingsTest | 23 | Configuration management |
| MetricsStorageTest | 22 | Storage operations |
| LoggerTest | 21 | Logging functionality |
| SyncStatusTest | 19 | Sync state management |
| CurlMultiHandlerTest | 18 | Concurrent HTTP requests |
| ComparatorTest | 58 | Data comparison, drift detection, sync issues |
| ApiClientTest | 12 | HTTP client operations |
| MqttEventLoggerTest | 76 | MQTT event handling, parsing, stats |
| MetricsPipelineTest | 8 | End-to-end metrics flow |

**Total:** 1,041 test methods + 35 data providers = 1,318 test executions

### Coverage by Component

#### Core Classes (Target: 90%+)

| Class | Methods | Lines | Status |
|-------|---------|-------|--------|
| **Logger** | 84.62% (11/13) | **94.44%** (34/36) | ✅ Excellent |
| **FileManager** | 33.33% (4/12) | **82.99%** (122/147) | ✅ Good |
| **Settings** | 76.92% (10/13) | **80.33%** (49/61) | ✅ Good |
| **Average** | - | **84.02%** (205/244) | 🟡 Close to target |

#### HTTP Classes (Target: 75%+)

| Class | Methods | Lines | Status |
|-------|---------|-------|--------|
| **CurlMultiHandler** | 100.00% (6/6) | **100.00%** (43/43) | ✅ Perfect |
| **ApiClient** | 57.14% (4/7) | **85.42%** (41/48) | ✅ Excellent |
| **Average** | - | **92.31%** (84/91) | ✅ Exceeds target! |

#### Metrics Classes (Target: 85%+)

| Class | Methods | Lines | Status |
|-------|---------|-------|--------|
| **RollupProcessor** | 58.82% (10/17) | **91.70%** (254/277) | ✅ Excellent |
| **MetricsStorage** | 25.00% (1/4) | **90.91%** (80/88) | ✅ Excellent |
| **PingCollector** | 83.33% (15/18) | **84.54%** (82/97) | ✅ Good |
| **SystemMetrics** | 78.95% (15/19) | **75.91%** (334/440) | 🟡 Close |
| **NetworkQualityCollector** | 59.26% (16/27) | **73.91%** (340/460) | 🟡 Close |
| **MultiSyncPingCollector** | 73.91% (17/23) | **73.10%** (125/171) | 🟡 Close |
| **EfuseCollector** | 63.16% (12/19) | **46.80%** (161/344) | ⚠️ Needs work |
| **Average** | - | **73.31%** (1,376/1,877) | 🟡 Improving |

#### Controllers (Target: 70%+)

| Class | Methods | Lines | Gap to Goal |
|-------|---------|-------|-------------|
| **EfuseHardware** | 22.22% (6/27) | 41.68% (233/559) | -28% |
| **RemoteControl** | 26.32% (5/19) | 36.40% (170/467) | -34% |
| **FalconController** | 25.00% (14/56) | 22.39% (182/813) | -48% |
| **NetworkAdapter** | 44.44% (4/9) | 12.96% (7/54) | -57% |
| **EfuseOutputConfig** | 20.00% (2/10) | 11.34% (22/194) | -59% |
| **Average** | - | **29.42%** (614/2,087) | ⚠️ Hardware-dependent |

**Note:** Controller coverage is limited because these classes make direct hardware calls (I2C, GPIO, exec) that cannot be easily unit tested without the actual hardware. Consider integration tests on real FPP devices.

#### MultiSync Classes (Target: 85%+)

| Class | Methods | Lines | Status |
|-------|---------|-------|--------|
| **SyncStatus** | 33.33% (5/15) | **87.10%** (81/93) | ✅ Good |
| **ClockDrift** | 66.67% (4/6) | **85.05%** (91/107) | ✅ Good |
| **Comparator** | 33.33% (3/9) | 30.55% (84/275) | ⚠️ Needs work |
| **Average** | - | **53.89%** (256/475) | 🟡 Mixed |

#### Utils Classes (Target: 80%+)

| Class | Methods | Lines | Status |
|-------|---------|-------|--------|
| **UpdateChecker** | 58.33% (7/12) | **89.08%** (106/119) | ✅ Exceeds target |
| **MqttEventLogger** | 25.00% (2/8) | **93.24%** (193/207) | ✅ Exceeds target |
| **Average** | - | **91.72%** (299/326) | ✅ Excellent! |

#### UI Classes (Target: 90%+)

| Class | Methods | Lines | Status |
|-------|---------|-------|--------|
| **ViewHelpers** | 100.00% (7/7) | **100.00%** (63/63) | ✅ Perfect |

### Progress vs Goals

| Component | Target | Actual | Variance | Status |
|-----------|--------|--------|----------|--------|
| Core Classes | 90%+ | **84.02%** | -5.98% | 🟡 Close |
| HTTP Classes | 75%+ | **92.31%** | +17.31% | ✅ Exceeds! |
| Metrics Classes | 85%+ | **73.31%** | -11.69% | 🟡 Improving |
| Controllers | 70%+ | **29.42%** | -40.58% | ⚠️ Hardware limits |
| MultiSync | 85%+ | **53.89%** | -31.11% | 🟡 Mixed |
| Utils | 80%+ | **91.72%** | +11.72% | ✅ Exceeds! |
| UI | 90%+ | **100.00%** | +10.00% | ✅ Perfect! |

### Action Plan


### Files Created/Updated

- `phpunit` - Wrapper script (use this for all test runs)
- `phpunit-11.phar` - PHPUnit 11.5.46
- `tests/coverage-html/` - HTML coverage reports (generated)

### Viewing Coverage Reports

```bash
# Generate and view HTML report
./phpunit --coverage-html tests/coverage-html
xdg-open tests/coverage-html/index.html

# Quick text summary
./phpunit --coverage-text | less

# Coverage for specific component
./phpunit --testsuite Unit --filter Core --coverage-text
```

### Coverage Best Practices

1. **Run coverage before committing**
   ```bash
   ./phpunit --coverage-text --testsuite Unit
   ```

2. **Focus on line coverage** - Methods can be misleading if they have complex logic

3. **Test behavior, not implementation** - Don't just aim for 100%, ensure tests are meaningful

4. **Mock external dependencies** - Hardware, network calls, file system (when appropriate)

5. **Use fixtures** - Create realistic test data in `tests/Fixtures/data/`

6. **Test edge cases** - Empty data, null values, errors, boundary conditions

7. **Don't skip hard parts** - Hardware controllers are hard to test, but that's where bugs hide

## Troubleshooting

### Tests Fail to Run

```bash
# Check PHP syntax
php -l tests/bootstrap.php

# Check autoloader
php -r "require 'classes/autoload.php'; echo 'OK';"
```

### Permission Errors

```bash
# Fix ownership
sudo chown -R fpp:fpp /home/fpp/media/plugins/fpp-plugin-watcher/tests
```

### Integration Tests Fail

1. Verify FPP is running: `systemctl status fppd`
2. Check API is responding: `curl http://127.0.0.1/api/fppd/status`
3. Check plugin is installed: `ls /home/fpp/media/plugins/fpp-plugin-watcher`

### Coverage Reports Show 0%

If coverage reports show 0% despite tests passing:

1. **Use the wrapper script:** `./phpunit` instead of system phpunit
2. **Check PCOV is installed:**
   ```bash
   php -m | grep pcov
   ```
3. **Enable PCOV explicitly:**
   ```bash
   php -d pcov.enabled=1 ./phpunit-11.phar --coverage-text
   ```
4. **Verify coverage driver:**
   ```bash
   ./phpunit --version
   # Should show: Runtime: PHP 8.x.x with PCOV x.x.x
   ```
5. **Check phpunit.xml has source configuration:**
   ```xml
   <source>
       <include>
           <directory>classes</directory>
           <directory>lib</directory>
       </include>
   </source>
   ```

### Coverage Not Matching Reality

If coverage seems incorrect (e.g., Logger shows 0% but tests are calling it):

- **Clear coverage cache:** `rm -rf .phpunit.cache tests/coverage-html`
- **Regenerate:** `./phpunit --coverage-html tests/coverage-html`
- **Verify tests execute production code:** Add debug output to confirm tests run real classes
