/**
 * Tests for js/src/core/api.js
 */

const {
  fetchJson,
  withButtonLoading,
  createRefreshController,
  loadTemperaturePreference,
  api,
} = require('../../../js/src/core/api.js');

// =============================================================================
// fetchJson tests
// =============================================================================

describe('fetchJson', () => {
  beforeEach(() => {
    jest.useFakeTimers();
    global.fetch.mockReset();
  });

  afterEach(() => {
    jest.useRealTimers();
  });

  test('fetches JSON data successfully', async () => {
    const mockData = { key: 'value' };
    global.fetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve(mockData),
    });

    const result = await fetchJson('/api/test');

    expect(result).toEqual(mockData);
    expect(global.fetch).toHaveBeenCalledWith('/api/test', expect.objectContaining({
      cache: 'no-store',
    }));
  });

  test('throws error on non-OK response', async () => {
    global.fetch.mockResolvedValueOnce({
      ok: false,
      status: 404,
    });

    await expect(fetchJson('/api/test')).rejects.toThrow('HTTP 404');
  });

  test('throws error on network failure', async () => {
    global.fetch.mockRejectedValueOnce(new Error('Network error'));

    await expect(fetchJson('/api/test')).rejects.toThrow('Network error');
  });

  test('uses custom timeout', async () => {
    global.fetch.mockImplementation(() => new Promise(() => {})); // Never resolves

    const promise = fetchJson('/api/test', 5000);

    // Advance past the timeout
    jest.advanceTimersByTime(5001);

    // The abort should have been called
    expect(global.AbortController).toHaveBeenCalled();
  });

  test('clears timeout on success', async () => {
    global.fetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({}),
    });

    await fetchJson('/api/test');

    // If we advance timers after resolution, nothing should happen
    jest.advanceTimersByTime(20000);
    // No additional assertions needed - just verify no errors
  });

  test('passes abort signal to fetch', async () => {
    global.fetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({}),
    });

    await fetchJson('/api/test');

    expect(global.fetch).toHaveBeenCalledWith('/api/test', expect.objectContaining({
      signal: expect.anything(),
    }));
  });
});

// =============================================================================
// withButtonLoading tests
// =============================================================================

describe('withButtonLoading', () => {
  test('shows loading state and restores on success', async () => {
    const mockIcon = { className: 'fas fa-sync-alt' };
    const mockBtn = {
      querySelector: jest.fn(() => mockIcon),
      disabled: false,
    };

    const asyncFn = jest.fn().mockResolvedValue('result');

    const result = await withButtonLoading(mockBtn, 'fas fa-sync-alt', asyncFn);

    expect(result).toBe('result');
    expect(mockIcon.className).toBe('fas fa-sync-alt');
    expect(mockBtn.disabled).toBe(false);
  });

  test('restores state on error', async () => {
    const mockIcon = { className: 'fas fa-sync-alt' };
    const mockBtn = {
      querySelector: jest.fn(() => mockIcon),
      disabled: false,
    };

    const asyncFn = jest.fn().mockRejectedValue(new Error('Test error'));

    await expect(withButtonLoading(mockBtn, 'fas fa-sync-alt', asyncFn)).rejects.toThrow('Test error');

    expect(mockIcon.className).toBe('fas fa-sync-alt');
    expect(mockBtn.disabled).toBe(false);
  });

  test('handles button without icon', async () => {
    const mockBtn = {
      querySelector: jest.fn(() => null),
      disabled: false,
    };

    const asyncFn = jest.fn().mockResolvedValue('result');

    const result = await withButtonLoading(mockBtn, 'fas fa-sync-alt', asyncFn);

    expect(result).toBe('result');
    expect(mockBtn.disabled).toBe(false);
  });

  test('handles null button gracefully', async () => {
    const asyncFn = jest.fn().mockResolvedValue('result');

    const result = await withButtonLoading(null, 'fas fa-sync-alt', asyncFn);

    expect(result).toBe('result');
  });

  test('sets spinner class during loading', async () => {
    const mockIcon = { className: 'fas fa-sync-alt' };
    const mockBtn = {
      querySelector: jest.fn(() => mockIcon),
      disabled: false,
    };

    let iconClassDuringLoad = null;
    let disabledDuringLoad = null;

    const asyncFn = jest.fn().mockImplementation(() => {
      iconClassDuringLoad = mockIcon.className;
      disabledDuringLoad = mockBtn.disabled;
      return Promise.resolve();
    });

    await withButtonLoading(mockBtn, 'fas fa-sync-alt', asyncFn);

    expect(iconClassDuringLoad).toBe('fas fa-spinner fa-spin');
    expect(disabledDuringLoad).toBe(true);
  });
});

// =============================================================================
// createRefreshController tests
// =============================================================================

describe('createRefreshController', () => {
  beforeEach(() => {
    jest.useFakeTimers();
    document.body.innerHTML = '<button class="refreshButton"><i></i></button>';
  });

  afterEach(() => {
    jest.useRealTimers();
  });

  test('creates controller with isRefreshing getter', () => {
    const controller = createRefreshController(jest.fn());
    expect(controller.isRefreshing).toBe(false);
  });

  test('refresh calls the refresh function', async () => {
    const refreshFn = jest.fn().mockResolvedValue(undefined);
    const controller = createRefreshController(refreshFn);

    await controller.refresh();

    expect(refreshFn).toHaveBeenCalledWith(true);
  });

  test('refresh passes showLoading parameter', async () => {
    const refreshFn = jest.fn().mockResolvedValue(undefined);
    const controller = createRefreshController(refreshFn);

    await controller.refresh(false);

    expect(refreshFn).toHaveBeenCalledWith(false);
  });

  test('prevents concurrent refreshes', async () => {
    let resolveFirst;
    const refreshFn = jest.fn().mockImplementation(() => {
      return new Promise(resolve => { resolveFirst = resolve; });
    });
    const controller = createRefreshController(refreshFn);

    const firstRefresh = controller.refresh();
    controller.refresh(); // Should be ignored
    controller.refresh(); // Should be ignored

    resolveFirst();
    await firstRefresh;

    expect(refreshFn).toHaveBeenCalledTimes(1);
  });

  test('sets animation on refresh button during refresh', async () => {
    let animationDuringRefresh = null;
    const refreshFn = jest.fn().mockImplementation(() => {
      const icon = document.querySelector('.refreshButton i');
      animationDuringRefresh = icon.style.animation;
      return Promise.resolve();
    });
    const controller = createRefreshController(refreshFn);

    await controller.refresh();

    expect(animationDuringRefresh).toBe('spin 1s linear infinite');
  });

  test('clears animation after refresh', async () => {
    const refreshFn = jest.fn().mockResolvedValue(undefined);
    const controller = createRefreshController(refreshFn);

    await controller.refresh();

    const icon = document.querySelector('.refreshButton i');
    expect(icon.style.animation).toBe('');
  });

  test('startAutoRefresh sets up interval', async () => {
    const refreshFn = jest.fn().mockResolvedValue(undefined);
    const controller = createRefreshController(refreshFn, 5000);

    controller.startAutoRefresh();

    // Initial state - no calls yet
    expect(refreshFn).not.toHaveBeenCalled();

    // Advance timer
    jest.advanceTimersByTime(5000);
    await Promise.resolve(); // Let promises resolve

    expect(refreshFn).toHaveBeenCalledTimes(1);

    // Stop to clean up
    controller.stopAutoRefresh();
  });

  test('stopAutoRefresh clears interval', async () => {
    const refreshFn = jest.fn().mockResolvedValue(undefined);
    const controller = createRefreshController(refreshFn, 5000);

    controller.startAutoRefresh();
    controller.stopAutoRefresh();

    jest.advanceTimersByTime(10000);

    expect(refreshFn).not.toHaveBeenCalled();
  });

  test('startAutoRefresh only starts once', () => {
    const refreshFn = jest.fn().mockResolvedValue(undefined);
    const controller = createRefreshController(refreshFn, 5000);

    controller.startAutoRefresh();
    controller.startAutoRefresh();
    controller.startAutoRefresh();

    // Should only have one interval running
    jest.advanceTimersByTime(5000);

    // Only one call should have happened
    expect(refreshFn).toHaveBeenCalledTimes(1);

    controller.stopAutoRefresh();
  });

  test('uses custom interval', async () => {
    const refreshFn = jest.fn().mockResolvedValue(undefined);
    const controller = createRefreshController(refreshFn, 60000);

    controller.startAutoRefresh();

    jest.advanceTimersByTime(30000);
    expect(refreshFn).not.toHaveBeenCalled();

    jest.advanceTimersByTime(30000);
    await Promise.resolve();
    expect(refreshFn).toHaveBeenCalledTimes(1);

    controller.stopAutoRefresh();
  });
});

// =============================================================================
// loadTemperaturePreference tests
// =============================================================================

describe('loadTemperaturePreference', () => {
  beforeEach(() => {
    localStorage.clear();
    global.fetch.mockReset();
  });

  test('returns cached value if available (true)', async () => {
    localStorage.setItem('temperatureInF', 'true');

    const result = await loadTemperaturePreference();

    expect(result).toBe(true);
    expect(global.fetch).not.toHaveBeenCalled();
  });

  test('returns cached value if available (false)', async () => {
    localStorage.setItem('temperatureInF', 'false');

    const result = await loadTemperaturePreference();

    expect(result).toBe(false);
    expect(global.fetch).not.toHaveBeenCalled();
  });

  test('fetches from API when not cached', async () => {
    global.fetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({ value: '1' }),
    });

    const result = await loadTemperaturePreference();

    expect(result).toBe(true);
    expect(global.fetch).toHaveBeenCalledWith('/api/settings/temperatureInF', expect.anything());
    expect(localStorage.getItem('temperatureInF')).toBe('true');
  });

  test('handles value as number 1', async () => {
    global.fetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({ value: 1 }),
    });

    const result = await loadTemperaturePreference();

    expect(result).toBe(true);
  });

  test('handles value as string 0', async () => {
    global.fetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({ value: '0' }),
    });

    const result = await loadTemperaturePreference();

    expect(result).toBe(false);
    expect(localStorage.getItem('temperatureInF')).toBe('false');
  });

  test('returns false on API error', async () => {
    global.fetch.mockRejectedValueOnce(new Error('Network error'));

    const result = await loadTemperaturePreference();

    expect(result).toBe(false);
  });

  test('returns false on HTTP error', async () => {
    global.fetch.mockResolvedValueOnce({
      ok: false,
      status: 500,
    });

    const result = await loadTemperaturePreference();

    expect(result).toBe(false);
  });
});

// =============================================================================
// Export object tests
// =============================================================================

describe('api export object', () => {
  test('contains all API functions', () => {
    expect(api.fetchJson).toBe(fetchJson);
    expect(api.withButtonLoading).toBe(withButtonLoading);
    expect(api.createRefreshController).toBe(createRefreshController);
    expect(api.loadTemperaturePreference).toBe(loadTemperaturePreference);
  });
});
