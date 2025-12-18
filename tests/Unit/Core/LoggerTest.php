<?php
/**
 * Unit tests for Logger class
 *
 * @package Watcher\Tests\Unit\Core
 */

declare(strict_types=1);

namespace Watcher\Tests\Unit\Core;

use Watcher\Tests\TestCase;
use Watcher\Core\Logger;

class LoggerTest extends TestCase
{
    private Logger $logger;
    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logFile = $this->testTmpDir . '/test.log';
        $this->logger = Logger::getInstance();
        $this->logger->setLogFile($this->logFile);
    }

    public function testGetInstanceReturnsSingleton(): void
    {
        $instance1 = Logger::getInstance();
        $instance2 = Logger::getInstance();

        $this->assertSame($instance1, $instance2);
    }

    public function testResetInstanceClearsInstance(): void
    {
        $instance1 = Logger::getInstance();
        Logger::resetInstance();
        $instance2 = Logger::getInstance();

        $this->assertNotSame($instance1, $instance2);
    }

    public function testSetAndGetLogFile(): void
    {
        $customPath = '/tmp/custom.log';
        $this->logger->setLogFile($customPath);

        $this->assertEquals($customPath, $this->logger->getLogFile());
    }

    public function testSetAndGetDebugEnabled(): void
    {
        $this->assertFalse($this->logger->isDebugEnabled());

        $this->logger->setDebugEnabled(true);
        $this->assertTrue($this->logger->isDebugEnabled());

        $this->logger->setDebugEnabled(false);
        $this->assertFalse($this->logger->isDebugEnabled());
    }

    public function testLogWritesToFile(): void
    {
        $this->logger->log('Test message', 'INFO');

        $this->assertFileExists($this->logFile);
        $content = file_get_contents($this->logFile);
        $this->assertStringContainsString('[INFO] Test message', $content);
    }

    public function testLogIncludesTimestamp(): void
    {
        $this->logger->log('Test message', 'INFO');

        $content = file_get_contents($this->logFile);
        // Check for timestamp format [YYYY-MM-DD HH:MM:SS]
        $this->assertMatchesRegularExpression(
            '/\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]/',
            $content
        );
    }

    public function testInfoLogsWithInfoLevel(): void
    {
        $this->logger->info('Info message');

        $content = file_get_contents($this->logFile);
        $this->assertStringContainsString('[INFO] Info message', $content);
    }

    public function testErrorLogsWithErrorLevel(): void
    {
        $this->logger->error('Error message');

        $content = file_get_contents($this->logFile);
        $this->assertStringContainsString('[ERROR] Error message', $content);
    }

    public function testWarningLogsWithWarningLevel(): void
    {
        $this->logger->warning('Warning message');

        $content = file_get_contents($this->logFile);
        $this->assertStringContainsString('[WARNING] Warning message', $content);
    }

    public function testDebugLogsOnlyWhenEnabled(): void
    {
        // Debug disabled by default
        $this->logger->debug('Debug message 1');
        $this->assertFileDoesNotExist($this->logFile);

        // Enable debug
        $this->logger->setDebugEnabled(true);
        $this->logger->debug('Debug message 2');

        $content = file_get_contents($this->logFile);
        $this->assertStringNotContainsString('Debug message 1', $content);
        $this->assertStringContainsString('[DEBUG] Debug message 2', $content);
    }

    public function testLogToCustomFile(): void
    {
        $customFile = $this->testTmpDir . '/custom.log';
        $this->logger->info('Custom file message', $customFile);

        $this->assertFileExists($customFile);
        $content = file_get_contents($customFile);
        $this->assertStringContainsString('Custom file message', $content);

        // Original log file should not exist
        $this->assertFileDoesNotExist($this->logFile);
    }

    public function testMultipleLogMessagesAppend(): void
    {
        $this->logger->info('Message 1');
        $this->logger->info('Message 2');
        $this->logger->info('Message 3');

        $content = file_get_contents($this->logFile);
        $this->assertStringContainsString('Message 1', $content);
        $this->assertStringContainsString('Message 2', $content);
        $this->assertStringContainsString('Message 3', $content);

        // Check messages are on separate lines
        $lines = array_filter(explode("\n", $content));
        $this->assertCount(3, $lines);
    }

    public function testLogWithEmptyMessage(): void
    {
        $this->logger->info('');

        $content = file_get_contents($this->logFile);
        $this->assertStringContainsString('[INFO] ', $content);
    }

    public function testLogWithSpecialCharacters(): void
    {
        $specialMessage = "Test with special chars: <script>alert('xss')</script> & \"quotes\"";
        $this->logger->info($specialMessage);

        $content = file_get_contents($this->logFile);
        $this->assertStringContainsString($specialMessage, $content);
    }

    public function testLogWithMultilineMessage(): void
    {
        $multilineMessage = "Line 1\nLine 2\nLine 3";
        $this->logger->info($multilineMessage);

        $content = file_get_contents($this->logFile);
        $this->assertStringContainsString($multilineMessage, $content);
    }

    public function testLogCreatesDirectoryIfNeeded(): void
    {
        $nestedPath = $this->testTmpDir . '/nested/deep/test.log';
        // Ensure parent directory exists first (Logger doesn't create dirs)
        mkdir(dirname($nestedPath), 0755, true);

        $this->logger->info('Nested message', $nestedPath);

        $this->assertFileExists($nestedPath);
    }

    public function testLogWithUnicodeCharacters(): void
    {
        $unicodeMessage = "Unicode test: 日本語 emoji 🎄🎅 symbols ±×÷";
        $this->logger->info($unicodeMessage);

        $content = file_get_contents($this->logFile);
        $this->assertStringContainsString($unicodeMessage, $content);
    }

    public function testConcurrentLogWrites(): void
    {
        // Simulate concurrent writes by rapidly logging multiple messages
        $messageCount = 50;
        for ($i = 0; $i < $messageCount; $i++) {
            $this->logger->info("Concurrent message {$i}");
        }

        $content = file_get_contents($this->logFile);
        $lines = array_filter(explode("\n", $content));

        $this->assertCount($messageCount, $lines);

        // Verify all messages are present and complete
        for ($i = 0; $i < $messageCount; $i++) {
            $this->assertStringContainsString("Concurrent message {$i}", $content);
        }
    }

    public function testLogLevelCaseSensitivity(): void
    {
        $this->logger->log('Test message', 'WARNING');
        $content = file_get_contents($this->logFile);

        // Level should be uppercase as provided
        $this->assertStringContainsString('[WARNING]', $content);
    }

    public function testDebugLevelFiltering(): void
    {
        $this->logger->setDebugEnabled(false);

        // These should log
        $this->logger->info('Info logged');
        $this->logger->warning('Warning logged');
        $this->logger->error('Error logged');

        // This should not log
        $this->logger->debug('Debug not logged');

        $content = file_get_contents($this->logFile);
        $this->assertStringContainsString('Info logged', $content);
        $this->assertStringContainsString('Warning logged', $content);
        $this->assertStringContainsString('Error logged', $content);
        $this->assertStringNotContainsString('Debug not logged', $content);
    }

    public function testLogPreservesFileContents(): void
    {
        // Pre-populate the log file
        file_put_contents($this->logFile, "Existing content\n");

        $this->logger->info('New message');

        $content = file_get_contents($this->logFile);
        $this->assertStringContainsString('Existing content', $content);
        $this->assertStringContainsString('New message', $content);
    }
}
