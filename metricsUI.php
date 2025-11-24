<?php
// Load configuration to get the default network adapter
include_once __DIR__ . '/lib/config.php';
$config = readPluginConfig();
$configuredAdapter = isset($config['networkAdapter']) ? $config['networkAdapter'] : 'default';

// If set to 'default', fall back to eth0
$defaultAdapter = ($configuredAdapter === 'default') ? 'eth0' : $configuredAdapter;
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Watcher - System Metrics</title>
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
    </style>
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
                        <select id="interfaceSelect" onchange="saveSelectedInterface(this.value); updateNetworkChart();">
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
        // Configuration from PHP
        const CONFIG_DEFAULT_ADAPTER = '<?php echo $defaultAdapter; ?>';
        const STORAGE_KEY_INTERFACE = 'watcher_selected_interface';

        let memoryChart = null;
        let cpuChart = null;
        let loadChart = null;
        let diskChart = null;
        let networkChart = null;
        let thermalChart = null;
        let wirelessChart = null;
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

                // Load available interfaces first
                await loadInterfaces();

                await Promise.all([
                    updateMemoryChart(),
                    updateCPUChart(),
                    updateLoadChart(),
                    updateDiskChart(),
                    updateNetworkChart(),
                    updateThermalChart(),
                    updateWirelessChart()
                ]);

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

        // Get the preferred interface - always use config default on initial load
        function getPreferredInterface() {
            return CONFIG_DEFAULT_ADAPTER;
        }

        // Save the selected interface to localStorage (for session continuity only)
        function saveSelectedInterface(interfaceName) {
            // Not saving to localStorage anymore - just triggers chart update
        }

        // Load available network interfaces
        async function loadInterfaces() {
            try {
                const response = await fetch('/api/plugin/fpp-plugin-watcher/metrics/interface/list');
                const data = await response.json();

                if (data.success && data.interfaces && data.interfaces.length > 0) {
                    const select = document.getElementById('interfaceSelect');

                    // Check if this is initial load (only has the hardcoded eth0 option)
                    const isInitialLoad = (select.options.length === 1);

                    // Get current selection: use preferred interface on initial load, otherwise preserve selection
                    const currentSelection = isInitialLoad ? getPreferredInterface() : select.value;

                    // Rebuild dropdown
                    select.innerHTML = '';
                    data.interfaces.forEach(iface => {
                        const option = document.createElement('option');
                        option.value = iface;
                        option.textContent = iface;
                        select.appendChild(option);
                    });

                    // Restore selection if it exists in the new list
                    if (currentSelection && data.interfaces.includes(currentSelection)) {
                        select.value = currentSelection;
                    } else if (data.interfaces.includes(CONFIG_DEFAULT_ADAPTER)) {
                        // Fall back to config default if available
                        select.value = CONFIG_DEFAULT_ADAPTER;
                    } else {
                        // Otherwise use the first interface
                        select.value = data.interfaces[0];
                    }
                }
            } catch (error) {
                console.error('Error loading interfaces:', error);
            }
        }

        // Get appropriate time unit based on hours
        function getTimeUnit(hours) {
            if (hours <= 1) return 'minute';
            if (hours <= 24) return 'hour';
            if (hours <= 168) return 'day';  // Up to 7 days
            return 'week';  // More than 7 days
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

        // Update all charts (called from time range selector)
        async function updateAllCharts() {
            await Promise.all([
                updateMemoryChart(),
                updateCPUChart(),
                updateLoadChart(),
                updateDiskChart(),
                updateNetworkChart(),
                updateThermalChart(),
                updateWirelessChart()
            ]);
        }

        // Update memory chart
        async function updateMemoryChart() {
            try {
                const hours = document.getElementById('timeRange').value;
                const response = await fetch(`/api/plugin/fpp-plugin-watcher/metrics/memory/free?hours=${hours}`);
                const data = await response.json();

                if (!data.success || !data.data || data.data.length === 0) {
                    console.error('No memory data available');
                    return;
                }

                // Calculate statistics
                const validData = data.data.filter(d => d.free_mb !== null);
                const memoryValues = validData.map(d => d.free_mb);
                const currentMemory = memoryValues[memoryValues.length - 1];
                const avgMemory = memoryValues.reduce((a, b) => a + b, 0) / memoryValues.length;
                const minMemory = Math.min(...memoryValues);
                const maxMemory = Math.max(...memoryValues);

                // Update stats
                document.getElementById('currentMemory').textContent = currentMemory.toFixed(1) + ' MB';
                document.getElementById('avgMemory').textContent = avgMemory.toFixed(1) + ' MB';
                document.getElementById('minMemory').textContent = minMemory.toFixed(1) + ' MB';
                document.getElementById('maxMemory').textContent = maxMemory.toFixed(1) + ' MB';

                // Prepare chart data
                const chartData = data.data.map(entry => ({
                    x: entry.timestamp * 1000, // Convert to milliseconds
                    y: entry.free_mb
                }));

                // Update or create chart
                if (memoryChart) {
                    memoryChart.data.datasets[0].data = chartData;
                    // Update time scale unit
                    memoryChart.options.scales.x.time.unit = getTimeUnit(hours);
                    memoryChart.options.scales.x.time.displayFormats = getTimeFormats(hours);
                    memoryChart.update('none');
                } else {
                    const ctx = document.getElementById('memoryChart').getContext('2d');
                    memoryChart = new Chart(ctx, {
                        type: 'line',
                        data: {
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
                                            return 'Free Memory: ' + context.parsed.y.toFixed(2) + ' MB';
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
                                    beginAtZero: false,
                                    title: {
                                        display: true,
                                        text: 'Free Memory (MB)',
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
                                        callback: function(value) {
                                            return value.toFixed(0) + ' MB';
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
            } catch (error) {
                console.error('Error updating memory chart:', error);
            }
        }

        // Update CPU chart
        async function updateCPUChart() {
            try {
                const hours = document.getElementById('timeRange').value;
                const response = await fetch(`/api/plugin/fpp-plugin-watcher/metrics/cpu/average?hours=${hours}`);
                const data = await response.json();

                if (!data.success || !data.data || data.data.length === 0) {
                    console.error('No CPU data available');
                    return;
                }

                // Prepare chart data
                const chartData = data.data.map(entry => ({
                    x: entry.timestamp * 1000,
                    y: entry.cpu_usage
                }));

                // Update or create chart
                if (cpuChart) {
                    cpuChart.data.datasets[0].data = chartData;
                    // Update time scale unit
                    cpuChart.options.scales.x.time.unit = getTimeUnit(hours);
                    cpuChart.options.scales.x.time.displayFormats = getTimeFormats(hours);
                    cpuChart.update('none');
                } else {
                    const ctx = document.getElementById('cpuChart').getContext('2d');
                    cpuChart = new Chart(ctx, {
                        type: 'line',
                        data: {
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
                                            return 'CPU Usage: ' + context.parsed.y.toFixed(2) + '%';
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
                                    max: 100,
                                    title: {
                                        display: true,
                                        text: 'CPU Usage (%)',
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
                                        callback: function(value) {
                                            return value.toFixed(0) + '%';
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
            } catch (error) {
                console.error('Error updating CPU chart:', error);
            }
        }

        // Update load average chart
        async function updateLoadChart() {
            try {
                const hours = document.getElementById('timeRange').value;
                const response = await fetch(`/api/plugin/fpp-plugin-watcher/metrics/load/average?hours=${hours}`);
                const data = await response.json();

                if (!data.success || !data.data || data.data.length === 0) {
                    console.error('No load average data available');
                    return;
                }

                // Prepare chart data for three load averages
                const shortTermData = data.data.map(entry => ({
                    x: entry.timestamp * 1000,
                    y: entry.shortterm
                }));

                const midTermData = data.data.map(entry => ({
                    x: entry.timestamp * 1000,
                    y: entry.midterm
                }));

                const longTermData = data.data.map(entry => ({
                    x: entry.timestamp * 1000,
                    y: entry.longterm
                }));

                // Update or create chart
                if (loadChart) {
                    loadChart.data.datasets[0].data = shortTermData;
                    loadChart.data.datasets[1].data = midTermData;
                    loadChart.data.datasets[2].data = longTermData;
                    // Update time scale unit
                    loadChart.options.scales.x.time.unit = getTimeUnit(hours);
                    loadChart.options.scales.x.time.displayFormats = getTimeFormats(hours);
                    loadChart.update('none');
                } else {
                    const ctx = document.getElementById('loadChart').getContext('2d');
                    loadChart = new Chart(ctx, {
                        type: 'line',
                        data: {
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
                                            return context.dataset.label + ' Load: ' + context.parsed.y.toFixed(2);
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
                                        text: 'Load Average',
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
                                        callback: function(value) {
                                            return value.toFixed(2);
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
            } catch (error) {
                console.error('Error updating load average chart:', error);
            }
        }

        // Update disk chart
        async function updateDiskChart() {
            try {
                const hours = document.getElementById('timeRange').value;
                const response = await fetch(`/api/plugin/fpp-plugin-watcher/metrics/disk/free?hours=${hours}`);
                const data = await response.json();

                if (!data.success || !data.data || data.data.length === 0) {
                    console.error('No disk data available');
                    return;
                }

                // Prepare chart data
                const chartData = data.data.map(entry => ({
                    x: entry.timestamp * 1000,
                    y: entry.free_gb
                }));

                // Update or create chart
                if (diskChart) {
                    diskChart.data.datasets[0].data = chartData;
                    // Update time scale unit
                    diskChart.options.scales.x.time.unit = getTimeUnit(hours);
                    diskChart.options.scales.x.time.displayFormats = getTimeFormats(hours);
                    diskChart.update('none');
                } else {
                    const ctx = document.getElementById('diskChart').getContext('2d');
                    diskChart = new Chart(ctx, {
                        type: 'line',
                        data: {
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
                                            return 'Free Space: ' + context.parsed.y.toFixed(2) + ' GB';
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
                                    beginAtZero: false,
                                    title: {
                                        display: true,
                                        text: 'Free Space (GB)',
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
                                        callback: function(value) {
                                            return value.toFixed(1) + ' GB';
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
            } catch (error) {
                console.error('Error updating disk chart:', error);
            }
        }

        // Update network chart
        async function updateNetworkChart() {
            try {
                const hours = document.getElementById('timeRange').value;
                const interface = document.getElementById('interfaceSelect').value;
                const response = await fetch(`/api/plugin/fpp-plugin-watcher/metrics/interface/bandwidth?interface=${interface}&hours=${hours}`);
                const data = await response.json();

                if (!data.success || !data.data || data.data.length === 0) {
                    console.error('No network data available');
                    return;
                }

                // Prepare chart data (two datasets: RX and TX)
                const rxData = data.data.map(entry => ({
                    x: entry.timestamp * 1000,
                    y: entry.rx_kbps
                }));

                const txData = data.data.map(entry => ({
                    x: entry.timestamp * 1000,
                    y: entry.tx_kbps
                }));

                // Update or create chart
                if (networkChart) {
                    networkChart.data.datasets[0].data = rxData;
                    networkChart.data.datasets[1].data = txData;
                    // Update time scale unit
                    networkChart.options.scales.x.time.unit = getTimeUnit(hours);
                    networkChart.options.scales.x.time.displayFormats = getTimeFormats(hours);
                    networkChart.update('none');
                } else {
                    const ctx = document.getElementById('networkChart').getContext('2d');
                    networkChart = new Chart(ctx, {
                        type: 'line',
                        data: {
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
                                            return context.dataset.label + ': ' + context.parsed.y.toFixed(2) + ' Kbps';
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
                                        text: 'Bandwidth (Kbps)',
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
                                        callback: function(value) {
                                            return value.toFixed(0) + ' Kbps';
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
            } catch (error) {
                console.error('Error updating network chart:', error);
            }
        }

        // Update thermal chart
        async function updateThermalChart() {
            try {
                const hours = document.getElementById('timeRange').value;
                const response = await fetch(`/api/plugin/fpp-plugin-watcher/metrics/thermal?hours=${hours}`);
                const data = await response.json();

                if (!data.success || !data.data || data.data.length === 0 || !data.zones || data.zones.length === 0) {
                    // Hide thermal card if no data available
                    document.getElementById('thermalCard').style.display = 'none';
                    return;
                }

                // Show thermal card
                document.getElementById('thermalCard').style.display = 'block';

                // Define colors for up to 8 thermal zones
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

                // Prepare datasets for each thermal zone
                const datasets = [];
                data.zones.forEach((zone, index) => {
                    const zoneData = data.data.map(entry => ({
                        x: entry.timestamp * 1000,
                        y: entry[zone]
                    }));

                    const colorIndex = index % zoneColors.length;
                    datasets.push({
                        label: zone,
                        data: zoneData,
                        borderColor: zoneColors[colorIndex].border,
                        backgroundColor: zoneColors[colorIndex].bg,
                        borderWidth: 2,
                        fill: false,
                        tension: 0.4,
                        pointRadius: 0,
                        pointHoverRadius: 5
                    });
                });

                // Update or create chart
                if (thermalChart) {
                    thermalChart.data.datasets = datasets;
                    // Update time scale unit
                    thermalChart.options.scales.x.time.unit = getTimeUnit(hours);
                    thermalChart.options.scales.x.time.displayFormats = getTimeFormats(hours);
                    thermalChart.update('none');
                } else {
                    const ctx = document.getElementById('thermalChart').getContext('2d');
                    thermalChart = new Chart(ctx, {
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
                                    position: 'top'
                                },
                                tooltip: {
                                    callbacks: {
                                        title: function(context) {
                                            const date = new Date(context[0].parsed.x);
                                            return date.toLocaleString();
                                        },
                                        label: function(context) {
                                            return context.dataset.label + ': ' + context.parsed.y.toFixed(1) + '°C';
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
                                    beginAtZero: false,
                                    title: {
                                        display: true,
                                        text: 'Temperature (°C)',
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
                                        callback: function(value) {
                                            return value.toFixed(0) + '°C';
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
            } catch (error) {
                console.error('Error updating thermal chart:', error);
                // Hide thermal card on error
                document.getElementById('thermalCard').style.display = 'none';
            }
        }

        // Update wireless chart
        async function updateWirelessChart() {
            try {
                const hours = document.getElementById('timeRange').value;
                const response = await fetch(`/api/plugin/fpp-plugin-watcher/metrics/wireless?hours=${hours}`);
                const data = await response.json();

                if (!data.success || !data.data || data.data.length === 0 || !data.interfaces || data.interfaces.length === 0) {
                    // Hide wireless card if no data available
                    document.getElementById('wirelessCard').style.display = 'none';
                    return;
                }

                // Show wireless card
                document.getElementById('wirelessCard').style.display = 'block';

                // Define colors for different metrics and interfaces
                const metricColors = {
                    signal_quality: { border: 'rgb(75, 192, 192)', bg: 'rgba(75, 192, 192, 0.1)' },
                    signal_power: { border: 'rgb(255, 99, 132)', bg: 'rgba(255, 99, 132, 0.1)' },
                    signal_noise: { border: 'rgb(255, 159, 64)', bg: 'rgba(255, 159, 64, 0.1)' }
                };

                // Additional colors for multiple interfaces
                const interfaceColorOffsets = [
                    { border: '', bg: '' },  // Use original colors
                    { border: 'rgb(153, 102, 255)', bg: 'rgba(153, 102, 255, 0.1)' },
                    { border: 'rgb(54, 162, 235)', bg: 'rgba(54, 162, 235, 0.1)' },
                    { border: 'rgb(201, 203, 207)', bg: 'rgba(201, 203, 207, 0.1)' }
                ];

                // Prepare datasets for each interface and metric combination
                const datasets = [];
                data.interfaces.forEach((iface, ifaceIndex) => {
                    if (data.available_metrics[iface]) {
                        data.available_metrics[iface].forEach(metric => {
                            const key = `${iface}_${metric}`;
                            const metricData = data.data.map(entry => ({
                                x: entry.timestamp * 1000,
                                y: entry[key]
                            }));

                            // Get color for this metric/interface combo
                            let color = metricColors[metric] || metricColors.signal_quality;
                            if (ifaceIndex > 0 && ifaceIndex < interfaceColorOffsets.length && interfaceColorOffsets[ifaceIndex].border) {
                                color = interfaceColorOffsets[ifaceIndex];
                            }

                            // Create friendly label
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

                // Update or create chart
                if (wirelessChart) {
                    wirelessChart.data.datasets = datasets;
                    // Update time scale unit
                    wirelessChart.options.scales.x.time.unit = getTimeUnit(hours);
                    wirelessChart.options.scales.x.time.displayFormats = getTimeFormats(hours);
                    wirelessChart.update('none');
                } else {
                    const ctx = document.getElementById('wirelessChart').getContext('2d');
                    wirelessChart = new Chart(ctx, {
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
                                    position: 'top'
                                },
                                tooltip: {
                                    callbacks: {
                                        title: function(context) {
                                            const date = new Date(context[0].parsed.x);
                                            return date.toLocaleString();
                                        },
                                        label: function(context) {
                                            return context.dataset.label + ': ' + context.parsed.y.toFixed(1);
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
                                    beginAtZero: false,
                                    title: {
                                        display: true,
                                        text: 'Signal Metrics',
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
                                        callback: function(value) {
                                            return value.toFixed(0);
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
            } catch (error) {
                console.error('Error updating wireless chart:', error);
                // Hide wireless card on error
                document.getElementById('wirelessCard').style.display = 'none';
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
