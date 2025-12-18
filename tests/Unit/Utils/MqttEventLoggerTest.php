<?php
/**
 * Unit tests for MqttEventLogger class
 *
 * @package Watcher\Tests\Unit\Utils
 */

declare(strict_types=1);

namespace Watcher\Tests\Unit\Utils;

use Watcher\Tests\TestCase;
use Watcher\Utils\MqttEventLogger;

class MqttEventLoggerTest extends TestCase
{
    private MqttEventLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = MqttEventLogger::getInstance();
    }

    // =========================================================================
    // Singleton Tests
    // =========================================================================

    public function testGetInstanceReturnsSingleton(): void
    {
        $instance1 = MqttEventLogger::getInstance();
        $instance2 = MqttEventLogger::getInstance();

        $this->assertSame($instance1, $instance2);
    }

    // =========================================================================
    // Event Type Constants Tests
    // =========================================================================

    public function testEventTypeConstants(): void
    {
        $this->assertEquals('ss', MqttEventLogger::EVENT_SEQ_START);
        $this->assertEquals('se', MqttEventLogger::EVENT_SEQ_STOP);
        $this->assertEquals('ps', MqttEventLogger::EVENT_PL_START);
        $this->assertEquals('pe', MqttEventLogger::EVENT_PL_STOP);
        $this->assertEquals('st', MqttEventLogger::EVENT_STATUS);
        $this->assertEquals('ms', MqttEventLogger::EVENT_MEDIA_START);
        $this->assertEquals('me', MqttEventLogger::EVENT_MEDIA_STOP);
        $this->assertEquals('wn', MqttEventLogger::EVENT_WARNING);
    }

    // =========================================================================
    // Event Label Tests
    // =========================================================================

    public function testGetEventLabelForSequenceEvents(): void
    {
        $this->assertEquals('Sequence Start', $this->logger->getEventLabel('ss'));
        $this->assertEquals('Sequence Stop', $this->logger->getEventLabel('se'));
    }

    public function testGetEventLabelForPlaylistEvents(): void
    {
        $this->assertEquals('Playlist Start', $this->logger->getEventLabel('ps'));
        $this->assertEquals('Playlist Stop', $this->logger->getEventLabel('pe'));
    }

    public function testGetEventLabelForMediaEvents(): void
    {
        $this->assertEquals('Media Start', $this->logger->getEventLabel('ms'));
        $this->assertEquals('Media Stop', $this->logger->getEventLabel('me'));
    }

    public function testGetEventLabelForOtherEvents(): void
    {
        $this->assertEquals('Status', $this->logger->getEventLabel('st'));
        $this->assertEquals('Warning', $this->logger->getEventLabel('wn'));
    }

    public function testGetEventLabelForUnknownEvent(): void
    {
        $result = $this->logger->getEventLabel('unknown');

        // Should return something reasonable for unknown events
        $this->assertIsString($result);
    }

    // =========================================================================
    // Public Method Existence Tests
    // =========================================================================

    public function testPublicMethodsExist(): void
    {
        $reflection = new \ReflectionClass(MqttEventLogger::class);

        $expectedMethods = [
            'getInstance',
            'getEventLabel',
            'writeEvent',
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
    // Write Event Tests
    // =========================================================================

    public function testWriteEventMethodSignature(): void
    {
        $reflection = new \ReflectionMethod($this->logger, 'writeEvent');
        $params = $reflection->getParameters();

        $this->assertGreaterThanOrEqual(3, count($params));

        $expectedParams = ['hostname', 'eventType', 'data'];
        foreach ($expectedParams as $i => $name) {
            $this->assertEquals($name, $params[$i]->getName());
        }
    }

    public function testWriteEventReturnsBool(): void
    {
        // This may fail without proper setup, but should return bool
        $result = $this->logger->writeEvent('testhost', 'ss', 'test.fseq');

        $this->assertIsBool($result);
    }

    // =========================================================================
    // All Event Types Map Correctly
    // =========================================================================

    public function testAllEventTypesHaveLabels(): void
    {
        $eventTypes = [
            MqttEventLogger::EVENT_SEQ_START,
            MqttEventLogger::EVENT_SEQ_STOP,
            MqttEventLogger::EVENT_PL_START,
            MqttEventLogger::EVENT_PL_STOP,
            MqttEventLogger::EVENT_STATUS,
            MqttEventLogger::EVENT_MEDIA_START,
            MqttEventLogger::EVENT_MEDIA_STOP,
            MqttEventLogger::EVENT_WARNING,
        ];

        foreach ($eventTypes as $type) {
            $label = $this->logger->getEventLabel($type);

            $this->assertIsString($label);
            $this->assertNotEmpty($label);
            $this->assertStringNotContainsString('Unknown', $label);
        }
    }
}
