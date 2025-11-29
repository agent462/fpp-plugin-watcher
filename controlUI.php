<?php
// Load configuration to check if control UI is enabled
include_once __DIR__ . '/lib/config.php';
include_once __DIR__ . '/lib/watcherCommon.php';
$config = readPluginConfig();

// Fetch local system status from FPP API
$localSystem = apiCall('GET', 'http://127.0.0.1/api/fppd/status', [], true, 5);
if ($localSystem === false) {
    $localSystem = [];
}

// Check conditions
$isEnabled = !empty($config['controlUIEnabled']);
$isPlayerMode = ($localSystem['mode_name'] ?? '') === 'player';
$showDashboard = $isEnabled && $isPlayerMode;

// Get remote systems if enabled
$remoteSystems = [];
if ($showDashboard) {
    $remoteSystems = getMultiSyncRemoteSystems();
}
?>

<link rel="stylesheet" href="/css/fpp-bootstrap/dist-new/fpp-bootstrap-5-3.css">
<link rel="stylesheet" href="/css/fpp.css">
<link rel="stylesheet" href="/plugin.php?plugin=fpp-plugin-watcher&file=css/commonUI.css&nopage=1">

<style>
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
    .controlCardsGrid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
        gap: 1.5rem;
        margin-bottom: 1.5rem;
    }
    .controlCard {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        overflow: hidden;
        transition: box-shadow 0.2s ease;
    }
    .controlCard:hover {
        box-shadow: 0 4px 16px rgba(0,0,0,0.15);
    }
    .controlCard.offline {
        opacity: 0.7;
    }
    .controlCard.offline .cardHeader {
        background: #6c757d;
    }
    .cardHeader {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: #fff;
        padding: 1rem 1.25rem;
    }
    .cardHeader .hostname {
        font-size: 1.15rem;
        font-weight: 600;
        margin-bottom: 0.25rem;
    }
    .cardHeader .address {
        font-size: 0.85rem;
        opacity: 0.85;
    }
    .cardBody {
        padding: 1.25rem;
    }
    .infoGrid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 0.75rem;
        margin-bottom: 1rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid #e9ecef;
    }
    .infoItem {
        display: flex;
        flex-direction: column;
    }
    .infoLabel {
        font-size: 0.7rem;
        color: #6c757d;
        text-transform: uppercase;
        font-weight: 500;
        margin-bottom: 0.15rem;
    }
    .infoValue {
        font-size: 0.9rem;
        font-weight: 500;
        color: #212529;
    }
    .statusIndicator {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.25rem 0.6rem;
        border-radius: 12px;
        font-size: 0.75rem;
        font-weight: 500;
    }
    .statusIndicator.online {
        background: rgba(40, 167, 69, 0.1);
        color: #28a745;
    }
    .statusIndicator.offline {
        background: rgba(220, 53, 69, 0.1);
        color: #dc3545;
    }
    .statusIndicator.testing {
        background: rgba(255, 193, 7, 0.15);
        color: #856404;
    }
    .statusIndicator .dot {
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: currentColor;
    }
    .controlActions {
        display: flex;
        flex-direction: column;
        gap: 1.25rem;
    }
    .actionRow {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
    }
    .actionLabel {
        font-size: 0.85rem;
        color: #495057;
        font-weight: 500;
    }
    .toggleSwitch {
        position: relative;
        display: inline-block;
        width: 48px;
        height: 26px;
    }
    .toggleSwitch input {
        opacity: 0;
        width: 0;
        height: 0;
    }
    .toggleSlider {
        position: absolute;
        cursor: pointer;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: #ccc;
        transition: 0.3s;
        border-radius: 26px;
    }
    .toggleSlider:before {
        position: absolute;
        content: "";
        height: 20px;
        width: 20px;
        left: 3px;
        bottom: 3px;
        background-color: white;
        transition: 0.3s;
        border-radius: 50%;
    }
    .toggleSwitch input:checked + .toggleSlider {
        background-color: #ffc107;
    }
    .toggleSwitch input:checked + .toggleSlider:before {
        transform: translateX(22px);
    }
    .toggleSwitch input:disabled + .toggleSlider {
        opacity: 0.5;
        cursor: not-allowed;
    }
    .actionButtons {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
        padding-top: 0.75rem;
        border-top: 1px solid #e9ecef;
    }
    .actionBtn {
        flex: 1;
        min-width: 100px;
        padding: 0.5rem 0.75rem;
        border: none;
        border-radius: 6px;
        font-size: 0.8rem;
        font-weight: 500;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.4rem;
        transition: all 0.2s ease;
    }
    .actionBtn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }
    .actionBtn.restart {
        background: #17a2b8;
        color: #fff;
    }
    .actionBtn.restart:hover:not(:disabled) {
        background: #138496;
    }
    .actionBtn.reboot {
        background: #dc3545;
        color: #fff;
    }
    .actionBtn.reboot:hover:not(:disabled) {
        background: #c82333;
    }
    .actionBtn.loading {
        pointer-events: none;
    }
    .actionBtn.loading i {
        animation: spin 1s linear infinite;
    }
    @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }
    .noRemotesMessage {
        text-align: center;
        padding: 3rem;
        background: #f8f9fa;
        border-radius: 8px;
        color: #6c757d;
    }
    .noRemotesMessage i {
        font-size: 3rem;
        margin-bottom: 1rem;
        opacity: 0.5;
    }
    .updateBanner {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 1rem 1.25rem;
        background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        border-radius: 8px;
        margin-bottom: 1.5rem;
        color: #fff;
        box-shadow: 0 2px 8px rgba(40, 167, 69, 0.3);
    }
    .updateBanner.checking {
        background: linear-gradient(135deg, #6c757d 0%, #adb5bd 100%);
    }
    .updateBanner.error {
        background: linear-gradient(135deg, #dc3545 0%, #e4606d 100%);
    }
    .updateBanner .updateInfo {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }
    .updateBanner .updateInfo i {
        font-size: 1.5rem;
    }
    .updateBanner .updateText h4 {
        margin: 0 0 0.25rem 0;
        font-size: 1rem;
        font-weight: 600;
    }
    .updateBanner .updateText p {
        margin: 0;
        font-size: 0.85rem;
        opacity: 0.9;
    }
    .updateBanner .updateBtn {
        padding: 0.5rem 1.25rem;
        background: rgba(255,255,255,0.2);
        border: 2px solid rgba(255,255,255,0.5);
        border-radius: 6px;
        color: #fff;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }
    .updateBanner .updateBtn:hover:not(:disabled) {
        background: rgba(255,255,255,0.3);
        border-color: rgba(255,255,255,0.8);
    }
    .updateBanner .updateBtn:disabled {
        opacity: 0.7;
        cursor: not-allowed;
    }
    .versionInfo {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 1rem;
        background: #f8f9fa;
        border-radius: 6px;
        margin-bottom: 1.5rem;
        font-size: 0.85rem;
        color: #6c757d;
    }
    .versionInfo .currentVersion {
        font-weight: 600;
        color: #495057;
    }
    .versionInfo .checkLink {
        margin-left: auto;
        color: #667eea;
        cursor: pointer;
        text-decoration: none;
    }
    .versionInfo .checkLink:hover {
        text-decoration: underline;
    }
    .refreshBar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.5rem;
        padding: 0.75rem 1rem;
        background: #f8f9fa;
        border-radius: 8px;
    }
    .refreshBar .lastUpdate {
        font-size: 0.85rem;
        color: #6c757d;
    }
    .confirmDialog {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 9999;
    }
    .confirmDialog .dialogContent {
        background: #fff;
        border-radius: 12px;
        padding: 1.5rem;
        max-width: 400px;
        width: 90%;
        box-shadow: 0 4px 24px rgba(0,0,0,0.2);
    }
    .confirmDialog h4 {
        margin: 0 0 0.75rem 0;
        color: #212529;
    }
    .confirmDialog p {
        margin: 0 0 1.25rem 0;
        color: #6c757d;
    }
    .confirmDialog .dialogButtons {
        display: flex;
        gap: 0.75rem;
        justify-content: flex-end;
    }
    .confirmDialog .btn {
        padding: 0.5rem 1rem;
        border-radius: 6px;
        font-size: 0.9rem;
        cursor: pointer;
        border: none;
    }
    .confirmDialog .btn-cancel {
        background: #e9ecef;
        color: #495057;
    }
    .confirmDialog .btn-confirm {
        background: #dc3545;
        color: #fff;
    }
</style>

<div class="metricsContainer">
    <h2 style="margin-bottom: 1.5rem; color: #212529;">
        <i class="fas fa-gamepad"></i> Remote System Control
    </h2>

    <!-- Update Banner (hidden by default, shown via JS when update available) -->
    <div id="updateBanner" class="updateBanner" style="display: none;">
        <div class="updateInfo">
            <i class="fas fa-arrow-circle-up"></i>
            <div class="updateText">
                <h4>Update Available</h4>
                <p>Version <span id="remoteVersionText">--</span> is available (you have <span id="localVersionText">--</span>)</p>
            </div>
        </div>
        <button class="updateBtn" onclick="upgradePlugin()" id="upgradeBtn">
            <i class="fas fa-download"></i> Upgrade Now
        </button>
    </div>

    <!-- Version Info Bar -->
    <div class="versionInfo">
        <i class="fas fa-info-circle"></i>
        <span>Watcher Plugin: <span class="currentVersion"><?php echo htmlspecialchars(WATCHERVERSION); ?></span></span>
        <a class="checkLink" onclick="checkForUpdates()"><i class="fas fa-sync-alt"></i> Check for updates</a>
    </div>

    <?php if (!$isEnabled): ?>
    <div class="disabledMessage">
        <h3><i class="fas fa-exclamation-circle"></i> Remote Control Disabled</h3>
        <p>This feature is not enabled. Go to <a href="plugin.php?plugin=fpp-plugin-watcher&page=configUI.php">Watcher Config</a> to enable it.</p>
    </div>
    <?php elseif (!$isPlayerMode): ?>
    <div class="disabledMessage">
        <h3><i class="fas fa-info-circle"></i> Player Mode Required</h3>
        <p>This feature is only available when FPP is in Player mode. Current mode: <?php echo htmlspecialchars($localSystem['mode_name'] ?? 'unknown'); ?></p>
    </div>
    <?php elseif (empty($remoteSystems)): ?>
    <div class="noRemotesMessage">
        <i class="fas fa-server"></i>
        <h3>No Remote Systems Found</h3>
        <p>No remote FPP systems were detected in your multi-sync configuration.<br>
        Make sure you have remote systems configured in FPP's MultiSync settings.</p>
    </div>
    <?php else: ?>

    <div class="refreshBar">
        <span class="lastUpdate">
            <i class="fas fa-clock"></i> Last updated: <span id="lastUpdateTime">--</span>
        </span>
        <button class="buttons btn-outline-primary" onclick="refreshAllStatus()" id="refreshBtn">
            <i class="fas fa-sync-alt"></i> Refresh All
        </button>
    </div>

    <div id="loadingIndicator" class="loadingSpinner">
        <i class="fas fa-spinner"></i>
        <p>Loading remote system status...</p>
    </div>

    <div id="controlContent" style="display: none;">
        <div class="controlCardsGrid" id="controlCardsGrid">
            <?php foreach ($remoteSystems as $system): ?>
            <div class="controlCard" id="card-<?php echo htmlspecialchars($system['address']); ?>" data-address="<?php echo htmlspecialchars($system['address']); ?>" data-hostname="<?php echo htmlspecialchars($system['hostname']); ?>">
                <div class="cardHeader">
                    <div class="hostname"><?php echo htmlspecialchars($system['hostname']); ?></div>
                    <div class="address"><a href="http://<?php echo htmlspecialchars($system['address']); ?>/" target="_blank" style="color: inherit; text-decoration: underline;"><?php echo htmlspecialchars($system['address']); ?></a></div>
                </div>
                <div class="cardBody">
                    <div class="infoGrid">
                        <div class="infoItem">
                            <span class="infoLabel">Status</span>
                            <span class="infoValue" id="status-<?php echo htmlspecialchars($system['address']); ?>">
                                <span class="statusIndicator offline"><span class="dot"></span> Loading...</span>
                            </span>
                        </div>
                        <div class="infoItem">
                            <span class="infoLabel">Platform</span>
                            <span class="infoValue" id="platform-<?php echo htmlspecialchars($system['address']); ?>">--</span>
                        </div>
                        <div class="infoItem">
                            <span class="infoLabel">Version</span>
                            <span class="infoValue" id="version-<?php echo htmlspecialchars($system['address']); ?>">--</span>
                        </div>
                        <div class="infoItem">
                            <span class="infoLabel">Mode</span>
                            <span class="infoValue" id="mode-<?php echo htmlspecialchars($system['address']); ?>">--</span>
                        </div>
                        <div class="infoItem">
                            <span class="infoLabel">Watcher</span>
                            <span class="infoValue" id="watcher-<?php echo htmlspecialchars($system['address']); ?>">--</span>
                        </div>
                    </div>
                    <div class="controlActions">
                        <div class="actionRow">
                            <span class="actionLabel"><i class="fas fa-vial"></i> Test Mode</span>
                            <label class="toggleSwitch">
                                <input type="checkbox" id="testmode-<?php echo htmlspecialchars($system['address']); ?>" onchange="toggleTestMode('<?php echo htmlspecialchars($system['address']); ?>', this.checked)" disabled>
                                <span class="toggleSlider"></span>
                            </label>
                        </div>
                        <div class="actionButtons">
                            <button class="actionBtn restart" onclick="restartFppd('<?php echo htmlspecialchars($system['address']); ?>')" id="restart-btn-<?php echo htmlspecialchars($system['address']); ?>" disabled>
                                <i class="fas fa-redo"></i> Restart FPPD
                            </button>
                            <button class="actionBtn reboot" onclick="confirmReboot('<?php echo htmlspecialchars($system['address']); ?>', '<?php echo htmlspecialchars($system['hostname']); ?>')" id="reboot-btn-<?php echo htmlspecialchars($system['address']); ?>" disabled>
                                <i class="fas fa-power-off"></i> Reboot
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div id="confirmDialog" class="confirmDialog" style="display: none;">
        <div class="dialogContent">
            <h4><i class="fas fa-exclamation-triangle" style="color: #dc3545;"></i> Confirm Reboot</h4>
            <p id="confirmMessage">Are you sure you want to reboot this system?</p>
            <div class="dialogButtons">
                <button class="btn btn-cancel" onclick="closeConfirmDialog()">Cancel</button>
                <button class="btn btn-confirm" id="confirmRebootBtn">Reboot</button>
            </div>
        </div>
    </div>

    <?php endif; ?>
</div>

<!-- Update check script (always included) -->
<script>
    // Check for plugin updates
    async function checkForUpdates() {
        const banner = document.getElementById('updateBanner');
        const checkLink = document.querySelector('.checkLink');
        const originalCheckText = checkLink.innerHTML;

        checkLink.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking...';

        try {
            const response = await fetch('/api/plugin/fpp-plugin-watcher/update/check');
            const data = await response.json();

            if (!data.success) {
                console.error('Update check failed:', data.error);
                checkLink.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Check failed';
                setTimeout(() => { checkLink.innerHTML = originalCheckText; }, 3000);
                return;
            }

            document.getElementById('localVersionText').textContent = data.localVersion;
            document.getElementById('remoteVersionText').textContent = data.remoteVersion;

            if (data.updateAvailable) {
                banner.classList.remove('checking', 'error');
                banner.style.display = 'flex';
                checkLink.innerHTML = '<i class="fas fa-check"></i> Update available!';
            } else {
                banner.style.display = 'none';
                checkLink.innerHTML = '<i class="fas fa-check"></i> Up to date!';
            }

            setTimeout(() => { checkLink.innerHTML = originalCheckText; }, 3000);

        } catch (error) {
            console.error('Error checking for updates:', error);
            checkLink.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Check failed';
            setTimeout(() => { checkLink.innerHTML = originalCheckText; }, 3000);
        }
    }

    // Upgrade the plugin
    async function upgradePlugin() {
        const btn = document.getElementById('upgradeBtn');
        const banner = document.getElementById('updateBanner');
        const originalBtnHtml = btn.innerHTML;

        if (!confirm('Are you sure you want to upgrade the Watcher plugin? The page will reload after the upgrade.')) {
            return;
        }

        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Upgrading...';
        btn.disabled = true;

        try {
            const response = await fetch('/api/plugin/fpp-plugin-watcher/update/upgrade', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                }
            });

            const data = await response.json();

            if (data.success) {
                btn.innerHTML = '<i class="fas fa-check"></i> Upgrade Complete!';
                banner.querySelector('.updateText h4').textContent = 'Upgrade Complete!';
                banner.querySelector('.updateText p').textContent = 'Reloading page...';

                // Reload the page after a short delay
                setTimeout(() => {
                    window.location.reload();
                }, 2000);
            } else {
                throw new Error(data.error || 'Upgrade failed');
            }

        } catch (error) {
            console.error('Error upgrading plugin:', error);
            btn.innerHTML = originalBtnHtml;
            btn.disabled = false;
            banner.classList.add('error');
            banner.querySelector('.updateText h4').textContent = 'Upgrade Failed';
            banner.querySelector('.updateText p').textContent = error.message;
        }
    }

    // Check for updates on page load (with a slight delay to not block rendering)
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(checkForUpdates, 1000);
    });
</script>

<?php if ($showDashboard && !empty($remoteSystems)): ?>
<script>
    const remoteAddresses = <?php echo json_encode(array_column($remoteSystems, 'address')); ?>;
    let isRefreshing = false;
    let pendingReboot = null;

    // Fetch status for a single remote via local proxy
    async function fetchRemoteStatus(address) {
        try {
            // Fetch both status and watcher version in parallel
            const [statusResponse, versionResponse] = await Promise.all([
                fetch(`/api/plugin/fpp-plugin-watcher/remote/status?host=${encodeURIComponent(address)}`),
                fetch(`http://${address}/api/plugin/fpp-plugin-watcher/version`).catch(() => null)
            ]);

            const data = await statusResponse.json();

            if (!data.success) {
                return {
                    success: false,
                    address: address,
                    error: data.error || 'Failed to fetch status'
                };
            }

            // Try to get watcher version
            let watcherVersion = null;
            if (versionResponse && versionResponse.ok) {
                try {
                    const versionData = await versionResponse.json();
                    watcherVersion = versionData.version || null;
                } catch (e) {
                    // Ignore version fetch errors
                }
            }

            return {
                success: true,
                address: address,
                status: data.status,
                testMode: data.testMode,
                watcherVersion: watcherVersion
            };
        } catch (error) {
            console.error(`Error fetching status for ${address}:`, error);
            return {
                success: false,
                address: address,
                error: error.message
            };
        }
    }

    // Update card UI with status data
    function updateCardUI(address, data) {
        const card = document.getElementById(`card-${address}`);
        const statusEl = document.getElementById(`status-${address}`);
        const platformEl = document.getElementById(`platform-${address}`);
        const versionEl = document.getElementById(`version-${address}`);
        const modeEl = document.getElementById(`mode-${address}`);
        const watcherEl = document.getElementById(`watcher-${address}`);
        const testModeToggle = document.getElementById(`testmode-${address}`);
        const restartBtn = document.getElementById(`restart-btn-${address}`);
        const rebootBtn = document.getElementById(`reboot-btn-${address}`);

        if (!data.success) {
            card.classList.add('offline');
            statusEl.innerHTML = '<span class="statusIndicator offline"><span class="dot"></span> Offline</span>';
            platformEl.textContent = '--';
            versionEl.textContent = '--';
            modeEl.textContent = '--';
            watcherEl.textContent = '--';
            testModeToggle.disabled = true;
            testModeToggle.checked = false;
            restartBtn.disabled = true;
            rebootBtn.disabled = true;
            return;
        }

        card.classList.remove('offline');
        const status = data.status;
        const testMode = data.testMode;

        // Update status indicator
        const isTestMode = testMode.enabled === 1;
        if (isTestMode) {
            statusEl.innerHTML = '<span class="statusIndicator testing"><span class="dot"></span> Test Mode</span>';
        } else {
            statusEl.innerHTML = '<span class="statusIndicator online"><span class="dot"></span> Online</span>';
        }

        // Update info
        platformEl.textContent = status.platform || '--';
        versionEl.textContent = status.branch || '--';
        modeEl.textContent = status.mode_name || '--';
        watcherEl.textContent = data.watcherVersion || 'Not installed';

        // Update test mode toggle
        testModeToggle.disabled = false;
        testModeToggle.checked = isTestMode;

        // Enable action buttons
        restartBtn.disabled = false;
        rebootBtn.disabled = false;
    }

    // Refresh status for all remotes
    async function refreshAllStatus() {
        if (isRefreshing) return;
        isRefreshing = true;

        const refreshBtn = document.getElementById('refreshBtn');
        refreshBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Refreshing...';
        refreshBtn.disabled = true;

        try {
            // Fetch all statuses in parallel
            const promises = remoteAddresses.map(addr => fetchRemoteStatus(addr));
            const results = await Promise.all(promises);

            // Update UI for each result
            results.forEach(result => {
                updateCardUI(result.address, result);
            });

            // Update last update time
            document.getElementById('lastUpdateTime').textContent = new Date().toLocaleTimeString();

            // Hide loading, show content
            document.getElementById('loadingIndicator').style.display = 'none';
            document.getElementById('controlContent').style.display = 'block';

        } catch (error) {
            console.error('Error refreshing status:', error);
        } finally {
            refreshBtn.innerHTML = '<i class="fas fa-sync-alt"></i> Refresh All';
            refreshBtn.disabled = false;
            isRefreshing = false;
        }
    }

    // Toggle test mode on a remote via local proxy
    async function toggleTestMode(address, enable) {
        const toggle = document.getElementById(`testmode-${address}`);
        toggle.disabled = true;

        try {
            let requestData;
            if (enable) {
                // Start test mode with RGB Cycle pattern
                requestData = {
                    host: address,
                    command: "Test Start",
                    multisyncCommand: false,
                    multisyncHosts: "",
                    args: ["1000", "RGB Cycle", "1-8", "R-G-B"]
                };
            } else {
                // Stop test mode
                requestData = {
                    host: address,
                    command: "Test Stop",
                    multisyncCommand: false,
                    multisyncHosts: "",
                    args: []
                };
            }

            const response = await fetch('/api/plugin/fpp-plugin-watcher/remote/command', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(requestData)
            });

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error || 'Failed to toggle test mode');
            }

            // Refresh this card's status after a short delay
            setTimeout(async () => {
                const result = await fetchRemoteStatus(address);
                updateCardUI(address, result);
            }, 500);

        } catch (error) {
            console.error(`Error toggling test mode for ${address}:`, error);
            // Revert toggle state
            toggle.checked = !enable;
            alert(`Failed to toggle test mode: ${error.message}`);
        } finally {
            toggle.disabled = false;
        }
    }

    // Restart FPPD on a remote via local proxy
    async function restartFppd(address) {
        const btn = document.getElementById(`restart-btn-${address}`);
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Restarting...';
        btn.disabled = true;

        try {
            const response = await fetch('/api/plugin/fpp-plugin-watcher/remote/restart', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ host: address })
            });

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error || 'Failed to restart FPPD');
            }

            // Show success briefly
            btn.innerHTML = '<i class="fas fa-check"></i> Restarted!';

            // Refresh status after delay to allow restart
            setTimeout(async () => {
                btn.innerHTML = originalHtml;
                btn.disabled = false;
                const result = await fetchRemoteStatus(address);
                updateCardUI(address, result);
            }, 3000);

        } catch (error) {
            console.error(`Error restarting FPPD for ${address}:`, error);
            btn.innerHTML = originalHtml;
            btn.disabled = false;
            alert(`Failed to restart FPPD: ${error.message}`);
        }
    }

    // Show confirm dialog for reboot
    function confirmReboot(address, hostname) {
        pendingReboot = { address, hostname };
        document.getElementById('confirmMessage').textContent =
            `Are you sure you want to reboot "${hostname}" (${address})? This will take the system offline temporarily.`;
        document.getElementById('confirmDialog').style.display = 'flex';
    }

    // Close confirm dialog
    function closeConfirmDialog() {
        document.getElementById('confirmDialog').style.display = 'none';
        pendingReboot = null;
    }

    // Execute reboot via local proxy
    async function executeReboot() {
        if (!pendingReboot) return;

        const { address, hostname } = pendingReboot;
        closeConfirmDialog();

        const btn = document.getElementById(`reboot-btn-${address}`);
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Rebooting...';
        btn.disabled = true;

        try {
            const response = await fetch('/api/plugin/fpp-plugin-watcher/remote/reboot', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ host: address })
            });

            // Show rebooting status (don't wait for response, system is rebooting)
            const card = document.getElementById(`card-${address}`);
            card.classList.add('offline');
            document.getElementById(`status-${address}`).innerHTML =
                '<span class="statusIndicator offline"><span class="dot"></span> Rebooting...</span>';

            // Keep checking until it comes back online
            let attempts = 0;
            const checkInterval = setInterval(async () => {
                attempts++;
                if (attempts > 60) { // 2 minutes timeout
                    clearInterval(checkInterval);
                    btn.innerHTML = originalHtml;
                    btn.disabled = false;
                    return;
                }

                const result = await fetchRemoteStatus(address);
                if (result.success) {
                    clearInterval(checkInterval);
                    updateCardUI(address, result);
                    btn.innerHTML = originalHtml;
                    btn.disabled = false;
                }
            }, 2000);

        } catch (error) {
            console.error(`Error rebooting ${address}:`, error);
            // The system is likely rebooting, show as offline
            const card = document.getElementById(`card-${address}`);
            card.classList.add('offline');
            document.getElementById(`status-${address}`).innerHTML =
                '<span class="statusIndicator offline"><span class="dot"></span> Rebooting...</span>';
            btn.innerHTML = originalHtml;
            btn.disabled = false;
        }
    }

    // Set up confirm button handler
    document.getElementById('confirmRebootBtn').addEventListener('click', executeReboot);

    // Close dialog on escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeConfirmDialog();
        }
    });

    // Auto-refresh every 30 seconds
    setInterval(() => {
        if (!isRefreshing) {
            refreshAllStatus();
        }
    }, 30000);

    // Load data on page load
    document.addEventListener('DOMContentLoaded', function() {
        refreshAllStatus();
    });
</script>
<?php endif; ?>
