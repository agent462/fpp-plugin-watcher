<?php
require_once __DIR__ . '/../classes/autoload.php';
require_once __DIR__ . '/../classes/Watcher/UI/ViewHelpers.php';
include_once __DIR__ . '/../lib/core/config.php';
include_once __DIR__ . '/../lib/core/watcherCommon.php';

use Watcher\Http\ApiClient;

$config = readPluginConfig();
$localSystem = ApiClient::getInstance()->get('http://127.0.0.1/api/fppd/status', 5) ?: [];
$access = checkDashboardAccess($config, $localSystem, 'multiSyncMetricsEnabled');
$remoteSystems = $access['show'] ? getMultiSyncRemoteSystems() : [];

renderCSSIncludes($access['show']);
?>
<style>
    .systemCard { background: #fff; border-radius: 8px; padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    .systemHeader { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 2px solid #e9ecef; }
    .systemName { font-size: 1.25rem; font-weight: 600; color: #212529; }
    .systemInfo { font-size: 0.875rem; color: #6c757d; }
    .systemStatus { padding: 0.25rem 0.75rem; border-radius: 4px; font-size: 0.875rem; font-weight: 500; }
    .systemStatus.online { background: #d4edda; color: #155724; }
    .systemStatus.offline { background: #f8d7da; color: #721c24; }
    .metricsGrid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; }
    .metricItem { background: #f8f9fa; border-radius: 6px; padding: 1rem; text-align: center; }
    .metricLabel { font-size: 0.75rem; color: #6c757d; text-transform: uppercase; margin-bottom: 0.25rem; }
    .metricValue { font-size: 1.5rem; font-weight: 600; color: #212529; }
    .metricValue.warning { color: #ffc107; }
    .metricValue.danger { color: #dc3545; }
    .chartsContainer { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(400px, 100%), 1fr)); gap: 1rem; margin-top: 1rem; }
    .chartWrapper { background: #f8f9fa; border-radius: 6px; padding: 1rem; overflow: hidden; }
    .miniChartTitle { font-size: 0.875rem; font-weight: 600; color: #495057; margin-bottom: 0.5rem; }
    .noDataMessage { text-align: center; color: #6c757d; padding: 2rem; }
    .summaryCards { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
    .summaryCard { background: var(--watcher-gradient-purple); color: white; padding: 1.25rem; border-radius: 8px; text-align: center; }
    .summaryCard.cpu { background: linear-gradient(135deg, #f5576c 0%, #f093fb 100%); }
    .summaryCard.memory { background: var(--watcher-gradient-purple); }
    .summaryCard.disk { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
    .summaryCard.systems { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
    .summaryLabel { font-size: 0.75rem; text-transform: uppercase; opacity: 0.9; }
    .summaryValue { font-size: 2rem; font-weight: 700; margin: 0.25rem 0; }
    .summarySubtext { font-size: 0.75rem; opacity: 0.8; }
    .allSystemsContainer { padding: 1rem; }
</style>

<?php if ($access['show']): ?>
<script>window.remoteSystems = <?php echo json_encode($remoteSystems); ?>;</script>
<?php endif; ?>

<div class="allSystemsContainer" data-watcher-page="remoteMetricsUI">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <h2 style="margin: 0; color: #212529;"><i class="fas fa-server"></i> All Remote Systems Metrics Dashboard</h2>
        <span id="lastUpdate" style="font-size: 0.875rem; color: #6c757d;"></span>
    </div>

    <?php if (!renderAccessError($access)): ?>
    <div id="loadingIndicator" class="loadingSpinner">
        <i class="fas fa-spinner fa-spin fa-3x"></i>
        <p>Loading metrics from all systems...</p>
    </div>

    <div id="metricsContent" style="display: none;">
        <div class="chartControls" style="margin-bottom: 1.5rem;">
            <div class="controlGroup">
                <label for="timeRange">Time Range:</label>
                <select id="timeRange" class="form-control" style="width: auto; display: inline-block;" onchange="page.refresh()">
                    <option value="1">Last 1 Hour</option>
                    <option value="6">Last 6 Hours</option>
                    <option value="12" selected>Last 12 Hours</option>
                    <option value="24">Last 24 Hours</option>
                    <option value="48">Last 2 Days</option>
                    <option value="72">Last 3 Days</option>
                </select>
            </div>
        </div>

        <div class="summaryCards" id="summaryCards">
            <div class="summaryCard systems"><div class="summaryLabel">Remote Systems</div><div class="summaryValue" id="totalSystems">--</div><div class="summarySubtext" id="onlineSystems">-- online</div></div>
            <div class="summaryCard cpu"><div class="summaryLabel">Avg CPU Usage</div><div class="summaryValue" id="avgCpu">--%</div><div class="summarySubtext">across all systems</div></div>
            <div class="summaryCard memory"><div class="summaryLabel">Avg Free Memory</div><div class="summaryValue" id="avgMemory">-- MB</div><div class="summarySubtext">across all systems</div></div>
            <div class="summaryCard disk"><div class="summaryLabel">Avg Free Disk</div><div class="summaryValue" id="avgDisk">-- GB</div><div class="summarySubtext">across all systems</div></div>
            <div class="summaryCard efuse" id="efuseSummaryCard" style="display: none; background: linear-gradient(135deg, #f5af19 0%, #f12711 100%);"><div class="summaryLabel">Total Current</div><div class="summaryValue" id="totalEfuse">-- A</div><div class="summarySubtext" id="efuseSystems">-- systems with eFuse</div></div>
        </div>

        <div id="systemsContainer"></div>
    </div>

    <button class="refreshButton" onclick="page.refresh()" title="Refresh Data"><i class="fas fa-sync-alt"></i></button>
    <?php endif; ?>
</div>

<?php if ($access['show']) renderWatcherJS(); ?>
