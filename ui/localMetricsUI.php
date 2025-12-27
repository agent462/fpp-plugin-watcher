<?php
require_once __DIR__ . '/../classes/autoload.php';
require_once __DIR__ . '/../classes/Watcher/UI/ViewHelpers.php';
include_once __DIR__ . '/../lib/core/config.php';

use Watcher\UI\ViewHelpers;
use Watcher\Controllers\NetworkAdapter;

$config = readPluginConfig();
$configuredAdapter = $config['networkAdapter'] ?? 'default';
$defaultAdapter = $configuredAdapter === 'default' ? NetworkAdapter::getInstance()->detectActiveInterface() : $configuredAdapter;

ViewHelpers::renderCSSIncludes(true);
?>
<script>window.watcherConfig = { defaultAdapter: <?php echo json_encode($defaultAdapter, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?> };</script>

<div class="metricsContainer" data-watcher-page="localMetricsUI">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <h2 style="margin: 0; color: #212529;">
            <i class="fas fa-chart-area"></i> System Metrics Dashboard
        </h2>
        <span id="lastUpdate" style="font-size: 0.875rem; color: #6c757d;"></span>
    </div>

    <div id="metricsContent">
        <?php ViewHelpers::renderTimeRangeSelector('timeRange', 'page.updateAllCharts()'); ?>

        <!-- CPU Usage Chart -->
        <div class="chartCard" id="cpuCard">
            <div class="chartLoading" id="cpuLoading"><i class="fas fa-spinner fa-spin"></i><p>Loading CPU data...</p></div>
            <div class="chartTitle"><span><i class="fas fa-microchip"></i> CPU Usage (Averaged Across All Cores)</span></div>
            <canvas id="cpuChart" class="chartCanvas"></canvas>
        </div>

        <!-- Load Average Chart -->
        <div class="chartCard" id="loadCard">
            <div class="chartLoading" id="loadLoading"><i class="fas fa-spinner fa-spin"></i><p>Loading load average data...</p></div>
            <div class="chartTitle"><span><i class="fas fa-tachometer-alt"></i> Load Average</span></div>
            <canvas id="loadChart" class="chartCanvas"></canvas>
        </div>

        <!-- Memory Stats Bar -->
        <div class="statsBar">
            <div class="statItem"><div class="statLabel">Current Free Memory</div><div class="statValue" id="currentMemory">-- MB</div></div>
            <div class="statItem"><div class="statLabel">Average (24h)</div><div class="statValue" id="avgMemory">-- MB</div></div>
            <div class="statItem"><div class="statLabel">Current Buffer Cache</div><div class="statValue" id="currentBufferCache">-- MB</div></div>
            <div class="statItem"><div class="statLabel">Avg Buffer Cache</div><div class="statValue" id="avgBufferCache">-- MB</div></div>
        </div>

        <!-- Memory Chart -->
        <div class="chartCard" id="memoryCard">
            <div class="chartLoading" id="memoryLoading"><i class="fas fa-spinner fa-spin"></i><p>Loading memory data...</p></div>
            <div class="chartTitle">
                <span><i class="fas fa-memory"></i> Memory Usage</span>
                <span class="infoTooltip" title="Buffer Cache is memory used by Linux to cache frequently accessed files (like sequences). This memory is automatically released when applications need it, so high buffer cache usage is good - it means your system is efficiently caching data for faster access."><i class="fas fa-info-circle"></i></span>
            </div>
            <canvas id="memoryChart" class="chartCanvas"></canvas>
        </div>

        <!-- Disk Chart -->
        <div class="chartCard" id="diskCard">
            <div class="chartLoading" id="diskLoading"><i class="fas fa-spinner fa-spin"></i><p>Loading disk data...</p></div>
            <div class="chartTitle"><span><i class="fas fa-hdd"></i> Disk Free Space (Root)</span></div>
            <div id="diskStatusBar" class="systemStatusBar" style="display: none; background: #f8f9fa; padding: 1rem; border-radius: 6px; margin-bottom: 1.5rem; border: 1px solid #e9ecef;"></div>
            <canvas id="diskChart" class="chartCanvas"></canvas>
        </div>

        <!-- Network Chart -->
        <div class="chartCard" id="networkCard">
            <div class="chartLoading" id="networkLoading"><i class="fas fa-spinner fa-spin"></i><p>Loading network data...</p></div>
            <div class="chartTitle"><span><i class="fas fa-network-wired"></i> Network Bandwidth</span></div>
            <div class="chartControls">
                <div class="controlGroup">
                    <label for="interfaceSelect">Interface:</label>
                    <select id="interfaceSelect" onchange="page.refreshMetric('network');"><option value="eth0">eth0</option></select>
                </div>
            </div>
            <canvas id="networkChart" class="chartCanvas"></canvas>
        </div>

        <!-- Temperature Chart -->
        <div class="chartCard" id="thermalCard" style="display: none;">
            <div class="chartLoading" id="thermalLoading"><i class="fas fa-spinner fa-spin"></i><p>Loading temperature data...</p></div>
            <div class="chartTitle"><span><i class="fas fa-thermometer-half"></i> Temperature (Thermal Zones)</span></div>
            <div id="temperatureStatusBar" class="systemStatusBar" style="display: none; background: #f8f9fa; padding: 1rem; border-radius: 6px; margin-bottom: 1.5rem; border: 1px solid #e9ecef;"></div>
            <canvas id="thermalChart" class="chartCanvas"></canvas>
        </div>

        <!-- Wireless Chart -->
        <div class="chartCard" id="wirelessCard" style="display: none;">
            <div class="chartLoading" id="wirelessLoading"><i class="fas fa-spinner fa-spin"></i><p>Loading wireless data...</p></div>
            <div class="chartTitle"><span><i class="fas fa-wifi"></i> Wireless Signal Quality</span></div>
            <canvas id="wirelessChart" class="chartCanvas"></canvas>
        </div>

        <!-- Apache Chart -->
        <div class="chartCard" id="apacheCard" style="display: none;">
            <div class="chartLoading" id="apacheLoading"><i class="fas fa-spinner fa-spin"></i><p>Loading Apache data...</p></div>
            <div class="chartTitle"><span><i class="fas fa-server"></i> Apache Web Server</span></div>
            <canvas id="apacheChart" class="chartCanvas"></canvas>
        </div>

        <!-- Apache Workers Chart -->
        <div class="chartCard" id="apacheWorkersCard" style="display: none;">
            <div class="chartLoading" id="apacheWorkersLoading"><i class="fas fa-spinner fa-spin"></i><p>Loading worker data...</p></div>
            <div class="chartTitle">
                <span><i class="fas fa-users-cog"></i> Apache Worker States</span>
                <span class="infoTooltip" title="Shows what Apache workers are doing: Sending/Reading (active work), Keepalive (persistent connections waiting), Waiting (idle, ready for requests), Open (available capacity)."><i class="fas fa-info-circle"></i></span>
            </div>
            <canvas id="apacheWorkersChart" class="chartCanvas"></canvas>
        </div>
    </div>

    <?php ViewHelpers::renderRefreshButton(); ?>
</div>
