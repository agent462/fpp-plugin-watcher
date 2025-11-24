<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Watcher - System Information</title>
    <link rel="stylesheet" href="/css/fpp-bootstrap/dist-new/fpp-bootstrap-5-3.css">
    <link rel="stylesheet" href="/css/fpp.css">
    <link rel="stylesheet" href="/plugin.php?plugin=fpp-plugin-watcher&file=css/displayUI.css&nopage=1">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
</head>
<body>
    <div class="systemMonitorContainer">
        <h2 style="margin-bottom: 2rem; color: #212529;">
            <i class="fas fa-chart-line"></i> Watcher System Information
        </h2>

        <div id="loadingIndicator" class="loadingSpinner">
            <i class="fas fa-spinner"></i>
            <p>Loading system information...</p>
        </div>

        <div id="systemStats" style="display: none;">
            <!-- Stats Grid -->
            <div class="statsGrid">
                <div class="statCard success">
                    <div class="statIcon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="statLabel">System Uptime</div>
                    <div class="statValue" id="uptimeValue">--</div>
                    <div class="statSubtext" id="uptimeSubtext">Loading...</div>
                </div>

                <div class="statCard info">
                    <div class="statIcon">
                        <i class="fas fa-microchip"></i>
                    </div>
                    <div class="statLabel">CPU Usage</div>
                    <div class="statValue" id="cpuValue">--%</div>
                    <div class="statSubtext" id="cpuSubtext">Loading...</div>
                </div>

                <div class="statCard warning">
                    <div class="statIcon">
                        <i class="fas fa-memory"></i>
                    </div>
                    <div class="statLabel">Memory Usage</div>
                    <div class="statValue" id="memoryValue">--%</div>
                    <div class="statSubtext" id="memorySubtext">Loading...</div>
                </div>
            </div>

            <!-- Temperature Sensors -->
            <div class="chartContainer" id="temperaturePanel" style="display: none;">
                <div class="panelTitle">
                    <i class="fas fa-thermometer-half"></i> Temperature Sensors
                </div>
                <div id="temperatureGraph">
                    <!-- Temperature graph will be populated here -->
                </div>
            </div>

            <!-- Storage Information -->
            <div class="chartContainer">
                <div class="panelTitle">
                    <i class="fas fa-hdd"></i> Storage Usage
                </div>
                <div id="storageInfo">
                    <!-- Storage info will be populated here -->
                </div>
            </div>

            <!-- System Details Panel -->
            <div class="detailsPanel">
                <div class="panelTitle">
                    <i class="fas fa-server"></i> System Information
                </div>
                <div id="systemDetails">
                    <!-- System details will be populated here -->
                </div>
            </div>

            <!-- Advanced View Data Panel -->
            <div class="detailsPanel">
                <div class="panelTitle">
                    <i class="fas fa-chart-bar"></i> Advanced System Information
                </div>
                <div id="advancedMetrics">
                    <!-- Advanced metrics will be populated here -->
                </div>
            </div>
        </div>

        <button class="refreshButton" onclick="loadSystemData()" title="Refresh Data">
            <i class="fas fa-sync-alt"></i>
        </button>
    </div>

    <script src="/plugin.php?plugin=fpp-plugin-watcher&file=js/displayUI.js&nopage=1"></script>

</body>
</html>
