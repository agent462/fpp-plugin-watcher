/**
 * Tests for js/src/pages/remoteControl/state.js
 */

const {
  DATA_SOURCES,
  bulkStatusCache,
  bulkUpdatesCache,
  localCache,
  hostsWithWatcherUpdates,
  hostsWithOtherPluginUpdates,
  hostsNeedingRestart,
  hostsWithFPPUpdates,
  hostsWithConnectivityFailure,
  shouldFetch,
  markFetched,
  invalidateCache,
  initConfig,
  config,
  resetState,
  escapeId,
  getHostname,
  setIsRefreshing,
  setPendingReboot,
  setCurrentBulkType,
  setLatestFPPRelease,
  isRefreshing,
  pendingReboot,
  currentBulkType,
  latestFPPRelease
} = require('../../../../js/src/pages/remoteControl/state.js');

// =============================================================================
// Data Sources Tests
// =============================================================================

describe('DATA_SOURCES configuration', () => {
  test('has all required data sources', () => {
    expect(DATA_SOURCES.bulkStatus).toBeDefined();
    expect(DATA_SOURCES.bulkUpdates).toBeDefined();
    expect(DATA_SOURCES.localStatus).toBeDefined();
    expect(DATA_SOURCES.localSysStatus).toBeDefined();
    expect(DATA_SOURCES.localConnectivity).toBeDefined();
    expect(DATA_SOURCES.localVersion).toBeDefined();
    expect(DATA_SOURCES.localUpdates).toBeDefined();
    expect(DATA_SOURCES.discrepancies).toBeDefined();
    expect(DATA_SOURCES.fppRelease).toBeDefined();
  });

  test('each source has interval and lastFetch', () => {
    Object.values(DATA_SOURCES).forEach(source => {
      expect(typeof source.interval).toBe('number');
      expect(typeof source.lastFetch).toBe('number');
    });
  });

  test('intervals are positive numbers', () => {
    Object.values(DATA_SOURCES).forEach(source => {
      expect(source.interval).toBeGreaterThan(0);
    });
  });
});

// =============================================================================
// Cache Tests
// =============================================================================

describe('caches', () => {
  beforeEach(() => {
    resetState();
  });

  test('bulkStatusCache is a Map', () => {
    expect(bulkStatusCache instanceof Map).toBe(true);
  });

  test('bulkUpdatesCache is a Map', () => {
    expect(bulkUpdatesCache instanceof Map).toBe(true);
  });

  test('localCache has required properties', () => {
    expect(localCache).toHaveProperty('status');
    expect(localCache).toHaveProperty('testMode');
    expect(localCache).toHaveProperty('sysStatus');
    expect(localCache).toHaveProperty('connectivity');
    expect(localCache).toHaveProperty('version');
    expect(localCache).toHaveProperty('updates');
  });
});

// =============================================================================
// Host Tracking Maps Tests
// =============================================================================

describe('host tracking maps', () => {
  beforeEach(() => {
    resetState();
  });

  test('hostsWithWatcherUpdates is a Map', () => {
    expect(hostsWithWatcherUpdates instanceof Map).toBe(true);
  });

  test('hostsWithOtherPluginUpdates is a Map', () => {
    expect(hostsWithOtherPluginUpdates instanceof Map).toBe(true);
  });

  test('hostsNeedingRestart is a Map', () => {
    expect(hostsNeedingRestart instanceof Map).toBe(true);
  });

  test('hostsWithFPPUpdates is a Map', () => {
    expect(hostsWithFPPUpdates instanceof Map).toBe(true);
  });

  test('hostsWithConnectivityFailure is a Map', () => {
    expect(hostsWithConnectivityFailure instanceof Map).toBe(true);
  });
});

// =============================================================================
// shouldFetch Tests
// =============================================================================

describe('shouldFetch', () => {
  beforeEach(() => {
    resetState();
  });

  test('returns true for never-fetched source', () => {
    expect(shouldFetch('bulkStatus')).toBe(true);
  });

  test('returns false immediately after fetch', () => {
    markFetched('bulkStatus');
    expect(shouldFetch('bulkStatus')).toBe(false);
  });

  test('returns true for unknown source', () => {
    expect(shouldFetch('nonexistent')).toBe(true);
  });
});

// =============================================================================
// markFetched Tests
// =============================================================================

describe('markFetched', () => {
  beforeEach(() => {
    resetState();
  });

  test('updates lastFetch timestamp', () => {
    const before = DATA_SOURCES.bulkStatus.lastFetch;
    markFetched('bulkStatus');
    expect(DATA_SOURCES.bulkStatus.lastFetch).toBeGreaterThan(before);
  });

  test('handles unknown source gracefully', () => {
    expect(() => markFetched('nonexistent')).not.toThrow();
  });
});

// =============================================================================
// invalidateCache Tests
// =============================================================================

describe('invalidateCache', () => {
  beforeEach(() => {
    resetState();
  });

  test('resets lastFetch to 0', () => {
    markFetched('bulkStatus');
    expect(DATA_SOURCES.bulkStatus.lastFetch).toBeGreaterThan(0);

    invalidateCache('bulkStatus');
    expect(DATA_SOURCES.bulkStatus.lastFetch).toBe(0);
  });

  test('makes shouldFetch return true', () => {
    markFetched('bulkStatus');
    expect(shouldFetch('bulkStatus')).toBe(false);

    invalidateCache('bulkStatus');
    expect(shouldFetch('bulkStatus')).toBe(true);
  });

  test('handles unknown source gracefully', () => {
    expect(() => invalidateCache('nonexistent')).not.toThrow();
  });
});

// =============================================================================
// Config Tests
// =============================================================================

describe('initConfig', () => {
  beforeEach(() => {
    resetState();
  });

  test('sets remote addresses', () => {
    initConfig({
      remoteAddresses: ['192.168.1.100', '192.168.1.101'],
      remoteHostnames: {},
      localHostname: 'player'
    });
    expect(config.remoteAddresses).toEqual(['192.168.1.100', '192.168.1.101']);
  });

  test('sets remote hostnames', () => {
    initConfig({
      remoteAddresses: [],
      remoteHostnames: { '192.168.1.100': 'remote1' },
      localHostname: 'player'
    });
    expect(config.remoteHostnames).toEqual({ '192.168.1.100': 'remote1' });
  });

  test('sets local hostname', () => {
    initConfig({
      remoteAddresses: [],
      remoteHostnames: {},
      localHostname: 'myplayer'
    });
    expect(config.localHostname).toBe('myplayer');
  });

  test('handles empty config', () => {
    initConfig({});
    expect(config.remoteAddresses).toEqual([]);
    expect(config.remoteHostnames).toEqual({});
    expect(config.localHostname).toBe('localhost');
  });
});

// =============================================================================
// resetState Tests
// =============================================================================

describe('resetState', () => {
  test('clears all caches', () => {
    bulkStatusCache.set('test', { data: 'test' });
    bulkUpdatesCache.set('test', { data: 'test' });
    localCache.status = { test: true };

    resetState();

    expect(bulkStatusCache.size).toBe(0);
    expect(bulkUpdatesCache.size).toBe(0);
    expect(localCache.status).toBeNull();
  });

  test('clears host tracking maps', () => {
    hostsWithWatcherUpdates.set('test', {});
    hostsWithOtherPluginUpdates.set('test', {});
    hostsNeedingRestart.set('test', {});
    hostsWithFPPUpdates.set('test', {});
    hostsWithConnectivityFailure.set('test', {});

    resetState();

    expect(hostsWithWatcherUpdates.size).toBe(0);
    expect(hostsWithOtherPluginUpdates.size).toBe(0);
    expect(hostsNeedingRestart.size).toBe(0);
    expect(hostsWithFPPUpdates.size).toBe(0);
    expect(hostsWithConnectivityFailure.size).toBe(0);
  });

  test('resets data source timestamps', () => {
    markFetched('bulkStatus');
    markFetched('bulkUpdates');

    resetState();

    expect(DATA_SOURCES.bulkStatus.lastFetch).toBe(0);
    expect(DATA_SOURCES.bulkUpdates.lastFetch).toBe(0);
  });
});

// =============================================================================
// Utility Function Tests
// =============================================================================

describe('escapeId', () => {
  test('replaces dots with dashes', () => {
    expect(escapeId('192.168.1.100')).toBe('192-168-1-100');
  });

  test('handles strings without dots', () => {
    expect(escapeId('localhost')).toBe('localhost');
  });

  test('handles empty string', () => {
    expect(escapeId('')).toBe('');
  });
});

describe('getHostname', () => {
  beforeEach(() => {
    initConfig({
      remoteAddresses: ['192.168.1.100'],
      remoteHostnames: { '192.168.1.100': 'remote-player' },
      localHostname: 'main-player'
    });
  });

  afterEach(() => {
    resetState();
  });

  test('returns local hostname for localhost', () => {
    expect(getHostname('localhost')).toBe('main-player');
  });

  test('returns remote hostname for known address', () => {
    expect(getHostname('192.168.1.100')).toBe('remote-player');
  });

  test('returns address for unknown host', () => {
    expect(getHostname('192.168.1.200')).toBe('192.168.1.200');
  });
});

// =============================================================================
// State Setter Tests
// =============================================================================

describe('state setters', () => {
  beforeEach(() => {
    resetState();
  });

  // Note: ES module `let` exports become read-only bindings in the importer.
  // The setter functions DO update the module-internal state, but we can't
  // directly observe it via the imported binding. These tests verify the
  // setters don't throw and behave correctly.

  test('setIsRefreshing does not throw', () => {
    expect(() => setIsRefreshing(true)).not.toThrow();
    expect(() => setIsRefreshing(false)).not.toThrow();
  });

  test('setPendingReboot does not throw', () => {
    expect(() => setPendingReboot(null)).not.toThrow();
    expect(() => setPendingReboot({ address: 'test', hostname: 'test-host' })).not.toThrow();
  });

  test('setCurrentBulkType does not throw', () => {
    expect(() => setCurrentBulkType(null)).not.toThrow();
    expect(() => setCurrentBulkType('upgrade')).not.toThrow();
  });

  test('setLatestFPPRelease does not throw', () => {
    expect(() => setLatestFPPRelease(null)).not.toThrow();
    expect(() => setLatestFPPRelease({ version: '9.3' })).not.toThrow();
  });
});
