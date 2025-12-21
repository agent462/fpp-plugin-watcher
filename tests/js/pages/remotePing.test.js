/**
 * Tests for js/src/pages/remotePing.js
 */

const { remotePing } = require('../../../js/src/pages/remotePing.js');

// =============================================================================
// Module Interface Tests
// =============================================================================

describe('remotePing module interface', () => {
  test('exports pageId', () => {
    expect(remotePing.pageId).toBe('remotePingUI');
  });

  test('exports init function', () => {
    expect(typeof remotePing.init).toBe('function');
  });

  test('exports destroy function', () => {
    expect(typeof remotePing.destroy).toBe('function');
  });

  test('exports refresh function', () => {
    expect(typeof remotePing.refresh).toBe('function');
  });

  test('exports updateAllCharts function', () => {
    expect(typeof remotePing.updateAllCharts).toBe('function');
  });

  test('exports updateRawPingLatencyChart function', () => {
    expect(typeof remotePing.updateRawPingLatencyChart).toBe('function');
  });
});

// =============================================================================
// Initialization Tests
// =============================================================================

describe('remotePing.init', () => {
  beforeEach(() => {
    remotePing.destroy();
  });

  afterEach(() => {
    remotePing.destroy();
  });

  test('initializes without errors', () => {
    expect(() => remotePing.init({})).not.toThrow();
  });

  test('handles missing config gracefully', () => {
    expect(() => remotePing.init()).not.toThrow();
    expect(() => remotePing.init({})).not.toThrow();
  });
});

// =============================================================================
// Destroy Tests
// =============================================================================

describe('remotePing.destroy', () => {
  test('clears refresh interval', () => {
    remotePing.init();
    remotePing.destroy();

    // Second call should not throw
    expect(() => remotePing.destroy()).not.toThrow();
  });

  test('can be called multiple times safely', () => {
    remotePing.destroy();
    remotePing.destroy();
    remotePing.destroy();
    // Should not throw
  });
});

// =============================================================================
// Integration Tests
// =============================================================================

describe('remotePing integration', () => {
  beforeEach(() => {
    document.body.innerHTML = `
      <select id="timeRange"><option value="12" selected>12</option></select>
      <select id="rawTimeRange"><option value="12" selected>12</option></select>
      <span id="lastUpdate"></span>
      <div id="loadingIndicator"></div>
      <div id="metricsContent" style="display: none;"></div>
      <div id="noDataMessage" style="display: none;"></div>
      <div id="statsBarSection"></div>
      <div id="rollupChartsSection"></div>
      <div id="perHostStatsSection"></div>
      <div id="perHostStats"></div>
      <span id="latencyTierBadge"></span>
      <span id="successTierBadge"></span>
      <span id="hostsCount"></span>
      <span id="overallAvgLatency"></span>
      <span id="bestLatency"></span>
      <span id="worstLatency"></span>
      <span id="dataPoints"></span>
      <canvas id="latencyChart"></canvas>
      <canvas id="successChart"></canvas>
      <canvas id="rawPingLatencyChart"></canvas>
      <button class="refreshButton"><i></i></button>
    `;

    remotePing.destroy();

    global.fetch = jest.fn(() =>
      Promise.resolve({
        ok: true,
        json: () => Promise.resolve({ success: true, data: [] }),
      })
    );
  });

  afterEach(() => {
    remotePing.destroy();
    document.body.innerHTML = '';
  });

  test('can initialize and destroy without errors', async () => {
    expect(() => remotePing.init({})).not.toThrow();
    expect(() => remotePing.destroy()).not.toThrow();
  });
});
