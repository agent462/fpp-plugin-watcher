/**
 * Tests for js/src/core/charts.js
 */

const {
  CHART_COLORS,
  getChartColor,
  getTimeUnit,
  getTimeFormats,
  createDataset,
  mapChartData,
  buildChartOptions,
  updateOrCreateChart,
  charts,
} = require('../../../js/src/core/charts.js');

// =============================================================================
// CHART_COLORS tests
// =============================================================================

describe('CHART_COLORS', () => {
  test('has border and bg properties for each color', () => {
    const colorNames = ['purple', 'red', 'green', 'blue', 'pink', 'orange', 'teal', 'coral', 'yellow', 'cyan', 'indigo'];

    colorNames.forEach(name => {
      expect(CHART_COLORS[name]).toBeDefined();
      expect(CHART_COLORS[name].border).toBeDefined();
      expect(CHART_COLORS[name].bg).toBeDefined();
    });
  });

  test('colors are valid RGB/RGBA strings', () => {
    Object.values(CHART_COLORS).forEach(color => {
      expect(color.border).toMatch(/^rgb\(\d+, \d+, \d+\)$/);
      expect(color.bg).toMatch(/^rgba\(\d+, \d+, \d+, [\d.]+\)$/);
    });
  });
});

// =============================================================================
// getChartColor tests
// =============================================================================

describe('getChartColor', () => {
  test('returns first color for index 0', () => {
    const color = getChartColor(0);
    expect(color).toEqual(CHART_COLORS.purple);
  });

  test('returns correct color for various indices', () => {
    expect(getChartColor(1)).toEqual(CHART_COLORS.green);
    expect(getChartColor(2)).toEqual(CHART_COLORS.pink);
    expect(getChartColor(3)).toEqual(CHART_COLORS.blue);
  });

  test('wraps around when index exceeds array length', () => {
    // 8 colors in array, so index 8 should wrap to 0
    expect(getChartColor(8)).toEqual(getChartColor(0));
    expect(getChartColor(9)).toEqual(getChartColor(1));
    expect(getChartColor(16)).toEqual(getChartColor(0));
  });
});

// =============================================================================
// Time helpers tests
// =============================================================================

describe('getTimeUnit', () => {
  test('returns minute for 1 hour or less', () => {
    expect(getTimeUnit(0.5)).toBe('minute');
    expect(getTimeUnit(1)).toBe('minute');
  });

  test('returns hour for 1-24 hours', () => {
    expect(getTimeUnit(2)).toBe('hour');
    expect(getTimeUnit(12)).toBe('hour');
    expect(getTimeUnit(24)).toBe('hour');
  });

  test('returns day for 1-7 days', () => {
    expect(getTimeUnit(25)).toBe('day');
    expect(getTimeUnit(48)).toBe('day');
    expect(getTimeUnit(168)).toBe('day'); // 7 days
  });

  test('returns week for more than 7 days', () => {
    expect(getTimeUnit(169)).toBe('week');
    expect(getTimeUnit(336)).toBe('week'); // 14 days
  });
});

describe('getTimeFormats', () => {
  test('returns format object with all time units', () => {
    const formats = getTimeFormats();
    expect(formats.minute).toBeDefined();
    expect(formats.hour).toBeDefined();
    expect(formats.day).toBeDefined();
    expect(formats.week).toBeDefined();
  });

  test('formats are valid date-fns format strings', () => {
    const formats = getTimeFormats();
    expect(formats.minute).toBe('HH:mm');
    expect(formats.hour).toBe('MMM d, HH:mm');
    expect(formats.day).toBe('MMM d');
    expect(formats.week).toBe('MMM d, yyyy');
  });
});

// =============================================================================
// createDataset tests
// =============================================================================

describe('createDataset', () => {
  test('creates dataset with color name string', () => {
    const dataset = createDataset('Test', [1, 2, 3], 'blue');
    expect(dataset.label).toBe('Test');
    expect(dataset.data).toEqual([1, 2, 3]);
    expect(dataset.borderColor).toBe(CHART_COLORS.blue.border);
    expect(dataset.backgroundColor).toBe(CHART_COLORS.blue.bg);
  });

  test('creates dataset with color object', () => {
    const customColor = { border: 'rgb(0, 0, 0)', bg: 'rgba(0, 0, 0, 0.5)' };
    const dataset = createDataset('Test', [], customColor);
    expect(dataset.borderColor).toBe('rgb(0, 0, 0)');
    expect(dataset.backgroundColor).toBe('rgba(0, 0, 0, 0.5)');
  });

  test('defaults to purple for unknown color name', () => {
    const dataset = createDataset('Test', [], 'unknown');
    expect(dataset.borderColor).toBe(CHART_COLORS.purple.border);
  });

  test('has correct default options', () => {
    const dataset = createDataset('Test', [], 'blue');
    expect(dataset.borderWidth).toBe(2);
    expect(dataset.fill).toBe(true);
    expect(dataset.tension).toBe(0);
    expect(dataset.spanGaps).toBe(true);
    expect(dataset.pointRadius).toBe(0);
    expect(dataset.pointHoverRadius).toBe(5);
  });

  test('allows overriding options', () => {
    const dataset = createDataset('Test', [], 'blue', {
      fill: false,
      pointRadius: 3,
      borderWidth: 4,
    });
    expect(dataset.fill).toBe(false);
    expect(dataset.pointRadius).toBe(3);
    expect(dataset.borderWidth).toBe(4);
  });
});

// =============================================================================
// mapChartData tests
// =============================================================================

describe('mapChartData', () => {
  test('maps payload data to chart points', () => {
    const payload = {
      data: [
        { timestamp: 1000, value: 10 },
        { timestamp: 2000, value: 20 },
        { timestamp: 3000, value: 30 },
      ],
    };
    const result = mapChartData(payload, 'value');
    expect(result).toEqual([
      { x: 1000000, y: 10 },
      { x: 2000000, y: 20 },
      { x: 3000000, y: 30 },
    ]);
  });

  test('handles empty data array', () => {
    expect(mapChartData({ data: [] }, 'value')).toEqual([]);
  });

  test('handles null/undefined payload', () => {
    expect(mapChartData(null, 'value')).toEqual([]);
    expect(mapChartData(undefined, 'value')).toEqual([]);
  });

  test('handles missing data property', () => {
    expect(mapChartData({}, 'value')).toEqual([]);
  });

  test('handles missing field in entries', () => {
    const payload = {
      data: [{ timestamp: 1000 }],
    };
    const result = mapChartData(payload, 'value');
    expect(result).toEqual([{ x: 1000000, y: undefined }]);
  });
});

// =============================================================================
// buildChartOptions tests
// =============================================================================

describe('buildChartOptions', () => {
  test('builds options with default values', () => {
    const options = buildChartOptions(24);
    expect(options.responsive).toBe(true);
    expect(options.maintainAspectRatio).toBe(true);
    expect(options.animation).toBe(false);
  });

  test('sets correct time unit based on hours', () => {
    expect(buildChartOptions(1).scales.x.time.unit).toBe('minute');
    expect(buildChartOptions(12).scales.x.time.unit).toBe('hour');
    expect(buildChartOptions(48).scales.x.time.unit).toBe('day');
    expect(buildChartOptions(200).scales.x.time.unit).toBe('week');
  });

  test('applies custom yLabel', () => {
    const options = buildChartOptions(24, { yLabel: 'Temperature' });
    expect(options.scales.y.title.text).toBe('Temperature');
  });

  test('applies beginAtZero', () => {
    expect(buildChartOptions(24, { beginAtZero: true }).scales.y.beginAtZero).toBe(true);
    expect(buildChartOptions(24, { beginAtZero: false }).scales.y.beginAtZero).toBe(false);
  });

  test('applies yMax', () => {
    const options = buildChartOptions(24, { yMax: 100 });
    expect(options.scales.y.max).toBe(100);
  });

  test('applies showLegend', () => {
    expect(buildChartOptions(24, { showLegend: true }).plugins.legend.display).toBe(true);
    expect(buildChartOptions(24, { showLegend: false }).plugins.legend.display).toBe(false);
  });

  test('applies animation', () => {
    expect(buildChartOptions(24, { animation: true }).animation).toBe(true);
    expect(buildChartOptions(24, { animation: false }).animation).toBe(false);
  });

  test('applies decimationSamples', () => {
    const options = buildChartOptions(24, { decimationSamples: 1000 });
    expect(options.plugins.decimation.samples).toBe(1000);
  });

  test('has interaction settings', () => {
    const options = buildChartOptions(24);
    expect(options.interaction.mode).toBe('nearest');
    expect(options.interaction.axis).toBe('x');
    expect(options.interaction.intersect).toBe(false);
  });

  test('has tooltip callbacks', () => {
    const options = buildChartOptions(24);
    expect(typeof options.plugins.tooltip.callbacks.title).toBe('function');
    expect(typeof options.plugins.tooltip.callbacks.label).toBe('function');
  });

  test('tooltip title formats date', () => {
    const options = buildChartOptions(24);
    const ctx = [{ parsed: { x: 1703030400000 } }]; // 2023-12-20
    const title = options.plugins.tooltip.callbacks.title(ctx);
    expect(typeof title).toBe('string');
    expect(title.length).toBeGreaterThan(0);
  });

  test('applies custom yTickFormatter', () => {
    const formatter = jest.fn(v => `${v}%`);
    const options = buildChartOptions(24, { yTickFormatter: formatter });
    expect(options.scales.y.ticks.callback).toBe(formatter);
  });

  test('applies custom tooltipLabel', () => {
    const tooltipLabel = jest.fn();
    const options = buildChartOptions(24, { tooltipLabel });
    expect(options.plugins.tooltip.callbacks.label).toBe(tooltipLabel);
  });
});

// =============================================================================
// updateOrCreateChart tests
// =============================================================================

describe('updateOrCreateChart', () => {
  beforeEach(() => {
    // Create canvas with mocked getContext
    document.body.innerHTML = '<canvas id="testChart"></canvas>';
    const canvas = document.getElementById('testChart');
    canvas.getContext = jest.fn(() => ({})); // Mock 2d context
  });

  test('creates new chart when key does not exist', () => {
    const chartsMap = {};
    const datasets = [createDataset('Test', [1, 2, 3], 'blue')];
    const options = buildChartOptions(24);

    const chart = updateOrCreateChart(chartsMap, 'test', 'testChart', 'line', datasets, options);

    expect(chart).not.toBeNull();
    expect(chartsMap.test).toBe(chart);
    expect(global.Chart).toHaveBeenCalled();
  });

  test('updates existing chart data', () => {
    const canvas = document.getElementById('testChart');
    const mockChart = {
      canvas, // Reference to same canvas element
      data: {
        datasets: [{ data: [1], label: 'Old', borderColor: 'red', backgroundColor: 'pink' }],
      },
      update: jest.fn(),
    };
    const chartsMap = { test: mockChart };
    const newDatasets = [createDataset('New', [2, 3, 4], 'blue')];

    const result = updateOrCreateChart(chartsMap, 'test', 'testChart', 'line', newDatasets, {});

    expect(result).toBe(mockChart);
    expect(mockChart.data.datasets[0].data).toEqual([2, 3, 4]);
    expect(mockChart.data.datasets[0].label).toBe('New');
    expect(mockChart.update).toHaveBeenCalledWith('none');
  });

  test('adds new datasets when updating', () => {
    const canvas = document.getElementById('testChart');
    const mockChart = {
      canvas, // Reference to same canvas element
      data: {
        datasets: [{ data: [1], label: 'First' }],
      },
      update: jest.fn(),
    };
    const chartsMap = { test: mockChart };
    const newDatasets = [
      createDataset('First', [1], 'blue'),
      createDataset('Second', [2], 'green'),
    ];

    updateOrCreateChart(chartsMap, 'test', 'testChart', 'line', newDatasets, {});

    expect(mockChart.data.datasets.length).toBe(2);
  });

  test('removes extra datasets when updating', () => {
    const canvas = document.getElementById('testChart');
    const mockChart = {
      canvas, // Reference to same canvas element
      data: {
        datasets: [
          { data: [1], label: 'First' },
          { data: [2], label: 'Second' },
          { data: [3], label: 'Third' },
        ],
      },
      update: jest.fn(),
    };
    const chartsMap = { test: mockChart };
    const newDatasets = [createDataset('Only', [1], 'blue')];

    updateOrCreateChart(chartsMap, 'test', 'testChart', 'line', newDatasets, {});

    expect(mockChart.data.datasets.length).toBe(1);
  });

  test('returns null when canvas not found', () => {
    const chartsMap = {};
    document.body.innerHTML = ''; // Remove canvas

    const result = updateOrCreateChart(chartsMap, 'test', 'nonexistent', 'line', [], {});

    expect(result).toBeNull();
    expect(chartsMap.test).toBeUndefined();
  });

  test('updates colors when dataset order changes', () => {
    const canvas = document.getElementById('testChart');
    const mockChart = {
      canvas, // Reference to same canvas element
      data: {
        datasets: [{ data: [1], label: 'Test', borderColor: 'old', backgroundColor: 'old' }],
      },
      update: jest.fn(),
    };
    const chartsMap = { test: mockChart };
    const newDatasets = [{
      ...createDataset('Test', [1, 2], 'green'),
    }];

    updateOrCreateChart(chartsMap, 'test', 'testChart', 'line', newDatasets, {});

    expect(mockChart.data.datasets[0].borderColor).toBe(CHART_COLORS.green.border);
    expect(mockChart.data.datasets[0].backgroundColor).toBe(CHART_COLORS.green.bg);
  });

  test('destroys existing Chart.js instance on canvas before creating new one', () => {
    const existingChart = { destroy: jest.fn() };
    global.Chart.getChart.mockReturnValueOnce(existingChart);

    const chartsMap = {};
    const datasets = [createDataset('Test', [1, 2, 3], 'blue')];
    const options = buildChartOptions(24);

    updateOrCreateChart(chartsMap, 'test', 'testChart', 'line', datasets, options);

    expect(global.Chart.getChart).toHaveBeenCalled();
    expect(existingChart.destroy).toHaveBeenCalled();
    expect(chartsMap.test).not.toBeNull();
  });

  test('recreates chart when tracked chart has stale canvas reference', () => {
    // Create a chart with a different canvas (simulating stale reference after FPP navigation)
    const staleCanvas = document.createElement('canvas');
    const mockChart = {
      canvas: staleCanvas, // Points to different canvas
      data: { datasets: [] },
      update: jest.fn(),
      destroy: jest.fn(),
    };
    const chartsMap = { test: mockChart };
    const datasets = [createDataset('Test', [1, 2, 3], 'blue')];
    const options = buildChartOptions(24);

    const result = updateOrCreateChart(chartsMap, 'test', 'testChart', 'line', datasets, options);

    // Should have destroyed the stale chart and created a new one
    expect(mockChart.destroy).toHaveBeenCalled();
    expect(result).not.toBe(mockChart);
    expect(chartsMap.test).not.toBe(mockChart);
    expect(global.Chart).toHaveBeenCalled();
  });
});

// =============================================================================
// Export object tests
// =============================================================================

describe('charts export object', () => {
  test('contains all chart functions', () => {
    expect(charts.CHART_COLORS).toBe(CHART_COLORS);
    expect(charts.getChartColor).toBe(getChartColor);
    expect(charts.getTimeUnit).toBe(getTimeUnit);
    expect(charts.getTimeFormats).toBe(getTimeFormats);
    expect(charts.createDataset).toBe(createDataset);
    expect(charts.mapChartData).toBe(mapChartData);
    expect(charts.buildChartOptions).toBe(buildChartOptions);
    expect(charts.updateOrCreateChart).toBe(updateOrCreateChart);
  });
});
