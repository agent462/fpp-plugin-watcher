/**
 * Tests for js/efuseHeatmap.js
 *
 * Note: Many internal functions (downsampleData, getEfuseColor, formatCurrent, etc.)
 * are private within the IIFE and cannot be directly tested. This file tests the
 * exposed window functions and integration behavior.
 *
 * For comprehensive unit testing, consider refactoring to ES6 modules or exposing
 * utility functions for testing purposes.
 */

describe('efuseHeatmap.js', () => {
  beforeEach(() => {
    // Load commonUI first (dependency)
    loadScript('js/commonUI.js');

    // Set up required DOM structure
    document.body.innerHTML = `
      <div id="efuseGrid"></div>
      <div id="efuseHistoryChartsContainer"></div>
      <div id="portDetailPanel" style="display: none;">
        <span id="portDetailName"></span>
        <span id="portDetailCurrent"></span>
        <span id="portDetailExpected"></span>
        <span id="portDetailPeak"></span>
        <span id="portDetailAvg"></span>
        <div id="portOutputConfig"></div>
        <canvas id="portHistoryChart"></canvas>
      </div>
      <div id="totalHistoryCard" style="display: none;">
        <canvas id="totalHistoryChart"></canvas>
      </div>
      <div id="trippedBanner" style="display: none;">
        <span id="trippedCount"></span>
        <span id="trippedPortList"></span>
      </div>
      <button id="resetTrippedBtn" style="display: none;">
        <span class="badge"></span>
      </button>
      <span id="totalCurrent"></span>
      <span id="activePorts"></span>
      <span id="peakCurrent"></span>
      <span id="avgCurrent"></span>
      <span id="peakLabel"></span>
      <span id="avgLabel"></span>
      <span id="lastUpdate"></span>
      <select id="timeRange"><option value="24" selected>24</option></select>
      <div id="toastContainer"></div>
      <div id="confirmModal" style="display: none;">
        <span id="confirmModalTitle"></span>
        <span id="confirmModalMessage"></span>
        <button id="confirmModalAction"></button>
      </div>
      <div id="expectedHelpModal" style="display: none;"></div>
      <div id="pageHelpModal" style="display: none;"></div>
    `;

    // Set up efuseConfig
    window.efuseConfig = { ports: 16 };

    // Load efuseHeatmap.js
    loadScript('js/efuseHeatmap.js');
  });

  describe('initialization', () => {
    test('exposes initEfuseMonitor to window', () => {
      expect(typeof window.initEfuseMonitor).toBe('function');
    });

    test('exposes control functions to window', () => {
      expect(typeof window.togglePort).toBe('function');
      expect(typeof window.resetFuse).toBe('function');
      expect(typeof window.masterControl).toBe('function');
      expect(typeof window.resetAllTripped).toBe('function');
    });

    test('exposes UI functions to window', () => {
      expect(typeof window.showPortDetail).toBe('function');
      expect(typeof window.closePortDetail).toBe('function');
      expect(typeof window.showExpectedHelp).toBe('function');
      expect(typeof window.hideExpectedHelp).toBe('function');
    });
  });

  describe('closePortDetail', () => {
    test('hides the port detail panel', () => {
      const panel = document.getElementById('portDetailPanel');
      panel.style.display = 'block';

      window.closePortDetail();

      expect(panel.style.display).toBe('none');
    });
  });

  describe('showExpectedHelp / hideExpectedHelp', () => {
    test('showExpectedHelp displays modal', () => {
      const modal = document.getElementById('expectedHelpModal');
      window.showExpectedHelp();
      expect(modal.style.display).toBe('flex');
    });

    test('hideExpectedHelp hides modal', () => {
      const modal = document.getElementById('expectedHelpModal');
      modal.style.display = 'flex';
      window.hideExpectedHelp();
      expect(modal.style.display).toBe('none');
    });
  });

  describe('showPageHelp / hidePageHelp', () => {
    test('showPageHelp displays modal', () => {
      const modal = document.getElementById('pageHelpModal');
      window.showPageHelp();
      expect(modal.style.display).toBe('flex');
    });

    test('hidePageHelp hides modal', () => {
      const modal = document.getElementById('pageHelpModal');
      modal.style.display = 'flex';
      window.hidePageHelp();
      expect(modal.style.display).toBe('none');
    });
  });

  describe('hideConfirmModal', () => {
    test('hides the confirm modal', () => {
      const modal = document.getElementById('confirmModal');
      modal.style.display = 'flex';

      window.hideConfirmModal();

      expect(modal.style.display).toBe('none');
    });
  });

  describe('togglePort', () => {
    beforeEach(() => {
      jest.useFakeTimers();
    });

    afterEach(() => {
      jest.useRealTimers();
    });

    test('sends POST request to toggle endpoint', async () => {
      global.fetch.mockResolvedValueOnce({
        ok: true,
        json: () => Promise.resolve({ success: true, newState: 'off' })
      });

      await window.togglePort('Port 1', 'off');

      expect(global.fetch).toHaveBeenCalledWith(
        '/api/plugin/fpp-plugin-watcher/efuse/port/toggle',
        expect.objectContaining({
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ port: 'Port 1', state: 'off' })
        })
      );
    });

    test('rate limits rapid toggle calls', async () => {
      global.fetch.mockResolvedValue({
        ok: true,
        json: () => Promise.resolve({ success: true, newState: 'on' })
      });

      // First call should succeed
      await window.togglePort('Port 1', 'on');
      expect(global.fetch).toHaveBeenCalledTimes(1);

      // Immediate second call should be rate-limited
      await window.togglePort('Port 2', 'on');
      // The function returns early without calling fetch due to rate limiting
      // Toast shown instead
    });
  });

  describe('resetFuse', () => {
    beforeEach(() => {
      jest.useFakeTimers();
      // Advance time to ensure rate limiting doesn't affect test
      jest.advanceTimersByTime(1000);
    });

    afterEach(() => {
      jest.useRealTimers();
    });

    test('sends POST request to reset endpoint', async () => {
      global.fetch.mockResolvedValueOnce({
        ok: true,
        json: () => Promise.resolve({ success: true })
      });

      await window.resetFuse('Port 5');

      expect(global.fetch).toHaveBeenCalledWith(
        '/api/plugin/fpp-plugin-watcher/efuse/port/reset',
        expect.objectContaining({
          method: 'POST',
          body: JSON.stringify({ port: 'Port 5' })
        })
      );
    });
  });

  describe('masterControl', () => {
    beforeEach(() => {
      jest.useFakeTimers();
      jest.advanceTimersByTime(1000);
    });

    afterEach(() => {
      jest.useRealTimers();
    });

    test('shows confirmation for "off" state', async () => {
      const modal = document.getElementById('confirmModal');

      window.masterControl('off');

      expect(modal.style.display).toBe('flex');
      expect(document.getElementById('confirmModalTitle').textContent).toBe('Disable All Ports?');
    });

    test('directly calls API for "on" state', async () => {
      global.fetch.mockResolvedValueOnce({
        ok: true,
        json: () => Promise.resolve({ success: true })
      });

      await window.masterControl('on');

      expect(global.fetch).toHaveBeenCalledWith(
        '/api/plugin/fpp-plugin-watcher/efuse/ports/master',
        expect.objectContaining({
          method: 'POST',
          body: JSON.stringify({ state: 'on' })
        })
      );
    });
  });

  describe('portPowerClick', () => {
    beforeEach(() => {
      jest.useFakeTimers();
      jest.advanceTimersByTime(1000);

      global.fetch.mockResolvedValue({
        ok: true,
        json: () => Promise.resolve({ success: true, newState: 'on' })
      });
    });

    afterEach(() => {
      jest.useRealTimers();
    });

    test('calls resetFuse when port is tripped', async () => {
      global.fetch.mockResolvedValueOnce({
        ok: true,
        json: () => Promise.resolve({ success: true })
      });

      await window.portPowerClick('Port 1', true, true); // isEnabled=true, isTripped=true

      expect(global.fetch).toHaveBeenCalledWith(
        '/api/plugin/fpp-plugin-watcher/efuse/port/reset',
        expect.any(Object)
      );
    });

    test('calls togglePort when port is not tripped', async () => {
      jest.advanceTimersByTime(1000); // Reset rate limiting

      await window.portPowerClick('Port 1', true, false); // isEnabled=true, isTripped=false

      expect(global.fetch).toHaveBeenCalledWith(
        '/api/plugin/fpp-plugin-watcher/efuse/port/toggle',
        expect.objectContaining({
          body: JSON.stringify({ port: 'Port 1', state: 'off' })
        })
      );
    });
  });

  describe('toast notifications', () => {
    beforeEach(() => {
      jest.useFakeTimers();
    });

    afterEach(() => {
      jest.useRealTimers();
    });

    test('rate limit warning shows toast', async () => {
      // First call
      global.fetch.mockResolvedValueOnce({
        ok: true,
        json: () => Promise.resolve({ success: true, newState: 'on' })
      });
      await window.togglePort('Port 1', 'on');

      // Second immediate call should show toast
      await window.togglePort('Port 2', 'on');

      const container = document.getElementById('toastContainer');
      // Toast should be added
      expect(container.children.length).toBeGreaterThan(0);
    });
  });

  describe('guard against double-loading', () => {
    test('sets guard flag after loading', () => {
      // Script was loaded in beforeEach
      expect(window._efuseHeatmapLoaded).toBe(true);
    });

    test('does not reinitialize if guard flag is set', () => {
      // First load happened in beforeEach, set guard flag
      window._efuseHeatmapLoaded = true;
      const originalInit = window.initEfuseMonitor;

      // Try to load again (without resetting flag)
      const fs = require('fs');
      const path = require('path');
      const scriptContent = fs.readFileSync(
        path.resolve(__dirname, '../../js/efuseHeatmap.js'),
        'utf8'
      );
      eval(scriptContent);

      // Should be the same function reference since guard prevented re-init
      expect(window.initEfuseMonitor).toBe(originalInit);
    });
  });
});
