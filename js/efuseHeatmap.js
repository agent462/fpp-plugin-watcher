/**
 * eFuse Heatmap Visualization
 *
 * Provides interactive heatmap visualization for eFuse current monitoring.
 */

(function() {
    // Guard against double-loading
    if (window._efuseHeatmapLoaded) return;
    window._efuseHeatmapLoaded = true;

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
    const PORTS_PER_CHART = 16;
    const MAX_CHART_POINTS = 200; // Downsample to this many points per line

    let efuseCharts = {};
    let currentPortData = {};
    let selectedPort = null;
    let refreshInterval = null;
    let chartSkeletonsCreated = false;

    /**
     * Downsample data array to target number of points using LTTB algorithm (simplified)
     */
    function downsampleData(data, targetPoints) {
        if (!data || data.length <= targetPoints) return data;

        const result = [];
        const bucketSize = (data.length - 2) / (targetPoints - 2);

        result.push(data[0]); // Always keep first point

        for (let i = 1; i < targetPoints - 1; i++) {
            const bucketStart = Math.floor((i - 1) * bucketSize) + 1;
            const bucketEnd = Math.min(Math.floor(i * bucketSize) + 1, data.length - 1);

            // Find point with max value in bucket (preserves peaks)
            let maxIdx = bucketStart;
            let maxVal = data[bucketStart].y;
            for (let j = bucketStart + 1; j < bucketEnd; j++) {
                if (data[j].y > maxVal) {
                    maxVal = data[j].y;
                    maxIdx = j;
                }
            }
            result.push(data[maxIdx]);
        }

        result.push(data[data.length - 1]); // Always keep last point
        return result;
    }

    /**
     * Create chart skeleton containers based on port count
     */
    function createChartSkeletons(portCount) {
        const container = document.getElementById('efuseHistoryChartsContainer');
        if (!container || chartSkeletonsCreated) return;

        const numCharts = Math.ceil(portCount / PORTS_PER_CHART);
        let html = '';

        for (let i = 0; i < numCharts; i++) {
            const startPort = i * PORTS_PER_CHART + 1;
            const endPort = Math.min((i + 1) * PORTS_PER_CHART, portCount);
            const chartId = `efuseHistoryChart_${i}`;

            html += `
                <div class="efuseChartCard" id="chartCard_${i}">
                    <div class="efuseChartTitle">
                        <span><i class="fas fa-chart-area"></i> Current History - Ports ${startPort}-${endPort}</span>
                    </div>
                    <div class="chartLoading" id="chartLoading_${i}">
                        <i class="fas fa-spinner fa-spin"></i> Loading chart data...
                    </div>
                    <canvas id="${chartId}" style="max-height: 400px;"></canvas>
                </div>
            `;
        }

        container.innerHTML = html;
        chartSkeletonsCreated = true;

        // Create empty charts immediately so structure is visible
        for (let i = 0; i < numCharts; i++) {
            createEmptyChart(i);
        }
    }

    /**
     * Create an empty chart with axes but no data
     */
    function createEmptyChart(groupIndex) {
        const chartId = `efuseHistoryChart_${groupIndex}`;
        const chartKey = `history_${groupIndex}`;
        const canvas = document.getElementById(chartId);

        if (!canvas || efuseCharts[chartKey]) return;

        const ctx = canvas.getContext('2d');
        efuseCharts[chartKey] = new Chart(ctx, {
            type: 'line',
            data: { datasets: [] },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                scales: {
                    x: {
                        type: 'time',
                        time: {
                            displayFormats: { hour: 'HH:mm', minute: 'HH:mm' }
                        }
                    },
                    y: {
                        min: 0,
                        title: { display: true, text: 'mA' }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { boxWidth: 12, usePointStyle: true }
                    }
                }
            }
        });
    }

    /**
     * Get chart color by index (16 distinct colors for PORTS_PER_CHART)
     */
    function getEfuseChartColor(index) {
        const colors = [
            '#4e9f3d', '#3498db', '#9b59b6', '#e67e22',
            '#1abc9c', '#e74c3c', '#34495e', '#f1c40f',
            '#16a085', '#2ecc71', '#8e44ad', '#d35400',
            '#00bcd4', '#ff5722', '#607d8b', '#795548'
        ];
        return colors[index % colors.length];
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
     * Show error message in the history charts container
     */
    function showHistoryChartsError(message) {
        const container = document.getElementById('efuseHistoryChartsContainer');
        if (!container) return;

        container.innerHTML = `
            <div class="efuseChartCard">
                <div class="efuseChartTitle">
                    <span><i class="fas fa-chart-area"></i> Current History</span>
                </div>
                <div class="noDataMessage"><i class="fas fa-exclamation-triangle"></i> ${escapeHtml(message)}</div>
            </div>
        `;
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
                showHistoryChartsError('Failed to load data');
                return;
            }

            try {
                updateHistoryChart(data);
                updatePeakStats(data);
            } catch (chartError) {
                console.error('Error updating chart:', chartError);
                showHistoryChartsError('Error rendering chart');
            }

        } catch (error) {
            console.error('Error loading heatmap data:', error);
            showHistoryChartsError('Network error');
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

        // Update labels to reflect selected time range
        const hours = document.getElementById('timeRange')?.value || 24;
        const timeLabel = hours == 1 ? '1h' : hours + 'h';

        const peakLabel = document.getElementById('peakLabel');
        if (peakLabel) {
            peakLabel.textContent = `Peak (${timeLabel})`;
        }

        const avgLabel = document.getElementById('avgLabel');
        if (avgLabel) {
            avgLabel.textContent = `Average (${timeLabel})`;
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

        const brightnessDisplay = (portData.brightness !== null && portData.brightness !== undefined)
            ? portData.brightness + '%'
            : '--';

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
                    <span class="configLabel">Brightness:</span>
                    <span class="configValue">${brightnessDisplay}</span>
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
     * Update main history charts with all ports (split into groups of 16)
     */
    function updateHistoryChart(data) {
        const container = document.getElementById('efuseHistoryChartsContainer');
        if (!container) return;

        const timeSeries = data.timeSeries || {};
        const portCount = window.efuseConfig?.ports || 16;

        // Ensure skeletons exist
        if (!chartSkeletonsCreated) {
            createChartSkeletons(portCount);
        }

        // Get list of ports with data, sorted by port number
        const portsWithData = Object.keys(timeSeries)
            .filter(p => timeSeries[p].length > 0)
            .sort((a, b) => {
                const numA = parseInt(a.replace(/\D/g, '')) || 0;
                const numB = parseInt(b.replace(/\D/g, '')) || 0;
                return numA - numB;
            });

        if (portsWithData.length === 0) {
            // Hide loading indicators and show no data message
            const numCharts = Math.ceil(portCount / PORTS_PER_CHART);
            for (let i = 0; i < numCharts; i++) {
                const loading = document.getElementById(`chartLoading_${i}`);
                if (loading) loading.style.display = 'none';
                // Clear chart data
                const chartKey = `history_${i}`;
                if (efuseCharts[chartKey]) {
                    efuseCharts[chartKey].data.datasets = [];
                    efuseCharts[chartKey].update('none');
                }
            }
            return;
        }

        // Split ports into groups of PORTS_PER_CHART
        const portGroups = [];
        for (let i = 0; i < portsWithData.length; i += PORTS_PER_CHART) {
            portGroups.push(portsWithData.slice(i, i + PORTS_PER_CHART));
        }

        // Update charts progressively using requestAnimationFrame
        let currentGroup = 0;

        function updateNextChart() {
            if (currentGroup >= portGroups.length) return;

            const group = portGroups[currentGroup];
            const groupIndex = currentGroup;
            const chartKey = `history_${groupIndex}`;

            // Hide loading indicator for this chart
            const loading = document.getElementById(`chartLoading_${groupIndex}`);
            if (loading) loading.style.display = 'none';

            // Build datasets for this group with downsampling
            const datasets = group.map((portName, index) => {
                const series = timeSeries[portName];
                const color = getEfuseChartColor(index);

                // Convert to chart format then downsample
                let chartData = series.map(p => ({
                    x: new Date(p.timestamp * 1000),
                    y: p.value ?? 0
                }));
                chartData = downsampleData(chartData, MAX_CHART_POINTS);

                return {
                    label: portName,
                    data: chartData,
                    borderColor: color,
                    backgroundColor: color + '20',
                    fill: false,
                    tension: 0,
                    pointRadius: 0
                };
            });

            // Reuse existing chart if available
            if (efuseCharts[chartKey]) {
                efuseCharts[chartKey].data.datasets = datasets;
                efuseCharts[chartKey].options.plugins.tooltip = {
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': ' + formatCurrent(context.raw.y);
                        }
                    }
                };
                efuseCharts[chartKey].update('none'); // 'none' = no animation
            } else {
                // Create new chart if skeleton didn't create it
                const chartId = `efuseHistoryChart_${groupIndex}`;
                const canvas = document.getElementById(chartId);
                if (canvas) {
                    const ctx = canvas.getContext('2d');
                    efuseCharts[chartKey] = new Chart(ctx, {
                        type: 'line',
                        data: { datasets },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            animation: false,
                            interaction: { mode: 'index', intersect: false },
                            scales: {
                                x: {
                                    type: 'time',
                                    time: { displayFormats: { hour: 'HH:mm', minute: 'HH:mm' } },
                                    title: { display: false }
                                },
                                y: {
                                    min: 0,
                                    title: { display: true, text: 'mA' }
                                }
                            },
                            plugins: {
                                legend: {
                                    position: 'top',
                                    labels: { boxWidth: 12, usePointStyle: true }
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            return context.dataset.label + ': ' + formatCurrent(context.raw.y);
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
            }

            currentGroup++;
            // Schedule next chart update on next frame
            if (currentGroup < portGroups.length) {
                requestAnimationFrame(updateNextChart);
            }
        }

        // Start progressive update
        requestAnimationFrame(updateNextChart);
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
     * Initialize the eFuse monitor
     */
    function initEfuseMonitor() {
        // Create chart skeletons immediately based on known port count
        const portCount = window.efuseConfig?.ports || 16;
        createChartSkeletons(portCount);

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

    // Expose functions that need to be called from HTML onclick handlers
    window.initEfuseMonitor = initEfuseMonitor;
    window.refreshData = refreshData;
    window.showPortDetail = showPortDetail;
    window.closePortDetail = closePortDetail;
    window.showExpectedHelp = showExpectedHelp;
    window.hideExpectedHelp = hideExpectedHelp;
    window.showPageHelp = showPageHelp;
    window.hidePageHelp = hidePageHelp;

})();
