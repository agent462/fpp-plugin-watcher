<?php
include_once __DIR__ . '/../lib/core/config.php';
include_once __DIR__ . '/../lib/core/watcherCommon.php';
include_once __DIR__ . '/../lib/ui/common.php';
include_once __DIR__ . '/../lib/controllers/efuseHardware.php';

$config = readPluginConfig();
$hardware = detectEfuseHardware();
$retentionDays = $config['efuseRetentionDays'] ?? 7;
$collectionInterval = $config['efuseCollectionInterval'] ?? 5;

// Build dynamic time range options based on retention
$timeRangeOptions = [
    '1' => 'Last 1 Hour',
    '6' => 'Last 6 Hours',
    '12' => 'Last 12 Hours',
    '24' => 'Last 24 Hours'
];

if ($retentionDays >= 3) {
    $timeRangeOptions['48'] = 'Last 2 Days';
    $timeRangeOptions['72'] = 'Last 3 Days';
}
if ($retentionDays >= 7) {
    $timeRangeOptions['168'] = 'Last 7 Days';
}
if ($retentionDays >= 14) {
    $timeRangeOptions['336'] = 'Last 14 Days';
}
if ($retentionDays >= 30) {
    $timeRangeOptions['720'] = 'Last 30 Days';
}
if ($retentionDays >= 90) {
    $timeRangeOptions['2160'] = 'Last 90 Days';
}

renderCSSIncludes(true);
renderCommonJS();
?>
<link rel="stylesheet" href="/plugin.php?plugin=fpp-plugin-watcher&file=css/efuseHeatmap.css&nopage=1">
<script src="/plugin.php?plugin=fpp-plugin-watcher&file=js/efuseHeatmap.js&nopage=1"></script>

<script>
    window.efuseConfig = {
        supported: <?php echo json_encode($hardware['supported']); ?>,
        type: <?php echo json_encode($hardware['type']); ?>,
        ports: <?php echo json_encode($hardware['ports']); ?>,
        collectionInterval: <?php echo json_encode($collectionInterval); ?>,
        retentionDays: <?php echo json_encode($retentionDays); ?>
    };
</script>

<div class="efuseContainer">
    <div class="efuseHeader">
        <h2>
            <i class="fas fa-bolt"></i> eFuse Current Monitor
        </h2>
        <div class="efuseHeaderRight">
            <span id="lastUpdate" class="lastUpdate"></span>
            <i class="fas fa-question-circle efusePageHelp" onclick="showPageHelp(event)" title="About eFuse Monitoring"></i>
        </div>
    </div>

    <?php if (!$hardware['supported']): ?>
    <div class="efuseNotSupported">
        <i class="fas fa-exclamation-triangle"></i>
        <h3>No Compatible Hardware Detected</h3>
        <p>eFuse current monitoring requires compatible hardware such as:</p>
        <ul>
            <li>BeagleBone capes with eFuse (PB16, etc.)</li>
            <li>I2C ADC for current sensing (ADS7828)</li>
            <li>Falcon smart receivers with current monitoring</li>
        </ul>
    </div>
    <?php else: ?>

    <!-- Tripped Fuse Alert Banner (shown by JS when fuses are tripped) -->
    <div id="trippedBanner" class="efuseTrippedBanner" style="display: none;">
        <span class="alertIcon"><i class="fas fa-exclamation-triangle"></i></span>
        <span class="alertText">
            <strong><span id="trippedCount">0</span> FUSES TRIPPED:</strong>
            <span id="trippedPortList"></span>
        </span>
        <button class="efuseControlBtn warning" onclick="resetAllTripped()">
            <i class="fas fa-redo"></i> Reset All
        </button>
    </div>

    <!-- Hardware Info Bar with Master Controls -->
    <div class="efuseHardwareInfo">
        <div class="hardwareInfoLeft">
            <div class="hardwareItem">
                <span class="hardwareLabel">Hardware:</span>
                <span class="hardwareValue" id="hardwareType"><?php echo htmlspecialchars($hardware['details']['cape'] ?? $hardware['type'] ?? 'Unknown'); ?></span>
            </div>
            <div class="hardwareItem">
                <span class="hardwareLabel">Ports:</span>
                <span class="hardwareValue" id="portCount"><?php echo $hardware['ports']; ?></span>
            </div>
            <div class="hardwareItem">
                <span class="hardwareLabel">Total Current:</span>
                <span class="hardwareValue" id="totalCurrent">-- A</span>
            </div>
        </div>
        <div class="hardwareMasterControls">
            <button class="efuseControlBtn primary" onclick="masterControl('on')" title="Enable all ports">
                <i class="fas fa-power-off"></i> All On
            </button>
            <button class="efuseControlBtn danger" onclick="masterControl('off')" title="Disable all ports">
                <i class="fas fa-power-off"></i> All Off
            </button>
            <button class="efuseControlBtn warning" id="resetTrippedBtn" onclick="resetAllTripped()" style="display: none;" title="Reset all tripped fuses">
                <i class="fas fa-redo"></i> Reset <span class="badge">0</span>
            </button>
        </div>
    </div>

    <!-- Stats Bar -->
    <div class="efuseStatsBar">
        <div class="statItem">
            <div class="statLabel">Active Ports</div>
            <div class="statValue" id="activePorts">--</div>
        </div>
        <div class="statItem">
            <div class="statLabel" id="peakLabel">Peak (24h)</div>
            <div class="statValue" id="peakCurrent">-- A</div>
        </div>
        <div class="statItem">
            <div class="statLabel" id="avgLabel">Average (24h)</div>
            <div class="statValue" id="avgCurrent">-- A</div>
        </div>
    </div>

    <!-- Port Grid Heatmap -->
    <div class="efuseGridCard">
        <div class="efuseGridTitle">
            <span><i class="fas fa-th"></i> Port Current Heatmap</span>
        </div>
        <div id="efuseGrid" class="efuseGrid"></div>
        <div class="efuseColorScale">
            <span class="scaleLabel">0A</span>
            <div class="scaleGradient"></div>
            <span class="scaleLabel">6A (per port max)</span>
        </div>
        <div class="efuseGridHint">
            <i class="fas fa-hand-pointer"></i> Click a port tile for details, click <i class="fas fa-power-off"></i> button to toggle on/off
        </div>
    </div>

    <!-- Current History Header with Time Range Selector -->
    <div class="efuseChartsHeader">
        <span><i class="fas fa-chart-line"></i> Current History</span>
        <div class="efuseControls">
            <label for="timeRange">Time Range:</label>
            <select id="timeRange" onchange="loadAllData()">
                <?php foreach ($timeRangeOptions as $value => $label): ?>
                <option value="<?php echo htmlspecialchars($value); ?>"<?php echo $value == '6' ? ' selected' : ''; ?>>
                    <?php echo htmlspecialchars($label); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <!-- Port Detail Panel (shown on port click) -->
    <div id="portDetailPanel" class="portDetailPanel" style="display: none;">
        <div class="portDetailHeader">
            <h3><i class="fas fa-plug"></i> <span id="portDetailName">Port 1</span></h3>
            <button class="closeBtn" onclick="closePortDetail()"><i class="fas fa-times"></i></button>
        </div>
        <div class="portDetailContent">
            <div class="portDetailStats">
                <div class="detailStatItem">
                    <div class="detailStatLabel">Current</div>
                    <div class="detailStatValue" id="portDetailCurrent">-- mA</div>
                </div>
                <div class="detailStatItem">
                    <div class="detailStatLabel">Peak (24h)</div>
                    <div class="detailStatValue" id="portDetailPeak">-- mA</div>
                </div>
                <div class="detailStatItem">
                    <div class="detailStatLabel">Average</div>
                    <div class="detailStatValue" id="portDetailAvg">-- mA</div>
                </div>
                <div class="detailStatItem">
                    <div class="detailStatLabel">Expected <i class="fas fa-question-circle expectedHelp" onclick="showExpectedHelp(event)" title="How is this calculated?"></i></div>
                    <div class="detailStatValue" id="portDetailExpected">-- mA</div>
                </div>
            </div>
            <!-- Output Config with integrated Port Control -->
            <div class="portOutputConfig" id="portOutputConfig">
                <!-- Output config and control loaded dynamically -->
            </div>
            <div class="portDetailChart">
                <canvas id="portHistoryChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Total Current History Chart -->
    <div id="totalHistoryCard" class="efuseChartCard" style="display: none;">
        <div class="efuseChartTitle">
            <span><i class="fas fa-chart-area"></i> Total Current History</span>
        </div>
        <div class="efuseChartContainer">
            <canvas id="totalHistoryChart"></canvas>
        </div>
    </div>

    <!-- History Charts Container (one chart per 16 ports) -->
    <div id="efuseHistoryChartsContainer">
        <!-- Charts will be created dynamically by JavaScript -->
    </div>

    <?php endif; ?>

    <button class="refreshButton" onclick="loadAllData()" title="Refresh Data">
        <i class="fas fa-sync-alt"></i>
    </button>

    <!-- Expected Amperage Help Modal -->
    <div id="expectedHelpModal" class="helpModal" style="display: none;" onclick="hideExpectedHelp(event)">
        <div class="helpModalContent" onclick="event.stopPropagation()">
            <div class="helpModalHeader">
                <h4><i class="fas fa-question-circle"></i> Expected Current Calculation</h4>
                <button class="closeBtn" onclick="hideExpectedHelp()"><i class="fas fa-times"></i></button>
            </div>
            <div class="helpModalBody">
                <p>Expected current is estimated based on real-world measurements and pixel count:</p>

                <h5>Theoretical Maximum (per pixel)</h5>
                <table class="helpTable">
                    <thead>
                        <tr><th>Protocol</th><th>Voltage</th><th>Max Current</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>WS2811</td><td>12V</td><td>42mA (~0.5W)</td></tr>
                        <tr><td>WS2812 / WS2812B / SK6812</td><td>5V</td><td>60mA (~0.3W)</td></tr>
                        <tr><td>APA102 / APA104</td><td>5V</td><td>60mA</td></tr>
                        <tr><td>TM1814 / SK6812W (RGBW)</td><td>5V</td><td>80mA (~0.4W)</td></tr>
                    </tbody>
                </table>

                <h5>Typical Show Usage</h5>
                <p class="helpNote"><strong>Formula:</strong> Pixels × Per-Pixel Max × 6% = Expected Current</p>
                <p class="helpNote"><strong>Example:</strong> 500 WS2811 × 42mA × 6% = 1,260mA (1.26A)</p>

                <p>The 6% factor is based on real-world measurements. Testing with 496 WS2811 pixels at 12V shows:</p>
                <ul>
                    <li>At 30% brightness: 1.21A (~2.4mA per pixel)</li>
                    <li>At 90% brightness: 1.24A (~2.5mA per pixel)</li>
                    <li>Brightness changes have minimal impact on current draw</li>
                </ul>

                <p class="helpNote"><strong>Tip:</strong> If actual current significantly exceeds expected (>3x), check for shorts or misconfigured pixel counts. The "Max" value shows theoretical maximum if all pixels were full white.</p>
            </div>
        </div>
    </div>

    <!-- Page Help Modal -->
    <div id="pageHelpModal" class="helpModal" style="display: none;" onclick="hidePageHelp(event)">
        <div class="helpModalContent" onclick="event.stopPropagation()">
            <div class="helpModalHeader">
                <h4><i class="fas fa-question-circle"></i> About eFuse Current Monitoring</h4>
                <button class="closeBtn" onclick="hidePageHelp()"><i class="fas fa-times"></i></button>
            </div>
            <div class="helpModalBody">
                <p>This dashboard monitors real-time current draw from each eFuse port on your controller.</p>

                <h5>Data Collection</h5>
                <table class="helpTable">
                    <tbody>
                        <tr><td><strong>Sampling Rate</strong></td><td>Every <?php echo $collectionInterval; ?> second<?php echo $collectionInterval !== 1 ? 's' : ''; ?></td></tr>
                        <tr><td><strong>Display Refresh</strong></td><td>Every 10 seconds</td></tr>
                        <tr><td><strong>Aggregation</strong></td><td>1-minute to 2-hour averages (min/avg/max)</td></tr>
                    </tbody>
                </table>

                <h5>Data Retention</h5>
                <table class="helpTable">
                    <tbody>
                        <tr><td><strong>Raw Data</strong></td><td>6 hours (<?php echo $collectionInterval; ?>-second samples)</td></tr>
                        <tr><td><strong>Historical Data</strong></td><td><?php echo $retentionDays; ?> day<?php echo $retentionDays !== 1 ? 's' : ''; ?> (multi-tier rollups)</td></tr>
                    </tbody>
                </table>

                <h5>Estimated Storage (5-hour show, 5-10pm)</h5>
                <table class="helpTable">
                    <thead>
                        <tr><th>Ports</th><th>Raw Data</th><th>Daily Rollup</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>4 ports</td><td>~400 KB</td><td>~200 KB</td></tr>
                        <tr><td>8 ports</td><td>~600 KB</td><td>~350 KB</td></tr>
                        <tr><td>16 ports</td><td>~1 MB</td><td>~600 KB</td></tr>
                        <tr><td>32 ports</td><td>~2 MB</td><td>~1.1 MB</td></tr>
                    </tbody>
                </table>
                <p style="font-size: 0.85rem; color: #6c757d; margin-top: 0.5rem;">
                    Raw data auto-rotates after 6 hours; rollup data after 24 hours.
                </p>

                <h5>Dashboard Metrics</h5>
                <ul>
                    <li><strong>Total Current:</strong> Sum of all port current readings</li>
                    <li><strong>Active Ports:</strong> Ports with current draw above 0mA</li>
                    <li><strong>Peak (24h):</strong> Highest single port reading in the time range</li>
                    <li><strong>Average (24h):</strong> Mean current across all ports in the time range</li>
                </ul>

                <h5>Heatmap Colors</h5>
                <p>Port tiles change color based on current draw:</p>
                <ul>
                    <li><span style="color: #1e5128;">●</span> <strong>Green:</strong> Normal (0-2A)</li>
                    <li><span style="color: #ffc107;">●</span> <strong>Yellow:</strong> Elevated (2-3A)</li>
                    <li><span style="color: #fd7e14;">●</span> <strong>Orange:</strong> High (3-4A)</li>
                    <li><span style="color: #dc3545;">●</span> <strong>Red:</strong> Very High (4A+)</li>
                </ul>

                <p class="helpNote"><strong>Tip:</strong> Click any port tile to view detailed history and output configuration for that port.</p>
            </div>
        </div>
    </div>

    <!-- Confirmation Modal for destructive actions -->
    <div id="confirmModal" class="confirmModal" style="display: none;" onclick="hideConfirmModal(event)">
        <div class="confirmModalContent" onclick="event.stopPropagation()">
            <div class="confirmModalIcon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <h3 id="confirmModalTitle">Confirm Action</h3>
            <p id="confirmModalMessage">Are you sure you want to proceed?</p>
            <div class="confirmModalButtons">
                <button class="efuseControlBtn secondary" onclick="hideConfirmModal()">Cancel</button>
                <button class="efuseControlBtn danger" id="confirmModalAction" onclick="confirmModalCallback()">Confirm</button>
            </div>
        </div>
    </div>

    <!-- Toast notification container -->
    <div id="toastContainer" class="toastContainer"></div>
</div>

<script>
<?php if ($hardware['supported']): ?>
// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    initEfuseMonitor();
});
<?php endif; ?>
</script>
