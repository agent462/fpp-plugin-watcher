<?php
require_once __DIR__ . '/../classes/autoload.php';
require_once __DIR__ . '/../classes/Watcher/UI/ViewHelpers.php';
include_once __DIR__ . '/../lib/core/config.php';
include_once __DIR__ . '/../lib/core/watcherCommon.php';

use Watcher\Http\ApiClient;

$config = readPluginConfig();
$localSystem = ApiClient::getInstance()->get('http://127.0.0.1/api/fppd/status', 5) ?: [];
$access = checkDashboardAccess($config, $localSystem, 'controlUIEnabled');
$remoteSystems = $access['show'] ? getMultiSyncRemoteSystems() : [];
$localHostname = gethostname() ?: 'localhost';

renderCSSIncludes(false);
?>

<div class="metricsContainer" data-watcher-page="remoteControlUI">
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
            <button class="bulk-action-btn bulk-action-btn--connectivity" id="connectivityFailBtn" onclick="page.showBulkModal('connectivity')">
                <i class="fas fa-network-wired"></i> Connectivity Failed <span class="badge" id="connectivityFailCount">0</span>
            </button>
            <button class="bulk-action-btn bulk-action-btn--fpp" id="fppUpgradeAllBtn" onclick="page.showBulkModal('fpp')">
                <i class="fas fa-code-branch"></i> Upgrade FPP <span class="badge" id="fppUpgradeAllCount">0</span>
            </button>
            <button class="bulk-action-btn bulk-action-btn--upgrade" id="upgradeAllBtn" onclick="page.showBulkModal('upgrade')">
                <i class="fas fa-arrow-circle-up"></i> Upgrade All Watcher <span class="badge" id="upgradeAllCount">0</span>
            </button>
            <button class="bulk-action-btn bulk-action-btn--plugins" id="upgradeOtherPluginsBtn" onclick="page.showBulkModal('otherPlugins')">
                <i class="fas fa-puzzle-piece"></i> Upgrade Plugins <span class="badge" id="upgradeOtherPluginsCount">0</span>
            </button>
            <button class="bulk-action-btn bulk-action-btn--restart" id="restartAllBtn" onclick="page.showBulkModal('restart')">
                <i class="fas fa-sync"></i> Restart Required <span class="badge" id="restartAllCount">0</span>
            </button>
            <button class="buttons btn-outline-primary" onclick="page.refresh()" id="refreshBtn">
                <i class="fas fa-sync-alt"></i> Refresh All
            </button>
        </div>
    </div>

    <!-- Issues Banner -->
    <div class="issues-banner" id="issuesBanner">
        <div class="issues-banner__header">
            <div class="issues-banner__title">
                <i class="fas fa-exclamation-triangle"></i>
                Potential Issues
                <span class="issues-banner__count" id="issuesCount">0</span>
            </div>
            <button class="issues-banner__toggle" id="issuesToggle" onclick="page.toggleIssuesDetails()">
                <i class="fas fa-chevron-down"></i> Details
            </button>
        </div>
        <div class="issues-banner__body" id="issuesBody" style="display: none;">
            <div class="issues-banner__list" id="issuesList"></div>
        </div>
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
                            <input type="checkbox" id="testmode-localhost" onchange="page.toggleTestMode('localhost', this.checked)" disabled>
                            <span class="toggleSlider"></span>
                        </label>
                    </div>
                    <div class="actionRow multisync-test-row" id="multisync-test-row-localhost" style="display: none;">
                        <span class="actionLabel"><i class="fas fa-broadcast-tower"></i> Test Mode (MultiSync)</span>
                        <label class="toggleSwitch">
                            <input type="checkbox" id="testmode-multisync-localhost" onchange="page.toggleMultiSyncTestMode(this.checked)" disabled>
                            <span class="toggleSlider toggleSlider--multisync"></span>
                        </label>
                    </div>
                    <div class="connectivity-alert" id="connectivity-alert-localhost">
                        <div class="connectivity-alert-content">
                            <div class="connectivity-alert-icon"><i class="fas fa-exclamation-triangle"></i></div>
                            <div class="connectivity-alert-text">
                                <strong>Connectivity Check Failed</strong>
                                <span class="connectivity-alert-details" id="connectivity-details-localhost"></span>
                            </div>
                            <button class="connectivity-alert-btn" onclick="page.clearLocalResetState()" id="connectivity-clear-btn-localhost">
                                <i class="fas fa-redo"></i> Clear
                            </button>
                        </div>
                    </div>
                    <div class="updates-container" id="updates-container-localhost">
                        <div class="updates-header">
                            <i class="fas fa-arrow-circle-up"></i> Updates Available
                        </div>
                        <div class="update-row update-row--fpp update-row--major" id="fpp-major-row-localhost">
                            <div class="update-info">
                                <span class="update-name"><i class="fas fa-exclamation-triangle"></i> FPP Major Upgrade</span>
                                <span class="update-version" id="fpp-major-version-localhost"></span>
                            </div>
                            <span class="major-upgrade-note">Requires OS Upgrade</span>
                        </div>
                        <div class="update-row update-row--fpp" id="fpp-crossversion-row-localhost">
                            <div class="update-info">
                                <span class="update-name"><i class="fas fa-arrow-up"></i> FPP Upgrade</span>
                                <span class="update-version" id="fpp-crossversion-version-localhost"></span>
                            </div>
                            <button class="banner-btn" onclick="page.upgradeFPPCrossVersion('localhost')" id="fpp-crossversion-btn-localhost">
                                <i class="fas fa-download"></i> Upgrade
                            </button>
                        </div>
                        <div class="update-row update-row--fpp" id="fpp-branch-row-localhost">
                            <div class="update-info">
                                <span class="update-name"><i class="fas fa-code-branch"></i> FPP Branch</span>
                                <span class="update-version" id="fpp-branch-version-localhost"></span>
                            </div>
                            <button class="banner-btn" onclick="page.upgradeFPPBranch('localhost')" id="fpp-branch-btn-localhost">
                                <i class="fas fa-sync"></i> Update
                            </button>
                        </div>
                        <div id="upgrades-list-localhost"></div>
                    </div>
                    <div class="actionButtons">
                        <button class="actionBtn restart" onclick="page.restartLocalFppd()" id="restart-btn-localhost" disabled>
                            <i class="fas fa-redo"></i> Restart FPPD
                        </button>
                        <button class="actionBtn reboot" onclick="page.confirmLocalReboot()" id="reboot-btn-localhost" disabled>
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
                            <input type="checkbox" id="testmode-<?php echo htmlspecialchars($system['address']); ?>" onchange="page.toggleTestMode('<?php echo htmlspecialchars($system['address']); ?>', this.checked)" disabled>
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
                            <button class="connectivity-alert-btn" onclick="page.clearResetState('<?php echo htmlspecialchars($system['address']); ?>')" id="connectivity-clear-btn-<?php echo htmlspecialchars($system['address']); ?>">
                                <i class="fas fa-redo"></i> Clear
                            </button>
                        </div>
                    </div>
                    <div class="updates-container" id="updates-container-<?php echo htmlspecialchars($system['address']); ?>">
                        <div class="updates-header">
                            <i class="fas fa-arrow-circle-up"></i> Updates Available
                        </div>
                        <div class="update-row update-row--fpp update-row--major" id="fpp-major-row-<?php echo htmlspecialchars($system['address']); ?>">
                            <div class="update-info">
                                <span class="update-name"><i class="fas fa-exclamation-triangle"></i> FPP Major Upgrade</span>
                                <span class="update-version" id="fpp-major-version-<?php echo htmlspecialchars($system['address']); ?>"></span>
                            </div>
                            <span class="major-upgrade-note">Requires OS Upgrade</span>
                        </div>
                        <div class="update-row update-row--fpp" id="fpp-crossversion-row-<?php echo htmlspecialchars($system['address']); ?>">
                            <div class="update-info">
                                <span class="update-name"><i class="fas fa-arrow-up"></i> FPP Upgrade</span>
                                <span class="update-version" id="fpp-crossversion-version-<?php echo htmlspecialchars($system['address']); ?>"></span>
                            </div>
                            <button class="banner-btn" onclick="page.upgradeFPPCrossVersion('<?php echo htmlspecialchars($system['address']); ?>')" id="fpp-crossversion-btn-<?php echo htmlspecialchars($system['address']); ?>">
                                <i class="fas fa-download"></i> Upgrade
                            </button>
                        </div>
                        <div class="update-row update-row--fpp" id="fpp-branch-row-<?php echo htmlspecialchars($system['address']); ?>">
                            <div class="update-info">
                                <span class="update-name"><i class="fas fa-code-branch"></i> FPP Branch</span>
                                <span class="update-version" id="fpp-branch-version-<?php echo htmlspecialchars($system['address']); ?>"></span>
                            </div>
                            <button class="banner-btn" onclick="page.upgradeFPPBranch('<?php echo htmlspecialchars($system['address']); ?>')" id="fpp-branch-btn-<?php echo htmlspecialchars($system['address']); ?>">
                                <i class="fas fa-sync"></i> Update
                            </button>
                        </div>
                        <div id="upgrades-list-<?php echo htmlspecialchars($system['address']); ?>"></div>
                    </div>
                    <div class="actionButtons">
                        <button class="actionBtn restart" onclick="page.restartFppd('<?php echo htmlspecialchars($system['address']); ?>')" id="restart-btn-<?php echo htmlspecialchars($system['address']); ?>" disabled>
                            <i class="fas fa-redo"></i> Restart FPPD
                        </button>
                        <button class="actionBtn reboot" onclick="page.confirmReboot('<?php echo htmlspecialchars($system['address']); ?>', '<?php echo htmlspecialchars($system['hostname']); ?>')" id="reboot-btn-<?php echo htmlspecialchars($system['address']); ?>" disabled>
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
                <button class="btn btn-muted" id="bulkModalCloseBtn" onclick="page.closeBulkModal()" disabled>Close</button>
            </div>
        </div>
    </div>

    <!-- Confirm Dialog -->
    <div id="confirmDialog" class="watcher-modal" style="display: none;">
        <div class="modal-content modal-content--sm">
            <h4><i class="fas fa-exclamation-triangle" style="color: #dc3545;"></i> Confirm Reboot</h4>
            <p id="confirmMessage">Are you sure you want to reboot this system?</p>
            <div class="modal-buttons">
                <button class="btn btn-secondary" onclick="page.closeConfirmDialog()">Cancel</button>
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
                    <input type="radio" name="fppUpgradeType" value="crossVersion" onchange="page.switchFPPUpgradeType('crossVersion')">
                    <span class="fpp-upgrade-type-label"><i class="fas fa-arrow-up"></i> Version Upgrade</span>
                    <span class="fpp-upgrade-type-desc" id="fppCrossVersionDesc">Upgrade to latest version</span>
                    <span class="fpp-upgrade-type-count" id="fppCrossVersionCount">0</span>
                </label>
                <label class="fpp-upgrade-type-option">
                    <input type="radio" name="fppUpgradeType" value="branchUpdate" onchange="page.switchFPPUpgradeType('branchUpdate')">
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
                        <button onclick="page.fppSelectAll()"><i class="fas fa-check-square"></i> All</button>
                        <button onclick="page.fppSelectNone()"><i class="fas fa-square"></i> None</button>
                    </div>
                    <button onclick="page.fppExpandAll()"><i class="fas fa-chevron-down"></i> Expand</button>
                    <button onclick="page.fppCollapseAll()"><i class="fas fa-chevron-up"></i> Collapse</button>
                </div>
            </div>
            <div class="fpp-accordion" id="fppAccordion"></div>
            <div class="modal-buttons modal-buttons--center">
                <button class="btn btn--fixed btn-primary" id="fppUpgradeStartBtn" onclick="page.startAllFPPUpgrades()">
                    <i class="fas fa-play"></i> Start All
                </button>
                <button class="btn btn--fixed btn-muted" id="fppUpgradeCloseBtn" onclick="page.closeFPPUpgradeModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Watcher Upgrade Modal (with parallel streaming output) -->
    <div id="watcherUpgradeModal" class="watcher-modal watcher-modal--dark" style="display: none;">
        <div class="modal-content modal-content--lg">
            <h4><i class="fas fa-arrow-circle-up" style="color: #28a745;"></i> Upgrade Watcher Plugin</h4>
            <div class="fpp-upgrade-summary" id="watcherUpgradeSummary">
                <div class="fpp-upgrade-summary-text">
                    <strong id="watcherUpgradeCount">0</strong> systems ready to upgrade
                </div>
                <div class="fpp-upgrade-actions">
                    <div class="fpp-upgrade-selection">
                        <button onclick="page.watcherSelectAll()"><i class="fas fa-check-square"></i> All</button>
                        <button onclick="page.watcherSelectNone()"><i class="fas fa-square"></i> None</button>
                    </div>
                    <button onclick="page.watcherExpandAll()"><i class="fas fa-chevron-down"></i> Expand</button>
                    <button onclick="page.watcherCollapseAll()"><i class="fas fa-chevron-up"></i> Collapse</button>
                </div>
            </div>
            <div class="fpp-accordion" id="watcherAccordion"></div>
            <div class="modal-buttons modal-buttons--center">
                <button class="btn btn--fixed btn-primary" id="watcherUpgradeStartBtn" onclick="page.startAllWatcherUpgrades()">
                    <i class="fas fa-play"></i> Start All
                </button>
                <button class="btn btn--fixed btn-muted" id="watcherUpgradeCloseBtn" onclick="page.closeWatcherUpgradeModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Pass PHP config to JavaScript -->
    <script>
    window.watcherConfig = {
        remoteAddresses: <?php echo json_encode(array_column($remoteSystems, 'address'), JSON_HEX_TAG | JSON_HEX_AMP); ?>,
        remoteHostnames: <?php echo json_encode(array_combine(array_column($remoteSystems, 'address'), array_column($remoteSystems, 'hostname')), JSON_HEX_TAG | JSON_HEX_AMP); ?>,
        localHostname: <?php echo json_encode($localHostname, JSON_HEX_TAG | JSON_HEX_AMP); ?>
    };
    </script>

    <?php endif; ?>
    <?php endif; ?>
</div>

<?php renderWatcherJS(); ?>
