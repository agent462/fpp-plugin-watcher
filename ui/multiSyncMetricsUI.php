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

renderCSSIncludes(true);
renderCommonJS();
?>

<div class="msm-container">
    <div class="msm-header">
        <h2><i class="fas fa-network-wired"></i> Multi-Sync Dashboard</h2>
        <div class="msm-header-right">
            <span class="msm-last-update" id="lastUpdate"></span>
            <button class="btn btn-sm btn-outline-secondary" onclick="loadAllData()">
                <i class="fas fa-sync-alt"></i> Refresh
            </button>
            <button class="btn btn-sm btn-outline-info msm-help-btn" onclick="toggleHelpTooltip()" title="Help">
                <i class="fas fa-question-circle"></i>
            </button>
        </div>

        <!-- Help Tooltip -->
        <div class="msm-help-tooltip" id="helpTooltip">
            <div class="msm-help-header">
                <span><i class="fas fa-info-circle"></i> Metrics Guide</span>
                <button class="msm-help-close" onclick="toggleHelpTooltip()">&times;</button>
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
            <button class="btn btn-sm btn-outline-secondary" onclick="resetMetrics()">
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
                <span class="msm-quality-label">Sync Packet Loss <i class="fas fa-info-circle" style="color: #6c757d; cursor: help; margin-left: 3px; opacity: 0.7;" title="Estimated by comparing expected sync rate (based on sequence step time) with actual packets received. Best-effort detection - may not catch every lost packet."></i></span>
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
                <select id="qualityTimeRange" class="msm-time-select" onchange="loadQualityCharts()">
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
            <button class="btn btn-sm btn-outline-secondary" onclick="resetMetrics()">
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

    <button class="msm-refresh-btn" onclick="loadAllData()" title="Refresh Data">
        <i class="fas fa-sync-alt"></i>
    </button>
</div>

<script>
const IS_PLAYER_MODE = <?php echo $isPlayerMode ? 'true' : 'false'; ?>;
const IS_REMOTE_MODE = <?php echo $isRemoteMode ? 'true' : 'false'; ?>;
// Filtered remote systems from PHP (single source of truth - matches comparison API)
const remoteSystems = <?php echo json_encode($remoteSystems); ?>;

// Polling intervals
const FAST_POLL_INTERVAL = 2000;  // Real-time data: 2 seconds
const SLOW_POLL_INTERVAL = 30000; // Static data: 30 seconds

let fastRefreshInterval = null;
let slowRefreshInterval = null;
let latencyJitterChart = null;
let packetLossChart = null;
let localStatus = null;
let fppSystems = [];
let systemsData = [];
let currentSort = { column: 'hostname', direction: 'asc' };
let sequenceMeta = null;
let lastSequenceName = null;
let clockDriftData = {}; // Map of address -> drift_ms

// Remote mode tracking
let lastPacketCount = 0;
let lastPacketTime = null;
let packetRate = 0;
let localFppStatus = null;

// Track if slow data has been loaded at least once
let slowDataLoaded = false;

// Consecutive failure tracking
// Only mark a host as offline after CONSECUTIVE_FAILURE_THRESHOLD consecutive failures
const CONSECUTIVE_FAILURE_THRESHOLD = 3;
let consecutiveFailures = {};  // Map of address -> failure count
let lastKnownGoodState = {};   // Map of address -> last good remote data

/**
 * Apply consecutive failure threshold to remote status
 * Similar to FPP's multisync.php which requires 4 consecutive failures before marking unreachable.
 * We use 3 failures to prevent UI flickering from transient network issues.
 *
 * @param {Array} remotes - Array of remote data from comparison API
 * @returns {Array} - Modified remotes with failure threshold applied
 */
function applyConsecutiveFailureThreshold(remotes) {
    return remotes.map(remote => {
        const addr = remote.address;

        if (remote.online) {
            // Host is online - reset failure counter and cache good state
            consecutiveFailures[addr] = 0;
            lastKnownGoodState[addr] = JSON.parse(JSON.stringify(remote));
            return remote;
        }

        // Host appears offline - increment failure counter
        consecutiveFailures[addr] = (consecutiveFailures[addr] || 0) + 1;

        // If we haven't reached threshold and have cached state, use cached state
        if (consecutiveFailures[addr] < CONSECUTIVE_FAILURE_THRESHOLD && lastKnownGoodState[addr]) {
            const cached = lastKnownGoodState[addr];
            // Keep the cached online state but mark metrics as potentially stale
            return {
                ...cached,
                _staleSinceFailure: consecutiveFailures[addr]
            };
        }

        // Threshold reached or no cached state - show as offline
        return remote;
    });
}

/**
 * Load fast-changing data (every 2 seconds)
 * - Local C++ plugin status
 * - Local FPP status
 * - Local issues
 * - Remote comparison (real-time sync metrics)
 * - Network quality current status
 */
async function loadFastData() {
    try {
        document.querySelector('.msm-refresh-btn i').classList.add('fa-spin');

        // Load local status from C++ plugin and FPP status in parallel
        const [statusResp, fppStatusResp] = await Promise.all([
            fetch('/api/plugin-apis/fpp-plugin-watcher/multisync/status'),
            fetch('/api/fppd/status')
        ]);

        if (!statusResp.ok) {
            showPluginError('C++ plugin not responding. Restart FPP to load the plugin.');
            return;
        }

        const status = await statusResp.json();
        localStatus = status;

        // Parse local FPP status (for remote mode frame display)
        if (fppStatusResp.ok) {
            localFppStatus = await fppStatusResp.json();
        }

        hidePluginError();
        await updateSystemCard(status);
        updateLifecycleMetrics(status);

        // Load issues from C++ plugin
        const issuesResp = await fetch('/api/plugin-apis/fpp-plugin-watcher/multisync/issues');
        let localIssues = [];
        if (issuesResp.ok) {
            const issuesData = await issuesResp.json();
            localIssues = issuesData.issues || [];
        }

        // If player mode, also load comparison data (which will render systems table)
        if (IS_PLAYER_MODE) {
            await loadComparison(localIssues);
            // Load current network quality (not history)
            await loadNetworkQualityCurrent();
        } else {
            // Remote mode - show local issues, packet summary, and remote-specific cards
            updateIssues(localIssues);
            updatePacketSummary(status);
            updateSyncSource();
            updateSyncHealth(status, localIssues);
            updatePacketRate(status);
            await updateLocalComparison(status);
        }

        updateLastRefresh();
    } catch (e) {
        console.error('Error loading fast data:', e);
        showPluginError('Error connecting to multi-sync plugin: ' + e.message);
    } finally {
        document.querySelector('.msm-refresh-btn i').classList.remove('fa-spin');
    }
}

/**
 * Load slow-changing data (every 30 seconds)
 * - FPP systems list (rarely changes)
 * - Clock drift data (changes slowly)
 * - Network quality history/charts
 */
async function loadSlowData() {
    try {
        if (IS_PLAYER_MODE) {
            // Load FPP systems, clock drift, and chart data in parallel
            const [systemsResp, clockDriftPromise, chartsPromise] = await Promise.all([
                fetch('/api/fppd/multiSyncSystems'),
                loadClockDrift(),
                loadQualityCharts()
            ]);

            // Parse FPP systems
            if (systemsResp.ok) {
                const sysData = await systemsResp.json();
                fppSystems = sysData.systems || [];
            }
        } else {
            // Remote mode - just load FPP systems for sync source detection
            const systemsResp = await fetch('/api/fppd/multiSyncSystems');
            if (systemsResp.ok) {
                const sysData = await systemsResp.json();
                fppSystems = sysData.systems || [];
            }
        }

        slowDataLoaded = true;
    } catch (e) {
        console.error('Error loading slow data:', e);
    }
}

/**
 * Load all data (initial load and manual refresh)
 */
async function loadAllData() {
    // Load slow data first if not yet loaded, then fast data
    if (!slowDataLoaded) {
        await loadSlowData();
    }
    await loadFastData();
}

async function updateSystemCard(status) {
    document.getElementById('systemHostname').textContent = '<?php echo htmlspecialchars($localSystem['host_name'] ?? gethostname()); ?>';

    const seqName = status.currentMasterSequence || '';
    const displayName = seqName.replace(/\.fseq$/i, '') || 'None';
    document.getElementById('systemSequence').textContent = displayName;
    document.getElementById('systemStatus').textContent = status.sequencePlaying ? 'Playing' : 'Idle';

    // Fetch sequence metadata if sequence changed (remotes have sequences too)
    if (seqName && seqName !== lastSequenceName) {
        lastSequenceName = seqName;
        try {
            const metaResp = await fetch(`/api/sequence/${encodeURIComponent(seqName)}/meta`);
            if (metaResp.ok) {
                sequenceMeta = await metaResp.json();
            } else {
                sequenceMeta = null;
            }
        } catch (e) {
            sequenceMeta = null;
        }
    } else if (!seqName) {
        sequenceMeta = null;
        lastSequenceName = null;
    }

    // Determine if actively syncing
    // For player: use sequencePlaying flag
    // For remote: check if receiving sync packets recently (within 5 seconds)
    const isActivelySyncing = IS_PLAYER_MODE
        ? status.sequencePlaying
        : (status.secondsSinceLastSync !== undefined && status.secondsSinceLastSync >= 0 && status.secondsSinceLastSync < 5);

    // Frame counter: current / total
    // Use localCurrentFrame from C++ plugin - this is the actual frame being played
    // Falls back to lastMasterFrame (from sync packets) if localCurrentFrame not available
    const frame = status.localCurrentFrame !== undefined && status.localCurrentFrame >= 0
        ? status.localCurrentFrame
        : (status.lastMasterFrame || 0);
    const totalFrames = sequenceMeta?.NumFrames || 0;
    if (isActivelySyncing && totalFrames > 0) {
        document.getElementById('systemFrame').textContent = `${frame.toLocaleString()} / ${totalFrames.toLocaleString()}`;
    } else if (isActivelySyncing && frame > 0) {
        document.getElementById('systemFrame').textContent = frame.toLocaleString();
    } else {
        document.getElementById('systemFrame').textContent = '--';
    }

    // Time: elapsed / total
    // For remote mode, use local FPP status seconds (actual playing time)
    // For player mode, use lastMasterSeconds from sync status
    const secs = IS_REMOTE_MODE && localFppStatus?.seconds_played !== undefined
        ? localFppStatus.seconds_played
        : (status.lastMasterSeconds || 0);
    const stepTime = sequenceMeta?.StepTime || 25;
    const totalSecs = totalFrames > 0 ? (totalFrames * stepTime / 1000) : 0;
    if (isActivelySyncing && totalSecs > 0) {
        document.getElementById('systemTime').textContent = `${formatTime(secs)} / ${formatTime(totalSecs)}`;
    } else if (isActivelySyncing && secs > 0) {
        document.getElementById('systemTime').textContent = formatTime(secs);
    } else {
        document.getElementById('systemTime').textContent = '--';
    }

    // Sequence metadata - show step time with calculated FPS (both modes)
    if (sequenceMeta) {
        const fps = Math.round(1000 / sequenceMeta.StepTime);
        document.getElementById('systemStepTime').textContent = `${sequenceMeta.StepTime}ms (${fps}fps)`;
    } else {
        document.getElementById('systemStepTime').textContent = '--';
    }

    // Player mode only fields
    if (IS_PLAYER_MODE) {
        document.getElementById('systemChannels').textContent = sequenceMeta ? sequenceMeta.ChannelCount.toLocaleString() : '--';
        document.getElementById('systemPacketsSent').textContent = (status.totalPacketsSent || 0).toLocaleString();
    }

    document.getElementById('systemPacketsReceived').textContent = (status.totalPacketsReceived || 0).toLocaleString();

    // Remote mode drift fields (only show when actively syncing)
    if (IS_REMOTE_MODE) {
        const avgDriftEl = document.getElementById('systemAvgDrift');
        if (avgDriftEl) {
            avgDriftEl.textContent = isActivelySyncing && status.avgFrameDrift !== undefined ? status.avgFrameDrift.toFixed(1) + ' frames' : '--';
        }
        const maxDriftEl = document.getElementById('systemMaxDrift');
        if (maxDriftEl) {
            const maxDrift = isActivelySyncing && status.maxFrameDrift !== undefined ? Math.abs(status.maxFrameDrift) : null;
            maxDriftEl.textContent = maxDrift !== null ? maxDrift.toFixed(1) + ' frames' : '--';
        }

        // Sync packet interval and jitter (only show when actively syncing)
        const syncIntervalEl = document.getElementById('systemSyncInterval');
        if (syncIntervalEl) {
            if (isActivelySyncing && status.avgSyncIntervalMs !== undefined && status.syncIntervalSamples > 0) {
                syncIntervalEl.textContent = status.avgSyncIntervalMs.toFixed(0) + 'ms';
            } else {
                syncIntervalEl.textContent = '--';
            }
        }
        const syncJitterEl = document.getElementById('systemSyncJitter');
        if (syncJitterEl) {
            if (isActivelySyncing && status.syncIntervalJitterMs !== undefined && status.syncIntervalSamples > 0) {
                const jitter = status.syncIntervalJitterMs;
                syncJitterEl.textContent = jitter.toFixed(1) + 'ms';
                // Color code: good <20ms, fair 20-50ms, poor >50ms
                syncJitterEl.classList.remove('status-good', 'status-warning', 'status-critical');
                if (jitter < 20) {
                    syncJitterEl.classList.add('status-good');
                } else if (jitter < 50) {
                    syncJitterEl.classList.add('status-warning');
                } else {
                    syncJitterEl.classList.add('status-critical');
                }
            } else {
                syncJitterEl.textContent = '--';
                syncJitterEl.classList.remove('status-good', 'status-warning', 'status-critical');
            }
        }
    }

    document.getElementById('systemLastSync').textContent = formatTimeSinceMs(status.millisecondsSinceLastSync);
}

function formatTime(seconds) {
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return `${mins}:${secs.toString().padStart(2, '0')}`;
}

function updateLifecycleMetrics(status) {
    const sent = status.packetsSent || {};
    const recv = status.packetsReceived || {};

    // Update lifecycle metrics
    const lc = status.lifecycle || {};
    document.getElementById('lcSeqOpen').textContent = (lc.seqOpen || 0).toLocaleString();
    document.getElementById('lcSeqStart').textContent = (lc.seqStart || 0).toLocaleString();
    document.getElementById('lcSeqStop').textContent = (lc.seqStop || 0).toLocaleString();
    document.getElementById('lcMediaOpen').textContent = (lc.mediaOpen || 0).toLocaleString();
    document.getElementById('lcMediaStart').textContent = (lc.mediaStart || 0).toLocaleString();
    document.getElementById('lcMediaStop').textContent = (lc.mediaStop || 0).toLocaleString();

    // Show related packet counts (sent + received for player, received only for remote)
    const syncTotal = IS_REMOTE_MODE ? (recv.sync || 0) : (sent.sync || 0) + (recv.sync || 0);
    const mediaTotal = IS_REMOTE_MODE ? (recv.mediaSync || 0) : (sent.mediaSync || 0) + (recv.mediaSync || 0);
    const blankTotal = IS_REMOTE_MODE ? (recv.blank || 0) : (sent.blank || 0) + (recv.blank || 0);
    const cmdTotal = IS_REMOTE_MODE ? (recv.command || 0) : (sent.command || 0) + (recv.command || 0);
    const pluginTotal = IS_REMOTE_MODE ? (recv.plugin || 0) : (sent.plugin || 0) + (recv.plugin || 0);
    document.getElementById('lcSyncPackets').textContent = syncTotal.toLocaleString();
    document.getElementById('lcMediaPackets').textContent = mediaTotal.toLocaleString();
    document.getElementById('lcBlankPackets').textContent = blankTotal.toLocaleString();
    document.getElementById('lcCmdPackets').textContent = cmdTotal.toLocaleString();
    document.getElementById('lcPluginPackets').textContent = pluginTotal.toLocaleString();
}

// Remote mode: Update packet summary card
function updatePacketSummary(status) {
    const recv = status.packetsReceived || {};
    const totalReceived = status.totalPacketsReceived || 0;

    document.getElementById('summaryTotalReceived').textContent = totalReceived.toLocaleString();
    document.getElementById('summarySyncReceived').textContent = (recv.sync || 0).toLocaleString();
    document.getElementById('summaryMediaReceived').textContent = (recv.mediaSync || 0).toLocaleString();
    document.getElementById('summaryCmdReceived').textContent = (recv.command || 0).toLocaleString();
}

// Remote mode: Update sync source card
function updateSyncSource() {
    const hostnameEl = document.getElementById('syncSourceHostname');
    const ipEl = document.getElementById('syncSourceIP');
    const statusEl = document.getElementById('syncSourceStatus');

    if (!hostnameEl) return;

    // Find player system from fppSystems
    const player = fppSystems.find(s => s.fppModeString === 'player' || s.fppMode === 2);

    if (player) {
        hostnameEl.textContent = player.hostname || 'Unknown';
        ipEl.textContent = player.address || '--';

        // Check if player was recently seen (within 60 seconds)
        const lastSeen = player.lastSeen ? new Date(player.lastSeen * 1000) : null;
        const now = new Date();
        const secondsAgo = lastSeen ? Math.floor((now - lastSeen) / 1000) : null;

        if (secondsAgo !== null && secondsAgo < 60) {
            statusEl.textContent = 'Online';
            statusEl.className = 'msm-sync-source-value status-good';
        } else if (secondsAgo !== null) {
            statusEl.textContent = `Last seen ${secondsAgo}s ago`;
            statusEl.className = 'msm-sync-source-value status-warning';
        } else {
            statusEl.textContent = 'Unknown';
            statusEl.className = 'msm-sync-source-value';
        }
    } else {
        hostnameEl.textContent = 'No player found';
        ipEl.textContent = '--';
        statusEl.textContent = 'Not detected';
        statusEl.className = 'msm-sync-source-value status-warning';
    }
}

// Remote mode: Update sync health badge
function updateSyncHealth(status, issues) {
    const badge = document.getElementById('syncHealthBadge');
    if (!badge) return;

    const icon = badge.querySelector('i');
    const text = badge.querySelector('span');

    const secondsSinceSync = status.secondsSinceLastSync ?? -1;
    const avgDrift = Math.abs(status.avgFrameDrift ?? 0);
    const hasIssues = issues && issues.length > 0;
    const hasCriticalIssues = issues && issues.some(i => i.severity >= 3);
    // Check if player is actively playing (from sync packet data)
    const isPlayerPlaying = status.sequencePlaying === true;

    let health = 'good';
    let healthText = 'Healthy';

    // Only flag old sync packets as an issue if the player is actively playing
    // When player is idle, not receiving sync packets is expected behavior
    const syncPacketIssue = isPlayerPlaying && secondsSinceSync > 30;
    const syncPacketWarning = isPlayerPlaying && secondsSinceSync > 10;

    if (hasCriticalIssues || syncPacketIssue || avgDrift > 10) {
        health = 'critical';
        healthText = 'Critical';
    } else if (hasIssues || syncPacketWarning || avgDrift > 5) {
        health = 'warning';
        healthText = 'Warning';
    } else if (secondsSinceSync < 0 || status.totalPacketsReceived === 0) {
        health = 'unknown';
        healthText = 'No Data';
    }

    badge.className = `msm-sync-health msm-sync-health-${health}`;
    text.textContent = healthText;
}

// Remote mode: Update packet rate
function updatePacketRate(status) {
    const rateEl = document.getElementById('systemPacketRate');
    if (!rateEl) return;

    const currentCount = status.totalPacketsReceived || 0;
    const now = Date.now();

    if (lastPacketTime !== null && lastPacketCount > 0) {
        const elapsed = (now - lastPacketTime) / 1000; // seconds
        if (elapsed > 0) {
            const packets = currentCount - lastPacketCount;
            packetRate = packets / elapsed;
        }
    }

    lastPacketCount = currentCount;
    lastPacketTime = now;

    if (packetRate > 0) {
        rateEl.textContent = `${packetRate.toFixed(1)}/sec`;
    } else {
        rateEl.textContent = '--';
    }
}

// Remote mode: Update local vs sync comparison
async function updateLocalComparison(status) {
    const localSeqEl = document.getElementById('compLocalSeq');
    const syncSeqEl = document.getElementById('compSyncSeq');
    const localStatusEl = document.getElementById('compLocalStatus');
    const syncStatusEl = document.getElementById('compSyncStatus');
    const compStatusEl = document.getElementById('comparisonStatus');

    if (!localSeqEl) return;

    // Fetch local FPP status
    try {
        const resp = await fetch('/api/fppd/status');
        if (resp.ok) {
            localFppStatus = await resp.json();
        }
    } catch (e) {
        console.error('Error fetching local FPP status:', e);
    }

    // Sync packet data
    const syncSeq = (status.currentMasterSequence || '').replace(/\.fseq$/i, '') || '(none)';
    const syncPlaying = status.sequencePlaying ? 'Playing' : 'Idle';

    syncSeqEl.textContent = syncSeq;
    syncStatusEl.textContent = syncPlaying;

    // Local FPP data
    let localSeq = '(none)';
    let localPlaying = 'Idle';
    let mismatches = 0;

    if (localFppStatus) {
        localSeq = (localFppStatus.current_sequence || '').replace(/\.fseq$/i, '') || '(none)';
        localPlaying = localFppStatus.status_name === 'playing' ? 'Playing' : 'Idle';
    }

    localSeqEl.textContent = localSeq;
    localStatusEl.textContent = localPlaying;

    // Check for mismatches (sequence and status only - media is optional for remotes)
    const seqMatch = localSeq === syncSeq || (localSeq === '(none)' && syncSeq === '(none)');
    const statusMatch = localPlaying === syncPlaying;

    // Update vs indicators
    updateComparisonVs('compLocalSeq', 'compSyncSeq', seqMatch);
    updateComparisonVs('compLocalStatus', 'compSyncStatus', statusMatch);

    if (!seqMatch) mismatches++;
    if (!statusMatch) mismatches++;

    // Update overall status
    if (mismatches === 0) {
        compStatusEl.textContent = 'Match';
        compStatusEl.className = 'msm-comparison-status status-good';
    } else {
        compStatusEl.textContent = `${mismatches} Mismatch${mismatches > 1 ? 'es' : ''}`;
        compStatusEl.className = 'msm-comparison-status status-critical';
    }
}

function updateComparisonVs(localId, syncId, match) {
    const localEl = document.getElementById(localId);
    const syncEl = document.getElementById(syncId);

    if (match) {
        localEl.classList.remove('mismatch');
        syncEl.classList.remove('mismatch');
        // Find and update the vs element
        const row = localEl.closest('.msm-comparison-row');
        if (row) {
            const vs = row.querySelector('.msm-comparison-vs');
            if (vs) {
                vs.textContent = '=';
                vs.classList.remove('mismatch');
            }
        }
    } else {
        localEl.classList.add('mismatch');
        syncEl.classList.add('mismatch');
        const row = localEl.closest('.msm-comparison-row');
        if (row) {
            const vs = row.querySelector('.msm-comparison-vs');
            if (vs) {
                vs.textContent = '≠';
                vs.classList.add('mismatch');
            }
        }
    }
}

async function loadComparison(localIssues) {
    try {
        const resp = await fetch('/api/plugin/fpp-plugin-watcher/multisync/comparison');
        if (!resp.ok) return;

        const data = await resp.json();
        if (!data.success) return;

        // Apply consecutive failure threshold to remotes
        // This prevents UI flickering from transient network issues
        const remotes = applyConsecutiveFailureThreshold(data.remotes);

        // Filter issues to exclude offline issues for hosts not yet at threshold
        const filteredIssues = data.issues.filter(issue => {
            if (issue.type === 'offline') {
                // Find the corresponding remote to check its failure count
                const remote = remotes.find(r => r.hostname === issue.host || r.address === issue.host);
                // Keep the issue only if the host is actually shown as offline
                return remote && !remote.online;
            }
            return true;
        });

        // Combine local issues with filtered comparison issues
        const allIssues = [...localIssues, ...filteredIssues];

        // Recalculate stats based on filtered remotes
        const onlineCount = remotes.filter(r => r.online).length;
        const pluginInstalledCount = remotes.filter(r => r.pluginInstalled).length;
        updateStats(remotes.length, onlineCount, pluginInstalledCount, allIssues.length);

        // Update issues
        updateIssues(allIssues);

        // Render remote cards
        renderRemoteCards(remotes);

        // Render systems packet metrics table (local + remotes)
        renderSystemsPacketTable(remotes, localStatus);
    } catch (e) {
        console.error('Error loading comparison:', e);
    }
}

function updateStats(total, online, withPlugin, issues) {
    document.getElementById('statRemotes').textContent = total;
    document.getElementById('statOnline').textContent = online;
    document.getElementById('statWithPlugin').textContent = withPlugin;

    const issuesEl = document.getElementById('statIssues');
    issuesEl.textContent = issues;
    issuesEl.className = 'msm-stat-value';
    if (issues > 0) issuesEl.classList.add('warning');
}

function updateIssues(issues) {
    const panel = document.getElementById('issuesPanel');
    const header = document.getElementById('issuesHeader');
    const list = document.getElementById('issuesList');

    if (!issues || issues.length === 0) {
        panel.classList.add('hidden');
        return;
    }

    panel.classList.remove('hidden');
    document.getElementById('issueCount').textContent = issues.length;

    const hasCritical = issues.some(i => i.severity >= 3);
    header.classList.toggle('critical', hasCritical);

    list.innerHTML = issues.map(issue => {
        const iconClass = issue.severity === 3 ? 'critical' : (issue.severity === 2 ? 'warning' : 'info');
        const icon = issue.severity === 3 ? 'times-circle' : (issue.severity === 2 ? 'exclamation-triangle' : 'info-circle');

        let details = '';
        if (issue.expected && issue.actual) {
            details = `Expected: ${issue.expected}, Actual: ${issue.actual}`;
        } else if (issue.maxDrift !== undefined) {
            details = `Max: ${issue.maxDrift} frames, Avg: ${issue.avgDrift} frames`;
        } else if (issue.secondsSinceSync !== undefined) {
            details = `Last sync: ${issue.secondsSinceSync}s ago`;
        }

        return `
            <div class="msm-issue-item">
                <div class="msm-issue-icon ${iconClass}"><i class="fas fa-${icon}"></i></div>
                <div class="msm-issue-content">
                    <div class="msm-issue-host">${escapeHtml(issue.host || 'Local')}</div>
                    <div class="msm-issue-description">${escapeHtml(issue.description)}</div>
                    ${details ? `<div class="msm-issue-details">${escapeHtml(details)}</div>` : ''}
                </div>
            </div>
        `;
    }).join('');
}

function renderRemoteCards(remotes) {
    const grid = document.getElementById('remotesGrid');
    if (!grid) return;

    if (!remotes || remotes.length === 0) {
        grid.innerHTML = `
            <div class="msm-empty" style="grid-column: 1/-1;">
                <i class="fas fa-satellite-dish"></i>
                <p>No remote systems found in multi-sync configuration</p>
            </div>
        `;
        return;
    }

    grid.innerHTML = remotes.map(remote => {
        let cardClass = 'msm-remote-card';
        let badge = '';

        if (!remote.online) {
            cardClass += ' offline';
            badge = '<span class="msm-remote-badge offline">Offline</span>';
        } else if (!remote.pluginInstalled) {
            cardClass += ' no-plugin';
            badge = '<span class="msm-remote-badge no-plugin">No Plugin</span>';
        } else {
            badge = '<span class="msm-remote-badge online">Online</span>';
        }

        if (remote.maxSeverity >= 3) cardClass += ' critical';
        else if (remote.maxSeverity >= 2) cardClass += ' has-issues';

        const m = remote.metrics || {};
        const fpp = remote.fppStatus || {};
        let metricsHtml = '';

        if (remote.pluginInstalled && remote.online) {
            // Use FPP status for actual sequence/status, watcher plugin for frame
            const actualSeq = fpp.sequence || m.currentMasterSequence || '--';
            const actualStatus = fpp.status || (m.sequencePlaying ? 'playing' : 'idle');
            const statusDisplay = actualStatus === 'playing' ? 'Playing' : 'Idle';
            // Use localCurrentFrame from watcher plugin - this is the remote's actual playing frame
            // Falls back to lastMasterFrame (from sync packets) for systems without updated plugin
            const frameValue = m.localCurrentFrame !== undefined && m.localCurrentFrame >= 0
                ? m.localCurrentFrame
                : m.lastMasterFrame;
            const currentFrame = (actualStatus === 'playing' && frameValue !== undefined) ? frameValue.toLocaleString() : '--';
            const pkts = m.totalPacketsReceived !== undefined ? m.totalPacketsReceived.toLocaleString() : '--';
            const lastSync = m.millisecondsSinceLastSync !== undefined ? formatTimeSinceMs(m.millisecondsSinceLastSync) : '--';

            // Check for missing sequence scenario
            const syncSaysPlaying = m.sequencePlaying;
            const actuallyPlaying = actualStatus === 'playing';

            // Drift metrics (only show when playing)
            const avgDriftNum = actuallyPlaying && m.avgFrameDrift !== undefined ? Math.abs(m.avgFrameDrift) : null;
            const avgDrift = avgDriftNum !== null ? avgDriftNum.toFixed(1) : '--';
            const maxDrift = actuallyPlaying && m.maxFrameDrift !== undefined ? Math.abs(m.maxFrameDrift) : null;

            // Sync interval and jitter metrics (only show when playing)
            const syncInterval = actuallyPlaying && m.avgSyncIntervalMs !== undefined && m.syncIntervalSamples > 0
                ? m.avgSyncIntervalMs.toFixed(0) + 'ms' : '--';
            const syncJitter = actuallyPlaying && m.syncIntervalJitterMs !== undefined && m.syncIntervalSamples > 0
                ? m.syncIntervalJitterMs.toFixed(1) + 'ms' : '--';
            // Calculate sync packet rate from interval (sequence sync only, not media)
            const syncRate = actuallyPlaying && m.avgSyncIntervalMs !== undefined && m.avgSyncIntervalMs > 0 && m.syncIntervalSamples > 0
                ? (1000 / m.avgSyncIntervalMs).toFixed(1) + '/s' : '--';
            let syncJitterClass = '';
            if (actuallyPlaying && m.syncIntervalJitterMs !== undefined && m.syncIntervalSamples > 0) {
                if (m.syncIntervalJitterMs < 20) syncJitterClass = 'good';
                else if (m.syncIntervalJitterMs < 50) syncJitterClass = 'warning';
                else syncJitterClass = 'critical';
            }

            // Use avg drift for alerting (max can spike on FPP restart)
            let driftClass = '';
            if (avgDriftNum !== null) {
                if (avgDriftNum > 10) driftClass = 'critical';
                else if (avgDriftNum > 5) driftClass = 'warning';
                else driftClass = 'good';
            }
            let statusClass = actuallyPlaying ? 'good' : '';
            if (syncSaysPlaying && !actuallyPlaying) {
                statusClass = 'critical'; // Sync says playing but FPP isn't
            }

            metricsHtml = `
                <div class="msm-remote-metrics">
                    <div><span class="msm-remote-metric-label">Received Sequence</span><br><span class="msm-remote-metric-value">${escapeHtml(actualSeq) || '(none)'}</span></div>
                    <div><span class="msm-remote-metric-label">Status</span><br><span class="msm-remote-metric-value ${statusClass}">${statusDisplay}</span></div>
                    <div><span class="msm-remote-metric-label">Packets Recv</span><br><span class="msm-remote-metric-value">${pkts}</span></div>
                    <div><span class="msm-remote-metric-label">Frame</span><br><span class="msm-remote-metric-value msm-mono">${currentFrame}</span></div>
                    <div><span class="msm-remote-metric-label">Seq Sync Rate</span><br><span class="msm-remote-metric-value">${syncRate}</span></div>
                    <div><span class="msm-remote-metric-label">Seq Sync Interval</span><br><span class="msm-remote-metric-value">${syncInterval}</span></div>
                    <div><span class="msm-remote-metric-label">Seq Sync Jitter</span><br><span class="msm-remote-metric-value ${syncJitterClass}">${syncJitter}</span></div>
                    <div><span class="msm-remote-metric-label">Last Sync</span><br><span class="msm-remote-metric-value">${lastSync}</span></div>
                    <div><span class="msm-remote-metric-label">Avg Frame Drift</span><br><span class="msm-remote-metric-value ${driftClass}">${avgDrift}f</span></div>
                    <div><span class="msm-remote-metric-label">Max Frame Drift</span><br><span class="msm-remote-metric-value ${driftClass}">${maxDrift !== null ? maxDrift + 'f' : '--'}</span></div>
                </div>
            `;
        } else if (remote.online) {
            metricsHtml = `<div class="msm-remote-message"><i class="fas fa-info-circle"></i> Watcher plugin not installed</div>`;
        } else {
            metricsHtml = `<div class="msm-remote-message"><i class="fas fa-plug"></i> Unreachable</div>`;
        }

        let issuesHtml = '';
        if (remote.issues && remote.issues.length > 0 && remote.issues[0].type !== 'no_plugin') {
            const issueClass = remote.maxSeverity >= 3 ? 'critical' : '';
            const texts = remote.issues.filter(i => i.type !== 'no_plugin').map(i => i.description).join('; ');
            if (texts) {
                issuesHtml = `<div class="msm-remote-issues ${issueClass}"><i class="fas fa-exclamation-triangle"></i> ${escapeHtml(texts)}</div>`;
            }
        }

        return `
            <div class="${cardClass}">
                <div class="msm-remote-header">
                    <div>
                        <div class="msm-remote-hostname">${escapeHtml(remote.hostname)}</div>
                        <div class="msm-remote-address"><a href="http://${escapeHtml(remote.address)}/" target="_blank" class="msm-host-link">${escapeHtml(remote.address)}</a></div>
                    </div>
                    ${badge}
                </div>
                <div class="msm-remote-body">${metricsHtml}</div>
                ${issuesHtml}
            </div>
        `;
    }).join('');
}

function renderSystemsPacketTable(remotes, local) {
    const tbody = document.getElementById('systemsPacketBody');
    if (!tbody) return;

    // Build data array for sorting
    systemsData = [];

    // Find local system in fppSystems to get type info
    const localFpp = fppSystems.find(s => s.local === 1) || {};
    const localSent = local?.packetsSent || {};
    const localRecv = local?.packetsReceived || {};
    const localHostname = '<?php echo htmlspecialchars($localSystem['host_name'] ?? gethostname()); ?>';

    // Add local system (player is the time reference)
    systemsData.push({
        isLocal: true,
        status: 'local',
        hostname: localHostname,
        address: localFpp.address || '127.0.0.1',
        type: localFpp.type || 'FPP',
        mode: IS_PLAYER_MODE ? 'Player' : (IS_REMOTE_MODE ? 'Remote' : localFpp.fppModeString || '--'),
        syncSent: localSent.sync || 0,
        syncRecv: localRecv.sync || 0,
        mediaSent: localSent.mediaSync || 0,
        mediaRecv: localRecv.mediaSync || 0,
        blankSent: localSent.blank || 0,
        blankRecv: localRecv.blank || 0,
        pluginSent: localSent.plugin || 0,
        pluginRecv: localRecv.plugin || 0,
        hasMetrics: true
    });

    // Add remote systems from comparison data
    if (remotes && remotes.length > 0) {
        remotes.forEach(remote => {
            const fppInfo = fppSystems.find(s => s.address === remote.address) || {};
            const m = remote.metrics || {};
            const sent = m.packetsSent || {};
            const recv = m.packetsReceived || {};

            systemsData.push({
                isLocal: false,
                status: !remote.online ? 'offline' : (!remote.pluginInstalled ? 'no-plugin' : 'online'),
                hostname: remote.hostname || fppInfo.hostname || remote.address,
                address: remote.address,
                type: fppInfo.type || '--',
                mode: fppInfo.fppModeString || '--',
                syncSent: sent.sync || 0,
                syncRecv: recv.sync || 0,
                mediaSent: sent.mediaSync || 0,
                mediaRecv: recv.mediaSync || 0,
                blankSent: sent.blank || 0,
                blankRecv: recv.blank || 0,
                pluginSent: sent.plugin || 0,
                pluginRecv: recv.plugin || 0,
                hasMetrics: remote.online && remote.pluginInstalled
            });
        });
    }

    // Add any FPP systems not in comparison (bridge devices, etc.)
    fppSystems.forEach(sys => {
        if (sys.local === 1) return;
        if (systemsData.some(s => s.address === sys.address)) return;

        systemsData.push({
            isLocal: false,
            status: 'unknown',
            hostname: sys.hostname,
            address: sys.address,
            type: sys.type || '--',
            mode: sys.fppModeString || '--',
            drift: null,
            driftFrames: null,
            syncSent: 0, syncRecv: 0, mediaSent: 0, mediaRecv: 0, blankSent: 0, blankRecv: 0, pluginSent: 0, pluginRecv: 0,
            hasMetrics: false
        });
    });

    sortAndRenderTable();
    setupTableSorting();
}

function sortAndRenderTable() {
    const tbody = document.getElementById('systemsPacketBody');
    if (!tbody || systemsData.length === 0) return;

    // Sort data
    const col = currentSort.column;
    const dir = currentSort.direction === 'asc' ? 1 : -1;

    systemsData.sort((a, b) => {
        // Local always first
        if (a.isLocal && !b.isLocal) return -1;
        if (!a.isLocal && b.isLocal) return 1;

        let valA = a[col];
        let valB = b[col];

        // Status ordering: local > online > no-plugin > offline > unknown
        if (col === 'status') {
            const order = { local: 0, online: 1, 'no-plugin': 2, offline: 3, unknown: 4 };
            valA = order[valA] ?? 5;
            valB = order[valB] ?? 5;
        }

        // Drift sorting uses clock drift data
        if (col === 'drift') {
            const driftA = clockDriftData[a.address]?.drift_ms ?? null;
            const driftB = clockDriftData[b.address]?.drift_ms ?? null;
            // Put nulls at end
            if (driftA === null && driftB === null) return 0;
            if (driftA === null) return 1;
            if (driftB === null) return -1;
            return dir * (Math.abs(driftA) - Math.abs(driftB));
        }

        if (typeof valA === 'string') {
            return dir * valA.localeCompare(valB);
        }
        return dir * ((valA ?? 0) - (valB ?? 0));
    });

    // Render rows
    tbody.innerHTML = systemsData.map(row => {
        const statusClass = row.isLocal ? 'msm-status-local' :
            (row.status === 'online' ? 'msm-status-online' :
             row.status === 'offline' ? 'msm-status-offline' :
             row.status === 'no-plugin' ? 'msm-status-noplugin' : 'msm-status-unknown');

        const statusLabel = row.isLocal ? 'Local' :
            (row.status === 'online' ? 'Online' :
             row.status === 'offline' ? 'Offline' :
             row.status === 'no-plugin' ? 'No Plugin' : '--');

        const total = row.syncSent + row.syncRecv + row.mediaSent + row.mediaRecv + row.blankSent + row.blankRecv + row.pluginSent + row.pluginRecv;

        const dimClass = !row.hasMetrics && !row.isLocal ? 'msm-row-dim' : '';

        // Format clock drift display with color coding
        // Clock drift is measured from the clock-drift API (actual system time difference)
        let driftDisplay = '--';
        let driftClass = '';
        const clockData = clockDriftData[row.address];

        if (row.isLocal) {
            driftDisplay = '<span class="msm-drift-ref">ref</span>';
        } else if (clockData && clockData.drift_ms !== null) {
            const driftMs = clockData.drift_ms;
            const absMs = Math.abs(driftMs);
            const sign = driftMs >= 0 ? '+' : '';
            // Color code thresholds - account for network measurement variance
            // Green: <100ms (well within acceptable range)
            // Yellow: 100ms-1s (noticeable but may not cause issues)
            // Red: >1s (likely to cause sync problems)
            if (absMs < 100) driftClass = 'msm-drift-good';
            else if (absMs < 1000) driftClass = 'msm-drift-fair';
            else driftClass = 'msm-drift-poor';
            const rttTitle = clockData.rtt_ms ? `RTT: ${clockData.rtt_ms}ms` : '';
            driftDisplay = `<span class="${driftClass}" title="${rttTitle}">${sign}${driftMs}ms</span>`;
        } else if (row.hasMetrics) {
            // Has plugin but no clock data yet
            driftDisplay = '<span class="msm-drift-pending">...</span>';
        }

        // Build hostname cell - clickable link for remote systems, plain text for local
        let hostnameCell;
        if (row.isLocal) {
            hostnameCell = `<i class="fas fa-home msm-home-icon"></i>${escapeHtml(row.hostname)}`;
        } else {
            hostnameCell = `<a href="http://${escapeHtml(row.address)}/" target="_blank" class="msm-host-link" title="${escapeHtml(row.address)}">${escapeHtml(row.hostname)}</a>`;
        }

        return `<tr class="${dimClass}">
            <td><span class="msm-status-badge ${statusClass}">${statusLabel}</span></td>
            <td>${hostnameCell}</td>
            <td>${escapeHtml(row.type)}</td>
            <td>${escapeHtml(row.mode)}</td>
            <td class="msm-td-num">${driftDisplay}</td>
            <td class="msm-td-num msm-td-sent">${row.hasMetrics ? row.syncSent.toLocaleString() : '--'}</td>
            <td class="msm-td-num msm-td-recv">${row.hasMetrics ? row.syncRecv.toLocaleString() : '--'}</td>
            <td class="msm-td-num msm-td-sent">${row.hasMetrics ? row.mediaSent.toLocaleString() : '--'}</td>
            <td class="msm-td-num msm-td-recv">${row.hasMetrics ? row.mediaRecv.toLocaleString() : '--'}</td>
            <td class="msm-td-num msm-td-sent">${row.hasMetrics ? row.blankSent.toLocaleString() : '--'}</td>
            <td class="msm-td-num msm-td-recv">${row.hasMetrics ? row.blankRecv.toLocaleString() : '--'}</td>
            <td class="msm-td-num msm-td-sent">${row.hasMetrics ? row.pluginSent.toLocaleString() : '--'}</td>
            <td class="msm-td-num msm-td-recv">${row.hasMetrics ? row.pluginRecv.toLocaleString() : '--'}</td>
            <td class="msm-td-num msm-td-total">${row.hasMetrics ? total.toLocaleString() : '--'}</td>
        </tr>`;
    }).join('');
}

function setupTableSorting() {
    const table = document.getElementById('systemsPacketTable');
    if (!table) return;

    table.querySelectorAll('th[data-sort]').forEach(th => {
        th.onclick = () => {
            const col = th.dataset.sort;
            if (currentSort.column === col) {
                currentSort.direction = currentSort.direction === 'asc' ? 'desc' : 'asc';
            } else {
                currentSort.column = col;
                currentSort.direction = 'asc';
            }

            // Update header classes
            table.querySelectorAll('th').forEach(h => {
                h.classList.remove('msm-th-sorted-asc', 'msm-th-sorted-desc');
            });
            th.classList.add(currentSort.direction === 'asc' ? 'msm-th-sorted-asc' : 'msm-th-sorted-desc');

            sortAndRenderTable();
        };
    });
}

async function resetMetrics() {
    // Get list of remotes with plugin installed
    const remotesWithPlugin = systemsData.filter(s => !s.isLocal && s.hasMetrics);
    const remoteCount = remotesWithPlugin.length;

    const msg = remoteCount > 0
        ? `Reset multi-sync metrics on this system and ${remoteCount} remote${remoteCount > 1 ? 's' : ''}?`
        : 'Reset all multi-sync metrics?';

    if (!confirm(msg)) return;

    try {
        // Reset local
        const requests = [
            fetch('/api/plugin-apis/fpp-plugin-watcher/multisync/reset', { method: 'POST' })
        ];

        // Reset all remotes with plugin in parallel
        remotesWithPlugin.forEach(remote => {
            requests.push(
                fetch(`http://${remote.address}/api/plugin-apis/fpp-plugin-watcher/multisync/reset`, {
                    method: 'POST',
                    mode: 'cors'
                }).catch(e => console.warn(`Failed to reset ${remote.hostname}:`, e))
            );
        });

        await Promise.all(requests);
        await loadAllData();
    } catch (e) {
        console.error('Error resetting:', e);
    }
}

function formatTimeSince(seconds) {
    if (seconds === undefined || seconds < 0) return '--';
    if (seconds < 60) return seconds + 's';
    if (seconds < 3600) return Math.floor(seconds / 60) + 'm';
    if (seconds < 86400) return Math.floor(seconds / 3600) + 'h';
    return Math.floor(seconds / 86400) + 'd';
}

function formatTimeSinceMs(ms) {
    if (ms === undefined || ms < 0) return '--';
    if (ms < 1000) return ms + 'ms';
    if (ms < 10000) return (ms / 1000).toFixed(1) + 's';
    const seconds = Math.floor(ms / 1000);
    if (seconds < 60) return seconds + 's';
    if (seconds < 3600) return Math.floor(seconds / 60) + 'm';
    if (seconds < 86400) return Math.floor(seconds / 3600) + 'h';
    return Math.floor(seconds / 86400) + 'd';
}

async function loadClockDrift() {
    try {
        const resp = await fetch('/api/plugin/fpp-plugin-watcher/multisync/clock-drift');
        if (!resp.ok) return;

        const data = await resp.json();
        if (!data.success) return;

        // Build map of address -> drift data
        clockDriftData = {};
        (data.hosts || []).forEach(host => {
            clockDriftData[host.address] = {
                drift_ms: host.drift_ms,
                rtt_ms: host.rtt_ms,
                hasPlugin: host.hasPlugin
            };
        });

        // Re-render table with new clock drift data
        if (systemsData.length > 0) {
            sortAndRenderTable();
        }
    } catch (e) {
        console.error('Error loading clock drift:', e);
    }
}

/**
 * Load current network quality status (fast poll)
 */
async function loadNetworkQualityCurrent() {
    try {
        const resp = await fetch('/api/plugin/fpp-plugin-watcher/metrics/network-quality/current');
        if (!resp.ok) return;

        const data = await resp.json();
        if (!data.success) return;

        updateQualityCard(data);
    } catch (e) {
        console.error('Error loading network quality:', e);
    }
}

/**
 * Load network quality (current + charts) - used for backward compatibility
 */
async function loadNetworkQuality() {
    await loadNetworkQualityCurrent();
    await loadQualityCharts();
}

function updateQualityCard(data) {
    const summary = data.summary || {};

    // Update quality indicator
    const qualityEl = document.getElementById('overallQuality');
    const quality = summary.overallQuality || 'unknown';
    qualityEl.textContent = quality.charAt(0).toUpperCase() + quality.slice(1);
    qualityEl.className = 'msm-quality-indicator msm-quality-' + quality;

    // Update metrics
    const latency = summary.avgLatency;
    document.getElementById('qualityLatency').textContent = latency !== null ? latency + 'ms' : '--';

    const jitter = summary.avgJitter;
    document.getElementById('qualityJitter').textContent = jitter !== null ? jitter + 'ms' : '--';

    const packetLoss = summary.avgPacketLoss;
    document.getElementById('qualityPacketLoss').textContent = packetLoss !== null ? packetLoss + '%' : '--';

    // Apply quality colors to values
    if (data.hosts && data.hosts.length > 0) {
        const host = data.hosts[0]; // Use first host for now
        applyQualityClass('qualityLatency', host.latency_quality);
        applyQualityClass('qualityJitter', host.jitter_quality);
        applyQualityClass('qualityPacketLoss', host.packet_loss_quality);
    }
}

function applyQualityClass(elementId, quality) {
    const el = document.getElementById(elementId);
    if (!el) return;
    el.className = 'msm-quality-value';
    if (quality) {
        el.classList.add('msm-quality-' + quality);
    }
}

async function loadQualityCharts() {
    const hours = document.getElementById('qualityTimeRange')?.value || 6;

    try {
        const resp = await fetch(`/api/plugin/fpp-plugin-watcher/metrics/network-quality/history?hours=${hours}`);
        if (!resp.ok) return;

        const data = await resp.json();
        if (!data.success || !data.chartData) return;

        renderLatencyJitterChart(data.chartData);
        renderPacketLossChart(data.chartData);
    } catch (e) {
        console.error('Error loading quality charts:', e);
    }
}

function renderLatencyJitterChart(chartData) {
    const canvas = document.getElementById('latencyJitterChart');
    if (!canvas) return;

    const labels = chartData.labels.map(ts => new Date(ts));

    // Update existing chart data inline
    if (latencyJitterChart) {
        latencyJitterChart.data.labels = labels;
        latencyJitterChart.data.datasets[0].data = chartData.latency;
        latencyJitterChart.data.datasets[1].data = chartData.jitter;
        latencyJitterChart.update('none'); // 'none' disables animation for faster updates
        return;
    }

    // Create chart on first load
    const ctx = canvas.getContext('2d');
    latencyJitterChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Latency (ms)',
                    data: chartData.latency,
                    borderColor: '#17a2b8',
                    backgroundColor: 'rgba(23, 162, 184, 0.1)',
                    fill: true,
                    tension: 0.3,
                    yAxisID: 'y'
                },
                {
                    label: 'Jitter (ms)',
                    data: chartData.jitter,
                    borderColor: '#ffc107',
                    backgroundColor: 'rgba(255, 193, 7, 0.1)',
                    fill: false,
                    tension: 0.3,
                    yAxisID: 'y'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false
            },
            plugins: {
                legend: {
                    display: true,
                    position: 'top'
                }
            },
            scales: {
                x: {
                    type: 'time',
                    time: {
                        displayFormats: {
                            minute: 'HH:mm',
                            hour: 'HH:mm'
                        }
                    },
                    ticks: {
                        maxTicksLimit: 8
                    }
                },
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Milliseconds'
                    }
                }
            }
        }
    });
}

function renderPacketLossChart(chartData) {
    const canvas = document.getElementById('packetLossChart');
    if (!canvas) return;

    const labels = chartData.labels.map(ts => new Date(ts));
    const lossData = chartData.packetLoss;

    // Calculate appropriate Y-axis max based on data
    // Filter out nulls and find max value
    const validValues = lossData.filter(v => v !== null && v !== undefined);
    const maxValue = validValues.length > 0 ? Math.max(...validValues) : 0;
    // Round up to nearest 5 or 10 for cleaner axis, minimum of 5
    const yMax = maxValue <= 5 ? 5 : Math.ceil(maxValue / 5) * 5;

    // Update existing chart data inline
    if (packetLossChart) {
        packetLossChart.data.labels = labels;
        packetLossChart.data.datasets[0].data = lossData;
        // Update Y-axis max to fit new data
        packetLossChart.options.scales.y.max = yMax;
        packetLossChart.update('none'); // 'none' disables animation for faster updates
        return;
    }

    // Create chart on first load
    const ctx = canvas.getContext('2d');
    packetLossChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Packet Loss (%)',
                    data: lossData,
                    borderColor: '#dc3545',
                    backgroundColor: 'rgba(220, 53, 69, 0.1)',
                    fill: true,
                    tension: 0.3
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top'
                }
            },
            scales: {
                x: {
                    type: 'time',
                    time: {
                        displayFormats: {
                            minute: 'HH:mm',
                            hour: 'HH:mm'
                        }
                    },
                    ticks: {
                        maxTicksLimit: 8
                    }
                },
                y: {
                    beginAtZero: true,
                    max: yMax,
                    title: {
                        display: true,
                        text: 'Packet Loss %'
                    }
                }
            }
        }
    });
}

function updateLastRefresh() {
    document.getElementById('lastUpdate').textContent = 'Updated: ' + new Date().toLocaleTimeString();
}

function showPluginError(msg) {
    document.getElementById('pluginErrorMessage').textContent = msg;
    document.getElementById('pluginError').style.display = 'flex';
}

function hidePluginError() {
    document.getElementById('pluginError').style.display = 'none';
}

function toggleHelpTooltip() {
    const tooltip = document.getElementById('helpTooltip');
    tooltip.classList.toggle('show');
}

// Close help tooltip when clicking outside
document.addEventListener('click', (e) => {
    const tooltip = document.getElementById('helpTooltip');
    const helpBtn = document.querySelector('.msm-help-btn');
    if (tooltip && tooltip.classList.contains('show') &&
        !tooltip.contains(e.target) && !helpBtn.contains(e.target)) {
        tooltip.classList.remove('show');
    }
});

document.addEventListener('DOMContentLoaded', () => {
    // Initial load - loads both slow and fast data
    loadAllData();

    // Start dual polling intervals
    fastRefreshInterval = setInterval(loadFastData, FAST_POLL_INTERVAL);
    slowRefreshInterval = setInterval(loadSlowData, SLOW_POLL_INTERVAL);
});

window.addEventListener('beforeunload', () => {
    if (fastRefreshInterval) clearInterval(fastRefreshInterval);
    if (slowRefreshInterval) clearInterval(slowRefreshInterval);
});
</script>
