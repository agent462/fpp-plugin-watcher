/**
 * Tests for js/src/pages/falconMonitor.js
 */

const {
  falconMonitor,
  formatTemperature,
  getTempColor,
  getVoltageStatus,
  createControllerCard,
  toggleConfig,
  hideDiscoveryResults,
} = require('../../../js/src/pages/falconMonitor.js');

// =============================================================================
// Temperature Helper Tests
// =============================================================================

describe('formatTemperature', () => {
  test('formats celsius by default', () => {
    // Module state starts with useFahrenheit = false
    const result = formatTemperature(25);
    expect(result).toBe('25.0°C');
  });

  test('handles decimal values', () => {
    const result = formatTemperature(25.567);
    expect(result).toBe('25.6°C');
  });

  test('handles zero', () => {
    const result = formatTemperature(0);
    expect(result).toBe('0.0°C');
  });

  test('handles negative temperatures', () => {
    const result = formatTemperature(-10);
    expect(result).toBe('-10.0°C');
  });
});

describe('getTempColor', () => {
  test('returns green for cool temperatures', () => {
    expect(getTempColor(30)).toBe('#38ef7d');
    expect(getTempColor(39)).toBe('#38ef7d');
  });

  test('returns blue for moderate temperatures', () => {
    expect(getTempColor(40)).toBe('#38ef7d');
    expect(getTempColor(50)).toBe('#4facfe');
    expect(getTempColor(59)).toBe('#4facfe');
  });

  test('returns yellow for warm temperatures', () => {
    expect(getTempColor(60)).toBe('#4facfe');
    expect(getTempColor(70)).toBe('#ffc107');
    expect(getTempColor(79)).toBe('#ffc107');
  });

  test('returns red for hot temperatures', () => {
    expect(getTempColor(80)).toBe('#ffc107');
    expect(getTempColor(85)).toBe('#f5576c');
    expect(getTempColor(100)).toBe('#f5576c');
  });
});

describe('getVoltageStatus', () => {
  test('returns warning for low voltage', () => {
    expect(getVoltageStatus(10)).toBe('warning');
    expect(getVoltageStatus(10.9)).toBe('warning');
  });

  test('returns good for normal voltage range', () => {
    expect(getVoltageStatus(11)).toBe('good');
    expect(getVoltageStatus(12)).toBe('good');
    expect(getVoltageStatus(13)).toBe('good');
  });

  test('returns warning for high voltage', () => {
    expect(getVoltageStatus(13.1)).toBe('warning');
    expect(getVoltageStatus(15)).toBe('warning');
  });

  test('returns normal for zero/invalid', () => {
    expect(getVoltageStatus(0)).toBe('normal');
    expect(getVoltageStatus(-1)).toBe('normal');
  });
});

// =============================================================================
// Card Creation Tests
// =============================================================================

describe('createControllerCard', () => {
  test('creates loading skeleton card', () => {
    const controller = { host: '192.168.1.100' };
    const card = createControllerCard(controller, 0, true);

    expect(card).toBeInstanceOf(HTMLElement);
    expect(card.id).toBe('controller-0');
    expect(card.innerHTML).toContain('fa-spinner');
    expect(card.innerHTML).toContain('watcher-skeleton');
  });

  test('creates offline card when not online', () => {
    const controller = {
      host: '192.168.1.100',
      online: false,
      error: 'Connection timeout'
    };
    const card = createControllerCard(controller, 1, false);

    expect(card).toBeInstanceOf(HTMLElement);
    expect(card.className).toContain('watcher-card--offline');
    expect(card.innerHTML).toContain('Controller not responding');
    expect(card.innerHTML).toContain('Connection timeout');
  });

  test('creates online card with status data', () => {
    const controller = {
      host: '192.168.1.100',
      online: true,
      status: {
        name: 'Test Controller',
        model: 'F16V4',
        firmware_version: '1.23',
        mode_name: 'Normal',
        num_ports: 16,
        uptime: '1d 2h',
        temperature1: 45,
        temperature2: 50,
        temperature3: null,
        voltage1: '3.3V',
        voltage2: '12.1V',
        pixels_bank0: 100,
        pixels_bank1: 200,
        pixels_bank2: 300,
        time: '14:30',
        date: '2024-12-20'
      }
    };
    const card = createControllerCard(controller, 2, false);

    expect(card).toBeInstanceOf(HTMLElement);
    expect(card.className).not.toContain('watcher-card--offline');
    expect(card.innerHTML).toContain('Test Controller');
    expect(card.innerHTML).toContain('F16V4');
    expect(card.innerHTML).toContain('1.23');
    expect(card.innerHTML).toContain('600'); // Total pixels
  });

  test('includes fuse controls for F16V5', () => {
    const controller = {
      host: '192.168.1.100',
      online: true,
      status: {
        name: 'F16V5',
        model: 'F16V5',
        product_code: 130, // F16V5 product code
        temperature1: 40,
        pixels_bank0: 0,
        pixels_bank1: 0,
        pixels_bank2: 0
      }
    };
    const card = createControllerCard(controller, 3, false);

    expect(card.innerHTML).toContain('watcher-fuse-controls');
    expect(card.innerHTML).toContain('Fuses On');
    expect(card.innerHTML).toContain('Fuses Off');
    expect(card.innerHTML).toContain('Reset Fuses');
  });
});

// =============================================================================
// UI Action Tests
// =============================================================================

describe('toggleConfig', () => {
  beforeEach(() => {
    document.body.innerHTML = '<div id="configPanel" class=""></div>';
  });

  test('toggles visible class on config panel', () => {
    const panel = document.getElementById('configPanel');

    toggleConfig();
    expect(panel.classList.contains('visible')).toBe(true);

    toggleConfig();
    expect(panel.classList.contains('visible')).toBe(false);
  });
});

describe('hideDiscoveryResults', () => {
  beforeEach(() => {
    document.body.innerHTML = '<div id="discoveryResults" class="visible"></div>';
  });

  test('removes visible class from discovery results', () => {
    const results = document.getElementById('discoveryResults');

    hideDiscoveryResults();
    expect(results.classList.contains('visible')).toBe(false);
  });
});

// =============================================================================
// Page Module Interface Tests
// =============================================================================

describe('falconMonitor page module', () => {
  test('has required pageId', () => {
    expect(falconMonitor.pageId).toBe('falconMonitorUI');
  });

  test('has init method', () => {
    expect(typeof falconMonitor.init).toBe('function');
  });

  test('has destroy method', () => {
    expect(typeof falconMonitor.destroy).toBe('function');
  });

  test('exports public methods', () => {
    expect(typeof falconMonitor.loadAllControllers).toBe('function');
    expect(typeof falconMonitor.refreshController).toBe('function');
    expect(typeof falconMonitor.toggleTestMode).toBe('function');
    expect(typeof falconMonitor.rebootController).toBe('function');
    expect(typeof falconMonitor.setFuses).toBe('function');
    expect(typeof falconMonitor.resetFuses).toBe('function');
    expect(typeof falconMonitor.discoverControllers).toBe('function');
    expect(typeof falconMonitor.toggleConfig).toBe('function');
    expect(typeof falconMonitor.saveConfiguration).toBe('function');
  });

  test('exports helper methods', () => {
    expect(typeof falconMonitor.formatTemperature).toBe('function');
    expect(typeof falconMonitor.getTempColor).toBe('function');
    expect(typeof falconMonitor.getVoltageStatus).toBe('function');
    expect(typeof falconMonitor.createControllerCard).toBe('function');
  });
});
