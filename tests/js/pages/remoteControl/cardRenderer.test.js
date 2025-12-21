/**
 * Tests for js/src/pages/remoteControl/cardRenderer.js
 *
 * Tests for card rendering functionality including:
 * - FPP update row rendering
 * - Blocked-by-branch logic (disable version upgrade when branch update pending)
 * - Bulk button updates
 */

const {
  updateCardUI,
  updateAllBulkButtons,
  updateBulkButton
} = require('../../../../js/src/pages/remoteControl/cardRenderer.js');

const {
  hostsWithFPPUpdates,
  hostsWithWatcherUpdates,
  hostsNeedingRestart,
  hostsWithConnectivityFailure,
  resetState
} = require('../../../../js/src/pages/remoteControl/state.js');

// =============================================================================
// Test Setup
// =============================================================================

beforeEach(() => {
  resetState();
  document.body.innerHTML = '';
});

// =============================================================================
// updateBulkButton Tests
// =============================================================================

describe('updateBulkButton', () => {
  beforeEach(() => {
    document.body.innerHTML = `
      <button id="testBtn" class="bulk-action-btn">
        <span class="badge" id="testCount">0</span>
      </button>
    `;
  });

  test('shows button when map has items', () => {
    const testMap = new Map([['host1', { hostname: 'test' }]]);

    updateBulkButton('testBtn', 'testCount', testMap);

    const btn = document.getElementById('testBtn');
    expect(btn.classList.contains('visible')).toBe(true);
  });

  test('hides button when map is empty', () => {
    const testMap = new Map();

    updateBulkButton('testBtn', 'testCount', testMap);

    const btn = document.getElementById('testBtn');
    expect(btn.classList.contains('visible')).toBe(false);
  });

  test('updates badge count', () => {
    const testMap = new Map([
      ['host1', {}],
      ['host2', {}],
      ['host3', {}]
    ]);

    updateBulkButton('testBtn', 'testCount', testMap);

    const badge = document.getElementById('testCount');
    expect(badge.textContent).toBe('3');
  });

  test('respects minCount parameter', () => {
    const testMap = new Map([['host1', {}]]);

    updateBulkButton('testBtn', 'testCount', testMap, 2);

    const btn = document.getElementById('testBtn');
    expect(btn.classList.contains('visible')).toBe(false);
  });

  test('handles missing elements gracefully', () => {
    const testMap = new Map([['host1', {}]]);

    // Should not throw
    expect(() => {
      updateBulkButton('nonexistent', 'alsoNonexistent', testMap);
    }).not.toThrow();
  });
});

// =============================================================================
// updateCardUI Tests - FPP Update Rows with blocked-by-branch
// =============================================================================

describe('updateCardUI - FPP update rows', () => {
  const createCardDOM = (address) => {
    const safeAddr = address.replace(/\./g, '-');
    return `
      <div class="controlCard" id="card-${address}" data-address="${address}">
        <div class="cardHeader">
          <div class="hostname">Test Host</div>
          <div class="address">${address}</div>
        </div>
        <div class="cardBody">
          <span id="status-${address}"></span>
          <span id="platform-${address}"></span>
          <span id="version-${address}"></span>
          <span id="mode-${address}"></span>
          <span id="watcher-${address}"></span>
          <input type="checkbox" id="testmode-${address}" disabled>
          <button id="restart-btn-${address}" disabled></button>
          <button id="reboot-btn-${address}" disabled></button>
          <div id="updates-container-${address}" class="updates-container">
            <div class="update-row" id="fpp-major-row-${address}">
              <span id="fpp-major-version-${address}"></span>
            </div>
            <div class="update-row" id="fpp-crossversion-row-${address}">
              <span id="fpp-crossversion-version-${address}"></span>
              <button id="fpp-crossversion-btn-${address}" class="banner-btn">Upgrade</button>
            </div>
            <div class="update-row" id="fpp-branch-row-${address}">
              <span id="fpp-branch-version-${address}"></span>
              <button id="fpp-branch-btn-${address}" class="banner-btn">Update</button>
            </div>
            <div id="upgrades-list-${address}"></div>
          </div>
          <div id="connectivity-alert-${address}" class="connectivity-alert">
            <span id="connectivity-details-${address}"></span>
          </div>
        </div>
      </div>
    `;
  };

  beforeEach(() => {
    document.body.innerHTML = createCardDOM('192.168.1.100');
    // Add bulk button elements that updateCardUI updates
    document.body.innerHTML += `
      <button id="upgradeAllBtn"><span id="upgradeAllCount">0</span></button>
      <button id="upgradeOtherPluginsBtn"><span id="upgradeOtherPluginsCount">0</span></button>
      <button id="restartAllBtn"><span id="restartAllCount">0</span></button>
      <button id="fppUpgradeAllBtn"><span id="fppUpgradeAllCount">0</span></button>
      <button id="connectivityFailBtn"><span id="connectivityFailCount">0</span></button>
    `;
  });

  test('disables cross-version button when branch update is also available', () => {
    const mockData = {
      success: true,
      status: {
        platform: 'Raspberry Pi',
        branch: 'v9.3',
        mode_name: 'player'
      },
      testMode: { enabled: 0 },
      pluginUpdates: [],
      fppLocalVersion: 'abc1234',
      fppRemoteVersion: 'def5678'
    };

    // Mock checkCrossVersionUpgrade to return available upgrade
    jest.doMock('../../../../js/src/pages/remoteControl/api.js', () => ({
      checkCrossVersionUpgrade: () => ({
        available: true,
        currentVersion: '9.3',
        latestVersion: '9.4',
        isMajorUpgrade: false
      })
    }));

    // Re-require to get mocked version
    jest.resetModules();
    const { updateCardUI: mockedUpdateCardUI } = require('../../../../js/src/pages/remoteControl/cardRenderer.js');

    mockedUpdateCardUI('192.168.1.100', mockData);

    const crossVersionBtn = document.getElementById('fpp-crossversion-btn-192.168.1.100');
    const crossVersionRow = document.getElementById('fpp-crossversion-row-192.168.1.100');

    // When both branch update (fppLocalVersion !== fppRemoteVersion) and cross-version are available
    // the cross-version button should be disabled
    expect(crossVersionBtn.disabled).toBe(true);
    expect(crossVersionBtn.title).toBe('Complete branch update first');
    expect(crossVersionRow.classList.contains('blocked-by-branch')).toBe(true);
  });

  test('enables cross-version button when only version upgrade available (no branch update)', () => {
    const mockData = {
      success: true,
      status: {
        platform: 'Raspberry Pi',
        branch: 'v9.3',
        mode_name: 'player'
      },
      testMode: { enabled: 0 },
      pluginUpdates: [],
      fppLocalVersion: 'abc1234',
      fppRemoteVersion: 'abc1234' // Same - no branch update needed
    };

    // Mock checkCrossVersionUpgrade to return available upgrade
    jest.doMock('../../../../js/src/pages/remoteControl/api.js', () => ({
      checkCrossVersionUpgrade: () => ({
        available: true,
        currentVersion: '9.3',
        latestVersion: '9.4',
        isMajorUpgrade: false
      })
    }));

    jest.resetModules();
    const { updateCardUI: mockedUpdateCardUI } = require('../../../../js/src/pages/remoteControl/cardRenderer.js');

    mockedUpdateCardUI('192.168.1.100', mockData);

    const crossVersionBtn = document.getElementById('fpp-crossversion-btn-192.168.1.100');
    const crossVersionRow = document.getElementById('fpp-crossversion-row-192.168.1.100');

    expect(crossVersionBtn.disabled).toBe(false);
    expect(crossVersionBtn.title).toBe('');
    expect(crossVersionRow.classList.contains('blocked-by-branch')).toBe(false);
  });

  test('shows branch update row when branch update available', () => {
    const mockData = {
      success: true,
      status: {
        platform: 'Raspberry Pi',
        branch: 'v9.3',
        mode_name: 'player'
      },
      testMode: { enabled: 0 },
      pluginUpdates: [],
      fppLocalVersion: 'abc1234567890',
      fppRemoteVersion: 'def5678901234'
    };

    // Mock no cross-version upgrade
    jest.doMock('../../../../js/src/pages/remoteControl/api.js', () => ({
      checkCrossVersionUpgrade: () => null
    }));

    jest.resetModules();
    const { updateCardUI: mockedUpdateCardUI } = require('../../../../js/src/pages/remoteControl/cardRenderer.js');

    mockedUpdateCardUI('192.168.1.100', mockData);

    const branchRow = document.getElementById('fpp-branch-row-192.168.1.100');
    const branchVersion = document.getElementById('fpp-branch-version-192.168.1.100');

    expect(branchRow.classList.contains('visible')).toBe(true);
    expect(branchVersion.textContent).toContain('abc1234');
    expect(branchVersion.textContent).toContain('def5678');
  });

  test('hides cross-version row when no upgrade available', () => {
    const mockData = {
      success: true,
      status: {
        platform: 'Raspberry Pi',
        branch: 'v9.3',
        mode_name: 'player'
      },
      testMode: { enabled: 0 },
      pluginUpdates: [],
      fppLocalVersion: 'abc1234',
      fppRemoteVersion: 'abc1234'
    };

    // Mock no cross-version upgrade
    jest.doMock('../../../../js/src/pages/remoteControl/api.js', () => ({
      checkCrossVersionUpgrade: () => null
    }));

    jest.resetModules();
    const { updateCardUI: mockedUpdateCardUI } = require('../../../../js/src/pages/remoteControl/cardRenderer.js');

    mockedUpdateCardUI('192.168.1.100', mockData);

    const crossVersionRow = document.getElementById('fpp-crossversion-row-192.168.1.100');

    expect(crossVersionRow.classList.contains('visible')).toBe(false);
    expect(crossVersionRow.classList.contains('blocked-by-branch')).toBe(false);
  });

  test('clears blocked-by-branch class when row becomes hidden', () => {
    // First set up with blocked state
    const crossVersionRow = document.getElementById('fpp-crossversion-row-192.168.1.100');
    crossVersionRow.classList.add('blocked-by-branch');

    const mockData = {
      success: true,
      status: {
        platform: 'Raspberry Pi',
        branch: 'v9.3',
        mode_name: 'player'
      },
      testMode: { enabled: 0 },
      pluginUpdates: [],
      fppLocalVersion: 'abc1234',
      fppRemoteVersion: 'abc1234'
    };

    // Mock no cross-version upgrade
    jest.doMock('../../../../js/src/pages/remoteControl/api.js', () => ({
      checkCrossVersionUpgrade: () => null
    }));

    jest.resetModules();
    const { updateCardUI: mockedUpdateCardUI } = require('../../../../js/src/pages/remoteControl/cardRenderer.js');

    mockedUpdateCardUI('192.168.1.100', mockData);

    expect(crossVersionRow.classList.contains('blocked-by-branch')).toBe(false);
  });
});

// =============================================================================
// updateAllBulkButtons Tests
// =============================================================================

describe('updateAllBulkButtons', () => {
  beforeEach(() => {
    document.body.innerHTML = `
      <button id="upgradeAllBtn"><span id="upgradeAllCount">0</span></button>
      <button id="upgradeOtherPluginsBtn"><span id="upgradeOtherPluginsCount">0</span></button>
      <button id="restartAllBtn"><span id="restartAllCount">0</span></button>
      <button id="fppUpgradeAllBtn"><span id="fppUpgradeAllCount">0</span></button>
      <button id="connectivityFailBtn"><span id="connectivityFailCount">0</span></button>
    `;
  });

  test('updates all bulk buttons based on tracking maps', () => {
    hostsWithWatcherUpdates.set('host1', { hostname: 'test1' });
    hostsWithFPPUpdates.set('host2', { hostname: 'test2' });
    hostsNeedingRestart.set('host3', { hostname: 'test3' });

    updateAllBulkButtons();

    expect(document.getElementById('upgradeAllCount').textContent).toBe('1');
    expect(document.getElementById('fppUpgradeAllCount').textContent).toBe('1');
    expect(document.getElementById('restartAllCount').textContent).toBe('1');
  });

  test('handles empty maps', () => {
    updateAllBulkButtons();

    expect(document.getElementById('upgradeAllBtn').classList.contains('visible')).toBe(false);
    expect(document.getElementById('fppUpgradeAllBtn').classList.contains('visible')).toBe(false);
  });
});
