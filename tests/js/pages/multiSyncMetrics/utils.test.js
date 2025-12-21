/**
 * Tests for multiSyncMetrics/utils.js
 */

import {
  formatTime,
  formatTimeSince,
  formatTimeSinceMs,
  applyQualityClass,
  updateLastRefresh,
  showPluginError,
  hidePluginError,
  toggleHelpTooltip,
  handleClickOutside,
  getDriftClass,
  getJitterClass,
  getDriftFrameClass
} from '@/pages/multiSyncMetrics/utils.js';

describe('multiSyncMetrics/utils', () => {
  describe('formatTime', () => {
    test('formats seconds as MM:SS', () => {
      expect(formatTime(0)).toBe('0:00');
      expect(formatTime(5)).toBe('0:05');
      expect(formatTime(30)).toBe('0:30');
      expect(formatTime(60)).toBe('1:00');
      expect(formatTime(65)).toBe('1:05');
      expect(formatTime(125)).toBe('2:05');
      expect(formatTime(3600)).toBe('60:00');
    });

    test('handles decimal seconds by truncating', () => {
      expect(formatTime(5.9)).toBe('0:05');
      expect(formatTime(65.7)).toBe('1:05');
    });
  });

  describe('formatTimeSince', () => {
    test('returns -- for undefined', () => {
      expect(formatTimeSince(undefined)).toBe('--');
    });

    test('returns -- for negative values', () => {
      expect(formatTimeSince(-1)).toBe('--');
      expect(formatTimeSince(-100)).toBe('--');
    });

    test('formats seconds', () => {
      expect(formatTimeSince(0)).toBe('0s');
      expect(formatTimeSince(30)).toBe('30s');
      expect(formatTimeSince(59)).toBe('59s');
    });

    test('formats minutes', () => {
      expect(formatTimeSince(60)).toBe('1m');
      expect(formatTimeSince(120)).toBe('2m');
      expect(formatTimeSince(3599)).toBe('59m');
    });

    test('formats hours', () => {
      expect(formatTimeSince(3600)).toBe('1h');
      expect(formatTimeSince(7200)).toBe('2h');
      expect(formatTimeSince(86399)).toBe('23h');
    });

    test('formats days', () => {
      expect(formatTimeSince(86400)).toBe('1d');
      expect(formatTimeSince(172800)).toBe('2d');
    });
  });

  describe('formatTimeSinceMs', () => {
    test('returns -- for undefined', () => {
      expect(formatTimeSinceMs(undefined)).toBe('--');
    });

    test('returns -- for negative values', () => {
      expect(formatTimeSinceMs(-1)).toBe('--');
    });

    test('formats milliseconds under 1 second', () => {
      expect(formatTimeSinceMs(0)).toBe('0ms');
      expect(formatTimeSinceMs(500)).toBe('500ms');
      expect(formatTimeSinceMs(999)).toBe('999ms');
    });

    test('formats seconds with decimal under 10 seconds', () => {
      expect(formatTimeSinceMs(1000)).toBe('1.0s');
      expect(formatTimeSinceMs(2500)).toBe('2.5s');
      expect(formatTimeSinceMs(9999)).toBe('10.0s');
    });

    test('formats seconds over 10 seconds', () => {
      expect(formatTimeSinceMs(10000)).toBe('10s');
      expect(formatTimeSinceMs(30000)).toBe('30s');
      expect(formatTimeSinceMs(59000)).toBe('59s');
    });

    test('formats minutes', () => {
      expect(formatTimeSinceMs(60000)).toBe('1m');
      expect(formatTimeSinceMs(120000)).toBe('2m');
    });

    test('formats hours', () => {
      expect(formatTimeSinceMs(3600000)).toBe('1h');
      expect(formatTimeSinceMs(7200000)).toBe('2h');
    });

    test('formats days', () => {
      expect(formatTimeSinceMs(86400000)).toBe('1d');
    });
  });

  describe('getDriftClass', () => {
    test('returns good for drift under 100ms', () => {
      expect(getDriftClass(0)).toBe('msm-drift-good');
      expect(getDriftClass(50)).toBe('msm-drift-good');
      expect(getDriftClass(99)).toBe('msm-drift-good');
    });

    test('returns fair for drift 100-999ms', () => {
      expect(getDriftClass(100)).toBe('msm-drift-fair');
      expect(getDriftClass(500)).toBe('msm-drift-fair');
      expect(getDriftClass(999)).toBe('msm-drift-fair');
    });

    test('returns poor for drift 1s+', () => {
      expect(getDriftClass(1000)).toBe('msm-drift-poor');
      expect(getDriftClass(5000)).toBe('msm-drift-poor');
    });
  });

  describe('getJitterClass', () => {
    test('returns good for jitter under 20ms', () => {
      expect(getJitterClass(0)).toBe('good');
      expect(getJitterClass(10)).toBe('good');
      expect(getJitterClass(19)).toBe('good');
    });

    test('returns warning for jitter 20-49ms', () => {
      expect(getJitterClass(20)).toBe('warning');
      expect(getJitterClass(35)).toBe('warning');
      expect(getJitterClass(49)).toBe('warning');
    });

    test('returns critical for jitter 50ms+', () => {
      expect(getJitterClass(50)).toBe('critical');
      expect(getJitterClass(100)).toBe('critical');
    });
  });

  describe('getDriftFrameClass', () => {
    test('returns good for drift under 5 frames', () => {
      expect(getDriftFrameClass(0)).toBe('good');
      expect(getDriftFrameClass(2.5)).toBe('good');
      expect(getDriftFrameClass(5)).toBe('good');
    });

    test('returns warning for drift 5-10 frames', () => {
      expect(getDriftFrameClass(5.1)).toBe('warning');
      expect(getDriftFrameClass(7.5)).toBe('warning');
      expect(getDriftFrameClass(10)).toBe('warning');
    });

    test('returns critical for drift over 10 frames', () => {
      expect(getDriftFrameClass(10.1)).toBe('critical');
      expect(getDriftFrameClass(15)).toBe('critical');
    });
  });

  describe('DOM manipulation functions', () => {
    beforeEach(() => {
      document.body.innerHTML = `
        <div id="lastUpdate"></div>
        <div id="pluginError" style="display: none;">
          <div id="pluginErrorMessage"></div>
        </div>
        <div id="helpTooltip"></div>
        <button class="msm-help-btn"></button>
        <span id="qualityLatency" class="msm-quality-value"></span>
      `;
    });

    describe('updateLastRefresh', () => {
      test('updates lastUpdate element with current time', () => {
        updateLastRefresh();
        const el = document.getElementById('lastUpdate');
        expect(el.textContent).toMatch(/Updated: \d{1,2}:\d{2}:\d{2}/);
      });

      test('handles missing element gracefully', () => {
        document.getElementById('lastUpdate').remove();
        expect(() => updateLastRefresh()).not.toThrow();
      });
    });

    describe('showPluginError', () => {
      test('shows error element and sets message', () => {
        showPluginError('Test error message');
        const errorEl = document.getElementById('pluginError');
        const msgEl = document.getElementById('pluginErrorMessage');
        expect(errorEl.style.display).toBe('flex');
        expect(msgEl.textContent).toBe('Test error message');
      });
    });

    describe('hidePluginError', () => {
      test('hides error element', () => {
        const errorEl = document.getElementById('pluginError');
        errorEl.style.display = 'flex';
        hidePluginError();
        expect(errorEl.style.display).toBe('none');
      });
    });

    describe('toggleHelpTooltip', () => {
      test('adds show class when not present', () => {
        const tooltip = document.getElementById('helpTooltip');
        toggleHelpTooltip();
        expect(tooltip.classList.contains('show')).toBe(true);
      });

      test('removes show class when present', () => {
        const tooltip = document.getElementById('helpTooltip');
        tooltip.classList.add('show');
        toggleHelpTooltip();
        expect(tooltip.classList.contains('show')).toBe(false);
      });
    });

    describe('handleClickOutside', () => {
      test('closes tooltip when clicking outside', () => {
        const tooltip = document.getElementById('helpTooltip');
        tooltip.classList.add('show');

        const event = new MouseEvent('click', { bubbles: true });
        Object.defineProperty(event, 'target', { value: document.body });

        handleClickOutside(event);
        expect(tooltip.classList.contains('show')).toBe(false);
      });

      test('keeps tooltip open when clicking inside', () => {
        const tooltip = document.getElementById('helpTooltip');
        tooltip.classList.add('show');

        const event = new MouseEvent('click', { bubbles: true });
        Object.defineProperty(event, 'target', { value: tooltip });

        handleClickOutside(event);
        expect(tooltip.classList.contains('show')).toBe(true);
      });

      test('keeps tooltip open when clicking help button', () => {
        const tooltip = document.getElementById('helpTooltip');
        tooltip.classList.add('show');
        const helpBtn = document.querySelector('.msm-help-btn');

        const event = new MouseEvent('click', { bubbles: true });
        Object.defineProperty(event, 'target', { value: helpBtn });

        handleClickOutside(event);
        expect(tooltip.classList.contains('show')).toBe(true);
      });
    });

    describe('applyQualityClass', () => {
      test('applies quality class to element', () => {
        applyQualityClass('qualityLatency', 'good');
        const el = document.getElementById('qualityLatency');
        expect(el.classList.contains('msm-quality-value')).toBe(true);
        expect(el.classList.contains('msm-quality-good')).toBe(true);
      });

      test('handles null quality', () => {
        applyQualityClass('qualityLatency', null);
        const el = document.getElementById('qualityLatency');
        expect(el.classList.contains('msm-quality-value')).toBe(true);
        expect(el.classList.length).toBe(1);
      });

      test('handles missing element', () => {
        expect(() => applyQualityClass('nonexistent', 'good')).not.toThrow();
      });
    });
  });
});
