#!/usr/bin/php
<?php
/**
 * MQTT Subscriber Daemon
 *
 * Subscribes to FPP MQTT topics and logs events for the dashboard.
 * Runs as a background process, started by postStart.sh when enabled.
 */

include_once __DIR__ . "/lib/core/watcherCommon.php";
include_once __DIR__ . "/lib/core/config.php";
include_once __DIR__ . "/lib/utils/mqttEvents.php";

$config = readPluginConfig();

if (!$config['mqttMonitorEnabled']) {
    logMessage("MQTT Monitor is disabled. Exiting.");
    exit(0);
}

// MQTT connection settings (FPP defaults)
$mqttHost = 'localhost';
$mqttPort = 1883;
$mqttUser = 'fpp';
$mqttPass = 'falcon';

// Topics to subscribe to (based on actual FPP MQTT output)
$topics = [
    'falcon/player/+/status',                      // playing/idle status
    'falcon/player/+/playlist/name/status',        // playlist name
    'falcon/player/+/playlist/sequence/status',    // sequence filename
    'falcon/player/+/playlist/sequence/secondsTotal', // sequence duration
    'falcon/player/+/playlist/section/status',     // section: MainPlaylist, LeadIn, LeadOut
    'falcon/player/+/playlist/media/status',       // media filename
    'falcon/player/+/playlist/media/title',        // song title
    'falcon/player/+/playlist/media/artist',       // artist name
    'falcon/player/+/warnings',                    // warning messages
];

// Rotation tracking
$lastRotationCheck = 0;
$rotationInterval = 3600; // Check rotation every hour

// Tracking state per host
$sequenceStartTimes = [];  // host => ['sequence' => name, 'start' => timestamp, 'duration' => seconds]
$currentMedia = [];        // host => ['title' => title, 'artist' => artist]

logMessage("=== MQTT Subscriber Started ===");
logMessage("Retention: {$config['mqttRetentionDays']} days");
logMessage("Topics: " . implode(', ', $topics));

/**
 * Parse MQTT topic to extract hostname
 * Topic format: falcon/player/{hostname}/event/...
 */
function parseHostnameFromTopic($topic) {
    $parts = explode('/', $topic);
    if (count($parts) >= 3 && $parts[0] === 'falcon' && $parts[1] === 'player') {
        return $parts[2];
    }
    return 'unknown';
}

/**
 * Determine event type from topic
 * Returns event type constant or special handling marker
 */
function parseEventTypeFromTopic($topic, $payload) {
    // Sequence status: falcon/player/{host}/playlist/sequence/status
    if (strpos($topic, '/playlist/sequence/status') !== false) {
        $payload = trim($payload);
        if (!empty($payload) && $payload !== 'null' && $payload !== '(null)') {
            return MQTT_EVENT_SEQ_START;
        } else {
            return MQTT_EVENT_SEQ_STOP;
        }
    }

    // Sequence duration: falcon/player/{host}/playlist/sequence/secondsTotal
    if (strpos($topic, '/playlist/sequence/secondsTotal') !== false) {
        return '_seq_duration'; // Internal marker, not logged as event
    }

    // Playlist name: falcon/player/{host}/playlist/name/status
    if (strpos($topic, '/playlist/name/status') !== false) {
        $payload = trim($payload);
        if (!empty($payload) && $payload !== 'null' && $payload !== '(null)') {
            return MQTT_EVENT_PL_START;
        } else {
            return MQTT_EVENT_PL_STOP;
        }
    }

    // Media title: falcon/player/{host}/playlist/media/title
    if (strpos($topic, '/playlist/media/title') !== false) {
        return '_media_title'; // Internal marker
    }

    // Media artist: falcon/player/{host}/playlist/media/artist
    if (strpos($topic, '/playlist/media/artist') !== false) {
        return '_media_artist'; // Internal marker
    }

    // Media status: falcon/player/{host}/playlist/media/status
    if (strpos($topic, '/playlist/media/status') !== false) {
        $payload = trim($payload);
        if (!empty($payload) && $payload !== 'null' && $payload !== '(null)') {
            return MQTT_EVENT_MEDIA_START;
        } else {
            return MQTT_EVENT_MEDIA_STOP;
        }
    }

    // Warnings: falcon/player/{host}/warnings
    if (strpos($topic, '/warnings') !== false) {
        return MQTT_EVENT_WARNING;
    }

    // Player status: falcon/player/{host}/status (playing/idle)
    if (preg_match('/falcon\/player\/[^\/]+\/status$/', $topic)) {
        return MQTT_EVENT_STATUS;
    }

    return null;
}

/**
 * Check if payload is a valid non-null value
 */
function isValidPayload($payload) {
    $payload = trim($payload);
    return !empty($payload) && $payload !== 'null' && $payload !== '(null)';
}

/**
 * Extract event data from payload
 */
function extractEventData($eventType, $payload) {
    $payload = trim($payload);

    // Return empty string for null/empty payloads (stop events)
    if (!isValidPayload($payload)) {
        return '';
    }

    // Try to decode JSON payload
    $data = @json_decode($payload, true);

    if ($data !== null) {
        // Handle structured payloads
        if (isset($data['name'])) {
            return $data['name'];
        }
        if (isset($data['sequence'])) {
            return $data['sequence'];
        }
        if (isset($data['playlist'])) {
            return $data['playlist'];
        }
        if (isset($data['status'])) {
            return $data['status'];
        }
    }

    // For simple string payloads
    if (strlen($payload) > 100) {
        $payload = substr($payload, 0, 97) . '...';
    }
    return $payload;
}

/**
 * Process an MQTT message
 */
function processMqttMessage($topic, $payload) {
    global $sequenceStartTimes, $currentMedia;

    $hostname = parseHostnameFromTopic($topic);
    $eventType = parseEventTypeFromTopic($topic, $payload);

    if ($eventType === null) {
        return; // Unrecognized event type
    }

    $payload = trim($payload);

    // Handle internal state tracking (not logged as events)
    if ($eventType === '_seq_duration') {
        // Store duration for current sequence
        if (!empty($payload) && is_numeric($payload)) {
            if (isset($sequenceStartTimes[$hostname])) {
                $sequenceStartTimes[$hostname]['duration'] = (int)$payload;
            }
        }
        return;
    }

    if ($eventType === '_media_title') {
        if (!isset($currentMedia[$hostname])) {
            $currentMedia[$hostname] = [];
        }
        // Only store if it's a real value, not null
        if (isValidPayload($payload)) {
            $currentMedia[$hostname]['title'] = $payload;
        }
        return;
    }

    if ($eventType === '_media_artist') {
        if (!isset($currentMedia[$hostname])) {
            $currentMedia[$hostname] = [];
        }
        // Only store if it's a real value, not null
        if (isValidPayload($payload)) {
            $currentMedia[$hostname]['artist'] = $payload;
        }
        return;
    }

    $data = extractEventData($eventType, $payload);
    $duration = null;

    // Track sequence start
    if ($eventType === MQTT_EVENT_SEQ_START && !empty($data)) {
        $sequenceStartTimes[$hostname] = [
            'sequence' => $data,
            'start' => time(),
            'duration' => null
        ];
    }

    // Calculate duration on sequence stop (use published duration if available, else calculate)
    if ($eventType === MQTT_EVENT_SEQ_STOP) {
        if (isset($sequenceStartTimes[$hostname])) {
            $duration = $sequenceStartTimes[$hostname]['duration']
                ?? (time() - $sequenceStartTimes[$hostname]['start']);
            unset($sequenceStartTimes[$hostname]);
        }
    }

    // For media start, include title/artist if available
    if ($eventType === MQTT_EVENT_MEDIA_START && !empty($data)) {
        $title = $currentMedia[$hostname]['title'] ?? '';
        $artist = $currentMedia[$hostname]['artist'] ?? '';
        if (!empty($title)) {
            // Use title instead of filename if available
            $data = !empty($artist) ? "{$title} - {$artist}" : $title;
        }
    }

    // For media stop, clear current media info
    if ($eventType === MQTT_EVENT_MEDIA_STOP) {
        unset($currentMedia[$hostname]);
    }

    // Handle warnings - parse JSON array
    if ($eventType === MQTT_EVENT_WARNING) {
        $warnings = @json_decode($payload, true);
        if (is_array($warnings) && !empty($warnings)) {
            // Log each warning as separate event
            foreach ($warnings as $warning) {
                if (is_string($warning) && !empty($warning)) {
                    writeMqttEvent($hostname, $eventType, $warning, null);
                }
            }
        }
        return;
    }

    writeMqttEvent($hostname, $eventType, $data, $duration);
}

/**
 * Run the MQTT subscriber using mosquitto_sub
 */
function runMqttSubscriber($host, $port, $user, $pass, $topics) {
    global $config, $lastRotationCheck, $rotationInterval;

    // Build topic arguments
    $topicArgs = '';
    foreach ($topics as $topic) {
        $topicArgs .= ' -t ' . escapeshellarg($topic);
    }

    // Build mosquitto_sub command with verbose output for topic parsing
    $cmd = sprintf(
        'mosquitto_sub -h %s -p %d -u %s -P %s -v%s 2>&1',
        escapeshellarg($host),
        $port,
        escapeshellarg($user),
        escapeshellarg($pass),
        $topicArgs
    );

    logMessage("Starting mosquitto_sub: mosquitto_sub -h $host -p $port -u $user -P *** -v ...");

    // Open process
    $descriptors = [
        0 => ['pipe', 'r'],  // stdin
        1 => ['pipe', 'w'],  // stdout
        2 => ['pipe', 'w']   // stderr
    ];

    $process = proc_open($cmd, $descriptors, $pipes);

    if (!is_resource($process)) {
        logMessage("ERROR: Failed to start mosquitto_sub process");
        return false;
    }

    // Close stdin - we don't need it
    fclose($pipes[0]);

    // Set stdout to non-blocking
    stream_set_blocking($pipes[1], false);

    logMessage("MQTT subscriber connected and listening...");

    // Read loop
    while (true) {
        // Check if process is still running
        $status = proc_get_status($process);
        if (!$status['running']) {
            logMessage("mosquitto_sub process exited with code: " . $status['exitcode']);
            break;
        }

        // Read available data (verbose format: topic payload)
        $line = fgets($pipes[1]);
        if ($line !== false) {
            $line = trim($line);
            if (!empty($line)) {
                // Parse verbose format: "topic payload"
                $spacePos = strpos($line, ' ');
                if ($spacePos !== false) {
                    $topic = substr($line, 0, $spacePos);
                    $payload = substr($line, $spacePos + 1);
                    processMqttMessage($topic, $payload);
                } else {
                    // Topic only, no payload
                    processMqttMessage($line, '');
                }
            }
        }

        // Periodic rotation check
        $now = time();
        if (($now - $lastRotationCheck) >= $rotationInterval) {
            rotateMqttEventsFile($config['mqttRetentionDays']);
            $lastRotationCheck = $now;
        }

        // Small sleep to prevent CPU spinning
        usleep(10000); // 10ms
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return true;
}

// Main loop with reconnection
$reconnectDelay = 5;
$maxReconnectDelay = 60;

while (true) {
    $result = runMqttSubscriber($mqttHost, $mqttPort, $mqttUser, $mqttPass, $topics);

    if ($result === false) {
        logMessage("MQTT connection failed, retrying in {$reconnectDelay}s...");
    } else {
        logMessage("MQTT subscriber disconnected, reconnecting in {$reconnectDelay}s...");
    }

    sleep($reconnectDelay);

    // Exponential backoff on repeated failures
    $reconnectDelay = min($reconnectDelay * 2, $maxReconnectDelay);

    // Reset delay on successful connection
    if ($result === true) {
        $reconnectDelay = 5;
    }

    // Reload config in case settings changed
    $config = readPluginConfig(true);
    if (!$config['mqttMonitorEnabled']) {
        logMessage("MQTT Monitor disabled via config. Exiting.");
        exit(0);
    }
}
?>
