<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Watcher - Ping Metrics</title>
    <link rel="stylesheet" href="/css/fpp-bootstrap/dist-new/fpp-bootstrap-5-3.css">
    <link rel="stylesheet" href="/css/fpp.css">
    <link rel="stylesheet" href="/plugin.php?plugin=fpp-plugin-watcher&file=css/commonUI.css&nopage=1">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
</head>
<body>
    <div class="metricsContainer">
        <h2 style="margin-bottom: 1.5rem; color: #212529;">
            <i class="fas fa-network-wired"></i> Connectivity Metrics
        </h2>

        <div id="loadingIndicator" class="loadingSpinner">
            <i class="fas fa-spinner"></i>
            <p>Loading connectivity metrics data...</p>
        </div>

        <div id="metricsContent" style="display: none;">
            <!-- Raw Ping Chart - Always visible (has its own data source) -->
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
                            <option value="12">Last 12 Hours</option>
                            <option value="24" selected>Last 24 Hours</option>
                        </select>
                    </div>
                </div>
                <canvas id="rawPingLatencyChart" style="max-height: 400px;"></canvas>
            </div>

            <div id="noDataMessage" class="infoBox" style="display: none;">
                <strong>No Data:</strong>  No rollup data is available for the selected time range yet. Try a shorter range or check back after more samples are collected.
            </div>
        <h2 style="margin-bottom: 1.5rem; color: #212529;">
            <i class="fas fa-network-wired"></i> Rollup Connectivity Metrics
        </h2>
            <!-- Stats Bar - Only visible when rollup data exists -->
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

            <!-- Time Range Selector - Always visible so users can try different time periods -->
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

            <!-- Rollup Charts - Only visible when rollup data exists -->
            <div id="rollupChartsSection" style="display: none;">
                <!-- Average Latency Chart -->
                <div class="chartCard">
                    <div class="chartTitle">
                        <span>
                            <i class="fas fa-chart-line"></i> Ping Latency Over Time
                            <span class="tierBadge" id="latencyTierBadge">1-minute averages</span>
                        </span>
                    </div>
                    <canvas id="latencyChart" style="max-height: 400px;"></canvas>
                </div>

                <!-- Min/Max Latency Chart -->
                <div class="chartCard">
                    <div class="chartTitle">
                        <span>
                            <i class="fas fa-chart-area"></i> Latency Range (Min/Max)
                            <span class="tierBadge" id="rangeTierBadge">1-minute averages</span>
                        </span>
                    </div>
                    <canvas id="rangeChart" style="max-height: 400px;"></canvas>
                </div>

                <!-- Sample Count Chart -->
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
        let latencyChart = null;
        let rangeChart = null;
        let sampleChart = null;
        let rawPingLatencyChart = null;
        let isRefreshing = false;

        // Load all metrics
        async function loadAllMetrics() {
            if (isRefreshing) return;
            isRefreshing = true;

            try {
                const refreshBtn = document.querySelector('.refreshButton i');
                if (refreshBtn) {
                    refreshBtn.style.animation = 'spin 1s linear infinite';
                }

                const hasData = await updateAllCharts();

                // Also update the raw ping latency chart (24 hours)
                await updateRawPingLatencyChart();

                document.getElementById('loadingIndicator').style.display = 'none';
                // Keep the controls visible even when there's no data
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
            const unit = getTimeUnit(hours);
            return {
                minute: 'HH:mm',
                hour: 'MMM d, HH:mm',
                day: 'MMM d',
                week: 'MMM d, yyyy'
            };
        }

        // Update all charts
        async function updateAllCharts() {
            try {
                const hours = parseInt(document.getElementById('timeRange').value);
                const response = await fetch(`/api/plugin/fpp-plugin-watcher/metrics/ping/rollup?hours=${hours}`);
                const data = await response.json();

                const noDataEl = document.getElementById('noDataMessage');
                const statsBarSection = document.getElementById('statsBarSection');
                const rollupChartsSection = document.getElementById('rollupChartsSection');

                if (!data.success || !data.data || data.data.length === 0) {
                    console.warn('No connectivity rollup data available');
                    if (statsBarSection) statsBarSection.style.display = 'none';
                    if (rollupChartsSection) rollupChartsSection.style.display = 'none';
                    if (noDataEl) noDataEl.style.display = 'block';
                    return false;
                }

                if (noDataEl) noDataEl.style.display = 'none';
                if (statsBarSection) statsBarSection.style.display = 'block';
                if (rollupChartsSection) rollupChartsSection.style.display = 'block';

                // Update tier badges
                if (data.tier_info) {
                    const tierLabel = data.tier_info.label;
                    document.getElementById('latencyTierBadge').textContent = tierLabel;
                    document.getElementById('rangeTierBadge').textContent = tierLabel;
                    document.getElementById('sampleTierBadge').textContent = tierLabel;
                }

                // Calculate statistics
                const latencyValues = data.data.map(d => d.avg_latency).filter(v => v !== null);
                const allMinLatency = data.data.map(d => d.min_latency).filter(v => v !== null);
                const allMaxLatency = data.data.map(d => d.max_latency).filter(v => v !== null);

                const currentLatency = latencyValues[latencyValues.length - 1] || 0;
                const avgLatency = latencyValues.reduce((a, b) => a + b, 0) / latencyValues.length;
                const minLatency = Math.min(...allMinLatency);
                const maxLatency = Math.max(...allMaxLatency);

                // Update stats
                document.getElementById('currentLatency').textContent = currentLatency.toFixed(2) + ' ms';
                document.getElementById('avgLatency').textContent = avgLatency.toFixed(2) + ' ms';
                document.getElementById('minLatency').textContent = minLatency.toFixed(2) + ' ms';
                document.getElementById('maxLatency').textContent = maxLatency.toFixed(2) + ' ms';
                document.getElementById('dataPoints').textContent = data.data.length.toLocaleString();

                // Update charts
                updateLatencyChart(data.data, hours);
                updateRangeChart(data.data, hours);
                updateSampleChart(data.data, hours);
                return true;
            } catch (error) {
                console.error('Error updating charts:', error);
                const noDataEl = document.getElementById('noDataMessage');
                const statsBarSection = document.getElementById('statsBarSection');
                const rollupChartsSection = document.getElementById('rollupChartsSection');
                if (statsBarSection) statsBarSection.style.display = 'none';
                if (rollupChartsSection) rollupChartsSection.style.display = 'none';
                if (noDataEl) noDataEl.style.display = 'block';
                return false;
            }
        }

        // Update latency chart
        function updateLatencyChart(data, hours) {
            const chartData = data.map(entry => ({
                x: entry.timestamp * 1000,
                y: entry.avg_latency
            }));

            if (latencyChart) {
                latencyChart.data.datasets[0].data = chartData;
                latencyChart.options.scales.x.time.unit = getTimeUnit(hours);
                latencyChart.options.scales.x.time.displayFormats = getTimeFormats(hours);
                latencyChart.update('none');
            } else {
                const ctx = document.getElementById('latencyChart').getContext('2d');
                latencyChart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        datasets: [{
                            label: 'Average Latency (ms)',
                            data: chartData,
                            borderColor: 'rgb(102, 126, 234)',
                            backgroundColor: 'rgba(102, 126, 234, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4,
                            pointRadius: 0,
                            pointHoverRadius: 5
                        }]
                    },
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
                                position: 'top'
                            },
                            tooltip: {
                                callbacks: {
                                    title: function(context) {
                                        const date = new Date(context[0].parsed.x);
                                        return date.toLocaleString();
                                    },
                                    label: function(context) {
                                        return 'Latency: ' + context.parsed.y.toFixed(2) + ' ms';
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

        // Update range chart (min/max)
        function updateRangeChart(data, hours) {
            const minData = data.map(entry => ({
                x: entry.timestamp * 1000,
                y: entry.min_latency
            }));

            const maxData = data.map(entry => ({
                x: entry.timestamp * 1000,
                y: entry.max_latency
            }));

            const avgData = data.map(entry => ({
                x: entry.timestamp * 1000,
                y: entry.avg_latency
            }));

            if (rangeChart) {
                rangeChart.data.datasets[0].data = minData;
                rangeChart.data.datasets[1].data = avgData;
                rangeChart.data.datasets[2].data = maxData;
                rangeChart.options.scales.x.time.unit = getTimeUnit(hours);
                rangeChart.options.scales.x.time.displayFormats = getTimeFormats(hours);
                rangeChart.update('none');
            } else {
                const ctx = document.getElementById('rangeChart').getContext('2d');
                rangeChart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        datasets: [
                            {
                                label: 'Min Latency',
                                data: minData,
                                borderColor: 'rgb(56, 239, 125)',
                                backgroundColor: 'rgba(56, 239, 125, 0.1)',
                                borderWidth: 2,
                                fill: false,
                                tension: 0.4,
                                pointRadius: 0,
                                pointHoverRadius: 5
                            },
                            {
                                label: 'Avg Latency',
                                data: avgData,
                                borderColor: 'rgb(102, 126, 234)',
                                backgroundColor: 'rgba(102, 126, 234, 0.1)',
                                borderWidth: 2,
                                fill: false,
                                tension: 0.4,
                                pointRadius: 0,
                                pointHoverRadius: 5
                            },
                            {
                                label: 'Max Latency',
                                data: maxData,
                                borderColor: 'rgb(245, 87, 108)',
                                backgroundColor: 'rgba(245, 87, 108, 0.1)',
                                borderWidth: 2,
                                fill: false,
                                tension: 0.4,
                                pointRadius: 0,
                                pointHoverRadius: 5
                            }
                        ]
                    },
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
                                position: 'top'
                            },
                            tooltip: {
                                callbacks: {
                                    title: function(context) {
                                        const date = new Date(context[0].parsed.x);
                                        return date.toLocaleString();
                                    },
                                    label: function(context) {
                                        return context.dataset.label + ': ' + context.parsed.y.toFixed(2) + ' ms';
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

        // Update sample count chart
        function updateSampleChart(data, hours) {
            const chartData = data.map(entry => ({
                x: entry.timestamp * 1000,
                y: entry.sample_count
            }));

            if (sampleChart) {
                sampleChart.data.datasets[0].data = chartData;
                sampleChart.options.scales.x.time.unit = getTimeUnit(hours);
                sampleChart.options.scales.x.time.displayFormats = getTimeFormats(hours);
                sampleChart.update('none');
            } else {
                const ctx = document.getElementById('sampleChart').getContext('2d');
                sampleChart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        datasets: [{
                            label: 'Sample Count',
                            data: chartData,
                            backgroundColor: 'rgba(79, 172, 254, 0.6)',
                            borderColor: 'rgb(79, 172, 254)',
                            borderWidth: 1
                        }]
                    },
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
                                position: 'top'
                            },
                            tooltip: {
                                callbacks: {
                                    title: function(context) {
                                        const date = new Date(context[0].parsed.x);
                                        return date.toLocaleString();
                                    },
                                    label: function(context) {
                                        return 'Samples: ' + context.parsed.y;
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
                                    text: 'Number of Samples',
                                    font: { size: 14, weight: 'bold' }
                                },
                                ticks: {
                                    callback: function(value) {
                                        return value.toFixed(0);
                                    }
                                }
                            }
                        }
                    }
                });
            }
        }

        // Update raw ping latency chart with selected time range
        async function updateRawPingLatencyChart() {
            try {
                const hours = parseInt(document.getElementById('rawTimeRange').value);
                const response = await fetch(`/api/plugin/fpp-plugin-watcher/metrics/ping/raw?hours=${hours}`);
                const metricsData = await response.json();

                if (!metricsData.success || !metricsData.data || metricsData.data.length === 0) {
                    console.warn('No raw connectivity data available');
                    return;
                }

                // Group data by host
                const dataByHost = {};
                metricsData.data.forEach(entry => {
                    if (!dataByHost[entry.host]) {
                        dataByHost[entry.host] = [];
                    }
                    dataByHost[entry.host].push({
                        x: entry.timestamp * 1000, // Convert to milliseconds for Chart.js
                        y: entry.latency
                    });
                });

                // Create datasets for each host
                const colors = [
                    { border: 'rgb(102, 126, 234)', bg: 'rgba(102, 126, 234, 0.1)' },
                    { border: 'rgb(56, 239, 125)', bg: 'rgba(56, 239, 125, 0.1)' },
                    { border: 'rgb(240, 147, 251)', bg: 'rgba(240, 147, 251, 0.1)' },
                    { border: 'rgb(79, 172, 254)', bg: 'rgba(79, 172, 254, 0.1)' }
                ];

                const datasets = Object.keys(dataByHost).map((host, index) => {
                    const color = colors[index % colors.length];
                    return {
                        label: host,
                        data: dataByHost[host],
                        borderColor: color.border,
                        backgroundColor: color.bg,
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4,
                        pointRadius: 2,
                        pointHoverRadius: 5
                    };
                });

                // Determine appropriate time unit based on hours
                const timeUnit = hours <= 4 ? 'minute' : 'hour';
                const displayFormats = hours <= 4 ?
                    { minute: 'HH:mm', hour: 'MMM d, HH:mm' } :
                    { hour: 'MMM d, HH:mm' };

                // Update existing chart or create new one
                if (rawPingLatencyChart) {
                    // Update existing chart data and time scale without destroying
                    rawPingLatencyChart.data.datasets = datasets;
                    rawPingLatencyChart.options.scales.x.time.unit = timeUnit;
                    rawPingLatencyChart.options.scales.x.time.displayFormats = displayFormats;
                    rawPingLatencyChart.update('none'); // 'none' mode = no animation, instant update
                } else {
                    // Create new chart on first load
                    const ctx = document.getElementById('rawPingLatencyChart').getContext('2d');
                    rawPingLatencyChart = new Chart(ctx, {
                        type: 'line',
                        data: {
                            datasets: datasets
                        },
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
                                        font: {
                                            size: 12
                                        }
                                    }
                                },
                                tooltip: {
                                    callbacks: {
                                        title: function(context) {
                                            const date = new Date(context[0].parsed.x);
                                            return date.toLocaleString();
                                        },
                                        label: function(context) {
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
                                        font: {
                                            size: 14,
                                            weight: 'bold'
                                        }
                                    },
                                    grid: {
                                        display: true,
                                        color: 'rgba(0, 0, 0, 0.05)'
                                    }
                                },
                                y: {
                                    beginAtZero: true,
                                    title: {
                                        display: true,
                                        text: 'Latency (ms)',
                                        font: {
                                            size: 14,
                                            weight: 'bold'
                                        }
                                    },
                                    grid: {
                                        display: true,
                                        color: 'rgba(0, 0, 0, 0.05)'
                                    }
                                }
                            }
                        }
                    });
                }
            } catch (error) {
                console.error('Error loading raw ping latency metrics:', error);
            }
        }

        // Auto-refresh every 30 seconds
        setInterval(() => {
            if (!isRefreshing) {
                loadAllMetrics();
            }
        }, 30000);

        // Load data on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadAllMetrics();
        });
    </script>
</body>
</html>
