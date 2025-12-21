/**
 * Tests for js/src/pages/remoteControl/index.js
 */

const { remoteControl } = require('../../../../js/src/pages/remoteControl/index.js');

// =============================================================================
// Module Interface Tests
// =============================================================================

describe('remoteControl module interface', () => {
  test('exports pageId', () => {
    expect(remoteControl.pageId).toBe('remoteControlUI');
  });

  test('exports init function', () => {
    expect(typeof remoteControl.init).toBe('function');
  });

  test('exports destroy function', () => {
    expect(typeof remoteControl.destroy).toBe('function');
  });

  test('exports refresh function', () => {
    expect(typeof remoteControl.refresh).toBe('function');
  });

  test('exports refreshAllStatus function', () => {
    expect(typeof remoteControl.refreshAllStatus).toBe('function');
  });
});

// =============================================================================
// Test Mode Functions
// =============================================================================

describe('remoteControl test mode functions', () => {
  test('exports toggleTestMode', () => {
    expect(typeof remoteControl.toggleTestMode).toBe('function');
  });

  test('exports toggleMultiSyncTestMode', () => {
    expect(typeof remoteControl.toggleMultiSyncTestMode).toBe('function');
  });
});

// =============================================================================
// Restart/Reboot Functions
// =============================================================================

describe('remoteControl restart/reboot functions', () => {
  test('exports restartFppd', () => {
    expect(typeof remoteControl.restartFppd).toBe('function');
  });

  test('exports confirmReboot', () => {
    expect(typeof remoteControl.confirmReboot).toBe('function');
  });

  test('exports closeConfirmDialog', () => {
    expect(typeof remoteControl.closeConfirmDialog).toBe('function');
  });

  test('exports restartLocalFppd', () => {
    expect(typeof remoteControl.restartLocalFppd).toBe('function');
  });

  test('exports confirmLocalReboot', () => {
    expect(typeof remoteControl.confirmLocalReboot).toBe('function');
  });
});

// =============================================================================
// Connectivity Functions
// =============================================================================

describe('remoteControl connectivity functions', () => {
  test('exports clearResetState', () => {
    expect(typeof remoteControl.clearResetState).toBe('function');
  });

  test('exports clearLocalResetState', () => {
    expect(typeof remoteControl.clearLocalResetState).toBe('function');
  });
});

// =============================================================================
// Plugin Upgrade Functions
// =============================================================================

describe('remoteControl plugin upgrade functions', () => {
  test('exports upgradePlugin', () => {
    expect(typeof remoteControl.upgradePlugin).toBe('function');
  });
});

// =============================================================================
// Bulk Modal Functions
// =============================================================================

describe('remoteControl bulk modal functions', () => {
  test('exports showBulkModal', () => {
    expect(typeof remoteControl.showBulkModal).toBe('function');
  });

  test('exports closeBulkModal', () => {
    expect(typeof remoteControl.closeBulkModal).toBe('function');
  });
});

// =============================================================================
// FPP Upgrade Functions
// =============================================================================

describe('remoteControl FPP upgrade functions', () => {
  test('exports showFPPUpgradeModal', () => {
    expect(typeof remoteControl.showFPPUpgradeModal).toBe('function');
  });

  test('exports closeFPPUpgradeModal', () => {
    expect(typeof remoteControl.closeFPPUpgradeModal).toBe('function');
  });

  test('exports toggleFPPAccordion', () => {
    expect(typeof remoteControl.toggleFPPAccordion).toBe('function');
  });

  test('exports toggleFPPSelection', () => {
    expect(typeof remoteControl.toggleFPPSelection).toBe('function');
  });

  test('exports fppSelectAll', () => {
    expect(typeof remoteControl.fppSelectAll).toBe('function');
  });

  test('exports fppSelectNone', () => {
    expect(typeof remoteControl.fppSelectNone).toBe('function');
  });

  test('exports fppExpandAll', () => {
    expect(typeof remoteControl.fppExpandAll).toBe('function');
  });

  test('exports fppCollapseAll', () => {
    expect(typeof remoteControl.fppCollapseAll).toBe('function');
  });

  test('exports switchFPPUpgradeType', () => {
    expect(typeof remoteControl.switchFPPUpgradeType).toBe('function');
  });

  test('exports startAllFPPUpgrades', () => {
    expect(typeof remoteControl.startAllFPPUpgrades).toBe('function');
  });

  test('exports upgradeFPPSingle', () => {
    expect(typeof remoteControl.upgradeFPPSingle).toBe('function');
  });

  test('exports upgradeFPPCrossVersion', () => {
    expect(typeof remoteControl.upgradeFPPCrossVersion).toBe('function');
  });

  test('exports upgradeFPPBranch', () => {
    expect(typeof remoteControl.upgradeFPPBranch).toBe('function');
  });
});

// =============================================================================
// Watcher Upgrade Functions
// =============================================================================

describe('remoteControl Watcher upgrade functions', () => {
  test('exports showWatcherUpgradeModal', () => {
    expect(typeof remoteControl.showWatcherUpgradeModal).toBe('function');
  });

  test('exports closeWatcherUpgradeModal', () => {
    expect(typeof remoteControl.closeWatcherUpgradeModal).toBe('function');
  });

  test('exports toggleWatcherAccordion', () => {
    expect(typeof remoteControl.toggleWatcherAccordion).toBe('function');
  });

  test('exports toggleWatcherSelection', () => {
    expect(typeof remoteControl.toggleWatcherSelection).toBe('function');
  });

  test('exports watcherSelectAll', () => {
    expect(typeof remoteControl.watcherSelectAll).toBe('function');
  });

  test('exports watcherSelectNone', () => {
    expect(typeof remoteControl.watcherSelectNone).toBe('function');
  });

  test('exports watcherExpandAll', () => {
    expect(typeof remoteControl.watcherExpandAll).toBe('function');
  });

  test('exports watcherCollapseAll', () => {
    expect(typeof remoteControl.watcherCollapseAll).toBe('function');
  });

  test('exports startAllWatcherUpgrades', () => {
    expect(typeof remoteControl.startAllWatcherUpgrades).toBe('function');
  });

  test('exports upgradeWatcherSingle', () => {
    expect(typeof remoteControl.upgradeWatcherSingle).toBe('function');
  });
});

// =============================================================================
// Issues Banner Functions
// =============================================================================

describe('remoteControl issues banner functions', () => {
  test('exports toggleIssuesDetails', () => {
    expect(typeof remoteControl.toggleIssuesDetails).toBe('function');
  });
});

// =============================================================================
// Initialization Tests
// =============================================================================

describe('remoteControl.init', () => {
  beforeEach(() => {
    // Clear any previous state
    remoteControl.destroy();
  });

  afterEach(() => {
    remoteControl.destroy();
  });

  test('initializes without errors', () => {
    expect(() => remoteControl.init({})).not.toThrow();
  });

  test('handles missing config gracefully', () => {
    expect(() => remoteControl.init()).not.toThrow();
    expect(() => remoteControl.init({})).not.toThrow();
  });

  test('accepts config with remote addresses', () => {
    const config = {
      remoteAddresses: ['192.168.1.100'],
      remoteHostnames: { '192.168.1.100': 'remote1' },
      localHostname: 'player'
    };
    expect(() => remoteControl.init(config)).not.toThrow();
  });
});

// =============================================================================
// Destroy Tests
// =============================================================================

describe('remoteControl.destroy', () => {
  test('can be called without prior init', () => {
    expect(() => remoteControl.destroy()).not.toThrow();
  });

  test('can be called multiple times safely', () => {
    remoteControl.destroy();
    remoteControl.destroy();
    remoteControl.destroy();
    expect(true).toBe(true);
  });

  test('clears interval after init', () => {
    remoteControl.init({});
    expect(() => remoteControl.destroy()).not.toThrow();
  });
});
