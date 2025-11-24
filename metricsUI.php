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
    <link rel="stylesheet" href="/plugin.php?plugin=fpp-plugin-watcher&file=css/commonUI.css&nopage=1">
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

    <script src="/plugin.php?plugin=fpp-plugin-watcher&file=js/metricsUI.js&nopage=1"></script>

</body>
</html>
