/**
 * Tests for multiSyncMetrics/state.js
 */

import {
  state,
  initState,
  resetState,
  isPlayerMode,
  isRemoteMode,
  getLocalHostname,
  isMultiSyncPingEnabled,
  FAST_POLL_INTERVAL,
  SLOW_POLL_INTERVAL,
  CONSECUTIVE_FAILURE_THRESHOLD
} from '@/pages/multiSyncMetrics/state.js';

describe('multiSyncMetrics/state', () => {
  afterEach(() => {
    // Reset state after each test
    resetState();
  });

  describe('constants', () => {
    test('FAST_POLL_INTERVAL is 2000ms', () => {
      expect(FAST_POLL_INTERVAL).toBe(2000);
    });

    test('SLOW_POLL_INTERVAL is 30000ms', () => {
      expect(SLOW_POLL_INTERVAL).toBe(30000);
    });

    test('CONSECUTIVE_FAILURE_THRESHOLD is 3', () => {
      expect(CONSECUTIVE_FAILURE_THRESHOLD).toBe(3);
    });
  });

  describe('initState', () => {
    test('initializes config with provided values', () => {
      initState({
        isPlayerMode: true,
        isRemoteMode: false,
        remoteSystems: [{ address: '192.168.1.100' }],
        localHostname: 'TestHost',
        multiSyncPingEnabled: true
      });

      expect(state.config.isPlayerMode).toBe(true);
      expect(state.config.isRemoteMode).toBe(false);
      expect(state.config.remoteSystems).toHaveLength(1);
      expect(state.config.localHostname).toBe('TestHost');
      expect(state.config.multiSyncPingEnabled).toBe(true);
    });

    test('uses default values for missing config', () => {
      initState({});

      expect(state.config.isPlayerMode).toBe(false);
      expect(state.config.isRemoteMode).toBe(false);
      expect(state.config.remoteSystems).toEqual([]);
      expect(state.config.localHostname).toBe('');
      expect(state.config.multiSyncPingEnabled).toBe(false);
    });
  });

  describe('resetState', () => {
    test('clears intervals', () => {
      const mockInterval = setInterval(() => {}, 1000);
      state.fastRefreshInterval = mockInterval;
      state.slowRefreshInterval = mockInterval;

      resetState();

      expect(state.fastRefreshInterval).toBeNull();
      expect(state.slowRefreshInterval).toBeNull();
    });

    test('destroys charts if present', () => {
      const mockChart1 = { destroy: jest.fn() };
      const mockChart2 = { destroy: jest.fn() };
      state.charts = { latencyJitter: mockChart1, packetLoss: mockChart2 };

      resetState();

      expect(mockChart1.destroy).toHaveBeenCalledTimes(1);
      expect(mockChart2.destroy).toHaveBeenCalledTimes(1);
      expect(state.charts).toEqual({});
    });

    test('resets cached data', () => {
      state.localStatus = { test: true };
      state.localFppStatus = { status: 'playing' };
      state.fppSystems = [{ hostname: 'test' }];
      state.systemsData = [{ isLocal: true }];
      state.sequenceMeta = { NumFrames: 1000 };
      state.lastSequenceName = 'test.fseq';
      state.clockDriftData = { '192.168.1.1': { drift_ms: 10 } };

      resetState();

      expect(state.localStatus).toBeNull();
      expect(state.localFppStatus).toBeNull();
      expect(state.fppSystems).toEqual([]);
      expect(state.systemsData).toEqual([]);
      expect(state.sequenceMeta).toBeNull();
      expect(state.lastSequenceName).toBeNull();
      expect(state.clockDriftData).toEqual({});
    });

    test('resets table sort state', () => {
      state.currentSort = { column: 'drift', direction: 'desc' };

      resetState();

      expect(state.currentSort).toEqual({ column: 'hostname', direction: 'asc' });
    });

    test('resets flags and tracking', () => {
      state.slowDataLoaded = true;
      state.lastPacketCount = 100;
      state.lastPacketTime = Date.now();
      state.packetRate = 5.5;
      state.consecutiveFailures = { '192.168.1.1': 2 };
      state.lastKnownGoodState = { '192.168.1.1': {} };

      resetState();

      expect(state.slowDataLoaded).toBe(false);
      expect(state.lastPacketCount).toBe(0);
      expect(state.lastPacketTime).toBeNull();
      expect(state.packetRate).toBe(0);
      expect(state.consecutiveFailures).toEqual({});
      expect(state.lastKnownGoodState).toEqual({});
    });
  });

  describe('isPlayerMode', () => {
    test('returns true when in player mode', () => {
      initState({ isPlayerMode: true });
      expect(isPlayerMode()).toBe(true);
    });

    test('returns false when not in player mode', () => {
      initState({ isPlayerMode: false });
      expect(isPlayerMode()).toBe(false);
    });
  });

  describe('isRemoteMode', () => {
    test('returns true when in remote mode', () => {
      initState({ isRemoteMode: true });
      expect(isRemoteMode()).toBe(true);
    });

    test('returns false when not in remote mode', () => {
      initState({ isRemoteMode: false });
      expect(isRemoteMode()).toBe(false);
    });
  });

  describe('getLocalHostname', () => {
    test('returns configured hostname', () => {
      initState({ localHostname: 'FPP-Player' });
      expect(getLocalHostname()).toBe('FPP-Player');
    });

    test('returns empty string when not configured', () => {
      initState({});
      expect(getLocalHostname()).toBe('');
    });
  });

  describe('isMultiSyncPingEnabled', () => {
    test('returns true when multiSyncPingEnabled is true', () => {
      initState({ multiSyncPingEnabled: true });
      expect(isMultiSyncPingEnabled()).toBe(true);
    });

    test('returns false when multiSyncPingEnabled is false', () => {
      initState({ multiSyncPingEnabled: false });
      expect(isMultiSyncPingEnabled()).toBe(false);
    });

    test('returns false when not configured', () => {
      initState({});
      expect(isMultiSyncPingEnabled()).toBe(false);
    });
  });

  describe('state object', () => {
    test('has expected default values', () => {
      expect(state.fastRefreshInterval).toBeNull();
      expect(state.slowRefreshInterval).toBeNull();
      expect(state.charts).toEqual({});
      expect(state.localStatus).toBeNull();
      expect(state.localFppStatus).toBeNull();
      expect(state.fppSystems).toEqual([]);
      expect(state.systemsData).toEqual([]);
      expect(state.sequenceMeta).toBeNull();
      expect(state.lastSequenceName).toBeNull();
      expect(state.clockDriftData).toEqual({});
      expect(state.currentSort).toEqual({ column: 'hostname', direction: 'asc' });
      expect(state.slowDataLoaded).toBe(false);
      expect(state.lastPacketCount).toBe(0);
      expect(state.lastPacketTime).toBeNull();
      expect(state.packetRate).toBe(0);
      expect(state.consecutiveFailures).toEqual({});
      expect(state.lastKnownGoodState).toEqual({});
    });
  });
});
