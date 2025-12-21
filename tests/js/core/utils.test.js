/**
 * Tests for js/src/core/utils.js
 */

const {
  escapeHtml,
  showElement,
  hideElement,
  setLoading,
  formatBytes,
  formatLatency,
  formatPercent,
  formatDuration,
  toFahrenheit,
  getTempUnit,
  formatTemp,
  getTempStatus,
  formatThermalZoneName,
  updateLastUpdateTime,
  utils,
} = require('../../../js/src/core/utils.js');

// =============================================================================
// escapeHtml tests
// =============================================================================

describe('escapeHtml', () => {
  test('escapes HTML special characters', () => {
    expect(escapeHtml('<script>')).toBe('&lt;script&gt;');
    expect(escapeHtml('Tom & Jerry')).toBe('Tom &amp; Jerry');
    expect(escapeHtml('"quoted"')).toBe('&quot;quoted&quot;');
    expect(escapeHtml("it's")).toBe("it&#039;s");
  });

  test('handles null and undefined', () => {
    expect(escapeHtml(null)).toBe('');
    expect(escapeHtml(undefined)).toBe('');
  });

  test('handles empty string', () => {
    expect(escapeHtml('')).toBe('');
  });

  test('converts numbers to strings', () => {
    expect(escapeHtml(123)).toBe('123');
  });

  test('handles complex XSS attempts', () => {
    const xss = '<img src="x" onerror="alert(\'XSS\')">';
    expect(escapeHtml(xss)).toBe('&lt;img src=&quot;x&quot; onerror=&quot;alert(&#039;XSS&#039;)&quot;&gt;');
  });

  test('handles multiple special characters', () => {
    expect(escapeHtml('<a href="test">&</a>')).toBe('&lt;a href=&quot;test&quot;&gt;&amp;&lt;/a&gt;');
  });
});

// =============================================================================
// DOM helpers tests
// =============================================================================

describe('showElement', () => {
  beforeEach(() => {
    document.body.innerHTML = '<div id="test" style="display: none;"></div>';
  });

  test('shows element by setting display to block', () => {
    showElement('test');
    expect(document.getElementById('test').style.display).toBe('block');
  });

  test('handles non-existent element gracefully', () => {
    expect(() => showElement('nonexistent')).not.toThrow();
  });
});

describe('hideElement', () => {
  beforeEach(() => {
    document.body.innerHTML = '<div id="test" style="display: block;"></div>';
  });

  test('hides element by setting display to none', () => {
    hideElement('test');
    expect(document.getElementById('test').style.display).toBe('none');
  });

  test('handles non-existent element gracefully', () => {
    expect(() => hideElement('nonexistent')).not.toThrow();
  });
});

describe('setLoading', () => {
  beforeEach(() => {
    document.body.innerHTML = '<div id="loader"></div>';
  });

  test('shows loader with flex display when true', () => {
    setLoading('loader', true);
    expect(document.getElementById('loader').style.display).toBe('flex');
  });

  test('hides loader when false', () => {
    setLoading('loader', false);
    expect(document.getElementById('loader').style.display).toBe('none');
  });

  test('handles non-existent element gracefully', () => {
    expect(() => setLoading('nonexistent', true)).not.toThrow();
  });
});

// =============================================================================
// Format helpers tests
// =============================================================================

describe('formatBytes', () => {
  test('formats 0 bytes', () => {
    expect(formatBytes(0)).toBe('0 Bytes');
    expect(formatBytes(null)).toBe('0 Bytes');
    expect(formatBytes(undefined)).toBe('0 Bytes');
  });

  test('formats bytes correctly', () => {
    expect(formatBytes(1)).toBe('1.00 Bytes');
    expect(formatBytes(512)).toBe('512.00 Bytes');
  });

  test('formats kilobytes', () => {
    expect(formatBytes(1024)).toBe('1.00 KB');
    expect(formatBytes(1536)).toBe('1.50 KB');
  });

  test('formats megabytes', () => {
    expect(formatBytes(1048576)).toBe('1.00 MB');
    expect(formatBytes(1572864)).toBe('1.50 MB');
  });

  test('formats gigabytes', () => {
    expect(formatBytes(1073741824)).toBe('1.00 GB');
  });

  test('formats terabytes', () => {
    expect(formatBytes(1099511627776)).toBe('1.00 TB');
  });
});

describe('formatLatency', () => {
  test('formats latency with 2 decimal places', () => {
    expect(formatLatency(12.345)).toBe('12.35 ms');
    expect(formatLatency(0.5)).toBe('0.50 ms');
  });

  test('handles null and undefined', () => {
    expect(formatLatency(null)).toBe('-- ms');
    expect(formatLatency(undefined)).toBe('-- ms');
  });

  test('formats zero', () => {
    expect(formatLatency(0)).toBe('0.00 ms');
  });
});

describe('formatPercent', () => {
  test('formats percentage with default 1 decimal', () => {
    expect(formatPercent(75.555)).toBe('75.6%');
    expect(formatPercent(100)).toBe('100.0%');
  });

  test('formats with custom decimals', () => {
    expect(formatPercent(75.555, 2)).toBe('75.56%');
    expect(formatPercent(75.555, 0)).toBe('76%');
  });

  test('handles null and undefined', () => {
    expect(formatPercent(null)).toBe('--%');
    expect(formatPercent(undefined)).toBe('--%');
  });

  test('formats zero', () => {
    expect(formatPercent(0)).toBe('0.0%');
  });
});

describe('formatDuration', () => {
  test('formats seconds only', () => {
    expect(formatDuration(45)).toBe('45s');
  });

  test('formats minutes and seconds', () => {
    expect(formatDuration(90)).toBe('1m 30s');
    expect(formatDuration(125)).toBe('2m 5s');
  });

  test('formats hours, minutes, and seconds', () => {
    expect(formatDuration(3661)).toBe('1h 1m 1s');
    expect(formatDuration(7200)).toBe('2h 0m 0s');
  });

  test('handles edge cases', () => {
    expect(formatDuration(0)).toBe('--');
    expect(formatDuration(null)).toBe('--');
    expect(formatDuration(undefined)).toBe('--');
    expect(formatDuration(-5)).toBe('--');
  });
});

// =============================================================================
// Temperature helpers tests
// =============================================================================

describe('toFahrenheit', () => {
  test('converts common temperatures', () => {
    expect(toFahrenheit(0)).toBe(32);
    expect(toFahrenheit(100)).toBe(212);
    expect(toFahrenheit(37)).toBeCloseTo(98.6, 1);
  });

  test('converts negative temperatures', () => {
    expect(toFahrenheit(-40)).toBe(-40); // Same in both scales!
  });
});

describe('getTempUnit', () => {
  test('returns correct unit string', () => {
    expect(getTempUnit(true)).toBe('°F');
    expect(getTempUnit(false)).toBe('°C');
  });
});

describe('formatTemp', () => {
  test('formats Celsius temperature', () => {
    expect(formatTemp(25, false)).toBe('25.0°C');
    expect(formatTemp(37.5, false)).toBe('37.5°C');
  });

  test('formats Fahrenheit temperature', () => {
    expect(formatTemp(0, true)).toBe('32.0°F');
    expect(formatTemp(100, true)).toBe('212.0°F');
  });
});

describe('getTempStatus', () => {
  test('returns Cool status for temps below 40', () => {
    const status = getTempStatus(35);
    expect(status.text).toBe('Cool');
    expect(status.color).toBe('#38ef7d');
  });

  test('returns Normal status for temps 40-59', () => {
    const status = getTempStatus(50);
    expect(status.text).toBe('Normal');
    expect(status.color).toBe('#28a745');
  });

  test('returns Warm status for temps 60-79', () => {
    const status = getTempStatus(70);
    expect(status.text).toBe('Warm');
    expect(status.color).toBe('#ffc107');
  });

  test('returns Hot status for temps 80+', () => {
    const status = getTempStatus(85);
    expect(status.text).toBe('Hot');
    expect(status.color).toBe('#f5576c');
  });

  test('returns correct icons', () => {
    expect(getTempStatus(30).icon).toBe('❄️');
    expect(getTempStatus(50).icon).toBe('✅');
    expect(getTempStatus(70).icon).toBe('⚠️');
    expect(getTempStatus(90).icon).toBe('🔥');
  });
});

describe('formatThermalZoneName', () => {
  test('formats hyphenated names', () => {
    expect(formatThermalZoneName('cpu-thermal')).toBe('CPU Thermal');
    expect(formatThermalZoneName('gpu-thermal')).toBe('GPU Thermal');
  });

  test('formats underscored names', () => {
    expect(formatThermalZoneName('soc_thermal')).toBe('SoC Thermal');
  });

  test('handles known abbreviations', () => {
    expect(formatThermalZoneName('acpi-zone')).toBe('ACPI Zone');
    expect(formatThermalZoneName('pch-thermal')).toBe('PCH Thermal');
  });

  test('handles null and undefined', () => {
    expect(formatThermalZoneName(null)).toBe(null);
    expect(formatThermalZoneName(undefined)).toBe(undefined);
  });

  test('handles empty string', () => {
    expect(formatThermalZoneName('')).toBe('');
  });

  test('capitalizes unknown words', () => {
    expect(formatThermalZoneName('custom-zone')).toBe('Custom Zone');
  });
});

// =============================================================================
// Time helpers tests
// =============================================================================

describe('updateLastUpdateTime', () => {
  beforeEach(() => {
    document.body.innerHTML = '<span id="lastUpdate"></span>';
  });

  test('updates element with current time', () => {
    updateLastUpdateTime();
    const el = document.getElementById('lastUpdate');
    expect(el.textContent).toMatch(/^Last updated: \d{1,2}:\d{2}:\d{2}/);
  });

  test('uses custom element ID', () => {
    document.body.innerHTML = '<span id="customUpdate"></span>';
    updateLastUpdateTime('customUpdate');
    const el = document.getElementById('customUpdate');
    expect(el.textContent).toMatch(/^Last updated:/);
  });

  test('handles non-existent element gracefully', () => {
    expect(() => updateLastUpdateTime('nonexistent')).not.toThrow();
  });
});

// =============================================================================
// Export object tests
// =============================================================================

describe('utils export object', () => {
  test('contains all utility functions', () => {
    expect(utils.escapeHtml).toBe(escapeHtml);
    expect(utils.showElement).toBe(showElement);
    expect(utils.hideElement).toBe(hideElement);
    expect(utils.setLoading).toBe(setLoading);
    expect(utils.formatBytes).toBe(formatBytes);
    expect(utils.formatLatency).toBe(formatLatency);
    expect(utils.formatPercent).toBe(formatPercent);
    expect(utils.formatDuration).toBe(formatDuration);
    expect(utils.toFahrenheit).toBe(toFahrenheit);
    expect(utils.getTempUnit).toBe(getTempUnit);
    expect(utils.formatTemp).toBe(formatTemp);
    expect(utils.getTempStatus).toBe(getTempStatus);
    expect(utils.formatThermalZoneName).toBe(formatThermalZoneName);
    expect(utils.updateLastUpdateTime).toBe(updateLastUpdateTime);
  });
});
