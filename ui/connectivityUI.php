<?php
require_once __DIR__ . '/../classes/autoload.php';
require_once __DIR__ . '/../classes/Watcher/UI/ViewHelpers.php';

use Watcher\UI\ViewHelpers;

ViewHelpers::renderCSSIncludes(true);
?>

<div class="metricsContainer" data-watcher-page="connectivityUI">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <h2 style="margin: 0; color: #212529;">
            <i class="fas fa-network-wired"></i> Connectivity Metrics
        </h2>
        <span id="lastUpdate" style="font-size: 0.875rem; color: #6c757d;"></span>
    </div>

    <!-- Network Reset Warning Banner -->
    <div id="networkResetBanner" class="warningBox" style="display: none; margin-bottom: 1.5rem;">
        <div style="display: flex; align-items: flex-start; gap: 1rem;">
            <i class="fas fa-exclamation-triangle" style="font-size: 1.5rem; color: #856404; margin-top: 0.25rem;"></i>
            <div style="flex: 1;">
                <strong style="font-size: 1.1rem;">Network Adapter Reset Triggered</strong>
                <p id="resetDetails" style="margin: 0.5rem 0;"></p>
                <p style="margin: 0.5rem 0; color: #856404;">
                    <strong>Note:</strong> This may explain missing or incomplete metrics data. The connectivity check daemon
                    is currently paused to prevent repeated resets.
                </p>
                <button onclick="page.clearNetworkResetState()" class="btn btn-warning" style="margin-top: 0.5rem;">
                    <i class="fas fa-redo"></i> Clear State &amp; Resume Monitoring
                </button>
            </div>
        </div>
    </div>

    <?php ViewHelpers::renderLoadingSpinner('Loading connectivity metrics data...'); ?>

    <div id="metricsContent" style="display: none;">
        <!-- Raw Ping Chart -->
        <div class="chartCard">
            <div class="chartTitle">
                <span>
                    <i class="fas fa-signal"></i> Real-time Network Latency
                    <span class="tierBadge">raw samples</span>
                </span>
            </div>
            <?php
            ViewHelpers::renderTimeRangeSelector(
                'rawTimeRange',
                'page.updateRawPingLatencyChart()',
                'Time Range:',
                [
                    '2' => 'Last 2 Hours',
                    '4' => 'Last 4 Hours',
                    '8' => 'Last 8 Hours',
                    '12' => 'Last 12 Hours',
                    '24' => 'Last 24 Hours'
                ]
            );
            ?>
            <canvas id="rawPingLatencyChart" class="chartCanvas"></canvas>
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

        <?php ViewHelpers::renderTimeRangeSelector('timeRange', 'page.updateAllCharts()', 'Rollup Time Range:'); ?>

        <!-- Rollup Charts -->
        <div id="rollupChartsSection" style="display: none;">
            <div class="chartCard">
                <div class="chartTitle">
                    <span>
                        <i class="fas fa-chart-line"></i> Ping Latency Over Time
                        <span class="tierBadge" id="latencyTierBadge">1-minute averages</span>
                    </span>
                </div>
                <canvas id="latencyChart" class="chartCanvas"></canvas>
            </div>

            <div class="chartCard">
                <div class="chartTitle">
                    <span>
                        <i class="fas fa-chart-area"></i> Latency Range (Min/Max)
                        <span class="tierBadge" id="rangeTierBadge">1-minute averages</span>
                    </span>
                </div>
                <canvas id="rangeChart" class="chartCanvas"></canvas>
            </div>

            <div class="chartCard">
                <div class="chartTitle">
                    <span>
                        <i class="fas fa-chart-bar"></i> Ping Sample Count
                        <span class="tierBadge" id="sampleTierBadge">1-minute averages</span>
                    </span>
                </div>
                <canvas id="sampleChart" class="chartCanvas"></canvas>
            </div>
        </div>
    </div>

    <?php ViewHelpers::renderRefreshButton(); ?>
</div>
