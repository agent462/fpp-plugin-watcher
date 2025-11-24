<?php
// Load configuration to get the default network adapter
include_once __DIR__ . '/lib/config.php';
$config = readPluginConfig();
$configuredAdapter = isset($config['networkAdapter']) ? $config['networkAdapter'] : 'default';

// If set to 'default', auto-detect the active interface
if ($configuredAdapter === 'default') {
    $defaultAdapter = detectActiveNetworkInterface();
} else {
    $defaultAdapter = $configuredAdapter;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Watcher - System Metrics</title>
    <link rel="stylesheet" href="/css/fpp-bootstrap/dist-new/fpp-bootstrap-5-3.css">
    <link rel="stylesheet" href="/css/fpp.css">
    <link rel="stylesheet" href="/plugin.php?plugin=gpp-plugin-watcher&file=css/metricsUI.css&nopage=1">
    <script>
        window.config = window.config || {};
        window.config.defaultAdapter = <?php echo json_encode($defaultAdapter); ?>;
    </script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
</head>
<body>
    <div class="metricsContainer">
        <h2 style="margin-bottom: 1.5rem; color: #212529;">
            <i class="fas fa-chart-area"></i> System Metrics Dashboard
        </h2>

        <div id="loadingIndicator" class="loadingSpinner">
            <i class="fas fa-spinner"></i>
            <p>Loading metrics data...</p>
        </div>

        <div id="metricsContent" style="display: none;">
            <!-- Time Range Selector -->
            <div class="chartControls" style="margin-bottom: 1.5rem;">
                <div class="controlGroup">
                    <label for="timeRange">Time Range:</label>
                    <select id="timeRange" onchange="updateAllCharts()">
                        <option value="1">Last 1 Hour</option>
                        <option value="6">Last 6 Hours</option>
                        <option value="12" selected>Last 12 Hours</option>
                        <option value="24">Last 24 Hours</option>
                        <option value="48">Last 2 Days</option>
                        <option value="72">Last 3 Days</option>
                        <option value="168">Last 7 Days</option>
                        <option value="336">Last 2 Weeks</option>
                        <option value="672">Last 4 Weeks</option>
                        <option value="1344">Last 8 Weeks</option>
                    </select>
                </div>
            </div>

            <!-- CPU Usage Chart -->
            <div class="chartCard">
                <div class="chartTitle">
                    <span><i class="fas fa-microchip"></i> CPU Usage (Averaged Across All Cores)</span>
                </div>
                <canvas id="cpuChart" style="max-height: 400px;"></canvas>
            </div>

            <!-- Load Average Chart -->
            <div class="chartCard">
                <div class="chartTitle">
                    <span><i class="fas fa-tachometer-alt"></i> Load Average</span>
                </div>
                <canvas id="loadChart" style="max-height: 400px;"></canvas>
            </div>

            <!-- Stats Bar -->
            <div class="statsBar">
                <div class="statItem">
                    <div class="statLabel">Current Free Memory</div>
                    <div class="statValue" id="currentMemory">-- MB</div>
                </div>
                <div class="statItem">
                    <div class="statLabel">Average (24h)</div>
                    <div class="statValue" id="avgMemory">-- MB</div>
                </div>
                <div class="statItem">
                    <div class="statLabel">Minimum (24h)</div>
                    <div class="statValue" id="minMemory">-- MB</div>
                </div>
                <div class="statItem">
                    <div class="statLabel">Maximum (24h)</div>
                    <div class="statValue" id="maxMemory">-- MB</div>
                </div>
            </div>

            <!-- Free Memory Chart -->
            <div class="chartCard">
                <div class="chartTitle">
                    <span><i class="fas fa-memory"></i> Free Memory</span>
                </div>
                <canvas id="memoryChart" style="max-height: 400px;"></canvas>
            </div>

            <!-- Disk Free Chart -->
            <div class="chartCard">
                <div class="chartTitle">
                    <span><i class="fas fa-hdd"></i> Disk Free Space (Root)</span>
                </div>
                <canvas id="diskChart" style="max-height: 400px;"></canvas>
            </div>

            <!-- Network Interface Bandwidth Chart -->
            <div class="chartCard">
                <div class="chartTitle">
                    <span><i class="fas fa-network-wired"></i> Network Bandwidth</span>
                </div>
                <div class="chartControls">
                    <div class="controlGroup">
                        <label for="interfaceSelect">Interface:</label>
                        <select id="interfaceSelect" onchange="refreshMetric('network');">
                            <option value="eth0">eth0</option>
                        </select>
                    </div>
                </div>
                <canvas id="networkChart" style="max-height: 400px;"></canvas>
            </div>

            <!-- Thermal Zones Chart -->
            <div class="chartCard" id="thermalCard" style="display: none;">
                <div class="chartTitle">
                    <span><i class="fas fa-thermometer-half"></i> Temperature (Thermal Zones)</span>
                </div>
                <canvas id="thermalChart" style="max-height: 400px;"></canvas>
            </div>

            <!-- Wireless Metrics Chart -->
            <div class="chartCard" id="wirelessCard" style="display: none;">
                <div class="chartTitle">
                    <span><i class="fas fa-wifi"></i> Wireless Signal Quality</span>
                </div>
                <canvas id="wirelessChart" style="max-height: 400px;"></canvas>
            </div>
        </div>

        <button class="refreshButton" onclick="loadAllMetrics()" title="Refresh Data">
            <i class="fas fa-sync-alt"></i>
        </button>
    </div>

        <script>
        const charts = {};
        let isRefreshing = false;

        async function fetchJson(url) {
            const response = await fetch(url);
            return response.json();
        }

        function getSelectedHours() {
            return document.getElementById('timeRange').value;
        }

        function getDefaultAdapter() {
            return (window.config && window.config.defaultAdapter) ? window.config.defaultAdapter : 'default';
        }

        function getSelectedInterface() {
            const select = document.getElementById('interfaceSelect');
            return select && select.value ? select.value : getDefaultAdapter();
        }

        // Get time config (unit + formats) for chart time axes
        function getTimeConfig(hours) {
            let unit = 'week';
            if (hours <= 1) unit = 'minute';
            else if (hours <= 24) unit = 'hour';
            else if (hours <= 168) unit = 'day';

            const formats = {
                minute: 'HH:mm',
                hour: 'MMM d, HH:mm',
                day: 'MMM d',
                week: 'MMM d, yyyy'
            };

            return { unit, formats };
        }

        function buildChartOptions(hours, chartOptions = {}) {
            const timeConfig = getTimeConfig(hours);
            const {
                yLabel = 'Value',
                beginAtZero = false,
                yMax,
                yTickFormatter = (value) => value,
                tooltipLabel = (context) => `${context.dataset.label}: ${context.parsed.y}`
            } = chartOptions;

            return {
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
                            label: tooltipLabel
                        }
                    }
                },
                scales: {
                    x: {
                        type: 'time',
                        time: {
                            unit: timeConfig.unit,
                            displayFormats: timeConfig.formats,
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
                        beginAtZero,
                        ...(yMax !== undefined ? { max: yMax } : {}),
                        title: {
                            display: true,
                            text: yLabel,
                            font: {
                                size: 14,
                                weight: 'bold'
                            }
                        },
                        grid: {
                            display: true,
                            color: 'rgba(0, 0, 0, 0.05)'
                        },
                        ticks: {
                            callback: yTickFormatter
                        }
                    }
                }
            };
        }

        function renderChart(key, canvasId, datasets, hours, chartOptions = {}) {
            const ctx = document.getElementById(canvasId).getContext('2d');
            const options = buildChartOptions(hours, chartOptions);

            if (charts[key]) {
                charts[key].data.datasets = datasets;
                charts[key].options = options;
                charts[key].update('none');
            } else {
                charts[key] = new Chart(ctx, {
                    type: 'line',
                    data: { datasets },
                    options
                });
            }
        }

        const METRIC_DEFINITIONS = [
            {
                key: 'memory',
                canvasId: 'memoryChart',
                url: (hours) => `/api/plugin/fpp-plugin-watcher/metrics/memory/free?hours=${hours}`,
                prepare: (payload) => {
                    if (!payload || !payload.success || !payload.data || payload.data.length === 0) {
                        console.error('No memory data available');
                        return null;
                    }

                    const validData = payload.data.filter(d => d.free_mb !== null);
                    if (validData.length === 0) {
                        console.error('No memory data available');
                        return null;
                    }

                    const memoryValues = validData.map(d => d.free_mb);
                    const currentMemory = memoryValues[memoryValues.length - 1];
                    const avgMemory = memoryValues.reduce((a, b) => a + b, 0) / memoryValues.length;
                    const minMemory = Math.min(...memoryValues);
                    const maxMemory = Math.max(...memoryValues);

                    document.getElementById('currentMemory').textContent = currentMemory.toFixed(1) + ' MB';
                    document.getElementById('avgMemory').textContent = avgMemory.toFixed(1) + ' MB';
                    document.getElementById('minMemory').textContent = minMemory.toFixed(1) + ' MB';
                    document.getElementById('maxMemory').textContent = maxMemory.toFixed(1) + ' MB';

                    const chartData = payload.data.map(entry => ({
                        x: entry.timestamp * 1000,
                        y: entry.free_mb
                    }));

                    return {
                        datasets: [{
                            label: 'Free Memory (MB)',
                            data: chartData,
                            borderColor: 'rgb(102, 126, 234)',
                            backgroundColor: 'rgba(102, 126, 234, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4,
                            pointRadius: 0,
                            pointHoverRadius: 5
                        }],
                        chartOptions: {
                            yLabel: 'Free Memory (MB)',
                            beginAtZero: false,
                            yTickFormatter: (value) => value.toFixed(0) + ' MB',
                            tooltipLabel: (context) => 'Free Memory: ' + context.parsed.y.toFixed(2) + ' MB'
                        }
                    };
                }
            },
            {
                key: 'cpu',
                canvasId: 'cpuChart',
                url: (hours) => `/api/plugin/fpp-plugin-watcher/metrics/cpu/average?hours=${hours}`,
                prepare: (payload) => {
                    if (!payload || !payload.success || !payload.data || payload.data.length === 0) {
                        console.error('No CPU data available');
                        return null;
                    }

                    const chartData = payload.data.map(entry => ({
                        x: entry.timestamp * 1000,
                        y: entry.cpu_usage
                    }));

                    return {
                        datasets: [{
                            label: 'CPU Usage (%)',
                            data: chartData,
                            borderColor: 'rgb(245, 87, 108)',
                            backgroundColor: 'rgba(245, 87, 108, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4,
                            pointRadius: 0,
                            pointHoverRadius: 5
                        }],
                        chartOptions: {
                            yLabel: 'CPU Usage (%)',
                            beginAtZero: true,
                            yMax: 100,
                            yTickFormatter: (value) => value.toFixed(0) + '%',
                            tooltipLabel: (context) => 'CPU Usage: ' + context.parsed.y.toFixed(2) + '%'
                        }
                    };
                }
            },
            {
                key: 'load',
                canvasId: 'loadChart',
                url: (hours) => `/api/plugin/fpp-plugin-watcher/metrics/load/average?hours=${hours}`,
                prepare: (payload) => {
                    if (!payload || !payload.success || !payload.data || payload.data.length === 0) {
                        console.error('No load average data available');
                        return null;
                    }

                    const shortTermData = payload.data.map(entry => ({
                        x: entry.timestamp * 1000,
                        y: entry.shortterm
                    }));

                    const midTermData = payload.data.map(entry => ({
                        x: entry.timestamp * 1000,
                        y: entry.midterm
                    }));

                    const longTermData = payload.data.map(entry => ({
                        x: entry.timestamp * 1000,
                        y: entry.longterm
                    }));

                    return {
                        datasets: [
                            {
                                label: '1 min',
                                data: shortTermData,
                                borderColor: 'rgb(255, 99, 132)',
                                backgroundColor: 'rgba(255, 99, 132, 0.1)',
                                borderWidth: 2,
                                fill: false,
                                tension: 0.4,
                                pointRadius: 0,
                                pointHoverRadius: 5
                            },
                            {
                                label: '5 min',
                                data: midTermData,
                                borderColor: 'rgb(255, 159, 64)',
                                backgroundColor: 'rgba(255, 159, 64, 0.1)',
                                borderWidth: 2,
                                fill: false,
                                tension: 0.4,
                                pointRadius: 0,
                                pointHoverRadius: 5
                            },
                            {
                                label: '15 min',
                                data: longTermData,
                                borderColor: 'rgb(75, 192, 192)',
                                backgroundColor: 'rgba(75, 192, 192, 0.1)',
                                borderWidth: 2,
                                fill: false,
                                tension: 0.4,
                                pointRadius: 0,
                                pointHoverRadius: 5
                            }
                        ],
                        chartOptions: {
                            yLabel: 'Load Average',
                            beginAtZero: true,
                            yTickFormatter: (value) => value.toFixed(2),
                            tooltipLabel: (context) => context.dataset.label + ' Load: ' + context.parsed.y.toFixed(2)
                        }
                    };
                }
            },
            {
                key: 'disk',
                canvasId: 'diskChart',
                url: (hours) => `/api/plugin/fpp-plugin-watcher/metrics/disk/free?hours=${hours}`,
                prepare: (payload) => {
                    if (!payload || !payload.success || !payload.data || payload.data.length === 0) {
                        console.error('No disk data available');
                        return null;
                    }

                    const chartData = payload.data.map(entry => ({
                        x: entry.timestamp * 1000,
                        y: entry.free_gb
                    }));

                    return {
                        datasets: [{
                            label: 'Free Space (GB)',
                            data: chartData,
                            borderColor: 'rgb(56, 239, 125)',
                            backgroundColor: 'rgba(56, 239, 125, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4,
                            pointRadius: 0,
                            pointHoverRadius: 5
                        }],
                        chartOptions: {
                            yLabel: 'Free Space (GB)',
                            beginAtZero: false,
                            yTickFormatter: (value) => value.toFixed(1) + ' GB',
                            tooltipLabel: (context) => 'Free Space: ' + context.parsed.y.toFixed(2) + ' GB'
                        }
                    };
                }
            },
            {
                key: 'network',
                canvasId: 'networkChart',
                url: (hours) => `/api/plugin/fpp-plugin-watcher/metrics/interface/bandwidth?interface=${getSelectedInterface()}&hours=${hours}`,
                prepare: (payload) => {
                    if (!payload || !payload.success || !payload.data || payload.data.length === 0) {
                        console.error('No network data available');
                        return null;
                    }

                    const rxData = payload.data.map(entry => ({
                        x: entry.timestamp * 1000,
                        y: entry.rx_kbps
                    }));

                    const txData = payload.data.map(entry => ({
                        x: entry.timestamp * 1000,
                        y: entry.tx_kbps
                    }));

                    return {
                        datasets: [
                            {
                                label: 'Download (RX)',
                                data: rxData,
                                borderColor: 'rgb(79, 172, 254)',
                                backgroundColor: 'rgba(79, 172, 254, 0.1)',
                                borderWidth: 2,
                                fill: true,
                                tension: 0.4,
                                pointRadius: 0,
                                pointHoverRadius: 5
                            },
                            {
                                label: 'Upload (TX)',
                                data: txData,
                                borderColor: 'rgb(240, 147, 251)',
                                backgroundColor: 'rgba(240, 147, 251, 0.1)',
                                borderWidth: 2,
                                fill: true,
                                tension: 0.4,
                                pointRadius: 0,
                                pointHoverRadius: 5
                            }
                        ],
                        chartOptions: {
                            yLabel: 'Bandwidth (Kbps)',
                            beginAtZero: true,
                            yTickFormatter: (value) => value.toFixed(0) + ' Kbps',
                            tooltipLabel: (context) => context.dataset.label + ': ' + context.parsed.y.toFixed(2) + ' Kbps'
                        }
                    };
                }
            },
            {
                key: 'thermal',
                canvasId: 'thermalChart',
                cardId: 'thermalCard',
                url: (hours) => `/api/plugin/fpp-plugin-watcher/metrics/thermal?hours=${hours}`,
                prepare: (payload) => {
                    if (!payload || !payload.success || !payload.data || payload.data.length === 0 || !payload.zones || payload.zones.length === 0) {
                        return { hidden: true };
                    }

                    const zoneColors = [
                        { border: 'rgb(255, 99, 132)', bg: 'rgba(255, 99, 132, 0.1)' },
                        { border: 'rgb(54, 162, 235)', bg: 'rgba(54, 162, 235, 0.1)' },
                        { border: 'rgb(255, 206, 86)', bg: 'rgba(255, 206, 86, 0.1)' },
                        { border: 'rgb(75, 192, 192)', bg: 'rgba(75, 192, 192, 0.1)' },
                        { border: 'rgb(153, 102, 255)', bg: 'rgba(153, 102, 255, 0.1)' },
                        { border: 'rgb(255, 159, 64)', bg: 'rgba(255, 159, 64, 0.1)' },
                        { border: 'rgb(201, 203, 207)', bg: 'rgba(201, 203, 207, 0.1)' },
                        { border: 'rgb(83, 102, 255)', bg: 'rgba(83, 102, 255, 0.1)' }
                    ];

                    const datasets = payload.zones.map((zone, index) => {
                        const zoneData = payload.data.map(entry => ({
                            x: entry.timestamp * 1000,
                            y: entry[zone]
                        }));

                        const colorIndex = index % zoneColors.length;
                        return {
                            label: zone,
                            data: zoneData,
                            borderColor: zoneColors[colorIndex].border,
                            backgroundColor: zoneColors[colorIndex].bg,
                            borderWidth: 2,
                            fill: false,
                            tension: 0.4,
                            pointRadius: 0,
                            pointHoverRadius: 5
                        };
                    });

                    return {
                        datasets,
                        chartOptions: {
                            yLabel: 'Temperature (°C)',
                            beginAtZero: false,
                            yTickFormatter: (value) => value.toFixed(0) + '°C',
                            tooltipLabel: (context) => context.dataset.label + ': ' + context.parsed.y.toFixed(1) + '°C'
                        }
                    };
                }
            },
            {
                key: 'wireless',
                canvasId: 'wirelessChart',
                cardId: 'wirelessCard',
                url: (hours) => `/api/plugin/fpp-plugin-watcher/metrics/wireless?hours=${hours}`,
                prepare: (payload) => {
                    if (!payload || !payload.success || !payload.data || payload.data.length === 0 || !payload.interfaces || payload.interfaces.length === 0) {
                        return { hidden: true };
                    }

                    const metricColors = {
                        signal_quality: { border: 'rgb(75, 192, 192)', bg: 'rgba(75, 192, 192, 0.1)' },
                        signal_power: { border: 'rgb(255, 99, 132)', bg: 'rgba(255, 99, 132, 0.1)' },
                        signal_noise: { border: 'rgb(255, 159, 64)', bg: 'rgba(255, 159, 64, 0.1)' }
                    };

                    const interfaceColorOffsets = [
                        { border: '', bg: '' },
                        { border: 'rgb(153, 102, 255)', bg: 'rgba(153, 102, 255, 0.1)' },
                        { border: 'rgb(54, 162, 235)', bg: 'rgba(54, 162, 235, 0.1)' },
                        { border: 'rgb(201, 203, 207)', bg: 'rgba(201, 203, 207, 0.1)' }
                    ];

                    const datasets = [];
                    const availableMetrics = payload.available_metrics || {};

                    payload.interfaces.forEach((iface, ifaceIndex) => {
                        if (availableMetrics[iface]) {
                            availableMetrics[iface].forEach(metric => {
                                const key = `${iface}_${metric}`;
                                const metricData = payload.data.map(entry => ({
                                    x: entry.timestamp * 1000,
                                    y: entry[key]
                                }));

                                let color = metricColors[metric] || metricColors.signal_quality;
                                if (ifaceIndex > 0 && ifaceIndex < interfaceColorOffsets.length && interfaceColorOffsets[ifaceIndex].border) {
                                    color = interfaceColorOffsets[ifaceIndex];
                                }

                                let metricLabel = metric.replace('signal_', '').replace('_', ' ');
                                metricLabel = metricLabel.charAt(0).toUpperCase() + metricLabel.slice(1);

                                datasets.push({
                                    label: `${iface} - ${metricLabel}`,
                                    data: metricData,
                                    borderColor: color.border,
                                    backgroundColor: color.bg,
                                    borderWidth: 2,
                                    fill: false,
                                    tension: 0.4,
                                    pointRadius: 0,
                                    pointHoverRadius: 5
                                });
                            });
                        }
                    });

                    if (datasets.length === 0) {
                        return { hidden: true };
                    }

                    return {
                        datasets,
                        chartOptions: {
                            yLabel: 'Signal Metrics',
                            beginAtZero: false,
                            yTickFormatter: (value) => value.toFixed(0),
                            tooltipLabel: (context) => context.dataset.label + ': ' + context.parsed.y.toFixed(1)
                        }
                    };
                }
            }
        ];

        async function updateMetric(definition, hours) {
            try {
                const payload = await fetchJson(definition.url(hours));
                const prepared = definition.prepare(payload);

                if (!prepared || prepared.hidden) {
                    if (definition.cardId) {
                        document.getElementById(definition.cardId).style.display = 'none';
                    }
                    return;
                }

                if (definition.cardId) {
                    document.getElementById(definition.cardId).style.display = 'block';
                }

                renderChart(definition.key, definition.canvasId, prepared.datasets, hours, prepared.chartOptions);
            } catch (error) {
                console.error(`Error updating ${definition.key} chart:`, error);
                if (definition.cardId) {
                    document.getElementById(definition.cardId).style.display = 'none';
                }
            }
        }

        function refreshMetric(key) {
            const definition = METRIC_DEFINITIONS.find(item => item.key === key);
            if (definition) {
                updateMetric(definition, getSelectedHours());
            }
        }

        async function runMetricUpdates() {
            const hours = getSelectedHours();
            await Promise.all(METRIC_DEFINITIONS.map(def => updateMetric(def, hours)));
        }

        // Load available network interfaces
        async function loadInterfaces() {
            try {
                const response = await fetch('/api/plugin/fpp-plugin-watcher/metrics/interface/list');
                const data = await response.json();

                if (data.success && data.interfaces && data.interfaces.length > 0) {
                    const select = document.getElementById('interfaceSelect');
                    const isInitialLoad = (select.options.length === 1);
                    const currentSelection = isInitialLoad ? getDefaultAdapter() : select.value;

                    select.innerHTML = '';
                    data.interfaces.forEach(iface => {
                        const option = document.createElement('option');
                        option.value = iface;
                        option.textContent = iface;
                        select.appendChild(option);
                    });

                    if (currentSelection && data.interfaces.includes(currentSelection)) {
                        select.value = currentSelection;
                    } else if (data.interfaces.includes(getDefaultAdapter())) {
                        select.value = getDefaultAdapter();
                    } else {
                        select.value = data.interfaces[0];
                    }
                }
            } catch (error) {
                console.error('Error loading interfaces:', error);
            }
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

                await loadInterfaces();
                await runMetricUpdates();

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

        // Update all charts (called from time range selector)
        async function updateAllCharts() {
            await runMetricUpdates();
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
