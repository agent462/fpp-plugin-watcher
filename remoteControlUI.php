<?php
include_once __DIR__ . '/lib/config.php';
include_once __DIR__ . '/lib/watcherCommon.php';
include_once __DIR__ . '/lib/uiCommon.php';

$config = readPluginConfig();
$localSystem = apiCall('GET', 'http://127.0.0.1/api/fppd/status', [], true, 5) ?: [];
$access = checkDashboardAccess($config, $localSystem, 'controlUIEnabled');
$remoteSystems = $access['show'] ? getMultiSyncRemoteSystems() : [];
$localHostname = gethostname() ?: 'localhost';

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
        display: flex;
        flex-direction: column;
    }
    .controlCard:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.15); }
    .cardHeader {
        background: #495057;
        color: #fff;
        padding: 0.85rem 1.25rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-left: 4px solid #28a745;
        transition: border-color 0.3s, background 0.3s;
    }
    .cardHeader .hostname { font-size: 1.1rem; font-weight: 600; }
    .cardHeader .address { font-size: 0.8rem; opacity: 0.9; }
    .cardHeader .address a { color: inherit; text-decoration: none; }
    .cardHeader .address a:hover { text-decoration: underline; }
    /* Status-based accent colors */
    .controlCard.offline .cardHeader { background: #6c757d; border-left-color: #6c757d; }
    .controlCard.status-ok .cardHeader { border-left-color: #28a745; }
    .controlCard.status-warning .cardHeader { border-left-color: #ffc107; }
    .controlCard.status-restart .cardHeader { border-left-color: #fd7e14; }
    .controlCard.status-update .cardHeader { border-left-color: #17a2b8; }
    .controlCard.status-testing .cardHeader { border-left-color: #e83e8c; }
    .cardBody { padding: 1.25rem; display: flex; flex-direction: column; flex: 1; }
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
    .version-major { font-size: 0.75rem; color: #856404; font-weight: normal; cursor: help; }
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
    .actionButtons { display: flex; gap: 0.5rem; flex-wrap: wrap; padding-top: 0.75rem; margin-top: auto; border-top: 1px solid #e9ecef; }
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
    /* Connectivity alert */
    .connectivity-alert {
        display: none;
        position: relative;
        background: #fff3cd;
        border: 1px solid #ffc107;
        border-radius: 8px;
        padding: 0.75rem 1rem;
        margin-top: 1.75rem;
        padding-top: 1rem;
    }
    .connectivity-alert.visible { display: block; }
    .connectivity-alert.visible::before {
        content: '';
        position: absolute;
        top: -1rem;
        left: 0;
        right: 0;
        border-top: 1px solid #e9ecef;
    }
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
    /* Localhost card styling */
    .controlCard.localhost .cardHeader { background: #2c3e50; }
    .controlCard.localhost .hostname::before { content: '\f015'; font-family: 'Font Awesome 5 Free'; font-weight: 900; margin-right: 0.5rem; }
    /* FPP Upgrade Accordion */
    .fpp-upgrade-summary { background: #f8f9fa; border-radius: 6px; padding: 0.75rem 1rem; margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: center; }
    .fpp-upgrade-summary-text { font-size: 0.9rem; color: #495057; }
    .fpp-upgrade-summary-text strong { color: #212529; }
    .fpp-upgrade-actions { display: flex; gap: 0.5rem; }
    .fpp-upgrade-actions button { background: none; border: 1px solid #dee2e6; border-radius: 4px; padding: 0.25rem 0.5rem; font-size: 0.75rem; cursor: pointer; color: #6c757d; }
    .fpp-upgrade-actions button:hover { background: #e9ecef; }
    .fpp-accordion { display: flex; flex-direction: column; gap: 0.5rem; max-height: 60vh; overflow-y: auto; padding-bottom: 0.5rem; }
    .fpp-accordion-item { border: 1px solid #dee2e6; border-radius: 8px; overflow: visible; }
    .fpp-accordion-header { display: flex; align-items: center; padding: 0.75rem 1rem; background: #f8f9fa; cursor: pointer; user-select: none; transition: background 0.2s; border-radius: 8px; }
    .fpp-accordion-header:hover { background: #e9ecef; }
    .fpp-accordion-item.expanded .fpp-accordion-header { background: #e9ecef; border-bottom: 1px solid #dee2e6; border-radius: 8px 8px 0 0; }
    .fpp-accordion-toggle { width: 20px; color: #6c757d; transition: transform 0.2s; }
    .fpp-accordion-item.expanded .fpp-accordion-toggle { transform: rotate(90deg); }
    .fpp-accordion-info { flex: 1; margin-left: 0.5rem; }
    .fpp-accordion-hostname { font-weight: 600; color: #212529; }
    .fpp-accordion-address { font-size: 0.8rem; color: #6c757d; margin-left: 0.5rem; }
    .fpp-accordion-version { font-size: 0.75rem; color: #007bff; margin-left: 0.75rem; }
    .fpp-accordion-status { display: flex; align-items: center; gap: 0.4rem; font-size: 0.8rem; padding: 0.25rem 0.6rem; border-radius: 12px; }
    .fpp-accordion-status.pending { background: #e9ecef; color: #6c757d; }
    .fpp-accordion-status.upgrading { background: #cce5ff; color: #004085; }
    .fpp-accordion-status.success { background: #d4edda; color: #155724; }
    .fpp-accordion-status.error { background: #f8d7da; color: #721c24; }
    .fpp-accordion-body { display: none; border: 1px solid #dee2e6; border-top: none; border-radius: 0 0 8px 8px; margin-top: -1px; }
    .fpp-accordion-item.expanded .fpp-accordion-body { display: block; }
    .fpp-accordion-log { height: 250px; overflow-y: auto; background: #1e1e1e; color: #d4d4d4; font-family: 'Consolas', 'Monaco', monospace; font-size: 0.75rem; padding: 0.75rem; white-space: pre-wrap; word-break: break-word; margin: 0.5rem; border-radius: 4px; }
    .fpp-accordion-log:empty::before { content: 'Waiting to start...'; color: #6c757d; font-style: italic; }
    .fpp-accordion-checkbox { margin-right: 0.5rem; width: 18px; height: 18px; cursor: pointer; accent-color: #007bff; }
    .fpp-accordion-item.excluded { opacity: 0.5; }
    .fpp-accordion-item.excluded .fpp-accordion-header { background: #f8f9fa; }
    .fpp-upgrade-selection { display: flex; gap: 0.25rem; margin-right: 0.75rem; padding-right: 0.75rem; border-right: 1px solid #dee2e6; }
    /* FPP Upgrade Type Selector */
    .fpp-upgrade-type-selector { display: flex; gap: 1rem; margin-bottom: 1rem; }
    .fpp-upgrade-type-option {
        flex: 1; display: flex; flex-direction: column; align-items: center; gap: 0.25rem;
        padding: 0.75rem 1rem; border: 2px solid #dee2e6; border-radius: 8px;
        cursor: pointer; transition: all 0.2s; background: #f8f9fa; position: relative;
    }
    .fpp-upgrade-type-option:hover { border-color: #adb5bd; background: #fff; }
    .fpp-upgrade-type-option:has(input:checked) { border-color: #007bff; background: #e7f1ff; }
    .fpp-upgrade-type-option:has(input:disabled) { opacity: 0.5; cursor: not-allowed; }
    .fpp-upgrade-type-option input { position: absolute; opacity: 0; }
    .fpp-upgrade-type-label { font-weight: 600; color: #212529; font-size: 0.9rem; display: flex; align-items: center; gap: 0.4rem; }
    .fpp-upgrade-type-desc { font-size: 0.75rem; color: #6c757d; }
    .fpp-upgrade-type-count {
        position: absolute; top: -8px; right: -8px; background: #007bff; color: #fff;
        font-size: 0.7rem; font-weight: 600; padding: 0.15rem 0.5rem; border-radius: 10px; min-width: 20px; text-align: center;
    }
    .fpp-upgrade-type-count:empty, .fpp-upgrade-type-count[data-count="0"] { display: none; }
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
            <!-- Localhost Card -->
            <div class="controlCard localhost" id="card-localhost" data-address="localhost" data-hostname="<?php echo htmlspecialchars($localHostname); ?>">
                <div class="cardHeader">
                    <div class="hostname"><?php echo htmlspecialchars($localHostname); ?></div>
                    <div class="address" id="localhost-address"><a href="/" target="_blank">(This System)</a></div>
                </div>
                <div class="cardBody">
                    <div class="infoGrid">
                        <div class="infoItem">
                            <span class="infoLabel">Status</span>
                            <span class="infoValue" id="status-localhost">
                                <span class="status-indicator status-indicator--offline"><span class="dot"></span> Loading...</span>
                            </span>
                        </div>
                        <div class="infoItem">
                            <span class="infoLabel">Platform</span>
                            <span class="infoValue" id="platform-localhost">--</span>
                        </div>
                        <div class="infoItem">
                            <span class="infoLabel">Version</span>
                            <span class="infoValue" id="version-localhost">--</span>
                        </div>
                        <div class="infoItem">
                            <span class="infoLabel">Mode</span>
                            <span class="infoValue" id="mode-localhost">--</span>
                        </div>
                        <div class="infoItem">
                            <span class="infoLabel">Watcher</span>
                            <span class="infoValue" id="watcher-localhost">--</span>
                        </div>
                    </div>
                    <div class="actionRow">
                        <span class="actionLabel"><i class="fas fa-vial"></i> Test Mode (Local Channels)</span>
                        <label class="toggleSwitch">
                            <input type="checkbox" id="testmode-localhost" onchange="toggleTestMode('localhost', this.checked)" disabled>
                            <span class="toggleSlider"></span>
                        </label>
                    </div>
                    <div class="connectivity-alert" id="connectivity-alert-localhost">
                        <div class="connectivity-alert-content">
                            <div class="connectivity-alert-icon"><i class="fas fa-exclamation-triangle"></i></div>
                            <div class="connectivity-alert-text">
                                <strong>Connectivity Check Failed</strong>
                                <span class="connectivity-alert-details" id="connectivity-details-localhost"></span>
                            </div>
                            <button class="connectivity-alert-btn" onclick="clearLocalResetState()" id="connectivity-clear-btn-localhost">
                                <i class="fas fa-redo"></i> Clear
                            </button>
                        </div>
                    </div>
                    <div class="updates-container" id="updates-container-localhost">
                        <div class="updates-header">
                            <i class="fas fa-arrow-circle-up"></i> Updates Available
                        </div>
                        <div class="update-row update-row--fpp" id="fpp-crossversion-row-localhost">
                            <div class="update-info">
                                <span class="update-name"><i class="fas fa-arrow-up"></i> FPP Upgrade</span>
                                <span class="update-version" id="fpp-crossversion-version-localhost"></span>
                            </div>
                            <button class="banner-btn" onclick="upgradeFPPCrossVersion('localhost')" id="fpp-crossversion-btn-localhost">
                                <i class="fas fa-download"></i> Upgrade
                            </button>
                        </div>
                        <div class="update-row update-row--fpp" id="fpp-branch-row-localhost">
                            <div class="update-info">
                                <span class="update-name"><i class="fas fa-code-branch"></i> FPP Branch</span>
                                <span class="update-version" id="fpp-branch-version-localhost"></span>
                            </div>
                            <button class="banner-btn" onclick="upgradeFPPBranch('localhost')" id="fpp-branch-btn-localhost">
                                <i class="fas fa-sync"></i> Update
                            </button>
                        </div>
                        <div id="upgrades-list-localhost"></div>
                    </div>
                    <div class="actionButtons">
                        <button class="actionBtn restart" onclick="restartLocalFppd()" id="restart-btn-localhost" disabled>
                            <i class="fas fa-redo"></i> Restart FPPD
                        </button>
                        <button class="actionBtn reboot" onclick="confirmLocalReboot()" id="reboot-btn-localhost" disabled>
                            <i class="fas fa-power-off"></i> Reboot
                        </button>
                    </div>
                </div>
            </div>
            <?php foreach ($remoteSystems as $index => $system): ?>
            <div class="controlCard" id="card-<?php echo htmlspecialchars($system['address']); ?>" data-address="<?php echo htmlspecialchars($system['address']); ?>" data-hostname="<?php echo htmlspecialchars($system['hostname']); ?>">
                <div class="cardHeader">
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
                    <div class="actionRow">
                        <span class="actionLabel"><i class="fas fa-vial"></i> Test Mode (Local Channels)</span>
                        <label class="toggleSwitch">
                            <input type="checkbox" id="testmode-<?php echo htmlspecialchars($system['address']); ?>" onchange="toggleTestMode('<?php echo htmlspecialchars($system['address']); ?>', this.checked)" disabled>
                            <span class="toggleSlider"></span>
                        </label>
                    </div>
                    <div class="connectivity-alert" id="connectivity-alert-<?php echo htmlspecialchars($system['address']); ?>">
                        <div class="connectivity-alert-content">
                            <div class="connectivity-alert-icon"><i class="fas fa-exclamation-triangle"></i></div>
                            <div class="connectivity-alert-text">
                                <strong>Connectivity Check Failed</strong>
                                <span class="connectivity-alert-details" id="connectivity-details-<?php echo htmlspecialchars($system['address']); ?>"></span>
                            </div>
                            <button class="connectivity-alert-btn" onclick="clearResetState('<?php echo htmlspecialchars($system['address']); ?>')" id="connectivity-clear-btn-<?php echo htmlspecialchars($system['address']); ?>">
                                <i class="fas fa-redo"></i> Clear
                            </button>
                        </div>
                    </div>
                    <div class="updates-container" id="updates-container-<?php echo htmlspecialchars($system['address']); ?>">
                        <div class="updates-header">
                            <i class="fas fa-arrow-circle-up"></i> Updates Available
                        </div>
                        <div class="update-row update-row--fpp" id="fpp-crossversion-row-<?php echo htmlspecialchars($system['address']); ?>">
                            <div class="update-info">
                                <span class="update-name"><i class="fas fa-arrow-up"></i> FPP Upgrade</span>
                                <span class="update-version" id="fpp-crossversion-version-<?php echo htmlspecialchars($system['address']); ?>"></span>
                            </div>
                            <button class="banner-btn" onclick="upgradeFPPCrossVersion('<?php echo htmlspecialchars($system['address']); ?>')" id="fpp-crossversion-btn-<?php echo htmlspecialchars($system['address']); ?>">
                                <i class="fas fa-download"></i> Upgrade
                            </button>
                        </div>
                        <div class="update-row update-row--fpp" id="fpp-branch-row-<?php echo htmlspecialchars($system['address']); ?>">
                            <div class="update-info">
                                <span class="update-name"><i class="fas fa-code-branch"></i> FPP Branch</span>
                                <span class="update-version" id="fpp-branch-version-<?php echo htmlspecialchars($system['address']); ?>"></span>
                            </div>
                            <button class="banner-btn" onclick="upgradeFPPBranch('<?php echo htmlspecialchars($system['address']); ?>')" id="fpp-branch-btn-<?php echo htmlspecialchars($system['address']); ?>">
                                <i class="fas fa-sync"></i> Update
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

    <!-- FPP Upgrade Modal (with parallel streaming output) -->
    <div id="fppUpgradeModal" class="watcher-modal watcher-modal--dark" style="display: none;">
        <div class="modal-content modal-content--lg">
            <h4><i class="fas fa-code-branch" style="color: #007bff;"></i> Upgrade FPP</h4>
            <div class="fpp-upgrade-type-selector" id="fppUpgradeTypeSelector">
                <label class="fpp-upgrade-type-option">
                    <input type="radio" name="fppUpgradeType" value="crossVersion" onchange="switchFPPUpgradeType('crossVersion')">
                    <span class="fpp-upgrade-type-label"><i class="fas fa-arrow-up"></i> Version Upgrade</span>
                    <span class="fpp-upgrade-type-desc" id="fppCrossVersionDesc">Upgrade to latest version</span>
                    <span class="fpp-upgrade-type-count" id="fppCrossVersionCount">0</span>
                </label>
                <label class="fpp-upgrade-type-option">
                    <input type="radio" name="fppUpgradeType" value="branchUpdate" onchange="switchFPPUpgradeType('branchUpdate')">
                    <span class="fpp-upgrade-type-label"><i class="fas fa-code-branch"></i> Branch Update</span>
                    <span class="fpp-upgrade-type-desc">Update current branch</span>
                    <span class="fpp-upgrade-type-count" id="fppBranchUpdateCount">0</span>
                </label>
            </div>
            <div class="fpp-upgrade-summary" id="fppUpgradeSummary">
                <div class="fpp-upgrade-summary-text">
                    <strong id="fppUpgradeCount">0</strong> systems ready to upgrade
                </div>
                <div class="fpp-upgrade-actions">
                    <div class="fpp-upgrade-selection">
                        <button onclick="fppSelectAll()"><i class="fas fa-check-square"></i> All</button>
                        <button onclick="fppSelectNone()"><i class="fas fa-square"></i> None</button>
                    </div>
                    <button onclick="fppExpandAll()"><i class="fas fa-chevron-down"></i> Expand</button>
                    <button onclick="fppCollapseAll()"><i class="fas fa-chevron-up"></i> Collapse</button>
                </div>
            </div>
            <div class="fpp-accordion" id="fppAccordion"></div>
            <div class="modal-buttons modal-buttons--center">
                <button class="btn btn--fixed btn-primary" id="fppUpgradeStartBtn" onclick="startAllFPPUpgrades()">
                    <i class="fas fa-play"></i> Start All
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
let syncCheckInterval = null;
let latestFPPRelease = null; // Cached latest FPP release from GitHub
const SYNC_THRESHOLD_SECONDS = 3; // hosts are out of sync if time differs by more than this

// =============================================================================
// Data Source Configuration
// =============================================================================
// Each unique API endpoint can have its own refresh interval (in ms).
// Set interval to 0 to fetch on every refresh cycle (default 30s).
// Data is cached per-host between fetches.

const DATA_SOURCES = {
    // -------------------------------------------------------------------------
    // status: /api/fppd/status (local) or /api/plugin/fpp-plugin-watcher/remote/status (remote)
    // Provides: platform, branch, mode_name, rebootFlag, restartFlag, testMode status
    // -------------------------------------------------------------------------
    status: { interval: 0 },

    // -------------------------------------------------------------------------
    // version: /api/plugin/fpp-plugin-watcher/version (direct to each host)
    // Provides: watcher plugin version string
    // -------------------------------------------------------------------------
    version: { interval: 60000 },

    // -------------------------------------------------------------------------
    // updates: /api/plugin/fpp-plugin-watcher/plugins/updates (via proxy for remote)
    // Provides: array of plugins with available updates (name, repoName, installedVersion, latestVersion)
    // -------------------------------------------------------------------------
    updates: { interval: 60000 },

    // -------------------------------------------------------------------------
    // sysStatus: /api/system/status (direct to each host)
    // Provides: fppLocalVersion, fppRemoteVersion, diskUtilization, cpuUtilization, memoryUtilization, ipAddress
    // -------------------------------------------------------------------------
    sysStatus: { interval: 0 },

    // -------------------------------------------------------------------------
    // connectivity: /api/plugin/fpp-plugin-watcher/connectivity/state (via proxy for remote)
    // Provides: hasResetAdapter, resetTime, adapter (connectivity failure info)
    // -------------------------------------------------------------------------
    connectivity: { interval: 0 }
};

// Runtime state for each data source
const dataSourceState = {};
for (const source of Object.keys(DATA_SOURCES)) {
    dataSourceState[source] = { lastFetch: 0, cache: new Map() };
}

// Flags set at start of each refresh cycle (true = should fetch this cycle)
let fetchFlags = {};

function updateFetchFlags() {
    const now = Date.now();
    for (const [source, config] of Object.entries(DATA_SOURCES)) {
        const state = dataSourceState[source];
        // Fetch if interval is 0 (always) or enough time has passed
        fetchFlags[source] = config.interval === 0 || (now - state.lastFetch >= config.interval);
    }
}

function updateLastFetchTimes() {
    const now = Date.now();
    for (const source of Object.keys(DATA_SOURCES)) {
        if (fetchFlags[source]) {
            dataSourceState[source].lastFetch = now;
        }
    }
}

function getCachedData(source, address) {
    return dataSourceState[source]?.cache.get(address) || null;
}

function setCachedData(source, address, data) {
    if (dataSourceState[source]) {
        dataSourceState[source].cache.set(address, data);
    }
}

// =============================================================================
// FPP Release Version Check
// =============================================================================

async function fetchLatestFPPRelease() {
    try {
        const response = await fetch('/api/plugin/fpp-plugin-watcher/fpp/release');
        if (!response.ok) return null;
        const data = await response.json();
        if (data.success) {
            latestFPPRelease = data;
            return data;
        }
    } catch (e) {
        console.log('Failed to fetch latest FPP release:', e);
    }
    return null;
}

function parseFPPVersion(version) {
    if (!version) return [0, 0];
    const v = version.replace(/^v/, '');
    const match = v.match(/^(\d+)\.(\d+)/);
    return match ? [parseInt(match[1]), parseInt(match[2])] : [0, 0];
}

function compareFPPVersions(current, latest) {
    const [curMajor, curMinor] = parseFPPVersion(current);
    const [latMajor, latMinor] = parseFPPVersion(latest);
    if (curMajor < latMajor) return -1;
    if (curMajor > latMajor) return 1;
    if (curMinor < latMinor) return -1;
    if (curMinor > latMinor) return 1;
    return 0;
}

function checkCrossVersionUpgrade(branch) {
    if (!latestFPPRelease || !latestFPPRelease.latestVersion) return null;
    const comparison = compareFPPVersions(branch, latestFPPRelease.latestVersion);
    if (comparison < 0) {
        // Check if this is a major version jump (e.g., v9.x to v10.x)
        const [curMajor] = parseFPPVersion(branch);
        const [latMajor] = parseFPPVersion(latestFPPRelease.latestVersion);
        const isMajorUpgrade = latMajor > curMajor;

        return {
            available: true,
            currentVersion: branch ? branch.replace(/^v/, '') : 'unknown',
            latestVersion: latestFPPRelease.latestVersion,
            isMajorUpgrade: isMajorUpgrade
        };
    }
    return null;
}

// =============================================================================
// Helper Functions
// =============================================================================

function escapeId(address) {
    return address.replace(/\./g, '-');
}

function getHostname(address) {
    return address === 'localhost' ? '<?php echo htmlspecialchars($localHostname); ?>' : (remoteHostnames[address] || address);
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

// Parse system status response for utilization and IP
function parseSystemStatus(sysStatus) {
    const result = { fppLocalVersion: null, fppRemoteVersion: null, diskUtilization: null, cpuUtilization: null, memoryUtilization: null, ipAddress: null };
    if (!sysStatus?.advancedView) return result;

    result.fppLocalVersion = sysStatus.advancedView.LocalGitVersion || null;
    result.fppRemoteVersion = sysStatus.advancedView.RemoteGitVersion || null;

    // Get primary IP address
    const ips = sysStatus.advancedView.IPs;
    if (ips && typeof ips === 'object') {
        // IPs is an object like { "eth0": "192.168.1.100", "wlan0": "192.168.1.101" }
        // Prefer eth0, then wlan0, then first available
        result.ipAddress = ips.eth0 || ips.wlan0 || Object.values(ips)[0] || null;
    }

    const utilization = sysStatus.advancedView.Utilization;
    if (utilization) {
        const diskInfo = utilization.Disk?.Root;
        if (diskInfo?.Total > 0) {
            result.diskUtilization = Math.round(((diskInfo.Total - diskInfo.Free) / diskInfo.Total) * 100);
        }
        if (typeof utilization.CPU === 'number') result.cpuUtilization = Math.round(utilization.CPU);
        if (typeof utilization.Memory === 'number') result.memoryUtilization = Math.round(utilization.Memory);
    }
    return result;
}

// Expand FPP accordion item
function expandFPPItem(address) {
    const state = fppUpgradeStates.get(address);
    if (state) {
        state.expanded = true;
        document.getElementById(`fpp-item-${escapeId(address)}`)?.classList.add('expanded');
    }
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

async function fetchSystemStatus(address) {
    const isLocal = address === 'localhost';
    try {
        // Build URLs based on local vs remote
        const urls = isLocal ? {
            status: '/api/fppd/status',
            version: '/api/plugin/fpp-plugin-watcher/version',
            updates: '/api/plugin/fpp-plugin-watcher/plugins/updates',
            sysStatus: '/api/system/status',
            connectivity: '/api/plugin/fpp-plugin-watcher/connectivity/state'
        } : {
            status: `/api/plugin/fpp-plugin-watcher/remote/status?host=${encodeURIComponent(address)}`,
            version: `http://${address}/api/plugin/fpp-plugin-watcher/version`,
            updates: `/api/plugin/fpp-plugin-watcher/remote/plugins/updates?host=${encodeURIComponent(address)}`,
            sysStatus: `http://${address}/api/system/status`,
            connectivity: `/api/plugin/fpp-plugin-watcher/remote/connectivity/state?host=${encodeURIComponent(address)}`
        };

        // Fetch each data source based on its configured interval
        const [statusResponse, versionResponse, updatesResponse, sysStatusResponse, connectivityResponse] = await Promise.all([
            fetchFlags.status ? fetch(urls.status) : Promise.resolve(null),
            fetchFlags.version ? fetch(urls.version).catch(() => null) : Promise.resolve(null),
            fetchFlags.updates ? fetch(urls.updates).catch(() => null) : Promise.resolve(null),
            fetchFlags.sysStatus ? fetch(urls.sysStatus).catch(() => null) : Promise.resolve(null),
            fetchFlags.connectivity ? fetch(urls.connectivity).catch(() => null) : Promise.resolve(null)
        ]);

        // Parse status (different format for local vs remote)
        let status, testMode;
        if (fetchFlags.status && statusResponse) {
            if (isLocal) {
                if (!statusResponse.ok) return { success: false, address, error: 'Failed to fetch status' };
                const fppStatus = await statusResponse.json();
                status = {
                    platform: fppStatus.platform || '--',
                    branch: fppStatus.branch || '--',
                    mode_name: fppStatus.mode_name || '--',
                    rebootFlag: fppStatus.rebootFlag || 0,
                    restartFlag: fppStatus.restartFlag || 0
                };
                testMode = { enabled: fppStatus.status_name === 'testing' ? 1 : 0 };
                setCachedData('status', address, { status, testMode });
            } else {
                const data = await statusResponse.json();
                if (!data.success) return { success: false, address, error: data.error || 'Failed to fetch status' };
                status = data.status;
                testMode = data.testMode;
                setCachedData('status', address, { status, testMode });
            }
        } else {
            // Use cached status data
            const cached = getCachedData('status', address);
            if (!cached) return { success: false, address, error: 'No cached status available' };
            status = cached.status;
            testMode = cached.testMode;
        }

        // Parse version (uses cache when not fetching)
        let watcherVersion = getCachedData('version', address);
        if (fetchFlags.version && versionResponse?.ok) {
            try {
                watcherVersion = (await versionResponse.json()).version || null;
                if (watcherVersion) setCachedData('version', address, watcherVersion);
            } catch (e) {}
        }

        // Parse plugin updates (uses cache when not fetching)
        let pluginUpdates = getCachedData('updates', address) || [];
        if (fetchFlags.updates && updatesResponse?.ok) {
            try {
                const updatesData = await updatesResponse.json();
                pluginUpdates = (updatesData.success && updatesData.updatesAvailable) ? updatesData.updatesAvailable : [];
                setCachedData('updates', address, pluginUpdates);
            } catch (e) {}
        }

        // Parse system status (uses cache when not fetching)
        let sysInfo = getCachedData('sysStatus', address) || { fppLocalVersion: null, fppRemoteVersion: null, diskUtilization: null, cpuUtilization: null, memoryUtilization: null, ipAddress: null };
        if (fetchFlags.sysStatus && sysStatusResponse?.ok) {
            try {
                sysInfo = parseSystemStatus(await sysStatusResponse.json());
                setCachedData('sysStatus', address, sysInfo);
            } catch (e) {}
        }

        // Parse connectivity state (uses cache when not fetching)
        let connectivityState = getCachedData('connectivity', address);
        if (fetchFlags.connectivity && connectivityResponse?.ok) {
            try {
                const connData = await connectivityResponse.json();
                connectivityState = (connData.success && connData.hasResetAdapter) ? connData : null;
                setCachedData('connectivity', address, connectivityState);
            } catch (e) {}
        }

        return { success: true, address, status, testMode, watcherVersion, pluginUpdates, connectivityState, ...sysInfo };
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
    // Separate rows for cross-version and branch updates
    const fppCrossVersionRow = document.getElementById(`fpp-crossversion-row-${address}`);
    const fppCrossVersionVersion = document.getElementById(`fpp-crossversion-version-${address}`);
    const fppBranchRow = document.getElementById(`fpp-branch-row-${address}`);
    const fppBranchVersion = document.getElementById(`fpp-branch-version-${address}`);

    // Clear all status classes
    card.classList.remove('offline', 'status-ok', 'status-warning', 'status-restart', 'status-update', 'status-testing');

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
        fppCrossVersionRow.classList.remove('visible');
        fppBranchRow.classList.remove('visible');
        document.getElementById(`connectivity-alert-${address}`)?.classList.remove('visible');
        hostsWithConnectivityFailure.delete(address);
        updateBulkButton('connectivityFailBtn', 'connectivityFailCount', hostsWithConnectivityFailure);
        return;
    }
    const { status, testMode, pluginUpdates = [], fppLocalVersion, fppRemoteVersion, connectivityState, diskUtilization, cpuUtilization, memoryUtilization, ipAddress } = data;
    const isTestMode = testMode.enabled === 1;
    const needsReboot = status.rebootFlag === 1;
    const needsRestart = status.restartFlag === 1;

    // Update localhost address display with actual IP
    if (address === 'localhost' && ipAddress) {
        const addrEl = document.getElementById('localhost-address');
        if (addrEl) addrEl.innerHTML = `<a href="http://${ipAddress}/" target="_blank">${ipAddress} (This System)</a>`;
    }

    // Check for same-branch updates (git version differs)
    const sameBranchUpdate = fppLocalVersion && fppRemoteVersion && fppRemoteVersion !== 'Unknown' && fppRemoteVersion !== '' && fppLocalVersion !== fppRemoteVersion;

    // Check for cross-version upgrades (e.g., v9.2 -> v9.3)
    const crossVersionUpgrade = checkCrossVersionUpgrade(status.branch);

    // Combined: any FPP update available
    const fppUpdateAvailable = sameBranchUpdate || (crossVersionUpgrade && crossVersionUpgrade.available);

    const hasConnectivityFailure = connectivityState && connectivityState.hasResetAdapter;
    const hasPluginUpdates = pluginUpdates.length > 0;
    const hasLowStorage = diskUtilization !== null && diskUtilization >= 90;
    const hasHighCpu = cpuUtilization !== null && cpuUtilization >= 80;
    const hasLowMemory = memoryUtilization !== null && memoryUtilization >= 90;

    // Set status class for left border accent (priority: testing > restart > update > warning > ok)
    if (isTestMode) card.classList.add('status-testing');
    else if (needsReboot || needsRestart) card.classList.add('status-restart');
    else if (fppUpdateAvailable || hasPluginUpdates) card.classList.add('status-update');
    else if (hasConnectivityFailure || hasLowStorage || hasHighCpu || hasLowMemory) card.classList.add('status-warning');
    else card.classList.add('status-ok');

    // Build status indicators
    let indicators = ['<span class="status-indicator status-indicator--online"><span class="dot"></span>Online</span>'];
    if (hasConnectivityFailure) indicators.push('<span class="status-indicator status-indicator--connectivity"><span class="dot"></span>Conn. Failed</span>');
    if (hasHighCpu) indicators.push(`<span class="status-indicator status-indicator--high-cpu"><span class="dot"></span>High CPU (${cpuUtilization}%)</span>`);
    if (hasLowMemory) indicators.push(`<span class="status-indicator status-indicator--low-memory"><span class="dot"></span>Low Memory (${memoryUtilization}%)</span>`);
    if (hasLowStorage) indicators.push(`<span class="status-indicator status-indicator--low-storage"><span class="dot"></span>Low Storage (${diskUtilization}%)</span>`);
    if (isTestMode) indicators.push('<span class="status-indicator status-indicator--testing"><span class="dot"></span>Test Mode</span>');
    // Show both indicators when both updates are available
    if (crossVersionUpgrade && crossVersionUpgrade.available) {
        if (crossVersionUpgrade.isMajorUpgrade) {
            // Major upgrade - show as info/warning, not actionable
            indicators.push(`<span class="status-indicator status-indicator--major-upgrade" title="Major version upgrade requires OS re-image"><span class="dot"></span>FPP v${crossVersionUpgrade.latestVersion}</span>`);
        } else {
            indicators.push(`<span class="status-indicator status-indicator--update"><span class="dot"></span>FPP v${crossVersionUpgrade.latestVersion}</span>`);
        }
    }
    if (sameBranchUpdate) {
        const branchDisplay = status.branch ? status.branch.replace(/^v/, '') : '';
        indicators.push(`<span class="status-indicator status-indicator--update"><span class="dot"></span>${branchDisplay} Update</span>`);
    }
    if (needsReboot) indicators.push('<span class="status-indicator status-indicator--reboot"><span class="dot"></span>Reboot Req</span>');
    else if (needsRestart) indicators.push('<span class="status-indicator status-indicator--restart"><span class="dot"></span>Restart Req</span>');
    statusEl.innerHTML = '<div class="status-indicators">' + indicators.join('') + '</div>';

    // Update info - show version upgrade info (show both when both available)
    platformEl.textContent = status.platform || '--';
    let versionHtml = status.branch || '--';
    let versionNotes = [];
    if (crossVersionUpgrade && crossVersionUpgrade.available) {
        if (crossVersionUpgrade.isMajorUpgrade) {
            versionNotes.push(`<span class="version-major" title="Major version upgrade requires OS re-image">v${crossVersionUpgrade.latestVersion} re-image</span>`);
        } else {
            versionNotes.push(`<span class="version-update">v${crossVersionUpgrade.latestVersion}</span>`);
        }
    }
    if (sameBranchUpdate) {
        versionNotes.push(`<span class="version-update">branch update</span>`);
    }
    if (versionNotes.length > 0) {
        versionHtml += ` (${versionNotes.join(', ')})`;
    }
    versionEl.innerHTML = versionHtml;
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
        hostsWithWatcherUpdates.set(address, { hostname: getHostname(address), installedVersion: watcherUpdate.installedVersion, latestVersion: watcherUpdate.latestVersion });
    } else {
        hostsWithWatcherUpdates.delete(address);
    }
    updateBulkButton('upgradeAllBtn', 'upgradeAllCount', hostsWithWatcherUpdates);

    // Track restart/reboot needed
    if (needsReboot) {
        hostsNeedingRestart.set(address, { hostname: getHostname(address), type: 'reboot' });
    } else if (needsRestart) {
        hostsNeedingRestart.set(address, { hostname: getHostname(address), type: 'restart' });
    } else {
        hostsNeedingRestart.delete(address);
    }
    updateBulkButton('restartAllBtn', 'restartAllCount', hostsNeedingRestart);

    // Track FPP updates (both same-branch and cross-version, but NOT major version upgrades)
    // Major version upgrades (e.g., v9.x to v10.x) require OS re-image and cannot be done via upgrade
    const isMajorUpgrade = crossVersionUpgrade && crossVersionUpgrade.available && crossVersionUpgrade.isMajorUpgrade;
    const hasCrossVersionUpgrade = crossVersionUpgrade && crossVersionUpgrade.available && !isMajorUpgrade;

    // Show/hide cross-version upgrade row
    if (hasCrossVersionUpgrade) {
        fppCrossVersionRow.classList.add('visible');
        fppCrossVersionVersion.textContent = `v${crossVersionUpgrade.currentVersion} → v${crossVersionUpgrade.latestVersion}`;
    } else {
        fppCrossVersionRow.classList.remove('visible');
    }

    // Show/hide same-branch update row
    if (sameBranchUpdate) {
        const branchDisplay = status.branch ? status.branch.replace(/^v/, '') : '';
        fppBranchRow.classList.add('visible');
        fppBranchVersion.textContent = `${branchDisplay}: ${fppLocalVersion.substring(0, 7)} → ${fppRemoteVersion.substring(0, 7)}`;
    } else {
        fppBranchRow.classList.remove('visible');
    }

    // Track for bulk modal - store both upgrade types if available
    if (hasCrossVersionUpgrade || sameBranchUpdate) {
        hostsWithFPPUpdates.set(address, {
            hostname: getHostname(address),
            branch: status.branch,
            crossVersion: hasCrossVersionUpgrade ? {
                localVersion: crossVersionUpgrade.currentVersion,
                remoteVersion: crossVersionUpgrade.latestVersion
            } : null,
            branchUpdate: sameBranchUpdate ? {
                localVersion: fppLocalVersion,
                remoteVersion: fppRemoteVersion
            } : null
        });
    } else {
        hostsWithFPPUpdates.delete(address);
    }
    updateBulkButton('fppUpgradeAllBtn', 'fppUpgradeAllCount', hostsWithFPPUpdates);

    // Track connectivity failures
    const connectivityAlert = document.getElementById(`connectivity-alert-${address}`);
    const connectivityDetails = document.getElementById(`connectivity-details-${address}`);
    if (hasConnectivityFailure) {
        const resetTime = connectivityState.resetTime || 'Unknown time';
        const adapter = connectivityState.adapter || 'Unknown';
        hostsWithConnectivityFailure.set(address, { hostname: getHostname(address), resetTime, adapter });
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
        // Determine which data sources should be fetched this cycle based on their intervals
        updateFetchFlags();

        // Fetch latest FPP release first (for cross-version upgrade detection)
        await fetchLatestFPPRelease();

        await Promise.all([
            fetchSystemStatus('localhost').then(result => updateCardUI('localhost', result)),
            ...remoteAddresses.map(addr =>
                fetchSystemStatus(addr).then(result => updateCardUI(result.address, result))
            ),
            updateSyncStatus()
        ]);
        document.getElementById('lastUpdateTime').textContent = new Date().toLocaleTimeString();
        updateLastFetchTimes();
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
    const isLocal = address === 'localhost';
    const toggle = document.getElementById(`testmode-${address}`);
    toggle.disabled = true;

    try {
        // Get channel range
        let channelRange = "1-8388608";
        if (enable) {
            try {
                const infoUrl = isLocal ? '/api/system/info' : `http://${address}/api/system/info`;
                const infoResponse = await fetch(infoUrl);
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
        }

        const command = enable ? 'Test Start' : 'Test Stop';
        const args = enable ? ["1000", "RGB Cycle", channelRange, "R-G-B"] : [];

        if (isLocal) {
            const response = await fetch('/api/command', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ command, args })
            });
            if (!response.ok) throw new Error('Failed to toggle test mode');
        } else {
            const response = await fetch('/api/plugin/fpp-plugin-watcher/remote/command', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ host: address, command, multisyncCommand: false, multisyncHosts: "", args })
            });
            const data = await response.json();
            if (!data.success) throw new Error(data.error || 'Failed to toggle test mode');
        }

        setTimeout(async () => {
            const result = await fetchSystemStatus(address);
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
    const isLocal = address === 'localhost';
    const btn = document.getElementById(`restart-btn-${address}`);
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Restarting...';
    btn.disabled = true;

    try {
        if (isLocal) {
            const response = await fetch('/api/fppd/restart', { method: 'GET' });
            if (!response.ok) throw new Error('Failed to restart FPPD');
        } else {
            const response = await fetch('/api/plugin/fpp-plugin-watcher/remote/restart', {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ host: address })
            });
            const data = await response.json();
            if (!data.success) throw new Error(data.error || 'Failed to restart FPPD');
        }

        btn.innerHTML = '<i class="fas fa-check"></i> Restarted!';
        setTimeout(async () => {
            btn.innerHTML = originalHtml;
            btn.disabled = false;
            const result = await fetchSystemStatus(address);
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
    const isLocal = address === 'localhost';
    closeConfirmDialog();

    const btn = document.getElementById(`reboot-btn-${address}`);
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Rebooting...';
    btn.disabled = true;

    try {
        if (isLocal) {
            await fetch('/api/system/reboot', { method: 'GET' });
        } else {
            await fetch('/api/plugin/fpp-plugin-watcher/remote/reboot', {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ host: address })
            });
        }

        document.getElementById(`card-${address}`).classList.add('offline');
        document.getElementById(`status-${address}`).innerHTML = '<span class="status-indicator status-indicator--offline"><span class="dot"></span> Rebooting...</span>';

        // For remote hosts, poll for recovery; localhost will lose connection
        if (!isLocal) {
            let attempts = 0;
            const checkInterval = setInterval(async () => {
                if (++attempts > 60) { clearInterval(checkInterval); btn.innerHTML = originalHtml; btn.disabled = false; return; }
                const result = await fetchSystemStatus(address);
                if (result.success) { clearInterval(checkInterval); updateCardUI(address, result); btn.innerHTML = originalHtml; btn.disabled = false; }
            }, 2000);
        }
    } catch (error) {
        // For localhost, connection loss is expected
        document.getElementById(`card-${address}`).classList.add('offline');
        document.getElementById(`status-${address}`).innerHTML = '<span class="status-indicator status-indicator--offline"><span class="dot"></span> Rebooting...</span>';
        if (!isLocal) { btn.innerHTML = originalHtml; btn.disabled = false; }
    }
}

document.getElementById('confirmRebootBtn').addEventListener('click', executeReboot);
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') { closeConfirmDialog(); closeBulkModal(); closeFPPUpgradeModal(); } });

async function clearResetState(address) {
    const isLocal = address === 'localhost';
    const btn = document.getElementById(`connectivity-clear-btn-${address}`);
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

    try {
        const url = isLocal
            ? '/api/plugin/fpp-plugin-watcher/connectivity/state/clear'
            : '/api/plugin/fpp-plugin-watcher/remote/connectivity/state/clear';
        const options = isLocal
            ? { method: 'POST' }
            : { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ host: address }) };

        const response = await fetch(url, options);
        const data = await response.json();
        if (!data.success) throw new Error(data.error || 'Failed to clear reset state');

        setTimeout(async () => {
            const result = await fetchSystemStatus(address);
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
        setTimeout(async () => { const result = await fetchSystemStatus(address); updateCardUI(address, result); }, 3000);
    } catch (error) {
        btn.innerHTML = originalHtml;
        btn.disabled = false;
        alert(`Upgrade failed: ${error.message}`);
    }
}

// =============================================================================
// Local System Actions (wrappers that call unified functions)
// =============================================================================

function restartLocalFppd() { restartFppd('localhost'); }
function confirmLocalReboot() { confirmReboot('localhost', '<?php echo htmlspecialchars($localHostname); ?>'); }
function clearLocalResetState() { clearResetState('localhost'); }

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
// FPP Upgrade (Parallel Streaming Modal with Accordion)
// =============================================================================

let fppUpgradeStates = new Map(); // address → {status, abortController, expanded, selected}
let fppUpgradeIsRunning = false;
let fppSelectedUpgradeType = 'crossVersion'; // 'crossVersion' or 'branchUpdate'

function getHostsForUpgradeType(upgradeType) {
    const result = new Map();
    hostsWithFPPUpdates.forEach((info, addr) => {
        if (upgradeType === 'crossVersion' && info.crossVersion) {
            result.set(addr, {
                hostname: info.hostname,
                localVersion: info.crossVersion.localVersion,
                remoteVersion: info.crossVersion.remoteVersion,
                isCrossVersion: true
            });
        } else if (upgradeType === 'branchUpdate' && info.branchUpdate) {
            const branchDisplay = info.branch ? info.branch.replace(/^v/, '') : '';
            result.set(addr, {
                hostname: info.hostname,
                localVersion: info.branchUpdate.localVersion.substring(0, 7),
                remoteVersion: info.branchUpdate.remoteVersion.substring(0, 7),
                branch: branchDisplay,
                isCrossVersion: false
            });
        }
    });
    return result;
}

function countHostsByUpgradeType() {
    let crossVersionCount = 0, branchUpdateCount = 0;
    hostsWithFPPUpdates.forEach(info => {
        if (info.crossVersion) crossVersionCount++;
        if (info.branchUpdate) branchUpdateCount++;
    });
    return { crossVersionCount, branchUpdateCount };
}

function switchFPPUpgradeType(upgradeType) {
    if (fppUpgradeIsRunning) return; // Don't switch during upgrade
    fppSelectedUpgradeType = upgradeType;
    buildFPPAccordion();
}

function buildFPPAccordion() {
    const accordion = document.getElementById('fppAccordion');
    const hostsForType = getHostsForUpgradeType(fppSelectedUpgradeType);

    // Reset state for new type
    fppUpgradeStates.clear();

    // Build accordion items
    let html = '';
    if (hostsForType.size === 0) {
        html = '<div style="text-align: center; padding: 2rem; color: #6c757d;"><i class="fas fa-info-circle"></i> No hosts available for this upgrade type</div>';
    } else {
        hostsForType.forEach((info, addr) => {
            const safeId = escapeId(addr);
            fppUpgradeStates.set(addr, { status: 'pending', abortController: null, expanded: false, selected: true });
            const versionDisplay = info.branch
                ? `${info.branch}: ${info.localVersion} → ${info.remoteVersion}`
                : `v${info.localVersion} → v${info.remoteVersion}`;
            html += `
                <div class="fpp-accordion-item" id="fpp-item-${safeId}" data-address="${addr}">
                    <div class="fpp-accordion-header">
                        <input type="checkbox" class="fpp-accordion-checkbox" id="fpp-check-${safeId}" checked onclick="event.stopPropagation(); toggleFPPSelection('${addr}')">
                        <div class="fpp-accordion-toggle" onclick="toggleFPPAccordion('${addr}')"><i class="fas fa-chevron-right"></i></div>
                        <div class="fpp-accordion-info" onclick="toggleFPPAccordion('${addr}')">
                            <span class="fpp-accordion-hostname">${info.hostname}</span>
                            <span class="fpp-accordion-address">${addr}</span>
                            <span class="fpp-accordion-version">${versionDisplay}</span>
                        </div>
                        <div class="fpp-accordion-status pending" id="fpp-status-${safeId}">
                            <i class="fas fa-clock"></i> Pending
                        </div>
                    </div>
                    <div class="fpp-accordion-body">
                        <div class="fpp-accordion-log" id="fpp-log-${safeId}"></div>
                    </div>
                </div>`;
        });
    }

    accordion.innerHTML = html;
    updateFPPSummary();
}

function showFPPUpgradeModal() {
    if (hostsWithFPPUpdates.size < 1) return;

    const modal = document.getElementById('fppUpgradeModal');
    const startBtn = document.getElementById('fppUpgradeStartBtn');
    const closeBtn = document.getElementById('fppUpgradeCloseBtn');

    // Reset state
    fppUpgradeIsRunning = false;

    // Count hosts by upgrade type
    const { crossVersionCount, branchUpdateCount } = countHostsByUpgradeType();

    // Update type selector counts
    document.getElementById('fppCrossVersionCount').textContent = crossVersionCount;
    document.getElementById('fppCrossVersionCount').setAttribute('data-count', crossVersionCount);
    document.getElementById('fppBranchUpdateCount').textContent = branchUpdateCount;
    document.getElementById('fppBranchUpdateCount').setAttribute('data-count', branchUpdateCount);

    // Update cross-version description with actual version
    if (latestFPPRelease && latestFPPRelease.latestVersion) {
        document.getElementById('fppCrossVersionDesc').textContent = `Upgrade to v${latestFPPRelease.latestVersion}`;
    }

    // Enable/disable type options based on availability
    const crossVersionRadio = document.querySelector('input[name="fppUpgradeType"][value="crossVersion"]');
    const branchUpdateRadio = document.querySelector('input[name="fppUpgradeType"][value="branchUpdate"]');
    crossVersionRadio.disabled = crossVersionCount === 0;
    branchUpdateRadio.disabled = branchUpdateCount === 0;

    // Select the type with available hosts (prefer crossVersion)
    if (crossVersionCount > 0) {
        fppSelectedUpgradeType = 'crossVersion';
        crossVersionRadio.checked = true;
    } else if (branchUpdateCount > 0) {
        fppSelectedUpgradeType = 'branchUpdate';
        branchUpdateRadio.checked = true;
    }

    // Build accordion for selected type
    buildFPPAccordion();

    startBtn.disabled = false;
    startBtn.style.display = '';
    startBtn.innerHTML = '<i class="fas fa-play"></i> Start Selected';
    closeBtn.disabled = false;
    closeBtn.classList.remove('btn-success');
    closeBtn.classList.add('btn-muted');
    closeBtn.innerHTML = 'Close';
    modal.style.display = 'flex';
}

function toggleFPPAccordion(address) {
    const safeId = escapeId(address);
    const item = document.getElementById(`fpp-item-${safeId}`);
    const state = fppUpgradeStates.get(address);
    if (!item || !state) return;

    state.expanded = !state.expanded;
    item.classList.toggle('expanded', state.expanded);
}

function fppExpandAll() {
    fppUpgradeStates.forEach((state, address) => {
        state.expanded = true;
        document.getElementById(`fpp-item-${escapeId(address)}`)?.classList.add('expanded');
    });
}

function fppCollapseAll() {
    fppUpgradeStates.forEach((state, address) => {
        state.expanded = false;
        document.getElementById(`fpp-item-${escapeId(address)}`)?.classList.remove('expanded');
    });
}

function toggleFPPSelection(address) {
    const safeId = escapeId(address);
    const checkbox = document.getElementById(`fpp-check-${safeId}`);
    const item = document.getElementById(`fpp-item-${safeId}`);
    const state = fppUpgradeStates.get(address);
    if (!state || !checkbox || !item) return;

    state.selected = checkbox.checked;
    item.classList.toggle('excluded', !state.selected);
    updateFPPSummary();
}

function fppSelectAll() {
    fppUpgradeStates.forEach((state, address) => {
        if (state.status === 'pending') {
            state.selected = true;
            const safeId = escapeId(address);
            const checkbox = document.getElementById(`fpp-check-${safeId}`);
            const item = document.getElementById(`fpp-item-${safeId}`);
            if (checkbox) checkbox.checked = true;
            item?.classList.remove('excluded');
        }
    });
    updateFPPSummary();
}

function fppSelectNone() {
    fppUpgradeStates.forEach((state, address) => {
        if (state.status === 'pending') {
            state.selected = false;
            const safeId = escapeId(address);
            const checkbox = document.getElementById(`fpp-check-${safeId}`);
            const item = document.getElementById(`fpp-item-${safeId}`);
            if (checkbox) checkbox.checked = false;
            item?.classList.add('excluded');
        }
    });
    updateFPPSummary();
}

function updateFPPStatus(address, status, icon, text) {
    const safeId = escapeId(address);
    const statusEl = document.getElementById(`fpp-status-${safeId}`);
    const state = fppUpgradeStates.get(address);
    if (statusEl && state) {
        state.status = status;
        statusEl.className = `fpp-accordion-status ${status}`;
        statusEl.innerHTML = `<i class="fas fa-${icon}"></i> ${text}`;
    }
}

function updateFPPSummary() {
    const countEl = document.getElementById('fppUpgradeCount');
    const startBtn = document.getElementById('fppUpgradeStartBtn');
    let pending = 0, upgrading = 0, success = 0, error = 0, selected = 0;
    fppUpgradeStates.forEach(state => {
        if (state.status === 'pending') {
            pending++;
            if (state.selected) selected++;
        }
        else if (state.status === 'upgrading') upgrading++;
        else if (state.status === 'success') success++;
        else if (state.status === 'error') error++;
    });
    const total = fppUpgradeStates.size;
    if (upgrading > 0) {
        countEl.textContent = `${upgrading} upgrading, ${success + error} of ${total} complete`;
    } else if (success + error > 0) {
        countEl.textContent = `${success} succeeded, ${error} failed of ${total}`;
    } else {
        countEl.textContent = `${selected} of ${total} selected`;
    }
    // Disable start button if nothing selected
    if (startBtn && !fppUpgradeIsRunning) {
        startBtn.disabled = selected === 0;
    }
}

async function startSingleFPPUpgrade(address) {
    const safeId = escapeId(address);
    const logEl = document.getElementById(`fpp-log-${safeId}`);
    const item = document.getElementById(`fpp-item-${safeId}`);
    const state = fppUpgradeStates.get(address);
    if (!logEl || !state) return;

    // Get upgrade info based on currently selected upgrade type
    const hostsForType = getHostsForUpgradeType(fppSelectedUpgradeType);
    const upgradeInfo = hostsForType.get(address);
    const isCrossVersion = fppSelectedUpgradeType === 'crossVersion';

    state.abortController = new AbortController();
    updateFPPStatus(address, 'upgrading', 'spinner fa-spin', 'Upgrading...');
    updateFPPSummary();
    logEl.textContent = '';

    // Build request body - include version for cross-version upgrades
    const requestBody = { host: address };
    if (isCrossVersion && upgradeInfo) {
        requestBody.version = 'v' + upgradeInfo.remoteVersion;
    }

    try {
        const response = await fetch('/api/plugin/fpp-plugin-watcher/remote/fpp/upgrade', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(requestBody),
            signal: state.abortController.signal
        });

        if (!response.ok) throw new Error(`HTTP error: ${response.status}`);

        const reader = response.body.getReader();
        const decoder = new TextDecoder();

        while (true) {
            const { done, value } = await reader.read();
            if (done) break;
            logEl.textContent += decoder.decode(value, { stream: true });
            logEl.scrollTop = logEl.scrollHeight;
        }

        logEl.textContent += '\n\n=== Upgrade complete ===';

        // Auto-reboot after cross-version upgrade
        if (isCrossVersion) {
            logEl.textContent += '\n\n=== Initiating reboot for cross-version upgrade ===';
            updateFPPStatus(address, 'success', 'sync fa-spin', 'Rebooting...');

            try {
                const rebootUrl = address === 'localhost' || address === '127.0.0.1'
                    ? '/api/system/reboot'
                    : `http://${address}/api/system/reboot`;
                await fetch(rebootUrl, { method: 'GET', mode: 'no-cors' });
                logEl.textContent += '\nReboot command sent. System will restart shortly.';
            } catch (rebootErr) {
                // Reboot may cause connection loss - this is expected
                logEl.textContent += '\nReboot initiated (connection closed as expected).';
            }
            updateFPPStatus(address, 'success', 'check', 'Rebooting');
        } else {
            updateFPPStatus(address, 'success', 'check', 'Complete');
        }
    } catch (error) {
        if (error.name === 'AbortError') {
            logEl.textContent += '\n\n=== Upgrade cancelled ===';
            updateFPPStatus(address, 'error', 'ban', 'Cancelled');
        } else {
            logEl.textContent += `\n\n=== ERROR: ${error.message} ===`;
            updateFPPStatus(address, 'error', 'times', 'Failed');
            // Auto-expand on error
            state.expanded = true;
            item?.classList.add('expanded');
        }
    } finally {
        state.abortController = null;
        updateFPPSummary();
    }
}

async function startAllFPPUpgrades() {
    if (fppUpgradeIsRunning) return;

    // Get selected hosts
    const selectedHosts = [];
    fppUpgradeStates.forEach((state, address) => {
        if (state.status === 'pending' && state.selected) {
            selectedHosts.push(address);
        }
    });

    if (selectedHosts.length === 0) return;

    fppUpgradeIsRunning = true;
    const startBtn = document.getElementById('fppUpgradeStartBtn');
    const closeBtn = document.getElementById('fppUpgradeCloseBtn');

    startBtn.disabled = true;
    startBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Upgrading...';
    closeBtn.disabled = true;

    // Disable checkboxes during upgrade
    fppUpgradeStates.forEach((state, address) => {
        const checkbox = document.getElementById(`fpp-check-${escapeId(address)}`);
        if (checkbox) checkbox.disabled = true;
    });

    // Expand first selected host
    if (selectedHosts[0]) expandFPPItem(selectedHosts[0]);

    // Start selected upgrades in parallel
    const upgradePromises = selectedHosts.map(address => startSingleFPPUpgrade(address));
    await Promise.allSettled(upgradePromises);

    fppUpgradeIsRunning = false;
    closeBtn.disabled = false;

    // Check if all are complete (no pending left)
    let hasPending = false;
    fppUpgradeStates.forEach(state => {
        if (state.status === 'pending') hasPending = true;
    });

    if (hasPending) {
        // Some hosts weren't selected, allow restarting
        startBtn.disabled = false;
        startBtn.innerHTML = '<i class="fas fa-play"></i> Start Selected';
        // Re-enable checkboxes for remaining pending hosts
        fppUpgradeStates.forEach((state, address) => {
            if (state.status === 'pending') {
                const checkbox = document.getElementById(`fpp-check-${escapeId(address)}`);
                if (checkbox) checkbox.disabled = false;
            }
        });
    } else {
        // All done - hide start button, make close green
        startBtn.style.display = 'none';
        closeBtn.classList.remove('btn-muted');
        closeBtn.classList.add('btn-success');
        closeBtn.innerHTML = '<i class="fas fa-check"></i> Done';
    }
    updateFPPSummary();
}

function closeFPPUpgradeModal() {
    // Abort all running upgrades
    fppUpgradeStates.forEach(state => {
        if (state.abortController) {
            state.abortController.abort();
        }
    });
    fppUpgradeStates.clear();
    fppUpgradeIsRunning = false;
    document.getElementById('fppUpgradeModal').style.display = 'none';
    refreshAllStatus();
}

function upgradeFPPSingle(address) {
    if (!hostsWithFPPUpdates.has(address)) { alert('No FPP update available.'); return; }
    showFPPUpgradeModal();
    setTimeout(() => expandFPPItem(address), 100);
}

// Individual upgrade button handlers for card UI
async function upgradeFPPCrossVersion(address) {
    const hostInfo = hostsWithFPPUpdates.get(address);
    if (!hostInfo || !hostInfo.crossVersion) {
        alert('No cross-version upgrade available for this host.');
        return;
    }

    // Pre-select cross-version type and open modal
    fppSelectedUpgradeType = 'crossVersion';
    showFPPUpgradeModal();
    setTimeout(() => expandFPPItem(address), 100);
}

async function upgradeFPPBranch(address) {
    const hostInfo = hostsWithFPPUpdates.get(address);
    if (!hostInfo || !hostInfo.branchUpdate) {
        alert('No branch update available for this host.');
        return;
    }

    // Pre-select branch update type and open modal
    fppSelectedUpgradeType = 'branchUpdate';
    showFPPUpgradeModal();
    setTimeout(() => expandFPPItem(address), 100);
}

// Auto-refresh every 30 seconds
setInterval(() => { if (!isRefreshing) refreshAllStatus(); }, 30000);

// Load on page ready
document.addEventListener('DOMContentLoaded', refreshAllStatus);
</script>
<?php endif; ?>
