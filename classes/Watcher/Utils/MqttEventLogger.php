<?php
declare(strict_types=1);

namespace Watcher\Utils;

use Watcher\Core\Logger;
use Watcher\Core\FileManager;

/**
 * MQTT Events Logger
 *
 * Functions for reading, writing, and analyzing MQTT event data.
 * Events are stored in a compact JSON-lines format for space efficiency.
 */
class MqttEventLogger
{
    // Event type constants (short keys for compact storage)
    public const EVENT_SEQ_START = 'ss';
    public const EVENT_SEQ_STOP = 'se';
    public const EVENT_PL_START = 'ps';
    public const EVENT_PL_STOP = 'pe';
    public const EVENT_STATUS = 'st';
    public const EVENT_MEDIA_START = 'ms';
    public const EVENT_MEDIA_STOP = 'me';
    public const EVENT_WARNING = 'wn';

    private static ?self $instance = null;
    private Logger $logger;
    private FileManager $fileManager;
    private string $eventsFile;

    /** @var array<string, bool> Track ownership verification per file */
    private array $ownershipVerified = [];

    private function __construct()
    {
        $this->logger = Logger::getInstance();
        $this->fileManager = FileManager::getInstance();
        $this->eventsFile = defined('WATCHERMQTTEVENTSFILE')
            ? WATCHERMQTTEVENTSFILE
            : '/home/fpp/media/logs/fpp-plugin-watcher-mqtt-events.log';
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Map short event codes to human-readable labels
     */
    public function getEventLabel(string $eventType): string
    {
        $labels = [
            self::EVENT_SEQ_START => 'Sequence Start',
            self::EVENT_SEQ_STOP => 'Sequence Stop',
            self::EVENT_PL_START => 'Playlist Start',
            self::EVENT_PL_STOP => 'Playlist Stop',
            self::EVENT_STATUS => 'Status',
            self::EVENT_MEDIA_START => 'Media Start',
            self::EVENT_MEDIA_STOP => 'Media Stop',
            self::EVENT_WARNING => 'Warning'
        ];
        return $labels[$eventType] ?? $eventType;
    }

    /**
     * Write an MQTT event to the log file
     *
     * @param string $hostname Hostname from MQTT topic
     * @param string $eventType Event type (use constants)
     * @param string $data Event data (sequence name, playlist name, status)
     * @param int|null $duration Duration in seconds (for stop events)
     * @return bool Success
     */
    public function writeEvent(string $hostname, string $eventType, string $data, ?int $duration = null): bool
    {
        $entry = [
            't' => time(),
            'h' => $hostname,
            'e' => $eventType,
            'd' => $data
        ];

        // Add duration for stop events
        if ($duration !== null && $duration > 0) {
            $entry['dur'] = $duration;
        }

        $fp = @fopen($this->eventsFile, 'a');
        if (!$fp) {
            $this->logger->error("Unable to open MQTT events file for writing");
            return false;
        }

        if (flock($fp, LOCK_EX)) {
            $timestamp = date('Y-m-d H:i:s', $entry['t']);
            $jsonData = json_encode($entry);
            fwrite($fp, "[{$timestamp}] {$jsonData}\n");
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);

        // Check ownership once per session
        if (!isset($this->ownershipVerified[$this->eventsFile])) {
            $this->fileManager->ensureFppOwnership($this->eventsFile);
            $this->ownershipVerified[$this->eventsFile] = true;
        }

        return true;
    }

    /**
     * Read MQTT events from the log file
     *
     * @param int $hoursBack Number of hours to look back (0 = all)
     * @param string|null $hostname Filter by hostname (null = all)
     * @param string|null $eventType Filter by event type (null = all)
     * @return array Result with success, count, and data
     */
    public function getEvents(int $hoursBack = 24, ?string $hostname = null, ?string $eventType = null): array
    {
        if (!file_exists($this->eventsFile)) {
            return [
                'success' => true,
                'count' => 0,
                'data' => [],
                'period' => ['hours' => $hoursBack]
            ];
        }

        $cutoffTime = $hoursBack > 0 ? time() - ($hoursBack * 3600) : 0;
        $events = [];

        $fp = fopen($this->eventsFile, 'r');
        if (!$fp) {
            return [
                'success' => false,
                'error' => 'Unable to read events file',
                'data' => []
            ];
        }

        if (flock($fp, LOCK_SH)) {
            while (($line = fgets($fp)) !== false) {
                // Parse log format: [timestamp] {json}
                if (preg_match('/\[.*?\]\s+(.+)$/', $line, $matches)) {
                    $jsonData = trim($matches[1]);
                    $entry = json_decode($jsonData, true);

                    if ($entry && isset($entry['t'])) {
                        // Time filter
                        if ($cutoffTime > 0 && $entry['t'] < $cutoffTime) {
                            continue;
                        }
                        // Hostname filter
                        if ($hostname !== null && ($entry['h'] ?? '') !== $hostname) {
                            continue;
                        }
                        // Event type filter
                        if ($eventType !== null && ($entry['e'] ?? '') !== $eventType) {
                            continue;
                        }

                        // Expand to full format for API response
                        $eventData = [
                            'timestamp' => $entry['t'],
                            'datetime' => date('Y-m-d H:i:s', $entry['t']),
                            'hostname' => $entry['h'] ?? 'unknown',
                            'eventType' => $entry['e'] ?? 'unknown',
                            'eventLabel' => $this->getEventLabel($entry['e'] ?? ''),
                            'data' => $entry['d'] ?? ''
                        ];

                        // Include duration if present
                        if (isset($entry['dur'])) {
                            $eventData['duration'] = $entry['dur'];
                        }

                        $events[] = $eventData;
                    }
                }
            }
            flock($fp, LOCK_UN);
        }
        fclose($fp);

        // Sort by timestamp descending (most recent first)
        usort($events, function($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });

        return [
            'success' => true,
            'count' => count($events),
            'data' => $events,
            'period' => ['hours' => $hoursBack]
        ];
    }

    /**
     * Get aggregated statistics from MQTT events
     *
     * @param int $hoursBack Number of hours to look back
     * @return array Statistics array
     */
    public function getEventStats(int $hoursBack = 24): array
    {
        $result = $this->getEvents($hoursBack);

        if (!$result['success']) {
            return $result;
        }

        $events = $result['data'];
        $stats = [
            'totalEvents' => count($events),
            'uniqueHosts' => [],
            'eventsByType' => [],
            'eventsByHost' => [],
            'sequencesPlayed' => [],
            'playlistsStarted' => [],
            'mediaPlayed' => [],
            'warnings' => [],
            'totalRuntime' => 0,
            'hourlyDistribution' => []
        ];

        foreach ($events as $event) {
            // Track unique hosts
            $host = $event['hostname'];
            if (!in_array($host, $stats['uniqueHosts'])) {
                $stats['uniqueHosts'][] = $host;
            }

            // Count by event type
            $type = $event['eventType'];
            if (!isset($stats['eventsByType'][$type])) {
                $stats['eventsByType'][$type] = 0;
            }
            $stats['eventsByType'][$type]++;

            // Count by host
            if (!isset($stats['eventsByHost'][$host])) {
                $stats['eventsByHost'][$host] = 0;
            }
            $stats['eventsByHost'][$host]++;

            // Track sequences
            if ($type === self::EVENT_SEQ_START && !empty($event['data'])) {
                if (!isset($stats['sequencesPlayed'][$event['data']])) {
                    $stats['sequencesPlayed'][$event['data']] = 0;
                }
                $stats['sequencesPlayed'][$event['data']]++;
            }

            // Track playlists
            if ($type === self::EVENT_PL_START && !empty($event['data'])) {
                if (!isset($stats['playlistsStarted'][$event['data']])) {
                    $stats['playlistsStarted'][$event['data']] = 0;
                }
                $stats['playlistsStarted'][$event['data']]++;
            }

            // Track media (songs)
            if ($type === self::EVENT_MEDIA_START && !empty($event['data'])) {
                if (!isset($stats['mediaPlayed'][$event['data']])) {
                    $stats['mediaPlayed'][$event['data']] = 0;
                }
                $stats['mediaPlayed'][$event['data']]++;
            }

            // Track warnings (store recent ones)
            if ($type === self::EVENT_WARNING && !empty($event['data'])) {
                $stats['warnings'][] = [
                    'time' => $event['datetime'],
                    'host' => $event['hostname'],
                    'message' => $event['data']
                ];
            }

            // Track total runtime from sequence durations
            if (isset($event['duration']) && $event['duration'] > 0) {
                $stats['totalRuntime'] += $event['duration'];
            }

            // Hourly distribution
            $hour = date('Y-m-d H:00', $event['timestamp']);
            if (!isset($stats['hourlyDistribution'][$hour])) {
                $stats['hourlyDistribution'][$hour] = 0;
            }
            $stats['hourlyDistribution'][$hour]++;
        }

        // Sort hourly distribution by time
        ksort($stats['hourlyDistribution']);

        // Convert hourly distribution to array format for charts
        $hourlyData = [];
        foreach ($stats['hourlyDistribution'] as $hour => $count) {
            $hourlyData[] = [
                'hour' => $hour,
                'timestamp' => strtotime($hour),
                'count' => $count
            ];
        }
        $stats['hourlyDistribution'] = $hourlyData;

        // Sort sequences, playlists, media by count (descending)
        arsort($stats['sequencesPlayed']);
        arsort($stats['playlistsStarted']);
        arsort($stats['mediaPlayed']);
        arsort($stats['eventsByHost']);

        return [
            'success' => true,
            'period' => ['hours' => $hoursBack],
            'stats' => $stats
        ];
    }

    /**
     * Get list of unique hostnames from events
     *
     * @return array List of hostnames
     */
    public function getHostsList(): array
    {
        $result = $this->getEvents(0); // All time

        if (!$result['success']) {
            return [];
        }

        $hosts = [];
        foreach ($result['data'] as $event) {
            $host = $event['hostname'];
            if (!in_array($host, $hosts)) {
                $hosts[] = $host;
            }
        }

        sort($hosts);
        return $hosts;
    }

    /**
     * Rotate MQTT events file to remove entries older than retention period
     *
     * @param int $retentionDays Number of days to retain
     */
    public function rotateEventsFile(int $retentionDays = 60): void
    {
        if (!file_exists($this->eventsFile)) {
            return;
        }

        $fp = fopen($this->eventsFile, 'c+');
        if (!$fp) {
            return;
        }

        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return;
        }

        $cutoffTime = time() - ($retentionDays * 24 * 3600);
        $recentEvents = [];
        $purgedCount = 0;

        rewind($fp);
        while (($line = fgets($fp)) !== false) {
            // Extract timestamp from compact format
            if (preg_match('/"t"\s*:\s*(\d+)/', $line, $matches)) {
                $entryTimestamp = (int)$matches[1];
                if ($entryTimestamp >= $cutoffTime) {
                    $recentEvents[] = $line;
                } else {
                    $purgedCount++;
                }
            }
        }

        // Only rewrite if we purged entries
        if ($purgedCount === 0) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return;
        }

        // Atomic swap via temp file
        $backupFile = $this->eventsFile . '.old';
        $tempFile = $this->eventsFile . '.tmp';

        $tempFp = fopen($tempFile, 'w');
        if ($tempFp) {
            if (!empty($recentEvents)) {
                fwrite($tempFp, implode('', $recentEvents));
            }
            fclose($tempFp);

            @unlink($backupFile);
            rename($this->eventsFile, $backupFile);
            rename($tempFile, $this->eventsFile);

            $this->logger->info("MQTT events purge: removed {$purgedCount} old entries, kept " . count($recentEvents) . " recent entries.");

            $this->fileManager->ensureFppOwnership($this->eventsFile);
            $this->fileManager->ensureFppOwnership($backupFile);
        } else {
            $this->logger->error("Unable to create temp file for MQTT events purge");
        }

        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
