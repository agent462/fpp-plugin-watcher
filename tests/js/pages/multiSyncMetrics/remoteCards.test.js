/**
 * Tests for multiSyncMetrics/remoteCards.js
 */

import { applyConsecutiveFailureThreshold, renderRemoteCards } from '@/pages/multiSyncMetrics/remoteCards.js';
import { state, resetState, CONSECUTIVE_FAILURE_THRESHOLD } from '@/pages/multiSyncMetrics/state.js';

describe('multiSyncMetrics/remoteCards', () => {
  beforeEach(() => {
    resetState();
    document.body.innerHTML = '<div id="remotesGrid"></div>';
  });

  describe('applyConsecutiveFailureThreshold', () => {
    test('returns online remotes unchanged', () => {
      const remotes = [
        { address: '192.168.1.100', online: true, hostname: 'Remote1' }
      ];

      const result = applyConsecutiveFailureThreshold(remotes);

      expect(result[0].online).toBe(true);
      expect(state.consecutiveFailures['192.168.1.100']).toBe(0);
    });

    test('caches good state for online remotes', () => {
      const remotes = [
        { address: '192.168.1.100', online: true, hostname: 'Remote1', metrics: { test: 1 } }
      ];

      applyConsecutiveFailureThreshold(remotes);

      expect(state.lastKnownGoodState['192.168.1.100']).toBeDefined();
      expect(state.lastKnownGoodState['192.168.1.100'].hostname).toBe('Remote1');
    });

    test('increments failure counter for offline remotes', () => {
      const remotes = [
        { address: '192.168.1.100', online: false, hostname: 'Remote1' }
      ];

      applyConsecutiveFailureThreshold(remotes);
      expect(state.consecutiveFailures['192.168.1.100']).toBe(1);

      applyConsecutiveFailureThreshold(remotes);
      expect(state.consecutiveFailures['192.168.1.100']).toBe(2);
    });

    test('uses cached state before threshold', () => {
      // First, set up a cached good state
      state.lastKnownGoodState['192.168.1.100'] = {
        address: '192.168.1.100',
        online: true,
        hostname: 'Remote1',
        metrics: { test: 1 }
      };
      state.consecutiveFailures['192.168.1.100'] = 0;

      // Now simulate offline
      const remotes = [
        { address: '192.168.1.100', online: false, hostname: 'Remote1' }
      ];

      const result = applyConsecutiveFailureThreshold(remotes);

      // Should use cached state
      expect(result[0].online).toBe(true);
      expect(result[0]._staleSinceFailure).toBe(1);
    });

    test('shows offline after threshold reached', () => {
      // Set up cached good state
      state.lastKnownGoodState['192.168.1.100'] = {
        address: '192.168.1.100',
        online: true,
        hostname: 'Remote1'
      };

      // Simulate failures up to threshold
      for (let i = 0; i < CONSECUTIVE_FAILURE_THRESHOLD; i++) {
        state.consecutiveFailures['192.168.1.100'] = i;
        const remotes = [{ address: '192.168.1.100', online: false }];
        applyConsecutiveFailureThreshold(remotes);
      }

      // Should now show as offline
      const remotes = [{ address: '192.168.1.100', online: false, hostname: 'Remote1' }];
      const result = applyConsecutiveFailureThreshold(remotes);

      expect(result[0].online).toBe(false);
    });

    test('resets failure counter when remote comes back online', () => {
      state.consecutiveFailures['192.168.1.100'] = 2;

      const remotes = [
        { address: '192.168.1.100', online: true, hostname: 'Remote1' }
      ];

      applyConsecutiveFailureThreshold(remotes);

      expect(state.consecutiveFailures['192.168.1.100']).toBe(0);
    });
  });

  describe('renderRemoteCards', () => {
    test('renders empty state when no remotes', () => {
      renderRemoteCards([]);

      const grid = document.getElementById('remotesGrid');
      expect(grid.innerHTML).toContain('No remote systems found');
      expect(grid.innerHTML).toContain('fa-satellite-dish');
    });

    test('renders empty state when remotes is null', () => {
      renderRemoteCards(null);

      const grid = document.getElementById('remotesGrid');
      expect(grid.innerHTML).toContain('No remote systems found');
    });

    test('renders online remote card', () => {
      const remotes = [
        {
          address: '192.168.1.100',
          hostname: 'Remote1',
          online: true,
          pluginInstalled: true,
          metrics: { totalPacketsReceived: 1000 }
        }
      ];

      renderRemoteCards(remotes);

      const grid = document.getElementById('remotesGrid');
      expect(grid.innerHTML).toContain('Remote1');
      expect(grid.innerHTML).toContain('192.168.1.100');
      expect(grid.innerHTML).toContain('Online');
    });

    test('renders offline remote card', () => {
      const remotes = [
        {
          address: '192.168.1.100',
          hostname: 'Remote1',
          online: false,
          pluginInstalled: false
        }
      ];

      renderRemoteCards(remotes);

      const grid = document.getElementById('remotesGrid');
      expect(grid.innerHTML).toContain('Remote1');
      expect(grid.innerHTML).toContain('Offline');
      expect(grid.innerHTML).toContain('Unreachable');
    });

    test('renders no-plugin remote card', () => {
      const remotes = [
        {
          address: '192.168.1.100',
          hostname: 'Remote1',
          online: true,
          pluginInstalled: false
        }
      ];

      renderRemoteCards(remotes);

      const grid = document.getElementById('remotesGrid');
      expect(grid.innerHTML).toContain('No Plugin');
      expect(grid.innerHTML).toContain('Watcher plugin not installed');
    });

    test('adds critical class for high severity issues', () => {
      const remotes = [
        {
          address: '192.168.1.100',
          hostname: 'Remote1',
          online: true,
          pluginInstalled: true,
          maxSeverity: 3,
          issues: [{ type: 'drift', description: 'High drift' }]
        }
      ];

      renderRemoteCards(remotes);

      const grid = document.getElementById('remotesGrid');
      expect(grid.innerHTML).toContain('critical');
    });

    test('escapes HTML in hostname', () => {
      const remotes = [
        {
          address: '192.168.1.100',
          hostname: '<script>alert("xss")</script>',
          online: true,
          pluginInstalled: true
        }
      ];

      renderRemoteCards(remotes);

      const grid = document.getElementById('remotesGrid');
      expect(grid.innerHTML).not.toContain('<script>');
      expect(grid.innerHTML).toContain('&lt;script&gt;');
    });

    test('renders metrics for remote with plugin', () => {
      const remotes = [
        {
          address: '192.168.1.100',
          hostname: 'Remote1',
          online: true,
          pluginInstalled: true,
          metrics: {
            currentMasterSequence: 'test.fseq',
            sequencePlaying: true,
            totalPacketsReceived: 1234,
            millisecondsSinceLastSync: 500,
            avgFrameDrift: 2.5,
            maxFrameDrift: 5.0
          },
          fppStatus: {
            sequence: 'test',
            status: 'playing'
          }
        }
      ];

      renderRemoteCards(remotes);

      const grid = document.getElementById('remotesGrid');
      expect(grid.innerHTML).toContain('1,234');
      expect(grid.innerHTML).toContain('Playing');
    });

    test('renders issues for remote', () => {
      const remotes = [
        {
          address: '192.168.1.100',
          hostname: 'Remote1',
          online: true,
          pluginInstalled: true,
          maxSeverity: 2,
          issues: [{ type: 'drift', description: 'High frame drift detected' }]
        }
      ];

      renderRemoteCards(remotes);

      const grid = document.getElementById('remotesGrid');
      expect(grid.innerHTML).toContain('High frame drift detected');
      expect(grid.innerHTML).toContain('fa-exclamation-triangle');
    });

    test('filters out no_plugin issues from display', () => {
      const remotes = [
        {
          address: '192.168.1.100',
          hostname: 'Remote1',
          online: true,
          pluginInstalled: false,
          issues: [{ type: 'no_plugin', description: 'Plugin not installed' }]
        }
      ];

      renderRemoteCards(remotes);

      const grid = document.getElementById('remotesGrid');
      expect(grid.innerHTML).not.toContain('msm-remote-issues');
    });
  });
});
