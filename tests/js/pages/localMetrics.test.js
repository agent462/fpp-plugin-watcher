/**
 * Tests for js/src/pages/localMetrics.js
 */

const { localMetrics } = require('../../../js/src/pages/localMetrics.js');

// =============================================================================
// Module Interface Tests
// =============================================================================

describe('localMetrics module interface', () => {
  test('exports pageId', () => {
    expect(localMetrics.pageId).toBe('localMetricsUI');
  });

  test('exports init function', () => {
    expect(typeof localMetrics.init).toBe('function');
  });

  test('exports destroy function', () => {
    expect(typeof localMetrics.destroy).toBe('function');
  });

  test('exports refresh function', () => {
    expect(typeof localMetrics.refresh).toBe('function');
  });

  test('exports refreshMetric function', () => {
    expect(typeof localMetrics.refreshMetric).toBe('function');
  });

  test('exports updateAllCharts function', () => {
    expect(typeof localMetrics.updateAllCharts).toBe('function');
  });
});

// =============================================================================
// Initialization Tests
// =============================================================================

describe('localMetrics.init', () => {
  beforeEach(() => {
    // Clean up any previous state
    localMetrics.destroy();
  });

  afterEach(() => {
    localMetrics.destroy();
  });

  test('initializes without errors', () => {
    expect(() => localMetrics.init({ defaultAdapter: 'eth0' })).not.toThrow();
  });

  test('accepts config with defaultAdapter', () => {
    // Should not throw
    expect(() => localMetrics.init({ defaultAdapter: 'wlan0' })).not.toThrow();
  });

  test('handles missing config gracefully', () => {
    expect(() => localMetrics.init()).not.toThrow();
    expect(() => localMetrics.init({})).not.toThrow();
  });
});

// =============================================================================
// Destroy Tests
// =============================================================================

describe('localMetrics.destroy', () => {
  test('clears refresh interval', () => {
    localMetrics.init();
    localMetrics.destroy();

    // Second call should not throw
    expect(() => localMetrics.destroy()).not.toThrow();
  });

  test('can be called multiple times safely', () => {
    localMetrics.destroy();
    localMetrics.destroy();
    localMetrics.destroy();
    // Should not throw
  });
});

// =============================================================================
// Refresh Tests
// =============================================================================

describe('localMetrics.refresh', () => {
  beforeEach(() => {
    localMetrics.destroy();
    global.fetch = jest.fn(() =>
      Promise.resolve({
        ok: true,
        json: () => Promise.resolve({ success: true, data: [] }),
      })
    );
  });

  afterEach(() => {
    localMetrics.destroy();
  });

  test('refresh is callable', () => {
    expect(typeof localMetrics.refresh).toBe('function');
  });
});

// =============================================================================
// Integration Tests
// =============================================================================

describe('localMetrics integration', () => {
  beforeEach(() => {
    // Set up DOM elements
    document.body.innerHTML = `
      <select id="timeRange"><option value="12" selected>12</option></select>
      <select id="interfaceSelect"><option value="eth0">eth0</option></select>
      <span id="lastUpdate"></span>
      <div id="temperatureStatusBar"></div>
      <div id="diskStatusBar"></div>
      <div id="currentMemory"></div>
      <div id="avgMemory"></div>
      <div id="currentBufferCache"></div>
      <div id="avgBufferCache"></div>
      <div id="memoryLoading"></div>
      <div id="cpuLoading"></div>
      <div id="loadLoading"></div>
      <div id="diskLoading"></div>
      <div id="networkLoading"></div>
      <div id="thermalLoading"></div>
      <div id="wirelessLoading"></div>
      <div id="thermalCard"></div>
      <div id="wirelessCard"></div>
      <canvas id="memoryChart"></canvas>
      <canvas id="cpuChart"></canvas>
      <canvas id="loadChart"></canvas>
      <canvas id="diskChart"></canvas>
      <canvas id="networkChart"></canvas>
      <canvas id="thermalChart"></canvas>
      <canvas id="wirelessChart"></canvas>
      <button class="refreshButton"><i></i></button>
    `;

    localMetrics.destroy();

    global.fetch = jest.fn(() =>
      Promise.resolve({
        ok: true,
        json: () => Promise.resolve({ success: true, data: [] }),
      })
    );
  });

  afterEach(() => {
    localMetrics.destroy();
    document.body.innerHTML = '';
  });

  test('can initialize and destroy without errors', async () => {
    expect(() => localMetrics.init({ defaultAdapter: 'eth0' })).not.toThrow();
    expect(() => localMetrics.destroy()).not.toThrow();
  });

  test('refreshMetric handles unknown metric key', () => {
    localMetrics.init();
    // Should not throw for unknown key
    expect(() => localMetrics.refreshMetric('unknown')).not.toThrow();
  });
});
