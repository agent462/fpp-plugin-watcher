<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Watcher - Ping Metrics</title>
    <link rel="stylesheet" href="/css/fpp-bootstrap/dist-new/fpp-bootstrap-5-3.css">
    <link rel="stylesheet" href="/css/fpp.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
    <style>
        .metricsContainer {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1rem;
        }

        .chartCard {
            background-color: #fff;
            border: 1px solid #dee2e6;
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .chartTitle {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: #212529;
            border-bottom: 2px solid #007bff;
            padding-bottom: 0.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .chartControls {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        .controlGroup {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .controlGroup label {
            margin: 0;
            font-weight: 500;
            color: #6c757d;
        }

        .controlGroup select {
            padding: 0.25rem 0.5rem;
            border: 1px solid #ced4da;
            border-radius: 0.25rem;
            font-size: 0.875rem;
        }

        .loadingSpinner {
            text-align: center;
            padding: 3rem;
            color: #6c757d;
        }

        .loadingSpinner i {
            font-size: 3rem;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .refreshButton {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            z-index: 1000;
        }

        .refreshButton:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2);
        }

        .refreshButton i {
            font-size: 1.5rem;
        }

        .statsBar {
            display: flex;
            gap: 1rem;
            padding: 1rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
            color: white;
        }

        .statItem {
            flex: 1;
            text-align: center;
        }

        .statLabel {
            font-size: 0.875rem;
            opacity: 0.9;
            margin-bottom: 0.25rem;
        }

        .statValue {
            font-size: 1.5rem;
            font-weight: bold;
        }

        .tierBadge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            background: rgba(102, 126, 234, 0.2);
            border-radius: 1rem;
            font-size: 0.75rem;
            font-weight: 600;
            color: #667eea;
            margin-left: 0.5rem;
        }

        .infoBox {
            background: #e7f3ff;
            border-left: 4px solid #007bff;
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: 0.25rem;
        }

        .infoBox strong {
            color: #007bff;
        }
    </style>
</head>
<body>
    <div class="metricsContainer">
        <h2 style="margin-bottom: 1.5rem; color: #212529;">
            <i class="fas fa-network-wired"></i> Ping Connectivity Metrics
        </h2>

        <div class="infoBox">
            <strong>About Rollup Data:</strong>Data is automatically aggregated at different resolutions based on the selected time range
            for optimal performance and storage efficiency.
        </div>

        <div id="loadingIndicator" class="loadingSpinner">
            <i class="fas fa-spinner"></i>
            <p>Loading ping metrics data...</p>
        </div>

        <div id="noDataMessage" class="infoBox" style="display: none;">
            <strong>No Data:</strong> No rollup data is available for the selected time range yet. Try a shorter range or check back after more samples are collected.
        </div>

        <div id="metricsContent" style="display: none;">
            <!-- Time Range Selector -->
            <div class="chartControls" style="margin-bottom: 1.5rem;">
                <div class="controlGroup">
                    <label for="timeRange">Time Range:</label>
                    <select id="timeRange" onchange="updateAllCharts()">
                        <option value="1">Last 1 Hour</option>
                        <option value="6">Last 6 Hours</option>
                        <option value="12">Last 12 Hours</option>
                        <option value="24" selected>Last 24 Hours</option>
                        <option value="48">Last 2 Days</option>
                        <option value="72">Last 3 Days</option>
                        <option value="168">Last 7 Days</option>
                        <option value="336">Last 2 Weeks</option>
                        <option value="720">Last 30 Days</option>
                        <option value="2160">Last 90 Days</option>
                    </select>
                </div>
            </div>

            <!-- Stats Bar -->
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

        <button class="refreshButton" onclick="loadAllMetrics()" title="Refresh Data">
            <i class="fas fa-sync-alt"></i>
        </button>
    </div>

    <script>
        let latencyChart = null;
        let rangeChart = null;
        let sampleChart = null;
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

                document.getElementById('loadingIndicator').style.display = 'none';
                if (hasData) {
                    document.getElementById('metricsContent').style.display = 'block';
                }

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
                const metricsContent = document.getElementById('metricsContent');

                if (!data.success || !data.data || data.data.length === 0) {
                    console.warn('No ping rollup data available');
                    if (metricsContent) metricsContent.style.display = 'none';
                    if (noDataEl) noDataEl.style.display = 'block';
                    return false;
                }

                if (noDataEl) noDataEl.style.display = 'none';
                if (metricsContent) metricsContent.style.display = 'block';

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
                const metricsContent = document.getElementById('metricsContent');
                if (metricsContent) metricsContent.style.display = 'none';
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
