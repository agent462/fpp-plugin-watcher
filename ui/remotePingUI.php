<?php
include_once __DIR__ . '/../lib/core/config.php';
include_once __DIR__ . '/../lib/core/watcherCommon.php';
include_once __DIR__ . '/../lib/ui/common.php';

$config = readPluginConfig();
$localSystem = apiCall('GET', 'http://127.0.0.1/api/fppd/status', [], true, 5) ?: [];
$access = checkDashboardAccess($config, $localSystem, 'multiSyncMetricsEnabled');

renderCSSIncludes($access['show']);
if ($access['show']) renderCommonJS();
?>
<style>
    .perHostStats { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
    .hostStatCard { background: #fff; border-radius: 8px; padding: 1rem; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border-left: 4px solid #007bff; }
    .hostStatCard .hostname { font-weight: 600; font-size: 1rem; color: #212529; margin-bottom: 0.25rem; }
    .hostStatCard .address { font-size: 0.8rem; color: #6c757d; margin-bottom: 0.75rem; }
    .hostStatCard .stats-row { display: flex; justify-content: space-between; gap: 0.5rem; }
    .hostStatCard .stat { text-align: center; flex: 1; }
    .hostStatCard .stat-label { font-size: 0.7rem; color: #6c757d; text-transform: uppercase; }
    .hostStatCard .stat-value { font-size: 1rem; font-weight: 600; color: #212529; }
    .hostStatCard .stat-value.success { color: #28a745; }
    .hostStatCard .stat-value.warning { color: #ffc107; }
    .hostStatCard .stat-value.danger { color: #dc3545; }
</style>

<div class="metricsContainer">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <h2 style="margin: 0; color: #212529;">
            <i class="fas fa-server"></i> Multi-Sync Host Ping Metrics
        </h2>
        <span id="lastUpdate" style="font-size: 0.875rem; color: #6c757d;"></span>
    </div>

    <?php if (!renderAccessError($access)): ?>

    <div id="loadingIndicator" class="loadingSpinner">
        <i class="fas fa-spinner"></i>
        <p>Loading multi-sync ping metrics data...</p>
    </div>

    <div id="metricsContent" style="display: none;">
        <!-- Raw Ping Chart -->
        <div class="chartCard">
            <div class="chartTitle">
                <span><i class="fas fa-signal"></i> Real-time Multi-Sync Host Latency <span class="tierBadge">raw samples</span></span>
            </div>
            <div class="chartControls" style="margin-bottom: 1rem;">
                <div class="controlGroup">
                    <label for="rawTimeRange">Time Range:</label>
                    <select id="rawTimeRange" onchange="updateRawPingLatencyChart()">
                        <option value="2">Last 2 Hours</option>
                        <option value="4">Last 4 Hours</option>
                        <option value="8">Last 8 Hours</option>
                        <option value="12" selected>Last 12 Hours</option>
                        <option value="24">Last 24 Hours</option>
                    </select>
                </div>
            </div>
            <canvas id="rawPingLatencyChart" class="chartCanvas"></canvas>
        </div>

        <div id="noDataMessage" class="infoBox" style="display: none;">
            <strong>No Data:</strong> No multi-sync ping data is available yet.
        </div>

        <h2 style="margin-bottom: 1.5rem; color: #212529;"><i class="fas fa-chart-line"></i> Rollup Metrics by Host</h2>

        <!-- Per-Host Statistics Cards -->
        <div id="perHostStatsSection" style="display: none;">
            <div class="perHostStats" id="perHostStats"></div>
        </div>

        <!-- Stats Bar -->
        <div id="statsBarSection" style="display: none;">
            <div class="statsBar">
                <div class="statItem"><div class="statLabel">Hosts Monitored</div><div class="statValue" id="hostsCount">--</div></div>
                <div class="statItem"><div class="statLabel">Overall Avg Latency</div><div class="statValue" id="overallAvgLatency">-- ms</div></div>
                <div class="statItem"><div class="statLabel">Best Host Latency</div><div class="statValue" id="bestLatency">-- ms</div></div>
                <div class="statItem"><div class="statLabel">Worst Host Latency</div><div class="statValue" id="worstLatency">-- ms</div></div>
                <div class="statItem"><div class="statLabel">Data Points</div><div class="statValue" id="dataPoints">--</div></div>
            </div>
        </div>

        <!-- Time Range Selector -->
        <div class="chartControls" style="margin-bottom: 1.5rem;">
            <div class="controlGroup">
                <label for="timeRange">Rollup Time Range:</label>
                <select id="timeRange" onchange="updateAllCharts()">
                    <option value="1">Last 1 Hour</option>
                    <option value="6">Last 6 Hours</option>
                    <option value="12" selected>Last 12 Hours</option>
                    <option value="24">Last 24 Hours</option>
                    <option value="48">Last 2 Days</option>
                    <option value="72">Last 3 Days</option>
                    <option value="168">Last 7 Days</option>
                    <option value="336">Last 2 Weeks</option>
                    <option value="720">Last 30 Days</option>
                    <option value="2160">Last 90 Days</option>
                </select>
            </div>
        </div>

        <!-- Rollup Charts -->
        <div id="rollupChartsSection" style="display: none;">
            <div class="chartCard">
                <div class="chartTitle"><span><i class="fas fa-chart-line"></i> Average Latency by Host <span class="tierBadge" id="latencyTierBadge">1-minute averages</span></span></div>
                <canvas id="latencyChart" class="chartCanvas"></canvas>
            </div>
            <div class="chartCard">
                <div class="chartTitle"><span><i class="fas fa-check-circle"></i> Success Rate by Host <span class="tierBadge" id="successTierBadge">1-minute averages</span></span></div>
                <canvas id="successChart" class="chartCanvas"></canvas>
            </div>
        </div>
    </div>

    <button class="refreshButton" onclick="loadAllMetrics()" title="Refresh Data"><i class="fas fa-sync-alt"></i></button>
    <?php endif; ?>
</div>

<?php if ($access['show']): ?>
<script>
const charts = {};
let isRefreshing = false;

function groupByHost(data) {
    const grouped = {};
    data.forEach(e => {
        const host = e.hostname || 'unknown';
        (grouped[host] = grouped[host] || { entries: [], address: e.address || '' }).entries.push(e);
    });
    return grouped;
}

function createHostDatasets(dataByHost, valueMapper, pointRadius = 0) {
    return Object.keys(dataByHost).sort().map((hostname, i) => {
        const color = getChartColor(i);
        return createDataset(hostname, dataByHost[hostname].entries.map(valueMapper), color, { fill: false, pointRadius });
    });
}

async function loadAllMetrics() {
    if (isRefreshing) return;
    isRefreshing = true;
    const btn = document.querySelector('.refreshButton i');
    if (btn) btn.style.animation = 'spin 1s linear infinite';
    try {
        await Promise.all([updateRawPingLatencyChart(), updateAllCharts()]);
        hideElement('loadingIndicator');
        showElement('metricsContent');
        updateLastUpdateTime();
    } catch (e) { console.error('Error loading metrics:', e); }
    finally { isRefreshing = false; if (btn) btn.style.animation = ''; }
}

async function updateRawPingLatencyChart() {
    const hours = parseInt(document.getElementById('rawTimeRange').value);
    const data = await fetchJson(`/api/plugin/fpp-plugin-watcher/metrics/multisync/ping/raw?hours=${hours}`);

    if (!data.success || !data.data?.length) {
        showElement('noDataMessage');
        return;
    }
    hideElement('noDataMessage');

    const dataByHost = groupByHost(data.data);
    const datasets = createHostDatasets(dataByHost, e => ({ x: e.timestamp * 1000, y: e.latency }), 2);
    const opts = buildChartOptions(hours, { yLabel: 'Latency (ms)', beginAtZero: true, tooltipLabel: ctx => ctx.dataset.label + ': ' + ctx.parsed.y.toFixed(2) + ' ms' });
    updateOrCreateChart(charts, 'rawPing', 'rawPingLatencyChart', 'line', datasets, opts);
}

async function updateAllCharts() {
    const hours = parseInt(document.getElementById('timeRange').value);
    const data = await fetchJson(`/api/plugin/fpp-plugin-watcher/metrics/multisync/ping/rollup?hours=${hours}`);

    const sections = ['statsBarSection', 'rollupChartsSection', 'perHostStatsSection'];
    if (!data.success || !data.data?.length) {
        sections.forEach(id => document.getElementById(id).style.display = 'none');
        return;
    }
    sections.forEach(id => document.getElementById(id).style.display = 'block');

    if (data.tier_info) {
        document.getElementById('latencyTierBadge').textContent = data.tier_info.label;
        document.getElementById('successTierBadge').textContent = data.tier_info.label;
    }

    const dataByHost = groupByHost(data.data);
    const hostnames = Object.keys(dataByHost).sort();

    // Calculate per-host stats
    const hostStats = hostnames.map(hostname => {
        const entries = dataByHost[hostname].entries;
        const latencies = entries.map(e => e.avg_latency).filter(v => v !== null);
        const success = entries.reduce((s, e) => s + (e.success_count || 0), 0);
        const failure = entries.reduce((s, e) => s + (e.failure_count || 0), 0);
        const total = success + failure;
        return {
            hostname, address: dataByHost[hostname].address,
            avgLatency: latencies.length ? latencies.reduce((a, b) => a + b) / latencies.length : null,
            minLatency: latencies.length ? Math.min(...latencies) : null,
            maxLatency: latencies.length ? Math.max(...latencies) : null,
            successRate: total ? success / total * 100 : 0
        };
    });

    // Render per-host stat cards
    document.getElementById('perHostStats').innerHTML = hostStats.map(s => {
        const lc = s.avgLatency === null ? '' : s.avgLatency > 100 ? 'danger' : s.avgLatency > 50 ? 'warning' : 'success';
        const sc = s.successRate >= 99 ? 'success' : s.successRate >= 90 ? 'warning' : 'danger';
        const bc = { danger: '#dc3545', warning: '#ffc107', success: '#28a745' }[lc] || '#6c757d';
        return `<div class="hostStatCard" style="border-left-color:${bc}">
            <div class="hostname">${escapeHtml(s.hostname)}</div>
            <div class="address">${escapeHtml(s.address)}</div>
            <div class="stats-row">
                <div class="stat"><div class="stat-label">Avg Latency</div><div class="stat-value ${lc}">${s.avgLatency !== null ? s.avgLatency.toFixed(1) + ' ms' : '--'}</div></div>
                <div class="stat"><div class="stat-label">Min/Max</div><div class="stat-value">${s.minLatency !== null ? s.minLatency.toFixed(1) : '--'} / ${s.maxLatency !== null ? s.maxLatency.toFixed(1) : '--'}</div></div>
                <div class="stat"><div class="stat-label">Success Rate</div><div class="stat-value ${sc}">${s.successRate.toFixed(1)}%</div></div>
            </div></div>`;
    }).join('');

    // Update summary stats
    const allLat = hostStats.filter(s => s.avgLatency !== null).map(s => s.avgLatency);
    document.getElementById('hostsCount').textContent = hostnames.length;
    document.getElementById('overallAvgLatency').textContent = allLat.length ? (allLat.reduce((a, b) => a + b) / allLat.length).toFixed(2) + ' ms' : '-- ms';
    document.getElementById('bestLatency').textContent = allLat.length ? Math.min(...allLat).toFixed(2) + ' ms' : '-- ms';
    document.getElementById('worstLatency').textContent = allLat.length ? Math.max(...allLat).toFixed(2) + ' ms' : '-- ms';
    document.getElementById('dataPoints').textContent = data.data.length.toLocaleString();

    // Update charts
    const latencyOpts = buildChartOptions(hours, { yLabel: 'Latency (ms)', beginAtZero: true, yTickFormatter: v => v.toFixed(1) + ' ms', tooltipLabel: ctx => ctx.dataset.label + ': ' + ctx.parsed.y.toFixed(2) + ' ms' });
    updateOrCreateChart(charts, 'latency', 'latencyChart', 'line', createHostDatasets(dataByHost, e => ({ x: e.timestamp * 1000, y: e.avg_latency })), latencyOpts);

    const successOpts = buildChartOptions(hours, { yLabel: 'Success Rate (%)', yMax: 100, yTickFormatter: v => v + '%', tooltipLabel: ctx => ctx.dataset.label + ': ' + ctx.parsed.y.toFixed(1) + '%' });
    const successDatasets = createHostDatasets(dataByHost, e => {
        const t = (e.success_count || 0) + (e.failure_count || 0);
        return { x: e.timestamp * 1000, y: t ? e.success_count / t * 100 : 100 };
    });
    updateOrCreateChart(charts, 'success', 'successChart', 'line', successDatasets, successOpts);
}

setInterval(() => { if (!isRefreshing) loadAllMetrics(); }, 60000);
document.addEventListener('DOMContentLoaded', loadAllMetrics);
</script>
<?php endif; ?>
