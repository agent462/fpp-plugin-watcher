<?php
/**
 * Watcher Plugin - Common UI PHP Functions
 *
 * Shared utilities for UI pages to reduce duplication.
 *
 * HTML Escaping Convention:
 * - PHP: Use h() or htmlspecialchars() for all dynamic content in HTML output
 * - JavaScript: Use escapeHtml() from commonUI.js for all dynamic content in innerHTML
 */

/**
 * HTML escape helper - shorthand for htmlspecialchars with consistent flags
 * Mirrors escapeHtml() in commonUI.js for naming consistency
 * @param string|null $text - Text to escape
 * @return string - Escaped text safe for HTML output
 */
function h($text) {
    if ($text === null) return '';
    return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Render standard CSS includes for dashboard pages
 * @param bool $includeChartJs - Include Chart.js libraries
 */
function renderCSSIncludes($includeChartJs = false) {
    ?>
<link rel="stylesheet" href="/css/fpp-bootstrap/dist-new/fpp-bootstrap-5-3.css">
<link rel="stylesheet" href="/css/fpp.css">
<link rel="stylesheet" href="/plugin.php?plugin=fpp-plugin-watcher&file=css/commonUI.css&nopage=1">
<?php if ($includeChartJs): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
<?php endif;
}

/**
 * Render common JavaScript utilities
 */
function renderCommonJS() {
    ?>
<script src="/plugin.php?plugin=fpp-plugin-watcher&file=js/commonUI.js&nopage=1"></script>
<?php
}

/**
 * Render an empty/disabled state message
 * @param string $icon - Font Awesome icon class (without 'fas')
 * @param string $title - Message title
 * @param string $message - Message body (can contain HTML)
 */
function renderEmptyMessage($icon, $title, $message) {
    ?>
<div class="empty-message">
    <i class="fas <?php echo htmlspecialchars($icon); ?>"></i>
    <h3><?php echo htmlspecialchars($title); ?></h3>
    <p><?php echo $message; ?></p>
</div>
<?php
}

/**
 * Check if a dashboard feature should be displayed
 * Returns array with 'show', 'isEnabled', 'isPlayerMode', and optional 'error' message
 *
 * @param array $config - Plugin configuration
 * @param array $localSystem - Local system status from FPP API
 * @param string $enabledKey - Config key for feature enabled check
 * @return array
 */
function checkDashboardAccess($config, $localSystem, $enabledKey) {
    $isEnabled = !empty($config[$enabledKey]);
    $isPlayerMode = ($localSystem['mode_name'] ?? '') === 'player';

    $result = [
        'show' => $isEnabled && $isPlayerMode,
        'isEnabled' => $isEnabled,
        'isPlayerMode' => $isPlayerMode,
        'modeName' => $localSystem['mode_name'] ?? 'unknown'
    ];

    if (!$isEnabled) {
        $result['errorIcon'] = 'fa-exclamation-circle';
        $result['errorTitle'] = 'Feature Disabled';
        $result['errorMessage'] = 'This feature is not enabled. Go to <a href="plugin.php?plugin=fpp-plugin-watcher&page=configUI.php">Watcher Config</a> to enable it.';
    } elseif (!$isPlayerMode) {
        $result['errorIcon'] = 'fa-info-circle';
        $result['errorTitle'] = 'Player Mode Required';
        $result['errorMessage'] = 'This feature is only available when FPP is in Player mode. Current mode: ' . htmlspecialchars($result['modeName']);
    }

    return $result;
}

/**
 * Render the access error message if feature should not be displayed
 * @param array $access - Result from checkDashboardAccess
 * @return bool - True if error was rendered
 */
function renderAccessError($access) {
    if ($access['show']) {
        return false;
    }
    renderEmptyMessage($access['errorIcon'], $access['errorTitle'], $access['errorMessage']);
    return true;
}

/**
 * Render a loading spinner
 * @param string $id - Element ID
 * @param string $message - Loading message
 */
function renderLoadingSpinner($id, $message) {
    ?>
<div id="<?php echo htmlspecialchars($id); ?>" class="loadingSpinner">
    <i class="fas fa-spinner"></i>
    <p><?php echo htmlspecialchars($message); ?></p>
</div>
<?php
}

/**
 * Render a floating refresh button
 * @param string $onclick - JavaScript function to call
 */
function renderRefreshButton($onclick = 'loadAllMetrics()') {
    ?>
<button class="refreshButton" onclick="<?php echo htmlspecialchars($onclick); ?>" title="Refresh Data">
    <i class="fas fa-sync-alt"></i>
</button>
<?php
}

/**
 * Render a time range selector dropdown
 * @param string $id - Select element ID
 * @param string $onchange - JavaScript function to call
 * @param string $label - Label text
 * @param array $options - Array of [value => label] options
 * @param string $selected - Selected value
 */
function renderTimeRangeSelector($id, $onchange, $label = 'Time Range:', $options = null, $selected = '12') {
    if ($options === null) {
        $options = [
            '1' => 'Last 1 Hour',
            '6' => 'Last 6 Hours',
            '12' => 'Last 12 Hours',
            '24' => 'Last 24 Hours',
            '48' => 'Last 2 Days',
            '72' => 'Last 3 Days',
            '168' => 'Last 7 Days',
            '336' => 'Last 2 Weeks',
            '720' => 'Last 30 Days',
            '1440' => 'Last 60 Days',
            '2160' => 'Last 90 Days'
        ];
    }
    ?>
<div class="chartControls" style="margin-bottom: 1.5rem;">
    <div class="controlGroup">
        <label for="<?php echo htmlspecialchars($id); ?>"><?php echo htmlspecialchars($label); ?></label>
        <select id="<?php echo htmlspecialchars($id); ?>" onchange="<?php echo htmlspecialchars($onchange); ?>">
            <?php foreach ($options as $value => $optionLabel): ?>
            <option value="<?php echo htmlspecialchars($value); ?>"<?php echo $value == $selected ? ' selected' : ''; ?>>
                <?php echo htmlspecialchars($optionLabel); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
</div>
<?php
}
