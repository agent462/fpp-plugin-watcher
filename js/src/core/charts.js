/**
 * Chart.js wrappers and helpers
 *
 * Shared chart utilities extracted from commonUI.js:
 * - CHART_COLORS - Color palette for consistent styling
 * - getChartColor - Get color by index
 * - createDataset - Create Chart.js dataset
 * - mapChartData - Map API response to chart data
 * - buildChartOptions - Build Chart.js options
 * - updateOrCreateChart - Update existing or create new chart
 */

// =============================================================================
// Chart.js Color Palette
// =============================================================================

/**
 * Color palette for charts
 * Each color has border (line) and bg (fill) variants
 */
export const CHART_COLORS = {
  purple: { border: 'rgb(102, 126, 234)', bg: 'rgba(102, 126, 234, 0.1)' },
  red: { border: 'rgb(245, 87, 108)', bg: 'rgba(245, 87, 108, 0.1)' },
  green: { border: 'rgb(56, 239, 125)', bg: 'rgba(56, 239, 125, 0.1)' },
  blue: { border: 'rgb(79, 172, 254)', bg: 'rgba(79, 172, 254, 0.1)' },
  pink: { border: 'rgb(240, 147, 251)', bg: 'rgba(240, 147, 251, 0.1)' },
  orange: { border: 'rgb(255, 159, 64)', bg: 'rgba(255, 159, 64, 0.1)' },
  teal: { border: 'rgb(75, 192, 192)', bg: 'rgba(75, 192, 192, 0.1)' },
  coral: { border: 'rgb(255, 99, 132)', bg: 'rgba(255, 99, 132, 0.1)' },
  yellow: { border: 'rgb(255, 193, 7)', bg: 'rgba(255, 193, 7, 0.1)' },
  cyan: { border: 'rgb(23, 162, 184)', bg: 'rgba(23, 162, 184, 0.1)' },
  indigo: { border: 'rgb(111, 66, 193)', bg: 'rgba(111, 66, 193, 0.1)' }
};

/**
 * Array of colors for multi-host/multi-series charts
 */
const CHART_COLOR_ARRAY = [
  CHART_COLORS.purple,
  CHART_COLORS.green,
  CHART_COLORS.pink,
  CHART_COLORS.blue,
  CHART_COLORS.coral,
  CHART_COLORS.yellow,
  CHART_COLORS.cyan,
  CHART_COLORS.indigo
];

/**
 * Get a color from the palette by index (wraps around)
 * @param {number} index - Index into color array
 * @returns {{border: string, bg: string}} - Color object
 */
export function getChartColor(index) {
  return CHART_COLOR_ARRAY[index % CHART_COLOR_ARRAY.length];
}

// =============================================================================
// Chart.js Time Helpers
// =============================================================================

/**
 * Get appropriate time unit based on time range
 * @param {number} hours - Number of hours in range
 * @returns {string} - Time unit (minute, hour, day, week)
 */
export function getTimeUnit(hours) {
  if (hours <= 1) return 'minute';
  if (hours <= 24) return 'hour';
  if (hours <= 168) return 'day';
  return 'week';
}

/**
 * Get time format strings for each unit
 * @returns {Object} - Format strings by unit
 */
export function getTimeFormats() {
  return {
    minute: 'HH:mm',
    hour: 'MMM d, HH:mm',
    day: 'MMM d',
    week: 'MMM d, yyyy'
  };
}

// =============================================================================
// Chart.js Dataset Factory
// =============================================================================

/**
 * Create a Chart.js dataset with consistent styling
 * @param {string} label - Dataset label
 * @param {Array} data - Data points array
 * @param {string|Object} color - Color name or color object
 * @param {Object} options - Additional options
 * @returns {Object} - Chart.js dataset configuration
 */
export function createDataset(label, data, color, options = {}) {
  const c = typeof color === 'string' ? (CHART_COLORS[color] || CHART_COLORS.purple) : color;
  return {
    label,
    data,
    borderColor: c.border,
    backgroundColor: c.bg,
    borderWidth: 2,
    fill: options.fill ?? true,
    tension: 0,
    spanGaps: true,
    pointRadius: options.pointRadius ?? 0,
    pointHoverRadius: 5,
    ...options
  };
}

/**
 * Map API response to chart data points
 * @param {Object} payload - API response with data array
 * @param {string} field - Field name to extract from each entry
 * @returns {Array} - Array of {x, y} points for Chart.js
 */
export function mapChartData(payload, field) {
  return (payload?.data || []).map(e => ({ x: e.timestamp * 1000, y: e[field] }));
}

// =============================================================================
// Chart.js Options Builder
// =============================================================================

/**
 * Build Chart.js options with time axis
 * @param {number} hours - Time range in hours (affects time unit)
 * @param {Object} config - Configuration options
 * @param {string} config.yLabel - Y-axis label (default: 'Value')
 * @param {boolean} config.beginAtZero - Start Y-axis at zero (default: false)
 * @param {number} config.yMax - Maximum Y value
 * @param {Function} config.yTickFormatter - Y-axis tick formatter
 * @param {Function} config.tooltipLabel - Tooltip label formatter
 * @param {boolean} config.showLegend - Show legend (default: true)
 * @param {boolean} config.animation - Enable animation (default: false)
 * @param {number} config.decimationSamples - Decimation samples (default: 500)
 * @returns {Object} - Chart.js options configuration
 */
export function buildChartOptions(hours, config = {}) {
  const {
    yLabel = 'Value',
    beginAtZero = false,
    yMax,
    yTickFormatter = v => v,
    tooltipLabel,
    showLegend = true,
    animation = false,
    decimationSamples = 500
  } = config;

  const unit = getTimeUnit(hours);
  const formats = getTimeFormats();

  return {
    responsive: true,
    maintainAspectRatio: true,
    animation: animation,
    interaction: {
      mode: 'nearest',
      axis: 'x',
      intersect: false
    },
    plugins: {
      decimation: {
        enabled: true,
        algorithm: 'lttb',
        samples: decimationSamples
      },
      legend: {
        display: showLegend,
        position: 'top'
      },
      tooltip: {
        callbacks: {
          title: ctx => new Date(ctx[0].parsed.x).toLocaleString(),
          label: tooltipLabel || (ctx => `${ctx.dataset.label}: ${ctx.parsed.y}`)
        }
      }
    },
    scales: {
      x: {
        type: 'time',
        time: {
          unit,
          displayFormats: formats,
          tooltipFormat: 'MMM d, yyyy HH:mm:ss'
        },
        title: {
          display: true,
          text: 'Time',
          font: { size: 14, weight: 'bold' }
        },
        grid: {
          display: true,
          color: 'rgba(0, 0, 0, 0.05)'
        }
      },
      y: {
        beginAtZero,
        ...(yMax !== undefined && { max: yMax }),
        title: {
          display: true,
          text: yLabel,
          font: { size: 14, weight: 'bold' }
        },
        grid: {
          display: true,
          color: 'rgba(0, 0, 0, 0.05)'
        },
        ticks: {
          callback: yTickFormatter
        }
      }
    }
  };
}

// =============================================================================
// Chart.js Update/Create Helper
// =============================================================================

/**
 * Update existing chart or create new one
 * @param {Object} chartsMap - Map of chart instances keyed by name
 * @param {string} key - Chart key in the map
 * @param {string} canvasId - Canvas element ID
 * @param {string} type - Chart type (e.g., 'line')
 * @param {Array} datasets - Array of datasets
 * @param {Object} options - Chart options
 * @returns {Chart|null} - Chart instance or null if canvas not found
 */
export function updateOrCreateChart(chartsMap, key, canvasId, type, datasets, options) {
  const canvas = document.getElementById(canvasId);
  if (!canvas) return null;

  // Check if we have a tracked chart instance
  if (chartsMap[key]) {
    const chart = chartsMap[key];

    // Verify the chart's canvas is still the same element in the DOM
    // FPP's SPA-like navigation can replace DOM elements, leaving us with stale references
    try {
      // Check if the chart's canvas still exists and matches
      if (chart.canvas && chart.canvas === canvas) {
        // Update existing datasets' data in place for smooth updates
        datasets.forEach((newDataset, i) => {
          if (chart.data.datasets[i]) {
            chart.data.datasets[i].data = newDataset.data;
            chart.data.datasets[i].label = newDataset.label;
            // Also update colors in case dataset order changed
            if (newDataset.borderColor) chart.data.datasets[i].borderColor = newDataset.borderColor;
            if (newDataset.backgroundColor) chart.data.datasets[i].backgroundColor = newDataset.backgroundColor;
          } else {
            chart.data.datasets.push(newDataset);
          }
        });
        // Remove extra datasets if there are fewer now
        chart.data.datasets.length = datasets.length;
        chart.update('none');
        return chart;
      }
    } catch (e) {
      // Chart reference is stale, fall through to create new one
      console.warn(`Chart ${key} has stale reference, recreating`);
    }

    // Stale chart reference - try to clean it up
    try {
      chartsMap[key].destroy();
    } catch (e) {
      // Ignore destroy errors on stale charts
    }
    delete chartsMap[key];
  }

  // Check for and destroy any existing Chart.js instance on this canvas
  // This handles cases where the page was reloaded without a full browser refresh
  // (e.g., FPP navigation) and the chart state wasn't properly cleaned up
  const existingChart = Chart.getChart(canvas);
  if (existingChart) {
    existingChart.destroy();
  }

  const ctx = canvas.getContext('2d');
  if (!ctx) return null;

  chartsMap[key] = new Chart(ctx, {
    type,
    data: { datasets },
    options
  });
  return chartsMap[key];
}

// =============================================================================
// Export object for convenient access
// =============================================================================

export const charts = {
  // Colors
  CHART_COLORS,
  getChartColor,

  // Time helpers
  getTimeUnit,
  getTimeFormats,

  // Dataset helpers
  createDataset,
  mapChartData,

  // Options and chart management
  buildChartOptions,
  updateOrCreateChart,
};
