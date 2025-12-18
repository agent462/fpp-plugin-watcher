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
│   ├── Metrics/           # RollupProcessor, MetricsStorage
│   ├── Controllers/       # FalconController, EfuseHardware
│   ├── MultiSync/         # SyncStatus, Comparator
│   └── Utils/             # UpdateChecker, MqttEventLogger
├── Integration/           # Integration tests (require FPP)
│   ├── MetricsPipelineTest.php  # Full metrics workflow
│   └── ApiEndpointTest.php      # API endpoint validation
├── Fixtures/              # Test data and fixtures
│   ├── data/              # Sample JSON data
│   └── test_constants.php # Mock FPP constants
└── Mocks/                 # Mock objects (future use)
```

## Running Tests

### Prerequisites

1. Install PHPUnit (if not already installed):
```bash
composer require --dev phpunit/phpunit
```

2. Or download PHPUnit PHAR:
```bash
wget https://phar.phpunit.de/phpunit-10.phar
chmod +x phpunit-10.phar
```

### Run All Tests

```bash
cd /home/fpp/media/plugins/fpp-plugin-watcher

# Using Composer
./vendor/bin/phpunit

# Using PHAR
php phpunit-10.phar
```

### Run Specific Test Suites

```bash
# Unit tests only (fast, no FPP required)
./vendor/bin/phpunit --testsuite Unit

# Integration tests only (requires running FPP)
./vendor/bin/phpunit --testsuite Integration
```

### Run Specific Test Files

```bash
# Single test file
./vendor/bin/phpunit tests/Unit/Core/LoggerTest.php

# Single test method
./vendor/bin/phpunit --filter testGetInstanceReturnsSingleton tests/Unit/Core/LoggerTest.php
```

### Generate Coverage Report

```bash
# Requires Xdebug or PCOV
./vendor/bin/phpunit --coverage-html tests/coverage-html

# View report
xdg-open tests/coverage-html/index.html
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

**Run time:** Fast (< 1 second for all unit tests)

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
          php-version: '8.1'
      - run: composer install
      - run: ./vendor/bin/phpunit --testsuite Unit
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
| API Endpoints | 75%+ |

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
