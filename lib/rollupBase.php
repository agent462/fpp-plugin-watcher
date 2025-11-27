<?php
/**
 * Rollup Base Library
 *
 * Generic functions for RRD-style rollup of metrics into multiple time resolutions.
 * Used by both ping metrics and multi-sync ping metrics.
 */

include_once "/opt/fpp/www/common.php";
include_once __DIR__ . "/watcherCommon.php";

/**
 * Format interval in human-readable form
 */
function formatInterval($seconds) {
    if ($seconds < 60) {
        return $seconds . ' seconds';
    } elseif ($seconds < 3600) {
        return ($seconds / 60) . ' minutes';
    } else {
        return ($seconds / 3600) . ' hours';
    }
}

/**
 * Format duration in human-readable form
 */
function formatDuration($seconds) {
    if ($seconds < 3600) {
        return ($seconds / 60) . ' minutes';
    } elseif ($seconds < 86400) {
        return ($seconds / 3600) . ' hours';
    } else {
        return ($seconds / 86400) . ' days';
    }
}

/**
 * Get or initialize rollup state
 *
 * @param string $stateFile Path to state file
 * @param array $tiers Tier configuration array
 * @return array State array
 */
function getRollupStateGeneric($stateFile, $tiers) {
    $buildFreshState = function() use ($tiers) {
        $state = [];
        foreach (array_keys($tiers) as $tier) {
            $state[$tier] = [
                'last_processed' => 0,
                'last_bucket_end' => 0,
                'last_rollup' => time()
            ];
        }
        return $state;
    };

    if (!file_exists($stateFile)) {
        $state = $buildFreshState();
        saveRollupStateGeneric($stateFile, $state);
        return $state;
    }

    $content = file_get_contents($stateFile);
    $state = json_decode($content, true);

    if (!$state || !is_array($state)) {
        logMessage("Corrupted rollup state file detected: {$stateFile}. Rebuilding fresh state.");
        $state = $buildFreshState();
        saveRollupStateGeneric($stateFile, $state);
    }

    // Backfill new state fields if needed
    foreach (array_keys($tiers) as $tier) {
        if (!isset($state[$tier])) {
            $state[$tier] = [
                'last_processed' => 0,
                'last_bucket_end' => 0,
                'last_rollup' => time()
            ];
            continue;
        }

        if (!isset($state[$tier]['last_bucket_end'])) {
            $state[$tier]['last_bucket_end'] = 0;
        }
        if (!isset($state[$tier]['last_rollup'])) {
            $state[$tier]['last_rollup'] = time();
        }
        if (!isset($state[$tier]['last_processed'])) {
            $state[$tier]['last_processed'] = 0;
        }
    }

    return $state;
}

/**
 * Save rollup state to disk
 *
 * @param string $stateFile Path to state file
 * @param array $state State array to save
 * @return bool Success
 */
function saveRollupStateGeneric($stateFile, $state) {
    $fp = fopen($stateFile, 'c');
    if (!$fp) {
        logMessage("ERROR: Unable to open rollup state file for writing: {$stateFile}");
        return false;
    }

    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        fwrite($fp, json_encode($state, JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
    }

    fclose($fp);
    ensureFppOwnership($stateFile);
    return true;
}

/**
 * Read raw metrics from a log file
 *
 * @param string $metricsFile Path to metrics file
 * @param int $sinceTimestamp Only return entries newer than this timestamp
 * @return array Array of metric entries
 */
function readMetricsFileGeneric($metricsFile, $sinceTimestamp = 0) {
    if (!file_exists($metricsFile)) {
        return [];
    }

    $metrics = [];
    $fp = fopen($metricsFile, 'r');

    if (!$fp) {
        return [];
    }

    if (flock($fp, LOCK_SH)) {
        while (($line = fgets($fp)) !== false) {
            if (preg_match('/\[.*?\]\s+(.+)$/', $line, $matches)) {
                $jsonData = trim($matches[1]);
                $entry = json_decode($jsonData, true);

                if ($entry && isset($entry['timestamp'])) {
                    if ($entry['timestamp'] > $sinceTimestamp) {
                        $metrics[] = $entry;
                    }
                }
            }
        }
        flock($fp, LOCK_UN);
    }

    fclose($fp);

    usort($metrics, function($a, $b) {
        return $a['timestamp'] - $b['timestamp'];
    });

    return $metrics;
}

/**
 * Append rollup entries to a rollup file
 *
 * @param string $rollupFile Path to rollup file
 * @param array $entries Array of entries to append
 * @return bool Success
 */
function appendRollupEntriesGeneric($rollupFile, $entries) {
    $fp = fopen($rollupFile, 'a');

    if (!$fp) {
        logMessage("ERROR: Unable to open rollup file for writing: {$rollupFile}");
        return false;
    }

    if (flock($fp, LOCK_EX)) {
        foreach ($entries as $entry) {
            $timestamp = date('Y-m-d H:i:s', $entry['timestamp']);
            $jsonData = json_encode($entry);
            $logEntry = "[{$timestamp}] {$jsonData}\n";
            fwrite($fp, $logEntry);
        }
        fflush($fp);
        flock($fp, LOCK_UN);
    }

    fclose($fp);
    ensureFppOwnership($rollupFile);
    return true;
}

/**
 * Rotate rollup file to keep only entries within retention period
 *
 * @param string $rollupFile Path to rollup file
 * @param int $retentionSeconds Retention period in seconds
 */
function rotateRollupFileGeneric($rollupFile, $retentionSeconds) {
    if (!file_exists($rollupFile)) {
        return;
    }

    $fileSize = filesize($rollupFile);

    // Only rotate if file is larger than 1MB to avoid excessive I/O
    if ($fileSize < 1024 * 1024) {
        return;
    }

    $cutoffTime = time() - $retentionSeconds;
    $recentEntries = [];

    $fp = fopen($rollupFile, 'r');
    if (!$fp) {
        return;
    }

    if (flock($fp, LOCK_SH)) {
        while (($line = fgets($fp)) !== false) {
            if (preg_match('/\[.*?\]\s+(.+)$/', $line, $matches)) {
                $jsonData = trim($matches[1]);
                $entry = json_decode($jsonData, true);

                if ($entry && isset($entry['timestamp'])) {
                    if ($entry['timestamp'] >= $cutoffTime) {
                        $recentEntries[] = $line;
                    }
                }
            }
        }
        flock($fp, LOCK_UN);
    }

    fclose($fp);

    if (!empty($recentEntries)) {
        $fp = fopen($rollupFile, 'w');
        if ($fp && flock($fp, LOCK_EX)) {
            foreach ($recentEntries as $line) {
                fwrite($fp, $line);
            }
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    } else {
        file_put_contents($rollupFile, '');
    }
    ensureFppOwnership($rollupFile);
}

/**
 * Read rollup data from a file with time filtering
 *
 * @param string $rollupFile Path to rollup file
 * @param string $tier Tier name
 * @param array $tiers Tier configuration array
 * @param int|null $startTime Start timestamp (null for tier default)
 * @param int|null $endTime End timestamp (null for now)
 * @param callable|null $filterFn Optional filter function for entries
 * @return array Result with success, count, data, tier, and period
 */
function readRollupDataGeneric($rollupFile, $tier, $tiers, $startTime = null, $endTime = null, $filterFn = null) {
    if (!file_exists($rollupFile)) {
        return [
            'success' => false,
            'error' => 'Rollup file not found',
            'data' => []
        ];
    }

    if ($endTime === null) {
        $endTime = time();
    }

    if ($startTime === null) {
        $tierConfig = $tiers[$tier] ?? null;
        if ($tierConfig) {
            $startTime = $endTime - $tierConfig['retention'];
        } else {
            $startTime = $endTime - (24 * 3600);
        }
    }

    $data = [];
    $fp = fopen($rollupFile, 'r');

    if (!$fp) {
        return [
            'success' => false,
            'error' => 'Unable to read rollup file',
            'data' => []
        ];
    }

    if (flock($fp, LOCK_SH)) {
        while (($line = fgets($fp)) !== false) {
            if (preg_match('/\[.*?\]\s+(.+)$/', $line, $matches)) {
                $jsonData = trim($matches[1]);
                $entry = json_decode($jsonData, true);

                if ($entry && isset($entry['timestamp'])) {
                    $timestamp = $entry['timestamp'];

                    if ($timestamp >= $startTime && $timestamp <= $endTime) {
                        // Apply custom filter if provided
                        if ($filterFn === null || $filterFn($entry)) {
                            $data[] = $entry;
                        }
                    }
                }
            }
        }
        flock($fp, LOCK_UN);
    }

    fclose($fp);

    usort($data, function($a, $b) {
        return $a['timestamp'] - $b['timestamp'];
    });

    return [
        'success' => true,
        'count' => count($data),
        'data' => $data,
        'tier' => $tier,
        'period' => [
            'start' => $startTime,
            'end' => $endTime
        ]
    ];
}

/**
 * Get the best rollup tier for a given time range
 *
 * @param int $hoursBack Number of hours to look back
 * @return string Tier name
 */
function getBestTierForHours($hoursBack) {
    if ($hoursBack <= 6) {
        return '1min';
    } elseif ($hoursBack <= 48) {
        return '5min';
    } elseif ($hoursBack <= 336) {
        return '30min';
    } else {
        return '2hour';
    }
}

/**
 * Get information about available rollup tiers
 *
 * @param array $tiers Tier configuration array
 * @param callable $getFilePath Function to get file path for a tier
 * @return array Tier info array
 */
function getTiersInfoGeneric($tiers, $getFilePath) {
    $result = [];

    foreach ($tiers as $tier => $config) {
        $filePath = $getFilePath($tier);
        $result[$tier] = [
            'interval' => $config['interval'],
            'interval_label' => formatInterval($config['interval']),
            'retention' => $config['retention'],
            'retention_label' => formatDuration($config['retention']),
            'label' => $config['label'],
            'file_exists' => file_exists($filePath),
            'file_size' => file_exists($filePath) ? filesize($filePath) : 0
        ];
    }

    return $result;
}

/**
 * Generic rollup tier processing
 *
 * @param string $tier Tier name
 * @param array $tierConfig Tier configuration
 * @param array $tiers All tiers configuration
 * @param string $stateFile Path to state file
 * @param string $metricsFile Path to raw metrics file
 * @param callable $getRollupFilePath Function to get rollup file path
 * @param callable $aggregateFn Function to aggregate metrics for a bucket
 * @param callable|null $getRollupStateFn Optional custom state getter
 * @param callable|null $saveRollupStateFn Optional custom state saver
 */
function processRollupTierGeneric(
    $tier,
    $tierConfig,
    $tiers,
    $stateFile,
    $metricsFile,
    $getRollupFilePath,
    $aggregateFn,
    $getRollupStateFn = null,
    $saveRollupStateFn = null
) {
    // Use provided functions or defaults
    $getState = $getRollupStateFn ?? function() use ($stateFile, $tiers) {
        return getRollupStateGeneric($stateFile, $tiers);
    };
    $saveState = $saveRollupStateFn ?? function($state) use ($stateFile) {
        return saveRollupStateGeneric($stateFile, $state);
    };

    $state = $getState();
    $lastProcessed = $state[$tier]['last_processed'] ?? 0;
    $lastBucketEnd = $state[$tier]['last_bucket_end'] ?? 0;
    $lastRollup = $state[$tier]['last_rollup'] ?? 0;
    $interval = $tierConfig['interval'];
    $retention = $tierConfig['retention'];
    $now = time();

    // Prevent running a tier more frequently than its interval
    if (($now - $lastRollup) < $interval) {
        return;
    }

    $rawMetrics = readMetricsFileGeneric($metricsFile, $lastProcessed);
    $processingCutoff = $now - 1;

    if (empty($rawMetrics)) {
        $state[$tier]['last_rollup'] = $now;
        $saveState($state);
        return;
    }

    // Group metrics into time buckets
    $buckets = [];
    foreach ($rawMetrics as $metric) {
        $timestamp = $metric['timestamp'];
        $bucketStart = floor($timestamp / $interval) * $interval;

        if (!isset($buckets[$bucketStart])) {
            $buckets[$bucketStart] = [];
        }

        $buckets[$bucketStart][] = $metric;
    }

    $rollupFile = $getRollupFilePath($tier);
    $newEntries = [];

    ksort($buckets);
    $latestProcessedBucketEnd = $lastBucketEnd;

    foreach ($buckets as $bucketStart => $bucketMetrics) {
        $bucketEnd = $bucketStart + $interval;

        if ($bucketEnd <= $lastBucketEnd) {
            continue;
        }

        if ($bucketEnd > $processingCutoff) {
            continue;
        }

        $aggregated = $aggregateFn($bucketMetrics, $bucketStart, $interval);

        if ($aggregated === null) {
            continue;
        }

        // Handle both single entry and array of entries (for per-host aggregation)
        if (isset($aggregated[0])) {
            // Array of entries (multi-sync per-host)
            foreach ($aggregated as $entry) {
                $newEntries[] = $entry;
            }
        } else {
            // Single entry
            $newEntries[] = $aggregated;
        }

        $latestProcessedBucketEnd = max($latestProcessedBucketEnd, $bucketEnd);
    }

    if (!empty($newEntries)) {
        appendRollupEntriesGeneric($rollupFile, $newEntries);

        $state[$tier]['last_processed'] = $latestProcessedBucketEnd - 1;
        $state[$tier]['last_bucket_end'] = $latestProcessedBucketEnd;
        $state[$tier]['last_rollup'] = $now;
        $saveState($state);
    } else {
        $state[$tier]['last_rollup'] = $now;
        $saveState($state);
    }

    rotateRollupFileGeneric($rollupFile, $retention);
}

?>
