<?php
include_once __DIR__ . '/lib/config.php';
include_once __DIR__ . '/lib/watcherCommon.php';
include_once __DIR__ . '/lib/uiCommon.php';

$config = readPluginConfig();
$localSystem = apiCall('GET', 'http://127.0.0.1/api/fppd/status', [], true, 5) ?: [];
$access = checkDashboardAccess($config, $localSystem, 'controlUIEnabled');
$remoteSystems = $access['show'] ? getMultiSyncRemoteSystems() : [];

renderCSSIncludes(false);
?>
<style>
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
    .controlCard:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.15); }
    .controlCard.offline { opacity: 0.7; }
    .controlCard.offline .cardHeader { background: #6c757d; }
    .cardHeader {
        color: #fff;
        padding: 0.85rem 1.25rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .cardHeader .hostname { font-size: 1.1rem; font-weight: 600; }
    .cardHeader .address { font-size: 0.8rem; opacity: 0.9; }
    .cardHeader .address a { color: inherit; text-decoration: none; }
    .cardHeader .address a:hover { text-decoration: underline; }
    /* Host color palette */
    .cardHeader--color-0 { background: #667eea; }
    .cardHeader--color-1 { background: #e84393; }
    .cardHeader--color-2 { background: #00b894; }
    .cardHeader--color-3 { background: #e17055; }
    .cardHeader--color-4 { background: #0984e3; }
    .cardHeader--color-5 { background: #6c5ce7; }
    .cardBody { padding: 1.25rem; }
    .infoGrid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 0.75rem;
        margin-bottom: 1rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid #e9ecef;
    }
    .infoItem { display: flex; flex-direction: column; }
    .infoLabel { font-size: 0.7rem; color: #6c757d; text-transform: uppercase; font-weight: 500; margin-bottom: 0.15rem; }
    .infoValue { font-size: 0.9rem; font-weight: 500; color: #212529; }
    .version-update { font-size: 0.75rem; color: #007bff; font-weight: normal; }
    .controlActions { display: flex; flex-direction: column; gap: 1.25rem; }
    .actionRow { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; }
    .actionLabel { font-size: 0.85rem; color: #495057; font-weight: 500; }
    .toggleSwitch { position: relative; display: inline-block; width: 48px; height: 26px; }
    .toggleSwitch input { opacity: 0; width: 0; height: 0; }
    .toggleSlider {
        position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0;
        background-color: #ccc; transition: 0.3s; border-radius: 26px;
    }
    .toggleSlider:before {
        position: absolute; content: ""; height: 20px; width: 20px; left: 3px; bottom: 3px;
        background-color: white; transition: 0.3s; border-radius: 50%;
    }
    .toggleSwitch input:checked + .toggleSlider { background-color: #ffc107; }
    .toggleSwitch input:checked + .toggleSlider:before { transform: translateX(22px); }
    .toggleSwitch input:disabled + .toggleSlider { opacity: 0.5; cursor: not-allowed; }
    .actionButtons { display: flex; gap: 0.5rem; flex-wrap: wrap; padding-top: 0.75rem; margin-top: 1rem; border-top: 1px solid #e9ecef; }
    .actionBtn {
        flex: 1; min-width: 100px; padding: 0.5rem 0.75rem; border: none; border-radius: 6px;
        font-size: 0.8rem; font-weight: 500; cursor: pointer; display: inline-flex;
        align-items: center; justify-content: center; gap: 0.4rem; transition: all 0.2s ease;
    }
    .actionBtn:disabled { opacity: 0.5; cursor: not-allowed; }
    .actionBtn.restart { background: #17a2b8; color: #fff; }
    .actionBtn.restart:hover:not(:disabled) { background: #138496; }
    .actionBtn.reboot { background: #dc3545; color: #fff; }
    .actionBtn.reboot:hover:not(:disabled) { background: #c82333; }
    .actionBtn.loading { pointer-events: none; }
    .actionBtn.loading i { animation: spin 1s linear infinite; }
    @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
    /* Connectivity alert */
    .connectivity-alert {
        display: none;
        background: #fff3cd;
        border: 1px solid #ffc107;
        border-radius: 8px;
        padding: 0.75rem;
        margin-bottom: 1rem;
    }
    .connectivity-alert.visible { display: block; }
    .connectivity-alert-content {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }
    .connectivity-alert-icon {
        color: #856404;
        font-size: 1.25rem;
        flex-shrink: 0;
    }
    .connectivity-alert-text {
        flex: 1;
        font-size: 0.85rem;
        color: #856404;
    }
    .connectivity-alert-text strong { display: block; margin-bottom: 0.15rem; }
    .connectivity-alert-details { font-size: 0.75rem; opacity: 0.85; }
    .connectivity-alert-btn {
        background: #ffc107;
        color: #212529;
        border: none;
        border-radius: 4px;
        padding: 0.4rem 0.75rem;
        font-size: 0.75rem;
        font-weight: 500;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        white-space: nowrap;
        transition: background 0.2s;
    }
    .connectivity-alert-btn:hover { background: #e0a800; }
    .connectivity-alert-btn:disabled { opacity: 0.6; cursor: not-allowed; }
    .bulk-action-btn--connectivity { background: #ffc107; color: #212529; }
    .bulk-action-btn--connectivity:hover { background: #e0a800; }
    .status-indicator--connectivity { background: #fff3cd; color: #856404; }
</style>

<div class="metricsContainer">
    <h2 style="margin-bottom: 1.5rem; color: #212529;">
        <i class="fas fa-gamepad"></i> Remote System Control
    </h2>

    <?php if (!renderAccessError($access)): ?>
    <?php if (empty($remoteSystems)): ?>
    <div class="empty-message">
        <i class="fas fa-server"></i>
        <h3>No Remote Systems Found</h3>
        <p>No remote FPP systems were detected in your multi-sync configuration.<br>
        Make sure you have remote systems configured in FPP's MultiSync settings.</p>
    </div>
    <?php else: ?>

    <div class="refresh-bar">
        <span class="last-update">
            <i class="fas fa-clock"></i> Last updated: <span id="lastUpdateTime">--</span>
        </span>
        <div>
            <button class="bulk-action-btn bulk-action-btn--connectivity" id="connectivityFailBtn" onclick="showBulkModal('connectivity')">
                <i class="fas fa-network-wired"></i> Connectivity Failed <span class="badge" id="connectivityFailCount">0</span>
            </button>
            <button class="bulk-action-btn bulk-action-btn--fpp" id="fppUpgradeAllBtn" onclick="showBulkModal('fpp')">
                <i class="fas fa-code-branch"></i> Upgrade FPP <span class="badge" id="fppUpgradeAllCount">0</span>
            </button>
            <button class="bulk-action-btn bulk-action-btn--upgrade" id="upgradeAllBtn" onclick="showBulkModal('upgrade')">
                <i class="fas fa-arrow-circle-up"></i> Upgrade All Watcher <span class="badge" id="upgradeAllCount">0</span>
            </button>
            <button class="bulk-action-btn bulk-action-btn--restart" id="restartAllBtn" onclick="showBulkModal('restart')">
                <i class="fas fa-sync"></i> Restart Required <span class="badge" id="restartAllCount">0</span>
            </button>
            <button class="buttons btn-outline-primary" onclick="refreshAllStatus()" id="refreshBtn">
                <i class="fas fa-sync-alt"></i> Refresh All
            </button>
        </div>
    </div>

    <!-- Sync Status Grid - shown when sequence is playing -->
    <div class="sync-status-container" id="syncStatusContainer">
        <div class="sync-status-header">
            <h3><i class="fas fa-sync-alt"></i> Playback Sync Status <span class="sync-sequence-name" id="syncSequenceName"></span></h3>
        </div>
        <div class="sync-status-grid" id="syncStatusGrid"></div>
    </div>

    <div id="loadingIndicator" class="loadingSpinner">
        <i class="fas fa-spinner"></i>
        <p>Loading remote system status...</p>
    </div>

    <div id="controlContent" style="display: none;">
        <div class="controlCardsGrid" id="controlCardsGrid">
            <?php foreach ($remoteSystems as $index => $system): ?>
            <div class="controlCard" id="card-<?php echo htmlspecialchars($system['address']); ?>" data-address="<?php echo htmlspecialchars($system['address']); ?>" data-hostname="<?php echo htmlspecialchars($system['hostname']); ?>">
                <div class="cardHeader cardHeader--color-<?php echo $index % 6; ?>">
                    <div class="hostname"><?php echo htmlspecialchars($system['hostname']); ?></div>
                    <div class="address"><a href="http://<?php echo htmlspecialchars($system['address']); ?>/" target="_blank"><?php echo htmlspecialchars($system['address']); ?></a></div>
                </div>
                <div class="cardBody">
                    <div class="infoGrid">
                        <div class="infoItem">
                            <span class="infoLabel">Status</span>
                            <span class="infoValue" id="status-<?php echo htmlspecialchars($system['address']); ?>">
                                <span class="status-indicator status-indicator--offline"><span class="dot"></span> Loading...</span>
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
                    <div class="connectivity-alert" id="connectivity-alert-<?php echo htmlspecialchars($system['address']); ?>">
                        <div class="connectivity-alert-content">
                            <div class="connectivity-alert-icon"><i class="fas fa-exclamation-triangle"></i></div>
                            <div class="connectivity-alert-text">
                                <strong>Connectivity Check Failed</strong>
                                <span class="connectivity-alert-details" id="connectivity-details-<?php echo htmlspecialchars($system['address']); ?>"></span>
                            </div>
                            <button class="connectivity-alert-btn" onclick="clearRemoteResetState('<?php echo htmlspecialchars($system['address']); ?>')" id="connectivity-clear-btn-<?php echo htmlspecialchars($system['address']); ?>">
                                <i class="fas fa-redo"></i> Clear
                            </button>
                        </div>
                    </div>
                    <div class="actionRow">
                        <span class="actionLabel"><i class="fas fa-vial"></i> Test Mode (Local Channels)</span>
                        <label class="toggleSwitch">
                            <input type="checkbox" id="testmode-<?php echo htmlspecialchars($system['address']); ?>" onchange="toggleTestMode('<?php echo htmlspecialchars($system['address']); ?>', this.checked)" disabled>
                            <span class="toggleSlider"></span>
                        </label>
                    </div>
                    <div class="updates-container" id="updates-container-<?php echo htmlspecialchars($system['address']); ?>">
                        <div class="updates-header">
                            <i class="fas fa-arrow-circle-up"></i> Updates Available
                        </div>
                        <div class="update-row update-row--fpp" id="fpp-update-row-<?php echo htmlspecialchars($system['address']); ?>">
                            <div class="update-info">
                                <span class="update-name"><i class="fas fa-code-branch"></i> FPP</span>
                                <span class="update-version" id="fpp-update-version-<?php echo htmlspecialchars($system['address']); ?>"></span>
                            </div>
                            <button class="banner-btn" onclick="upgradeFPPSingle('<?php echo htmlspecialchars($system['address']); ?>')" id="fpp-upgrade-btn-<?php echo htmlspecialchars($system['address']); ?>">
                                <i class="fas fa-download"></i> Upgrade
                            </button>
                        </div>
                        <div id="upgrades-list-<?php echo htmlspecialchars($system['address']); ?>"></div>
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
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Single Reusable Bulk Modal -->
    <div id="bulkModal" class="watcher-modal watcher-modal--dark" style="display: none;">
        <div class="modal-content modal-content--md">
            <h4 id="bulkModalTitle"><i class="fas fa-arrow-circle-up"></i> <span></span></h4>
            <div class="progress-info" id="bulkModalProgress">Preparing...</div>
            <div class="host-list" id="bulkModalHostList"></div>
            <div class="modal-buttons">
                <button class="btn btn-muted" id="bulkModalCloseBtn" onclick="closeBulkModal()" disabled>Close</button>
            </div>
        </div>
    </div>

    <!-- Confirm Dialog -->
    <div id="confirmDialog" class="watcher-modal" style="display: none;">
        <div class="modal-content modal-content--sm">
            <h4><i class="fas fa-exclamation-triangle" style="color: #dc3545;"></i> Confirm Reboot</h4>
            <p id="confirmMessage">Are you sure you want to reboot this system?</p>
            <div class="modal-buttons">
                <button class="btn btn-secondary" onclick="closeConfirmDialog()">Cancel</button>
                <button class="btn btn-danger" id="confirmRebootBtn">Reboot</button>
            </div>
        </div>
    </div>

    <!-- FPP Upgrade Modal (with streaming output) -->
    <div id="fppUpgradeModal" class="watcher-modal watcher-modal--dark" style="display: none;">
        <div class="modal-content modal-content--lg">
            <h4><i class="fas fa-code-branch" style="color: #007bff;"></i> Upgrade FPP</h4>
            <div class="host-selector">
                <label for="fppUpgradeHostSelect">Select system to upgrade:</label>
                <select id="fppUpgradeHostSelect"></select>
            </div>
            <div class="stream-output" id="fppUpgradeOutput">Select a system and click "Start Upgrade" to begin...</div>
            <div class="modal-buttons modal-buttons--center">
                <button class="btn btn--fixed btn-primary" id="fppUpgradeStartBtn" onclick="startFPPUpgrade()">
                    <i class="fas fa-play"></i> Start Upgrade
                </button>
                <button class="btn btn--fixed btn-muted" id="fppUpgradeCloseBtn" onclick="closeFPPUpgradeModal()">Close</button>
            </div>
        </div>
    </div>

    <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($access['show'] && !empty($remoteSystems)): ?>
<script>
const remoteAddresses = <?php echo json_encode(array_column($remoteSystems, 'address')); ?>;
const remoteHostnames = <?php echo json_encode(array_combine(array_column($remoteSystems, 'address'), array_column($remoteSystems, 'hostname'))); ?>;

// State
let isRefreshing = false;
let pendingReboot = null;
let hostsWithWatcherUpdates = new Map();
let hostsNeedingRestart = new Map();
let hostsWithFPPUpdates = new Map();
let hostsWithConnectivityFailure = new Map();
let currentBulkType = null;
let fppUpgradeAbortController = null;
let syncCheckInterval = null;
const SYNC_THRESHOLD_SECONDS = 3; // hosts are out of sync if time differs by more than this

// =============================================================================
// Helper Functions
// =============================================================================

function escapeId(address) {
    return address.replace(/\./g, '-');
}

function updateBulkButton(buttonId, countId, map, minCount = 1) {
    const btn = document.getElementById(buttonId);
    const badge = document.getElementById(countId);
    const count = map.size;
    btn.classList.toggle('visible', count >= minCount);
    badge.textContent = count;
}

function buildHostListHtml(hostsMap, idPrefix, extraInfoFn = null) {
    let html = '';
    hostsMap.forEach((info, address) => {
        const extra = extraInfoFn ? extraInfoFn(info) : '';
        html += `
            <div class="host-item" id="${idPrefix}-${escapeId(address)}">
                <div class="host-name">${info.hostname} (${address})${extra}</div>
                <div class="host-status pending" id="${idPrefix}-status-${escapeId(address)}">
                    <i class="fas fa-clock"></i> Pending
                </div>
            </div>`;
    });
    return html;
}

async function processBulkOperation(hostsArray, operationFn, idPrefix, progressEl, parallel = false) {
    let completed = 0, failed = 0;
    const total = hostsArray.length;

    if (parallel) {
        progressEl.textContent = `Processing ${total} hosts in parallel...`;

        // Mark all as in-progress
        hostsArray.forEach(item => {
            const address = Array.isArray(item) ? item[0] : item;
            const statusEl = document.getElementById(`${idPrefix}-status-${escapeId(address)}`);
            statusEl.className = 'host-status in-progress';
            statusEl.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
        });

        // Run all operations in parallel
        const results = await Promise.allSettled(hostsArray.map(async (item) => {
            const address = Array.isArray(item) ? item[0] : item;
            const statusEl = document.getElementById(`${idPrefix}-status-${escapeId(address)}`);
            const updateStatus = (icon, text, className = 'in-progress') => {
                statusEl.className = `host-status ${className}`;
                statusEl.innerHTML = `<i class="fas fa-${icon}"></i> ${text}`;
            };
            try {
                await operationFn(item, updateStatus);
                statusEl.className = 'host-status success';
                statusEl.innerHTML = '<i class="fas fa-check"></i> Done';
                return { success: true };
            } catch (error) {
                console.error(`Error processing ${address}:`, error);
                statusEl.className = 'host-status error';
                statusEl.innerHTML = '<i class="fas fa-times"></i> Failed';
                return { success: false };
            }
        }));

        results.forEach(r => r.value?.success ? completed++ : failed++);
    } else {
        progressEl.textContent = `Processing 0 of ${total} hosts...`;

        for (const item of hostsArray) {
            const address = Array.isArray(item) ? item[0] : item;
            const statusEl = document.getElementById(`${idPrefix}-status-${escapeId(address)}`);
            statusEl.className = 'host-status in-progress';
            statusEl.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            const updateStatus = (icon, text, className = 'in-progress') => {
                statusEl.className = `host-status ${className}`;
                statusEl.innerHTML = `<i class="fas fa-${icon}"></i> ${text}`;
            };

            try {
                await operationFn(item, updateStatus);
                statusEl.className = 'host-status success';
                statusEl.innerHTML = '<i class="fas fa-check"></i> Done';
                completed++;
            } catch (error) {
                console.error(`Error processing ${address}:`, error);
                statusEl.className = 'host-status error';
                statusEl.innerHTML = '<i class="fas fa-times"></i> Failed';
                failed++;
            }
            progressEl.textContent = `Processed ${completed + failed} of ${total} hosts...`;
        }
    }

    return { completed, failed, total };
}

// =============================================================================
// Sync Status Grid (uses server-side batch fetch to avoid browser connection limits)
// =============================================================================

async function updateSyncStatus() {
    const container = document.getElementById('syncStatusContainer');
    const grid = document.getElementById('syncStatusGrid');
    const seqName = document.getElementById('syncSequenceName');

    // Single server-side batch request (uses curl_multi for parallel fetches with timeouts)
    let data;
    try {
        const response = await fetch('/api/plugin/fpp-plugin-watcher/remote/playback/sync', { signal: AbortSignal.timeout(5000) });
        if (!response.ok) { container.classList.remove('visible'); return; }
        data = await response.json();
    } catch {
        container.classList.remove('visible');
        return;
    }

    // If local is not playing, hide sync grid
    if (!data.local || data.local.status !== 'playing') {
        container.classList.remove('visible');
        stopSyncChecking();
        return;
    }

    const localStatus = { address: 'local', ...data.local };
    const allStatuses = [localStatus, ...data.remotes];
    const refTime = localStatus.secondsPlayed;
    const refSequence = localStatus.sequence;

    // Build grid HTML
    let html = '';
    allStatuses.forEach(host => {
        let boxClass = '';
        let diffHtml = '';

        if (host.status === 'offline') {
            boxClass = 'offline';
        } else if (host.status !== 'playing') {
            boxClass = 'idle';
        } else if (host.sequence !== refSequence) {
            boxClass = 'out-of-sync';
            diffHtml = `<div class="sync-host-box__diff">Different sequence</div>`;
        } else {
            const timeDiff = Math.abs(host.secondsPlayed - refTime);
            if (timeDiff > SYNC_THRESHOLD_SECONDS) {
                boxClass = 'out-of-sync';
                const sign = host.secondsPlayed > refTime ? '+' : '-';
                diffHtml = `<div class="sync-host-box__diff">${sign}${timeDiff.toFixed(1)}s</div>`;
            }
        }

        const seqDisplay = host.sequence ? host.sequence.replace(/\.(fseq|eseq)$/i, '') : '--';
        const timeDisplay = host.status === 'offline' ? 'Offline' : (host.status !== 'playing' ? 'Idle' : host.timeElapsed);
        const modeClass = host.mode === 'player' ? 'player' : 'remote';
        const modeLabel = host.mode === 'player' ? 'P' : 'R';

        html += `
            <div class="sync-host-box ${boxClass}">
                <div class="sync-host-box__mode sync-host-box__mode--${modeClass}" title="${host.mode}">${modeLabel}</div>
                <div class="sync-host-box__name">${host.hostname}</div>
                <div class="sync-host-box__sequence" title="${seqDisplay}">${seqDisplay}</div>
                <div class="sync-host-box__time">${timeDisplay}</div>
                ${diffHtml}
            </div>`;
    });

    grid.innerHTML = html;
    seqName.textContent = `- ${refSequence.replace(/\.(fseq|eseq)$/i, '')}`;
    container.classList.add('visible');

    // Start rapid polling if not already running
    startSyncChecking();
}

function startSyncChecking() {
    if (syncCheckInterval) return;
    syncCheckInterval = setInterval(updateSyncStatus, 3000);
}

function stopSyncChecking() {
    if (syncCheckInterval) {
        clearInterval(syncCheckInterval);
        syncCheckInterval = null;
    }
}

// =============================================================================
// Status Fetching
// =============================================================================

async function fetchRemoteStatus(address) {
    try {
        const [statusResponse, versionResponse, updatesResponse, sysStatusResponse, connectivityResponse] = await Promise.all([
            fetch(`/api/plugin/fpp-plugin-watcher/remote/status?host=${encodeURIComponent(address)}`),
            fetch(`http://${address}/api/plugin/fpp-plugin-watcher/version`).catch(() => null),
            fetch(`/api/plugin/fpp-plugin-watcher/remote/plugins/updates?host=${encodeURIComponent(address)}`).catch(() => null),
            fetch(`http://${address}/api/system/status`).catch(() => null),
            fetch(`/api/plugin/fpp-plugin-watcher/remote/connectivity/state?host=${encodeURIComponent(address)}`).catch(() => null)
        ]);

        const data = await statusResponse.json();
        if (!data.success) {
            return { success: false, address, error: data.error || 'Failed to fetch status' };
        }

        let watcherVersion = null;
        if (versionResponse?.ok) {
            try { watcherVersion = (await versionResponse.json()).version || null; } catch (e) {}
        }

        let pluginUpdates = [];
        if (updatesResponse?.ok) {
            try {
                const updatesData = await updatesResponse.json();
                if (updatesData.success && updatesData.updatesAvailable) {
                    pluginUpdates = updatesData.updatesAvailable;
                }
            } catch (e) {}
        }

        let fppLocalVersion = null, fppRemoteVersion = null;
        if (sysStatusResponse?.ok) {
            try {
                const sysStatus = await sysStatusResponse.json();
                fppLocalVersion = sysStatus.advancedView?.LocalGitVersion || null;
                fppRemoteVersion = sysStatus.advancedView?.RemoteGitVersion || null;
            } catch (e) {}
        }

        let connectivityState = null;
        if (connectivityResponse?.ok) {
            try {
                const connData = await connectivityResponse.json();
                if (connData.success && connData.hasResetAdapter) {
                    connectivityState = connData;
                }
            } catch (e) {}
        }

        return { success: true, address, status: data.status, testMode: data.testMode, watcherVersion, pluginUpdates, fppLocalVersion, fppRemoteVersion, connectivityState };
    } catch (error) {
        return { success: false, address, error: error.message };
    }
}

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
    const updatesContainer = document.getElementById(`updates-container-${address}`);
    const upgradesList = document.getElementById(`upgrades-list-${address}`);
    const fppUpdateRow = document.getElementById(`fpp-update-row-${address}`);
    const fppUpdateVersion = document.getElementById(`fpp-update-version-${address}`);

    if (!data.success) {
        card.classList.add('offline');
        statusEl.innerHTML = '<div class="status-indicators"><span class="status-indicator status-indicator--offline"><span class="dot"></span>Offline</span></div>';
        platformEl.textContent = '--';
        versionEl.textContent = '--';
        modeEl.textContent = '--';
        watcherEl.textContent = '--';
        testModeToggle.disabled = true;
        testModeToggle.checked = false;
        restartBtn.disabled = true;
        rebootBtn.disabled = true;
        updatesContainer.classList.remove('visible');
        fppUpdateRow.classList.remove('visible');
        document.getElementById(`connectivity-alert-${address}`)?.classList.remove('visible');
        hostsWithConnectivityFailure.delete(address);
        updateBulkButton('connectivityFailBtn', 'connectivityFailCount', hostsWithConnectivityFailure);
        return;
    }

    card.classList.remove('offline');
    const { status, testMode, pluginUpdates = [], fppLocalVersion, fppRemoteVersion, connectivityState } = data;
    const isTestMode = testMode.enabled === 1;
    const needsReboot = status.rebootFlag === 1;
    const needsRestart = status.restartFlag === 1;
    const fppUpdateAvailable = fppLocalVersion && fppRemoteVersion && fppRemoteVersion !== 'Unknown' && fppRemoteVersion !== '' && fppLocalVersion !== fppRemoteVersion;
    const hasConnectivityFailure = connectivityState && connectivityState.hasResetAdapter;

    // Build status indicators
    let indicators = ['<span class="status-indicator status-indicator--online"><span class="dot"></span>Online</span>'];
    if (hasConnectivityFailure) indicators.push('<span class="status-indicator status-indicator--connectivity"><span class="dot"></span>Conn. Failed</span>');
    if (isTestMode) indicators.push('<span class="status-indicator status-indicator--testing"><span class="dot"></span>Test Mode</span>');
    if (fppUpdateAvailable) indicators.push('<span class="status-indicator status-indicator--update"><span class="dot"></span>FPP Update</span>');
    if (needsReboot) indicators.push('<span class="status-indicator status-indicator--reboot"><span class="dot"></span>Reboot Req</span>');
    else if (needsRestart) indicators.push('<span class="status-indicator status-indicator--restart"><span class="dot"></span>Restart Req</span>');
    statusEl.innerHTML = '<div class="status-indicators">' + indicators.join('') + '</div>';

    // Update info
    platformEl.textContent = status.platform || '--';
    versionEl.innerHTML = fppUpdateAvailable
        ? `${status.branch || '--'} <span class="version-update">(${fppLocalVersion} → ${fppRemoteVersion})</span>`
        : status.branch || '--';
    modeEl.textContent = status.mode_name || '--';
    watcherEl.textContent = data.watcherVersion || 'Not installed';

    // Enable controls
    testModeToggle.disabled = false;
    testModeToggle.checked = isTestMode;
    restartBtn.disabled = false;
    rebootBtn.disabled = false;

    // Track Watcher updates
    const watcherUpdate = pluginUpdates.find(p => p.repoName === 'fpp-plugin-watcher');
    if (watcherUpdate) {
        hostsWithWatcherUpdates.set(address, { hostname: remoteHostnames[address] || address, installedVersion: watcherUpdate.installedVersion, latestVersion: watcherUpdate.latestVersion });
    } else {
        hostsWithWatcherUpdates.delete(address);
    }
    updateBulkButton('upgradeAllBtn', 'upgradeAllCount', hostsWithWatcherUpdates);

    // Track restart/reboot needed
    if (needsReboot) {
        hostsNeedingRestart.set(address, { hostname: remoteHostnames[address] || address, type: 'reboot' });
    } else if (needsRestart) {
        hostsNeedingRestart.set(address, { hostname: remoteHostnames[address] || address, type: 'restart' });
    } else {
        hostsNeedingRestart.delete(address);
    }
    updateBulkButton('restartAllBtn', 'restartAllCount', hostsNeedingRestart);

    // Track FPP updates
    if (fppUpdateAvailable) {
        hostsWithFPPUpdates.set(address, { hostname: remoteHostnames[address] || address, localVersion: fppLocalVersion, remoteVersion: fppRemoteVersion });
        fppUpdateRow.classList.add('visible');
        fppUpdateVersion.textContent = `${fppLocalVersion} → ${fppRemoteVersion}`;
    } else {
        hostsWithFPPUpdates.delete(address);
        fppUpdateRow.classList.remove('visible');
    }
    updateBulkButton('fppUpgradeAllBtn', 'fppUpgradeAllCount', hostsWithFPPUpdates);

    // Track connectivity failures
    const connectivityAlert = document.getElementById(`connectivity-alert-${address}`);
    const connectivityDetails = document.getElementById(`connectivity-details-${address}`);
    if (hasConnectivityFailure) {
        const resetTime = connectivityState.resetTime || 'Unknown time';
        const adapter = connectivityState.adapter || 'Unknown';
        hostsWithConnectivityFailure.set(address, { hostname: remoteHostnames[address] || address, resetTime, adapter });
        connectivityDetails.textContent = `Adapter ${adapter} reset at ${resetTime}`;
        connectivityAlert.classList.add('visible');
    } else {
        hostsWithConnectivityFailure.delete(address);
        connectivityAlert.classList.remove('visible');
    }
    updateBulkButton('connectivityFailBtn', 'connectivityFailCount', hostsWithConnectivityFailure);

    // Plugin updates list
    if (pluginUpdates.length > 0) {
        let html = '';
        pluginUpdates.forEach(plugin => {
            const versionDisplay = plugin.latestVersion ? `v${plugin.installedVersion} → v${plugin.latestVersion}` : `v${plugin.installedVersion}`;
            html += `
                <div class="upgrade-item" id="upgrade-item-${address}-${plugin.repoName}">
                    <div class="update-info">
                        <span class="update-name">${plugin.name}</span>
                        <span class="update-version">${versionDisplay}</span>
                    </div>
                    <button class="banner-btn" onclick="upgradePlugin('${address}', '${plugin.repoName}')" id="upgrade-btn-${address}-${plugin.repoName}">
                        <i class="fas fa-download"></i> Upgrade
                    </button>
                </div>`;
        });
        upgradesList.innerHTML = html;
    } else {
        upgradesList.innerHTML = '';
    }

    // Show/hide unified updates container based on any updates available
    if (fppUpdateAvailable || pluginUpdates.length > 0) {
        updatesContainer.classList.add('visible');
    } else {
        updatesContainer.classList.remove('visible');
    }
}

async function refreshAllStatus() {
    if (isRefreshing) return;
    isRefreshing = true;

    const refreshBtn = document.getElementById('refreshBtn');
    refreshBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Refreshing...';
    refreshBtn.disabled = true;

    document.getElementById('loadingIndicator').style.display = 'none';
    document.getElementById('controlContent').style.display = 'block';

    try {
        await Promise.all([
            ...remoteAddresses.map(addr =>
                fetchRemoteStatus(addr).then(result => updateCardUI(result.address, result))
            ),
            updateSyncStatus()
        ]);
        document.getElementById('lastUpdateTime').textContent = new Date().toLocaleTimeString();
    } finally {
        refreshBtn.innerHTML = '<i class="fas fa-sync-alt"></i> Refresh All';
        refreshBtn.disabled = false;
        isRefreshing = false;
    }
}

// =============================================================================
// Actions
// =============================================================================

async function toggleTestMode(address, enable) {
    const toggle = document.getElementById(`testmode-${address}`);
    toggle.disabled = true;

    try {
        let requestData;
        if (enable) {
            let channelRange = "1-8388608";
            try {
                const infoResponse = await fetch(`http://${address}/api/system/info`);
                if (infoResponse.ok) {
                    const info = await infoResponse.json();
                    if (info.channelRanges) {
                        const parts = info.channelRanges.split('-');
                        if (parts.length === 2) {
                            channelRange = `${parseInt(parts[0]) + 1}-${parseInt(parts[1]) + 1}`;
                        }
                    }
                }
            } catch (e) {}
            requestData = { host: address, command: "Test Start", multisyncCommand: false, multisyncHosts: "", args: ["1000", "RGB Cycle", channelRange, "R-G-B"] };
        } else {
            requestData = { host: address, command: "Test Stop", multisyncCommand: false, multisyncHosts: "", args: [] };
        }

        const response = await fetch('/api/plugin/fpp-plugin-watcher/remote/command', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(requestData)
        });
        const data = await response.json();
        if (!data.success) throw new Error(data.error || 'Failed to toggle test mode');

        setTimeout(async () => {
            const result = await fetchRemoteStatus(address);
            updateCardUI(address, result);
        }, 500);
    } catch (error) {
        toggle.checked = !enable;
        alert(`Failed to toggle test mode: ${error.message}`);
    } finally {
        toggle.disabled = false;
    }
}

async function restartFppd(address) {
    const btn = document.getElementById(`restart-btn-${address}`);
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Restarting...';
    btn.disabled = true;

    try {
        const response = await fetch('/api/plugin/fpp-plugin-watcher/remote/restart', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ host: address })
        });
        const data = await response.json();
        if (!data.success) throw new Error(data.error || 'Failed to restart FPPD');

        btn.innerHTML = '<i class="fas fa-check"></i> Restarted!';
        setTimeout(async () => {
            btn.innerHTML = originalHtml;
            btn.disabled = false;
            const result = await fetchRemoteStatus(address);
            updateCardUI(address, result);
        }, 3000);
    } catch (error) {
        btn.innerHTML = originalHtml;
        btn.disabled = false;
        alert(`Failed to restart FPPD: ${error.message}`);
    }
}

function confirmReboot(address, hostname) {
    pendingReboot = { address, hostname };
    document.getElementById('confirmMessage').textContent = `Are you sure you want to reboot "${hostname}" (${address})? This will take the system offline temporarily.`;
    document.getElementById('confirmDialog').style.display = 'flex';
}

function closeConfirmDialog() {
    document.getElementById('confirmDialog').style.display = 'none';
    pendingReboot = null;
}

async function executeReboot() {
    if (!pendingReboot) return;
    const { address } = pendingReboot;
    closeConfirmDialog();

    const btn = document.getElementById(`reboot-btn-${address}`);
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Rebooting...';
    btn.disabled = true;

    try {
        await fetch('/api/plugin/fpp-plugin-watcher/remote/reboot', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ host: address })
        });

        const card = document.getElementById(`card-${address}`);
        card.classList.add('offline');
        document.getElementById(`status-${address}`).innerHTML = '<span class="status-indicator status-indicator--offline"><span class="dot"></span> Rebooting...</span>';

        let attempts = 0;
        const checkInterval = setInterval(async () => {
            if (++attempts > 60) { clearInterval(checkInterval); btn.innerHTML = originalHtml; btn.disabled = false; return; }
            const result = await fetchRemoteStatus(address);
            if (result.success) { clearInterval(checkInterval); updateCardUI(address, result); btn.innerHTML = originalHtml; btn.disabled = false; }
        }, 2000);
    } catch (error) {
        document.getElementById(`card-${address}`).classList.add('offline');
        document.getElementById(`status-${address}`).innerHTML = '<span class="status-indicator status-indicator--offline"><span class="dot"></span> Rebooting...</span>';
        btn.innerHTML = originalHtml;
        btn.disabled = false;
    }
}

document.getElementById('confirmRebootBtn').addEventListener('click', executeReboot);
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') { closeConfirmDialog(); closeBulkModal(); closeFPPUpgradeModal(); } });

async function clearRemoteResetState(address) {
    const btn = document.getElementById(`connectivity-clear-btn-${address}`);
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

    try {
        const response = await fetch('/api/plugin/fpp-plugin-watcher/remote/connectivity/state/clear', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ host: address })
        });
        const data = await response.json();

        if (!data.success) throw new Error(data.error || 'Failed to clear reset state');

        // Refresh this card's status
        setTimeout(async () => {
            const result = await fetchRemoteStatus(address);
            updateCardUI(address, result);
        }, 1000);
    } catch (error) {
        btn.innerHTML = originalHtml;
        btn.disabled = false;
        alert(`Failed to clear reset state: ${error.message}`);
    }
}

async function upgradePlugin(address, pluginRepoName) {
    const btn = document.getElementById(`upgrade-btn-${address}-${pluginRepoName}`);
    const item = document.getElementById(`upgrade-item-${address}-${pluginRepoName}`);
    const originalHtml = btn.innerHTML;

    if (!confirm(`Upgrade ${pluginRepoName} on ${address}?`)) return;

    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    btn.disabled = true;

    try {
        const response = await fetch('/api/plugin/fpp-plugin-watcher/remote/upgrade', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ host: address, plugin: pluginRepoName })
        });
        const data = await response.json();
        if (!data.success) throw new Error(data.error || 'Upgrade failed');

        btn.innerHTML = '<i class="fas fa-check"></i> Done';
        if (item) setTimeout(() => { item.style.opacity = '0.5'; }, 500);
        setTimeout(async () => { const result = await fetchRemoteStatus(address); updateCardUI(address, result); }, 3000);
    } catch (error) {
        btn.innerHTML = originalHtml;
        btn.disabled = false;
        alert(`Upgrade failed: ${error.message}`);
    }
}

// =============================================================================
// Bulk Operations (Unified Modal)
// =============================================================================

const bulkConfig = {
    connectivity: {
        title: '<i class="fas fa-network-wired" style="color: #ffc107;"></i> Clearing Connectivity Failures',
        getHosts: () => hostsWithConnectivityFailure,
        extraInfo: (info) => `<span style="font-size: 0.8em; color: #666;"> - ${info.adapter} at ${info.resetTime}</span>`,
        operation: async ([address]) => {
            const response = await fetch('/api/plugin/fpp-plugin-watcher/remote/connectivity/state/clear', {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ host: address })
            });
            const data = await response.json();
            if (!data.success) throw new Error(data.error);
        },
        refreshDelay: 1000
    },
    upgrade: {
        title: '<i class="fas fa-arrow-circle-up" style="color: #28a745;"></i> Upgrading Watcher',
        getHosts: () => hostsWithWatcherUpdates,
        parallel: true,
        operation: async ([address], updateStatus) => {
            updateStatus('spinner fa-spin', 'Updating...');
            const response = await fetch('/api/plugin/fpp-plugin-watcher/remote/upgrade', {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ host: address, plugin: 'fpp-plugin-watcher' })
            });
            const data = await response.json();
            if (!data.success) throw new Error(data.error);

            updateStatus('spinner fa-spin', 'Restarting FPPD...');
            await fetch('/api/plugin/fpp-plugin-watcher/remote/restart', {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ host: address })
            });

            updateStatus('hourglass-half', 'Waiting...');
            await new Promise(resolve => setTimeout(resolve, 2000));
        },
        refreshDelay: 3000
    },
    restart: {
        title: '<i class="fas fa-sync" style="color: #fd7e14;"></i> Restarting Systems',
        getHosts: () => hostsNeedingRestart,
        extraInfo: (info) => `<span class="restart-type ${info.type}">${info.type === 'reboot' ? 'Reboot' : 'FPPD Restart'}</span>`,
        operation: async ([address, info]) => {
            const endpoint = info.type === 'reboot' ? '/api/plugin/fpp-plugin-watcher/remote/reboot' : '/api/plugin/fpp-plugin-watcher/remote/restart';
            const response = await fetch(endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ host: address }) });
            const data = await response.json();
            if (!data.success) throw new Error(data.error);
        },
        refreshDelay: 3000
    },
    fpp: {
        // FPP uses separate modal with streaming
    }
};

async function showBulkModal(type) {
    if (type === 'fpp') { showFPPUpgradeModal(); return; }

    const config = bulkConfig[type];
    const hostsMap = config.getHosts();
    if (hostsMap.size < 1) return;

    currentBulkType = type;
    const modal = document.getElementById('bulkModal');
    const titleEl = document.getElementById('bulkModalTitle');
    const hostList = document.getElementById('bulkModalHostList');
    const progressEl = document.getElementById('bulkModalProgress');
    const closeBtn = document.getElementById('bulkModalCloseBtn');

    titleEl.innerHTML = config.title;
    hostList.innerHTML = buildHostListHtml(hostsMap, 'bulk', config.extraInfo);
    modal.style.display = 'flex';
    closeBtn.disabled = true;

    const hostsArray = Array.from(hostsMap.entries());
    const { completed, failed, total } = await processBulkOperation(hostsArray, config.operation, 'bulk', progressEl, config.parallel || false);

    progressEl.textContent = failed === 0 ? `Successfully processed ${completed} hosts!` : `Completed: ${completed} succeeded, ${failed} failed`;
    closeBtn.disabled = false;
}

function closeBulkModal() {
    document.getElementById('bulkModal').style.display = 'none';
    const delay = bulkConfig[currentBulkType]?.refreshDelay || 0;
    setTimeout(refreshAllStatus, delay);
    currentBulkType = null;
}

// =============================================================================
// FPP Upgrade (Streaming Modal)
// =============================================================================

function showFPPUpgradeModal() {
    if (hostsWithFPPUpdates.size < 1) return;

    const modal = document.getElementById('fppUpgradeModal');
    const hostSelect = document.getElementById('fppUpgradeHostSelect');
    const outputEl = document.getElementById('fppUpgradeOutput');
    const startBtn = document.getElementById('fppUpgradeStartBtn');
    const closeBtn = document.getElementById('fppUpgradeCloseBtn');

    let options = '';
    hostsWithFPPUpdates.forEach((info, addr) => {
        options += `<option value="${addr}">${info.hostname} (${addr}) - ${info.localVersion} → ${info.remoteVersion}</option>`;
    });
    hostSelect.innerHTML = options;
    outputEl.textContent = 'Select a system and click "Start Upgrade" to begin...\n\nNote: FPP upgrades can take 5-15 minutes.\nIncludes: git pull, compile, and fppd restart.';
    startBtn.disabled = false;
    startBtn.innerHTML = '<i class="fas fa-play"></i> Start Upgrade';
    closeBtn.disabled = false;
    modal.style.display = 'flex';
}

async function startFPPUpgrade() {
    const hostSelect = document.getElementById('fppUpgradeHostSelect');
    const outputEl = document.getElementById('fppUpgradeOutput');
    const startBtn = document.getElementById('fppUpgradeStartBtn');
    const closeBtn = document.getElementById('fppUpgradeCloseBtn');
    const address = hostSelect.value;
    if (!address) return;

    hostSelect.disabled = true;
    startBtn.disabled = true;
    startBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Upgrading...';
    closeBtn.disabled = true;
    outputEl.textContent = '';
    fppUpgradeAbortController = new AbortController();

    try {
        const response = await fetch('/api/plugin/fpp-plugin-watcher/remote/fpp/upgrade', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ host: address }), signal: fppUpgradeAbortController.signal
        });
        if (!response.ok) throw new Error(`HTTP error: ${response.status}`);

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        while (true) {
            const { done, value } = await reader.read();
            if (done) break;
            outputEl.textContent += decoder.decode(value, { stream: true });
            outputEl.scrollTop = outputEl.scrollHeight;
        }
        outputEl.textContent += '\n\n=== Upgrade process finished ===';
    } catch (error) {
        outputEl.textContent += error.name === 'AbortError' ? '\n\n=== Upgrade cancelled ===' : `\n\n=== ERROR: ${error.message} ===`;
    } finally {
        fppUpgradeAbortController = null;
        hostSelect.disabled = false;
        startBtn.disabled = false;
        startBtn.innerHTML = '<i class="fas fa-play"></i> Start Upgrade';
        closeBtn.disabled = false;
    }
}

function closeFPPUpgradeModal() {
    if (fppUpgradeAbortController) fppUpgradeAbortController.abort();
    document.getElementById('fppUpgradeModal').style.display = 'none';
    refreshAllStatus();
}

function upgradeFPPSingle(address) {
    if (!hostsWithFPPUpdates.has(address)) { alert('No FPP update available.'); return; }
    showFPPUpgradeModal();
    document.getElementById('fppUpgradeHostSelect').value = address;
}

// Auto-refresh every 30 seconds
setInterval(() => { if (!isRefreshing) refreshAllStatus(); }, 30000);

// Load on page ready
document.addEventListener('DOMContentLoaded', refreshAllStatus);
</script>
<?php endif; ?>
