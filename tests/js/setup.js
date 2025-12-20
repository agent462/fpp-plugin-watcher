/**
 * Jest Setup - Global mocks and configuration for JavaScript tests
 */

// Mock Chart.js
global.Chart = jest.fn().mockImplementation(function(ctx, config) {
  this.data = config.data || { datasets: [] };
  this.options = config.options || {};
  this.update = jest.fn();
  this.destroy = jest.fn();
});

// Mock localStorage
const localStorageMock = {
  store: {},
  getItem: jest.fn(key => localStorageMock.store[key] || null),
  setItem: jest.fn((key, value) => { localStorageMock.store[key] = String(value); }),
  removeItem: jest.fn(key => { delete localStorageMock.store[key]; }),
  clear: jest.fn(() => { localStorageMock.store = {}; })
};
Object.defineProperty(window, 'localStorage', { value: localStorageMock });

// Mock fetch
global.fetch = jest.fn(() =>
  Promise.resolve({
    ok: true,
    json: () => Promise.resolve({})
  })
);

// Mock AbortController
global.AbortController = jest.fn().mockImplementation(() => ({
  signal: {},
  abort: jest.fn()
}));

// Mock requestAnimationFrame
global.requestAnimationFrame = jest.fn(cb => setTimeout(cb, 0));
global.cancelAnimationFrame = jest.fn(id => clearTimeout(id));

// Helper to load a JS file into the test environment
global.loadScript = function(scriptPath) {
  const fs = require('fs');
  const path = require('path');
  const scriptContent = fs.readFileSync(path.resolve(__dirname, '../../', scriptPath), 'utf8');

  // Reset guard flags before loading
  delete window._watcherCommonUILoaded;
  delete window._efuseHeatmapLoaded;

  // Execute the script
  eval(scriptContent);
};

// Reset mocks before each test
beforeEach(() => {
  jest.clearAllMocks();
  localStorageMock.store = {};

  // Reset window functions that may have been loaded
  delete window._watcherCommonUILoaded;
  delete window._efuseHeatmapLoaded;

  // Reset fetch mock
  global.fetch.mockImplementation(() =>
    Promise.resolve({
      ok: true,
      json: () => Promise.resolve({})
    })
  );
});

// Clean up after tests
afterEach(() => {
  jest.useRealTimers();
});
