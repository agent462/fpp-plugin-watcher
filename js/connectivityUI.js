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
