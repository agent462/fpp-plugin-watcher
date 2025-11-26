<?php
// Load configuration to check if multi-sync metrics is enabled
include_once __DIR__ . '/lib/config.php';
include_once __DIR__ . '/lib/apiCall.php';
$config = readPluginConfig();

// Check conditions
$isEnabled = !empty($config['multiSyncMetricsEnabled']);
$isPlayerMode = isPlayerMode();
$showDashboard = $isEnabled && $isPlayerMode;

// Fetch remote systems list only if dashboard should be shown
$remoteSystems = [];
if ($showDashboard) {
    $multiSyncData = apiCall('GET', 'http://127.0.0.1/api/fppd/multiSyncSystems', [], true, 5);
    if ($multiSyncData && isset($multiSyncData['systems']) && is_array($multiSyncData['systems'])) {
        foreach ($multiSyncData['systems'] as $system) {
            if (empty($system['local'])) {
                $remoteSystems[] = $system;
            }
        }
    }
}
?>

<link rel="stylesheet" href="/plugin.php?plugin=fpp-plugin-watcher&file=css/commonUI.css&nopage=1">
<?php if ($showDashboard): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
<?php endif; ?>

<style>
    .systemCard {
        background: #fff;
        border-radius: 8px;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    .systemHeader {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
        padding-bottom: 0.5rem;
        border-bottom: 2px solid #e9ecef;
    }
    .systemName {
        font-size: 1.25rem;
        font-weight: 600;
        color: #212529;
    }
    .systemInfo {
        font-size: 0.875rem;
        color: #6c757d;
    }
    .systemStatus {
        padding: 0.25rem 0.75rem;
        border-radius: 4px;
        font-size: 0.875rem;
        font-weight: 500;
    }
    .systemStatus.online {
        background: #d4edda;
        color: #155724;
    }
    .systemStatus.offline {
        background: #f8d7da;
        color: #721c24;
    }
    .metricsGrid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
    }
    .metricItem {
        background: #f8f9fa;
        border-radius: 6px;
        padding: 1rem;
        text-align: center;
    }
    .metricLabel {
        font-size: 0.75rem;
        color: #6c757d;
        text-transform: uppercase;
        margin-bottom: 0.25rem;
    }
    .metricValue {
        font-size: 1.5rem;
        font-weight: 600;
        color: #212529;
    }
    .metricValue.warning { color: #ffc107; }
    .metricValue.danger { color: #dc3545; }
    .chartsContainer {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
        gap: 1rem;
        margin-top: 1rem;
    }
    .chartWrapper {
        background: #f8f9fa;
        border-radius: 6px;
        padding: 1rem;
    }
    .chartTitle {
        font-size: 0.875rem;
        font-weight: 600;
        color: #495057;
        margin-bottom: 0.5rem;
    }
    .noDataMessage {
        text-align: center;
        color: #6c757d;
        padding: 2rem;
    }
    .summaryCards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 1rem;
        margin-bottom: 2rem;
    }
    .summaryCard {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 1.25rem;
        border-radius: 8px;
        text-align: center;
    }
    .summaryCard.cpu { background: linear-gradient(135deg, #f5576c 0%, #f093fb 100%); }
    .summaryCard.memory { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
    .summaryCard.disk { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
    .summaryCard.systems { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
    .summaryLabel {
        font-size: 0.75rem;
        text-transform: uppercase;
        opacity: 0.9;
    }
    .summaryValue {
        font-size: 2rem;
        font-weight: 700;
        margin: 0.25rem 0;
    }
    .summarySubtext {
        font-size: 0.75rem;
        opacity: 0.8;
    }
    .disabledMessage {
        padding: 3rem;
        text-align: center;
        background: #f8f9fa;
        border-radius: 8px;
        margin: 2rem auto;
        max-width: 600px;
    }
    .disabledMessage h3 {
        color: #495057;
        margin-bottom: 1rem;
    }
    .disabledMessage p {
        color: #6c757d;
    }
    .allSystemsContainer {
        padding: 1rem;
    }
</style>

<?php if ($showDashboard): ?>
<script>
    window.remoteSystems = <?php echo json_encode($remoteSystems); ?>;
    window.localSystem = <?php echo json_encode($localSystem); ?>;
</script>
<?php endif; ?>

<div class="allSystemsContainer">
    <h2 style="margin-bottom: 1.5rem; color: #212529;">
        <i class="fas fa-server"></i> All Systems Metrics Dashboard
    </h2>

    <?php if (!$isEnabled): ?>
    <div class="disabledMessage">
        <h3><i class="fas fa-exclamation-circle"></i> Multi-Sync Metrics Disabled</h3>
        <p>This feature is not enabled. Go to <a href="plugin.php?plugin=fpp-plugin-watcher&page=configUI.php">Watcher Config</a> to enable it.</p>
    </div>
    <?php elseif (!$isPlayerMode): ?>
    <div class="disabledMessage">
        <h3><i class="fas fa-info-circle"></i> Player Mode Required</h3>
        <p>This feature is only available when FPP is in Player mode. Current mode: <?php echo htmlspecialchars($localSystem['fppModeString'] ?? 'unknown'); ?></p>
    </div>
    <?php else: ?>
    <div id="loadingIndicator" class="loadingSpinner">
        <i class="fas fa-spinner fa-spin fa-3x"></i>
        <p>Loading metrics from all systems...</p>
    </div>

    <div id="metricsContent" style="display: none;">
        <!-- Time Range Selector -->
        <div class="chartControls" style="margin-bottom: 1.5rem;">
            <div class="controlGroup">
                <label for="timeRange">Time Range:</label>
                <select id="timeRange" class="form-control" style="width: auto; display: inline-block;" onchange="refreshAllSystems()">
                    <option value="1">Last 1 Hour</option>
                    <option value="6">Last 6 Hours</option>
                    <option value="12" selected>Last 12 Hours</option>
                    <option value="24">Last 24 Hours</option>
                    <option value="48">Last 2 Days</option>
                    <option value="72">Last 3 Days</option>
                </select>
                <button class="btn btn-outline-secondary btn-sm" onclick="refreshAllSystems()" title="Refresh Data" style="margin-left: 10px;">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="summaryCards" id="summaryCards">
            <div class="summaryCard systems">
                <div class="summaryLabel">Remote Systems</div>
                <div class="summaryValue" id="totalSystems">--</div>
                <div class="summarySubtext" id="onlineSystems">-- online</div>
            </div>
            <div class="summaryCard cpu">
                <div class="summaryLabel">Avg CPU Usage</div>
                <div class="summaryValue" id="avgCpu">--%</div>
                <div class="summarySubtext">across all systems</div>
            </div>
            <div class="summaryCard memory">
                <div class="summaryLabel">Avg Free Memory</div>
                <div class="summaryValue" id="avgMemory">-- MB</div>
                <div class="summarySubtext">across all systems</div>
            </div>
            <div class="summaryCard disk">
                <div class="summaryLabel">Avg Free Disk</div>
                <div class="summaryValue" id="avgDisk">-- GB</div>
                <div class="summarySubtext">across all systems</div>
            </div>
        </div>

        <!-- Per-System Metrics -->
        <div id="systemsContainer"></div>
    </div>
    <?php endif; ?>
</div>

<?php if ($showDashboard): ?>
<script>
    const charts = {};
    let isRefreshing = false;
    let systemMetrics = {};

    function getSelectedHours() {
        return document.getElementById('timeRange').value;
    }

    async function fetchJson(url, timeout = 5000) {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), timeout);

        try {
            const response = await fetch(url, { signal: controller.signal });
            clearTimeout(timeoutId);
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            return await response.json();
        } catch (error) {
            clearTimeout(timeoutId);
            throw error;
        }
    }

    async function fetchSystemMetrics(system) {
        const address = system.address;
        const hours = getSelectedHours();
        const baseUrl = `http://${address}/api/plugin/fpp-plugin-watcher`;

        const result = {
            hostname: system.hostname,
            address: address,
            model: system.model || system.type || 'Unknown',
            version: system.version || '',
            online: false,
            error: null,
            cpu: null,
            memory: null,
            disk: null,
            load: null
        };

        try {
            // Try to fetch CPU metrics first to check if watcher plugin is available
            const cpuData = await fetchJson(`${baseUrl}/metrics/cpu/average?hours=${hours}`);
            result.online = true;

            if (cpuData.success && cpuData.data && cpuData.data.length > 0) {
                const validCpu = cpuData.data.filter(d => d.cpu_usage !== null);
                if (validCpu.length > 0) {
                    const latestCpu = validCpu[validCpu.length - 1].cpu_usage;
                    const avgCpu = validCpu.reduce((a, b) => a + b.cpu_usage, 0) / validCpu.length;
                    result.cpu = { current: latestCpu, average: avgCpu, data: cpuData.data };
                }
            }

            // Fetch memory metrics
            try {
                const memData = await fetchJson(`${baseUrl}/metrics/memory/free?hours=${hours}`);
                if (memData.success && memData.data && memData.data.length > 0) {
                    const validMem = memData.data.filter(d => d.free_mb !== null);
                    if (validMem.length > 0) {
                        const latestMem = validMem[validMem.length - 1].free_mb;
                        const avgMem = validMem.reduce((a, b) => a + b.free_mb, 0) / validMem.length;
                        result.memory = { current: latestMem, average: avgMem, data: memData.data };
                    }
                }
            } catch (e) { }

            // Fetch disk metrics
            try {
                const diskData = await fetchJson(`${baseUrl}/metrics/disk/free?hours=${hours}`);
                if (diskData.success && diskData.data && diskData.data.length > 0) {
                    const validDisk = diskData.data.filter(d => d.free_gb !== null);
                    if (validDisk.length > 0) {
                        const latestDisk = validDisk[validDisk.length - 1].free_gb;
                        const avgDisk = validDisk.reduce((a, b) => a + b.free_gb, 0) / validDisk.length;
                        result.disk = { current: latestDisk, average: avgDisk, data: diskData.data };
                    }
                }
            } catch (e) { }

            // Fetch load metrics
            try {
                const loadData = await fetchJson(`${baseUrl}/metrics/load/average?hours=${hours}`);
                if (loadData.success && loadData.data && loadData.data.length > 0) {
                    const validLoad = loadData.data.filter(d => d.shortterm !== null);
                    if (validLoad.length > 0) {
                        const latestLoad = validLoad[validLoad.length - 1];
                        result.load = { shortterm: latestLoad.shortterm, midterm: latestLoad.midterm, longterm: latestLoad.longterm };
                    }
                }
            } catch (e) { }

        } catch (error) {
            result.online = false;
            result.error = error.message || 'Failed to connect';
        }

        return result;
    }

    function renderSystemCard(metrics, index) {
        const statusClass = metrics.online ? 'online' : 'offline';
        const statusText = metrics.online ? 'Online' : 'Offline';

        let metricsHtml = '';
        if (metrics.online) {
            let cpuClass = '', cpuValue = '--';
            if (metrics.cpu) {
                cpuValue = metrics.cpu.current.toFixed(1) + '%';
                if (metrics.cpu.current > 80) cpuClass = 'danger';
                else if (metrics.cpu.current > 60) cpuClass = 'warning';
            }

            let memValue = '--', memClass = '';
            if (metrics.memory) {
                memValue = metrics.memory.current.toFixed(0) + ' MB';
                if (metrics.memory.current < 100) memClass = 'danger';
                else if (metrics.memory.current < 250) memClass = 'warning';
            }

            let diskValue = '--', diskClass = '';
            if (metrics.disk) {
                diskValue = metrics.disk.current.toFixed(1) + ' GB';
                if (metrics.disk.current < 1) diskClass = 'danger';
                else if (metrics.disk.current < 2) diskClass = 'warning';
            }

            let loadValue = '--';
            if (metrics.load) loadValue = metrics.load.shortterm.toFixed(2);

            metricsHtml = `
                <div class="metricsGrid">
                    <div class="metricItem">
                        <div class="metricLabel">CPU Usage</div>
                        <div class="metricValue ${cpuClass}">${cpuValue}</div>
                    </div>
                    <div class="metricItem">
                        <div class="metricLabel">Free Memory</div>
                        <div class="metricValue ${memClass}">${memValue}</div>
                    </div>
                    <div class="metricItem">
                        <div class="metricLabel">Free Disk</div>
                        <div class="metricValue ${diskClass}">${diskValue}</div>
                    </div>
                    <div class="metricItem">
                        <div class="metricLabel">Load (1min)</div>
                        <div class="metricValue">${loadValue}</div>
                    </div>
                </div>
                <div class="chartsContainer">
                    <div class="chartWrapper">
                        <div class="chartTitle"><i class="fas fa-microchip"></i> CPU Usage</div>
                        <canvas id="cpuChart-${index}" height="150"></canvas>
                    </div>
                    <div class="chartWrapper">
                        <div class="chartTitle"><i class="fas fa-memory"></i> Free Memory</div>
                        <canvas id="memoryChart-${index}" height="150"></canvas>
                    </div>
                </div>
            `;
        } else {
            metricsHtml = `
                <div class="noDataMessage">
                    <i class="fas fa-exclamation-triangle"></i>
                    <p>Unable to fetch metrics: ${metrics.error || 'Connection failed'}</p>
                    <p style="font-size: 0.875rem;">Ensure the Watcher plugin is installed and collectd is enabled on this system.</p>
                </div>
            `;
        }

        return `
            <div class="systemCard" data-system="${index}">
                <div class="systemHeader">
                    <div>
                        <div class="systemName">${escapeHtml(metrics.hostname)}</div>
                        <div class="systemInfo">${escapeHtml(metrics.address)} | ${escapeHtml(metrics.model)} | FPP ${escapeHtml(metrics.version)}</div>
                    </div>
                    <div class="systemStatus ${statusClass}">${statusText}</div>
                </div>
                ${metricsHtml}
            </div>
        `;
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function renderMiniChart(canvasId, data, label, color, valueKey) {
        const ctx = document.getElementById(canvasId);
        if (!ctx) return;

        const chartData = data.map(entry => ({
            x: entry.timestamp * 1000,
            y: entry[valueKey]
        })).filter(d => d.y !== null);

        if (charts[canvasId]) charts[canvasId].destroy();

        // Determine unit and formatting based on metric type
        const isPercent = valueKey === 'cpu_usage';
        const isMB = valueKey === 'free_mb';
        const formatValue = (val) => {
            if (isPercent) return val.toFixed(1) + '%';
            if (isMB) return val.toFixed(0) + ' MB';
            return val.toFixed(2);
        };

        charts[canvasId] = new Chart(ctx, {
            type: 'line',
            data: {
                datasets: [{
                    label: label,
                    data: chartData,
                    borderColor: color,
                    backgroundColor: color.replace('rgb', 'rgba').replace(')', ', 0.1)'),
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
                    legend: { display: false },
                    tooltip: {
                        enabled: true,
                        callbacks: {
                            title: function(context) {
                                const date = new Date(context[0].parsed.x);
                                return date.toLocaleString();
                            },
                            label: function(context) {
                                return label + ': ' + formatValue(context.parsed.y);
                            }
                        }
                    }
                },
                scales: {
                    x: { type: 'time', display: false },
                    y: { beginAtZero: isPercent, display: true, grid: { display: false } }
                }
            }
        });
    }

    function updateSummaryCards() {
        const systems = Object.values(systemMetrics);
        const onlineSystems = systems.filter(s => s.online);

        document.getElementById('totalSystems').textContent = systems.length;
        document.getElementById('onlineSystems').textContent = `${onlineSystems.length} online`;

        if (onlineSystems.length > 0) {
            const cpuValues = onlineSystems.filter(s => s.cpu).map(s => s.cpu.current);
            const memValues = onlineSystems.filter(s => s.memory).map(s => s.memory.current);
            const diskValues = onlineSystems.filter(s => s.disk).map(s => s.disk.current);

            if (cpuValues.length > 0) {
                document.getElementById('avgCpu').textContent = (cpuValues.reduce((a, b) => a + b, 0) / cpuValues.length).toFixed(1) + '%';
            }
            if (memValues.length > 0) {
                document.getElementById('avgMemory').textContent = (memValues.reduce((a, b) => a + b, 0) / memValues.length).toFixed(0) + ' MB';
            }
            if (diskValues.length > 0) {
                document.getElementById('avgDisk').textContent = (diskValues.reduce((a, b) => a + b, 0) / diskValues.length).toFixed(1) + ' GB';
            }
        }
    }

    async function refreshAllSystems() {
        if (isRefreshing) return;
        isRefreshing = true;

        const container = document.getElementById('systemsContainer');
        const systems = window.remoteSystems || [];

        if (systems.length === 0) {
            container.innerHTML = '<div class="noDataMessage"><p>No remote systems found in multi-sync configuration.</p></div>';
            document.getElementById('loadingIndicator').style.display = 'none';
            document.getElementById('metricsContent').style.display = 'block';
            isRefreshing = false;
            return;
        }

        // Fetch metrics from all systems in parallel
        const results = await Promise.all(systems.map((system, index) => fetchSystemMetrics(system)));

        // Store and render results
        systemMetrics = {};
        let html = '';
        results.forEach((metrics, index) => {
            systemMetrics[index] = metrics;
            html += renderSystemCard(metrics, index);
        });

        container.innerHTML = html;

        // Render charts for online systems
        results.forEach((metrics, index) => {
            if (metrics.online) {
                if (metrics.cpu && metrics.cpu.data) {
                    renderMiniChart(`cpuChart-${index}`, metrics.cpu.data, 'CPU %', 'rgb(245, 87, 108)', 'cpu_usage');
                }
                if (metrics.memory && metrics.memory.data) {
                    renderMiniChart(`memoryChart-${index}`, metrics.memory.data, 'Memory MB', 'rgb(102, 126, 234)', 'free_mb');
                }
            }
        });

        updateSummaryCards();

        document.getElementById('loadingIndicator').style.display = 'none';
        document.getElementById('metricsContent').style.display = 'block';
        isRefreshing = false;
    }

    // Auto-refresh every 60 seconds
    setInterval(() => { if (!isRefreshing) refreshAllSystems(); }, 60000);

    // Load data on page load
    $(document).ready(function() {
        refreshAllSystems();
    });
</script>
<?php endif; ?>
