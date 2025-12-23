<?php
/**
 * Unit tests for ViewHelpers class
 */

declare(strict_types=1);

namespace Watcher\Tests\Unit\UI;

use Watcher\Tests\TestCase;
use Watcher\UI\ViewHelpers;

class ViewHelpersTest extends TestCase
{
    // ========================================
    // checkDashboardAccess() Tests
    // ========================================

    public function testCheckDashboardAccessWhenEnabledAndPlayerMode(): void
    {
        $config = ['multiSyncEnabled' => true];
        $localSystem = ['mode_name' => 'player'];

        $result = ViewHelpers::checkDashboardAccess($config, $localSystem, 'multiSyncEnabled');

        $this->assertTrue($result['show']);
        $this->assertTrue($result['isEnabled']);
        $this->assertTrue($result['isPlayerMode']);
        $this->assertEquals('player', $result['modeName']);
    }

    public function testCheckDashboardAccessWhenDisabled(): void
    {
        $config = ['multiSyncEnabled' => false];
        $localSystem = ['mode_name' => 'player'];

        $result = ViewHelpers::checkDashboardAccess($config, $localSystem, 'multiSyncEnabled');

        $this->assertFalse($result['show']);
        $this->assertFalse($result['isEnabled']);
        $this->assertTrue($result['isPlayerMode']);
        $this->assertArrayHasKey('errorIcon', $result);
        $this->assertArrayHasKey('errorTitle', $result);
        $this->assertArrayHasKey('errorMessage', $result);
        $this->assertEquals('Feature Disabled', $result['errorTitle']);
    }

    public function testCheckDashboardAccessWhenNotPlayerMode(): void
    {
        $config = ['multiSyncEnabled' => true];
        $localSystem = ['mode_name' => 'remote'];

        $result = ViewHelpers::checkDashboardAccess($config, $localSystem, 'multiSyncEnabled');

        $this->assertFalse($result['show']);
        $this->assertTrue($result['isEnabled']);
        $this->assertFalse($result['isPlayerMode']);
        $this->assertEquals('Player Mode Required', $result['errorTitle']);
        $this->assertStringContainsString('remote', $result['errorMessage']);
    }

    public function testCheckDashboardAccessWhenDisabledAndNotPlayerMode(): void
    {
        $config = ['multiSyncEnabled' => false];
        $localSystem = ['mode_name' => 'remote'];

        $result = ViewHelpers::checkDashboardAccess($config, $localSystem, 'multiSyncEnabled');

        $this->assertFalse($result['show']);
        $this->assertFalse($result['isEnabled']);
        $this->assertFalse($result['isPlayerMode']);
        // Should show "Feature Disabled" error first (not "Player Mode Required")
        $this->assertEquals('Feature Disabled', $result['errorTitle']);
    }

    public function testCheckDashboardAccessWithMissingModeKey(): void
    {
        $config = ['multiSyncEnabled' => true];
        $localSystem = []; // No mode_name key

        $result = ViewHelpers::checkDashboardAccess($config, $localSystem, 'multiSyncEnabled');

        $this->assertFalse($result['show']);
        $this->assertEquals('unknown', $result['modeName']);
    }

    public function testCheckDashboardAccessWithMissingConfigKey(): void
    {
        $config = []; // multiSyncEnabled not set
        $localSystem = ['mode_name' => 'player'];

        $result = ViewHelpers::checkDashboardAccess($config, $localSystem, 'multiSyncEnabled');

        $this->assertFalse($result['show']);
        $this->assertFalse($result['isEnabled']);
    }

    public function testCheckDashboardAccessWithTruthyConfigValue(): void
    {
        $config = ['featureEnabled' => 1]; // Truthy but not true
        $localSystem = ['mode_name' => 'player'];

        $result = ViewHelpers::checkDashboardAccess($config, $localSystem, 'featureEnabled');

        $this->assertTrue($result['show']);
        $this->assertTrue($result['isEnabled']);
    }

    public function testCheckDashboardAccessWithStringConfigValue(): void
    {
        $config = ['featureEnabled' => 'yes']; // Truthy string
        $localSystem = ['mode_name' => 'player'];

        $result = ViewHelpers::checkDashboardAccess($config, $localSystem, 'featureEnabled');

        $this->assertTrue($result['show']);
    }

    public function testCheckDashboardAccessWithEmptyStringConfigValue(): void
    {
        $config = ['featureEnabled' => '']; // Falsy
        $localSystem = ['mode_name' => 'player'];

        $result = ViewHelpers::checkDashboardAccess($config, $localSystem, 'featureEnabled');

        $this->assertFalse($result['show']);
        $this->assertFalse($result['isEnabled']);
    }

    public function testCheckDashboardAccessWithZeroConfigValue(): void
    {
        $config = ['featureEnabled' => 0]; // Falsy
        $localSystem = ['mode_name' => 'player'];

        $result = ViewHelpers::checkDashboardAccess($config, $localSystem, 'featureEnabled');

        $this->assertFalse($result['show']);
    }

    // ========================================
    // renderAccessError() Tests
    // ========================================

    public function testRenderAccessErrorReturnsFalseWhenShowIsTrue(): void
    {
        $access = [
            'show' => true,
            'isEnabled' => true,
            'isPlayerMode' => true
        ];

        $result = ViewHelpers::renderAccessError($access);

        $this->assertFalse($result);
    }

    public function testRenderAccessErrorReturnsTrueWhenShowIsFalse(): void
    {
        $access = [
            'show' => false,
            'isEnabled' => false,
            'isPlayerMode' => true,
            'errorIcon' => 'fa-exclamation-circle',
            'errorTitle' => 'Test Error',
            'errorMessage' => 'Test message'
        ];

        ob_start();
        $result = ViewHelpers::renderAccessError($access);
        $output = ob_get_clean();

        $this->assertTrue($result);
        $this->assertStringContainsString('empty-message', $output);
        $this->assertStringContainsString('Test Error', $output);
    }

    // ========================================
    // renderEmptyMessage() Tests
    // ========================================

    public function testRenderEmptyMessageOutputsCorrectHtml(): void
    {
        ob_start();
        ViewHelpers::renderEmptyMessage('fa-info-circle', 'No Data', 'No data available');
        $output = ob_get_clean();

        $this->assertStringContainsString('class="empty-message"', $output);
        $this->assertStringContainsString('fa-info-circle', $output);
        $this->assertStringContainsString('No Data', $output);
        $this->assertStringContainsString('No data available', $output);
    }

    public function testRenderEmptyMessageEscapesTitleAndIcon(): void
    {
        ob_start();
        ViewHelpers::renderEmptyMessage('<script>', '<h1>XSS</h1>', 'Message');
        $output = ob_get_clean();

        $this->assertStringContainsString('&lt;script&gt;', $output);
        $this->assertStringContainsString('&lt;h1&gt;XSS&lt;/h1&gt;', $output);
    }

    public function testRenderEmptyMessageAllowsHtmlInMessage(): void
    {
        ob_start();
        ViewHelpers::renderEmptyMessage('fa-link', 'Click Here', '<a href="#">Link</a>');
        $output = ob_get_clean();

        // Message should contain unescaped HTML
        $this->assertStringContainsString('<a href="#">Link</a>', $output);
    }

    // ========================================
    // renderCSSIncludes() Tests
    // ========================================

    public function testRenderCSSIncludesOutputsBaseCss(): void
    {
        ob_start();
        ViewHelpers::renderCSSIncludes(false);
        $output = ob_get_clean();

        $this->assertStringContainsString('watcher.css', $output);
        $this->assertStringNotContainsString('chart.js', $output);
    }

    public function testRenderCSSIncludesWithChartJs(): void
    {
        ob_start();
        ViewHelpers::renderCSSIncludes(true);
        $output = ob_get_clean();

        $this->assertStringContainsString('chart.js', $output);
        $this->assertStringContainsString('chartjs-adapter-date-fns', $output);
    }

    // ========================================
    // renderTimeRangeSelector() Tests
    // ========================================

    public function testRenderTimeRangeSelectorOutputsSelect(): void
    {
        ob_start();
        ViewHelpers::renderTimeRangeSelector('timeRange', 'updateChart()', 'Time Range:', null, '12');
        $output = ob_get_clean();

        $this->assertStringContainsString('<select', $output);
        $this->assertStringContainsString('id="timeRange"', $output);
        $this->assertStringContainsString('onchange="updateChart()"', $output);
        $this->assertStringContainsString('Time Range:', $output);
    }

    public function testRenderTimeRangeSelectorUsesDefaultOptions(): void
    {
        ob_start();
        ViewHelpers::renderTimeRangeSelector('timeRange', 'updateChart()');
        $output = ob_get_clean();

        // Check for default options
        $this->assertStringContainsString('Last 1 Hour', $output);
        $this->assertStringContainsString('Last 6 Hours', $output);
        $this->assertStringContainsString('Last 12 Hours', $output);
        $this->assertStringContainsString('Last 24 Hours', $output);
        $this->assertStringContainsString('Last 7 Days', $output);
        $this->assertStringContainsString('Last 30 Days', $output);
        $this->assertStringContainsString('Last 90 Days', $output);
    }

    public function testRenderTimeRangeSelectorWithCustomOptions(): void
    {
        $customOptions = [
            '1' => 'One Hour',
            '2' => 'Two Hours',
            '3' => 'Three Hours'
        ];

        ob_start();
        ViewHelpers::renderTimeRangeSelector('customRange', 'handleChange()', 'Custom:', $customOptions, '2');
        $output = ob_get_clean();

        $this->assertStringContainsString('One Hour', $output);
        $this->assertStringContainsString('Two Hours', $output);
        $this->assertStringContainsString('Three Hours', $output);
        // Should NOT contain default options
        $this->assertStringNotContainsString('Last 24 Hours', $output);
    }

    public function testRenderTimeRangeSelectorMarksSelectedOption(): void
    {
        ob_start();
        ViewHelpers::renderTimeRangeSelector('timeRange', 'updateChart()', 'Time:', null, '24');
        $output = ob_get_clean();

        // The option with value 24 should be selected
        $this->assertMatchesRegularExpression('/value="24"[^>]*selected/', $output);
    }

    public function testRenderTimeRangeSelectorEscapesIdAndOnchange(): void
    {
        ob_start();
        ViewHelpers::renderTimeRangeSelector('<script>', 'alert("xss")', 'Label');
        $output = ob_get_clean();

        $this->assertStringContainsString('id="&lt;script&gt;"', $output);
        $this->assertStringContainsString('onchange="alert(&quot;xss&quot;)"', $output);
    }

    public function testRenderTimeRangeSelectorEscapesLabel(): void
    {
        ob_start();
        ViewHelpers::renderTimeRangeSelector('id', 'fn()', '<b>Bold</b>');
        $output = ob_get_clean();

        $this->assertStringContainsString('&lt;b&gt;Bold&lt;/b&gt;', $output);
    }

    public function testRenderTimeRangeSelectorEscapesOptionValues(): void
    {
        $options = [
            '1">' => 'Option with injection attempt'
        ];

        ob_start();
        ViewHelpers::renderTimeRangeSelector('id', 'fn()', 'Label:', $options);
        $output = ob_get_clean();

        $this->assertStringContainsString('value="1&quot;&gt;"', $output);
    }

    public function testRenderTimeRangeSelectorEscapesOptionLabels(): void
    {
        $options = [
            '1' => '<script>alert("xss")</script>'
        ];

        ob_start();
        ViewHelpers::renderTimeRangeSelector('id', 'fn()', 'Label:', $options);
        $output = ob_get_clean();

        $this->assertStringContainsString('&lt;script&gt;', $output);
    }

    // ========================================
    // renderLoadingSpinner() Tests
    // ========================================

    public function testRenderLoadingSpinnerOutputsDefaultMessage(): void
    {
        ob_start();
        ViewHelpers::renderLoadingSpinner();
        $output = ob_get_clean();

        $this->assertStringContainsString('class="loadingSpinner"', $output);
        $this->assertStringContainsString('id="loadingIndicator"', $output);
        $this->assertStringContainsString('fa-spinner', $output);
        $this->assertStringContainsString('Loading data...', $output);
    }

    public function testRenderLoadingSpinnerWithCustomMessage(): void
    {
        ob_start();
        ViewHelpers::renderLoadingSpinner('Loading metrics...');
        $output = ob_get_clean();

        $this->assertStringContainsString('Loading metrics...', $output);
    }

    public function testRenderLoadingSpinnerWithCustomId(): void
    {
        ob_start();
        ViewHelpers::renderLoadingSpinner('Loading...', 'customSpinner');
        $output = ob_get_clean();

        $this->assertStringContainsString('id="customSpinner"', $output);
    }

    public function testRenderLoadingSpinnerEscapesMessage(): void
    {
        ob_start();
        ViewHelpers::renderLoadingSpinner('<script>alert("xss")</script>');
        $output = ob_get_clean();

        $this->assertStringContainsString('&lt;script&gt;', $output);
        $this->assertStringNotContainsString('<script>alert', $output);
    }

    public function testRenderLoadingSpinnerEscapesId(): void
    {
        ob_start();
        ViewHelpers::renderLoadingSpinner('Loading', '"><script>');
        $output = ob_get_clean();

        $this->assertStringContainsString('id="&quot;&gt;&lt;script&gt;"', $output);
    }

    // ========================================
    // renderRefreshButton() Tests
    // ========================================

    public function testRenderRefreshButtonOutputsDefaultButton(): void
    {
        ob_start();
        ViewHelpers::renderRefreshButton();
        $output = ob_get_clean();

        $this->assertStringContainsString('class="refreshButton"', $output);
        $this->assertStringContainsString('onclick="page.refresh()"', $output);
        $this->assertStringContainsString('title="Refresh Data"', $output);
        $this->assertStringContainsString('fa-sync-alt', $output);
    }

    public function testRenderRefreshButtonWithCustomOnclick(): void
    {
        ob_start();
        ViewHelpers::renderRefreshButton('customRefresh()');
        $output = ob_get_clean();

        $this->assertStringContainsString('onclick="customRefresh()"', $output);
    }

    public function testRenderRefreshButtonWithCustomTitle(): void
    {
        ob_start();
        ViewHelpers::renderRefreshButton('page.refresh()', 'Reload Dashboard');
        $output = ob_get_clean();

        $this->assertStringContainsString('title="Reload Dashboard"', $output);
    }

    public function testRenderRefreshButtonEscapesOnclick(): void
    {
        ob_start();
        ViewHelpers::renderRefreshButton('alert("xss")');
        $output = ob_get_clean();

        $this->assertStringContainsString('onclick="alert(&quot;xss&quot;)"', $output);
    }

    public function testRenderRefreshButtonEscapesTitle(): void
    {
        ob_start();
        ViewHelpers::renderRefreshButton('fn()', '<script>XSS</script>');
        $output = ob_get_clean();

        $this->assertStringContainsString('title="&lt;script&gt;XSS&lt;/script&gt;"', $output);
    }

    // ========================================
    // Edge Cases
    // ========================================

    public function testCheckDashboardAccessWithVariousModenames(): void
    {
        $config = ['enabled' => true];

        $modes = ['player', 'remote', 'master', 'bridge', 'standalone', ''];

        foreach ($modes as $mode) {
            $localSystem = ['mode_name' => $mode];
            $result = ViewHelpers::checkDashboardAccess($config, $localSystem, 'enabled');

            if ($mode === 'player') {
                $this->assertTrue($result['isPlayerMode'], "Mode '$mode' should be player mode");
                $this->assertTrue($result['show'], "Mode '$mode' should show");
            } else {
                $this->assertFalse($result['isPlayerMode'], "Mode '$mode' should not be player mode");
                $this->assertFalse($result['show'], "Mode '$mode' should not show");
            }
        }
    }

    public function testRenderTimeRangeSelectorWithNumericStringKeys(): void
    {
        $options = [
            '1' => 'Hour',
            '24' => 'Day',
            '168' => 'Week'
        ];

        ob_start();
        ViewHelpers::renderTimeRangeSelector('id', 'fn()', 'Label:', $options, '1');
        $output = ob_get_clean();

        $this->assertStringContainsString('value="1"', $output);
        $this->assertStringContainsString('value="24"', $output);
        $this->assertStringContainsString('value="168"', $output);
    }
}
