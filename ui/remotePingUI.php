<?php
require_once __DIR__ . '/../classes/autoload.php';
include_once __DIR__ . '/../lib/core/config.php';
include_once __DIR__ . '/../lib/core/watcherCommon.php';

use Watcher\Http\ApiClient;
use Watcher\UI\ViewHelpers;

$config = readPluginConfig();
$localSystem = ApiClient::getInstance()->get('http://127.0.0.1/api/fppd/status', 5) ?: [];
$access = ViewHelpers::checkDashboardAccess($config, $localSystem, 'multiSyncMetricsEnabled');

ViewHelpers::renderCSSIncludes($access['show']);
?>
<style>
    .perHostStats { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
    .hostStatCard { background: #fff; border-radius: 8px; padding: 1rem; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border-left: 4px solid #007bff; }
    .hostStatCard .hostname { font-weight: 600; font-size: 1rem; color: #212529; margin-bottom: 0.25rem; }
    .hostStatCard .address { font-size: 0.8rem; color: #6c757d; margin-bottom: 0.75rem; }
    .hostStatCard .stats-row { display: flex; justify-content: space-between; gap: 0.5rem; }
    .hostStatCard .stat { text-align: center; flex: 1; }
    .hostStatCard .stat-label { font-size: 0.7rem; color: #6c757d; text-transform: uppercase; }
    .hostStatCard .stat-value { font-size: 1rem; font-weight: 600; color: #212529; }
    .hostStatCard .stat-value.success { color: #28a745; }
    .hostStatCard .stat-value.warning { color: #ffc107; }
    .hostStatCard .stat-value.danger { color: #dc3545; }
</style>

<div class="metricsContainer" data-watcher-page="remotePingUI">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <h2 style="margin: 0; color: #212529;">
            <i class="fas fa-server"></i> Multi-Sync Host Ping Metrics
        </h2>
        <span id="lastUpdate" style="font-size: 0.875rem; color: #6c757d;"></span>
    </div>

    <?php if (!ViewHelpers::renderAccessError($access)): ?>

    <?php ViewHelpers::renderLoadingSpinner('Loading multi-sync ping metrics data...'); ?>

    <div id="metricsContent" style="display: none;">
        <!-- Raw Ping Chart -->
        <div class="chartCard">
            <div class="chartTitle">
                <span><i class="fas fa-signal"></i> Real-time Multi-Sync Host Latency <span class="tierBadge">raw samples</span></span>
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
            <strong>No Data:</strong> No multi-sync ping data is available yet.
        </div>

        <h2 style="margin-bottom: 1.5rem; color: #212529;"><i class="fas fa-chart-line"></i> Rollup Metrics by Host</h2>

        <!-- Per-Host Statistics Cards -->
        <div id="perHostStatsSection" style="display: none;">
            <div class="perHostStats" id="perHostStats"></div>
        </div>

        <!-- Stats Bar -->
        <div id="statsBarSection" style="display: none;">
            <div class="statsBar">
                <div class="statItem"><div class="statLabel">Hosts Monitored</div><div class="statValue" id="hostsCount">--</div></div>
                <div class="statItem"><div class="statLabel">Overall Avg Latency</div><div class="statValue" id="overallAvgLatency">-- ms</div></div>
                <div class="statItem"><div class="statLabel">Best Host Latency</div><div class="statValue" id="bestLatency">-- ms</div></div>
                <div class="statItem"><div class="statLabel">Worst Host Latency</div><div class="statValue" id="worstLatency">-- ms</div></div>
                <div class="statItem"><div class="statLabel">Data Points</div><div class="statValue" id="dataPoints">--</div></div>
            </div>
        </div>

        <?php ViewHelpers::renderTimeRangeSelector('timeRange', 'page.updateAllCharts()', 'Rollup Time Range:'); ?>

        <!-- Rollup Charts -->
        <div id="rollupChartsSection" style="display: none;">
            <div class="chartCard">
                <div class="chartTitle"><span><i class="fas fa-chart-line"></i> Average Latency by Host <span class="tierBadge" id="latencyTierBadge">1-minute averages</span></span></div>
                <canvas id="latencyChart" class="chartCanvas"></canvas>
            </div>
            <div class="chartCard">
                <div class="chartTitle"><span><i class="fas fa-check-circle"></i> Success Rate by Host <span class="tierBadge" id="successTierBadge">1-minute averages</span></span></div>
                <canvas id="successChart" class="chartCanvas"></canvas>
            </div>
        </div>
    </div>

    <?php ViewHelpers::renderRefreshButton(); ?>
    <?php endif; ?>
</div>
