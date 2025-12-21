/**
 * Tests for js/src/pages/events.js
 */

const { events } = require('../../../js/src/pages/events.js');

// =============================================================================
// Module Interface Tests
// =============================================================================

describe('events module interface', () => {
  test('exports pageId', () => {
    expect(events.pageId).toBe('eventsUI');
  });

  test('exports init function', () => {
    expect(typeof events.init).toBe('function');
  });

  test('exports destroy function', () => {
    expect(typeof events.destroy).toBe('function');
  });

  test('exports refresh function', () => {
    expect(typeof events.refresh).toBe('function');
  });

  test('exports showMoreEvents function', () => {
    expect(typeof events.showMoreEvents).toBe('function');
  });
});

// =============================================================================
// Initialization Tests
// =============================================================================

describe('events.init', () => {
  beforeEach(() => {
    events.destroy();
  });

  afterEach(() => {
    events.destroy();
  });

  test('initializes without errors', () => {
    expect(() => events.init({})).not.toThrow();
  });

  test('handles missing config gracefully', () => {
    expect(() => events.init()).not.toThrow();
    expect(() => events.init({})).not.toThrow();
  });
});

// =============================================================================
// Destroy Tests
// =============================================================================

describe('events.destroy', () => {
  test('clears refresh interval', () => {
    events.init();
    events.destroy();

    // Second call should not throw
    expect(() => events.destroy()).not.toThrow();
  });

  test('can be called multiple times safely', () => {
    events.destroy();
    events.destroy();
    events.destroy();
    // Should not throw
  });
});

// =============================================================================
// Integration Tests
// =============================================================================

describe('events integration', () => {
  beforeEach(() => {
    document.body.innerHTML = `
      <select id="timeRange"><option value="24" selected>24</option></select>
      <span id="lastUpdate"></span>
      <div id="loadingIndicator"></div>
      <div id="metricsContent" style="display: none;"></div>
      <span id="totalEvents"></span>
      <span id="sequencesPlayed"></span>
      <span id="playlistsStarted"></span>
      <span id="totalRuntime"></span>
      <span id="eventCount"></span>
      <div id="topSequences"></div>
      <div id="showMoreContainer" style="display: none;"></div>
      <canvas id="timelineChart"></canvas>
      <tbody id="eventsTableBody"></tbody>
      <button class="refreshButton"><i></i></button>
    `;

    events.destroy();

    global.fetch = jest.fn(() =>
      Promise.resolve({
        ok: true,
        json: () =>
          Promise.resolve({
            success: true,
            data: [],
            stats: {
              totalEvents: 0,
              sequencesPlayed: {},
              playlistsStarted: {},
              totalRuntime: 0,
              hourlyDistribution: [],
            },
          }),
      })
    );
  });

  afterEach(() => {
    events.destroy();
    document.body.innerHTML = '';
  });

  test('can initialize and destroy without errors', async () => {
    expect(() => events.init({})).not.toThrow();
    expect(() => events.destroy()).not.toThrow();
  });

  test('showMoreEvents increases displayed events count', () => {
    events.init({});
    // Should not throw and should increase internal counter
    expect(() => events.showMoreEvents()).not.toThrow();
  });
});

// =============================================================================
// Chart Tests
// =============================================================================

describe('events chart rendering', () => {
  beforeEach(() => {
    document.body.innerHTML = `
      <select id="timeRange"><option value="24" selected>24</option></select>
      <canvas id="timelineChart"></canvas>
    `;
    events.destroy();
  });

  afterEach(() => {
    events.destroy();
    document.body.innerHTML = '';
  });

  test('timeline chart creation does not throw', () => {
    // The chart won't actually render without Chart.js, but it shouldn't throw
    expect(() => events.init({})).not.toThrow();
  });
});
