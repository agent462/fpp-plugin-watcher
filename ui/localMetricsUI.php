<?php
require_once __DIR__ . '/../classes/autoload.php';
include_once __DIR__ . '/../lib/core/config.php';

use Watcher\UI\ViewHelpers;
use Watcher\Controllers\NetworkAdapter;

$config = readPluginConfig();
$configuredAdapter = $config['networkAdapter'] ?? 'default';
$defaultAdapter = $configuredAdapter === 'default' ? NetworkAdapter::getInstance()->detectActiveInterface() : $configuredAdapter;

ViewHelpers::renderCSSIncludes(true);
?>
<script>window.watcherConfig = {
    defaultAdapter: <?php echo json_encode($defaultAdapter, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
    voltageRetentionDays: <?php echo intval($config['voltageRetentionDays'] ?? 1); ?>
};</script>

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

        <!-- Voltage Stats Bar (Raspberry Pi only) - labels updated dynamically by JS -->
        <div class="statsBar" id="voltageStatsBar" style="display: none;">
            <div class="statItem"><div class="statLabel" id="voltageStat0Label">--</div><div class="statValue" id="voltageStat0">-- V</div></div>
            <div class="statItem"><div class="statLabel" id="voltageStat1Label">--</div><div class="statValue" id="voltageStat1">-- V</div></div>
            <div class="statItem"><div class="statLabel" id="voltageStat2Label">--</div><div class="statValue" id="voltageStat2">-- V</div></div>
            <div class="statItem"><div class="statLabel" id="voltageStat3Label">--</div><div class="statValue" id="voltageStat3">-- V</div></div>
        </div>

        <!-- Voltage Chart (Raspberry Pi only) -->
        <div class="chartCard" id="voltageCard" style="display: none;">
            <div class="chartLoading" id="voltageLoading"><i class="fas fa-spinner fa-spin"></i><p>Loading voltage data...</p></div>
            <div class="chartTitle">
                <span><i class="fas fa-bolt"></i> Voltage Rails</span>
                <span class="infoTooltip" title="Raspberry Pi voltage rails. Monitors power supply (5V), core, and system voltages. Significant drops may indicate power supply issues."><i class="fas fa-info-circle"></i></span>
            </div>
            <div class="chartControls">
                <div class="controlGroup">
                    <label for="voltageTimeRange">Time Range:</label>
                    <select id="voltageTimeRange" onchange="page.refreshMetric('voltage');">
                        <option value="0.5">30 minutes</option>
                        <option value="1">1 hour</option>
                        <option value="2">2 hours</option>
                        <option value="4">4 hours</option>
                        <option value="8">8 hours</option>
                        <option value="12" selected>12 hours</option>
                        <option value="24">24 hours</option>
                        <?php if (($config['voltageRetentionDays'] ?? 1) >= 3): ?>
                        <option value="48">2 days</option>
                        <option value="72">3 days</option>
                        <?php endif; ?>
                        <?php if (($config['voltageRetentionDays'] ?? 1) >= 7): ?>
                        <option value="168">7 days</option>
                        <?php endif; ?>
                        <?php if (($config['voltageRetentionDays'] ?? 1) >= 14): ?>
                        <option value="336">14 days</option>
                        <?php endif; ?>
                        <?php if (($config['voltageRetentionDays'] ?? 1) >= 30): ?>
                        <option value="720">30 days</option>
                        <?php endif; ?>
                    </select>
                </div>
            </div>
            <div id="voltageStatusBar" class="systemStatusBar" style="display: none; background: #f8f9fa; padding: 1rem; border-radius: 6px; margin-bottom: 1.5rem; border: 1px solid #e9ecef;"></div>
            <canvas id="voltageChart" class="chartCanvas"></canvas>
        </div>

        <!-- Wireless Chart -->
        <div class="chartCard" id="wirelessCard" style="display: none;">
            <div class="chartLoading" id="wirelessLoading"><i class="fas fa-spinner fa-spin"></i><p>Loading wireless data...</p></div>
            <div class="chartTitle"><span><i class="fas fa-wifi"></i> Wireless Signal Quality</span></div>
            <div class="chartControls">
                <div class="controlGroup">
                    <label for="wirelessInterfaceSelect">Interface:</label>
                    <select id="wirelessInterfaceSelect" onchange="page.refreshMetric('wireless');"><option value="wlan0">wlan0</option></select>
                </div>
            </div>
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
