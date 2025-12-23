<?php
/**
 * multiSyncMetricsUI.php - Comprehensive Multi-Sync Dashboard
 *
 * Unified dashboard showing:
 * - Local sync metrics from C++ plugin
 * - Player vs remote comparison
 * - Real-time sync status and issues
 */
require_once __DIR__ . '/../classes/autoload.php';
require_once __DIR__ . '/../classes/Watcher/UI/ViewHelpers.php';
include_once __DIR__ . '/../lib/core/config.php';
include_once __DIR__ . '/../lib/core/watcherCommon.php';

use Watcher\Http\ApiClient;

$config = readPluginConfig();
$localSystem = ApiClient::getInstance()->get('http://127.0.0.1/api/fppd/status', 5) ?: [];

// Get filtered remote systems (single source of truth for comparison)
$remoteSystems = getMultiSyncRemoteSystems();

// Check if multi-sync is enabled (player-only setting)
$multiSyncEnabled = ($localSystem['multisync'] ?? false) === true;

// FPP Mode detection - use mode_name for clarity
// mode_name: 'player', 'remote', 'master', 'bridge', etc.
// mode: 2=Player, 6=Remote, 8=Master (deprecated)
$modeName = $localSystem['mode_name'] ?? '';
$isRemoteMode = ($modeName === 'remote');
$isPlayerMode = ($modeName === 'player' || $modeName === 'master');

use Watcher\UI\ViewHelpers;
ViewHelpers::renderCSSIncludes(true);
?>

<div class="msm-container" data-watcher-page="multiSyncMetricsUI">
    <script>
    window.watcherConfig = {
        isPlayerMode: <?php echo $isPlayerMode ? 'true' : 'false'; ?>,
        isRemoteMode: <?php echo $isRemoteMode ? 'true' : 'false'; ?>,
        remoteSystems: <?php echo json_encode($remoteSystems, JSON_HEX_TAG | JSON_HEX_AMP); ?>,
        localHostname: <?php echo json_encode($localSystem['host_name'] ?? gethostname(), JSON_HEX_TAG | JSON_HEX_AMP); ?>,
        multiSyncPingEnabled: <?php echo ($config['multiSyncPingEnabled'] ?? false) ? 'true' : 'false'; ?>
    };
    </script>
    <div class="msm-header">
        <h2><i class="fas fa-network-wired"></i> Multi-Sync Dashboard</h2>
        <div class="msm-header-right">
            <span class="msm-last-update" id="lastUpdate"></span>
            <button class="btn btn-sm btn-outline-secondary" onclick="page.refresh()">
                <i class="fas fa-sync-alt"></i> Refresh
            </button>
            <button class="btn btn-sm btn-outline-info msm-help-btn" onclick="page.toggleHelpTooltip()" title="Help">
                <i class="fas fa-question-circle"></i>
            </button>
        </div>

        <!-- Help Tooltip -->
        <div class="msm-help-tooltip" id="helpTooltip">
            <div class="msm-help-header">
                <span><i class="fas fa-info-circle"></i> Metrics Guide</span>
                <button class="msm-help-close" onclick="page.toggleHelpTooltip()">&times;</button>
            </div>
            <div class="msm-help-body">
                <?php if ($isPlayerMode): ?>
                <div class="msm-help-section">
                    <h4>Network Quality</h4>
                    <dl>
                        <dt>Latency</dt>
                        <dd>Round-trip time to reach each remote system. Lower is better. Good: &lt;50ms, Fair: 50-100ms, Poor: &gt;100ms</dd>
                        <dt>Network Jitter</dt>
                        <dd>Variation in ICMP ping latency over time (RFC 3550). Measures general network path stability. Good: &lt;10ms, Fair: 10-25ms</dd>
                        <dt>Sync Packet Loss</dt>
                        <dd>Estimated based on sync packet receive rate during playback. FPP sends sync packets every 10 frames (~2-4/sec depending on sequence frame rate).</dd>
                    </dl>
                </div>
                <div class="msm-help-section">
                    <h4>Remote Seq Sync Packet Timing</h4>
                    <dl>
                        <dt>Packets Recv</dt>
                        <dd>Total MultiSync packets received by each remote, including <strong>all</strong> packet types: sequence sync, media sync, blank, command, and plugin packets.</dd>
                        <dt>Seq Sync Rate</dt>
                        <dd>Sequence sync packets per second, calculated from the interval (1000ms ÷ interval). This is <strong>only sequence syncs</strong>, not media or other packets.</dd>
                        <dt>Seq Sync Interval</dt>
                        <dd>Average time between sequence sync packets. FPP sends sync every ~10 frames, so at 40fps expect ~250ms (~4/sec). At 20fps expect ~500ms (~2/sec).</dd>
                        <dt>Seq Sync Jitter</dt>
                        <dd>Variation in sequence sync packet arrival times (RFC 3550). Measures MultiSync UDP timing - different from network ping jitter. Good: &lt;20ms, Fair: 20-50ms</dd>
                    </dl>
                    <div class="msm-help-example">
                        <strong>Why Packets Recv differs from Seq Sync Rate:</strong><br>
                        • Seq Sync Rate 4/s = only sequence sync packets<br>
                        • Media sync adds ~2 more packets/sec<br>
                        • So total ~6 packets/sec in Packets Recv
                    </div>
                </div>
                <?php else: ?>
                <div class="msm-help-section">
                    <h4>Seq Sync Packet Timing</h4>
                    <dl>
                        <dt>Packet Rate</dt>
                        <dd>Total MultiSync packets received per second, including <strong>all</strong> packet types: sequence sync, media sync, blank, command, and plugin packets. Example: 6 packets/sec might be 4 sync + 2 media packets.</dd>
                        <dt>Seq Sync Interval</dt>
                        <dd>Average time between <strong>sequence sync packets only</strong> (not media or other packets). FPP sends sync every ~10 frames, so at 40fps expect ~250ms interval (~4/sec). At 20fps expect ~500ms (~2/sec). This is by design to reduce network traffic - remotes interpolate between sync points.</dd>
                        <dt>Seq Sync Jitter</dt>
                        <dd>Variation in sequence sync packet arrival times (RFC 3550). Measures MultiSync UDP timing consistency - different from network ping jitter which uses ICMP. High jitter may indicate network congestion affecting broadcast/multicast traffic. Good: &lt;20ms, Fair: 20-50ms</dd>
                    </dl>
                    <div class="msm-help-example">
                        <strong>Example:</strong> With Packet Rate 6/sec and Seq Sync Interval 250ms:<br>
                        • Sequence sync: ~4 packets/sec (1000ms ÷ 250ms)<br>
                        • Media sync: ~2 packets/sec (remainder)<br>
                        • Total: 6 packets/sec ✓
                    </div>
                </div>
                <?php endif; ?>
                <div class="msm-help-section">
                    <h4>Sync Metrics</h4>
                    <dl>
                        <?php if ($isPlayerMode): ?>
                        <dt>Time Drift</dt>
                        <dd>System clock difference between player and remote. Positive = remote clock is ahead, negative = behind. Small variations (±100ms) are normal due to network measurement limitations. Uses NTP-style calculation with multiple samples, selecting the lowest RTT for accuracy. Green: &lt;100ms, Yellow: 100ms-1s, Red: &gt;1s.</dd>
                        <dt>Step Time</dt>
                        <dd>Milliseconds per frame from sequence file. Common values: 25ms (40fps), 50ms (20fps).</dd>
                        <?php else: ?>
                        <dt>Frame Drift</dt>
                        <dd>Difference between expected and actual frame position. Calculated from sync packet timing. Positive = ahead of player, negative = behind. Good: &lt;5 frames, Warning: 5-10 frames, Critical: &gt;10 frames.</dd>
                        <?php endif; ?>
                        <dt>Last Sync</dt>
                        <dd>Time since last sync packet was received. Should be &lt;1 second during active playback.</dd>
                    </dl>
                </div>
                <div class="msm-help-section">
                    <h4>Packet Types</h4>
                    <dl>
                        <dt>Sync Packets</dt>
                        <dd>Sequence sync commands (open/start/stop/sync). <?php echo $isPlayerMode ? 'Sent by player, received by remotes.' : 'Received from player to keep sequence in sync.'; ?></dd>
                        <dt>Media Packets</dt>
                        <dd>Audio sync for media playback. Keeps audio in sync across systems.</dd>
                        <dt>Command Packets</dt>
                        <dd>Remote control commands (test mode, etc.).</dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>

    <?php if ($isPlayerMode && !$multiSyncEnabled): ?>
    <div class="msm-notice msm-notice-warning">
        <i class="fas fa-exclamation-triangle"></i>
        <div>
            <strong>MultiSync is not enabled.</strong>
            <p style="margin: 0.25rem 0 0;">Enable MultiSync in FPP Settings to collect multi-sync metrics.</p>
        </div>
    </div>
    <?php endif; ?>

    <div id="pluginError" class="msm-notice msm-notice-error" style="display: none;">
        <i class="fas fa-exclamation-circle"></i>
        <div>
            <strong>Plugin Not Loaded</strong>
            <p id="pluginErrorMessage" style="margin: 0.25rem 0 0;"></p>
        </div>
    </div>

    <!-- System Status Card -->
    <div class="msm-system-card" id="systemCard">
        <div class="msm-system-header">
            <span class="msm-system-title">
                <?php if ($isPlayerMode): ?>
                <i class="fas fa-crown"></i>
                <?php else: ?>
                <i class="fas fa-satellite-dish"></i>
                <?php endif; ?>
                <span id="systemHostname">This System</span>
            </span>
            <span class="msm-system-mode <?php echo $isRemoteMode ? 'msm-mode-remote' : 'msm-mode-player'; ?>" id="systemMode"><?php echo $isPlayerMode ? 'Player' : ($isRemoteMode ? 'Remote' : '--'); ?></span>
            <?php if ($isRemoteMode): ?>
            <span class="msm-sync-health" id="syncHealthBadge" title="Overall sync health"><i class="fas fa-circle"></i> <span>--</span></span>
            <?php endif; ?>
        </div>
        <div class="msm-system-metrics">
            <div class="msm-system-metric msm-metric-wide">
                <span class="msm-system-metric-label"><?php echo $isRemoteMode ? 'Receiving Sequence' : 'Sequence'; ?></span>
                <span class="msm-system-metric-value" id="systemSequence">--</span>
            </div>
            <div class="msm-system-metric">
                <span class="msm-system-metric-label">Status</span>
                <span class="msm-system-metric-value" id="systemStatus">--</span>
            </div>
            <div class="msm-system-metric">
                <span class="msm-system-metric-label">Frame</span>
                <span class="msm-system-metric-value msm-mono" id="systemFrame">--</span>
            </div>
            <div class="msm-system-metric">
                <span class="msm-system-metric-label">Time</span>
                <span class="msm-system-metric-value msm-mono" id="systemTime">--</span>
            </div>
            <div class="msm-system-metric">
                <span class="msm-system-metric-label">Step Time</span>
                <span class="msm-system-metric-value" id="systemStepTime">--</span>
            </div>
            <?php if ($isPlayerMode): ?>
            <div class="msm-system-metric">
                <span class="msm-system-metric-label">Channels</span>
                <span class="msm-system-metric-value" id="systemChannels">--</span>
            </div>
            <div class="msm-system-metric">
                <span class="msm-system-metric-label">Sync Packets Sent</span>
                <span class="msm-system-metric-value" id="systemPacketsSent">--</span>
            </div>
            <?php endif; ?>
            <div class="msm-system-metric">
                <span class="msm-system-metric-label">Sync Packets Received</span>
                <span class="msm-system-metric-value" id="systemPacketsReceived">--</span>
            </div>
            <?php if ($isRemoteMode): ?>
            <div class="msm-system-metric">
                <span class="msm-system-metric-label">Avg Frame Drift</span>
                <span class="msm-system-metric-value" id="systemAvgDrift">--</span>
            </div>
            <div class="msm-system-metric">
                <span class="msm-system-metric-label">Max Frame Drift</span>
                <span class="msm-system-metric-value" id="systemMaxDrift">--</span>
            </div>
            <div class="msm-system-metric">
                <span class="msm-system-metric-label">Packet Rate</span>
                <span class="msm-system-metric-value" id="systemPacketRate">--</span>
            </div>
            <div class="msm-system-metric">
                <span class="msm-system-metric-label">Seq Sync Interval <i class="fas fa-info-circle" style="color: #6c757d; cursor: help; font-size: 0.7em; opacity: 0.7;" title="Average time between sequence sync packets from player. FPP sends sync every ~10 frames (~250ms at 40fps)."></i></span>
                <span class="msm-system-metric-value" id="systemSyncInterval">--</span>
            </div>
            <div class="msm-system-metric">
                <span class="msm-system-metric-label">Seq Sync Jitter <i class="fas fa-info-circle" style="color: #6c757d; cursor: help; font-size: 0.7em; opacity: 0.7;" title="Variation in sequence sync packet arrival times (RFC 3550). Different from network ping jitter - measures MultiSync UDP timing consistency."></i></span>
                <span class="msm-system-metric-value" id="systemSyncJitter">--</span>
            </div>
            <?php endif; ?>
            <div class="msm-system-metric">
                <span class="msm-system-metric-label">Last Sync</span>
                <span class="msm-system-metric-value" id="systemLastSync">--</span>
            </div>
        </div>
        <!-- Sequence Lifecycle Metrics -->
        <div class="msm-lifecycle">
            <div class="msm-lifecycle-header">
                <i class="fas fa-film"></i> <?php echo $isRemoteMode ? 'Received Packets' : 'Sequence Lifecycle'; ?>
            </div>
            <div class="msm-lifecycle-grid">
                <div class="msm-lifecycle-item">
                    <span class="msm-lifecycle-label"><?php echo $isRemoteMode ? 'Seq Open' : 'Seq Open'; ?></span>
                    <span class="msm-lifecycle-value" id="lcSeqOpen">0</span>
                </div>
                <div class="msm-lifecycle-item">
                    <span class="msm-lifecycle-label"><?php echo $isRemoteMode ? 'Seq Start' : 'Seq Start'; ?></span>
                    <span class="msm-lifecycle-value" id="lcSeqStart">0</span>
                </div>
                <div class="msm-lifecycle-item">
                    <span class="msm-lifecycle-label"><?php echo $isRemoteMode ? 'Seq Stop' : 'Seq Stop'; ?></span>
                    <span class="msm-lifecycle-value" id="lcSeqStop">0</span>
                </div>
                <div class="msm-lifecycle-item">
                    <span class="msm-lifecycle-label">Media Open</span>
                    <span class="msm-lifecycle-value" id="lcMediaOpen">0</span>
                </div>
                <div class="msm-lifecycle-item">
                    <span class="msm-lifecycle-label">Media Start</span>
                    <span class="msm-lifecycle-value" id="lcMediaStart">0</span>
                </div>
                <div class="msm-lifecycle-item">
                    <span class="msm-lifecycle-label">Media Stop</span>
                    <span class="msm-lifecycle-value" id="lcMediaStop">0</span>
                </div>
                <div class="msm-lifecycle-item">
                    <span class="msm-lifecycle-label">Sync Pkts</span>
                    <span class="msm-lifecycle-value" id="lcSyncPackets">0</span>
                </div>
                <div class="msm-lifecycle-item">
                    <span class="msm-lifecycle-label">Media Pkts</span>
                    <span class="msm-lifecycle-value" id="lcMediaPackets">0</span>
                </div>
                <div class="msm-lifecycle-item">
                    <span class="msm-lifecycle-label">Blank Pkts</span>
                    <span class="msm-lifecycle-value" id="lcBlankPackets">0</span>
                </div>
                <div class="msm-lifecycle-item">
                    <span class="msm-lifecycle-label">Cmd Pkts</span>
                    <span class="msm-lifecycle-value" id="lcCmdPackets">0</span>
                </div>
                <div class="msm-lifecycle-item">
                    <span class="msm-lifecycle-label">Plugin Pkts</span>
                    <span class="msm-lifecycle-value" id="lcPluginPackets">0</span>
                </div>
            </div>
        </div>
    </div>

    <?php if ($isRemoteMode): ?>
    <!-- Issues Panel (Remote Mode - shown first) -->
    <div class="msm-issues-panel hidden" id="issuesPanel">
        <div class="msm-issues-header" id="issuesHeader">
            <span><i class="fas fa-exclamation-triangle"></i> Issues Detected</span>
            <span id="issueCount">0</span>
        </div>
        <div class="msm-issues-body" id="issuesList"></div>
    </div>

    <!-- Packet Summary Card (Remote Mode) -->
    <div class="msm-card">
        <div class="msm-card-header">
            <h3 class="msm-card-title"><i class="fas fa-chart-bar"></i> Packet Summary</h3>
            <button class="btn btn-sm btn-outline-secondary" onclick="page.resetMetrics()">
                <i class="fas fa-undo"></i> Reset
            </button>
        </div>
        <div class="msm-card-body">
            <div class="msm-packet-summary">
                <div class="msm-packet-summary-item">
                    <i class="fas fa-arrow-down"></i>
                    <div class="msm-packet-summary-content">
                        <span class="msm-packet-summary-value" id="summaryTotalReceived">--</span>
                        <span class="msm-packet-summary-label">Total Packets Received</span>
                    </div>
                </div>
                <div class="msm-packet-summary-item">
                    <i class="fas fa-sync"></i>
                    <div class="msm-packet-summary-content">
                        <span class="msm-packet-summary-value" id="summarySyncReceived">--</span>
                        <span class="msm-packet-summary-label">Sync Packets</span>
                    </div>
                </div>
                <div class="msm-packet-summary-item">
                    <i class="fas fa-music"></i>
                    <div class="msm-packet-summary-content">
                        <span class="msm-packet-summary-value" id="summaryMediaReceived">--</span>
                        <span class="msm-packet-summary-label">Media Packets</span>
                    </div>
                </div>
                <div class="msm-packet-summary-item">
                    <i class="fas fa-terminal"></i>
                    <div class="msm-packet-summary-content">
                        <span class="msm-packet-summary-value" id="summaryCmdReceived">--</span>
                        <span class="msm-packet-summary-label">Command Packets</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Side-by-side: Sync Source + Comparison (Remote Mode) -->
    <div class="msm-remote-cards-row">
        <!-- Sync Source Card -->
        <div class="msm-sync-source-card" id="syncSourceCard">
            <div class="msm-sync-source-header">
                <i class="fas fa-crown"></i>
                <span>Sync Source</span>
            </div>
            <div class="msm-sync-source-body">
                <div class="msm-sync-source-info">
                    <div class="msm-sync-source-main">
                        <span class="msm-sync-source-label">Player</span>
                        <span class="msm-sync-source-value" id="syncSourceHostname">Searching...</span>
                    </div>
                    <div class="msm-sync-source-detail">
                        <span class="msm-sync-source-label">IP Address</span>
                        <span class="msm-sync-source-value" id="syncSourceIP">--</span>
                    </div>
                    <div class="msm-sync-source-detail">
                        <span class="msm-sync-source-label">Status</span>
                        <span class="msm-sync-source-value" id="syncSourceStatus">--</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Local vs Sync Comparison Card -->
        <div class="msm-comparison-card" id="comparisonCard">
            <div class="msm-comparison-header">
                <i class="fas fa-balance-scale"></i>
                <span>Local vs Sync Packets</span>
                <span class="msm-comparison-status" id="comparisonStatus">--</span>
            </div>
            <div class="msm-comparison-body">
                <div class="msm-comparison-row">
                    <span class="msm-comparison-label">Sequence</span>
                    <span class="msm-comparison-local" id="compLocalSeq">--</span>
                    <span class="msm-comparison-vs">=</span>
                    <span class="msm-comparison-sync" id="compSyncSeq">--</span>
                </div>
                <div class="msm-comparison-row">
                    <span class="msm-comparison-label">Status</span>
                    <span class="msm-comparison-local" id="compLocalStatus">--</span>
                    <span class="msm-comparison-vs">=</span>
                    <span class="msm-comparison-sync" id="compSyncStatus">--</span>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isPlayerMode): ?>
    <!-- Stats Summary (Player Mode Only) -->
    <div class="msm-stats-grid" id="statsGrid">
        <div class="msm-stat-card">
            <div class="msm-stat-value" id="statRemotes">--</div>
            <div class="msm-stat-label">Remotes</div>
        </div>
        <div class="msm-stat-card">
            <div class="msm-stat-value healthy" id="statOnline">--</div>
            <div class="msm-stat-label">Online</div>
        </div>
        <div class="msm-stat-card">
            <div class="msm-stat-value" id="statWithPlugin">--</div>
            <div class="msm-stat-label">With Plugin</div>
        </div>
        <div class="msm-stat-card">
            <div class="msm-stat-value" id="statIssues">0</div>
            <div class="msm-stat-label">Issues</div>
        </div>
    </div>

    <!-- Network Quality Card (Player Mode Only) -->
    <div class="msm-quality-card" id="qualityCard">
        <div class="msm-quality-header">
            <h3><i class="fas fa-signal"></i> Network Quality</h3>
            <span class="msm-quality-indicator" id="overallQuality">--</span>
        </div>
        <div class="msm-quality-grid">
            <div class="msm-quality-metric">
                <span class="msm-quality-label">Avg Latency</span>
                <span class="msm-quality-value" id="qualityLatency">--</span>
            </div>
            <div class="msm-quality-metric">
                <span class="msm-quality-label">Avg Jitter</span>
                <span class="msm-quality-value" id="qualityJitter">--</span>
            </div>
            <div class="msm-quality-metric">
                <span class="msm-quality-label">Sync Packet Loss <i class="fas fa-info-circle" style="color: #6c757d; cursor: help; margin-left: 3px; opacity: 0.7;" title="Estimated by comparing expected sync rate (based on sequence step time) with actual packets received. Best-effort detection - may not catch every lost packet. If metrics were reset, calculation is since reset."></i></span>
                <span class="msm-quality-value" id="qualityPacketLoss">--</span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isPlayerMode): ?>
    <!-- Issues Panel (Player Mode) -->
    <div class="msm-issues-panel hidden" id="issuesPanel">
        <div class="msm-issues-header" id="issuesHeader">
            <span><i class="fas fa-exclamation-triangle"></i> Issues Detected</span>
            <span id="issueCount">0</span>
        </div>
        <div class="msm-issues-body" id="issuesList"></div>
    </div>

    <!-- Remote Systems (Player Mode) -->
    <h3 class="msm-section-header"><i class="fas fa-satellite-dish"></i> Remote Systems Sync Status</h3>
    <div class="msm-remotes-grid" id="remotesGrid">
        <div class="msm-empty">
            <i class="fas fa-spinner fa-spin"></i>
            <p>Loading remote systems...</p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Network Quality Charts -->
    <?php if ($isPlayerMode): ?>
    <div class="msm-two-col">
        <div class="msm-card">
            <div class="msm-card-header">
                <h3 class="msm-card-title"><i class="fas fa-wave-square"></i> Latency & Jitter</h3>
                <select id="qualityTimeRange" class="msm-time-select" onchange="page.loadQualityCharts()">
                    <option value="1">1 Hour</option>
                    <option value="6" selected>6 Hours</option>
                    <option value="24">24 Hours</option>
                </select>
            </div>
            <div class="msm-card-body">
                <canvas id="latencyJitterChart" height="200"></canvas>
            </div>
        </div>
        <div class="msm-card">
            <div class="msm-card-header">
                <h3 class="msm-card-title"><i class="fas fa-chart-line"></i> Sync Packet Loss <i class="fas fa-info-circle" style="color: #6c757d; cursor: help; font-size: 0.75em; opacity: 0.7;" title="Estimated by comparing expected sync rate (based on sequence step time) with actual packets received. Best-effort detection - may not catch every lost packet."></i></h3>
            </div>
            <div class="msm-card-body">
                <canvas id="packetLossChart" height="200"></canvas>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isPlayerMode): ?>
    <!-- All Systems Packet Metrics Table (Player Mode) -->
    <div class="msm-card">
        <div class="msm-card-header">
            <h3 class="msm-card-title"><i class="fas fa-chart-bar"></i> System Packet Metrics</h3>
            <button class="btn btn-sm btn-outline-secondary" onclick="page.resetMetrics()">
                <i class="fas fa-undo"></i> Reset
            </button>
        </div>
        <div class="msm-packet-info">
            <i class="fas fa-info-circle"></i>
            <span>Packet counts may differ between systems. MultiSync uses UDP broadcast which does not guarantee delivery.
            Counters reset when FPP restarts, and remotes only count packets while actively listening.
            Small differences are normal; large gaps may indicate network issues.</span>
        </div>
        <div class="msm-card-body msm-table-wrapper">
            <table class="msm-packet-table" id="systemsPacketTable">
                <thead>
                    <tr>
                        <th data-sort="status" class="msm-th-sortable">Status</th>
                        <th data-sort="hostname" class="msm-th-sortable msm-th-sorted-asc">Host</th>
                        <th data-sort="type" class="msm-th-sortable">Type</th>
                        <th data-sort="mode" class="msm-th-sortable">Mode</th>
                        <th data-sort="drift" class="msm-th-sortable msm-th-right">Time Drift <i class="fas fa-info-circle" style="color: #6c757d; cursor: help; font-size: 0.75em; opacity: 0.7;" title="System clock difference from player. Small variations (±100ms) are normal due to measurement limitations. Uses NTP-style calculation that accounts for network round-trip time."></i></th>
                        <th data-sort="syncSent" class="msm-th-sortable msm-th-right" title="Sync Sent">Sync<i class="fas fa-arrow-up"></i></th>
                        <th data-sort="syncRecv" class="msm-th-sortable msm-th-right" title="Sync Received">Sync<i class="fas fa-arrow-down"></i></th>
                        <th data-sort="mediaSent" class="msm-th-sortable msm-th-right" title="Media Sent">Media<i class="fas fa-arrow-up"></i></th>
                        <th data-sort="mediaRecv" class="msm-th-sortable msm-th-right" title="Media Received">Media<i class="fas fa-arrow-down"></i></th>
                        <th data-sort="blankSent" class="msm-th-sortable msm-th-right" title="Blank Sent">Blank<i class="fas fa-arrow-up"></i></th>
                        <th data-sort="blankRecv" class="msm-th-sortable msm-th-right" title="Blank Received">Blank<i class="fas fa-arrow-down"></i></th>
                        <th data-sort="pluginSent" class="msm-th-sortable msm-th-right" title="Plugin Sent">Plugin<i class="fas fa-arrow-up"></i></th>
                        <th data-sort="pluginRecv" class="msm-th-sortable msm-th-right" title="Plugin Received">Plugin<i class="fas fa-arrow-down"></i></th>
                        <th data-sort="total" class="msm-th-sortable msm-th-right">Total</th>
                    </tr>
                </thead>
                <tbody id="systemsPacketBody">
                    <tr><td colspan="14" class="msm-td-loading"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php ViewHelpers::renderRefreshButton(); ?>
</div>
