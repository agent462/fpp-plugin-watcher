/**
 * Tests for multiSyncMetrics/charts.js
 */

import {
  showPingDisabledMessage,
  isPingEnabled,
  updateQualityCard
} from '@/pages/multiSyncMetrics/charts.js';
import { initState, resetState } from '@/pages/multiSyncMetrics/state.js';

describe('multiSyncMetrics/charts', () => {
  beforeEach(() => {
    // Reset state before each test
    resetState();

    // Setup minimal DOM
    document.body.innerHTML = `
      <div class="msm-container">
        <div class="msm-card-body">
          <canvas id="latencyJitterChart"></canvas>
        </div>
        <div class="msm-card-body">
          <canvas id="packetLossChart"></canvas>
        </div>
        <span id="overallQuality" class="msm-quality-indicator"></span>
        <span id="qualityLatency"></span>
        <span id="qualityJitter"></span>
        <span id="qualityPacketLoss"></span>
      </div>
    `;
  });

  afterEach(() => {
    resetState();
  });

  describe('isPingEnabled', () => {
    test('returns true when multiSyncPingEnabled is true', () => {
      initState({ multiSyncPingEnabled: true });
      expect(isPingEnabled()).toBe(true);
    });

    test('returns false when multiSyncPingEnabled is false', () => {
      initState({ multiSyncPingEnabled: false });
      expect(isPingEnabled()).toBe(false);
    });

    test('returns false when not configured', () => {
      initState({});
      expect(isPingEnabled()).toBe(false);
    });
  });

  describe('showPingDisabledMessage', () => {
    test('replaces latencyJitterChart canvas with message', () => {
      showPingDisabledMessage();

      const canvas = document.getElementById('latencyJitterChart');
      expect(canvas).toBeNull();

      const message = document.querySelector('.msm-ping-disabled');
      expect(message).not.toBeNull();
    });

    test('replaces packetLossChart canvas with message', () => {
      showPingDisabledMessage();

      const canvas = document.getElementById('packetLossChart');
      expect(canvas).toBeNull();

      const messages = document.querySelectorAll('.msm-ping-disabled');
      expect(messages.length).toBe(2);
    });

    test('displays correct message text', () => {
      showPingDisabledMessage();

      const message = document.querySelector('.msm-ping-disabled strong');
      expect(message.textContent).toBe('Remote Ping is disabled');
    });

    test('includes link to settings page', () => {
      showPingDisabledMessage();

      const link = document.querySelector('.msm-ping-disabled a');
      expect(link).not.toBeNull();
      expect(link.href).toContain('plugin.php?plugin=fpp-plugin-watcher&page=configUI.php');
    });

    test('updates quality indicator to Disabled', () => {
      showPingDisabledMessage();

      const qualityEl = document.getElementById('overallQuality');
      expect(qualityEl.textContent).toBe('Disabled');
      expect(qualityEl.className).toContain('msm-quality-unknown');
    });

    test('clears quality metric values', () => {
      // Set initial values
      document.getElementById('qualityLatency').textContent = '50ms';
      document.getElementById('qualityJitter').textContent = '10ms';
      document.getElementById('qualityPacketLoss').textContent = '1%';

      showPingDisabledMessage();

      expect(document.getElementById('qualityLatency').textContent).toBe('--');
      expect(document.getElementById('qualityJitter').textContent).toBe('--');
      expect(document.getElementById('qualityPacketLoss').textContent).toBe('--');
    });

    test('handles missing DOM elements gracefully', () => {
      // Clear the DOM
      document.body.innerHTML = '';

      // Should not throw
      expect(() => showPingDisabledMessage()).not.toThrow();
    });
  });

  describe('updateQualityCard', () => {
    test('updates quality indicator with overallQuality', () => {
      updateQualityCard({
        summary: {
          overallQuality: 'good',
          avgLatency: 25,
          avgJitter: 5,
          avgPacketLoss: 0
        }
      });

      const qualityEl = document.getElementById('overallQuality');
      expect(qualityEl.textContent).toBe('Good');
      expect(qualityEl.className).toContain('msm-quality-good');
    });

    test('displays latency value', () => {
      updateQualityCard({
        summary: {
          avgLatency: 50
        }
      });

      const latencyEl = document.getElementById('qualityLatency');
      expect(latencyEl.textContent).toBe('50ms');
    });

    test('displays jitter value', () => {
      updateQualityCard({
        summary: {
          avgJitter: 15
        }
      });

      const jitterEl = document.getElementById('qualityJitter');
      expect(jitterEl.textContent).toBe('15ms');
    });

    test('displays packet loss value', () => {
      updateQualityCard({
        summary: {
          avgPacketLoss: 2.5
        }
      });

      const packetLossEl = document.getElementById('qualityPacketLoss');
      expect(packetLossEl.textContent).toBe('2.5%');
    });

    test('shows -- for null values', () => {
      updateQualityCard({
        summary: {
          avgLatency: null,
          avgJitter: null,
          avgPacketLoss: null
        }
      });

      expect(document.getElementById('qualityLatency').textContent).toBe('--');
      expect(document.getElementById('qualityJitter').textContent).toBe('--');
      expect(document.getElementById('qualityPacketLoss').textContent).toBe('--');
    });

    test('handles missing summary gracefully', () => {
      expect(() => updateQualityCard({})).not.toThrow();
    });

    test('handles missing DOM elements gracefully', () => {
      document.body.innerHTML = '';
      expect(() => updateQualityCard({ summary: { avgLatency: 50 } })).not.toThrow();
    });
  });
});
