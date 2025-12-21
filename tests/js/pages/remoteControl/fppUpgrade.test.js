/**
 * Tests for js/src/pages/remoteControl/fppUpgrade.js
 *
 * Tests for FPP upgrade functionality including:
 * - Host filtering by upgrade type
 * - Blocked host detection (branch update required before version upgrade)
 * - Accordion UI building with blocked states
 * - Selection controls respecting blocked hosts
 * - Summary count updates with blocked hosts
 */

const {
  getHostsForUpgradeType,
  countHostsByUpgradeType,
  fppUpgradeStates,
  buildFPPAccordion,
  toggleFPPSelection,
  fppSelectAll,
  fppSelectNone,
  updateFPPSummary,
  setFppSelectedUpgradeType
} = require('../../../../js/src/pages/remoteControl/fppUpgrade.js');

const {
  hostsWithFPPUpdates,
  resetState,
  escapeId
} = require('../../../../js/src/pages/remoteControl/state.js');

// =============================================================================
// Test Setup
// =============================================================================

beforeEach(() => {
  resetState();
  fppUpgradeStates.clear();
  document.body.innerHTML = '';
});

// =============================================================================
// getHostsForUpgradeType Tests
// =============================================================================

describe('getHostsForUpgradeType', () => {
  beforeEach(() => {
    hostsWithFPPUpdates.clear();
  });

  describe('crossVersion type', () => {
    test('returns hosts with crossVersion upgrade available', () => {
      hostsWithFPPUpdates.set('192.168.1.100', {
        hostname: 'player1',
        branch: 'v9.3',
        crossVersion: { localVersion: '9.3', remoteVersion: '9.4' },
        branchUpdate: null
      });

      const result = getHostsForUpgradeType('crossVersion');

      expect(result.size).toBe(1);
      expect(result.get('192.168.1.100')).toEqual({
        hostname: 'player1',
        localVersion: '9.3',
        remoteVersion: '9.4',
        isCrossVersion: true,
        blockedByBranch: false
      });
    });

    test('marks host as blockedByBranch when branch update also available', () => {
      hostsWithFPPUpdates.set('192.168.1.100', {
        hostname: 'player1',
        branch: 'v9.3',
        crossVersion: { localVersion: '9.3', remoteVersion: '9.4' },
        branchUpdate: { localVersion: 'abc1234', remoteVersion: 'def5678' }
      });

      const result = getHostsForUpgradeType('crossVersion');

      expect(result.size).toBe(1);
      expect(result.get('192.168.1.100').blockedByBranch).toBe(true);
    });

    test('does not mark host as blocked when only crossVersion available', () => {
      hostsWithFPPUpdates.set('192.168.1.100', {
        hostname: 'player1',
        branch: 'v9.3',
        crossVersion: { localVersion: '9.3', remoteVersion: '9.4' },
        branchUpdate: null
      });

      const result = getHostsForUpgradeType('crossVersion');

      expect(result.get('192.168.1.100').blockedByBranch).toBe(false);
    });

    test('excludes hosts without crossVersion upgrade', () => {
      hostsWithFPPUpdates.set('192.168.1.100', {
        hostname: 'player1',
        branch: 'v9.3',
        crossVersion: null,
        branchUpdate: { localVersion: 'abc1234', remoteVersion: 'def5678' }
      });

      const result = getHostsForUpgradeType('crossVersion');

      expect(result.size).toBe(0);
    });
  });

  describe('branchUpdate type', () => {
    test('returns hosts with branch update available', () => {
      hostsWithFPPUpdates.set('192.168.1.100', {
        hostname: 'player1',
        branch: 'v9.3',
        crossVersion: null,
        branchUpdate: { localVersion: 'abc1234567', remoteVersion: 'def5678901' }
      });

      const result = getHostsForUpgradeType('branchUpdate');

      expect(result.size).toBe(1);
      expect(result.get('192.168.1.100')).toEqual({
        hostname: 'player1',
        localVersion: 'abc1234',
        remoteVersion: 'def5678',
        branch: '9.3',
        isCrossVersion: false,
        blockedByBranch: false
      });
    });

    test('branch updates are never blocked', () => {
      hostsWithFPPUpdates.set('192.168.1.100', {
        hostname: 'player1',
        branch: 'v9.3',
        crossVersion: { localVersion: '9.3', remoteVersion: '9.4' },
        branchUpdate: { localVersion: 'abc1234567', remoteVersion: 'def5678901' }
      });

      const result = getHostsForUpgradeType('branchUpdate');

      expect(result.get('192.168.1.100').blockedByBranch).toBe(false);
    });

    test('excludes hosts without branch update', () => {
      hostsWithFPPUpdates.set('192.168.1.100', {
        hostname: 'player1',
        branch: 'v9.3',
        crossVersion: { localVersion: '9.3', remoteVersion: '9.4' },
        branchUpdate: null
      });

      const result = getHostsForUpgradeType('branchUpdate');

      expect(result.size).toBe(0);
    });
  });

  describe('multiple hosts', () => {
    test('correctly identifies blocked and unblocked hosts', () => {
      // Host with both upgrades (blocked for crossVersion)
      hostsWithFPPUpdates.set('192.168.1.100', {
        hostname: 'player1',
        branch: 'v9.3',
        crossVersion: { localVersion: '9.3', remoteVersion: '9.4' },
        branchUpdate: { localVersion: 'abc1234', remoteVersion: 'def5678' }
      });

      // Host with only crossVersion (not blocked)
      hostsWithFPPUpdates.set('192.168.1.101', {
        hostname: 'player2',
        branch: 'v9.3',
        crossVersion: { localVersion: '9.3', remoteVersion: '9.4' },
        branchUpdate: null
      });

      const result = getHostsForUpgradeType('crossVersion');

      expect(result.size).toBe(2);
      expect(result.get('192.168.1.100').blockedByBranch).toBe(true);
      expect(result.get('192.168.1.101').blockedByBranch).toBe(false);
    });
  });
});

// =============================================================================
// countHostsByUpgradeType Tests
// =============================================================================

describe('countHostsByUpgradeType', () => {
  beforeEach(() => {
    hostsWithFPPUpdates.clear();
  });

  test('counts hosts with crossVersion upgrades', () => {
    hostsWithFPPUpdates.set('192.168.1.100', {
      crossVersion: { localVersion: '9.3', remoteVersion: '9.4' },
      branchUpdate: null
    });

    const { crossVersionCount, branchUpdateCount } = countHostsByUpgradeType();

    expect(crossVersionCount).toBe(1);
    expect(branchUpdateCount).toBe(0);
  });

  test('counts hosts with branch updates', () => {
    hostsWithFPPUpdates.set('192.168.1.100', {
      crossVersion: null,
      branchUpdate: { localVersion: 'abc', remoteVersion: 'def' }
    });

    const { crossVersionCount, branchUpdateCount } = countHostsByUpgradeType();

    expect(crossVersionCount).toBe(0);
    expect(branchUpdateCount).toBe(1);
  });

  test('counts hosts with both upgrade types', () => {
    hostsWithFPPUpdates.set('192.168.1.100', {
      crossVersion: { localVersion: '9.3', remoteVersion: '9.4' },
      branchUpdate: { localVersion: 'abc', remoteVersion: 'def' }
    });

    const { crossVersionCount, branchUpdateCount } = countHostsByUpgradeType();

    expect(crossVersionCount).toBe(1);
    expect(branchUpdateCount).toBe(1);
  });

  test('returns zero counts for empty map', () => {
    const { crossVersionCount, branchUpdateCount } = countHostsByUpgradeType();

    expect(crossVersionCount).toBe(0);
    expect(branchUpdateCount).toBe(0);
  });
});

// =============================================================================
// buildFPPAccordion Tests (blocked host rendering)
// =============================================================================

describe('buildFPPAccordion', () => {
  beforeEach(() => {
    hostsWithFPPUpdates.clear();
    fppUpgradeStates.clear();
    setFppSelectedUpgradeType('crossVersion');

    // Set up DOM elements
    document.body.innerHTML = `
      <div id="fppAccordion"></div>
      <div id="fppUpgradeCount"></div>
      <button id="fppUpgradeStartBtn"></button>
    `;
  });

  test('renders blocked host with correct classes', () => {
    hostsWithFPPUpdates.set('192.168.1.100', {
      hostname: 'player1',
      branch: 'v9.3',
      crossVersion: { localVersion: '9.3', remoteVersion: '9.4' },
      branchUpdate: { localVersion: 'abc1234', remoteVersion: 'def5678' }
    });

    buildFPPAccordion();

    const item = document.getElementById('fpp-item-192-168-1-100');
    expect(item).not.toBeNull();
    expect(item.classList.contains('blocked')).toBe(true);
    expect(item.classList.contains('excluded')).toBe(true);
  });

  test('renders blocked host with disabled checkbox', () => {
    hostsWithFPPUpdates.set('192.168.1.100', {
      hostname: 'player1',
      branch: 'v9.3',
      crossVersion: { localVersion: '9.3', remoteVersion: '9.4' },
      branchUpdate: { localVersion: 'abc1234', remoteVersion: 'def5678' }
    });

    buildFPPAccordion();

    const checkbox = document.getElementById('fpp-check-192-168-1-100');
    expect(checkbox).not.toBeNull();
    expect(checkbox.disabled).toBe(true);
    expect(checkbox.checked).toBe(false);
  });

  test('renders blocked host with blocked status badge', () => {
    hostsWithFPPUpdates.set('192.168.1.100', {
      hostname: 'player1',
      branch: 'v9.3',
      crossVersion: { localVersion: '9.3', remoteVersion: '9.4' },
      branchUpdate: { localVersion: 'abc1234', remoteVersion: 'def5678' }
    });

    buildFPPAccordion();

    const status = document.getElementById('fpp-status-192-168-1-100');
    expect(status).not.toBeNull();
    expect(status.classList.contains('blocked')).toBe(true);
    expect(status.textContent).toContain('Branch Update Required');
  });

  test('renders blocked note for blocked hosts', () => {
    hostsWithFPPUpdates.set('192.168.1.100', {
      hostname: 'player1',
      branch: 'v9.3',
      crossVersion: { localVersion: '9.3', remoteVersion: '9.4' },
      branchUpdate: { localVersion: 'abc1234', remoteVersion: 'def5678' }
    });

    buildFPPAccordion();

    const accordion = document.getElementById('fppAccordion');
    expect(accordion.innerHTML).toContain('fpp-accordion-blocked-note');
    expect(accordion.innerHTML).toContain('Complete the branch update');
  });

  test('sets blocked state in fppUpgradeStates', () => {
    hostsWithFPPUpdates.set('192.168.1.100', {
      hostname: 'player1',
      branch: 'v9.3',
      crossVersion: { localVersion: '9.3', remoteVersion: '9.4' },
      branchUpdate: { localVersion: 'abc1234', remoteVersion: 'def5678' }
    });

    buildFPPAccordion();

    const state = fppUpgradeStates.get('192.168.1.100');
    expect(state).toBeDefined();
    expect(state.status).toBe('blocked');
    expect(state.selected).toBe(false);
    expect(state.blockedByBranch).toBe(true);
  });

  test('renders unblocked host normally', () => {
    hostsWithFPPUpdates.set('192.168.1.100', {
      hostname: 'player1',
      branch: 'v9.3',
      crossVersion: { localVersion: '9.3', remoteVersion: '9.4' },
      branchUpdate: null
    });

    buildFPPAccordion();

    const item = document.getElementById('fpp-item-192-168-1-100');
    expect(item.classList.contains('blocked')).toBe(false);

    const checkbox = document.getElementById('fpp-check-192-168-1-100');
    expect(checkbox.disabled).toBe(false);
    expect(checkbox.checked).toBe(true);

    const state = fppUpgradeStates.get('192.168.1.100');
    expect(state.status).toBe('pending');
    expect(state.selected).toBe(true);
    expect(state.blockedByBranch).toBe(false);
  });
});

// =============================================================================
// toggleFPPSelection Tests (blocked host prevention)
// =============================================================================

describe('toggleFPPSelection', () => {
  beforeEach(() => {
    fppUpgradeStates.clear();
    document.body.innerHTML = `
      <div class="fpp-accordion-item" id="fpp-item-192-168-1-100">
        <input type="checkbox" id="fpp-check-192-168-1-100">
      </div>
      <div id="fppUpgradeCount"></div>
      <button id="fppUpgradeStartBtn"></button>
    `;
  });

  test('prevents selection of blocked hosts', () => {
    fppUpgradeStates.set('192.168.1.100', {
      status: 'blocked',
      selected: false,
      blockedByBranch: true
    });

    const checkbox = document.getElementById('fpp-check-192-168-1-100');
    checkbox.checked = true;

    toggleFPPSelection('192.168.1.100');

    expect(checkbox.checked).toBe(false);
    expect(fppUpgradeStates.get('192.168.1.100').selected).toBe(false);
  });

  test('allows selection of unblocked hosts', () => {
    fppUpgradeStates.set('192.168.1.100', {
      status: 'pending',
      selected: false,
      blockedByBranch: false
    });

    const checkbox = document.getElementById('fpp-check-192-168-1-100');
    checkbox.checked = true;

    toggleFPPSelection('192.168.1.100');

    expect(fppUpgradeStates.get('192.168.1.100').selected).toBe(true);
  });

  test('allows deselection of unblocked hosts', () => {
    fppUpgradeStates.set('192.168.1.100', {
      status: 'pending',
      selected: true,
      blockedByBranch: false
    });

    const checkbox = document.getElementById('fpp-check-192-168-1-100');
    checkbox.checked = false;

    toggleFPPSelection('192.168.1.100');

    expect(fppUpgradeStates.get('192.168.1.100').selected).toBe(false);
  });
});

// =============================================================================
// fppSelectAll Tests (skips blocked hosts)
// =============================================================================

describe('fppSelectAll', () => {
  beforeEach(() => {
    fppUpgradeStates.clear();
    document.body.innerHTML = `
      <div class="fpp-accordion-item" id="fpp-item-192-168-1-100">
        <input type="checkbox" id="fpp-check-192-168-1-100">
      </div>
      <div class="fpp-accordion-item" id="fpp-item-192-168-1-101">
        <input type="checkbox" id="fpp-check-192-168-1-101">
      </div>
      <div id="fppUpgradeCount"></div>
      <button id="fppUpgradeStartBtn"></button>
    `;
  });

  test('selects only unblocked pending hosts', () => {
    fppUpgradeStates.set('192.168.1.100', {
      status: 'pending',
      selected: false,
      blockedByBranch: false
    });
    fppUpgradeStates.set('192.168.1.101', {
      status: 'blocked',
      selected: false,
      blockedByBranch: true
    });

    fppSelectAll();

    expect(fppUpgradeStates.get('192.168.1.100').selected).toBe(true);
    expect(fppUpgradeStates.get('192.168.1.101').selected).toBe(false);

    expect(document.getElementById('fpp-check-192-168-1-100').checked).toBe(true);
    // Blocked checkbox should remain unchecked
  });

  test('does not select hosts with blockedByBranch flag even if pending status', () => {
    // Edge case: status might be pending but blockedByBranch is true
    fppUpgradeStates.set('192.168.1.100', {
      status: 'pending',
      selected: false,
      blockedByBranch: true
    });

    fppSelectAll();

    expect(fppUpgradeStates.get('192.168.1.100').selected).toBe(false);
  });
});

// =============================================================================
// updateFPPSummary Tests (blocked host counting)
// =============================================================================

describe('updateFPPSummary', () => {
  beforeEach(() => {
    fppUpgradeStates.clear();
    document.body.innerHTML = `
      <div id="fppUpgradeCount"></div>
      <button id="fppUpgradeStartBtn"></button>
    `;
  });

  test('shows blocked count when all hosts are blocked', () => {
    fppUpgradeStates.set('192.168.1.100', { status: 'blocked', selected: false });
    fppUpgradeStates.set('192.168.1.101', { status: 'blocked', selected: false });

    updateFPPSummary();

    const countEl = document.getElementById('fppUpgradeCount');
    expect(countEl.textContent).toContain('2 blocked');
    expect(countEl.textContent).toContain('branch update required');
  });

  test('shows blocked count alongside selected count', () => {
    fppUpgradeStates.set('192.168.1.100', { status: 'pending', selected: true });
    fppUpgradeStates.set('192.168.1.101', { status: 'blocked', selected: false });

    updateFPPSummary();

    const countEl = document.getElementById('fppUpgradeCount');
    expect(countEl.textContent).toContain('1 of 1 selected');
    expect(countEl.textContent).toContain('1 blocked');
  });

  test('excludes blocked hosts from total when calculating available', () => {
    fppUpgradeStates.set('192.168.1.100', { status: 'pending', selected: true });
    fppUpgradeStates.set('192.168.1.101', { status: 'pending', selected: false });
    fppUpgradeStates.set('192.168.1.102', { status: 'blocked', selected: false });

    updateFPPSummary();

    const countEl = document.getElementById('fppUpgradeCount');
    // Should show "1 of 2 selected (1 blocked)" not "1 of 3 selected"
    expect(countEl.textContent).toContain('1 of 2 selected');
  });

  test('disables start button when no hosts selected', () => {
    fppUpgradeStates.set('192.168.1.100', { status: 'blocked', selected: false });

    updateFPPSummary();

    const startBtn = document.getElementById('fppUpgradeStartBtn');
    expect(startBtn.disabled).toBe(true);
  });

  test('enables start button when hosts are selected', () => {
    fppUpgradeStates.set('192.168.1.100', { status: 'pending', selected: true });

    updateFPPSummary();

    const startBtn = document.getElementById('fppUpgradeStartBtn');
    expect(startBtn.disabled).toBe(false);
  });

  test('shows normal count when no blocked hosts', () => {
    fppUpgradeStates.set('192.168.1.100', { status: 'pending', selected: true });
    fppUpgradeStates.set('192.168.1.101', { status: 'pending', selected: true });

    updateFPPSummary();

    const countEl = document.getElementById('fppUpgradeCount');
    expect(countEl.textContent).toBe('2 of 2 selected');
    expect(countEl.textContent).not.toContain('blocked');
  });
});
