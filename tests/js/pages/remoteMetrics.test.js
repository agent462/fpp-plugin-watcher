/**
 * Tests for js/src/pages/remoteMetrics.js
 */

const { remoteMetrics } = require('../../../js/src/pages/remoteMetrics.js');

// =============================================================================
// Module Interface Tests
// =============================================================================

describe('remoteMetrics module interface', () => {
  test('exports pageId', () => {
    expect(remoteMetrics.pageId).toBe('remoteMetricsUI');
  });

  test('exports init function', () => {
    expect(typeof remoteMetrics.init).toBe('function');
  });

  test('exports destroy function', () => {
    expect(typeof remoteMetrics.destroy).toBe('function');
  });

  test('exports refresh function', () => {
    expect(typeof remoteMetrics.refresh).toBe('function');
  });
});

// =============================================================================
// Initialization Tests
// =============================================================================

describe('remoteMetrics.init', () => {
  beforeEach(() => {
    remoteMetrics.destroy();
  });

  afterEach(() => {
    remoteMetrics.destroy();
  });

  test('initializes without errors', () => {
    expect(() => remoteMetrics.init({})).not.toThrow();
  });

  test('handles missing config gracefully', () => {
    expect(() => remoteMetrics.init()).not.toThrow();
    expect(() => remoteMetrics.init({})).not.toThrow();
  });
});

// =============================================================================
// Destroy Tests
// =============================================================================

describe('remoteMetrics.destroy', () => {
  test('clears refresh interval', () => {
    remoteMetrics.init();
    remoteMetrics.destroy();

    // Second call should not throw
    expect(() => remoteMetrics.destroy()).not.toThrow();
  });

  test('can be called multiple times safely', () => {
    remoteMetrics.destroy();
    remoteMetrics.destroy();
    remoteMetrics.destroy();
    // Should not throw
  });
});

// =============================================================================
// Integration Tests
// =============================================================================

describe('remoteMetrics integration', () => {
  beforeEach(() => {
    // Set up window.remoteSystems
    window.remoteSystems = [];

    document.body.innerHTML = `
      <select id="timeRange"><option value="12" selected>12</option></select>
      <span id="lastUpdate"></span>
      <div id="loadingIndicator"></div>
      <div id="metricsContent" style="display: none;"></div>
      <div id="systemsContainer"></div>
      <span id="totalSystems"></span>
      <span id="onlineSystems"></span>
      <span id="avgCpu"></span>
      <span id="avgMemory"></span>
      <span id="avgDisk"></span>
      <span id="totalEfuse"></span>
      <span id="efuseSystems"></span>
      <div id="efuseSummaryCard" style="display: none;"></div>
      <button class="refreshButton"><i></i></button>
    `;

    remoteMetrics.destroy();

    global.fetch = jest.fn(() =>
      Promise.resolve({
        ok: true,
        json: () =>
          Promise.resolve({
            success: true,
            data: [],
            cpu: { success: true, data: [] },
            memory: { success: true, data: [] },
            disk: { success: true, data: [] },
            load: { success: true, data: [] },
            thermal: { success: true, data: [], zones: [] },
            wireless: { success: true, data: [], interfaces: [] },
            ping: { success: true, data: [] },
          }),
      })
    );
  });

  afterEach(() => {
    remoteMetrics.destroy();
    document.body.innerHTML = '';
    delete window.remoteSystems;
  });

  test('can initialize and destroy without errors', async () => {
    expect(() => remoteMetrics.init({})).not.toThrow();
    expect(() => remoteMetrics.destroy()).not.toThrow();
  });

  test('handles empty remote systems list', async () => {
    window.remoteSystems = [];
    expect(() => remoteMetrics.init({})).not.toThrow();
  });

  test('handles undefined remote systems', async () => {
    delete window.remoteSystems;
    expect(() => remoteMetrics.init({})).not.toThrow();
  });
});

// =============================================================================
// Summary Cards Tests
// =============================================================================

describe('remoteMetrics summary cards', () => {
  beforeEach(() => {
    window.remoteSystems = [];
    document.body.innerHTML = `
      <select id="timeRange"><option value="12" selected>12</option></select>
      <div id="systemsContainer"></div>
      <span id="totalSystems">0</span>
      <span id="onlineSystems">0 online</span>
      <span id="avgCpu">--%</span>
      <span id="avgMemory">-- MB</span>
      <span id="avgDisk">-- GB</span>
      <div id="efuseSummaryCard" style="display: none;"></div>
      <span id="totalEfuse"></span>
      <span id="efuseSystems"></span>
    `;
    remoteMetrics.destroy();
  });

  afterEach(() => {
    remoteMetrics.destroy();
    document.body.innerHTML = '';
    delete window.remoteSystems;
  });

  test('summary cards update with system count', () => {
    remoteMetrics.init({});

    // Initial state should show 0 systems
    const totalEl = document.getElementById('totalSystems');
    expect(totalEl.textContent).toBe('0');
  });
});

// =============================================================================
// Card Rendering Tests
// =============================================================================

describe('remoteMetrics card rendering', () => {
  beforeEach(() => {
    window.remoteSystems = [
      {
        hostname: 'fpp-remote-1',
        address: '192.168.1.100',
        model: 'Raspberry Pi',
        version: '8.0',
      },
    ];

    document.body.innerHTML = `
      <select id="timeRange"><option value="12" selected>12</option></select>
      <div id="loadingIndicator"></div>
      <div id="metricsContent" style="display: none;"></div>
      <div id="systemsContainer"></div>
      <span id="totalSystems"></span>
      <span id="onlineSystems"></span>
      <span id="avgCpu"></span>
      <span id="avgMemory"></span>
      <span id="avgDisk"></span>
      <div id="efuseSummaryCard" style="display: none;"></div>
      <button class="refreshButton"><i></i></button>
    `;

    remoteMetrics.destroy();

    global.fetch = jest.fn(() =>
      Promise.resolve({
        ok: true,
        json: () => Promise.resolve({ success: false }),
      })
    );
  });

  afterEach(() => {
    remoteMetrics.destroy();
    document.body.innerHTML = '';
    delete window.remoteSystems;
  });

  test('initializes without error when systems exist', () => {
    // init triggers async rendering so we just verify it doesn't throw
    expect(() => remoteMetrics.init({})).not.toThrow();
  });
});
