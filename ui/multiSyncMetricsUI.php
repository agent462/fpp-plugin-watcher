<?php
/**
 * multiSyncMetricsUI.php - Comprehensive Multi-Sync Dashboard
 *
 * Unified dashboard showing:
 * - Local sync metrics from C++ plugin
 * - Player vs remote comparison
 * - Real-time sync status and issues
 */
include_once __DIR__ . '/../lib/core/config.php';
include_once __DIR__ . '/../lib/core/watcherCommon.php';
include_once __DIR__ . '/../lib/ui/common.php';
include_once __DIR__ . '/../lib/multisync/syncStatus.php';

$config = readPluginConfig();
$localSystem = apiCall('GET', 'http://127.0.0.1/api/fppd/status', [], true, 5) ?: [];

// Check if multi-sync is enabled
$multiSyncEnabled = ($localSystem['multisync'] ?? false) === true;

// FPP Mode: 2=Player, 6=Remote, 8=Master (deprecated)
$fppMode = $localSystem['mode'] ?? 0;
$isRemoteMode = ($fppMode == 6);
$isPlayerMode = ($fppMode == 2 || $fppMode == 8);

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
                <div class="msm-help-section">
                    <h4>Network Quality</h4>
                    <dl>
                        <dt>Latency</dt>
                        <dd>Round-trip time to reach each remote system. Lower is better. Good: &lt;50ms, Fair: 50-100ms, Poor: &gt;100ms</dd>
                        <dt>Jitter</dt>
                        <dd>Variation in latency over time (RFC 3550). High jitter can cause sync glitches. Good: &lt;10ms, Fair: 10-25ms</dd>
                        <dt>Packet Loss</dt>
                        <dd>Estimated based on sync packet receive rate during playback. FPP sends sync packets every 10 frames (~2-4/sec depending on sequence frame rate).</dd>
                    </dl>
                </div>
                <div class="msm-help-section">
                    <h4>Sync Metrics</h4>
                    <dl>
                        <dt>Time Drift</dt>
                        <dd>System clock difference between player and remote. Positive = remote clock is ahead, negative = behind. Large drift (&gt;500ms) may cause sync issues. Uses NTP-style measurement accounting for network round-trip time.</dd>
                        <dt>Step Time</dt>
                        <dd>Milliseconds per frame from sequence file. Common values: 25ms (40fps), 50ms (20fps).</dd>
                        <dt>Last Sync</dt>
                        <dd>Time since last sync packet was received. Should be &lt;1 second during active playback.</dd>
                    </dl>
                </div>
                <div class="msm-help-section">
                    <h4>Packet Types</h4>
                    <dl>
                        <dt>Sync Packets</dt>
                        <dd>Sequence sync commands (open/start/stop/sync). Sent by player, received by remotes.</dd>
                        <dt>Media Packets</dt>
                        <dd>Audio sync for media playback. Keeps audio in sync across systems.</dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>

    <?php if (!$multiSyncEnabled): ?>
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

    <!-- Player Status Card -->
    <div class="msm-player-card" id="playerCard">
        <div class="msm-player-header">
            <span class="msm-player-title">
                <i class="fas fa-crown"></i> <span id="playerHostname">This System</span>
            </span>
            <span class="msm-player-mode" id="playerMode"><?php echo $isPlayerMode ? 'Player' : ($isRemoteMode ? 'Remote' : '--'); ?></span>
        </div>
        <div class="msm-player-metrics">
            <div class="msm-player-metric msm-metric-wide">
                <span class="msm-player-metric-label">Sequence</span>
                <span class="msm-player-metric-value" id="playerSequence">--</span>
            </div>
            <div class="msm-player-metric">
                <span class="msm-player-metric-label">Status</span>
                <span class="msm-player-metric-value" id="playerStatus">--</span>
            </div>
            <div class="msm-player-metric">
                <span class="msm-player-metric-label">Frame</span>
                <span class="msm-player-metric-value msm-mono" id="playerFrame">--</span>
            </div>
            <div class="msm-player-metric">
                <span class="msm-player-metric-label">Time</span>
                <span class="msm-player-metric-value msm-mono" id="playerTime">--</span>
            </div>
            <div class="msm-player-metric">
                <span class="msm-player-metric-label">Step Time</span>
                <span class="msm-player-metric-value" id="playerStepTime">--</span>
            </div>
            <div class="msm-player-metric">
                <span class="msm-player-metric-label">Channels</span>
                <span class="msm-player-metric-value" id="playerChannels">--</span>
            </div>
            <div class="msm-player-metric">
                <span class="msm-player-metric-label">Packets Sent</span>
                <span class="msm-player-metric-value" id="playerPacketsSent">--</span>
            </div>
            <div class="msm-player-metric">
                <span class="msm-player-metric-label">Packets Received</span>
                <span class="msm-player-metric-value" id="playerPacketsReceived">--</span>
            </div>
            <?php if ($isRemoteMode): ?>
            <div class="msm-player-metric">
                <span class="msm-player-metric-label">Avg Drift</span>
                <span class="msm-player-metric-value" id="playerAvgDrift">--</span>
            </div>
            <?php endif; ?>
            <div class="msm-player-metric">
                <span class="msm-player-metric-label">Last Sync</span>
                <span class="msm-player-metric-value" id="playerLastSync">--</span>
            </div>
        </div>
        <!-- Sequence Lifecycle Metrics -->
        <div class="msm-player-lifecycle">
            <div class="msm-lifecycle-header">
                <i class="fas fa-film"></i> Sequence Lifecycle
            </div>
            <div class="msm-lifecycle-grid">
                <div class="msm-lifecycle-item">
                    <span class="msm-lifecycle-label">Open</span>
                    <span class="msm-lifecycle-value" id="lcSeqOpenPlayer">0</span>
                </div>
                <div class="msm-lifecycle-item">
                    <span class="msm-lifecycle-label">Start</span>
                    <span class="msm-lifecycle-value" id="lcSeqStartPlayer">0</span>
                </div>
                <div class="msm-lifecycle-item">
                    <span class="msm-lifecycle-label">Stop</span>
                    <span class="msm-lifecycle-value" id="lcSeqStopPlayer">0</span>
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
                    <span class="msm-lifecycle-label">Cmd Pkts</span>
                    <span class="msm-lifecycle-value" id="lcCmdPackets">0</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats Summary -->
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

    <!-- Network Quality Card -->
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
                <span class="msm-quality-label">Packet Loss</span>
                <span class="msm-quality-value" id="qualityPacketLoss">--</span>
            </div>
        </div>
    </div>

    <!-- Issues Panel -->
    <div class="msm-issues-panel hidden" id="issuesPanel">
        <div class="msm-issues-header" id="issuesHeader">
            <span><i class="fas fa-exclamation-triangle"></i> Issues Detected</span>
            <span id="issueCount">0</span>
        </div>
        <div class="msm-issues-body" id="issuesList"></div>
    </div>

    <?php if ($isPlayerMode): ?>
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
                <h3 class="msm-card-title"><i class="fas fa-chart-line"></i> Packet Loss</h3>
            </div>
            <div class="msm-card-body">
                <canvas id="packetLossChart" height="200"></canvas>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- All Systems Packet Metrics Table -->
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
                        <th data-sort="address" class="msm-th-sortable">IP</th>
                        <th data-sort="type" class="msm-th-sortable">Type</th>
                        <th data-sort="mode" class="msm-th-sortable">Mode</th>
                        <th data-sort="drift" class="msm-th-sortable msm-th-right" title="Time drift from player (negative = behind)">Time Drift</th>
                        <th data-sort="syncSent" class="msm-th-sortable msm-th-right" title="Sync Sent">Sync<i class="fas fa-arrow-up"></i></th>
                        <th data-sort="syncRecv" class="msm-th-sortable msm-th-right" title="Sync Received">Sync<i class="fas fa-arrow-down"></i></th>
                        <th data-sort="mediaSent" class="msm-th-sortable msm-th-right" title="Media Sent">Media<i class="fas fa-arrow-up"></i></th>
                        <th data-sort="mediaRecv" class="msm-th-sortable msm-th-right" title="Media Received">Media<i class="fas fa-arrow-down"></i></th>
                        <th data-sort="total" class="msm-th-sortable msm-th-right">Total</th>
                    </tr>
                </thead>
                <tbody id="systemsPacketBody">
                    <tr><td colspan="11" class="msm-td-loading"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <button class="msm-refresh-btn" onclick="loadAllData()" title="Refresh Data">
        <i class="fas fa-sync-alt"></i>
    </button>
</div>

<script>
const IS_PLAYER_MODE = <?php echo $isPlayerMode ? 'true' : 'false'; ?>;
const IS_REMOTE_MODE = <?php echo $isRemoteMode ? 'true' : 'false'; ?>;
let refreshInterval = null;
let latencyJitterChart = null;
let packetLossChart = null;
let localStatus = null;
let fppSystems = [];
let systemsData = [];
let currentSort = { column: 'hostname', direction: 'asc' };
let sequenceMeta = null;
let lastSequenceName = null;
let clockDriftData = {}; // Map of address -> drift_ms

// Quality colors
const QUALITY_COLORS = {
    good: '#28a745',
    fair: '#ffc107',
    poor: '#fd7e14',
    critical: '#dc3545',
    unknown: '#6c757d'
};

async function loadAllData() {
    try {
        document.querySelector('.msm-refresh-btn i').classList.add('fa-spin');

        // Load local status from C++ plugin and FPP systems in parallel
        const [statusResp, systemsResp] = await Promise.all([
            fetch('/api/plugin-apis/fpp-plugin-watcher/multisync/status'),
            fetch('/api/fppd/multiSyncSystems')
        ]);

        if (!statusResp.ok) {
            showPluginError('C++ plugin not responding. Restart FPP to load the plugin.');
            return;
        }

        const status = await statusResp.json();
        localStatus = status;

        // Parse FPP systems
        if (systemsResp.ok) {
            const sysData = await systemsResp.json();
            fppSystems = sysData.systems || [];
        }

        hidePluginError();
        await updatePlayerCard(status);
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
        } else {
            // Remote mode - just show local issues and local-only systems table
            updateStats(0, 0, 0, localIssues.length);
            updateIssues(localIssues);
            renderSystemsPacketTable([], status);
        }

        // Load network quality and clock drift data (player mode only)
        if (IS_PLAYER_MODE) {
            await Promise.all([
                loadNetworkQuality(),
                loadClockDrift()
            ]);
        }

        updateLastRefresh();
    } catch (e) {
        console.error('Error loading data:', e);
        showPluginError('Error connecting to multi-sync plugin: ' + e.message);
    } finally {
        document.querySelector('.msm-refresh-btn i').classList.remove('fa-spin');
    }
}

async function updatePlayerCard(status) {
    document.getElementById('playerHostname').textContent = '<?php echo htmlspecialchars($localSystem['host_name'] ?? gethostname()); ?>';

    const seqName = status.currentMasterSequence || '';
    const displayName = seqName.replace(/\.fseq$/i, '') || 'None';
    document.getElementById('playerSequence').textContent = displayName;
    document.getElementById('playerStatus').textContent = status.sequencePlaying ? 'Playing' : 'Idle';

    // Fetch sequence metadata if sequence changed
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

    // Frame counter: current / total
    const frame = status.lastMasterFrame || 0;
    const totalFrames = sequenceMeta?.NumFrames || 0;
    if (status.sequencePlaying && totalFrames > 0) {
        document.getElementById('playerFrame').textContent = `${frame.toLocaleString()} / ${totalFrames.toLocaleString()}`;
    } else if (status.sequencePlaying) {
        document.getElementById('playerFrame').textContent = frame.toLocaleString();
    } else {
        document.getElementById('playerFrame').textContent = '--';
    }

    // Time: elapsed / total
    const secs = status.lastMasterSeconds || 0;
    const stepTime = sequenceMeta?.StepTime || 25;
    const totalSecs = totalFrames > 0 ? (totalFrames * stepTime / 1000) : 0;
    if (status.sequencePlaying && totalSecs > 0) {
        document.getElementById('playerTime').textContent = `${formatTime(secs)} / ${formatTime(totalSecs)}`;
    } else if (status.sequencePlaying) {
        document.getElementById('playerTime').textContent = formatTime(secs);
    } else {
        document.getElementById('playerTime').textContent = '--';
    }

    // Sequence metadata - show step time with calculated FPS
    if (sequenceMeta) {
        const fps = Math.round(1000 / sequenceMeta.StepTime);
        document.getElementById('playerStepTime').textContent = `${sequenceMeta.StepTime}ms (${fps}fps)`;
    } else {
        document.getElementById('playerStepTime').textContent = '--';
    }
    document.getElementById('playerChannels').textContent = sequenceMeta ? sequenceMeta.ChannelCount.toLocaleString() : '--';

    document.getElementById('playerPacketsSent').textContent = (status.totalPacketsSent || 0).toLocaleString();
    document.getElementById('playerPacketsReceived').textContent = (status.totalPacketsReceived || 0).toLocaleString();

    const avgDriftEl = document.getElementById('playerAvgDrift');
    if (avgDriftEl) {
        avgDriftEl.textContent = status.avgFrameDrift !== undefined ? status.avgFrameDrift.toFixed(1) + ' frames' : '--';
    }

    document.getElementById('playerLastSync').textContent = formatTimeSince(status.secondsSinceLastSync);
}

function formatTime(seconds) {
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return `${mins}:${secs.toString().padStart(2, '0')}`;
}

function updateLifecycleMetrics(status) {
    const sent = status.packetsSent || {};
    const recv = status.packetsReceived || {};

    // Update player lifecycle metrics in player card
    const lc = status.lifecycle || {};
    document.getElementById('lcSeqOpenPlayer').textContent = (lc.seqOpen || 0).toLocaleString();
    document.getElementById('lcSeqStartPlayer').textContent = (lc.seqStart || 0).toLocaleString();
    document.getElementById('lcSeqStopPlayer').textContent = (lc.seqStop || 0).toLocaleString();

    // Show related packet counts (sent + received) for lifecycle context
    const syncTotal = (sent.sync || 0) + (recv.sync || 0);
    const mediaTotal = (sent.mediaSync || 0) + (recv.mediaSync || 0);
    const cmdTotal = (sent.command || 0) + (recv.command || 0);
    document.getElementById('lcSyncPackets').textContent = syncTotal.toLocaleString();
    document.getElementById('lcMediaPackets').textContent = mediaTotal.toLocaleString();
    document.getElementById('lcCmdPackets').textContent = cmdTotal.toLocaleString();
}

async function loadComparison(localIssues) {
    try {
        const resp = await fetch('/api/plugin/fpp-plugin-watcher/multisync/comparison');
        if (!resp.ok) return;

        const data = await resp.json();
        if (!data.success) return;

        // Combine local issues with comparison issues
        const allIssues = [...localIssues, ...data.issues];

        // Update stats
        const summary = data.summary;
        updateStats(summary.totalRemotes, summary.onlineCount, summary.pluginInstalledCount, allIssues.length);

        // Update issues
        updateIssues(allIssues);

        // Render remote cards
        renderRemoteCards(data.remotes);

        // Render systems packet metrics table (local + remotes)
        renderSystemsPacketTable(data.remotes, localStatus);
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
            // Use FPP status for actual sequence/status, fall back to sync packet data
            const actualSeq = fpp.sequence || m.currentMasterSequence || '--';
            const actualStatus = fpp.status || (m.sequencePlaying ? 'playing' : 'idle');
            const statusDisplay = actualStatus === 'playing' ? 'Playing' : 'Idle';
            const pkts = m.totalPacketsReceived !== undefined ? m.totalPacketsReceived.toLocaleString() : '--';
            const avgDrift = m.avgFrameDrift !== undefined ? m.avgFrameDrift.toFixed(1) : '--';
            const maxDrift = m.maxFrameDrift !== undefined ? Math.abs(m.maxFrameDrift) : null;
            const lastSync = m.secondsSinceLastSync !== undefined ? m.secondsSinceLastSync + 's' : '--';

            let driftClass = '';
            if (maxDrift !== null) {
                if (maxDrift > 10) driftClass = 'critical';
                else if (maxDrift > 5) driftClass = 'warning';
                else driftClass = 'good';
            }

            // Check for missing sequence scenario
            const syncSaysPlaying = m.sequencePlaying;
            const actuallyPlaying = actualStatus === 'playing';
            let statusClass = actuallyPlaying ? 'good' : '';
            if (syncSaysPlaying && !actuallyPlaying) {
                statusClass = 'critical'; // Sync says playing but FPP isn't
            }

            metricsHtml = `
                <div class="msm-remote-metrics">
                    <div><span class="msm-remote-metric-label">Received Sequence</span><br><span class="msm-remote-metric-value">${escapeHtml(actualSeq) || '(none)'}</span></div>
                    <div><span class="msm-remote-metric-label">Status</span><br><span class="msm-remote-metric-value ${statusClass}">${statusDisplay}</span></div>
                    <div><span class="msm-remote-metric-label">Packets Recv</span><br><span class="msm-remote-metric-value">${pkts}</span></div>
                    <div><span class="msm-remote-metric-label">Last Sync</span><br><span class="msm-remote-metric-value">${lastSync}</span></div>
                    <div><span class="msm-remote-metric-label">Avg Drift</span><br><span class="msm-remote-metric-value ${driftClass}">${avgDrift}f</span></div>
                    <div><span class="msm-remote-metric-label">Max Drift</span><br><span class="msm-remote-metric-value ${driftClass}">${maxDrift !== null ? maxDrift + 'f' : '--'}</span></div>
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
                        <div class="msm-remote-address">${escapeHtml(remote.address)}</div>
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
            syncSent: 0, syncRecv: 0, mediaSent: 0, mediaRecv: 0, blankSent: 0, blankRecv: 0,
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

        const total = row.syncSent + row.syncRecv + row.mediaSent + row.mediaRecv + row.blankSent + row.blankRecv;

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
            // Color code: green for <50ms, yellow for 50-500ms, red for >500ms
            // These thresholds are for system clock drift (more tolerant than frame drift)
            if (absMs < 50) driftClass = 'msm-drift-good';
            else if (absMs < 500) driftClass = 'msm-drift-fair';
            else driftClass = 'msm-drift-poor';
            const rttTitle = clockData.rtt_ms ? `RTT: ${clockData.rtt_ms}ms` : '';
            driftDisplay = `<span class="${driftClass}" title="${rttTitle}">${sign}${driftMs}ms</span>`;
        } else if (row.hasMetrics) {
            // Has plugin but no clock data yet
            driftDisplay = '<span class="msm-drift-pending">...</span>';
        }

        return `<tr class="${dimClass}">
            <td><span class="msm-status-badge ${statusClass}">${statusLabel}</span></td>
            <td>${row.isLocal ? '<i class="fas fa-home msm-home-icon"></i>' : ''}${escapeHtml(row.hostname)}</td>
            <td class="msm-td-mono">${escapeHtml(row.address)}</td>
            <td>${escapeHtml(row.type)}</td>
            <td>${escapeHtml(row.mode)}</td>
            <td class="msm-td-num">${driftDisplay}</td>
            <td class="msm-td-num msm-td-sent">${row.hasMetrics ? row.syncSent.toLocaleString() : '--'}</td>
            <td class="msm-td-num msm-td-recv">${row.hasMetrics ? row.syncRecv.toLocaleString() : '--'}</td>
            <td class="msm-td-num msm-td-sent">${row.hasMetrics ? row.mediaSent.toLocaleString() : '--'}</td>
            <td class="msm-td-num msm-td-recv">${row.hasMetrics ? row.mediaRecv.toLocaleString() : '--'}</td>
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

async function loadNetworkQuality() {
    try {
        const resp = await fetch('/api/plugin/fpp-plugin-watcher/metrics/network-quality/current');
        if (!resp.ok) return;

        const data = await resp.json();
        if (!data.success) return;

        updateQualityCard(data);

        // Update charts on every refresh
        await loadQualityCharts();
    } catch (e) {
        console.error('Error loading network quality:', e);
    }
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
    loadAllData();
    refreshInterval = setInterval(loadAllData, 2000);
});

window.addEventListener('beforeunload', () => {
    if (refreshInterval) clearInterval(refreshInterval);
});
</script>
