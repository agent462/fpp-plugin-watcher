/**
 * Tests for js/src/pages/connectivity.js
 */

const { connectivity } = require('../../../js/src/pages/connectivity.js');

// =============================================================================
// Module Interface Tests
// =============================================================================

describe('connectivity module interface', () => {
  test('exports pageId', () => {
    expect(connectivity.pageId).toBe('connectivityUI');
  });

  test('exports init function', () => {
    expect(typeof connectivity.init).toBe('function');
  });

  test('exports destroy function', () => {
    expect(typeof connectivity.destroy).toBe('function');
  });

  test('exports refresh function', () => {
    expect(typeof connectivity.refresh).toBe('function');
  });

  test('exports updateAllCharts function', () => {
    expect(typeof connectivity.updateAllCharts).toBe('function');
  });

  test('exports updateRawPingLatencyChart function', () => {
    expect(typeof connectivity.updateRawPingLatencyChart).toBe('function');
  });

  test('exports clearNetworkResetState function', () => {
    expect(typeof connectivity.clearNetworkResetState).toBe('function');
  });
});

// =============================================================================
// Initialization Tests
// =============================================================================

describe('connectivity.init', () => {
  beforeEach(() => {
    connectivity.destroy();
  });

  afterEach(() => {
    connectivity.destroy();
  });

  test('initializes without errors', () => {
    expect(() => connectivity.init({})).not.toThrow();
  });

  test('handles missing config gracefully', () => {
    expect(() => connectivity.init()).not.toThrow();
    expect(() => connectivity.init({})).not.toThrow();
  });
});

// =============================================================================
// Destroy Tests
// =============================================================================

describe('connectivity.destroy', () => {
  test('clears refresh interval', () => {
    connectivity.init();
    connectivity.destroy();

    // Second call should not throw
    expect(() => connectivity.destroy()).not.toThrow();
  });

  test('can be called multiple times safely', () => {
    connectivity.destroy();
    connectivity.destroy();
    connectivity.destroy();
    // Should not throw
  });
});

// =============================================================================
// Integration Tests
// =============================================================================

describe('connectivity integration', () => {
  beforeEach(() => {
    document.body.innerHTML = `
      <select id="timeRange"><option value="12" selected>12</option></select>
      <select id="rawTimeRange"><option value="12" selected>12</option></select>
      <span id="lastUpdate"></span>
      <div id="networkResetBanner" style="display: none;"></div>
      <div id="resetDetails"></div>
      <div id="loadingIndicator"></div>
      <div id="metricsContent" style="display: none;"></div>
      <div id="noDataMessage" style="display: none;"></div>
      <div id="statsBarSection"></div>
      <div id="rollupChartsSection"></div>
      <span id="latencyTierBadge"></span>
      <span id="rangeTierBadge"></span>
      <span id="sampleTierBadge"></span>
      <span id="currentLatency"></span>
      <span id="avgLatency"></span>
      <span id="minLatency"></span>
      <span id="maxLatency"></span>
      <span id="dataPoints"></span>
      <canvas id="latencyChart"></canvas>
      <canvas id="rangeChart"></canvas>
      <canvas id="sampleChart"></canvas>
      <canvas id="rawPingLatencyChart"></canvas>
      <button class="refreshButton"><i></i></button>
    `;

    connectivity.destroy();

    global.fetch = jest.fn(() =>
      Promise.resolve({
        ok: true,
        json: () => Promise.resolve({ success: true, data: [] }),
      })
    );
  });

  afterEach(() => {
    connectivity.destroy();
    document.body.innerHTML = '';
  });

  test('can initialize and destroy without errors', async () => {
    expect(() => connectivity.init({})).not.toThrow();
    expect(() => connectivity.destroy()).not.toThrow();
  });
});
