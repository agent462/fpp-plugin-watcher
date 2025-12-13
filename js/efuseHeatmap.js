/**
 * eFuse Heatmap Visualization
 *
 * Provides interactive heatmap visualization for eFuse current monitoring.
 */

// Color thresholds in mA (0-6A gradient)
const EFUSE_COLORS = {
    0:    '#1a1a2e',  // Dark (off/zero)
    500:  '#16213e',  // Cool blue
    1000: '#1e5128',  // Green
    2000: '#4e9f3d',  // Light green
    3000: '#ffc107',  // Yellow (warning)
    4000: '#fd7e14',  // Orange
    5000: '#dc3545',  // Red (high)
    6000: '#c82333'   // Dark red (max)
};

const MAX_CURRENT_MA = 6000;

let efuseCharts = {};
let currentPortData = {};
let selectedPort = null;
let refreshInterval = null;

/**
 * Initialize the eFuse monitor
 */
function initEfuseMonitor() {
    loadCurrentData().then(() => {
        // Auto-select Port 1 (or first available port) after data loads
        const portNames = Object.keys(currentPortData);
        if (portNames.length > 0) {
            // Prefer Port 1 if available, otherwise use first port
            const defaultPort = currentPortData['Port 1'] ? 'Port 1' : portNames.sort((a, b) => {
                const numA = parseInt(a.replace(/\D/g, '')) || 0;
                const numB = parseInt(b.replace(/\D/g, '')) || 0;
                return numA - numB;
            })[0];
            showPortDetail(defaultPort);
        }
    }).catch(err => {
        console.error('Error in initial load:', err);
    });
    loadHeatmapData();

    // Auto-refresh every 10 seconds
    refreshInterval = setInterval(() => {
        loadCurrentData();
    }, 10000);
}

/**
 * Get color for current value
 */
function getEfuseColor(mA) {
    if (mA <= 0) return EFUSE_COLORS[0];

    const thresholds = Object.keys(EFUSE_COLORS).map(Number).sort((a, b) => a - b);

    for (let i = thresholds.length - 1; i >= 0; i--) {
        if (mA >= thresholds[i]) {
            return EFUSE_COLORS[thresholds[i]];
        }
    }

    return EFUSE_COLORS[0];
}

/**
 * Format current value for display
 */
function formatCurrent(mA, showUnit = true) {
    if (mA === null || mA === undefined) return '--';
    if (mA === 0) return showUnit ? '0 mA' : '0';
    if (mA >= 1000) {
        return (mA / 1000).toFixed(2) + (showUnit ? ' A' : '');
    }
    return mA + (showUnit ? ' mA' : '');
}

/**
 * Load current readings
 */
async function loadCurrentData() {
    try {
        const response = await fetch('/api/plugin/fpp-plugin-watcher/efuse/current');
        const data = await response.json();

        if (!data.success) {
            console.error('Failed to load current data:', data.error);
            return;
        }

        currentPortData = data.ports || {};
        updateLastUpdate();
        updateStatsBar(data);
        updatePortGrid(data.ports);

        // Update port detail panel if a port is selected
        if (selectedPort && currentPortData[selectedPort]) {
            updatePortDetailCurrent(currentPortData[selectedPort]);
        }

    } catch (error) {
        console.error('Error loading current data:', error);
    }
}

/**
 * Update the port detail panel's current reading
 */
function updatePortDetailCurrent(portData) {
    const currentElem = document.getElementById('portDetailCurrent');
    if (currentElem) {
        currentElem.textContent = formatCurrent(portData.currentMa);
    }

    const expectedElem = document.getElementById('portDetailExpected');
    if (expectedElem) {
        expectedElem.textContent = formatCurrent(portData.expectedCurrentMa);
    }
}

/**
 * Load heatmap/history data
 */
async function loadHeatmapData() {
    const hours = document.getElementById('timeRange')?.value || 24;

    try {
        const response = await fetch(`/api/plugin/fpp-plugin-watcher/efuse/heatmap?hours=${hours}`);
        const data = await response.json();

        if (!data.success) {
            console.error('Failed to load heatmap data:', data.error);
            showChartError('Failed to load data');
            return;
        }

        try {
            updateHistoryChart(data);
            updatePeakStats(data);
        } catch (chartError) {
            console.error('Error updating chart:', chartError);
            showChartError('Error rendering chart');
        }

    } catch (error) {
        console.error('Error loading heatmap data:', error);
        showChartError('Network error');
    }
}

/**
 * Update the last update timestamp
 */
function updateLastUpdate() {
    const elem = document.getElementById('lastUpdate');
    if (elem) {
        elem.textContent = 'Updated: ' + new Date().toLocaleTimeString();
    }
}

/**
 * Update stats bar with totals
 */
function updateStatsBar(data) {
    const totals = data.totals || {};

    const totalElem = document.getElementById('totalCurrent');
    if (totalElem) {
        totalElem.textContent = (totals.totalAmps || 0).toFixed(2) + ' A';
    }

    const activeElem = document.getElementById('activePorts');
    if (activeElem) {
        activeElem.textContent = (totals.activePortCount || 0) + ' / ' + (totals.portCount || 0);
    }
}

/**
 * Update peak statistics from heatmap data
 */
function updatePeakStats(data) {
    const peaks = data.peaks || {};
    let maxPeak = 0;
    let totalAvg = 0;
    let avgCount = 0;

    for (const portName in peaks) {
        if (peaks[portName] > maxPeak) {
            maxPeak = peaks[portName];
        }
    }

    // Calculate averages from time series
    const timeSeries = data.timeSeries || {};
    for (const portName in timeSeries) {
        const series = timeSeries[portName];
        if (series.length > 0) {
            const sum = series.reduce((acc, p) => acc + (p.value || 0), 0);
            totalAvg += sum / series.length;
            avgCount++;
        }
    }

    const peakElem = document.getElementById('peakCurrent');
    if (peakElem) {
        peakElem.textContent = formatCurrent(maxPeak);
    }

    const avgElem = document.getElementById('avgCurrent');
    if (avgElem) {
        avgElem.textContent = avgCount > 0 ? formatCurrent(Math.round(totalAvg / avgCount)) : '-- mA';
    }
}

/**
 * Update the port grid with current values
 */
function updatePortGrid(ports) {
    const grid = document.getElementById('efuseGrid');
    if (!grid) return;

    // Sort ports by number
    const sortedPorts = Object.entries(ports || {}).sort((a, b) => {
        const numA = parseInt(a[0].replace(/\D/g, '')) || 0;
        const numB = parseInt(b[0].replace(/\D/g, '')) || 0;
        return numA - numB;
    });

    if (sortedPorts.length === 0) {
        grid.innerHTML = '<div class="noDataMessage">No port data available</div>';
        return;
    }

    // Calculate grid layout (max 8 columns)
    const portCount = sortedPorts.length;
    const cols = Math.min(8, portCount);

    grid.style.gridTemplateColumns = `repeat(${cols}, 1fr)`;

    let html = '';
    for (const [portName, portData] of sortedPorts) {
        const current = portData.currentMa || 0;
        const color = getEfuseColor(current);
        const status = portData.status || 'normal';
        const percent = Math.min(100, (current / MAX_CURRENT_MA) * 100);
        const isSelected = selectedPort === portName ? 'selected' : '';

        html += `
            <div class="efusePort ${status} ${isSelected}" onclick="showPortDetail('${escapeHtml(portName)}')"
                 style="background: ${color};" title="Click to view ${escapeHtml(portName)} details">
                <div class="portName">${escapeHtml(portName.replace('Port', 'P'))}</div>
                <div class="portValue">${formatCurrent(current, false)}</div>
                <div class="portBar">
                    <div class="portBarFill" style="width: ${percent}%;"></div>
                </div>
            </div>
        `;
    }

    grid.innerHTML = html;
}

/**
 * Show port detail panel
 */
async function showPortDetail(portName) {
    selectedPort = portName;

    // Update grid to show selected state
    updatePortGrid(currentPortData);

    const panel = document.getElementById('portDetailPanel');
    if (!panel) return;

    // Update port name
    document.getElementById('portDetailName').textContent = portName;

    // Get current data
    const portData = currentPortData[portName] || {};
    document.getElementById('portDetailCurrent').textContent = formatCurrent(portData.currentMa);
    document.getElementById('portDetailExpected').textContent = formatCurrent(portData.expectedCurrentMa);

    // Show panel
    panel.style.display = 'block';

    // Load port history
    await loadPortHistory(portName);

    // Load output config
    updatePortOutputConfig(portData);
}

/**
 * Load port history for detail view
 */
async function loadPortHistory(portName) {
    const hours = document.getElementById('timeRange')?.value || 24;

    try {
        const response = await fetch(`/api/plugin/fpp-plugin-watcher/efuse/history?port=${encodeURIComponent(portName)}&hours=${hours}`);
        const data = await response.json();

        if (!data.success) {
            console.error('Failed to load port history:', data.error);
            return;
        }

        // Update peak/avg stats
        const history = data.history || [];
        if (history.length > 0) {
            let peak = 0;
            let sum = 0;

            history.forEach(h => {
                const val = h.max || h.value || 0;
                if (val > peak) peak = val;
                sum += h.avg || h.value || 0;
            });

            document.getElementById('portDetailPeak').textContent = formatCurrent(peak);
            document.getElementById('portDetailAvg').textContent = formatCurrent(Math.round(sum / history.length));
        }

        // Update chart
        updatePortHistoryChart(data);

    } catch (error) {
        console.error('Error loading port history:', error);
    }
}

/**
 * Update port output configuration display
 */
function updatePortOutputConfig(portData) {
    const container = document.getElementById('portOutputConfig');
    if (!container) return;

    if (!portData || !portData.pixelCount) {
        container.innerHTML = '<div class="noConfig">No output configuration found</div>';
        return;
    }

    container.innerHTML = `
        <div class="outputConfigTitle">Output Configuration</div>
        <div class="outputConfigGrid">
            <div class="configItem">
                <span class="configLabel">Type:</span>
                <span class="configValue">${escapeHtml(portData.protocol || 'Unknown')}</span>
            </div>
            <div class="configItem">
                <span class="configLabel">Pixels:</span>
                <span class="configValue">${portData.pixelCount || 0}</span>
            </div>
            <div class="configItem">
                <span class="configLabel">Start Ch:</span>
                <span class="configValue">${portData.startChannel || 0}</span>
            </div>
            <div class="configItem">
                <span class="configLabel">Color Order:</span>
                <span class="configValue">${escapeHtml(portData.colorOrder || 'RGB')}</span>
            </div>
            ${portData.description ? `
            <div class="configItem configDesc">
                <span class="configLabel">Description:</span>
                <span class="configValue">${escapeHtml(portData.description)}</span>
            </div>` : ''}
        </div>
        <div class="expectedInfo">
            Expected: ~${formatCurrent(portData.expectedCurrentMa)} typical / ${formatCurrent(portData.maxCurrentMa)} max
        </div>
    `;
}

/**
 * Update port history chart
 */
function updatePortHistoryChart(data) {
    const history = data.history || [];

    // Prepare data points - treat null/undefined as 0
    const chartData = history.map(h => ({
        x: new Date(h.timestamp * 1000),
        y: h.avg ?? h.value ?? 0
    }));

    const maxData = history.map(h => ({
        x: new Date(h.timestamp * 1000),
        y: h.max ?? h.value ?? 0
    }));

    const datasets = [
        {
            label: 'Average',
            data: chartData,
            borderColor: '#4e9f3d',
            backgroundColor: 'rgba(78, 159, 61, 0.1)',
            fill: true,
            tension: 0,
            pointRadius: 0
        },
        {
            label: 'Max',
            data: maxData,
            borderColor: '#dc3545',
            borderDash: [5, 5],
            fill: false,
            tension: 0,
            pointRadius: 0
        }
    ];

    const chartOptions = {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,  // Disable animation to prevent flashing
        interaction: {
            mode: 'index',
            intersect: false
        },
        scales: {
            x: {
                type: 'time',
                time: {
                    displayFormats: {
                        hour: 'HH:mm',
                        minute: 'HH:mm'
                    }
                },
                title: { display: false }
            },
            y: {
                min: 0,
                title: {
                    display: true,
                    text: 'mA'
                }
            }
        },
        plugins: {
            legend: {
                position: 'top'
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return context.dataset.label + ': ' + formatCurrent(context.raw.y);
                    }
                }
            }
        }
    };

    // Use shared chart helper from commonUI.js
    updateOrCreateChart(efuseCharts, 'portHistory', 'portHistoryChart', 'line', datasets, chartOptions);
}

/**
 * Update main history chart with all ports
 */
function updateHistoryChart(data) {
    // Hide any previous error messages
    hideChartError();

    const timeSeries = data.timeSeries || {};

    // Get list of ports with data
    const portsWithData = Object.keys(timeSeries).filter(p => timeSeries[p].length > 0);

    if (portsWithData.length === 0) {
        // No data - show message
        showChartError('No data available for selected time range');
        return;
    }

    // Build datasets
    const datasets = portsWithData.map((portName, index) => {
        const series = timeSeries[portName];
        const color = getChartColor(index);

        return {
            label: portName,
            data: series.map(p => ({
                x: new Date(p.timestamp * 1000),
                y: p.value ?? 0
            })),
            borderColor: color,
            backgroundColor: color + '20',
            fill: false,
            tension: 0,
            pointRadius: 0
        };
    });

    const chartOptions = {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,  // Disable animation to prevent flashing
        interaction: {
            mode: 'index',
            intersect: false
        },
        scales: {
            x: {
                type: 'time',
                time: {
                    displayFormats: {
                        hour: 'HH:mm',
                        minute: 'HH:mm'
                    }
                },
                title: { display: false }
            },
            y: {
                min: 0,
                title: {
                    display: true,
                    text: 'mA'
                }
            }
        },
        plugins: {
            legend: {
                position: 'top',
                labels: {
                    boxWidth: 12,
                    usePointStyle: true
                }
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return context.dataset.label + ': ' + formatCurrent(context.raw.y);
                    }
                }
            }
        }
    };

    // Use shared chart helper from commonUI.js
    updateOrCreateChart(efuseCharts, 'history', 'efuseHistoryChart', 'line', datasets, chartOptions);
}

/**
 * Close port detail panel
 */
function closePortDetail() {
    const panel = document.getElementById('portDetailPanel');
    if (panel) {
        panel.style.display = 'none';
    }
    selectedPort = null;
}

/**
 * Refresh all data
 */
function refreshData() {
    loadCurrentData();
    loadHeatmapData();

    if (selectedPort) {
        loadPortHistory(selectedPort);
    }
}

/**
 * Show error message on chart area
 */
function showChartError(message) {
    const canvas = document.getElementById('efuseHistoryChart');
    if (!canvas) return;

    // Destroy existing chart if any
    if (efuseCharts.history) {
        efuseCharts.history.destroy();
        efuseCharts.history = null;
    }

    // Show error message in chart area
    const parent = canvas.parentElement;
    let errorDiv = parent.querySelector('.chartErrorMessage');
    if (!errorDiv) {
        errorDiv = document.createElement('div');
        errorDiv.className = 'chartErrorMessage noDataMessage';
        parent.insertBefore(errorDiv, canvas);
    }
    errorDiv.innerHTML = `<i class="fas fa-exclamation-triangle"></i> ${escapeHtml(message)}`;
    errorDiv.style.display = 'block';
    canvas.style.display = 'none';
}

/**
 * Hide chart error message and ensure canvas is visible
 */
function hideChartError() {
    const canvas = document.getElementById('efuseHistoryChart');
    if (!canvas) return;

    const parent = canvas.parentElement;
    const errorDiv = parent.querySelector('.chartErrorMessage');

    // Hide error div if present
    if (errorDiv) {
        errorDiv.style.display = 'none';
    }

    // Always ensure canvas is visible when loading new data
    canvas.style.display = 'block';
}

/**
 * Get chart color by index
 */
function getChartColor(index) {
    const colors = [
        '#4e9f3d', '#3498db', '#9b59b6', '#e67e22',
        '#1abc9c', '#e74c3c', '#34495e', '#f1c40f',
        '#16a085', '#2ecc71', '#8e44ad', '#d35400'
    ];
    return colors[index % colors.length];
}

// Note: escapeHtml() is provided by commonUI.js

/**
 * Show expected current help modal
 */
function showExpectedHelp(event) {
    if (event) event.stopPropagation();
    const modal = document.getElementById('expectedHelpModal');
    if (modal) modal.style.display = 'flex';
}

/**
 * Hide expected current help modal
 */
function hideExpectedHelp(event) {
    const modal = document.getElementById('expectedHelpModal');
    if (modal) modal.style.display = 'none';
}

/**
 * Show page help modal
 */
function showPageHelp(event) {
    if (event) event.stopPropagation();
    const modal = document.getElementById('pageHelpModal');
    if (modal) modal.style.display = 'flex';
}

/**
 * Hide page help modal
 */
function hidePageHelp(event) {
    const modal = document.getElementById('pageHelpModal');
    if (modal) modal.style.display = 'none';
}

// Cleanup on page unload
window.addEventListener('beforeunload', function() {
    if (refreshInterval) {
        clearInterval(refreshInterval);
    }
});
