/**
 * Tests for multiSyncMetrics/index.js
 */

import { multiSyncMetrics } from '@/pages/multiSyncMetrics/index.js';
import { state, resetState } from '@/pages/multiSyncMetrics/state.js';

// Mock fetch globally
global.fetch = jest.fn(() =>
  Promise.resolve({
    ok: true,
    json: () => Promise.resolve({ success: true })
  })
);

describe('multiSyncMetrics/index', () => {
  beforeEach(() => {
    // Reset state before each test
    resetState();

    // Reset fetch mock
    global.fetch.mockClear();

    // Setup minimal DOM
    document.body.innerHTML = `
      <div class="msm-container" data-watcher-page="multiSyncMetricsUI">
        <div id="lastUpdate"></div>
        <div id="pluginError" style="display: none;">
          <div id="pluginErrorMessage"></div>
        </div>
        <div id="helpTooltip"></div>
        <button class="msm-help-btn"></button>
        <button class="msm-refresh-btn"><i class="fas fa-sync-alt"></i></button>
        <div id="systemHostname"></div>
        <div id="systemSequence"></div>
        <div id="systemStatus"></div>
        <div id="systemFrame"></div>
        <div id="systemTime"></div>
        <div id="systemStepTime"></div>
        <div id="systemPacketsReceived"></div>
        <div id="systemLastSync"></div>
        <div id="lcSeqOpen"></div>
        <div id="lcSeqStart"></div>
        <div id="lcSeqStop"></div>
        <div id="lcMediaOpen"></div>
        <div id="lcMediaStart"></div>
        <div id="lcMediaStop"></div>
        <div id="lcSyncPackets"></div>
        <div id="lcMediaPackets"></div>
        <div id="lcBlankPackets"></div>
        <div id="lcCmdPackets"></div>
        <div id="lcPluginPackets"></div>
      </div>
    `;
  });

  afterEach(() => {
    multiSyncMetrics.destroy();
  });

  describe('module interface', () => {
    test('has required pageId', () => {
      expect(multiSyncMetrics.pageId).toBe('multiSyncMetricsUI');
    });

    test('has init function', () => {
      expect(typeof multiSyncMetrics.init).toBe('function');
    });

    test('has destroy function', () => {
      expect(typeof multiSyncMetrics.destroy).toBe('function');
    });

    test('has refresh function', () => {
      expect(typeof multiSyncMetrics.refresh).toBe('function');
    });

    test('has resetMetrics function', () => {
      expect(typeof multiSyncMetrics.resetMetrics).toBe('function');
    });

    test('has toggleHelpTooltip function', () => {
      expect(typeof multiSyncMetrics.toggleHelpTooltip).toBe('function');
    });

    test('has loadQualityCharts function', () => {
      expect(typeof multiSyncMetrics.loadQualityCharts).toBe('function');
    });
  });

  describe('init', () => {
    test('initializes state with config', () => {
      const config = {
        isPlayerMode: true,
        isRemoteMode: false,
        remoteSystems: [],
        localHostname: 'TestHost'
      };

      multiSyncMetrics.init(config);

      expect(state.config.isPlayerMode).toBe(true);
      expect(state.config.localHostname).toBe('TestHost');
    });

    test('starts polling intervals', async () => {
      multiSyncMetrics.init({ isPlayerMode: true });

      // Wait a bit for async operations
      await new Promise(resolve => setTimeout(resolve, 100));

      expect(state.fastRefreshInterval).not.toBeNull();
      expect(state.slowRefreshInterval).not.toBeNull();
    });

    test('adds click outside handler', () => {
      const addEventListenerSpy = jest.spyOn(document, 'addEventListener');

      multiSyncMetrics.init({ isPlayerMode: true });

      expect(addEventListenerSpy).toHaveBeenCalledWith('click', expect.any(Function));

      addEventListenerSpy.mockRestore();
    });
  });

  describe('destroy', () => {
    test('removes click handler', () => {
      const removeEventListenerSpy = jest.spyOn(document, 'removeEventListener');

      multiSyncMetrics.init({ isPlayerMode: true });
      multiSyncMetrics.destroy();

      expect(removeEventListenerSpy).toHaveBeenCalledWith('click', expect.any(Function));

      removeEventListenerSpy.mockRestore();
    });

    test('clears intervals', () => {
      multiSyncMetrics.init({ isPlayerMode: true });

      expect(state.fastRefreshInterval).not.toBeNull();

      multiSyncMetrics.destroy();

      expect(state.fastRefreshInterval).toBeNull();
      expect(state.slowRefreshInterval).toBeNull();
    });
  });

  describe('toggleHelpTooltip', () => {
    test('toggles help tooltip visibility', () => {
      const tooltip = document.getElementById('helpTooltip');

      multiSyncMetrics.toggleHelpTooltip();
      expect(tooltip.classList.contains('show')).toBe(true);

      multiSyncMetrics.toggleHelpTooltip();
      expect(tooltip.classList.contains('show')).toBe(false);
    });
  });
});
