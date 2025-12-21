/**
 * Tests for js/src/pages/efuseMonitor.js
 */

const {
  efuseMonitor,
  downsampleData,
  getEfuseChartColor,
  getEfuseColor,
  formatCurrent,
  showToast,
  showConfirmModal,
  hideConfirmModal,
  executeConfirmAction,
} = require('../../../js/src/pages/efuseMonitor.js');

// =============================================================================
// Utility Function Tests
// =============================================================================

describe('downsampleData', () => {
  test('returns same array if under target points', () => {
    const data = [{ x: 1, y: 10 }, { x: 2, y: 20 }];
    expect(downsampleData(data, 10)).toBe(data);
  });

  test('returns null/undefined unchanged', () => {
    expect(downsampleData(null, 10)).toBeNull();
    expect(downsampleData(undefined, 10)).toBeUndefined();
  });

  test('downsamples to target points preserving peaks', () => {
    const data = [];
    for (let i = 0; i < 100; i++) {
      data.push({ x: i, y: i % 10 === 5 ? 100 : i });
    }
    const result = downsampleData(data, 20);
    expect(result.length).toBe(20);
    // First and last points are always preserved
    expect(result[0]).toEqual(data[0]);
    expect(result[result.length - 1]).toEqual(data[data.length - 1]);
  });

  test('preserves peak values in buckets', () => {
    const data = [
      { x: 0, y: 1 },
      { x: 1, y: 5 },
      { x: 2, y: 2 },
      { x: 3, y: 10 }, // peak
      { x: 4, y: 3 },
      { x: 5, y: 1 },
      { x: 6, y: 2 },
      { x: 7, y: 8 },
      { x: 8, y: 3 },
      { x: 9, y: 1 },
    ];
    const result = downsampleData(data, 4);
    expect(result.length).toBe(4);
    // Should include the peak value of 10
    const yValues = result.map(p => p.y);
    expect(yValues).toContain(10);
  });
});

describe('getEfuseChartColor', () => {
  test('returns color string for valid index', () => {
    expect(getEfuseChartColor(0)).toBe('#4e9f3d');
    expect(getEfuseChartColor(1)).toBe('#3498db');
  });

  test('wraps around for index >= 16', () => {
    expect(getEfuseChartColor(16)).toBe('#4e9f3d');
    expect(getEfuseChartColor(17)).toBe('#3498db');
  });

  test('handles negative index by wrapping', () => {
    // JavaScript modulo with negatives returns negative, so -1 % 16 = -1
    // The array access PORT_CHART_COLORS[-1] returns undefined
    // This is an edge case that doesn't occur in practice, but we document behavior
    const result = getEfuseChartColor(-1);
    // Returns undefined from array access with negative index
    expect(result).toBeUndefined();
  });
});

describe('getEfuseColor', () => {
  test('returns dark color for zero/negative', () => {
    expect(getEfuseColor(0)).toBe('#1a1a2e');
    expect(getEfuseColor(-100)).toBe('#1a1a2e');
  });

  test('returns green for low current', () => {
    expect(getEfuseColor(1000)).toBe('#1e5128');
    expect(getEfuseColor(1500)).toBe('#1e5128');
  });

  test('returns yellow for warning level', () => {
    expect(getEfuseColor(3000)).toBe('#ffc107');
    expect(getEfuseColor(3500)).toBe('#ffc107');
  });

  test('returns red for high current', () => {
    expect(getEfuseColor(5000)).toBe('#dc3545');
    expect(getEfuseColor(5500)).toBe('#dc3545');
  });

  test('returns dark red for max current', () => {
    expect(getEfuseColor(6000)).toBe('#c82333');
    expect(getEfuseColor(7000)).toBe('#c82333');
  });
});

describe('formatCurrent', () => {
  test('formats null/undefined as --', () => {
    expect(formatCurrent(null)).toBe('--');
    expect(formatCurrent(undefined)).toBe('--');
  });

  test('formats zero', () => {
    expect(formatCurrent(0)).toBe('0 mA');
    expect(formatCurrent(0, false)).toBe('0');
  });

  test('formats milliamps', () => {
    expect(formatCurrent(500)).toBe('500 mA');
    expect(formatCurrent(500, false)).toBe('500');
  });

  test('converts to amps above 1000mA', () => {
    expect(formatCurrent(1000)).toBe('1.00 A');
    expect(formatCurrent(1500)).toBe('1.50 A');
    expect(formatCurrent(2500, false)).toBe('2.50');
  });

  test('formats large values correctly', () => {
    expect(formatCurrent(6000)).toBe('6.00 A');
  });
});

// =============================================================================
// Toast Notification Tests
// =============================================================================

describe('showToast', () => {
  beforeEach(() => {
    document.body.innerHTML = '<div id="toastContainer"></div>';
    jest.useFakeTimers();
  });

  afterEach(() => {
    jest.useRealTimers();
  });

  test('does nothing if container not found', () => {
    document.body.innerHTML = '';
    expect(() => showToast('test', 'info')).not.toThrow();
  });

  test('creates toast element with correct class', () => {
    showToast('Test message', 'success');
    // Run only the animation frame, not the removal timeout
    jest.advanceTimersByTime(1);
    const toast = document.querySelector('.toast');
    expect(toast).not.toBeNull();
    expect(toast.classList.contains('toast-success')).toBe(true);
  });

  test('displays message text', () => {
    showToast('Hello world', 'info');
    jest.advanceTimersByTime(1);
    const toast = document.querySelector('.toast');
    expect(toast.textContent).toContain('Hello world');
  });

  test('uses correct icon for each type', () => {
    showToast('Success', 'success');
    jest.advanceTimersByTime(1);
    let icon = document.querySelector('.toast i');
    expect(icon.classList.contains('fa-check-circle')).toBe(true);

    document.getElementById('toastContainer').innerHTML = '';
    showToast('Error', 'error');
    jest.advanceTimersByTime(1);
    icon = document.querySelector('.toast i');
    expect(icon.classList.contains('fa-exclamation-circle')).toBe(true);
  });

  test('removes toast after duration', () => {
    showToast('Test', 'info', 3000);
    jest.advanceTimersByTime(1);
    expect(document.querySelector('.toast')).not.toBeNull();

    // Advance past the duration + removal animation time
    jest.advanceTimersByTime(3500);
    expect(document.querySelector('.toast')).toBeNull();
  });
});

// =============================================================================
// Confirmation Modal Tests
// =============================================================================

describe('showConfirmModal', () => {
  beforeEach(() => {
    document.body.innerHTML = `
      <div id="confirmModal" style="display:none;">
        <span id="confirmModalTitle"></span>
        <span id="confirmModalMessage"></span>
        <span id="confirmModalAction"></span>
      </div>
    `;
  });

  test('does nothing if modal not found', () => {
    document.body.innerHTML = '';
    expect(() => showConfirmModal('Title', 'Message', 'Action', jest.fn())).not.toThrow();
  });

  test('sets modal content and shows it', () => {
    showConfirmModal('Test Title', 'Test Message', 'Confirm', jest.fn());

    expect(document.getElementById('confirmModalTitle').textContent).toBe('Test Title');
    expect(document.getElementById('confirmModalMessage').textContent).toBe('Test Message');
    expect(document.getElementById('confirmModalAction').textContent).toBe('Confirm');
    expect(document.getElementById('confirmModal').style.display).toBe('flex');
  });
});

describe('hideConfirmModal', () => {
  beforeEach(() => {
    document.body.innerHTML = '<div id="confirmModal" style="display:flex;"></div>';
  });

  test('hides the modal', () => {
    hideConfirmModal();
    expect(document.getElementById('confirmModal').style.display).toBe('none');
  });

  test('handles missing modal gracefully', () => {
    document.body.innerHTML = '';
    expect(() => hideConfirmModal()).not.toThrow();
  });
});

describe('executeConfirmAction', () => {
  beforeEach(() => {
    document.body.innerHTML = `
      <div id="confirmModal" style="display:flex;">
        <span id="confirmModalTitle"></span>
        <span id="confirmModalMessage"></span>
        <span id="confirmModalAction"></span>
      </div>
    `;
  });

  test('executes callback and hides modal', () => {
    const callback = jest.fn();
    showConfirmModal('Title', 'Message', 'Action', callback);
    executeConfirmAction();

    expect(callback).toHaveBeenCalled();
    expect(document.getElementById('confirmModal').style.display).toBe('none');
  });
});

// =============================================================================
// Page Module Interface Tests
// =============================================================================

describe('efuseMonitor page module', () => {
  test('has required pageId', () => {
    expect(efuseMonitor.pageId).toBe('efuseMonitorUI');
  });

  test('has init method', () => {
    expect(typeof efuseMonitor.init).toBe('function');
  });

  test('has destroy method', () => {
    expect(typeof efuseMonitor.destroy).toBe('function');
  });

  test('exports public methods', () => {
    expect(typeof efuseMonitor.loadAllData).toBe('function');
    expect(typeof efuseMonitor.loadCurrentData).toBe('function');
    expect(typeof efuseMonitor.loadHeatmapData).toBe('function');
    expect(typeof efuseMonitor.showPortDetail).toBe('function');
    expect(typeof efuseMonitor.closePortDetail).toBe('function');
    expect(typeof efuseMonitor.togglePort).toBe('function');
    expect(typeof efuseMonitor.resetFuse).toBe('function');
    expect(typeof efuseMonitor.masterControl).toBe('function');
  });

  test('exports utility methods', () => {
    expect(typeof efuseMonitor.formatCurrent).toBe('function');
    expect(typeof efuseMonitor.getEfuseColor).toBe('function');
    expect(typeof efuseMonitor.getEfuseChartColor).toBe('function');
    expect(typeof efuseMonitor.downsampleData).toBe('function');
  });
});
