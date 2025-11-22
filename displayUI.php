<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Watcher - System Information</title>
    <link rel="stylesheet" href="/css/fpp-bootstrap/dist-new/fpp-bootstrap-5-3.css">
    <link rel="stylesheet" href="/css/fpp.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
    <style>
        .systemMonitorContainer {
            max-width: 1200px;
            margin: 0 auto;
        }

        .statsGrid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .statCard {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 0.5rem;
            padding: 1.5rem;
            color: white;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .statCard:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
        }

        .statCard.success {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        }

        .statCard.warning {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }

        .statCard.info {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }

        .statCard.primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .statCard.dark {
            background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%);
        }

        .statIcon {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
            opacity: 0.9;
        }

        .statLabel {
            font-size: 0.875rem;
            opacity: 0.9;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
        }

        .statValue {
            font-size: 2rem;
            font-weight: bold;
            line-height: 1.2;
        }

        .statSubtext {
            font-size: 0.875rem;
            opacity: 0.8;
            margin-top: 0.5rem;
        }

        .detailsPanel {
            background-color: #fff;
            border: 1px solid #dee2e6;
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .panelTitle {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: #212529;
            border-bottom: 2px solid #007bff;
            padding-bottom: 0.5rem;
        }

        .infoRow {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .infoRow:last-child {
            border-bottom: none;
        }

        .infoLabel {
            font-weight: 500;
            color: #6c757d;
        }

        .infoValue {
            color: #212529;
            font-weight: 600;
        }

        .statusBadge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .statusBadge.online {
            background-color: #d4edda;
            color: #155724;
        }

        .statusBadge.offline {
            background-color: #f8d7da;
            color: #721c24;
        }

        .statusBadge.unknown {
            background-color: #fff3cd;
            color: #856404;
        }

        .loadingSpinner {
            text-align: center;
            padding: 3rem;
            color: #6c757d;
        }

        .loadingSpinner i {
            font-size: 3rem;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .refreshButton {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            z-index: 1000;
        }

        .refreshButton:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2);
        }

        .refreshButton i {
            font-size: 1.5rem;
        }

        .chartContainer {
            background-color: #fff;
            border: 1px solid #dee2e6;
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .progressBar {
            height: 1.5rem;
            background-color: #e9ecef;
            border-radius: 0.5rem;
            overflow: hidden;
            margin-top: 0.5rem;
        }

        .progressFill {
            height: 100%;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            transition: width 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.875rem;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <div class="systemMonitorContainer">
        <h2 style="margin-bottom: 2rem; color: #212529;">
            <i class="fas fa-chart-line"></i> Watcher System Information
        </h2>

        <div id="loadingIndicator" class="loadingSpinner">
            <i class="fas fa-spinner"></i>
            <p>Loading system information...</p>
        </div>

        <div id="systemStats" style="display: none;">
            <!-- Stats Grid -->
            <div class="statsGrid">
                <div class="statCard success">
                    <div class="statIcon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="statLabel">System Uptime</div>
                    <div class="statValue" id="uptimeValue">--</div>
                    <div class="statSubtext" id="uptimeSubtext">Loading...</div>
                </div>

                <div class="statCard info">
                    <div class="statIcon">
                        <i class="fas fa-microchip"></i>
                    </div>
                    <div class="statLabel">CPU Usage</div>
                    <div class="statValue" id="cpuValue">--%</div>
                    <div class="statSubtext" id="cpuSubtext">Loading...</div>
                </div>

                <div class="statCard warning">
                    <div class="statIcon">
                        <i class="fas fa-memory"></i>
                    </div>
                    <div class="statLabel">Memory Usage</div>
                    <div class="statValue" id="memoryValue">--%</div>
                    <div class="statSubtext" id="memorySubtext">Loading...</div>
                </div>
            </div>

            <!-- Temperature Sensors -->
            <div class="chartContainer" id="temperaturePanel" style="display: none;">
                <div class="panelTitle">
                    <i class="fas fa-thermometer-half"></i> Temperature Sensors
                </div>
                <div id="temperatureGraph">
                    <!-- Temperature graph will be populated here -->
                </div>
            </div>

            <!-- Storage Information -->
            <div class="chartContainer">
                <div class="panelTitle">
                    <i class="fas fa-hdd"></i> Storage Usage
                </div>
                <div id="storageInfo">
                    <!-- Storage info will be populated here -->
                </div>
            </div>

            <!-- System Details Panel -->
            <div class="detailsPanel">
                <div class="panelTitle">
                    <i class="fas fa-server"></i> System Information
                </div>
                <div id="systemDetails">
                    <!-- System details will be populated here -->
                </div>
            </div>

            <!-- Advanced View Data Panel -->
            <div class="detailsPanel">
                <div class="panelTitle">
                    <i class="fas fa-chart-bar"></i> Advanced System Information
                </div>
                <div id="advancedMetrics">
                    <!-- Advanced metrics will be populated here -->
                </div>
            </div>
        </div>

        <button class="refreshButton" onclick="loadSystemData()" title="Refresh Data">
            <i class="fas fa-sync-alt"></i>
        </button>
    </div>

    <script>
        let isRefreshing = false;
        let useFahrenheit = false; // Global setting for temperature display

        // Load system data from FPP API
        async function loadSystemData(showLoadingIndicator = true) {
            if (isRefreshing) return;
            isRefreshing = true;

            try {
                // Add spinning animation to refresh button
                const refreshBtn = document.querySelector('.refreshButton i');
                if (refreshBtn) {
                    refreshBtn.style.animation = 'spin 1s linear infinite';
                }

                // Only show loading indicator on initial load
                if (showLoadingIndicator) {
                    document.getElementById('loadingIndicator').style.display = 'block';
                    document.getElementById('systemStats').style.display = 'none';
                }

                // Fetch temperature preference setting
                try {
                    const tempSettingResponse = await fetch('/api/settings/temperatureInF');
                    const tempSettingData = await tempSettingResponse.json();
                    useFahrenheit = (tempSettingData.value === "1" || tempSettingData.value === 1);
                } catch (error) {
                    console.warn('Could not fetch temperature setting, defaulting to Celsius:', error);
                    useFahrenheit = false;
                }

                // Fetch system status (includes all data)
                const statusResponse = await fetch('/api/system/status');
                const statusData = await statusResponse.json();

                // Update the display
                updateDisplay(statusData);

                // Hide loading indicator
                document.getElementById('loadingIndicator').style.display = 'none';
                document.getElementById('systemStats').style.display = 'block';

                // Stop spinning animation
                if (refreshBtn) {
                    refreshBtn.style.animation = '';
                }
            } catch (error) {
                console.error('Error loading system data:', error);

                if (showLoadingIndicator) {
                    document.getElementById('loadingIndicator').innerHTML = `
                        <i class="fas fa-exclamation-triangle" style="color: #dc3545;"></i>
                        <p style="color: #dc3545;">Error loading system information</p>
                        <button class="buttons btn-primary" onclick="loadSystemData()">Retry</button>
                    `;
                }
            } finally {
                isRefreshing = false;
            }
        }

        // Convert Celsius to Fahrenheit
        function celsiusToFahrenheit(celsius) {
            return (celsius * 9/5) + 32;
        }

        // Format temperature based on user preference
        function formatTemperature(celsius, includeUnit = true) {
            if (useFahrenheit) {
                const fahrenheit = celsiusToFahrenheit(celsius);
                return includeUnit ? `${fahrenheit.toFixed(1)}°F` : fahrenheit.toFixed(1);
            }
            return includeUnit ? `${celsius.toFixed(1)}°C` : celsius.toFixed(1);
        }

        // Update the display with fetched data
        function updateDisplay(status) {
            // Update Uptime
            if (status.uptimeTotalSeconds) {
                const uptime = formatUptime(status.uptimeTotalSeconds);
                document.getElementById('uptimeValue').textContent = uptime.primary;
                document.getElementById('uptimeSubtext').textContent = uptime.secondary;
            }

            // Update CPU Usage
            if (status.advancedView?.Utilization?.CPU) {
                const cpuPercent = parseFloat(status.advancedView.Utilization.CPU) || 0;
                document.getElementById('cpuValue').textContent = cpuPercent.toFixed(1) + '%';
                document.getElementById('cpuSubtext').textContent = getCPUStatus(cpuPercent);
            }

            // Update Memory Usage
            if (status.advancedView?.Utilization?.Memory) {
                const memPercent = parseFloat(status.advancedView.Utilization.Memory) || 0;
                document.getElementById('memoryValue').textContent = memPercent.toFixed(1) + '%';
                document.getElementById('memorySubtext').textContent = getMemoryStatus(memPercent);
            }

            // Update System Details
            updateSystemDetails(status);

            // Update Advanced Metrics
            updateAdvancedMetrics(status);

            // Update Temperature Sensors
            updateTemperatureSensors(status);

            // Update Storage Info
            updateStorageInfo(status);
        }

        // Update system details panel
        function updateSystemDetails(status) {
            const container = document.getElementById('systemDetails');
            let html = '';

            const details = [
                { label: 'Hostname', value: status.advancedView?.HostName || 'Unknown' },
                { label: 'FPP Version', value: status.advancedView?.Version || 'Unknown' },
                { label: 'Platform', value: status.advancedView?.Platform || 'Unknown' },
                { label: 'Variant', value: status.advancedView?.Variant || 'Unknown' },
                { label: 'FPP Mode', value: status.advancedView?.Mode || status.mode_name || 'Unknown' },
                { label: 'Current Time', value: status.time || 'Unknown' },
                { label: 'System Uptime', value: status.advancedView?.Utilization?.Uptime || status.uptimeStr || 'Unknown' }
            ];

            details.forEach(detail => {
                html += `
                    <div class="infoRow">
                        <span class="infoLabel">${detail.label}:</span>
                        <span class="infoValue">${escapeHtml(detail.value)}</span>
                    </div>
                `;
            });

            container.innerHTML = html;
        }

        // Update advanced metrics panel
        function updateAdvancedMetrics(status) {
            const container = document.getElementById('advancedMetrics');
            let html = '';

            if (status.advancedView) {
                const metrics = [
                    { label: 'OS Release', value: status.advancedView.OSRelease || 'N/A' },
                    { label: 'OS Version', value: status.advancedView.OSVersion || 'N/A' },
                    { label: 'Kernel Version', value: status.advancedView.Kernel || 'N/A' },
                    { label: 'Branch', value: status.advancedView.Branch || 'N/A' },
                    { label: 'Local Git Version', value: status.advancedView.LocalGitVersion || 'N/A' },
                    { label: 'Channel Ranges', value: status.advancedView.channelRanges || 'N/A' },
                    { label: 'IP Addresses', value: status.advancedView.IPs?.join(', ') || 'N/A' }
                ];

                metrics.forEach(metric => {
                    html += `
                        <div class="infoRow">
                            <span class="infoLabel">${metric.label}:</span>
                            <span class="infoValue">${escapeHtml(String(metric.value))}</span>
                        </div>
                    `;
                });
            } else {
                html = '<p style="text-align: center; color: #6c757d;">No advanced metrics available</p>';
            }

            container.innerHTML = html;
        }

        // Update temperature sensors with visual graph
        function updateTemperatureSensors(status) {
            const panel = document.getElementById('temperaturePanel');
            const container = document.getElementById('temperatureGraph');

            if (status.sensors && status.sensors.length > 0) {
                // Filter for temperature sensors
                const tempSensors = status.sensors.filter(sensor =>
                    sensor.valueType === 'Temperature'
                );

                if (tempSensors.length > 0) {
                    panel.style.display = 'block';
                    let html = '<div style="padding: 1rem 0;">';

                    tempSensors.forEach(sensor => {
                        const tempCelsius = parseFloat(sensor.value) || 0;
                        const maxTempCelsius = 100; // Max temperature for scale (°C)
                        const tempPercent = Math.min((tempCelsius / maxTempCelsius) * 100, 100);

                        // Color coding based on temperature (in Celsius)
                        let barColor = '#38ef7d'; // Green for cool
                        if (tempCelsius > 80) {
                            barColor = '#f5576c'; // Red for hot
                        } else if (tempCelsius > 60) {
                            barColor = '#f093fb'; // Pink for warm
                        } else if (tempCelsius > 40) {
                            barColor = '#ffc107'; // Yellow for moderate
                        }

                        // Format temperatures based on user preference
                        const displayTemp = formatTemperature(tempCelsius, true);
                        const minTemp = formatTemperature(0, true);
                        const maxTemp = formatTemperature(maxTempCelsius, true);
                        const barTemp = formatTemperature(tempCelsius, true);

                        html += `
                            <div style="margin-bottom: 1.5rem;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                                        <i class="fas fa-thermometer-half" style="color: ${barColor};"></i>
                                        <strong>${escapeHtml(sensor.label.replace(':', '').trim())}</strong>
                                    </div>
                                    <span style="font-size: 1.5rem; font-weight: bold; color: ${barColor};">
                                        ${displayTemp}
                                    </span>
                                </div>
                                <div class="progressBar" style="background-color: #e9ecef;">
                                    <div class="progressFill" style="width: ${tempPercent}%; background: linear-gradient(90deg, ${barColor}, ${barColor}); transition: all 0.3s ease;">
                                        <span style="color: white; font-size: 0.875rem;">${barTemp}</span>
                                    </div>
                                </div>
                                <div style="margin-top: 0.5rem; display: flex; justify-content: space-between; font-size: 0.75rem; color: #6c757d;">
                                    <span>${minTemp}</span>
                                    <span style="text-align: center;">
                                        Status: ${getTempStatus(tempCelsius)}
                                    </span>
                                    <span>${maxTemp}</span>
                                </div>
                            </div>
                        `;
                    });

                    html += '</div>';
                    container.innerHTML = html;
                } else {
                    panel.style.display = 'none';
                }
            } else {
                panel.style.display = 'none';
            }
        }

        // Get temperature status text (always based on Celsius thresholds)
        function getTempStatus(tempCelsius) {
            if (tempCelsius < 40) return '❄️ Cool';
            if (tempCelsius < 60) return '✅ Normal';
            if (tempCelsius < 80) return '⚠️ Warm';
            return '🔥 Hot';
        }

        // Update storage info with progress bars
        function updateStorageInfo(status) {
            const container = document.getElementById('storageInfo');
            let html = '';

            // Only show Root storage volume
            if (status.advancedView?.Utilization?.Disk?.Root) {
                const diskInfo = status.advancedView.Utilization.Disk.Root;
                const freeBytes = diskInfo.Free || 0;
                const totalBytes = diskInfo.Total || 1;
                const usedBytes = totalBytes - freeBytes;
                const usedPercent = (usedBytes / totalBytes) * 100;

                // Determine color based on usage
                let progressColor = '#667eea'; // Default blue
                if (usedPercent > 90) {
                    progressColor = '#f5576c'; // Red for critical
                } else if (usedPercent > 75) {
                    progressColor = '#f093fb'; // Pink for warning
                } else {
                    progressColor = '#38ef7d'; // Green for good
                }

                html = `
                    <div style="margin-bottom: 1.5rem;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                            <strong>Root Filesystem</strong>
                            <span style="font-weight: 500;">
                                ${formatBytes(usedBytes)} / ${formatBytes(totalBytes)}
                            </span>
                        </div>
                        <div class="progressBar">
                            <div class="progressFill" style="width: ${usedPercent}%; background: linear-gradient(90deg, ${progressColor}, ${progressColor});">
                                ${usedPercent.toFixed(1)}% Used
                            </div>
                        </div>
                        <div style="margin-top: 0.25rem; font-size: 0.875rem; color: #6c757d;">
                            <i class="fas fa-check-circle" style="color: #28a745;"></i> Available: ${formatBytes(freeBytes)}
                        </div>
                    </div>
                `;
            } else {
                html = '<p style="text-align: center; color: #6c757d; padding: 2rem;">No storage information available</p>';
            }

            container.innerHTML = html;
        }

        // Format uptime from seconds
        function formatUptime(seconds) {
            const days = Math.floor(seconds / 86400);
            const hours = Math.floor((seconds % 86400) / 3600);
            const minutes = Math.floor((seconds % 3600) / 60);

            if (days > 0) {
                return {
                    primary: `${days}d ${hours}h`,
                    secondary: `${days} days, ${hours} hours`
                };
            } else if (hours > 0) {
                return {
                    primary: `${hours}h ${minutes}m`,
                    secondary: `${hours} hours, ${minutes} minutes`
                };
            } else {
                return {
                    primary: `${minutes}m`,
                    secondary: `${minutes} minutes`
                };
            }
        }

        // Get CPU status text
        function getCPUStatus(percent) {
            if (percent < 25) return 'Low usage';
            if (percent < 50) return 'Normal usage';
            if (percent < 75) return 'Moderate usage';
            return 'High usage';
        }

        // Get Memory status text
        function getMemoryStatus(percent) {
            if (percent < 50) return 'Good';
            if (percent < 75) return 'Moderate';
            if (percent < 90) return 'High';
            return 'Critical';
        }

        // Format bytes to human readable
        function formatBytes(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        // Escape HTML to prevent XSS
        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return String(text).replace(/[&<>"']/g, m => map[m]);
        }

        // Auto-refresh every 30 seconds (without showing loading indicator)
        setInterval(() => loadSystemData(false), 30000);

        // Load data on page load (with loading indicator)
        document.addEventListener('DOMContentLoaded', function() {
            loadSystemData(true);
        });
    </script>
</body>
</html>
