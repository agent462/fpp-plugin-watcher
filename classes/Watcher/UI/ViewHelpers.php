<?php
declare(strict_types=1);

namespace Watcher\UI {

/**
 * Watcher Plugin - Common UI PHP Functions
 *
 * Shared utilities for UI pages to reduce duplication.
 *
 * HTML Escaping Convention:
 * - PHP: Use h() or htmlspecialchars() for all dynamic content in HTML output
 * - JavaScript: Use watcher.utils.escapeHtml() or page module methods for all dynamic content in innerHTML
 */
class ViewHelpers
{
    /**
     * HTML escape helper - shorthand for htmlspecialchars with consistent flags
     * Mirrors watcher.utils.escapeHtml() for naming consistency
     *
     * @param string|null $text Text to escape
     * @return string Escaped text safe for HTML output
     */
    public static function h(?string $text): string
    {
        if ($text === null) return '';
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Render standard CSS includes for dashboard pages
     *
     * @param bool $includeChartJs Include Chart.js libraries
     */
    public static function renderCSSIncludes(bool $includeChartJs = false): void
    {
        ?>
<link rel="stylesheet" href="/plugin.php?plugin=fpp-plugin-watcher&file=css/watcher.css&nopage=1">
<?php if ($includeChartJs): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
<?php endif;
    }

    /**
     * Render the watcher.js bundle for pages using the new module architecture
     *
     * The watcher.js bundle contains all page modules and auto-initializes based on
     * the data-watcher-page attribute on the body or wrapper element.
     *
     * Usage in PHP:
     *   <div data-watcher-page="connectivityUI">
     *   <script>window.watcherConfig = { ... };</script>
     *   <?php ViewHelpers::renderWatcherJS(); ?>
     *
     * Note: FPP auto-loads ALL .js files in plugin directories, so we only
     * include watcher.min.js to avoid duplicate loading.
     */
    public static function renderWatcherJS(): void
    {
        // Commented out: FPP auto-loads js/watcher.min.js from plugin directory
        // ?>
<!-- <script src="/plugin.php?plugin=fpp-plugin-watcher&file=js/watcher.min.js&nopage=1"></script> -->
<?php
    }

    /**
     * Render an empty/disabled state message
     *
     * @param string $icon Font Awesome icon class (without 'fas')
     * @param string $title Message title
     * @param string $message Message body (can contain HTML)
     */
    public static function renderEmptyMessage(string $icon, string $title, string $message): void
    {
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
     * @param array $config Plugin configuration
     * @param array $localSystem Local system status from FPP API
     * @param string $enabledKey Config key for feature enabled check
     * @return array
     */
    public static function checkDashboardAccess(array $config, array $localSystem, string $enabledKey): array
    {
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
     *
     * @param array $access Result from checkDashboardAccess
     * @return bool True if error was rendered
     */
    public static function renderAccessError(array $access): bool
    {
        if ($access['show']) {
            return false;
        }
        self::renderEmptyMessage($access['errorIcon'], $access['errorTitle'], $access['errorMessage']);
        return true;
    }

    /**
     * Render a time range selector dropdown
     *
     * @param string $id Select element ID
     * @param string $onchange JavaScript function to call
     * @param string $label Label text
     * @param array|null $options Array of [value => label] options
     * @param string $selected Selected value
     */
    public static function renderTimeRangeSelector(
        string $id,
        string $onchange,
        string $label = 'Time Range:',
        ?array $options = null,
        string $selected = '12'
    ): void {
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
            <option value="<?php echo htmlspecialchars((string)$value); ?>"<?php echo (string)$value === $selected ? ' selected' : ''; ?>>
                <?php echo htmlspecialchars($optionLabel); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
</div>
<?php
    }
}

} // end namespace Watcher\UI

// Global namespace for backward compatibility function aliases
namespace {

if (!function_exists('h')) {
    function h(?string $text): string {
        return \Watcher\UI\ViewHelpers::h($text);
    }
}

if (!function_exists('renderCSSIncludes')) {
    function renderCSSIncludes(bool $includeChartJs = false): void {
        \Watcher\UI\ViewHelpers::renderCSSIncludes($includeChartJs);
    }
}

if (!function_exists('renderWatcherJS')) {
    function renderWatcherJS(): void {
        \Watcher\UI\ViewHelpers::renderWatcherJS();
    }
}

if (!function_exists('renderEmptyMessage')) {
    function renderEmptyMessage(string $icon, string $title, string $message): void {
        \Watcher\UI\ViewHelpers::renderEmptyMessage($icon, $title, $message);
    }
}

if (!function_exists('checkDashboardAccess')) {
    function checkDashboardAccess(array $config, array $localSystem, string $enabledKey): array {
        return \Watcher\UI\ViewHelpers::checkDashboardAccess($config, $localSystem, $enabledKey);
    }
}

if (!function_exists('renderAccessError')) {
    function renderAccessError(array $access): bool {
        return \Watcher\UI\ViewHelpers::renderAccessError($access);
    }
}

if (!function_exists('renderTimeRangeSelector')) {
    function renderTimeRangeSelector(
        string $id,
        string $onchange,
        string $label = 'Time Range:',
        ?array $options = null,
        string $selected = '12'
    ): void {
        \Watcher\UI\ViewHelpers::renderTimeRangeSelector($id, $onchange, $label, $options, $selected);
    }
}

} // end global namespace
