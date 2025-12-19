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
    // h() HTML Escape Helper Tests
    // ========================================

    public function testHEscapesHtmlSpecialCharacters(): void
    {
        $input = '<script>alert("xss")</script>';
        $expected = '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;';

        $result = ViewHelpers::h($input);

        $this->assertEquals($expected, $result);
    }

    public function testHEscapesAmpersand(): void
    {
        $input = 'foo & bar';
        $expected = 'foo &amp; bar';

        $result = ViewHelpers::h($input);

        $this->assertEquals($expected, $result);
    }

    public function testHEscapesSingleQuotes(): void
    {
        $input = "it's a test";
        $expected = "it&apos;s a test"; // HTML5 uses &apos; instead of &#039;

        $result = ViewHelpers::h($input);

        $this->assertEquals($expected, $result);
    }

    public function testHEscapesDoubleQuotes(): void
    {
        $input = 'say "hello"';
        $expected = 'say &quot;hello&quot;';

        $result = ViewHelpers::h($input);

        $this->assertEquals($expected, $result);
    }

    public function testHReturnsEmptyStringForNull(): void
    {
        $result = ViewHelpers::h(null);

        $this->assertEquals('', $result);
    }

    public function testHReturnsEmptyStringForEmptyInput(): void
    {
        $result = ViewHelpers::h('');

        $this->assertEquals('', $result);
    }

    public function testHPreservesPlainText(): void
    {
        $input = 'Hello World 123';

        $result = ViewHelpers::h($input);

        $this->assertEquals($input, $result);
    }

    public function testHHandlesMultipleSpecialCharacters(): void
    {
        $input = '<div class="test" data-value=\'foo&bar\'>';
        $expected = '&lt;div class=&quot;test&quot; data-value=&apos;foo&amp;bar&apos;&gt;'; // HTML5 uses &apos;

        $result = ViewHelpers::h($input);

        $this->assertEquals($expected, $result);
    }

    public function testHHandlesUnicodeCharacters(): void
    {
        $input = 'Hello <World> with Unicode: ' . "\xC3\xA9\xC3\xA8\xC3\xA0"; // e with acute, grave, a with grave

        $result = ViewHelpers::h($input);

        $this->assertStringContainsString('&lt;World&gt;', $result);
        $this->assertStringContainsString("\xC3\xA9", $result); // Unicode preserved
    }

    // ========================================
    // Global h() Function Tests
    // ========================================

    public function testGlobalHFunctionExists(): void
    {
        $this->assertTrue(function_exists('h'));
    }

    public function testGlobalHFunctionDelegatesToViewHelpers(): void
    {
        $input = '<test>';

        $result = h($input);

        $this->assertEquals('&lt;test&gt;', $result);
    }

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

        $this->assertStringContainsString('fpp-bootstrap', $output);
        $this->assertStringContainsString('fpp.css', $output);
        $this->assertStringContainsString('commonUI.css', $output);
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
    // renderCommonJS() Tests
    // ========================================

    public function testRenderCommonJSOutputsScript(): void
    {
        ob_start();
        ViewHelpers::renderCommonJS();
        $output = ob_get_clean();

        $this->assertStringContainsString('<script', $output);
        $this->assertStringContainsString('commonUI.js', $output);
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
    // Global Function Aliases Tests
    // ========================================

    public function testGlobalRenderCSSIncludesFunctionExists(): void
    {
        $this->assertTrue(function_exists('renderCSSIncludes'));
    }

    public function testGlobalRenderCommonJSFunctionExists(): void
    {
        $this->assertTrue(function_exists('renderCommonJS'));
    }

    public function testGlobalRenderEmptyMessageFunctionExists(): void
    {
        $this->assertTrue(function_exists('renderEmptyMessage'));
    }

    public function testGlobalCheckDashboardAccessFunctionExists(): void
    {
        $this->assertTrue(function_exists('checkDashboardAccess'));
    }

    public function testGlobalRenderAccessErrorFunctionExists(): void
    {
        $this->assertTrue(function_exists('renderAccessError'));
    }

    public function testGlobalRenderTimeRangeSelectorFunctionExists(): void
    {
        $this->assertTrue(function_exists('renderTimeRangeSelector'));
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

    public function testHWithOnlyWhitespace(): void
    {
        $result = ViewHelpers::h('   ');

        $this->assertEquals('   ', $result);
    }

    public function testHWithNewlines(): void
    {
        $result = ViewHelpers::h("line1\nline2\rline3");

        $this->assertEquals("line1\nline2\rline3", $result);
    }

    public function testHWithTabs(): void
    {
        $result = ViewHelpers::h("col1\tcol2");

        $this->assertEquals("col1\tcol2", $result);
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
