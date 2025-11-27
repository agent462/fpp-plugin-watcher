<?php
// Load configuration to check if multi-sync metrics is enabled
include_once __DIR__ . '/lib/config.php';
include_once __DIR__ . '/lib/watcherCommon.php';
$config = readPluginConfig();

// Fetch local system status from FPP API
$localSystem = apiCall('GET', 'http://127.0.0.1/api/fppd/status', [], true, 5);
if ($localSystem === false) {
    $localSystem = [];
}

// Check conditions
$isEnabled = !empty($config['multiSyncMetricsEnabled']);
$isPlayerMode = ($localSystem['mode_name'] ?? '') === 'player';
$showDashboard = $isEnabled && $isPlayerMode;
?>

<link rel="stylesheet" href="/css/fpp-bootstrap/dist-new/fpp-bootstrap-5-3.css">
<link rel="stylesheet" href="/css/fpp.css">
<link rel="stylesheet" href="/plugin.php?plugin=fpp-plugin-watcher&file=css/commonUI.css&nopage=1">
<?php if ($showDashboard): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
<?php endif; ?>

<style>
    .disabledMessage {
        padding: 3rem;
        text-align: center;
        background: #f8f9fa;
        border-radius: 8px;
        margin: 2rem auto;
        max-width: 600px;
    }
    .disabledMessage h3 {
        color: #495057;
        margin-bottom: 1rem;
    }
    .disabledMessage p {
        color: #6c757d;
    }
    .hostSelector {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-bottom: 1rem;
    }
    .hostSelector .host-badge {
        padding: 0.35rem 0.75rem;
        border-radius: 20px;
        font-size: 0.85rem;
        cursor: pointer;
        border: 2px solid transparent;
        transition: all 0.2s ease;
    }
    .hostSelector .host-badge.active {
        border-color: #007bff;
        box-shadow: 0 0 0 2px rgba(0, 123, 255, 0.25);
    }
    .hostSelector .host-badge:hover {
        opacity: 0.85;
    }
    .perHostStats {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }
    .hostStatCard {
        background: #fff;
        border-radius: 8px;
        padding: 1rem;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        border-left: 4px solid #007bff;
    }
    .hostStatCard .hostname {
        font-weight: 600;
        font-size: 1rem;
        color: #212529;
        margin-bottom: 0.25rem;
    }
    .hostStatCard .address {
        font-size: 0.8rem;
        color: #6c757d;
        margin-bottom: 0.75rem;
    }
    .hostStatCard .stats-row {
        display: flex;
        justify-content: space-between;
        gap: 0.5rem;
    }
    .hostStatCard .stat {
        text-align: center;
        flex: 1;
    }
    .hostStatCard .stat-label {
        font-size: 0.7rem;
        color: #6c757d;
        text-transform: uppercase;
    }
    .hostStatCard .stat-value {
        font-size: 1rem;
        font-weight: 600;
        color: #212529;
    }
    .hostStatCard .stat-value.success { color: #28a745; }
    .hostStatCard .stat-value.warning { color: #ffc107; }
    .hostStatCard .stat-value.danger { color: #dc3545; }
</style>

<div class="metricsContainer">
    <h2 style="margin-bottom: 1.5rem; color: #212529;">
        <i class="fas fa-server"></i> Multi-Sync Host Ping Metrics
    </h2>

    <?php if (!$isEnabled): ?>
    <div class="disabledMessage">
        <h3><i class="fas fa-exclamation-circle"></i> Multi-Sync Metrics Disabled</h3>
        <p>This feature is not enabled. Go to <a href="plugin.php?plugin=fpp-plugin-watcher&page=configUI.php">Watcher Config</a> to enable it.</p>
    </div>
    <?php elseif (!$isPlayerMode): ?>
    <div class="disabledMessage">
        <h3><i class="fas fa-info-circle"></i> Player Mode Required</h3>
        <p>This feature is only available when FPP is in Player mode. Current mode: <?php echo htmlspecialchars($localSystem['fppModeString'] ?? 'unknown'); ?></p>
    </div>
    <?php else: ?>

    <div id="loadingIndicator" class="loadingSpinner">
        <i class="fas fa-spinner"></i>
        <p>Loading multi-sync ping metrics data...</p>
    </div>

    <div id="metricsContent" style="display: none;">
        <!-- Raw Ping Chart - All Hosts -->
        <div class="chartCard">
            <div class="chartTitle">
                <span>
                    <i class="fas fa-signal"></i> Real-time Multi-Sync Host Latency
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
            <strong>No Data:</strong> No multi-sync ping data is available yet. Data will appear after the connectivity checker runs for a few minutes in Player mode.
        </div>

        <h2 style="margin-bottom: 1.5rem; color: #212529;">
            <i class="fas fa-chart-line"></i> Rollup Metrics by Host
        </h2>

        <!-- Per-Host Statistics Cards -->
        <div id="perHostStatsSection" style="display: none;">
            <div class="perHostStats" id="perHostStats"></div>
        </div>

        <!-- Stats Bar -->
        <div id="statsBarSection" style="display: none;">
            <div class="statsBar">
                <div class="statItem">
                    <div class="statLabel">Hosts Monitored</div>
                    <div class="statValue" id="hostsCount">--</div>
                </div>
                <div class="statItem">
                    <div class="statLabel">Overall Avg Latency</div>
                    <div class="statValue" id="overallAvgLatency">-- ms</div>
                </div>
                <div class="statItem">
                    <div class="statLabel">Best Host Latency</div>
                    <div class="statValue" id="bestLatency">-- ms</div>
                </div>
                <div class="statItem">
                    <div class="statLabel">Worst Host Latency</div>
                    <div class="statValue" id="worstLatency">-- ms</div>
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
            <!-- Average Latency Chart per Host -->
            <div class="chartCard">
                <div class="chartTitle">
                    <span>
                        <i class="fas fa-chart-line"></i> Average Latency by Host
                        <span class="tierBadge" id="latencyTierBadge">1-minute averages</span>
                    </span>
                </div>
                <canvas id="latencyChart" style="max-height: 400px;"></canvas>
            </div>

            <!-- Success/Failure Rate Chart -->
            <div class="chartCard">
                <div class="chartTitle">
                    <span>
                        <i class="fas fa-check-circle"></i> Success Rate by Host
                        <span class="tierBadge" id="successTierBadge">1-minute averages</span>
                    </span>
                </div>
                <canvas id="successChart" style="max-height: 400px;"></canvas>
            </div>
        </div>
    </div>

    <button class="refreshButton" onclick="loadAllMetrics()" title="Refresh Data">
        <i class="fas fa-sync-alt"></i>
    </button>
    <?php endif; ?>
</div>

<?php if ($showDashboard): ?>
<script>
    let latencyChart = null;
    let successChart = null;
    let rawPingLatencyChart = null;
    let isRefreshing = false;

    // Color palette for hosts
    const hostColors = [
        { border: 'rgb(102, 126, 234)', bg: 'rgba(102, 126, 234, 0.1)' },
        { border: 'rgb(56, 239, 125)', bg: 'rgba(56, 239, 125, 0.1)' },
        { border: 'rgb(240, 147, 251)', bg: 'rgba(240, 147, 251, 0.1)' },
        { border: 'rgb(79, 172, 254)', bg: 'rgba(79, 172, 254, 0.1)' },
        { border: 'rgb(245, 87, 108)', bg: 'rgba(245, 87, 108, 0.1)' },
        { border: 'rgb(255, 193, 7)', bg: 'rgba(255, 193, 7, 0.1)' },
        { border: 'rgb(23, 162, 184)', bg: 'rgba(23, 162, 184, 0.1)' },
        { border: 'rgb(111, 66, 193)', bg: 'rgba(111, 66, 193, 0.1)' }
    ];

    function getHostColor(index) {
        return hostColors[index % hostColors.length];
    }

    // Load all metrics
    async function loadAllMetrics() {
        if (isRefreshing) return;
        isRefreshing = true;

        try {
            const refreshBtn = document.querySelector('.refreshButton i');
            if (refreshBtn) {
                refreshBtn.style.animation = 'spin 1s linear infinite';
            }

            await updateRawPingLatencyChart();
            await updateAllCharts();

            document.getElementById('loadingIndicator').style.display = 'none';
            document.getElementById('metricsContent').style.display = 'block';

            if (refreshBtn) {
                refreshBtn.style.animation = '';
            }
        } catch (error) {
            console.error('Error loading metrics:', error);
        } finally {
            isRefreshing = false;
        }
    }

    // Get appropriate time unit based on hours
    function getTimeUnit(hours) {
        if (hours <= 1) return 'minute';
        if (hours <= 24) return 'hour';
        if (hours <= 168) return 'day';
        return 'week';
    }

    // Get time display formats
    function getTimeFormats(hours) {
        return {
            minute: 'HH:mm',
            hour: 'MMM d, HH:mm',
            day: 'MMM d',
            week: 'MMM d, yyyy'
        };
    }

    // Update raw ping latency chart
    async function updateRawPingLatencyChart() {
        try {
            const hours = parseInt(document.getElementById('rawTimeRange').value);
            const response = await fetch(`/api/plugin/fpp-plugin-watcher/metrics/multisync/ping/raw?hours=${hours}`);
            const metricsData = await response.json();

            const noDataEl = document.getElementById('noDataMessage');

            if (!metricsData.success || !metricsData.data || metricsData.data.length === 0) {
                console.warn('No raw multi-sync ping data available');
                if (noDataEl) noDataEl.style.display = 'block';
                return false;
            }

            if (noDataEl) noDataEl.style.display = 'none';

            // Group data by hostname
            const dataByHost = {};
            metricsData.data.forEach(entry => {
                const hostname = entry.hostname || 'unknown';
                if (!dataByHost[hostname]) {
                    dataByHost[hostname] = [];
                }
                dataByHost[hostname].push({
                    x: entry.timestamp * 1000,
                    y: entry.latency
                });
            });

            // Create datasets for each host
            const datasets = Object.keys(dataByHost).sort().map((hostname, index) => {
                const color = getHostColor(index);
                return {
                    label: hostname,
                    data: dataByHost[hostname],
                    borderColor: color.border,
                    backgroundColor: color.bg,
                    borderWidth: 2,
                    fill: false,
                    tension: 0.4,
                    pointRadius: 2,
                    pointHoverRadius: 5
                };
            });

            const timeUnit = hours <= 4 ? 'minute' : 'hour';
            const displayFormats = hours <= 4 ?
                { minute: 'HH:mm', hour: 'MMM d, HH:mm' } :
                { hour: 'MMM d, HH:mm' };

            if (rawPingLatencyChart) {
                rawPingLatencyChart.data.datasets = datasets;
                rawPingLatencyChart.options.scales.x.time.unit = timeUnit;
                rawPingLatencyChart.options.scales.x.time.displayFormats = displayFormats;
                rawPingLatencyChart.update('none');
            } else {
                const ctx = document.getElementById('rawPingLatencyChart').getContext('2d');
                rawPingLatencyChart = new Chart(ctx, {
                    type: 'line',
                    data: { datasets: datasets },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        interaction: {
                            mode: 'nearest',
                            axis: 'x',
                            intersect: false
                        },
                        plugins: {
                            legend: {
                                display: true,
                                position: 'top',
                                labels: {
                                    usePointStyle: true,
                                    padding: 15,
                                    font: { size: 12 }
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    title: function(context) {
                                        const date = new Date(context[0].parsed.x);
                                        return date.toLocaleString();
                                    },
                                    label: function(context) {
                                        if (context.parsed.y === null) return context.dataset.label + ': Failed';
                                        return context.dataset.label + ': ' + context.parsed.y.toFixed(2) + ' ms';
                                    }
                                }
                            }
                        },
                        scales: {
                            x: {
                                type: 'time',
                                time: {
                                    unit: timeUnit,
                                    displayFormats: displayFormats,
                                    tooltipFormat: 'MMM d, yyyy HH:mm:ss'
                                },
                                title: {
                                    display: true,
                                    text: 'Time',
                                    font: { size: 14, weight: 'bold' }
                                },
                                grid: { display: true, color: 'rgba(0, 0, 0, 0.05)' }
                            },
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Latency (ms)',
                                    font: { size: 14, weight: 'bold' }
                                },
                                grid: { display: true, color: 'rgba(0, 0, 0, 0.05)' }
                            }
                        }
                    }
                });
            }
            return true;
        } catch (error) {
            console.error('Error loading raw ping latency metrics:', error);
            return false;
        }
    }

    // Update all rollup charts
    async function updateAllCharts() {
        try {
            const hours = parseInt(document.getElementById('timeRange').value);
            const response = await fetch(`/api/plugin/fpp-plugin-watcher/metrics/multisync/ping/rollup?hours=${hours}`);
            const data = await response.json();

            const statsBarSection = document.getElementById('statsBarSection');
            const rollupChartsSection = document.getElementById('rollupChartsSection');
            const perHostStatsSection = document.getElementById('perHostStatsSection');

            if (!data.success || !data.data || data.data.length === 0) {
                console.warn('No multi-sync rollup data available');
                if (statsBarSection) statsBarSection.style.display = 'none';
                if (rollupChartsSection) rollupChartsSection.style.display = 'none';
                if (perHostStatsSection) perHostStatsSection.style.display = 'none';
                return false;
            }

            if (statsBarSection) statsBarSection.style.display = 'block';
            if (rollupChartsSection) rollupChartsSection.style.display = 'block';
            if (perHostStatsSection) perHostStatsSection.style.display = 'block';

            // Update tier badges
            if (data.tier_info) {
                const tierLabel = data.tier_info.label;
                document.getElementById('latencyTierBadge').textContent = tierLabel;
                document.getElementById('successTierBadge').textContent = tierLabel;
            }

            // Group data by hostname
            const dataByHost = {};
            data.data.forEach(entry => {
                const hostname = entry.hostname || 'unknown';
                if (!dataByHost[hostname]) {
                    dataByHost[hostname] = {
                        entries: [],
                        address: entry.address || ''
                    };
                }
                dataByHost[hostname].entries.push(entry);
            });

            const hostnames = Object.keys(dataByHost).sort();

            // Calculate per-host statistics
            const hostStats = hostnames.map((hostname, index) => {
                const entries = dataByHost[hostname].entries;
                const latencies = entries.map(e => e.avg_latency).filter(v => v !== null);
                const successCounts = entries.reduce((sum, e) => sum + (e.success_count || 0), 0);
                const failureCounts = entries.reduce((sum, e) => sum + (e.failure_count || 0), 0);
                const totalSamples = successCounts + failureCounts;

                return {
                    hostname: hostname,
                    address: dataByHost[hostname].address,
                    color: getHostColor(index),
                    avgLatency: latencies.length > 0 ? latencies.reduce((a, b) => a + b, 0) / latencies.length : null,
                    minLatency: latencies.length > 0 ? Math.min(...latencies) : null,
                    maxLatency: latencies.length > 0 ? Math.max(...latencies) : null,
                    successRate: totalSamples > 0 ? (successCounts / totalSamples * 100) : 0,
                    dataPoints: entries.length
                };
            });

            // Update per-host statistics cards
            const perHostStatsEl = document.getElementById('perHostStats');
            perHostStatsEl.innerHTML = hostStats.map(stat => {
                const latencyClass = stat.avgLatency === null ? '' :
                    stat.avgLatency > 100 ? 'danger' :
                    stat.avgLatency > 50 ? 'warning' : 'success';
                const successClass = stat.successRate >= 99 ? 'success' :
                    stat.successRate >= 90 ? 'warning' : 'danger';

                // Map latency class to border color
                const borderColor = latencyClass === 'danger' ? '#dc3545' :
                    latencyClass === 'warning' ? '#ffc107' :
                    latencyClass === 'success' ? '#28a745' : '#6c757d';

                return `
                    <div class="hostStatCard" style="border-left-color: ${borderColor};">
                        <div class="hostname">${escapeHtml(stat.hostname)}</div>
                        <div class="address">${escapeHtml(stat.address)}</div>
                        <div class="stats-row">
                            <div class="stat">
                                <div class="stat-label">Avg Latency</div>
                                <div class="stat-value ${latencyClass}">${stat.avgLatency !== null ? stat.avgLatency.toFixed(1) + ' ms' : '--'}</div>
                            </div>
                            <div class="stat">
                                <div class="stat-label">Min/Max</div>
                                <div class="stat-value">${stat.minLatency !== null ? stat.minLatency.toFixed(1) : '--'} / ${stat.maxLatency !== null ? stat.maxLatency.toFixed(1) : '--'}</div>
                            </div>
                            <div class="stat">
                                <div class="stat-label">Success Rate</div>
                                <div class="stat-value ${successClass}">${stat.successRate.toFixed(1)}%</div>
                            </div>
                        </div>
                    </div>
                `;
            }).join('');

            // Update overall stats
            const allLatencies = hostStats.filter(s => s.avgLatency !== null).map(s => s.avgLatency);
            document.getElementById('hostsCount').textContent = hostnames.length;
            document.getElementById('overallAvgLatency').textContent = allLatencies.length > 0 ?
                (allLatencies.reduce((a, b) => a + b, 0) / allLatencies.length).toFixed(2) + ' ms' : '-- ms';
            document.getElementById('bestLatency').textContent = allLatencies.length > 0 ?
                Math.min(...allLatencies).toFixed(2) + ' ms' : '-- ms';
            document.getElementById('worstLatency').textContent = allLatencies.length > 0 ?
                Math.max(...allLatencies).toFixed(2) + ' ms' : '-- ms';
            document.getElementById('dataPoints').textContent = data.data.length.toLocaleString();

            // Update charts
            updateLatencyChart(dataByHost, hostnames, hours);
            updateSuccessChart(dataByHost, hostnames, hours);

            return true;
        } catch (error) {
            console.error('Error updating charts:', error);
            return false;
        }
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Update latency chart with per-host data
    function updateLatencyChart(dataByHost, hostnames, hours) {
        const datasets = hostnames.map((hostname, index) => {
            const color = getHostColor(index);
            const chartData = dataByHost[hostname].entries.map(entry => ({
                x: entry.timestamp * 1000,
                y: entry.avg_latency
            }));

            return {
                label: hostname,
                data: chartData,
                borderColor: color.border,
                backgroundColor: color.bg,
                borderWidth: 2,
                fill: false,
                tension: 0.4,
                pointRadius: 0,
                pointHoverRadius: 5
            };
        });

        if (latencyChart) {
            latencyChart.data.datasets = datasets;
            latencyChart.options.scales.x.time.unit = getTimeUnit(hours);
            latencyChart.options.scales.x.time.displayFormats = getTimeFormats(hours);
            latencyChart.update('none');
        } else {
            const ctx = document.getElementById('latencyChart').getContext('2d');
            latencyChart = new Chart(ctx, {
                type: 'line',
                data: { datasets: datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    interaction: {
                        mode: 'nearest',
                        axis: 'x',
                        intersect: false
                    },
                    plugins: {
                        legend: { display: true, position: 'top' },
                        tooltip: {
                            callbacks: {
                                title: function(context) {
                                    const date = new Date(context[0].parsed.x);
                                    return date.toLocaleString();
                                },
                                label: function(context) {
                                    return context.dataset.label + ': ' + (context.parsed.y !== null ? context.parsed.y.toFixed(2) + ' ms' : 'N/A');
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            type: 'time',
                            time: {
                                unit: getTimeUnit(hours),
                                displayFormats: getTimeFormats(hours),
                                tooltipFormat: 'MMM d, yyyy HH:mm:ss'
                            },
                            title: {
                                display: true,
                                text: 'Time',
                                font: { size: 14, weight: 'bold' }
                            }
                        },
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Latency (ms)',
                                font: { size: 14, weight: 'bold' }
                            },
                            ticks: {
                                callback: function(value) {
                                    return value.toFixed(1) + ' ms';
                                }
                            }
                        }
                    }
                }
            });
        }
    }

    // Update success rate chart
    function updateSuccessChart(dataByHost, hostnames, hours) {
        const datasets = hostnames.map((hostname, index) => {
            const color = getHostColor(index);
            const chartData = dataByHost[hostname].entries.map(entry => {
                const total = (entry.success_count || 0) + (entry.failure_count || 0);
                const successRate = total > 0 ? (entry.success_count / total * 100) : 100;
                return {
                    x: entry.timestamp * 1000,
                    y: successRate
                };
            });

            return {
                label: hostname,
                data: chartData,
                borderColor: color.border,
                backgroundColor: color.bg,
                borderWidth: 2,
                fill: false,
                tension: 0.4,
                pointRadius: 0,
                pointHoverRadius: 5
            };
        });

        if (successChart) {
            successChart.data.datasets = datasets;
            successChart.options.scales.x.time.unit = getTimeUnit(hours);
            successChart.options.scales.x.time.displayFormats = getTimeFormats(hours);
            successChart.update('none');
        } else {
            const ctx = document.getElementById('successChart').getContext('2d');
            successChart = new Chart(ctx, {
                type: 'line',
                data: { datasets: datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    interaction: {
                        mode: 'nearest',
                        axis: 'x',
                        intersect: false
                    },
                    plugins: {
                        legend: { display: true, position: 'top' },
                        tooltip: {
                            callbacks: {
                                title: function(context) {
                                    const date = new Date(context[0].parsed.x);
                                    return date.toLocaleString();
                                },
                                label: function(context) {
                                    return context.dataset.label + ': ' + context.parsed.y.toFixed(1) + '%';
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            type: 'time',
                            time: {
                                unit: getTimeUnit(hours),
                                displayFormats: getTimeFormats(hours),
                                tooltipFormat: 'MMM d, yyyy HH:mm:ss'
                            },
                            title: {
                                display: true,
                                text: 'Time',
                                font: { size: 14, weight: 'bold' }
                            }
                        },
                        y: {
                            min: 0,
                            max: 100,
                            title: {
                                display: true,
                                text: 'Success Rate (%)',
                                font: { size: 14, weight: 'bold' }
                            },
                            ticks: {
                                callback: function(value) {
                                    return value + '%';
                                }
                            }
                        }
                    }
                }
            });
        }
    }

    // Auto-refresh every 60 seconds
    setInterval(() => {
        if (!isRefreshing) {
            loadAllMetrics();
        }
    }, 60000);

    // Load data on page load
    document.addEventListener('DOMContentLoaded', function() {
        loadAllMetrics();
    });
</script>
<?php endif; ?>
