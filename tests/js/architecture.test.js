/**
 * Architectural tests to enforce coding standards across the codebase
 *
 * These tests verify that common patterns are followed consistently
 * to prevent bugs like "Canvas is already in use" from Chart.js.
 */

const fs = require('fs');
const path = require('path');
const glob = require('glob');

const JS_SRC_DIR = path.join(__dirname, '../../js/src');

/**
 * Get all JavaScript source files in a directory
 * @param {string} dir - Directory to search
 * @returns {string[]} - Array of file paths
 */
function getJsFiles(dir) {
  return glob.sync(path.join(dir, '**/*.js'));
}

/**
 * Read file contents
 * @param {string} filePath - Path to file
 * @returns {string} - File contents
 */
function readFile(filePath) {
  return fs.readFileSync(filePath, 'utf8');
}

describe('Architecture: Chart.js usage patterns', () => {
  const pageFiles = getJsFiles(path.join(JS_SRC_DIR, 'pages'));
  const coreChartsFile = path.join(JS_SRC_DIR, 'core/charts.js');

  test('page modules must NOT use "new Chart" directly', () => {
    const violations = [];

    pageFiles.forEach(filePath => {
      const content = readFile(filePath);
      const relativePath = path.relative(JS_SRC_DIR, filePath);

      // Look for "new Chart(" pattern - this indicates direct Chart.js usage
      // which can cause "Canvas is already in use" errors
      const newChartMatch = content.match(/new\s+Chart\s*\(/g);
      if (newChartMatch) {
        violations.push({
          file: relativePath,
          issue: `Found ${newChartMatch.length} "new Chart(" call(s). Use updateOrCreateChart() from core/charts.js instead.`,
        });
      }
    });

    if (violations.length > 0) {
      const message = violations
        .map(v => `  - ${v.file}: ${v.issue}`)
        .join('\n');
      throw new Error(
        `Direct "new Chart" usage found in page modules!\n\n` +
        `This can cause "Canvas is already in use" errors during page re-initialization.\n` +
        `Use updateOrCreateChart() from core/charts.js which handles orphaned charts.\n\n` +
        `Violations:\n${message}`
      );
    }
  });

  test('core/charts.js should have updateOrCreateChart that uses Chart.getChart', () => {
    const content = readFile(coreChartsFile);

    // Verify updateOrCreateChart exists
    expect(content).toMatch(/export\s+function\s+updateOrCreateChart/);

    // Verify it calls Chart.getChart to check for existing charts
    expect(content).toMatch(/Chart\.getChart\s*\(/);

    // Verify it destroys existing charts
    expect(content).toMatch(/existingChart\.destroy\s*\(\)/);
  });

  test('page modules that CREATE charts should import updateOrCreateChart', () => {
    const violations = [];

    pageFiles.forEach(filePath => {
      const content = readFile(filePath);
      const relativePath = path.relative(JS_SRC_DIR, filePath);

      // Only check files that actually CREATE charts (call new Chart or updateOrCreateChart)
      // Skip files that just import/export chart functions or store chart references
      const createsCharts =
        content.includes('new Chart(') ||
        content.includes('updateOrCreateChart(');

      if (createsCharts) {
        // If creating charts, must use updateOrCreateChart, not new Chart
        const usesNewChart = content.includes('new Chart(');
        const usesHelper = content.includes('updateOrCreateChart');

        if (usesNewChart && !usesHelper) {
          violations.push({
            file: relativePath,
            issue: 'Creates charts with "new Chart" but should use updateOrCreateChart',
          });
        }
      }
    });

    if (violations.length > 0) {
      const message = violations
        .map(v => `  - ${v.file}: ${v.issue}`)
        .join('\n');
      throw new Error(
        `Chart-creating modules must use updateOrCreateChart:\n${message}`
      );
    }
  });

  test('page modules with charts should use state.charts object pattern', () => {
    const violations = [];

    pageFiles.forEach(filePath => {
      const content = readFile(filePath);
      const relativePath = path.relative(JS_SRC_DIR, filePath);

      // Skip files that don't use updateOrCreateChart
      if (!content.includes('updateOrCreateChart')) {
        return;
      }

      // Check that updateOrCreateChart is called with state.charts as first arg
      const calls = content.match(/updateOrCreateChart\s*\([^)]+\)/g) || [];
      calls.forEach(call => {
        if (!call.includes('state.charts')) {
          violations.push({
            file: relativePath,
            issue: `updateOrCreateChart should use state.charts: ${call.substring(0, 50)}...`,
          });
        }
      });
    });

    if (violations.length > 0) {
      const message = violations
        .map(v => `  - ${v.file}: ${v.issue}`)
        .join('\n');
      throw new Error(
        `updateOrCreateChart calls must use state.charts:\n${message}`
      );
    }
  });
});

describe('Architecture: State management patterns', () => {
  const pageFiles = getJsFiles(path.join(JS_SRC_DIR, 'pages'));

  test('state modules with charts property should have charts cleanup in resetState', () => {
    const violations = [];

    pageFiles.forEach(filePath => {
      const content = readFile(filePath);
      const relativePath = path.relative(JS_SRC_DIR, filePath);

      // Only check state files that define a charts property in state object
      const isStateFile = relativePath.includes('state.js') || relativePath.endsWith('state.js');
      const definesChartsProperty = content.match(/charts\s*:\s*\{\s*\}/);

      if (!isStateFile || !definesChartsProperty) return;

      // Should have resetState function with charts cleanup
      const hasResetState = content.includes('function resetState');
      const hasChartsCleanup =
        content.includes('state.charts') &&
        (content.includes('.destroy()') || content.includes('.destroy?.()'));

      if (hasResetState && !hasChartsCleanup) {
        violations.push({
          file: relativePath,
          issue: 'Has charts state but missing chart cleanup (destroy) in resetState',
        });
      }
    });

    if (violations.length > 0) {
      const message = violations
        .map(v => `  - ${v.file}: ${v.issue}`)
        .join('\n');
      throw new Error(
        `State files with charts must clean up charts on reset:\n${message}`
      );
    }
  });
});
