<?php
include_once __DIR__ . '/../lib/core/config.php';
include_once __DIR__ . '/../lib/core/watcherCommon.php';
include_once __DIR__ . '/../lib/ui/common.php';

$config = readPluginConfig();
$localSystem = apiCall('GET', 'http://127.0.0.1/api/fppd/status', [], true, 5) ?: [];
$access = checkDashboardAccess($config, $localSystem, 'multiSyncMetricsEnabled');
$remoteSystems = $access['show'] ? getMultiSyncRemoteSystems() : [];

renderCSSIncludes($access['show']);
if ($access['show']) renderCommonJS();
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

<div class="allSystemsContainer">
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
                <select id="timeRange" class="form-control" style="width: auto; display: inline-block;" onchange="refreshAllSystems()">
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
        </div>

        <div id="systemsContainer"></div>
    </div>

    <button class="refreshButton" onclick="refreshAllSystems()" title="Refresh Data"><i class="fas fa-sync-alt"></i></button>
    <?php endif; ?>
</div>

<?php if ($access['show']): ?>
<script>
const charts = {};
let isRefreshing = false;
let systemMetrics = {};
let useFahrenheit = false;

const getSelectedHours = () => document.getElementById('timeRange').value;
const toFahrenheit = c => c * 9/5 + 32;
const getTempUnit = () => useFahrenheit ? '°F' : '°C';
const convertTemp = c => useFahrenheit ? toFahrenheit(c) : c;

async function loadTemperaturePreference() {
    const cached = localStorage.getItem('temperatureInF');
    if (cached !== null) {
        useFahrenheit = cached === 'true';
    } else {
        try {
            const { value } = await fetchJson('/api/settings/temperatureInF');
            useFahrenheit = value === '1' || value === 1;
            localStorage.setItem('temperatureInF', useFahrenheit);
        } catch { useFahrenheit = false; }
    }
}

const metricConfig = {
    cpu: { getValue: m => m.cpu?.current, getClass: v => v > 80 ? 'danger' : v > 60 ? 'warning' : '', format: v => v.toFixed(1) + '%', chartKey: 'cpu_usage' },
    memory: { getValue: m => m.memory?.current, getClass: v => v < 100 ? 'danger' : v < 250 ? 'warning' : '', format: v => v.toFixed(0) + ' MB', chartKey: 'free_mb' },
    disk: { getValue: m => m.disk?.current, getClass: v => v < 1 ? 'danger' : v < 2 ? 'warning' : '', format: v => v.toFixed(1) + ' GB', chartKey: 'free_gb' },
    load: { getValue: m => m.load?.shortterm, getClass: () => '', format: v => v.toFixed(2), chartKey: null },
    temp: { getValue: m => m.temperature?.current, getClass: v => v > 80 ? 'danger' : v > 70 ? 'warning' : '', format: v => convertTemp(v).toFixed(1) + getTempUnit(), chartKey: 'temperature' },
    wireless: { getValue: m => m.wireless?.signal, getClass: v => v < -80 ? 'danger' : v < -70 ? 'warning' : '', format: v => v.toFixed(0) + ' dBm', chartKey: 'signal_dbm' },
    ping: { getValue: m => m.ping?.current, getClass: v => v > 100 ? 'danger' : v > 50 ? 'warning' : '', format: v => v.toFixed(1) + ' ms', chartKey: 'avg_latency' }
};

function getMetricDisplay(metrics, key) {
    const cfg = metricConfig[key];
    const val = cfg.getValue(metrics);
    return val == null ? { value: '--', class: '' } : { value: cfg.format(val), class: cfg.getClass(val) };
}

function processMetricData(data, valueKey) {
    if (!data?.success || !data.data?.length) return null;
    const valid = data.data.filter(d => d[valueKey] !== null);
    if (!valid.length) return null;
    return { current: valid.at(-1)[valueKey], average: valid.reduce((a, b) => a + b[valueKey], 0) / valid.length, data: data.data };
}

async function fetchSystemMetrics(system) {
    const { address, hostname, model, type, version } = system;
    const hours = getSelectedHours();
    const baseUrl = `http://${address}/api/plugin/fpp-plugin-watcher`;
    const result = { hostname, address, model: model || type || 'Unknown', version: version || '', watcherVersion: null, online: false, noWatcher: false, error: null, cpu: null, memory: null, disk: null, load: null, temperature: null, wireless: null, ping: null };

    try {
        const [allData, versionData] = await Promise.all([
            fetchJson(`${baseUrl}/metrics/all?hours=${hours}`, 8000),
            fetchJson(`${baseUrl}/version`, 3000).catch(() => null)
        ]);

        if (!allData.success) { result.error = 'API returned unsuccessful response'; return result; }
        result.online = true;
        if (versionData?.version) result.watcherVersion = versionData.version;

        result.cpu = processMetricData(allData.cpu, 'cpu_usage');
        result.memory = processMetricData(allData.memory, 'free_mb');
        result.disk = processMetricData(allData.disk, 'free_gb');

        if (allData.load?.success && allData.load.data?.length) {
            const valid = allData.load.data.filter(d => d.shortterm !== null);
            if (valid.length) {
                const latest = valid.at(-1);
                result.load = { shortterm: latest.shortterm, midterm: latest.midterm, longterm: latest.longterm };
            }
        }

        // Temperature
        const tempData = allData.thermal;
        if (tempData?.success && tempData.data?.length && tempData.zones?.length) {
            const zones = tempData.zones.filter(z => z.startsWith('thermal_zone'));
            for (const zone of (zones.length ? zones : tempData.zones)) {
                const valid = tempData.data.filter(d => d[zone] !== null);
                if (valid.length) {
                    result.temperature = { current: valid.at(-1)[zone], average: valid.reduce((a, b) => a + b[zone], 0) / valid.length, data: tempData.data.map(d => ({ timestamp: d.timestamp, temperature: d[zone] })), zone };
                    break;
                }
            }
        }

        // Wireless
        const wData = allData.wireless;
        if (wData?.success && wData.data?.length && wData.interfaces?.length) {
            const iface = wData.interfaces[0];
            const powerKey = `${iface}_signal_power`;
            const valid = wData.data.filter(d => d[powerKey] !== null);
            if (valid.length) {
                const latest = valid.at(-1);
                result.wireless = { signal: latest[powerKey], noise: latest[`${iface}_signal_noise`], quality: latest[`${iface}_signal_quality`], data: wData.data.map(d => ({ timestamp: d.timestamp, signal_dbm: d[powerKey] })), interface: iface };
            }
        }

        // Ping
        const pingData = allData.ping;
        if (pingData?.success && pingData.data?.length) {
            const valid = pingData.data.filter(d => d.avg_latency !== null);
            if (valid.length) {
                result.ping = { current: valid.at(-1).avg_latency, average: valid.reduce((a, b) => a + b.avg_latency, 0) / valid.length, min: Math.min(...valid.map(d => d.min_latency)), max: Math.max(...valid.map(d => d.max_latency)), data: pingData.data, tier: pingData.tier_info?.label || '5-min averages' };
            }
        }
    } catch (e) {
        // Watcher API failed - check if host is still online via basic FPP API
        try {
            const fppStatus = await fetchJson(`http://${address}/api/fppd/status`, 5000);
            if (fppStatus && typeof fppStatus === 'object') {
                result.online = true;
                result.noWatcher = true;
            }
        } catch {
            result.error = e.message || 'Failed to connect';
        }
    }
    return result;
}

function renderLoadingCard(system, index) {
    return `<div class="systemCard" data-system="${index}">
        <div class="systemHeader">
            <div><div class="systemName">${escapeHtml(system.hostname)}</div>
            <div class="systemInfo"><a href="http://${escapeHtml(system.address)}" target="_blank" style="color:#007bff">${escapeHtml(system.address)}</a> | ${escapeHtml(system.model || system.type || 'Unknown')} | FPP ${escapeHtml(system.version || '')}</div></div>
            <div class="systemStatus" style="background:#e9ecef;color:#6c757d">Loading...</div>
        </div>
        <div class="noDataMessage"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Fetching metrics...</p></div>
    </div>`;
}

function renderSystemCard(m, index) {
    if (!m.online) {
        return `<div class="systemCard" data-system="${index}">
            <div class="systemHeader"><div><div class="systemName">${escapeHtml(m.hostname)}</div>
            <div class="systemInfo"><a href="http://${escapeHtml(m.address)}" target="_blank" style="color:#007bff">${escapeHtml(m.address)}</a> | ${escapeHtml(m.model)} | FPP ${escapeHtml(m.version)}</div></div>
            <div class="systemStatus offline">Offline</div></div>
            <div class="noDataMessage"><i class="fas fa-exclamation-triangle"></i><p>Unable to fetch metrics: ${escapeHtml(m.error || 'Connection failed')}</p><p style="font-size:0.875rem">Ensure the Watcher plugin is installed and collectd is enabled.</p></div>
        </div>`;
    }

    if (m.noWatcher) {
        return `<div class="systemCard" data-system="${index}">
            <div class="systemHeader"><div><div class="systemName">${escapeHtml(m.hostname)}</div>
            <div class="systemInfo"><a href="http://${escapeHtml(m.address)}" target="_blank" style="color:#007bff">${escapeHtml(m.address)}</a> | ${escapeHtml(m.model)} | FPP ${escapeHtml(m.version)}</div></div>
            <div class="systemStatus online">Online</div></div>
            <div class="noDataMessage"><i class="fas fa-info-circle"></i><p>Watcher plugin not installed or metrics not available</p><p style="font-size:0.875rem">Install the Watcher plugin on this system to view metrics.</p></div>
        </div>`;
    }

    const watcherInfo = m.watcherVersion ? ` | Watcher ${escapeHtml(m.watcherVersion)}` : '';
    const metrics = ['cpu', 'memory', 'disk', 'load'].map(k => { const d = getMetricDisplay(m, k); return `<div class="metricItem" data-metric="${k}"><div class="metricLabel">${k === 'cpu' ? 'CPU Usage' : k === 'memory' ? 'Free Memory' : k === 'disk' ? 'Free Disk' : 'Load (1min)'}</div><div class="metricValue ${d.class}">${d.value}</div></div>`; }).join('');
    const optMetrics = [['temp', 'Temperature', m.temperature], ['wireless', 'WiFi Signal', m.wireless], ['ping', 'Ping Latency', m.ping]].filter(([,,v]) => v).map(([k, l]) => { const d = getMetricDisplay(m, k); return `<div class="metricItem" data-metric="${k}"><div class="metricLabel">${l}</div><div class="metricValue ${d.class}">${d.value}</div></div>`; }).join('');

    const chartWrappers = [
        `<div class="chartWrapper"><div class="miniChartTitle"><i class="fas fa-microchip"></i> CPU Usage</div><canvas id="cpuChart-${index}" height="150"></canvas></div>`,
        `<div class="chartWrapper"><div class="miniChartTitle"><i class="fas fa-memory"></i> Free Memory</div><canvas id="memoryChart-${index}" height="150"></canvas></div>`,
        m.temperature ? `<div class="chartWrapper"><div class="miniChartTitle"><i class="fas fa-thermometer-half"></i> Temperature</div><canvas id="tempChart-${index}" height="150"></canvas></div>` : '',
        m.wireless ? `<div class="chartWrapper"><div class="miniChartTitle"><i class="fas fa-wifi"></i> WiFi Signal</div><canvas id="wirelessChart-${index}" height="150"></canvas></div>` : '',
        m.ping ? `<div class="chartWrapper"><div class="miniChartTitle"><i class="fas fa-network-wired"></i> Ping Latency</div><canvas id="pingChart-${index}" height="150"></canvas></div>` : ''
    ].join('');

    return `<div class="systemCard" data-system="${index}">
        <div class="systemHeader"><div><div class="systemName">${escapeHtml(m.hostname)}</div>
        <div class="systemInfo"><a href="http://${escapeHtml(m.address)}" target="_blank" style="color:#007bff">${escapeHtml(m.address)}</a> | ${escapeHtml(m.model)} | FPP ${escapeHtml(m.version)}${watcherInfo}</div></div>
        <div class="systemStatus online">Online</div></div>
        <div class="metricsGrid">${metrics}${optMetrics}</div>
        <div class="chartsContainer">${chartWrappers}</div>
    </div>`;
}

function renderMiniChart(canvasId, data, label, colorKey, valueKey) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return;

    const isTemp = valueKey === 'temperature';
    const chartData = data.map(e => ({ x: e.timestamp * 1000, y: isTemp ? convertTemp(e[valueKey]) : e[valueKey] })).filter(d => d.y !== null);
    const color = CHART_COLORS[colorKey] || CHART_COLORS.purple;
    const formatters = { cpu_usage: v => v.toFixed(1)+'%', free_mb: v => v.toFixed(0)+' MB', temperature: v => v.toFixed(1)+getTempUnit(), signal_dbm: v => v.toFixed(0)+' dBm', avg_latency: v => v.toFixed(1)+' ms' };
    const formatValue = formatters[valueKey] || (v => v.toFixed(2));

    if (charts[canvasId]) {
        charts[canvasId].data.datasets[0].data = chartData;
        charts[canvasId].update('none');
        return;
    }

    charts[canvasId] = new Chart(ctx, {
        type: 'line',
        data: { datasets: [createDataset(label, chartData, color)] },
        options: {
            responsive: true, maintainAspectRatio: true, animation: false,
            interaction: { mode: 'nearest', axis: 'x', intersect: false },
            plugins: { legend: { display: false }, tooltip: { callbacks: { title: ctx => new Date(ctx[0].parsed.x).toLocaleString(), label: ctx => label + ': ' + formatValue(ctx.parsed.y) } } },
            scales: {
                x: { type: 'time', display: true, grid: { display: false }, ticks: { maxTicksLimit: 6, font: { size: 10 }, color: '#6c757d' }, time: { displayFormats: { hour: 'h:mm a', day: 'MMM d' } } },
                y: { beginAtZero: valueKey === 'cpu_usage', display: true, grid: { display: false } }
            }
        }
    });
}

function updateSummaryCards() {
    const systems = Object.values(systemMetrics);
    const online = systems.filter(s => s.online);
    document.getElementById('totalSystems').textContent = systems.length;
    document.getElementById('onlineSystems').textContent = `${online.length} online`;

    if (online.length) {
        const avg = arr => arr.length ? arr.reduce((a, b) => a + b) / arr.length : null;
        const cpuAvg = avg(online.filter(s => s.cpu).map(s => s.cpu.current));
        const memAvg = avg(online.filter(s => s.memory).map(s => s.memory.current));
        const diskAvg = avg(online.filter(s => s.disk).map(s => s.disk.current));
        if (cpuAvg !== null) document.getElementById('avgCpu').textContent = cpuAvg.toFixed(1) + '%';
        if (memAvg !== null) document.getElementById('avgMemory').textContent = memAvg.toFixed(0) + ' MB';
        if (diskAvg !== null) document.getElementById('avgDisk').textContent = diskAvg.toFixed(1) + ' GB';
    }
}

function destroyAllCharts() { Object.keys(charts).forEach(k => { if (charts[k]) { charts[k].destroy(); delete charts[k]; } }); }

async function asyncPool(concurrency, items, fn) {
    const results = [], executing = new Set();
    for (const [i, item] of items.entries()) {
        const p = Promise.resolve().then(() => fn(item, i));
        results.push(p); executing.add(p);
        p.finally(() => executing.delete(p));
        if (executing.size >= concurrency) await Promise.race(executing);
    }
    return Promise.all(results);
}

async function refreshAllSystems() {
    if (isRefreshing) return;
    isRefreshing = true;

    const btn = document.querySelector('.refreshButton i');
    if (btn) btn.style.animation = 'spin 1s linear infinite';

    await loadTemperaturePreference();

    const container = document.getElementById('systemsContainer');
    const systems = window.remoteSystems || [];

    if (!systems.length) {
        destroyAllCharts();
        container.innerHTML = '<div class="noDataMessage"><p>No remote systems found in multi-sync configuration.</p></div>';
        hideElement('loadingIndicator');
        showElement('metricsContent');
        isRefreshing = false;
        return;
    }

    const isInitialLoad = !Object.keys(systemMetrics).length;
    if (isInitialLoad) { destroyAllCharts(); systemMetrics = {}; container.innerHTML = systems.map((s, i) => renderLoadingCard(s, i)).join(''); }

    hideElement('loadingIndicator');
    showElement('metricsContent');

    await asyncPool(6, systems, async (system, index) => {
        try {
            const metrics = await fetchSystemMetrics(system);
            systemMetrics[index] = metrics;
            const card = container.querySelector(`[data-system="${index}"]`);
            if (card) {
                // Destroy existing charts before replacing HTML (canvas elements will be new)
                ['cpu', 'memory', 'temp', 'wireless', 'ping'].forEach(key => {
                    const chartKey = `${key}Chart-${index}`;
                    if (charts[chartKey]) { charts[chartKey].destroy(); delete charts[chartKey]; }
                });
                card.outerHTML = renderSystemCard(metrics, index);
                if (metrics.online) {
                    [['cpu', 'red', 'cpu_usage'], ['memory', 'purple', 'free_mb'], ['temp', 'orange', 'temperature'], ['wireless', 'teal', 'signal_dbm'], ['ping', 'indigo', 'avg_latency']]
                        .forEach(([key, color, field]) => {
                            const data = key === 'cpu' ? metrics.cpu?.data : key === 'memory' ? metrics.memory?.data : key === 'temp' ? metrics.temperature?.data : key === 'wireless' ? metrics.wireless?.data : metrics.ping?.data;
                            const label = key === 'cpu' ? 'CPU %' : key === 'memory' ? 'Memory MB' : key === 'temp' ? 'Temp ' + getTempUnit() : key === 'wireless' ? 'Signal dBm' : 'Latency ms';
                            if (data) renderMiniChart(`${key === 'temp' ? 'temp' : key}Chart-${index}`, data, label, color, field);
                        });
                }
            }
            updateSummaryCards();
        } catch (e) { console.error(`Failed to fetch metrics for ${system.hostname}:`, e); }
    });

    updateLastUpdateTime();
    if (btn) btn.style.animation = '';
    isRefreshing = false;
}

setInterval(() => { if (!isRefreshing) refreshAllSystems(); }, 60000);
$(document).ready(refreshAllSystems);
window.addEventListener('beforeunload', destroyAllCharts);
</script>
<?php endif; ?>
