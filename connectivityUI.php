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

    <script src="/plugin.php?plugin=fpp-plugin-watcher&file=js/connectivityUI.js&nopage=1"></script>

</body>
</html>
