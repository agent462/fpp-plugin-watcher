/**
 * Events Page Module
 *
 * MQTT events dashboard showing sequences, playlists, and event timeline.
 * Extracted from eventsUI.php
 */

import {
  escapeHtml,
  showElement,
  hideElement,
  formatDuration,
} from '../core/utils.js';
import {
  buildChartOptions,
  createDataset,
  updateOrCreateChart,
} from '../core/charts.js';
import { fetchJson } from '../core/api.js';

// =============================================================================
// Module State
// =============================================================================

let state = {
  charts: {},
  isRefreshing: false,
  refreshInterval: null,
  allEvents: [],
  displayedEvents: 50,
  config: {},
};

// =============================================================================
// Helper Functions
// =============================================================================

/**
 * Get CSS class for event badge based on event type
 * @param {string} eventType - Event type code
 * @returns {string} - CSS class name
 */
function getBadgeClass(eventType) {
  const classes = {
    ss: 'seq-start',
    se: 'seq-stop',
    ps: 'pl-start',
    pe: 'pl-stop',
    st: 'status',
    ms: 'media-start',
    me: 'media-stop',
    wn: 'warning',
  };
  return classes[eventType] || '';
}

// =============================================================================
// Data Loading Functions
// =============================================================================

async function loadStats() {
  const hours = document.getElementById('timeRange')?.value || '24';
  const url = `/api/plugin/fpp-plugin-watcher/mqtt/stats?hours=${hours}`;

  const response = await fetchJson(url);
  if (response?.success && response.stats) {
    const stats = response.stats;

    const setTextContent = (id, text) => {
      const el = document.getElementById(id);
      if (el) el.textContent = text;
    };

    setTextContent('totalEvents', stats.totalEvents || 0);
    setTextContent('sequencesPlayed', Object.keys(stats.sequencesPlayed || {}).length);
    setTextContent('playlistsStarted', Object.keys(stats.playlistsStarted || {}).length);
    setTextContent('totalRuntime', formatDuration(stats.totalRuntime || 0));

    // Update timeline chart
    updateTimelineChart(stats.hourlyDistribution || []);

    // Update top sequences
    updateTopSequences(stats.sequencesPlayed || {});
  }
}

async function loadEvents() {
  const hours = document.getElementById('timeRange')?.value || '24';
  const url = `/api/plugin/fpp-plugin-watcher/mqtt/events?hours=${hours}`;

  const response = await fetchJson(url);
  if (response?.success) {
    state.allEvents = response.data || [];
    state.displayedEvents = 50;
    updateEventsTable();
    const eventCountEl = document.getElementById('eventCount');
    if (eventCountEl) eventCountEl.textContent = state.allEvents.length + ' events';
  }
}

// =============================================================================
// Chart Update Functions
// =============================================================================

function updateTimelineChart(data) {
  const chartData = data.map((d) => ({
    x: new Date(d.timestamp * 1000),
    y: d.count,
  }));

  const hours = parseInt(document.getElementById('timeRange')?.value || '24');
  const datasets = [createDataset('Events', chartData, 'blue', { pointRadius: 2 })];
  const options = buildChartOptions(hours, {
    yLabel: 'Events',
    beginAtZero: true,
    showLegend: false,
  });

  updateOrCreateChart(state.charts, 'timeline', 'timelineChart', 'line', datasets, options);
}

function updateTopSequences(sequences) {
  const container = document.getElementById('topSequences');
  if (!container) return;

  const entries = Object.entries(sequences).slice(0, 10);

  if (entries.length === 0) {
    container.innerHTML = '<p class="noData">No sequence data available</p>';
    return;
  }

  container.innerHTML = entries
    .map(
      ([name, count], index) => `
        <div class="topItem">
            <span class="rank">${index + 1}</span>
            <span class="name">${escapeHtml(name)}</span>
            <span class="count">${count} plays</span>
        </div>
    `
    )
    .join('');
}

function updateEventsTable() {
  const tbody = document.getElementById('eventsTableBody');
  if (!tbody) return;

  const eventsToShow = state.allEvents.slice(0, state.displayedEvents);

  if (eventsToShow.length === 0) {
    tbody.innerHTML = '<tr><td colspan="5" class="noData">No events found</td></tr>';
    hideElement('showMoreContainer');
    return;
  }

  tbody.innerHTML = eventsToShow
    .map((event) => {
      const badgeClass = getBadgeClass(event.eventType);
      const durationStr = event.duration ? formatDuration(event.duration) : '';
      return `
            <tr>
                <td>${escapeHtml(event.datetime)}</td>
                <td>${escapeHtml(event.hostname)}</td>
                <td><span class="eventBadge ${badgeClass}">${escapeHtml(event.eventLabel)}</span></td>
                <td>${escapeHtml(event.data || '-')}</td>
                <td>${durationStr}</td>
            </tr>
        `;
    })
    .join('');

  // Show/hide "Show More" button
  if (state.allEvents.length > state.displayedEvents) {
    showElement('showMoreContainer');
  } else {
    hideElement('showMoreContainer');
  }
}

function showMoreEvents() {
  state.displayedEvents += 50;
  updateEventsTable();
}

// =============================================================================
// Main Load Function
// =============================================================================

async function loadAllData() {
  if (state.isRefreshing) return;
  state.isRefreshing = true;

  const refreshBtn = document.querySelector('.refreshButton i');
  if (refreshBtn) refreshBtn.style.animation = 'spin 1s linear infinite';

  try {
    await Promise.all([loadStats(), loadEvents()]);
    hideElement('loadingIndicator');
    showElement('metricsContent');
    const lastUpdateEl = document.getElementById('lastUpdate');
    if (lastUpdateEl) lastUpdateEl.textContent = 'Updated: ' + new Date().toLocaleTimeString();
  } catch (error) {
    console.error('Error loading data:', error);
  } finally {
    state.isRefreshing = false;
    if (refreshBtn) refreshBtn.style.animation = '';
  }
}

// =============================================================================
// Public Interface
// =============================================================================

export const events = {
  pageId: 'eventsUI',

  /**
   * Initialize the events page
   * @param {Object} config - Configuration from PHP
   */
  init(config = {}) {
    state.config = config;

    // Load data on DOM ready
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', loadAllData);
    } else {
      loadAllData();
    }

    // Auto-refresh every 30 seconds
    state.refreshInterval = setInterval(loadAllData, 30000);
  },

  /**
   * Cleanup and reset state
   */
  destroy() {
    if (state.refreshInterval) {
      clearInterval(state.refreshInterval);
    }
    Object.values(state.charts).forEach((c) => c?.destroy?.());
    state = {
      charts: {},
      isRefreshing: false,
      refreshInterval: null,
      allEvents: [],
      displayedEvents: 50,
      config: {},
    };
  },

  // Public methods for onclick handlers
  refresh: loadAllData,
  showMoreEvents,
};
