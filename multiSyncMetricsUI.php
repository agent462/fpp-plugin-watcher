<?php
/**
 * multiSyncMetricsUI.php - Comprehensive Multi-Sync Dashboard
 *
 * Unified dashboard showing:
 * - Local sync metrics from C++ plugin
 * - Player vs remote comparison
 * - Real-time sync status and issues
 */
include_once __DIR__ . '/lib/config.php';
include_once __DIR__ . '/lib/watcherCommon.php';
include_once __DIR__ . '/lib/uiCommon.php';
include_once __DIR__ . '/lib/multiSyncMetrics.php';

$config = readPluginConfig();
$localSystem = apiCall('GET', 'http://127.0.0.1/api/fppd/status', [], true, 5) ?: [];

// Check if multi-sync is enabled
$multiSyncEnabled = ($localSystem['multisync'] ?? false) === true;

// FPP Mode: 2=Player, 6=Remote, 8=Master (deprecated)
$fppMode = $localSystem['fppd'] ?? $localSystem['mode'] ?? 0;
$isRemoteMode = ($fppMode == 6);
$isPlayerMode = ($fppMode == 2 || $fppMode == 8);

renderCSSIncludes(true);
renderCommonJS();
?>

<style>
/* Layout */
.msm-container { padding: 1.5rem; max-width: 1600px; margin: 0 auto; }
.msm-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem; }
.msm-header h2 { margin: 0; color: #212529; }
.msm-header-right { display: flex; align-items: center; gap: 1rem; }
.msm-last-update { font-size: 0.85rem; color: #6c757d; }

/* Player Card */
.msm-player-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #fff;
    border-radius: 0.5rem;
    padding: 1.25rem 1.5rem;
    margin-bottom: 1.5rem;
}
.msm-player-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.msm-player-title { font-size: 1.1rem; font-weight: 600; }
.msm-player-mode { background: rgba(255,255,255,0.2); padding: 0.25rem 0.75rem; border-radius: 1rem; font-size: 0.8rem; }
.msm-player-metrics { display: flex; gap: 2rem; flex-wrap: wrap; }
.msm-player-metric { display: flex; flex-direction: column; }
.msm-player-metric-label { font-size: 0.7rem; opacity: 0.8; text-transform: uppercase; }
.msm-player-metric-value { font-size: 1rem; font-weight: 500; }

/* Stats Grid */
.msm-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
.msm-stat-card { background: #f8f9fa; padding: 1rem; border-radius: 8px; border: 1px solid #e9ecef; text-align: center; }
.msm-stat-value { font-size: 1.75rem; font-weight: bold; color: #212529; line-height: 1; }
.msm-stat-label { font-size: 0.75rem; color: #6c757d; margin-top: 0.25rem; text-transform: uppercase; }
.msm-stat-value.healthy { color: #28a745; }
.msm-stat-value.warning { color: #ffc107; }
.msm-stat-value.critical { color: #dc3545; }

/* Issues Panel */
.msm-issues-panel { background: #fff; border: 1px solid #dee2e6; border-radius: 0.5rem; margin-bottom: 1.5rem; }
.msm-issues-panel.hidden { display: none; }
.msm-issues-header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 0.75rem 1rem;
    background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%);
    color: #000; border-radius: 0.5rem 0.5rem 0 0; font-weight: 600;
}
.msm-issues-header.critical { background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); color: #fff; }
.msm-issues-body { padding: 1rem; max-height: 300px; overflow-y: auto; }
.msm-issue-item { display: flex; align-items: flex-start; gap: 0.75rem; padding: 0.5rem 0; border-bottom: 1px solid #eee; }
.msm-issue-item:last-child { border-bottom: none; }
.msm-issue-icon { width: 24px; text-align: center; flex-shrink: 0; }
.msm-issue-icon.critical { color: #dc3545; }
.msm-issue-icon.warning { color: #ffc107; }
.msm-issue-icon.info { color: #17a2b8; }
.msm-issue-content { flex: 1; }
.msm-issue-host { font-weight: 600; color: #212529; }
.msm-issue-description { color: #495057; font-size: 0.9rem; }
.msm-issue-details { font-size: 0.8rem; color: #6c757d; margin-top: 0.25rem; }

/* Cards */
.msm-card { background: #fff; border: 1px solid #e9ecef; border-radius: 8px; margin-bottom: 1.5rem; overflow: hidden; }
.msm-card-header { background: #f8f9fa; padding: 0.875rem 1.25rem; border-bottom: 1px solid #e9ecef; display: flex; justify-content: space-between; align-items: center; }
.msm-card-title { font-weight: 600; color: #212529; margin: 0; font-size: 1rem; }
.msm-card-body { padding: 1.25rem; }

/* Two Column Layout */
.msm-two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
@media (max-width: 1200px) { .msm-two-col { grid-template-columns: 1fr; } }

/* Tables */
.msm-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
.msm-table th, .msm-table td { padding: 0.625rem 0.75rem; text-align: left; border-bottom: 1px solid #e9ecef; }
.msm-table th { background: #f8f9fa; font-weight: 600; color: #495057; font-size: 0.8rem; text-transform: uppercase; }
.msm-table tr:hover { background: #f8f9fa; }

/* Remote Cards Grid */
.msm-remotes-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1rem; }
.msm-remote-card { background: #fff; border: 1px solid #dee2e6; border-radius: 0.5rem; overflow: hidden; transition: box-shadow 0.2s; }
.msm-remote-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
.msm-remote-card.has-issues { border-left: 4px solid #ffc107; }
.msm-remote-card.critical { border-left: 4px solid #dc3545; }
.msm-remote-card.offline { border-left: 4px solid #6c757d; opacity: 0.7; }
.msm-remote-card.no-plugin { border-left: 4px solid #17a2b8; }
.msm-remote-header { display: flex; justify-content: space-between; align-items: center; padding: 0.625rem 0.875rem; background: #f8f9fa; border-bottom: 1px solid #dee2e6; }
.msm-remote-hostname { font-weight: 600; color: #212529; font-size: 0.95rem; }
.msm-remote-address { font-size: 0.75rem; color: #6c757d; }
.msm-remote-badge { font-size: 0.7rem; padding: 0.2rem 0.5rem; border-radius: 0.25rem; font-weight: 500; }
.msm-remote-badge.online { background: #d4edda; color: #155724; }
.msm-remote-badge.offline { background: #f8d7da; color: #721c24; }
.msm-remote-badge.no-plugin { background: #d1ecf1; color: #0c5460; }
.msm-remote-body { padding: 0.875rem; }
.msm-remote-metrics { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; }
.msm-remote-metric-label { font-size: 0.65rem; color: #6c757d; text-transform: uppercase; }
.msm-remote-metric-value { font-size: 0.85rem; font-weight: 500; color: #212529; }
.msm-remote-metric-value.good { color: #28a745; }
.msm-remote-metric-value.warning { color: #ffc107; }
.msm-remote-metric-value.critical { color: #dc3545; }
.msm-remote-issues { padding: 0.5rem 0.875rem; background: #fff3cd; border-top: 1px solid #dee2e6; font-size: 0.8rem; }
.msm-remote-issues.critical { background: #f8d7da; }
.msm-remote-message { padding: 0.5rem 0; color: #6c757d; font-size: 0.85rem; }

/* Badges */
.msm-badge { display: inline-block; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600; }
.msm-badge-info { background: #d1ecf1; color: #0c5460; }
.msm-badge-warning { background: #fff3cd; color: #856404; }
.msm-badge-critical { background: #f8d7da; color: #721c24; }
.msm-badge-success { background: #d4edda; color: #155724; }

/* Indicators */
.msm-indicator { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 0.5rem; }
.msm-indicator-ok { background: #28a745; }
.msm-indicator-warn { background: #ffc107; }
.msm-indicator-error { background: #dc3545; }

/* Notices */
.msm-notice { border-radius: 8px; padding: 1rem 1.25rem; margin-bottom: 1.5rem; display: flex; align-items: flex-start; gap: 0.75rem; }
.msm-notice i { font-size: 1.25rem; flex-shrink: 0; margin-top: 0.1rem; }
.msm-notice-warning { background: #fff3cd; border: 1px solid #ffc107; }
.msm-notice-warning i { color: #856404; }
.msm-notice-error { background: #f8d7da; border: 1px solid #f5c6cb; }
.msm-notice-error i { color: #721c24; }

/* Empty State */
.msm-empty { text-align: center; padding: 2rem; color: #6c757d; }
.msm-empty i { font-size: 2.5rem; margin-bottom: 0.75rem; display: block; opacity: 0.5; }

/* Refresh Button */
.msm-refresh-btn {
    position: fixed; bottom: 2rem; right: 2rem;
    width: 50px; height: 50px; border-radius: 50%;
    background: #007bff; color: white; border: none;
    cursor: pointer; box-shadow: 0 2px 10px rgba(0,0,0,0.2);
    transition: all 0.3s ease; z-index: 100;
}
.msm-refresh-btn:hover { background: #0056b3; transform: scale(1.1); }

/* Section Headers */
.msm-section-header {
    font-size: 1rem; font-weight: 600; color: #495057;
    margin: 1.5rem 0 1rem; padding-bottom: 0.5rem;
    border-bottom: 2px solid #e9ecef;
}
</style>

<div class="msm-container">
    <div class="msm-header">
        <h2><i class="fas fa-network-wired"></i> Multi-Sync Dashboard</h2>
        <div class="msm-header-right">
            <span class="msm-last-update" id="lastUpdate"></span>
            <button class="btn btn-sm btn-outline-secondary" onclick="loadAllData()">
                <i class="fas fa-sync-alt"></i> Refresh
            </button>
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
            <div class="msm-player-metric">
                <span class="msm-player-metric-label">Sequence</span>
                <span class="msm-player-metric-value" id="playerSequence">--</span>
            </div>
            <div class="msm-player-metric">
                <span class="msm-player-metric-label">Status</span>
                <span class="msm-player-metric-value" id="playerStatus">--</span>
            </div>
            <div class="msm-player-metric">
                <span class="msm-player-metric-label">Frame / Seconds</span>
                <span class="msm-player-metric-value" id="playerPosition">--</span>
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

    <!-- Packet Statistics & Systems Table -->
    <div class="msm-two-col">
        <!-- Packet Stats -->
        <div class="msm-card">
            <div class="msm-card-header">
                <h3 class="msm-card-title"><i class="fas fa-chart-bar"></i> Packet Statistics</h3>
                <button class="btn btn-sm btn-outline-secondary" onclick="resetMetrics()">
                    <i class="fas fa-undo"></i> Reset
                </button>
            </div>
            <div class="msm-card-body">
                <table class="msm-table">
                    <thead>
                        <tr><th>Type</th><th>Sent</th><th>Received</th></tr>
                    </thead>
                    <tbody id="packetStatsBody">
                        <tr><td>Sequence Sync</td><td id="pktSyncSent">--</td><td id="pktSyncRecv">--</td></tr>
                        <tr><td>Media Sync</td><td id="pktMediaSyncSent">--</td><td id="pktMediaSyncRecv">--</td></tr>
                        <tr><td>Blank</td><td id="pktBlankSent">--</td><td id="pktBlankRecv">--</td></tr>
                        <tr><td>Plugin</td><td id="pktPluginSent">--</td><td id="pktPluginRecv">--</td></tr>
                        <tr><td>Command</td><td id="pktCommandSent">--</td><td id="pktCommandRecv">--</td></tr>
                    </tbody>
                </table>
                <div style="margin-top: 1rem; font-size: 0.8rem; color: #6c757d;">
                    <strong>Lifecycle:</strong>
                    Seq Open: <span id="lcSeqOpen">0</span> |
                    Start: <span id="lcSeqStart">0</span> |
                    Stop: <span id="lcSeqStop">0</span>
                </div>
            </div>
        </div>

        <!-- FPP Systems -->
        <div class="msm-card">
            <div class="msm-card-header">
                <h3 class="msm-card-title"><i class="fas fa-server"></i> Discovered Systems</h3>
            </div>
            <div class="msm-card-body" id="fppSystemsContainer" style="max-height: 400px; overflow-y: auto;">
                <div class="msm-empty">
                    <i class="fas fa-spinner fa-spin"></i>
                    <p>Loading systems...</p>
                </div>
            </div>
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

async function loadAllData() {
    try {
        document.querySelector('.msm-refresh-btn i').classList.add('fa-spin');

        // Load local status from C++ plugin
        const statusResp = await fetch('/api/plugin-apis/fpp-plugin-watcher/multisync/status');
        if (!statusResp.ok) {
            showPluginError('C++ plugin not responding. Restart FPP to load the plugin.');
            return;
        }

        const status = await statusResp.json();
        hidePluginError();
        updatePlayerCard(status);
        updatePacketStats(status);

        // Load issues from C++ plugin
        const issuesResp = await fetch('/api/plugin-apis/fpp-plugin-watcher/multisync/issues');
        let localIssues = [];
        if (issuesResp.ok) {
            const issuesData = await issuesResp.json();
            localIssues = issuesData.issues || [];
        }

        // If player mode, also load comparison data
        if (IS_PLAYER_MODE) {
            await loadComparison(localIssues);
        } else {
            // Remote mode - just show local issues
            updateStats(0, 0, 0, localIssues.length);
            updateIssues(localIssues);
        }

        // Load FPP systems
        await loadFppSystems();

        updateLastRefresh();
    } catch (e) {
        console.error('Error loading data:', e);
        showPluginError('Error connecting to multi-sync plugin: ' + e.message);
    } finally {
        document.querySelector('.msm-refresh-btn i').classList.remove('fa-spin');
    }
}

function updatePlayerCard(status) {
    document.getElementById('playerHostname').textContent = '<?php echo htmlspecialchars($localSystem['host_name'] ?? gethostname()); ?>';
    document.getElementById('playerSequence').textContent = status.currentMasterSequence || 'None';
    document.getElementById('playerStatus').textContent = status.sequencePlaying ? 'Playing' : 'Idle';

    const frame = status.lastMasterFrame || 0;
    const secs = status.lastMasterSeconds !== undefined ? status.lastMasterSeconds.toFixed(1) : '0';
    document.getElementById('playerPosition').textContent = status.sequencePlaying ? `${frame} / ${secs}s` : '--';

    document.getElementById('playerPacketsSent').textContent = (status.totalPacketsSent || 0).toLocaleString();
    document.getElementById('playerPacketsReceived').textContent = (status.totalPacketsReceived || 0).toLocaleString();

    const avgDriftEl = document.getElementById('playerAvgDrift');
    if (avgDriftEl) {
        avgDriftEl.textContent = status.avgFrameDrift !== undefined ? status.avgFrameDrift.toFixed(1) + ' frames' : '--';
    }

    document.getElementById('playerLastSync').textContent = formatTimeSince(status.secondsSinceLastSync);
}

function updatePacketStats(status) {
    const sent = status.packetsSent || {};
    const recv = status.packetsReceived || {};

    document.getElementById('pktSyncSent').textContent = (sent.sync || 0).toLocaleString();
    document.getElementById('pktSyncRecv').textContent = (recv.sync || 0).toLocaleString();
    document.getElementById('pktMediaSyncSent').textContent = (sent.mediaSync || 0).toLocaleString();
    document.getElementById('pktMediaSyncRecv').textContent = (recv.mediaSync || 0).toLocaleString();
    document.getElementById('pktBlankSent').textContent = (sent.blank || 0).toLocaleString();
    document.getElementById('pktBlankRecv').textContent = (recv.blank || 0).toLocaleString();
    document.getElementById('pktPluginSent').textContent = (sent.plugin || 0).toLocaleString();
    document.getElementById('pktPluginRecv').textContent = (recv.plugin || 0).toLocaleString();
    document.getElementById('pktCommandSent').textContent = (sent.command || 0).toLocaleString();
    document.getElementById('pktCommandRecv').textContent = (recv.command || 0).toLocaleString();

    const lc = status.lifecycle || {};
    document.getElementById('lcSeqOpen').textContent = lc.seqOpen || 0;
    document.getElementById('lcSeqStart').textContent = lc.seqStart || 0;
    document.getElementById('lcSeqStop').textContent = lc.seqStop || 0;
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
        let metricsHtml = '';

        if (remote.pluginInstalled && remote.online) {
            const seq = m.currentMasterSequence || '--';
            const playing = m.sequencePlaying ? 'Playing' : 'Idle';
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

            metricsHtml = `
                <div class="msm-remote-metrics">
                    <div><span class="msm-remote-metric-label">Sequence</span><br><span class="msm-remote-metric-value">${escapeHtml(seq)}</span></div>
                    <div><span class="msm-remote-metric-label">Status</span><br><span class="msm-remote-metric-value">${playing}</span></div>
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

async function loadFppSystems() {
    const container = document.getElementById('fppSystemsContainer');

    try {
        const resp = await fetch('/api/fppd/multiSyncSystems');
        if (!resp.ok) {
            container.innerHTML = '<p class="text-muted">Unable to load systems</p>';
            return;
        }

        const data = await resp.json();
        const systems = data.systems || [];

        if (systems.length === 0) {
            container.innerHTML = `<div class="msm-empty"><i class="fas fa-server"></i><p>No systems discovered</p></div>`;
            return;
        }

        // Quick ping for bridge nodes
        const bridgeSystems = systems.filter(s => s.local !== 1 && s.typeId >= 128);
        let pingStatus = {};

        if (bridgeSystems.length > 0) {
            try {
                const ipParams = bridgeSystems.map(s => `ips[]=${encodeURIComponent(s.address)}`).join('&');
                const pingResp = await fetch(`/api/plugin/fpp-plugin-watcher/ping/check?${ipParams}`);
                if (pingResp.ok) {
                    const pingData = await pingResp.json();
                    pingStatus = pingData.results || {};
                }
            } catch (e) { }
        }

        let html = `<table class="msm-table">
            <thead><tr><th>Status</th><th>Host</th><th>IP</th><th>Type</th><th>Mode</th></tr></thead>
            <tbody>`;

        systems.forEach(sys => {
            const isLocal = sys.local === 1;
            const isBridge = sys.typeId >= 128;
            const ping = pingStatus[sys.address];

            let isOnline = isLocal || (isBridge ? (ping && ping.reachable) : true);
            let label = isLocal ? 'Local' : (isOnline ? 'Online' : 'Offline');
            const indicatorClass = isOnline ? 'msm-indicator-ok' : 'msm-indicator-error';

            html += `<tr>
                <td><span class="msm-indicator ${indicatorClass}"></span>${escapeHtml(label)}</td>
                <td>${escapeHtml(sys.hostname)}</td>
                <td>${escapeHtml(sys.address)}</td>
                <td>${escapeHtml(sys.type)}</td>
                <td>${escapeHtml(sys.fppModeString)}</td>
            </tr>`;
        });

        html += '</tbody></table>';
        container.innerHTML = html;
    } catch (e) {
        console.error('Error loading systems:', e);
        container.innerHTML = '<p class="text-muted">Error loading systems</p>';
    }
}

async function resetMetrics() {
    if (!confirm('Reset all multi-sync metrics?')) return;
    try {
        await fetch('/api/plugin-apis/fpp-plugin-watcher/multisync/reset', { method: 'POST' });
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

document.addEventListener('DOMContentLoaded', () => {
    loadAllData();
    refreshInterval = setInterval(loadAllData, 5000);
});

window.addEventListener('beforeunload', () => {
    if (refreshInterval) clearInterval(refreshInterval);
});
</script>
