<?php
include_once __DIR__ . '/../lib/core/config.php';
include_once __DIR__ . '/../lib/ui/common.php';
$config = readPluginConfig();
$configuredAdapter = $config['networkAdapter'] ?? 'default';
$defaultAdapter = $configuredAdapter === 'default' ? detectActiveNetworkInterface() : $configuredAdapter;

renderCSSIncludes(true);
renderCommonJS();
?>
<script>window.config = { defaultAdapter: <?php echo json_encode($defaultAdapter); ?> };</script>

<div class="metricsContainer">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <h2 style="margin: 0; color: #212529;">
            <i class="fas fa-chart-area"></i> System Metrics Dashboard
        </h2>
        <span id="lastUpdate" style="font-size: 0.875rem; color: #6c757d;"></span>
    </div>

    <div id="metricsContent">
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
        <div class="chartCard" id="cpuCard">
            <div class="chartLoading" id="cpuLoading"><i class="fas fa-spinner fa-spin"></i><p>Loading CPU data...</p></div>
            <div class="chartTitle"><span><i class="fas fa-microchip"></i> CPU Usage (Averaged Across All Cores)</span></div>
            <canvas id="cpuChart" style="max-height: 400px;"></canvas>
        </div>

        <!-- Load Average Chart -->
        <div class="chartCard" id="loadCard">
            <div class="chartLoading" id="loadLoading"><i class="fas fa-spinner fa-spin"></i><p>Loading load average data...</p></div>
            <div class="chartTitle"><span><i class="fas fa-tachometer-alt"></i> Load Average</span></div>
            <canvas id="loadChart" style="max-height: 400px;"></canvas>
        </div>

        <!-- Memory Stats Bar -->
        <div class="statsBar">
            <div class="statItem"><div class="statLabel">Current Free Memory</div><div class="statValue" id="currentMemory">-- MB</div></div>
            <div class="statItem"><div class="statLabel">Average (24h)</div><div class="statValue" id="avgMemory">-- MB</div></div>
            <div class="statItem"><div class="statLabel">Minimum (24h)</div><div class="statValue" id="minMemory">-- MB</div></div>
            <div class="statItem"><div class="statLabel">Maximum (24h)</div><div class="statValue" id="maxMemory">-- MB</div></div>
        </div>

        <!-- Memory Chart -->
        <div class="chartCard" id="memoryCard">
            <div class="chartLoading" id="memoryLoading"><i class="fas fa-spinner fa-spin"></i><p>Loading memory data...</p></div>
            <div class="chartTitle"><span><i class="fas fa-memory"></i> Free Memory</span></div>
            <canvas id="memoryChart" style="max-height: 400px;"></canvas>
        </div>

        <!-- Disk Chart -->
        <div class="chartCard" id="diskCard">
            <div class="chartLoading" id="diskLoading"><i class="fas fa-spinner fa-spin"></i><p>Loading disk data...</p></div>
            <div class="chartTitle"><span><i class="fas fa-hdd"></i> Disk Free Space (Root)</span></div>
            <div id="diskStatusBar" class="systemStatusBar" style="display: none; background: #f8f9fa; padding: 1rem; border-radius: 6px; margin-bottom: 1.5rem; border: 1px solid #e9ecef;"></div>
            <canvas id="diskChart" style="max-height: 400px;"></canvas>
        </div>

        <!-- Network Chart -->
        <div class="chartCard" id="networkCard">
            <div class="chartLoading" id="networkLoading"><i class="fas fa-spinner fa-spin"></i><p>Loading network data...</p></div>
            <div class="chartTitle"><span><i class="fas fa-network-wired"></i> Network Bandwidth</span></div>
            <div class="chartControls">
                <div class="controlGroup">
                    <label for="interfaceSelect">Interface:</label>
                    <select id="interfaceSelect" onchange="refreshMetric('network');"><option value="eth0">eth0</option></select>
                </div>
            </div>
            <canvas id="networkChart" style="max-height: 400px;"></canvas>
        </div>

        <!-- Temperature Chart -->
        <div class="chartCard" id="thermalCard" style="display: none;">
            <div class="chartLoading" id="thermalLoading"><i class="fas fa-spinner fa-spin"></i><p>Loading temperature data...</p></div>
            <div class="chartTitle"><span><i class="fas fa-thermometer-half"></i> Temperature (Thermal Zones)</span></div>
            <div id="temperatureStatusBar" class="systemStatusBar" style="display: none; background: #f8f9fa; padding: 1rem; border-radius: 6px; margin-bottom: 1.5rem; border: 1px solid #e9ecef;"></div>
            <canvas id="thermalChart" style="max-height: 400px;"></canvas>
        </div>

        <!-- Wireless Chart -->
        <div class="chartCard" id="wirelessCard" style="display: none;">
            <div class="chartLoading" id="wirelessLoading"><i class="fas fa-spinner fa-spin"></i><p>Loading wireless data...</p></div>
            <div class="chartTitle"><span><i class="fas fa-wifi"></i> Wireless Signal Quality</span></div>
            <canvas id="wirelessChart" style="max-height: 400px;"></canvas>
        </div>
    </div>

    <button class="refreshButton" onclick="loadAllMetrics()" title="Refresh Data"><i class="fas fa-sync-alt"></i></button>
</div>

<script>
const charts = {};
let isRefreshing = false;
let useFahrenheit = false;

const getSelectedHours = () => document.getElementById('timeRange').value;
const getDefaultAdapter = () => window.config?.defaultAdapter || 'default';
const getSelectedInterface = () => document.getElementById('interfaceSelect')?.value || getDefaultAdapter();

// Temperature helpers
const formatTemp = c => useFahrenheit ? `${(c * 9/5 + 32).toFixed(1)}°F` : `${c.toFixed(1)}°C`;
const getTempStatus = c => c < 40 ? { text: 'Cool', color: '#38ef7d', icon: '❄️' }
    : c < 60 ? { text: 'Normal', color: '#28a745', icon: '✅' }
    : c < 80 ? { text: 'Warm', color: '#ffc107', icon: '⚠️' }
    : { text: 'Hot', color: '#f5576c', icon: '🔥' };

// System status display
async function loadSystemStatus() {
    try {
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
        const status = await fetchJson('/api/system/status');
        updateTemperatureStatus(status);
        updateDiskStatus(status);
    } catch (e) { console.error('Error loading system status:', e); }
}

function updateTemperatureStatus(status) {
    const container = document.getElementById('temperatureStatusBar');
    const sensors = status.sensors?.filter(s => s.valueType === 'Temperature') || [];
    if (!sensors.length) { container.style.display = 'none'; return; }

    container.innerHTML = sensors.map((sensor, i) => {
        const temp = parseFloat(sensor.value) || 0;
        const st = getTempStatus(temp);
        const pct = Math.min(temp, 100);
        const label = sensor.label.replace(':', '').trim();
        const display = formatTemp(temp);
        return `<div${i < sensors.length - 1 ? ' style="margin-bottom: 1.5rem;"' : ''}>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fas fa-thermometer-half" style="color: ${st.color};"></i><strong>${escapeHtml(label)}</strong>
                </div>
                <span style="font-size: 1.5rem; font-weight: bold; color: ${st.color};">${display}</span>
            </div>
            <div class="progressBar" style="background-color: #e9ecef; height: 24px; border-radius: 4px; overflow: hidden;">
                <div style="width: ${pct}%; height: 100%; background: ${st.color}; display: flex; align-items: center; justify-content: center; color: white; font-size: 0.875rem; font-weight: 500;">${display}</div>
            </div>
            <div style="display: flex; justify-content: space-between; margin-top: 0.5rem; font-size: 0.75rem; color: #6c757d;">
                <span>${formatTemp(0)}</span><span>Status: ${st.icon} ${st.text}</span><span>${formatTemp(100)}</span>
            </div>
        </div>`;
    }).join('');
    container.style.display = 'block';
}

function updateDiskStatus(status) {
    const container = document.getElementById('diskStatusBar');
    const disk = status.advancedView?.Utilization?.Disk?.Root;
    if (!disk) { container.style.display = 'none'; return; }

    const { Free: free = 0, Total: total = 1 } = disk;
    const used = total - free, pct = (used / total) * 100;
    const color = pct > 90 ? '#f5576c' : pct > 75 ? '#ffc107' : '#38ef7d';

    container.innerHTML = `<div>
        <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
            <strong>Root Filesystem</strong><span style="font-weight: 500;">${formatBytes(used)} / ${formatBytes(total)}</span>
        </div>
        <div class="progressBar" style="background-color: #e9ecef; height: 24px; border-radius: 4px; overflow: hidden;">
            <div style="width: ${pct}%; height: 100%; background: ${color}; display: flex; align-items: center; justify-content: center; color: white; font-size: 0.875rem; font-weight: 500;">${pct.toFixed(1)}% Used</div>
        </div>
        <div style="margin-top: 0.5rem; font-size: 0.875rem; color: #6c757d;">
            <i class="fas fa-check-circle" style="color: #28a745;"></i> Available: ${formatBytes(free)}
        </div>
    </div>`;
    container.style.display = 'block';
}

// Metric definitions
const METRIC_DEFS = [
    { key: 'memory', canvasId: 'memoryChart', loadingId: 'memoryLoading',
        url: h => `/api/plugin/fpp-plugin-watcher/metrics/memory/free?hours=${h}`,
        prepare: p => {
            if (!p?.success || !p.data?.length) return null;
            const vals = p.data.filter(d => d.free_mb !== null).map(d => d.free_mb);
            if (!vals.length) return null;
            document.getElementById('currentMemory').textContent = vals.at(-1).toFixed(1) + ' MB';
            document.getElementById('avgMemory').textContent = (vals.reduce((a, b) => a + b) / vals.length).toFixed(1) + ' MB';
            document.getElementById('minMemory').textContent = Math.min(...vals).toFixed(1) + ' MB';
            document.getElementById('maxMemory').textContent = Math.max(...vals).toFixed(1) + ' MB';
            return { datasets: [createDataset('Free Memory (MB)', mapChartData(p, 'free_mb'), 'purple')],
                opts: { yLabel: 'Free Memory (MB)', yTickFormatter: v => v.toFixed(0) + ' MB', tooltipLabel: c => 'Free Memory: ' + c.parsed.y.toFixed(2) + ' MB' } };
        } },
    { key: 'cpu', canvasId: 'cpuChart', loadingId: 'cpuLoading',
        url: h => `/api/plugin/fpp-plugin-watcher/metrics/cpu/average?hours=${h}`,
        prepare: p => !p?.success || !p.data?.length ? null : {
            datasets: [createDataset('CPU Usage (%)', mapChartData(p, 'cpu_usage'), 'red')],
            opts: { yLabel: 'CPU Usage (%)', beginAtZero: true, yMax: 100, yTickFormatter: v => v.toFixed(0) + '%', tooltipLabel: c => 'CPU Usage: ' + c.parsed.y.toFixed(2) + '%' } } },
    { key: 'load', canvasId: 'loadChart', loadingId: 'loadLoading',
        url: h => `/api/plugin/fpp-plugin-watcher/metrics/load/average?hours=${h}`,
        prepare: p => !p?.success || !p.data?.length ? null : {
            datasets: [
                createDataset('1 min', mapChartData(p, 'shortterm'), 'coral', { fill: false }),
                createDataset('5 min', mapChartData(p, 'midterm'), 'orange', { fill: false }),
                createDataset('15 min', mapChartData(p, 'longterm'), 'teal', { fill: false })
            ],
            opts: { yLabel: 'Load Average', beginAtZero: true, yTickFormatter: v => v.toFixed(2), tooltipLabel: c => c.dataset.label + ' Load: ' + c.parsed.y.toFixed(2) } } },
    { key: 'disk', canvasId: 'diskChart', loadingId: 'diskLoading',
        url: h => `/api/plugin/fpp-plugin-watcher/metrics/disk/free?hours=${h}`,
        prepare: p => !p?.success || !p.data?.length ? null : {
            datasets: [createDataset('Free Space (GB)', mapChartData(p, 'free_gb'), 'green')],
            opts: { yLabel: 'Free Space (GB)', yTickFormatter: v => v.toFixed(1) + ' GB', tooltipLabel: c => 'Free Space: ' + c.parsed.y.toFixed(2) + ' GB' } } },
    { key: 'network', canvasId: 'networkChart', loadingId: 'networkLoading',
        url: h => `/api/plugin/fpp-plugin-watcher/metrics/interface/bandwidth?interface=${getSelectedInterface()}&hours=${h}`,
        prepare: p => !p?.success || !p.data?.length ? null : {
            datasets: [createDataset('Download (RX)', mapChartData(p, 'rx_kbps'), 'blue'), createDataset('Upload (TX)', mapChartData(p, 'tx_kbps'), 'pink')],
            opts: { yLabel: 'Bandwidth (Kbps)', beginAtZero: true, yTickFormatter: v => v.toFixed(0) + ' Kbps', tooltipLabel: c => c.dataset.label + ': ' + c.parsed.y.toFixed(2) + ' Kbps' } } },
    { key: 'thermal', canvasId: 'thermalChart', cardId: 'thermalCard', loadingId: 'thermalLoading',
        url: h => `/api/plugin/fpp-plugin-watcher/metrics/thermal?hours=${h}`,
        prepare: p => {
            if (!p?.success || !p.data?.length || !p.zones?.length) return { hidden: true };
            const colorKeys = ['coral', 'blue', 'yellow', 'teal', 'indigo', 'orange', 'green', 'pink'];
            const toF = c => c * 9/5 + 32;
            const unit = useFahrenheit ? '°F' : '°C';
            const convertData = (response, key) => mapChartData(response, key).map(d => ({ x: d.x, y: useFahrenheit ? toF(d.y) : d.y }));
            return { datasets: p.zones.map((z, i) => createDataset(z, convertData(p, z), colorKeys[i % colorKeys.length], { fill: false })),
                opts: { yLabel: `Temperature (${unit})`, yTickFormatter: v => v.toFixed(0) + unit, tooltipLabel: c => c.dataset.label + ': ' + c.parsed.y.toFixed(1) + unit } }; } },
    { key: 'wireless', canvasId: 'wirelessChart', cardId: 'wirelessCard', loadingId: 'wirelessLoading',
        url: h => `/api/plugin/fpp-plugin-watcher/metrics/wireless?hours=${h}`,
        prepare: p => {
            if (!p?.success || !p.data?.length || !p.interfaces?.length) return { hidden: true };
            const colorMap = { signal_quality: 'teal', signal_power: 'coral', signal_noise: 'orange' };
            const datasets = [];
            (p.available_metrics || {})[p.interfaces[0]]?.forEach(metric => {
                const key = `${p.interfaces[0]}_${metric}`;
                const label = metric.replace('signal_', '').replace('_', ' ').replace(/^./, c => c.toUpperCase());
                datasets.push(createDataset(`${p.interfaces[0]} - ${label}`, mapChartData(p, key), colorMap[metric] || 'teal', { fill: false }));
            });
            return datasets.length ? { datasets, opts: { yLabel: 'Signal Metrics', yTickFormatter: v => v.toFixed(0), tooltipLabel: c => c.dataset.label + ': ' + c.parsed.y.toFixed(1) } } : { hidden: true }; } }
];

async function updateMetric(def, hours) {
    const isInitialLoad = !charts[def.key];
    if (isInitialLoad) setLoading(def.loadingId, true);
    try {
        const prepared = def.prepare(await fetchJson(def.url(hours)));
        if (!prepared || prepared.hidden) {
            if (def.cardId) document.getElementById(def.cardId).style.display = 'none';
        } else {
            if (def.cardId) document.getElementById(def.cardId).style.display = 'block';
            updateOrCreateChart(charts, def.key, def.canvasId, 'line', prepared.datasets, buildChartOptions(hours, prepared.opts));
        }
    } catch (e) {
        console.error(`Error updating ${def.key}:`, e);
        if (def.cardId) document.getElementById(def.cardId).style.display = 'none';
    }
    if (isInitialLoad) setLoading(def.loadingId, false);
}

const refreshMetric = key => { const def = METRIC_DEFS.find(d => d.key === key); if (def) updateMetric(def, getSelectedHours()); };
const updateAllCharts = () => Promise.all(METRIC_DEFS.map(d => updateMetric(d, getSelectedHours())));

async function loadInterfaces() {
    try {
        const { success, interfaces = [] } = await fetchJson('/api/plugin/fpp-plugin-watcher/metrics/interface/list');
        if (!success || !interfaces.length) return;
        const select = document.getElementById('interfaceSelect');
        const current = select.options.length === 1 ? getDefaultAdapter() : select.value;
        select.innerHTML = interfaces.map(i => `<option value="${escapeHtml(i)}">${escapeHtml(i)}</option>`).join('');
        select.value = interfaces.includes(current) ? current : interfaces.includes(getDefaultAdapter()) ? getDefaultAdapter() : interfaces[0];
    } catch (e) { console.error('Error loading interfaces:', e); }
}

async function loadAllMetrics() {
    if (isRefreshing) return;
    isRefreshing = true;
    const btn = document.querySelector('.refreshButton i');
    if (btn) btn.style.animation = 'spin 1s linear infinite';
    try {
        loadInterfaces();
        await loadSystemStatus();
        await updateAllCharts();
        updateLastUpdateTime();
    } catch (e) { console.error('Error loading metrics:', e); }
    if (btn) btn.style.animation = '';
    isRefreshing = false;
}

setInterval(() => { if (!isRefreshing) loadAllMetrics(); }, 30000);
document.addEventListener('DOMContentLoaded', loadAllMetrics);
</script>
