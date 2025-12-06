<?php
include_once __DIR__ . '/lib/ui/common.php';
renderCSSIncludes(true);
renderCommonJS();
?>

<div class="metricsContainer">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <h2 style="margin: 0; color: #212529;">
            <i class="fas fa-network-wired"></i> Connectivity Metrics
        </h2>
        <span id="lastUpdate" style="font-size: 0.875rem; color: #6c757d;"></span>
    </div>

    <div id="loadingIndicator" class="loadingSpinner">
        <i class="fas fa-spinner"></i>
        <p>Loading connectivity metrics data...</p>
    </div>

    <div id="metricsContent" style="display: none;">
        <!-- Raw Ping Chart -->
        <div class="chartCard">
            <div class="chartTitle">
                <span>
                    <i class="fas fa-signal"></i> Real-time Network Latency
                    <span class="tierBadge">raw samples</span>
                </span>
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
            <canvas id="rawPingLatencyChart" style="max-height: 400px;"></canvas>
        </div>

        <div id="noDataMessage" class="infoBox" style="display: none;">
            <strong>No Data:</strong> No rollup data is available for the selected time range yet.
        </div>

        <h2 style="margin-bottom: 1.5rem; color: #212529;">
            <i class="fas fa-network-wired"></i> Rollup Connectivity Metrics
        </h2>

        <!-- Stats Bar -->
        <div id="statsBarSection" style="display: none;">
            <div class="statsBar">
                <div class="statItem">
                    <div class="statLabel">Current Latency</div>
                    <div class="statValue" id="currentLatency">-- ms</div>
                </div>
                <div class="statItem">
                    <div class="statLabel">Average Latency</div>
                    <div class="statValue" id="avgLatency">-- ms</div>
                </div>
                <div class="statItem">
                    <div class="statLabel">Min Latency</div>
                    <div class="statValue" id="minLatency">-- ms</div>
                </div>
                <div class="statItem">
                    <div class="statLabel">Max Latency</div>
                    <div class="statValue" id="maxLatency">-- ms</div>
                </div>
                <div class="statItem">
                    <div class="statLabel">Data Points</div>
                    <div class="statValue" id="dataPoints">--</div>
                </div>
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
                <div class="chartTitle">
                    <span>
                        <i class="fas fa-chart-line"></i> Ping Latency Over Time
                        <span class="tierBadge" id="latencyTierBadge">1-minute averages</span>
                    </span>
                </div>
                <canvas id="latencyChart" style="max-height: 400px;"></canvas>
            </div>

            <div class="chartCard">
                <div class="chartTitle">
                    <span>
                        <i class="fas fa-chart-area"></i> Latency Range (Min/Max)
                        <span class="tierBadge" id="rangeTierBadge">1-minute averages</span>
                    </span>
                </div>
                <canvas id="rangeChart" style="max-height: 400px;"></canvas>
            </div>

            <div class="chartCard">
                <div class="chartTitle">
                    <span>
                        <i class="fas fa-chart-bar"></i> Ping Sample Count
                        <span class="tierBadge" id="sampleTierBadge">1-minute averages</span>
                    </span>
                </div>
                <canvas id="sampleChart" style="max-height: 400px;"></canvas>
            </div>
        </div>
    </div>

    <button class="refreshButton" onclick="loadAllMetrics()" title="Refresh Data">
        <i class="fas fa-sync-alt"></i>
    </button>
</div>

<script>
const charts = {};
let isRefreshing = false;

async function loadAllMetrics() {
    if (isRefreshing) return;
    isRefreshing = true;

    const refreshBtn = document.querySelector('.refreshButton i');
    if (refreshBtn) refreshBtn.style.animation = 'spin 1s linear infinite';

    try {
        await Promise.all([updateAllCharts(), updateRawPingLatencyChart()]);
        hideElement('loadingIndicator');
        showElement('metricsContent');
        updateLastUpdateTime();
    } catch (error) {
        console.error('Error loading metrics:', error);
    } finally {
        isRefreshing = false;
        if (refreshBtn) refreshBtn.style.animation = '';
    }
}

async function updateAllCharts() {
    const hours = parseInt(document.getElementById('timeRange').value);
    const data = await fetchJson(`/api/plugin/fpp-plugin-watcher/metrics/ping/rollup?hours=${hours}`);

    const noDataEl = document.getElementById('noDataMessage');
    const statsSection = document.getElementById('statsBarSection');
    const chartsSection = document.getElementById('rollupChartsSection');

    if (!data.success || !data.data?.length) {
        statsSection.style.display = 'none';
        chartsSection.style.display = 'none';
        noDataEl.style.display = 'block';
        return false;
    }

    noDataEl.style.display = 'none';
    statsSection.style.display = 'block';
    chartsSection.style.display = 'block';

    // Update tier badges
    if (data.tier_info) {
        ['latencyTierBadge', 'rangeTierBadge', 'sampleTierBadge'].forEach(id => {
            document.getElementById(id).textContent = data.tier_info.label;
        });
    }

    // Calculate and display stats
    const latencies = data.data.map(d => d.avg_latency).filter(v => v !== null);
    const minLats = data.data.map(d => d.min_latency).filter(v => v !== null);
    const maxLats = data.data.map(d => d.max_latency).filter(v => v !== null);

    document.getElementById('currentLatency').textContent = formatLatency(latencies.at(-1));
    document.getElementById('avgLatency').textContent = formatLatency(latencies.reduce((a, b) => a + b, 0) / latencies.length);
    document.getElementById('minLatency').textContent = formatLatency(Math.min(...minLats));
    document.getElementById('maxLatency').textContent = formatLatency(Math.max(...maxLats));
    document.getElementById('dataPoints').textContent = data.data.length.toLocaleString();

    // Build chart options
    const latencyOpts = buildChartOptions(hours, {
        yLabel: 'Latency (ms)',
        beginAtZero: true,
        yTickFormatter: v => v.toFixed(1) + ' ms',
        tooltipLabel: ctx => 'Latency: ' + ctx.parsed.y.toFixed(2) + ' ms'
    });

    const sampleOpts = buildChartOptions(hours, {
        yLabel: 'Number of Samples',
        beginAtZero: true,
        yTickFormatter: v => v.toFixed(0),
        tooltipLabel: ctx => 'Samples: ' + ctx.parsed.y
    });

    // Latency chart
    updateOrCreateChart(charts, 'latency', 'latencyChart', 'line',
        [createDataset('Average Latency (ms)', mapChartData(data, 'avg_latency'), 'purple')],
        latencyOpts
    );

    // Range chart (min/avg/max)
    updateOrCreateChart(charts, 'range', 'rangeChart', 'line', [
        createDataset('Min Latency', mapChartData(data, 'min_latency'), 'green', { fill: false }),
        createDataset('Avg Latency', mapChartData(data, 'avg_latency'), 'purple', { fill: false }),
        createDataset('Max Latency', mapChartData(data, 'max_latency'), 'red', { fill: false })
    ], latencyOpts);

    // Sample count chart (bar)
    const barDataset = createDataset('Sample Count', mapChartData(data, 'sample_count'), 'blue');
    barDataset.borderWidth = 1;
    updateOrCreateChart(charts, 'sample', 'sampleChart', 'bar', [barDataset], sampleOpts);

    return true;
}

async function updateRawPingLatencyChart() {
    const hours = parseInt(document.getElementById('rawTimeRange').value);
    const data = await fetchJson(`/api/plugin/fpp-plugin-watcher/metrics/ping/raw?hours=${hours}`);

    if (!data.success || !data.data?.length) return;

    // Group by host and create datasets
    const byHost = {};
    data.data.forEach(e => {
        (byHost[e.host] = byHost[e.host] || []).push({ x: e.timestamp * 1000, y: e.latency });
    });

    const datasets = Object.keys(byHost).map((host, i) => {
        const color = getChartColor(i);
        return createDataset(host, byHost[host], color, { pointRadius: 2 });
    });

    const opts = buildChartOptions(hours, {
        yLabel: 'Latency (ms)',
        beginAtZero: true,
        tooltipLabel: ctx => ctx.dataset.label + ': ' + ctx.parsed.y.toFixed(2) + ' ms'
    });

    updateOrCreateChart(charts, 'rawPing', 'rawPingLatencyChart', 'line', datasets, opts);
}

setInterval(() => { if (!isRefreshing) loadAllMetrics(); }, 30000);
document.addEventListener('DOMContentLoaded', loadAllMetrics);
</script>
