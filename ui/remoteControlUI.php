<?php
include_once __DIR__ . '/../lib/core/config.php';
include_once __DIR__ . '/../lib/core/watcherCommon.php';
include_once __DIR__ . '/../lib/ui/common.php';

$config = readPluginConfig();
$localSystem = apiCall('GET', 'http://127.0.0.1/api/fppd/status', [], true, 5) ?: [];
$access = checkDashboardAccess($config, $localSystem, 'controlUIEnabled');
$remoteSystems = $access['show'] ? getMultiSyncRemoteSystems() : [];
$localHostname = gethostname() ?: 'localhost';

renderCSSIncludes(false);
?>

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
            <button class="bulk-action-btn bulk-action-btn--plugins" id="upgradeOtherPluginsBtn" onclick="showBulkModal('otherPlugins')">
                <i class="fas fa-puzzle-piece"></i> Upgrade Plugins <span class="badge" id="upgradeOtherPluginsCount">0</span>
            </button>
            <button class="bulk-action-btn bulk-action-btn--restart" id="restartAllBtn" onclick="showBulkModal('restart')">
                <i class="fas fa-sync"></i> Restart Required <span class="badge" id="restartAllCount">0</span>
            </button>
            <button class="buttons btn-outline-primary" onclick="refreshAllStatus()" id="refreshBtn">
                <i class="fas fa-sync-alt"></i> Refresh All
            </button>
        </div>
    </div>

    <!-- Issues Banner -->
    <div class="issues-banner" id="issuesBanner">
        <div class="issues-banner__header">
            <div class="issues-banner__title">
                <i class="fas fa-exclamation-triangle"></i>
                Configuration Issues
                <span class="issues-banner__count" id="issuesCount">0</span>
            </div>
            <button class="issues-banner__toggle" id="issuesToggle" onclick="toggleIssuesDetails()">
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
                            <input type="checkbox" id="testmode-localhost" onchange="toggleTestMode('localhost', this.checked)" disabled>
                            <span class="toggleSlider"></span>
                        </label>
                    </div>
                    <div class="actionRow multisync-test-row" id="multisync-test-row-localhost" style="display: none;">
                        <span class="actionLabel"><i class="fas fa-broadcast-tower"></i> Test Mode (MultiSync)</span>
                        <label class="toggleSwitch">
                            <input type="checkbox" id="testmode-multisync-localhost" onchange="toggleMultiSyncTestMode(this.checked)" disabled>
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
                            <button class="connectivity-alert-btn" onclick="clearLocalResetState()" id="connectivity-clear-btn-localhost">
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
                        <button onclick="watcherSelectAll()"><i class="fas fa-check-square"></i> All</button>
                        <button onclick="watcherSelectNone()"><i class="fas fa-square"></i> None</button>
                    </div>
                    <button onclick="watcherExpandAll()"><i class="fas fa-chevron-down"></i> Expand</button>
                    <button onclick="watcherCollapseAll()"><i class="fas fa-chevron-up"></i> Collapse</button>
                </div>
            </div>
            <div class="fpp-accordion" id="watcherAccordion"></div>
            <div class="modal-buttons modal-buttons--center">
                <button class="btn btn--fixed btn-primary" id="watcherUpgradeStartBtn" onclick="startAllWatcherUpgrades()">
                    <i class="fas fa-play"></i> Start All
                </button>
                <button class="btn btn--fixed btn-muted" id="watcherUpgradeCloseBtn" onclick="closeWatcherUpgradeModal()">Close</button>
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
let hostsWithOtherPluginUpdates = new Map(); // Non-Watcher plugins with updates
let hostsNeedingRestart = new Map();
let hostsWithFPPUpdates = new Map();
let hostsWithConnectivityFailure = new Map();
let currentBulkType = null;
let latestFPPRelease = null; // Cached latest FPP release from GitHub

// =============================================================================
// Data Source Configuration (Optimized with Bulk Endpoints)
// =============================================================================
// Intervals in milliseconds. Bulk endpoints reduce API calls by ~90%.

const DATA_SOURCES = {
    // Bulk status: fppd/status + system/status + connectivity (all remotes)
    bulkStatus: { interval: 10000, lastFetch: 0 },
    // Bulk updates: watcher version + plugin updates (all remotes)
    bulkUpdates: { interval: 60000, lastFetch: 0 },
    // Local-only data sources
    localStatus: { interval: 10000, lastFetch: 0 },
    localSysStatus: { interval: 30000, lastFetch: 0 },
    localConnectivity: { interval: 30000, lastFetch: 0 },
    localVersion: { interval: 60000, lastFetch: 0 },
    localUpdates: { interval: 60000, lastFetch: 0 },
    // Global sources
    discrepancies: { interval: 60000, lastFetch: 0 },
    fppRelease: { interval: 60000, lastFetch: 0 }
};

// Cached data from bulk endpoints (keyed by address)
const bulkStatusCache = new Map();
const bulkUpdatesCache = new Map();

// Local host cache
const localCache = {
    status: null,
    testMode: null,
    sysStatus: null,
    connectivity: null,
    version: null,
    updates: []
};

function shouldFetch(source) {
    const now = Date.now();
    const config = DATA_SOURCES[source];
    return now - config.lastFetch >= config.interval;
}

function markFetched(source) {
    DATA_SOURCES[source].lastFetch = Date.now();
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
// Bulk Status Fetching (Optimized)
// =============================================================================

// Fetch bulk status for all remote hosts (status + sysStatus + connectivity)
async function fetchBulkStatus() {
    if (!shouldFetch('bulkStatus')) return;
    try {
        const response = await fetch('/api/plugin/fpp-plugin-watcher/remote/bulk/status');
        if (!response.ok) return;
        const data = await response.json();
        if (data.success && data.hosts) {
            for (const [address, hostData] of Object.entries(data.hosts)) {
                bulkStatusCache.set(address, hostData);
            }
        }
        markFetched('bulkStatus');
    } catch (e) {
        console.log('Failed to fetch bulk status:', e);
    }
}

// Fetch bulk updates for all remote hosts (version + plugin updates)
async function fetchBulkUpdates() {
    if (!shouldFetch('bulkUpdates')) return;
    try {
        const response = await fetch('/api/plugin/fpp-plugin-watcher/remote/bulk/updates');
        if (!response.ok) return;
        const data = await response.json();
        if (data.success && data.hosts) {
            for (const [address, hostData] of Object.entries(data.hosts)) {
                bulkUpdatesCache.set(address, hostData);
            }
        }
        markFetched('bulkUpdates');
    } catch (e) {
        console.log('Failed to fetch bulk updates:', e);
    }
}

// Fetch localhost status data
async function fetchLocalStatus() {
    try {
        // Fetch status and test mode (always at 10s interval)
        if (shouldFetch('localStatus')) {
            const [statusResponse, testModeResponse] = await Promise.all([
                fetch('/api/fppd/status'),
                fetch('/api/testmode')
            ]);
            if (statusResponse.ok) {
                const fppStatus = await statusResponse.json();
                localCache.status = {
                    platform: fppStatus.platform || '--',
                    branch: fppStatus.branch || '--',
                    mode_name: fppStatus.mode_name || '--',
                    status_name: fppStatus.status_name || 'idle',
                    rebootFlag: fppStatus.rebootFlag || 0,
                    restartFlag: fppStatus.restartFlag || 0
                };
            }
            if (testModeResponse.ok) {
                const testModeData = await testModeResponse.json();
                localCache.testMode = { enabled: testModeData.enabled ? 1 : 0 };
            } else {
                localCache.testMode = { enabled: 0 };
            }
            markFetched('localStatus');
        }

        // Fetch sysStatus (30s interval)
        if (shouldFetch('localSysStatus')) {
            const sysResponse = await fetch('/api/system/status');
            if (sysResponse.ok) {
                const sysData = await sysResponse.json();
                localCache.sysStatus = parseSystemStatus(sysData);
            }
            markFetched('localSysStatus');
        }

        // Fetch connectivity (30s interval)
        if (shouldFetch('localConnectivity')) {
            const connResponse = await fetch('/api/plugin/fpp-plugin-watcher/connectivity/state');
            if (connResponse.ok) {
                const connData = await connResponse.json();
                localCache.connectivity = (connData.success && connData.hasResetAdapter) ? connData : null;
            }
            markFetched('localConnectivity');
        }

        // Fetch version (60s interval)
        if (shouldFetch('localVersion')) {
            const versionResponse = await fetch('/api/plugin/fpp-plugin-watcher/version');
            if (versionResponse.ok) {
                const versionData = await versionResponse.json();
                localCache.version = versionData.version || null;
            }
            markFetched('localVersion');
        }

        // Fetch updates (60s interval)
        if (shouldFetch('localUpdates')) {
            const updatesResponse = await fetch('/api/plugin/fpp-plugin-watcher/plugins/updates');
            if (updatesResponse.ok) {
                const updatesData = await updatesResponse.json();
                localCache.updates = (updatesData.success && updatesData.updatesAvailable) ? updatesData.updatesAvailable : [];
            }
            markFetched('localUpdates');
        }
    } catch (e) {
        console.log('Failed to fetch local status:', e);
    }
}

// Build card data from cached bulk data for a remote host
function getRemoteCardData(address) {
    const statusData = bulkStatusCache.get(address);
    const updatesData = bulkUpdatesCache.get(address);

    // Check if host is offline
    if (!statusData || !statusData.success) {
        return { success: false, address, error: statusData?.error || 'No data available' };
    }

    // Parse sysStatus from bulk response
    const sysInfo = statusData.sysStatus ? parseSystemStatus(statusData.sysStatus) : {
        fppLocalVersion: null, fppRemoteVersion: null, diskUtilization: null,
        cpuUtilization: null, memoryUtilization: null, ipAddress: null
    };

    // Parse connectivity from bulk response
    const connectivityState = (statusData.connectivity && statusData.connectivity.hasResetAdapter)
        ? statusData.connectivity : null;

    return {
        success: true,
        address,
        status: statusData.status,
        testMode: statusData.testMode,
        watcherVersion: updatesData?.version || null,
        pluginUpdates: updatesData?.updates || [],
        connectivityState,
        ...sysInfo
    };
}

// Build card data for localhost from cache
function getLocalCardData() {
    if (!localCache.status) {
        return { success: false, address: 'localhost', error: 'No data available' };
    }

    return {
        success: true,
        address: 'localhost',
        status: localCache.status,
        testMode: localCache.testMode,
        watcherVersion: localCache.version,
        pluginUpdates: localCache.updates,
        connectivityState: localCache.connectivity,
        ...(localCache.sysStatus || {})
    };
}

// Legacy function for single-host refresh after actions (uses individual endpoints)
async function fetchSystemStatus(address) {
    const isLocal = address === 'localhost';
    try {
        if (isLocal) {
            // Force refresh all local data
            DATA_SOURCES.localStatus.lastFetch = 0;
            DATA_SOURCES.localSysStatus.lastFetch = 0;
            DATA_SOURCES.localConnectivity.lastFetch = 0;
            await fetchLocalStatus();
            return getLocalCardData();
        } else {
            // For remote, fetch directly (used after actions like restart)
            const [statusResponse, sysResponse, connResponse] = await Promise.all([
                fetch(`/api/plugin/fpp-plugin-watcher/remote/status?host=${encodeURIComponent(address)}`),
                fetch(`/api/plugin/fpp-plugin-watcher/remote/sysStatus?host=${encodeURIComponent(address)}`).catch(() => null),
                fetch(`/api/plugin/fpp-plugin-watcher/remote/connectivity/state?host=${encodeURIComponent(address)}`).catch(() => null)
            ]);

            if (!statusResponse.ok) return { success: false, address, error: 'Failed to fetch status' };
            const statusData = await statusResponse.json();
            if (!statusData.success) return { success: false, address, error: statusData.error || 'Failed' };

            let sysInfo = { fppLocalVersion: null, fppRemoteVersion: null, diskUtilization: null, cpuUtilization: null, memoryUtilization: null, ipAddress: null };
            if (sysResponse?.ok) {
                const sysData = await sysResponse.json();
                sysInfo = parseSystemStatus(sysData.data || sysData);
            }

            let connectivityState = null;
            if (connResponse?.ok) {
                const connData = await connResponse.json();
                connectivityState = (connData.success && connData.hasResetAdapter) ? connData : null;
            }

            // Get cached updates data
            const updatesData = bulkUpdatesCache.get(address);

            // Update bulk cache with fresh data
            bulkStatusCache.set(address, {
                success: true,
                status: statusData.status,
                testMode: statusData.testMode,
                sysStatus: sysInfo,
                connectivity: connectivityState
            });

            return {
                success: true,
                address,
                status: statusData.status,
                testMode: statusData.testMode,
                watcherVersion: updatesData?.version || null,
                pluginUpdates: updatesData?.updates || [],
                connectivityState,
                ...sysInfo
            };
        }
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
    // Separate rows for cross-version, branch updates, and major upgrades
    const fppMajorRow = document.getElementById(`fpp-major-row-${address}`);
    const fppMajorVersion = document.getElementById(`fpp-major-version-${address}`);
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
        fppMajorRow.classList.remove('visible');
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
    // Show playing/idle status (but not if testing since that's shown separately)
    const playbackStatus = status.status_name || 'idle';
    if (playbackStatus === 'playing') {
        indicators.push('<span class="status-indicator status-indicator--playing"><span class="dot"></span>Playing</span>');
    } else if (!isTestMode && playbackStatus === 'idle') {
        indicators.push('<span class="status-indicator status-indicator--idle"><span class="dot"></span>Idle</span>');
    }
    if (hasConnectivityFailure) indicators.push('<span class="status-indicator status-indicator--connectivity"><span class="dot"></span>Conn. Failed</span>');
    if (hasHighCpu) indicators.push(`<span class="status-indicator status-indicator--high-cpu"><span class="dot"></span>High CPU (${cpuUtilization}%)</span>`);
    if (hasLowMemory) indicators.push(`<span class="status-indicator status-indicator--low-memory"><span class="dot"></span>Low Memory (${memoryUtilization}%)</span>`);
    if (hasLowStorage) indicators.push(`<span class="status-indicator status-indicator--low-storage"><span class="dot"></span>Low Storage (${diskUtilization}%)</span>`);
    if (isTestMode) indicators.push('<span class="status-indicator status-indicator--testing"><span class="dot"></span>Test Mode</span>');
    // Show both indicators when both updates are available
    if (crossVersionUpgrade && crossVersionUpgrade.available) {
        if (crossVersionUpgrade.isMajorUpgrade) {
            // Major upgrade - show as info/warning, not actionable
            indicators.push(`<span class="status-indicator status-indicator--major-upgrade" title="Major version upgrade requires OS Upgrade"><span class="dot"></span>FPP v${crossVersionUpgrade.latestVersion}</span>`);
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
            versionNotes.push(`<span class="version-major" title="Major version upgrade requires OS Upgrade">v${crossVersionUpgrade.latestVersion} Upgrade</span>`);
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

    // Show/hide MultiSync test mode toggle for localhost when in player mode
    // MultiSync toggle is independent - it's a broadcast action, not synced to local test mode state
    if (address === 'localhost') {
        const multiSyncRow = document.getElementById('multisync-test-row-localhost');
        const multiSyncToggle = document.getElementById('testmode-multisync-localhost');
        const isPlayer = status.mode_name && status.mode_name.toLowerCase() === 'player';
        if (isPlayer) {
            multiSyncRow.style.display = 'flex';
            multiSyncToggle.disabled = false;
        } else {
            multiSyncRow.style.display = 'none';
            multiSyncToggle.disabled = true;
        }
    }

    // Track Watcher updates
    const watcherUpdate = pluginUpdates.find(p => p.repoName === 'fpp-plugin-watcher');
    if (watcherUpdate) {
        hostsWithWatcherUpdates.set(address, { hostname: getHostname(address), installedVersion: watcherUpdate.installedVersion, latestVersion: watcherUpdate.latestVersion });
    } else {
        hostsWithWatcherUpdates.delete(address);
    }
    updateBulkButton('upgradeAllBtn', 'upgradeAllCount', hostsWithWatcherUpdates);

    // Track non-Watcher plugin updates
    const otherPluginUpdates = pluginUpdates.filter(p => p.repoName !== 'fpp-plugin-watcher');
    if (otherPluginUpdates.length > 0) {
        hostsWithOtherPluginUpdates.set(address, { hostname: getHostname(address), plugins: otherPluginUpdates });
    } else {
        hostsWithOtherPluginUpdates.delete(address);
    }
    updateBulkButton('upgradeOtherPluginsBtn', 'upgradeOtherPluginsCount', hostsWithOtherPluginUpdates);

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
    // Major version upgrades (e.g., v9.x to v10.x) require OS Upgrade and cannot be done via upgrade
    const isMajorUpgrade = crossVersionUpgrade && crossVersionUpgrade.available && crossVersionUpgrade.isMajorUpgrade;
    const hasCrossVersionUpgrade = crossVersionUpgrade && crossVersionUpgrade.available && !isMajorUpgrade;

    // Show/hide major upgrade row (informational only - requires OS Upgrade)
    if (isMajorUpgrade) {
        fppMajorRow.classList.add('visible');
        fppMajorVersion.textContent = `v${crossVersionUpgrade.currentVersion} → v${crossVersionUpgrade.latestVersion}`;
    } else {
        fppMajorRow.classList.remove('visible');
    }

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
            // Use streaming modal for Watcher plugin, standard upgrade for others
            const onclickHandler = plugin.repoName === 'fpp-plugin-watcher'
                ? `upgradeWatcherSingle('${address}')`
                : `upgradePlugin('${address}', '${plugin.repoName}')`;
            html += `
                <div class="upgrade-item" id="upgrade-item-${address}-${plugin.repoName}">
                    <div class="update-info">
                        <span class="update-name">${plugin.name}</span>
                        <span class="update-version">${versionDisplay}</span>
                    </div>
                    <button class="banner-btn" onclick="${onclickHandler}" id="upgrade-btn-${address}-${plugin.repoName}">
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
        // Fetch FPP release info (60s interval)
        if (shouldFetch('fppRelease')) {
            await fetchLatestFPPRelease();
            markFetched('fppRelease');
        }

        // Fetch all data in parallel using bulk endpoints for remotes
        await Promise.all([
            fetchLocalStatus(),
            fetchBulkStatus(),
            fetchBulkUpdates(),
            fetchIssues().then(data => renderIssues(data))
        ]);

        // Update UI from cached data
        updateCardUI('localhost', getLocalCardData());
        for (const addr of remoteAddresses) {
            updateCardUI(addr, getRemoteCardData(addr));
        }

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
    const isLocal = address === 'localhost';
    const toggle = document.getElementById(`testmode-${address}`);
    toggle.disabled = true;

    try {
        // Get channel range
        let channelRange = "1-8388608";
        if (enable) {
            try {
                const infoUrl = isLocal ? '/api/system/info' : `/api/plugin/fpp-plugin-watcher/remote/sysInfo?host=${encodeURIComponent(address)}`;
                const infoResponse = await fetch(infoUrl);
                if (infoResponse.ok) {
                    const infoData = await infoResponse.json();
                    // Remote proxy wraps response in {success, data}, local returns data directly
                    const info = isLocal ? infoData : (infoData.data || infoData);
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

// MultiSync test mode - broadcasts to all sync'd systems (player mode only)
async function toggleMultiSyncTestMode(enable) {
    const toggle = document.getElementById('testmode-multisync-localhost');
    const localToggle = document.getElementById('testmode-localhost');
    toggle.disabled = true;

    try {
        // Get channel range from local system
        let channelRange = "1-8388608";
        if (enable) {
            try {
                const infoResponse = await fetch('/api/system/info');
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

        // Send command with multisyncCommand: true to broadcast to all sync'd systems
        const response = await fetch('/api/command', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ command, multisyncCommand: true, multisyncHosts: "", args })
        });
        if (!response.ok) throw new Error('Failed to toggle multisync test mode');

        // Update local toggle to match (since multisync affects local too)
        localToggle.checked = enable;

        // Refresh all cards after a short delay to show updated test mode status
        setTimeout(() => refreshAllStatus(), 1000);
    } catch (error) {
        toggle.checked = !enable;
        alert(`Failed to toggle multisync test mode: ${error.message}`);
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
    otherPlugins: {
        title: '<i class="fas fa-puzzle-piece" style="color: #17a2b8;"></i> Upgrading Plugins',
        getHosts: () => hostsWithOtherPluginUpdates,
        extraInfo: (info) => `<span style="font-size: 0.8em; color: #666;"> - ${info.plugins.map(p => p.name).join(', ')}</span>`,
        parallel: true,
        operation: async ([address, info], updateStatus) => {
            const plugins = info.plugins;
            for (let i = 0; i < plugins.length; i++) {
                const plugin = plugins[i];
                updateStatus('spinner fa-spin', `Upgrading ${plugin.name} (${i + 1}/${plugins.length})...`);
                const response = await fetch('/api/plugin/fpp-plugin-watcher/remote/upgrade', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ host: address, plugin: plugin.repoName })
                });
                const data = await response.json();
                if (!data.success) throw new Error(`Failed to upgrade ${plugin.name}: ${data.error}`);
            }
        },
        refreshDelay: 3000
    }
};

async function showBulkModal(type) {
    if (type === 'fpp') { showFPPUpgradeModal(); return; }
    if (type === 'upgrade') { showWatcherUpgradeModal(); return; }

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
            // Store upgrade info at the time modal is opened to prevent race conditions with background poll
            fppUpgradeStates.set(addr, { status: 'pending', abortController: null, expanded: false, selected: true, upgradeInfo: info });
            const versionDisplay = info.branch
                ? `${info.branch}: ${info.localVersion} → ${info.remoteVersion}`
                : `v${info.localVersion} → v${info.remoteVersion}`;
            html += `
                <div class="fpp-accordion-item" id="fpp-item-${safeId}" data-address="${addr}">
                    <div class="fpp-accordion-header" onclick="toggleFPPAccordion('${addr}')">
                        <input type="checkbox" class="fpp-accordion-checkbox" id="fpp-check-${safeId}" checked onclick="event.stopPropagation(); toggleFPPSelection('${addr}')">
                        <div class="fpp-accordion-toggle"><i class="fas fa-chevron-right"></i></div>
                        <div class="fpp-accordion-info">
                            <span class="fpp-accordion-hostname">${info.hostname}</span>
                            <span class="fpp-accordion-address">${addr}</span>
                            <span class="fpp-accordion-version">${versionDisplay}</span>
                        </div>
                        <div class="fpp-accordion-status pending" id="fpp-status-${safeId}" onclick="event.stopPropagation()">
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

    // Only auto-select if current selection has no available hosts (respect explicit user selection)
    const currentTypeHasHosts = (fppSelectedUpgradeType === 'crossVersion' && crossVersionCount > 0)
        || (fppSelectedUpgradeType === 'branchUpdate' && branchUpdateCount > 0);

    if (!currentTypeHasHosts) {
        // Current type invalid - auto-select first available (prefer crossVersion)
        if (crossVersionCount > 0) {
            fppSelectedUpgradeType = 'crossVersion';
        } else if (branchUpdateCount > 0) {
            fppSelectedUpgradeType = 'branchUpdate';
        }
    }
    // Update radio button to match selection
    document.querySelector(`input[name="fppUpgradeType"][value="${fppSelectedUpgradeType}"]`).checked = true;

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

    // Use upgrade info stored when modal was opened (prevents race conditions with background poll)
    const upgradeInfo = state.upgradeInfo;
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
        let fullOutput = '';

        while (true) {
            const { done, value } = await reader.read();
            if (done) break;
            const chunk = decoder.decode(value, { stream: true });
            fullOutput += chunk;
            logEl.textContent += chunk;
            logEl.scrollTop = logEl.scrollHeight;
        }

        // Check if output contains error marker from backend
        const hasError = fullOutput.includes('=== ERROR:');
        if (hasError) {
            updateFPPStatus(address, 'error', 'times', 'Failed');
            state.expanded = true;
            item?.classList.add('expanded');
            return;
        }

        logEl.textContent += '\n\n=== Upgrade complete ===';

        // Auto-reboot after cross-version upgrade
        if (isCrossVersion) {
            logEl.textContent += '\n\n=== Initiating reboot for cross-version upgrade ===';
            updateFPPStatus(address, 'success', 'sync fa-spin', 'Rebooting...');

            try {
                if (address === 'localhost' || address === '127.0.0.1') {
                    await fetch('/api/system/reboot', { method: 'GET' });
                } else {
                    await fetch('/api/plugin/fpp-plugin-watcher/remote/reboot', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ host: address })
                    });
                }
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
    // Invalidate version cache so banners update immediately after upgrade
    DATA_SOURCES.bulkUpdates.lastFetch = 0;
    DATA_SOURCES.localVersion.lastFetch = 0;
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

// =============================================================================
// Watcher Upgrade (Parallel Streaming Modal with Accordion)
// =============================================================================

let watcherUpgradeStates = new Map(); // address → {status, abortController, expanded, selected}
let watcherUpgradeIsRunning = false;

function buildWatcherAccordion() {
    const accordion = document.getElementById('watcherAccordion');

    // Reset state
    watcherUpgradeStates.clear();

    // Build accordion items from hostsWithWatcherUpdates
    let html = '';
    if (hostsWithWatcherUpdates.size === 0) {
        html = '<div style="text-align: center; padding: 2rem; color: #6c757d;"><i class="fas fa-info-circle"></i> No hosts need Watcher updates</div>';
    } else {
        hostsWithWatcherUpdates.forEach((info, addr) => {
            const safeId = escapeId(addr);
            watcherUpgradeStates.set(addr, { status: 'pending', abortController: null, expanded: false, selected: true });
            const versionDisplay = `${info.installedVersion || '?'} → ${info.latestVersion || '?'}`;
            html += `
                <div class="fpp-accordion-item" id="watcher-item-${safeId}" data-address="${addr}">
                    <div class="fpp-accordion-header" onclick="toggleWatcherAccordion('${addr}')">
                        <input type="checkbox" class="fpp-accordion-checkbox" id="watcher-check-${safeId}" checked onclick="event.stopPropagation(); toggleWatcherSelection('${addr}')">
                        <div class="fpp-accordion-toggle"><i class="fas fa-chevron-right"></i></div>
                        <div class="fpp-accordion-info">
                            <span class="fpp-accordion-hostname">${info.hostname}</span>
                            <span class="fpp-accordion-address">${addr}</span>
                            <span class="fpp-accordion-version">${versionDisplay}</span>
                        </div>
                        <div class="fpp-accordion-status pending" id="watcher-status-${safeId}" onclick="event.stopPropagation()">
                            <i class="fas fa-clock"></i> Pending
                        </div>
                    </div>
                    <div class="fpp-accordion-body">
                        <div class="fpp-accordion-log" id="watcher-log-${safeId}"></div>
                    </div>
                </div>`;
        });
    }

    accordion.innerHTML = html;
    updateWatcherSummary();
}

function showWatcherUpgradeModal() {
    if (hostsWithWatcherUpdates.size < 1) return;

    const modal = document.getElementById('watcherUpgradeModal');
    const startBtn = document.getElementById('watcherUpgradeStartBtn');
    const closeBtn = document.getElementById('watcherUpgradeCloseBtn');

    // Reset state
    watcherUpgradeIsRunning = false;

    // Build accordion
    buildWatcherAccordion();

    startBtn.disabled = false;
    startBtn.style.display = '';
    startBtn.innerHTML = '<i class="fas fa-play"></i> Start Selected';
    closeBtn.disabled = false;
    closeBtn.classList.remove('btn-success');
    closeBtn.classList.add('btn-muted');
    closeBtn.innerHTML = 'Close';
    modal.style.display = 'flex';
}

function toggleWatcherAccordion(address) {
    const safeId = escapeId(address);
    const item = document.getElementById(`watcher-item-${safeId}`);
    const state = watcherUpgradeStates.get(address);
    if (!item || !state) return;

    state.expanded = !state.expanded;
    item.classList.toggle('expanded', state.expanded);
}

function watcherExpandAll() {
    watcherUpgradeStates.forEach((state, address) => {
        state.expanded = true;
        document.getElementById(`watcher-item-${escapeId(address)}`)?.classList.add('expanded');
    });
}

function watcherCollapseAll() {
    watcherUpgradeStates.forEach((state, address) => {
        state.expanded = false;
        document.getElementById(`watcher-item-${escapeId(address)}`)?.classList.remove('expanded');
    });
}

function toggleWatcherSelection(address) {
    const safeId = escapeId(address);
    const checkbox = document.getElementById(`watcher-check-${safeId}`);
    const item = document.getElementById(`watcher-item-${safeId}`);
    const state = watcherUpgradeStates.get(address);
    if (!state || !checkbox || !item) return;

    state.selected = checkbox.checked;
    item.classList.toggle('excluded', !state.selected);
    updateWatcherSummary();
}

function watcherSelectAll() {
    watcherUpgradeStates.forEach((state, address) => {
        if (state.status === 'pending') {
            state.selected = true;
            const safeId = escapeId(address);
            const checkbox = document.getElementById(`watcher-check-${safeId}`);
            const item = document.getElementById(`watcher-item-${safeId}`);
            if (checkbox) checkbox.checked = true;
            item?.classList.remove('excluded');
        }
    });
    updateWatcherSummary();
}

function watcherSelectNone() {
    watcherUpgradeStates.forEach((state, address) => {
        if (state.status === 'pending') {
            state.selected = false;
            const safeId = escapeId(address);
            const checkbox = document.getElementById(`watcher-check-${safeId}`);
            const item = document.getElementById(`watcher-item-${safeId}`);
            if (checkbox) checkbox.checked = false;
            item?.classList.add('excluded');
        }
    });
    updateWatcherSummary();
}

function updateWatcherStatus(address, status, icon, text) {
    const safeId = escapeId(address);
    const statusEl = document.getElementById(`watcher-status-${safeId}`);
    const state = watcherUpgradeStates.get(address);
    if (statusEl && state) {
        state.status = status;
        statusEl.className = `fpp-accordion-status ${status}`;
        statusEl.innerHTML = `<i class="fas fa-${icon}"></i> ${text}`;
    }
}

function updateWatcherSummary() {
    const countEl = document.getElementById('watcherUpgradeCount');
    const startBtn = document.getElementById('watcherUpgradeStartBtn');
    let pending = 0, upgrading = 0, success = 0, error = 0, selected = 0;
    watcherUpgradeStates.forEach(state => {
        if (state.status === 'pending') {
            pending++;
            if (state.selected) selected++;
        }
        else if (state.status === 'upgrading') upgrading++;
        else if (state.status === 'success') success++;
        else if (state.status === 'error') error++;
    });
    const total = watcherUpgradeStates.size;
    if (upgrading > 0) {
        countEl.textContent = `${upgrading} upgrading, ${success + error} of ${total} complete`;
    } else if (success + error > 0) {
        countEl.textContent = `${success} succeeded, ${error} failed of ${total}`;
    } else {
        countEl.textContent = `${selected} of ${total} selected`;
    }
    // Disable start button if nothing selected
    if (startBtn && !watcherUpgradeIsRunning) {
        startBtn.disabled = selected === 0;
    }
}

async function startSingleWatcherUpgrade(address) {
    const safeId = escapeId(address);
    const logEl = document.getElementById(`watcher-log-${safeId}`);
    const item = document.getElementById(`watcher-item-${safeId}`);
    const state = watcherUpgradeStates.get(address);
    if (!logEl || !state) return;

    state.abortController = new AbortController();
    updateWatcherStatus(address, 'upgrading', 'spinner fa-spin', 'Upgrading...');
    updateWatcherSummary();
    logEl.textContent = '';

    try {
        const response = await fetch('/api/plugin/fpp-plugin-watcher/remote/watcher/upgrade', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ host: address }),
            signal: state.abortController.signal
        });

        if (!response.ok) throw new Error(`HTTP error: ${response.status}`);

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let fullOutput = '';

        while (true) {
            const { done, value } = await reader.read();
            if (done) break;
            const chunk = decoder.decode(value, { stream: true });
            fullOutput += chunk;
            logEl.textContent += chunk;
            logEl.scrollTop = logEl.scrollHeight;
        }

        // Check if output contains error marker from backend
        const hasError = fullOutput.includes('=== ERROR:');
        if (hasError) {
            updateWatcherStatus(address, 'error', 'times', 'Failed');
            state.expanded = true;
            item?.classList.add('expanded');
            return;
        }

        logEl.textContent += '\n\n=== Upgrade complete, restarting FPPD... ===';
        updateWatcherStatus(address, 'success', 'sync fa-spin', 'Restarting...');

        // Restart FPPD after successful upgrade
        try {
            await fetch('/api/plugin/fpp-plugin-watcher/remote/restart', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ host: address })
            });
            logEl.textContent += '\nFPPD restart initiated.';
        } catch (restartErr) {
            logEl.textContent += '\nFPPD restart may have failed: ' + restartErr.message;
        }

        updateWatcherStatus(address, 'success', 'check', 'Complete');
    } catch (error) {
        if (error.name === 'AbortError') {
            logEl.textContent += '\n\n=== Upgrade cancelled ===';
            updateWatcherStatus(address, 'error', 'ban', 'Cancelled');
        } else {
            logEl.textContent += `\n\n=== ERROR: ${error.message} ===`;
            updateWatcherStatus(address, 'error', 'times', 'Failed');
            // Auto-expand on error
            state.expanded = true;
            item?.classList.add('expanded');
        }
    } finally {
        state.abortController = null;
        updateWatcherSummary();
    }
}

async function startAllWatcherUpgrades() {
    if (watcherUpgradeIsRunning) return;

    // Get selected hosts
    const selectedHosts = [];
    watcherUpgradeStates.forEach((state, address) => {
        if (state.status === 'pending' && state.selected) {
            selectedHosts.push(address);
        }
    });

    if (selectedHosts.length === 0) return;

    watcherUpgradeIsRunning = true;
    const startBtn = document.getElementById('watcherUpgradeStartBtn');
    const closeBtn = document.getElementById('watcherUpgradeCloseBtn');

    startBtn.disabled = true;
    startBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Upgrading...';
    closeBtn.disabled = true;

    // Disable checkboxes during upgrade
    watcherUpgradeStates.forEach((state, address) => {
        const checkbox = document.getElementById(`watcher-check-${escapeId(address)}`);
        if (checkbox) checkbox.disabled = true;
    });

    // Start selected upgrades in parallel
    const upgradePromises = selectedHosts.map(address => startSingleWatcherUpgrade(address));
    await Promise.allSettled(upgradePromises);

    watcherUpgradeIsRunning = false;
    closeBtn.disabled = false;

    // Check if all are complete (no pending left)
    let hasPending = false;
    watcherUpgradeStates.forEach(state => {
        if (state.status === 'pending') hasPending = true;
    });

    if (hasPending) {
        // Some hosts weren't selected, allow restarting
        startBtn.disabled = false;
        startBtn.innerHTML = '<i class="fas fa-play"></i> Start Selected';
        // Re-enable checkboxes for remaining pending hosts
        watcherUpgradeStates.forEach((state, address) => {
            if (state.status === 'pending') {
                const checkbox = document.getElementById(`watcher-check-${escapeId(address)}`);
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
    updateWatcherSummary();
}

function closeWatcherUpgradeModal() {
    // Abort all running upgrades
    watcherUpgradeStates.forEach(state => {
        if (state.abortController) {
            state.abortController.abort();
        }
    });
    watcherUpgradeStates.clear();
    watcherUpgradeIsRunning = false;
    document.getElementById('watcherUpgradeModal').style.display = 'none';
    // Invalidate version cache so banners update immediately after upgrade
    DATA_SOURCES.bulkUpdates.lastFetch = 0;
    DATA_SOURCES.localVersion.lastFetch = 0;
    refreshAllStatus();
}

function expandWatcherItem(address) {
    const safeId = escapeId(address);
    const item = document.getElementById(`watcher-item-${safeId}`);
    const state = watcherUpgradeStates.get(address);
    if (item && state) {
        state.expanded = true;
        item.classList.add('expanded');
    }
}

function upgradeWatcherSingle(address) {
    if (!hostsWithWatcherUpdates.has(address)) { alert('No Watcher update available.'); return; }
    showWatcherUpgradeModal();
    setTimeout(() => expandWatcherItem(address), 100);
}

// =============================================================================
// Issues Banner
// =============================================================================

let issuesExpanded = false;
let cachedIssuesData = null;

async function fetchIssues() {
    // Use interval-based caching (60s)
    if (!shouldFetch('discrepancies')) {
        return cachedIssuesData;
    }

    try {
        const response = await fetch('/api/plugin/fpp-plugin-watcher/outputs/discrepancies');
        if (!response.ok) return cachedIssuesData;
        const data = await response.json();
        if (data.success) {
            cachedIssuesData = data;
            markFetched('discrepancies');
            return data;
        }
        return cachedIssuesData;
    } catch (e) {
        console.log('Failed to fetch issues:', e);
        return cachedIssuesData;
    }
}

function renderIssues(data) {
    const banner = document.getElementById('issuesBanner');
    const countEl = document.getElementById('issuesCount');
    const listEl = document.getElementById('issuesList');

    if (!data || !data.discrepancies || data.discrepancies.length === 0) {
        banner.classList.remove('visible');
        return;
    }

    const discrepancies = data.discrepancies;
    countEl.textContent = discrepancies.length;

    let html = '';
    discrepancies.forEach(d => {
        let icon, details = [];

        switch (d.type) {
            case 'channel_mismatch':
                icon = 'fa-not-equal';
                details.push(`<span><strong>Player:</strong> ${escapeHtml(d.playerRange)}</span>`);
                details.push(`<span><strong>Remote:</strong> ${escapeHtml(d.remoteRange)}</span>`);
                break;
            case 'output_to_remote':
                icon = 'fa-exclamation-triangle';
                if (d.description) details.push(`<span><strong>Name:</strong> ${escapeHtml(d.description)}</span>`);
                details.push(`<span><strong>Channels:</strong> ${d.startChannel}-${d.startChannel + d.channelCount - 1}</span>`);
                break;
            case 'inactive_output':
                icon = 'fa-info-circle';
                if (d.description) details.push(`<span><strong>Name:</strong> ${escapeHtml(d.description)}</span>`);
                details.push(`<span><strong>Channels:</strong> ${d.startChannel}-${d.startChannel + d.channelCount - 1}</span>`);
                break;
            case 'missing_sequences':
                icon = 'fa-file-audio';
                if (d.sequences && d.sequences.length > 0) {
                    const seqList = d.sequences.slice(0, 5).map(s => escapeHtml(s)).join(', ');
                    const more = d.sequences.length > 5 ? ` (+${d.sequences.length - 5} more)` : '';
                    details.push(`<span><strong>Missing:</strong> ${seqList}${more}</span>`);
                }
                break;
            default:
                icon = 'fa-question-circle';
        }

        html += `
            <div class="issues-item severity-${d.severity}">
                <div class="issues-item__icon"><i class="fas ${icon}"></i></div>
                <div class="issues-item__content">
                    <div class="issues-item__message">
                        ${escapeHtml(d.message)}
                    </div>
                    <div class="issues-item__details">
                        ${details.join('')}
                    </div>
                </div>
                <span class="issues-item__address">${escapeHtml(d.hostname || d.address)}</span>
            </div>`;
    });

    listEl.innerHTML = html;
    banner.classList.add('visible');
}

function toggleIssuesDetails() {
    const body = document.getElementById('issuesBody');
    const toggle = document.getElementById('issuesToggle');
    issuesExpanded = !issuesExpanded;

    if (issuesExpanded) {
        body.style.display = 'block';
        toggle.innerHTML = '<i class="fas fa-chevron-up"></i> Hide';
    } else {
        body.style.display = 'none';
        toggle.innerHTML = '<i class="fas fa-chevron-down"></i> Details';
    }
}

// Auto-refresh every 10 seconds (individual intervals control actual fetch frequency)
setInterval(() => { if (!isRefreshing) refreshAllStatus(); }, 10000);

// Load on page ready
document.addEventListener('DOMContentLoaded', refreshAllStatus);
</script>
<?php endif; ?>
