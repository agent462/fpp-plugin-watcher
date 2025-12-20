/**
 * Tests for js/commonUI.js
 */

describe('commonUI.js', () => {
  beforeEach(() => {
    // Load the script fresh for each test
    loadScript('js/commonUI.js');
  });

  describe('escapeHtml', () => {
    test('escapes HTML special characters', () => {
      expect(window.escapeHtml('<script>alert("xss")</script>')).toBe(
        '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;'
      );
    });

    test('escapes ampersand', () => {
      expect(window.escapeHtml('foo & bar')).toBe('foo &amp; bar');
    });

    test('escapes single quotes', () => {
      expect(window.escapeHtml("it's")).toBe('it&#039;s');
    });

    test('returns empty string for null', () => {
      expect(window.escapeHtml(null)).toBe('');
    });

    test('returns empty string for undefined', () => {
      expect(window.escapeHtml(undefined)).toBe('');
    });

    test('converts numbers to string', () => {
      expect(window.escapeHtml(123)).toBe('123');
    });

    test('handles empty string', () => {
      expect(window.escapeHtml('')).toBe('');
    });
  });

  describe('formatBytes', () => {
    test('formats 0 bytes', () => {
      expect(window.formatBytes(0)).toBe('0 Bytes');
    });

    test('formats null as 0 bytes', () => {
      expect(window.formatBytes(null)).toBe('0 Bytes');
    });

    test('formats bytes', () => {
      expect(window.formatBytes(500)).toBe('500.00 Bytes');
    });

    test('formats kilobytes', () => {
      expect(window.formatBytes(1024)).toBe('1.00 KB');
    });

    test('formats megabytes', () => {
      expect(window.formatBytes(1024 * 1024)).toBe('1.00 MB');
    });

    test('formats gigabytes', () => {
      expect(window.formatBytes(1024 * 1024 * 1024)).toBe('1.00 GB');
    });

    test('formats terabytes', () => {
      expect(window.formatBytes(1024 * 1024 * 1024 * 1024)).toBe('1.00 TB');
    });
  });

  describe('formatLatency', () => {
    test('formats latency with 2 decimal places', () => {
      expect(window.formatLatency(12.345)).toBe('12.35 ms');
    });

    test('returns placeholder for null', () => {
      expect(window.formatLatency(null)).toBe('-- ms');
    });

    test('returns placeholder for undefined', () => {
      expect(window.formatLatency(undefined)).toBe('-- ms');
    });

    test('formats zero latency', () => {
      expect(window.formatLatency(0)).toBe('0.00 ms');
    });
  });

  describe('formatPercent', () => {
    test('formats percentage with default 1 decimal', () => {
      expect(window.formatPercent(75.567)).toBe('75.6%');
    });

    test('formats percentage with custom decimals', () => {
      expect(window.formatPercent(75.567, 2)).toBe('75.57%');
    });

    test('returns placeholder for null', () => {
      expect(window.formatPercent(null)).toBe('--%');
    });

    test('returns placeholder for undefined', () => {
      expect(window.formatPercent(undefined)).toBe('--%');
    });

    test('formats 100%', () => {
      expect(window.formatPercent(100)).toBe('100.0%');
    });
  });

  describe('formatDuration', () => {
    test('formats seconds only', () => {
      expect(window.formatDuration(45)).toBe('45s');
    });

    test('formats minutes and seconds', () => {
      expect(window.formatDuration(125)).toBe('2m 5s');
    });

    test('formats hours, minutes, and seconds', () => {
      expect(window.formatDuration(3725)).toBe('1h 2m 5s');
    });

    test('returns placeholder for 0', () => {
      expect(window.formatDuration(0)).toBe('--');
    });

    test('returns placeholder for null', () => {
      expect(window.formatDuration(null)).toBe('--');
    });

    test('returns placeholder for negative values', () => {
      expect(window.formatDuration(-5)).toBe('--');
    });
  });

  describe('toFahrenheit', () => {
    test('converts 0C to 32F', () => {
      expect(window.toFahrenheit(0)).toBe(32);
    });

    test('converts 100C to 212F', () => {
      expect(window.toFahrenheit(100)).toBe(212);
    });

    test('converts 37C to 98.6F', () => {
      expect(window.toFahrenheit(37)).toBeCloseTo(98.6, 1);
    });
  });

  describe('getTempUnit', () => {
    test('returns F for fahrenheit', () => {
      expect(window.getTempUnit(true)).toBe('°F');
    });

    test('returns C for celsius', () => {
      expect(window.getTempUnit(false)).toBe('°C');
    });
  });

  describe('formatTemp', () => {
    test('formats celsius temperature', () => {
      expect(window.formatTemp(45.5, false)).toBe('45.5°C');
    });

    test('formats fahrenheit temperature', () => {
      expect(window.formatTemp(0, true)).toBe('32.0°F');
    });
  });

  describe('getTempStatus', () => {
    test('returns cool for under 40C', () => {
      const status = window.getTempStatus(35);
      expect(status.text).toBe('Cool');
      expect(status.icon).toBe('❄️');
    });

    test('returns normal for 40-60C', () => {
      const status = window.getTempStatus(50);
      expect(status.text).toBe('Normal');
      expect(status.icon).toBe('✅');
    });

    test('returns warm for 60-80C', () => {
      const status = window.getTempStatus(70);
      expect(status.text).toBe('Warm');
      expect(status.icon).toBe('⚠️');
    });

    test('returns hot for 80C+', () => {
      const status = window.getTempStatus(85);
      expect(status.text).toBe('Hot');
      expect(status.icon).toBe('🔥');
    });
  });

  describe('formatThermalZoneName', () => {
    test('formats cpu-thermal', () => {
      expect(window.formatThermalZoneName('cpu-thermal')).toBe('CPU Thermal');
    });

    test('formats gpu_temp', () => {
      expect(window.formatThermalZoneName('gpu_temp')).toBe('GPU Temp');
    });

    test('formats soc-thermal', () => {
      expect(window.formatThermalZoneName('soc-thermal')).toBe('SoC Thermal');
    });

    test('returns null/undefined as-is', () => {
      expect(window.formatThermalZoneName(null)).toBe(null);
      expect(window.formatThermalZoneName(undefined)).toBe(undefined);
    });

    test('handles empty string', () => {
      expect(window.formatThermalZoneName('')).toBe('');
    });
  });

  describe('CHART_COLORS', () => {
    test('has expected color entries', () => {
      expect(window.CHART_COLORS).toHaveProperty('purple');
      expect(window.CHART_COLORS).toHaveProperty('red');
      expect(window.CHART_COLORS).toHaveProperty('green');
      expect(window.CHART_COLORS).toHaveProperty('blue');
    });

    test('colors have border and bg properties', () => {
      expect(window.CHART_COLORS.purple).toHaveProperty('border');
      expect(window.CHART_COLORS.purple).toHaveProperty('bg');
    });
  });

  describe('getChartColor', () => {
    test('returns color for index 0', () => {
      const color = window.getChartColor(0);
      expect(color).toHaveProperty('border');
      expect(color).toHaveProperty('bg');
    });

    test('wraps around for large indices', () => {
      const color0 = window.getChartColor(0);
      const color8 = window.getChartColor(8);
      expect(color0).toEqual(color8);
    });
  });

  describe('createDataset', () => {
    test('creates dataset with label and data', () => {
      const data = [{ x: 1, y: 10 }];
      const dataset = window.createDataset('Test', data, 'purple');

      expect(dataset.label).toBe('Test');
      expect(dataset.data).toBe(data);
      expect(dataset.borderWidth).toBe(2);
      expect(dataset.fill).toBe(true);
    });

    test('accepts color as string name', () => {
      const dataset = window.createDataset('Test', [], 'red');
      expect(dataset.borderColor).toBe(window.CHART_COLORS.red.border);
    });

    test('accepts color as object', () => {
      const customColor = { border: '#123456', bg: '#654321' };
      const dataset = window.createDataset('Test', [], customColor);
      expect(dataset.borderColor).toBe('#123456');
    });

    test('accepts options override', () => {
      const dataset = window.createDataset('Test', [], 'blue', { fill: false, pointRadius: 3 });
      expect(dataset.fill).toBe(false);
      expect(dataset.pointRadius).toBe(3);
    });
  });

  describe('mapChartData', () => {
    test('maps payload data to chart format', () => {
      const payload = {
        data: [
          { timestamp: 1000, value: 50 },
          { timestamp: 2000, value: 60 }
        ]
      };

      const result = window.mapChartData(payload, 'value');
      expect(result).toEqual([
        { x: 1000000, y: 50 },
        { x: 2000000, y: 60 }
      ]);
    });

    test('handles null payload', () => {
      const result = window.mapChartData(null, 'value');
      expect(result).toEqual([]);
    });

    test('handles missing data property', () => {
      const result = window.mapChartData({}, 'value');
      expect(result).toEqual([]);
    });
  });

  describe('buildChartOptions', () => {
    test('returns options object with scales', () => {
      const options = window.buildChartOptions(24);
      expect(options).toHaveProperty('scales');
      expect(options.scales).toHaveProperty('x');
      expect(options.scales).toHaveProperty('y');
    });

    test('sets time unit based on hours', () => {
      const options1h = window.buildChartOptions(1);
      expect(options1h.scales.x.time.unit).toBe('minute');

      const options24h = window.buildChartOptions(24);
      expect(options24h.scales.x.time.unit).toBe('hour');

      const options48h = window.buildChartOptions(48);
      expect(options48h.scales.x.time.unit).toBe('day');

      const options200h = window.buildChartOptions(200);
      expect(options200h.scales.x.time.unit).toBe('week');
    });

    test('accepts config options', () => {
      const options = window.buildChartOptions(24, {
        yLabel: 'Amps',
        beginAtZero: true,
        yMax: 100,
        showLegend: false
      });

      expect(options.scales.y.title.text).toBe('Amps');
      expect(options.scales.y.beginAtZero).toBe(true);
      expect(options.scales.y.max).toBe(100);
      expect(options.plugins.legend.display).toBe(false);
    });
  });

  describe('showElement / hideElement / setLoading', () => {
    beforeEach(() => {
      document.body.innerHTML = '<div id="testEl" style="display: none;"></div>';
    });

    test('showElement sets display to block', () => {
      window.showElement('testEl');
      expect(document.getElementById('testEl').style.display).toBe('block');
    });

    test('hideElement sets display to none', () => {
      document.getElementById('testEl').style.display = 'block';
      window.hideElement('testEl');
      expect(document.getElementById('testEl').style.display).toBe('none');
    });

    test('setLoading toggles flex display', () => {
      window.setLoading('testEl', true);
      expect(document.getElementById('testEl').style.display).toBe('flex');

      window.setLoading('testEl', false);
      expect(document.getElementById('testEl').style.display).toBe('none');
    });

    test('handles non-existent element gracefully', () => {
      expect(() => window.showElement('nonexistent')).not.toThrow();
      expect(() => window.hideElement('nonexistent')).not.toThrow();
      expect(() => window.setLoading('nonexistent', true)).not.toThrow();
    });
  });

  describe('fetchJson', () => {
    test('fetches and parses JSON', async () => {
      const mockData = { success: true, value: 42 };
      global.fetch.mockResolvedValueOnce({
        ok: true,
        json: () => Promise.resolve(mockData)
      });

      const result = await window.fetchJson('/api/test');
      expect(result).toEqual(mockData);
      expect(global.fetch).toHaveBeenCalledWith('/api/test', expect.objectContaining({
        cache: 'no-store'
      }));
    });

    test('throws on HTTP error', async () => {
      global.fetch.mockResolvedValueOnce({
        ok: false,
        status: 404
      });

      await expect(window.fetchJson('/api/notfound')).rejects.toThrow('HTTP 404');
    });

    test('throws on network error', async () => {
      global.fetch.mockRejectedValueOnce(new Error('Network error'));

      await expect(window.fetchJson('/api/test')).rejects.toThrow('Network error');
    });
  });

  describe('updateLastUpdateTime', () => {
    beforeEach(() => {
      document.body.innerHTML = '<span id="lastUpdate"></span>';
    });

    test('updates element with current time', () => {
      window.updateLastUpdateTime('lastUpdate');
      const text = document.getElementById('lastUpdate').textContent;
      expect(text).toMatch(/^Last updated:/);
    });

    test('handles non-existent element', () => {
      expect(() => window.updateLastUpdateTime('nonexistent')).not.toThrow();
    });
  });

  describe('createRefreshController', () => {
    beforeEach(() => {
      jest.useFakeTimers();
    });

    test('creates controller with refresh method', () => {
      const refreshFn = jest.fn().mockResolvedValue();
      const controller = window.createRefreshController(refreshFn);

      expect(controller).toHaveProperty('refresh');
      expect(controller).toHaveProperty('startAutoRefresh');
      expect(controller).toHaveProperty('stopAutoRefresh');
    });

    test('calls refresh function', async () => {
      const refreshFn = jest.fn().mockResolvedValue();
      const controller = window.createRefreshController(refreshFn);

      await controller.refresh();
      expect(refreshFn).toHaveBeenCalled();
    });

    test('prevents concurrent refreshes', async () => {
      let resolveRefresh;
      const refreshFn = jest.fn(() => new Promise(r => { resolveRefresh = r; }));
      const controller = window.createRefreshController(refreshFn);

      // Start first refresh
      const firstRefresh = controller.refresh();
      expect(controller.isRefreshing).toBe(true);

      // Try to start second refresh - should not call again
      controller.refresh();
      expect(refreshFn).toHaveBeenCalledTimes(1);

      // Complete first refresh
      resolveRefresh();
      await firstRefresh;
      expect(controller.isRefreshing).toBe(false);
    });

    test('auto-refresh calls at interval', async () => {
      const refreshFn = jest.fn().mockResolvedValue();
      const controller = window.createRefreshController(refreshFn, 1000);

      controller.startAutoRefresh();
      expect(refreshFn).not.toHaveBeenCalled();

      // First interval
      jest.advanceTimersByTime(1000);
      await Promise.resolve(); // Allow async refresh to complete
      expect(refreshFn).toHaveBeenCalledTimes(1);

      // Second interval
      jest.advanceTimersByTime(1000);
      await Promise.resolve();
      expect(refreshFn).toHaveBeenCalledTimes(2);

      // After stopping, no more calls
      controller.stopAutoRefresh();
      jest.advanceTimersByTime(1000);
      expect(refreshFn).toHaveBeenCalledTimes(2);
    });
  });

  describe('withButtonLoading', () => {
    test('disables button and shows spinner during async operation', async () => {
      document.body.innerHTML = '<button id="btn"><i class="fas fa-sync"></i></button>';
      const btn = document.getElementById('btn');
      const asyncFn = jest.fn().mockResolvedValue('result');

      const result = await window.withButtonLoading(btn, 'fas fa-sync', asyncFn);

      expect(result).toBe('result');
      expect(asyncFn).toHaveBeenCalled();
      // Button should be re-enabled after
      expect(btn.disabled).toBe(false);
      expect(btn.querySelector('i').className).toBe('fas fa-sync');
    });

    test('restores button state even on error', async () => {
      document.body.innerHTML = '<button id="btn"><i class="fas fa-sync"></i></button>';
      const btn = document.getElementById('btn');
      const asyncFn = jest.fn().mockRejectedValue(new Error('fail'));

      await expect(window.withButtonLoading(btn, 'fas fa-sync', asyncFn)).rejects.toThrow('fail');

      expect(btn.disabled).toBe(false);
    });

    test('handles null button gracefully', async () => {
      const asyncFn = jest.fn().mockResolvedValue('ok');
      const result = await window.withButtonLoading(null, 'icon', asyncFn);
      expect(result).toBe('ok');
    });
  });
});
