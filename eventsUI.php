<?php
include_once __DIR__ . '/lib/config.php';
include_once __DIR__ . '/lib/watcherCommon.php';
include_once __DIR__ . '/lib/uiCommon.php';

$config = readPluginConfig();
$localSystem = apiCall('GET', 'http://127.0.0.1/api/fppd/status', [], true, 5) ?: [];
$access = checkDashboardAccess($config, $localSystem, 'mqttMonitorEnabled');

renderCSSIncludes(true);
renderCommonJS();
?>

<?php if (!renderAccessError($access)): ?>
<div class="metricsContainer">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <h2 style="margin: 0; color: #212529;">
            <i class="fas fa-rss"></i> FPP Events
        </h2>
        <span id="lastUpdate" style="font-size: 0.875rem; color: #6c757d;"></span>
    </div>

    <div id="loadingIndicator" class="loadingSpinner">
        <i class="fas fa-spinner"></i>
        <p>Loading FPP events...</p>
    </div>

    <div id="metricsContent" style="display: none;">
        <!-- Stats Bar -->
        <div class="statsBar">
            <div class="statItem">
                <div class="statLabel">Total Events</div>
                <div class="statValue" id="totalEvents">--</div>
            </div>
            <div class="statItem">
                <div class="statLabel">Sequences Played</div>
                <div class="statValue" id="sequencesPlayed">--</div>
            </div>
            <div class="statItem">
                <div class="statLabel">Playlists Started</div>
                <div class="statValue" id="playlistsStarted">--</div>
            </div>
            <div class="statItem">
                <div class="statLabel">Total Runtime</div>
                <div class="statValue" id="totalRuntime">--</div>
            </div>
        </div>

        <!-- Time Range Selector -->
        <div class="chartControls" style="margin-bottom: 1.5rem;">
            <div class="controlGroup">
                <label for="timeRange">Time Range:</label>
                <select id="timeRange" onchange="loadAllData()">
                    <option value="24" selected>Last 24 Hours</option>
                    <option value="168">Last 7 Days</option>
                    <option value="720">Last 30 Days</option>
                    <option value="1440">Last 60 Days</option>
                </select>
            </div>
        </div>

        <!-- Top Sequences -->
        <div class="chartCard">
            <div class="chartTitle">
                <span>
                    <i class="fas fa-play-circle"></i> Top Sequences
                </span>
            </div>
            <div id="topSequences" class="topItemsList">
                <p class="noData">No sequence data available</p>
            </div>
        </div>

        <!-- Event Timeline Chart -->
        <div class="chartCard">
            <div class="chartTitle">
                <span>
                    <i class="fas fa-chart-line"></i> Event Timeline
                </span>
            </div>
            <canvas id="timelineChart" style="max-height: 300px;"></canvas>
        </div>

        <!-- Recent Events Table -->
        <div class="chartCard">
            <div class="chartTitle">
                <span>
                    <i class="fas fa-list"></i> Recent Events
                    <span class="tierBadge" id="eventCount">0 events</span>
                </span>
            </div>
            <div class="tableWrapper">
                <table class="eventsTable" id="eventsTable">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Host</th>
                            <th>Event</th>
                            <th>Details</th>
                            <th>Duration</th>
                        </tr>
                    </thead>
                    <tbody id="eventsTableBody">
                        <tr><td colspan="5" class="noData">No events found</td></tr>
                    </tbody>
                </table>
            </div>
            <div id="showMoreContainer" style="text-align: center; margin-top: 1rem; display: none;">
                <button class="buttons btn-outline-secondary btn-sm" onclick="showMoreEvents()">
                    <i class="fas fa-chevron-down"></i> Show More
                </button>
            </div>
        </div>
    </div>

    <button class="refreshButton" onclick="loadAllData()" title="Refresh Data">
        <i class="fas fa-sync-alt"></i>
    </button>
</div>

<style>
.topItemsList {
    padding: 0.5rem 0;
}
.topItem {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.5rem 1rem;
    border-bottom: 1px solid #e9ecef;
}
.topItem:last-child {
    border-bottom: none;
}
.topItem .rank {
    width: 24px;
    height: 24px;
    background: #6c757d;
    color: #fff;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
    font-weight: 600;
    flex-shrink: 0;
}
.topItem .name {
    flex: 1;
    font-weight: 500;
    color: #212529;
    margin-left: 0.75rem;
}
.topItem .count {
    background: #e7f3ff;
    color: #0066cc;
    padding: 0.25rem 0.75rem;
    border-radius: 12px;
    font-size: 0.875rem;
    font-weight: 600;
}
.tableWrapper {
    overflow-x: auto;
}
.eventsTable {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}
.eventsTable th,
.eventsTable td {
    padding: 0.75rem;
    text-align: left;
    border-bottom: 1px solid #e9ecef;
}
.eventsTable th {
    background: #f8f9fa;
    font-weight: 600;
    color: #495057;
}
.eventsTable tr:hover {
    background: #f8f9fa;
}
.eventsTable .noData {
    text-align: center;
    color: #6c757d;
    padding: 2rem;
}
.eventBadge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
}
.eventBadge.seq-start { background: #d4edda; color: #155724; }
.eventBadge.seq-stop { background: #f8d7da; color: #721c24; }
.eventBadge.pl-start { background: #cce5ff; color: #004085; }
.eventBadge.pl-stop { background: #e2e3e5; color: #383d41; }
.eventBadge.status { background: #fff3cd; color: #856404; }
.eventBadge.media-start { background: #e7e3ff; color: #5a47a8; }
.eventBadge.media-stop { background: #f3e8ff; color: #7c3aed; }
.eventBadge.warning { background: #fed7aa; color: #c2410c; }
</style>

<script>
const charts = {};
let isRefreshing = false;
let allEvents = [];
let displayedEvents = 50;

async function loadAllData() {
    if (isRefreshing) return;
    isRefreshing = true;

    const refreshBtn = document.querySelector('.refreshButton i');
    if (refreshBtn) refreshBtn.style.animation = 'spin 1s linear infinite';

    try {
        await Promise.all([loadStats(), loadEvents()]);
        hideElement('loadingIndicator');
        showElement('metricsContent');
        document.getElementById('lastUpdate').textContent = 'Updated: ' + new Date().toLocaleTimeString();
    } catch (error) {
        console.error('Error loading data:', error);
    } finally {
        isRefreshing = false;
        if (refreshBtn) refreshBtn.style.animation = '';
    }
}

async function loadStats() {
    const hours = document.getElementById('timeRange').value;
    const url = `/api/plugin/fpp-plugin-watcher/mqtt/stats?hours=${hours}`;

    const response = await fetchJson(url);
    if (response && response.success && response.stats) {
        const stats = response.stats;

        document.getElementById('totalEvents').textContent = stats.totalEvents || 0;
        document.getElementById('sequencesPlayed').textContent = Object.keys(stats.sequencesPlayed || {}).length;
        document.getElementById('playlistsStarted').textContent = Object.keys(stats.playlistsStarted || {}).length;

        // Total runtime formatted
        document.getElementById('totalRuntime').textContent = formatDuration(stats.totalRuntime || 0);

        // Update timeline chart
        updateTimelineChart(stats.hourlyDistribution || []);

        // Update top sequences
        updateTopSequences(stats.sequencesPlayed || {});
    }
}

async function loadEvents() {
    const hours = document.getElementById('timeRange').value;
    const url = `/api/plugin/fpp-plugin-watcher/mqtt/events?hours=${hours}`;

    const response = await fetchJson(url);
    if (response && response.success) {
        allEvents = response.data || [];
        displayedEvents = 50;
        updateEventsTable();
        document.getElementById('eventCount').textContent = allEvents.length + ' events';
    }
}

function updateTimelineChart(data) {
    const ctx = document.getElementById('timelineChart');
    if (!ctx) return;

    const chartData = data.map(d => ({
        x: new Date(d.timestamp * 1000),
        y: d.count
    }));

    const hours = parseInt(document.getElementById('timeRange').value);
    const dataset = createDataset('Events', chartData, 'blue', { pointRadius: 2 });

    if (charts.timeline) {
        charts.timeline.data.datasets[0].data = chartData;
        charts.timeline.update('none');
    } else {
        charts.timeline = new Chart(ctx, {
            type: 'line',
            data: { datasets: [dataset] },
            options: buildChartOptions(hours, {
                yLabel: 'Events',
                beginAtZero: true,
                showLegend: false
            })
        });
    }
}

function updateTopSequences(sequences) {
    const container = document.getElementById('topSequences');
    if (!container) return;

    const entries = Object.entries(sequences).slice(0, 10);

    if (entries.length === 0) {
        container.innerHTML = '<p class="noData">No sequence data available</p>';
        return;
    }

    container.innerHTML = entries.map(([name, count], index) => `
        <div class="topItem">
            <span class="rank">${index + 1}</span>
            <span class="name">${escapeHtml(name)}</span>
            <span class="count">${count} plays</span>
        </div>
    `).join('');
}

function updateEventsTable() {
    const tbody = document.getElementById('eventsTableBody');
    if (!tbody) return;

    const eventsToShow = allEvents.slice(0, displayedEvents);

    if (eventsToShow.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="noData">No events found</td></tr>';
        hideElement('showMoreContainer');
        return;
    }

    tbody.innerHTML = eventsToShow.map(event => {
        const badgeClass = getBadgeClass(event.eventType);
        const durationStr = event.duration ? formatDuration(event.duration) : '';
        return `
            <tr>
                <td>${escapeHtml(event.datetime)}</td>
                <td>${escapeHtml(event.hostname)}</td>
                <td><span class="eventBadge ${badgeClass}">${escapeHtml(event.eventLabel)}</span></td>
                <td>${escapeHtml(event.data || '-')}</td>
                <td>${durationStr}</td>
            </tr>
        `;
    }).join('');

    // Show/hide "Show More" button
    if (allEvents.length > displayedEvents) {
        showElement('showMoreContainer');
    } else {
        hideElement('showMoreContainer');
    }
}

function showMoreEvents() {
    displayedEvents += 50;
    updateEventsTable();
}

function getBadgeClass(eventType) {
    const classes = {
        'ss': 'seq-start',
        'se': 'seq-stop',
        'ps': 'pl-start',
        'pe': 'pl-stop',
        'st': 'status',
        'ms': 'media-start',
        'me': 'media-stop',
        'wn': 'warning'
    };
    return classes[eventType] || '';
}

// Initial load
document.addEventListener('DOMContentLoaded', loadAllData);

// Auto-refresh every 30 seconds
setInterval(loadAllData, 30000);
</script>
<?php endif; ?>
