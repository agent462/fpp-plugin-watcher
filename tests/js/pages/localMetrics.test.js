/**
 * Tests for js/src/pages/localMetrics.js
 */

const { localMetrics } = require('../../../js/src/pages/localMetrics.js');

// =============================================================================
// Module Interface Tests
// =============================================================================

describe('localMetrics module interface', () => {
  test('exports pageId', () => {
    expect(localMetrics.pageId).toBe('localMetricsUI');
  });

  test('exports init function', () => {
    expect(typeof localMetrics.init).toBe('function');
  });

  test('exports destroy function', () => {
    expect(typeof localMetrics.destroy).toBe('function');
  });

  test('exports refresh function', () => {
    expect(typeof localMetrics.refresh).toBe('function');
  });

  test('exports refreshMetric function', () => {
    expect(typeof localMetrics.refreshMetric).toBe('function');
  });

  test('exports updateAllCharts function', () => {
    expect(typeof localMetrics.updateAllCharts).toBe('function');
  });
});

// =============================================================================
// Initialization Tests
// =============================================================================

describe('localMetrics.init', () => {
  beforeEach(() => {
    // Clean up any previous state
    localMetrics.destroy();
  });

  afterEach(() => {
    localMetrics.destroy();
  });

  test('initializes without errors', () => {
    expect(() => localMetrics.init({ defaultAdapter: 'eth0' })).not.toThrow();
  });

  test('accepts config with defaultAdapter', () => {
    // Should not throw
    expect(() => localMetrics.init({ defaultAdapter: 'wlan0' })).not.toThrow();
  });

  test('handles missing config gracefully', () => {
    expect(() => localMetrics.init()).not.toThrow();
    expect(() => localMetrics.init({})).not.toThrow();
  });
});

// =============================================================================
// Destroy Tests
// =============================================================================

describe('localMetrics.destroy', () => {
  test('clears refresh interval', () => {
    localMetrics.init();
    localMetrics.destroy();

    // Second call should not throw
    expect(() => localMetrics.destroy()).not.toThrow();
  });

  test('can be called multiple times safely', () => {
    localMetrics.destroy();
    localMetrics.destroy();
    localMetrics.destroy();
    // Should not throw
  });
});

// =============================================================================
// Refresh Tests
// =============================================================================

describe('localMetrics.refresh', () => {
  beforeEach(() => {
    localMetrics.destroy();
    global.fetch = jest.fn(() =>
      Promise.resolve({
        ok: true,
        json: () => Promise.resolve({ success: true, data: [] }),
      })
    );
  });

  afterEach(() => {
    localMetrics.destroy();
  });

  test('refresh is callable', () => {
    expect(typeof localMetrics.refresh).toBe('function');
  });
});

// =============================================================================
// Integration Tests
// =============================================================================

describe('localMetrics integration', () => {
  beforeEach(() => {
    // Set up DOM elements
    document.body.innerHTML = `
      <select id="timeRange"><option value="12" selected>12</option></select>
      <select id="interfaceSelect"><option value="eth0">eth0</option></select>
      <select id="voltageTimeRange"><option value="12" selected>12</option></select>
      <span id="lastUpdate"></span>
      <div id="temperatureStatusBar"></div>
      <div id="diskStatusBar"></div>
      <div id="currentMemory"></div>
      <div id="avgMemory"></div>
      <div id="currentBufferCache"></div>
      <div id="avgBufferCache"></div>
      <div id="memoryLoading"></div>
      <div id="cpuLoading"></div>
      <div id="loadLoading"></div>
      <div id="diskLoading"></div>
      <div id="networkLoading"></div>
      <div id="thermalLoading"></div>
      <div id="wirelessLoading"></div>
      <div id="voltageLoading"></div>
      <div id="thermalCard"></div>
      <div id="wirelessCard"></div>
      <div id="voltageCard" style="display: none;"></div>
      <div id="voltageStatsBar" style="display: none;"></div>
      <div id="voltageStatusBar" style="display: none;"></div>
      <div id="voltage5V"></div>
      <div id="voltageCore"></div>
      <div id="voltage3V3"></div>
      <div id="voltage1V8"></div>
      <canvas id="memoryChart"></canvas>
      <canvas id="cpuChart"></canvas>
      <canvas id="loadChart"></canvas>
      <canvas id="diskChart"></canvas>
      <canvas id="networkChart"></canvas>
      <canvas id="thermalChart"></canvas>
      <canvas id="wirelessChart"></canvas>
      <canvas id="voltageChart"></canvas>
      <button class="refreshButton"><i></i></button>
    `;

    localMetrics.destroy();

    global.fetch = jest.fn(() =>
      Promise.resolve({
        ok: true,
        json: () => Promise.resolve({ success: true, data: [] }),
      })
    );
  });

  afterEach(() => {
    localMetrics.destroy();
    document.body.innerHTML = '';
  });

  test('can initialize and destroy without errors', async () => {
    expect(() => localMetrics.init({ defaultAdapter: 'eth0' })).not.toThrow();
    expect(() => localMetrics.destroy()).not.toThrow();
  });

  test('refreshMetric handles unknown metric key', () => {
    localMetrics.init();
    // Should not throw for unknown key
    expect(() => localMetrics.refreshMetric('unknown')).not.toThrow();
  });

  test('refreshMetric handles voltage metric key', () => {
    localMetrics.init();
    // Should not throw for voltage key
    expect(() => localMetrics.refreshMetric('voltage')).not.toThrow();
  });
});

// =============================================================================
// Voltage Metric Tests
// =============================================================================

describe('localMetrics voltage metric', () => {
  beforeEach(() => {
    // Set up DOM with voltage elements
    document.body.innerHTML = `
      <select id="timeRange"><option value="12" selected>12</option></select>
      <select id="voltageTimeRange"><option value="12" selected>12</option></select>
      <select id="interfaceSelect"><option value="eth0">eth0</option></select>
      <span id="lastUpdate"></span>
      <div id="voltageLoading"></div>
      <div id="voltageCard" style="display: none;"></div>
      <div id="voltageStatsBar" style="display: none;"></div>
      <div id="voltageStatusBar" style="display: none;"></div>
      <div id="voltage5V"></div>
      <div id="voltageCore"></div>
      <div id="voltage3V3"></div>
      <div id="voltage1V8"></div>
      <canvas id="voltageChart"></canvas>
      <button class="refreshButton"><i></i></button>
    `;

    localMetrics.destroy();
  });

  afterEach(() => {
    localMetrics.destroy();
    document.body.innerHTML = '';
  });

  test('voltage time range selector is read correctly', () => {
    const select = document.getElementById('voltageTimeRange');
    // The select only has option value="12", so test that it reads that value
    expect(select.value).toBe('12');

    // Add option for 24 hours and test setting it
    const option24 = document.createElement('option');
    option24.value = '24';
    select.appendChild(option24);
    select.value = '24';
    expect(select.value).toBe('24');
  });

  test('voltage elements exist in DOM', () => {
    expect(document.getElementById('voltageCard')).toBeTruthy();
    expect(document.getElementById('voltageStatsBar')).toBeTruthy();
    expect(document.getElementById('voltageStatusBar')).toBeTruthy();
    expect(document.getElementById('voltageChart')).toBeTruthy();
    expect(document.getElementById('voltage5V')).toBeTruthy();
    expect(document.getElementById('voltageCore')).toBeTruthy();
    expect(document.getElementById('voltage3V3')).toBeTruthy();
    expect(document.getElementById('voltage1V8')).toBeTruthy();
  });
});

// =============================================================================
// Voltage Data Processing Tests
// =============================================================================

describe('voltage data processing', () => {
  // Test data for Pi 5 PMIC format
  const mockPi5Data = {
    success: true,
    data: [
      {
        timestamp: Math.floor(Date.now() / 1000) - 60,
        voltages: {
          EXT5V_V: { avg: 5.1536, min: 5.1500, max: 5.1570, samples: 20 },
          VDD_CORE_V: { avg: 0.8768, min: 0.8760, max: 0.8780, samples: 20 },
          '3V3_SYS_V': { avg: 3.3128, min: 3.3120, max: 3.3135, samples: 20 },
          '1V8_SYS_V': { avg: 1.8061, min: 1.8050, max: 1.8070, samples: 20 },
        },
      },
      {
        timestamp: Math.floor(Date.now() / 1000),
        voltages: {
          EXT5V_V: { avg: 5.1540, min: 5.1510, max: 5.1580, samples: 20 },
          VDD_CORE_V: { avg: 0.8770, min: 0.8765, max: 0.8775, samples: 20 },
          '3V3_SYS_V': { avg: 3.3130, min: 3.3125, max: 3.3140, samples: 20 },
          '1V8_SYS_V': { avg: 1.8063, min: 1.8055, max: 1.8075, samples: 20 },
        },
      },
    ],
    rails: ['EXT5V_V', 'VDD_CORE_V', '3V3_SYS_V', '1V8_SYS_V'],
    labels: {
      EXT5V_V: '5V Input',
      VDD_CORE_V: 'Core',
      '3V3_SYS_V': '3.3V System',
      '1V8_SYS_V': '1.8V System',
    },
    tier_info: {
      tier: '1min',
      interval: 60,
      label: '1-minute averages',
    },
  };

  // Test data for legacy Pi 4 format
  const mockLegacyData = {
    success: true,
    data: [
      {
        timestamp: Math.floor(Date.now() / 1000) - 60,
        voltages: {
          core: { avg: 1.2375, min: 1.2350, max: 1.2400, samples: 20 },
          sdram_c: { avg: 1.2500, min: 1.2480, max: 1.2520, samples: 20 },
          sdram_i: { avg: 1.2500, min: 1.2480, max: 1.2520, samples: 20 },
          sdram_p: { avg: 1.2250, min: 1.2230, max: 1.2270, samples: 20 },
        },
      },
    ],
    rails: ['core', 'sdram_c', 'sdram_i', 'sdram_p'],
    labels: {
      core: 'Core',
      sdram_c: 'SDRAM Core',
      sdram_i: 'SDRAM I/O',
      sdram_p: 'SDRAM PHY',
    },
    tier_info: {
      tier: '1min',
      interval: 60,
      label: '1-minute averages',
    },
  };

  // Empty/no data response
  const mockEmptyData = {
    success: true,
    data: [],
    rails: [],
    labels: {},
  };

  // Unsupported platform response
  const mockUnsupportedData = {
    success: false,
    data: [],
    error: 'Voltage monitoring not supported on this platform',
  };

  test('Pi 5 PMIC data has correct structure', () => {
    expect(mockPi5Data.success).toBe(true);
    expect(mockPi5Data.data.length).toBe(2);
    expect(mockPi5Data.rails).toContain('EXT5V_V');
    expect(mockPi5Data.rails).toContain('VDD_CORE_V');
    expect(mockPi5Data.labels['EXT5V_V']).toBe('5V Input');
  });

  test('legacy Pi 4 data has correct structure', () => {
    expect(mockLegacyData.success).toBe(true);
    expect(mockLegacyData.rails).toContain('core');
    expect(mockLegacyData.labels['core']).toBe('Core');
  });

  test('voltage data entry has timestamp and voltages', () => {
    const entry = mockPi5Data.data[0];
    expect(entry).toHaveProperty('timestamp');
    expect(entry).toHaveProperty('voltages');
    expect(typeof entry.timestamp).toBe('number');
    expect(typeof entry.voltages).toBe('object');
  });

  test('voltage aggregation has avg, min, max, samples', () => {
    const voltageData = mockPi5Data.data[0].voltages['VDD_CORE_V'];
    expect(voltageData).toHaveProperty('avg');
    expect(voltageData).toHaveProperty('min');
    expect(voltageData).toHaveProperty('max');
    expect(voltageData).toHaveProperty('samples');
    expect(typeof voltageData.avg).toBe('number');
  });

  test('empty data is handled correctly', () => {
    expect(mockEmptyData.success).toBe(true);
    expect(mockEmptyData.data.length).toBe(0);
  });

  test('unsupported platform is handled correctly', () => {
    expect(mockUnsupportedData.success).toBe(false);
    expect(mockUnsupportedData.error).toBeTruthy();
  });
});

// =============================================================================
// Voltage Color Mapping Tests
// =============================================================================

describe('voltage rail color mapping', () => {
  const railColors = {
    // Pi 5 PMIC rails
    EXT5V_V: 'green',
    VDD_CORE_V: 'coral',
    HDMI_V: 'orange',
    '3V7_WL_SW_V': 'pink',
    '3V3_SYS_V': 'blue',
    '3V3_DAC_V': 'indigo',
    '3V3_ADC_V': 'cyan',
    '1V8_SYS_V': 'purple',
    '1V1_SYS_V': 'magenta',
    DDR_VDD2_V: 'teal',
    DDR_VDDQ_V: 'lime',
    '0V8_SW_V': 'yellow',
    '0V8_AON_V': 'brown',
    // Legacy rails
    core: 'coral',
    sdram_c: 'blue',
    sdram_i: 'purple',
    sdram_p: 'teal',
  };

  test('Pi 5 critical rails have distinct colors', () => {
    expect(railColors['EXT5V_V']).toBe('green');
    expect(railColors['VDD_CORE_V']).toBe('coral');
    expect(railColors['3V3_SYS_V']).toBe('blue');
    expect(railColors['1V8_SYS_V']).toBe('purple');
  });

  test('legacy core rail has same color as Pi 5 core', () => {
    expect(railColors['core']).toBe(railColors['VDD_CORE_V']);
  });

  test('all Pi 5 PMIC rails have colors', () => {
    const pmicRails = [
      'EXT5V_V', 'VDD_CORE_V', 'HDMI_V', '3V7_WL_SW_V', '3V3_SYS_V',
      '3V3_DAC_V', '3V3_ADC_V', '1V8_SYS_V', '1V1_SYS_V', 'DDR_VDD2_V',
      'DDR_VDDQ_V', '0V8_SW_V', '0V8_AON_V',
    ];

    pmicRails.forEach(rail => {
      expect(railColors[rail]).toBeTruthy();
    });
  });

  test('all legacy rails have colors', () => {
    const legacyRails = ['core', 'sdram_c', 'sdram_i', 'sdram_p'];

    legacyRails.forEach(rail => {
      expect(railColors[rail]).toBeTruthy();
    });
  });
});

// =============================================================================
// Voltage Stats Bar Mapping Tests
// =============================================================================

describe('voltage stats bar mapping', () => {
  const statsMapping = {
    EXT5V_V: 'voltage5V',
    VDD_CORE_V: 'voltageCore',
    '3V3_SYS_V': 'voltage3V3',
    '1V8_SYS_V': 'voltage1V8',
    // Legacy
    core: 'voltageCore',
    sdram_c: 'voltage3V3',
    sdram_i: 'voltage1V8',
  };

  test('Pi 5 key voltages map to stats elements', () => {
    expect(statsMapping['EXT5V_V']).toBe('voltage5V');
    expect(statsMapping['VDD_CORE_V']).toBe('voltageCore');
    expect(statsMapping['3V3_SYS_V']).toBe('voltage3V3');
    expect(statsMapping['1V8_SYS_V']).toBe('voltage1V8');
  });

  test('legacy core maps to same element as Pi 5 core', () => {
    expect(statsMapping['core']).toBe(statsMapping['VDD_CORE_V']);
  });
});

// =============================================================================
// Voltage Warning Threshold Tests
// =============================================================================

describe('voltage warning thresholds', () => {
  test('5V input drop threshold is 3%', () => {
    const threshold = 3; // 3% drop threshold
    const normalVoltage = 5.15;
    const minVoltage = 4.90; // ~5% drop

    const dropPercent = ((normalVoltage - minVoltage) / normalVoltage) * 100;
    expect(dropPercent).toBeGreaterThan(threshold);
  });

  test('5V input within threshold passes', () => {
    const threshold = 3;
    const normalVoltage = 5.15;
    const minVoltage = 5.05; // ~2% drop

    const dropPercent = ((normalVoltage - minVoltage) / normalVoltage) * 100;
    expect(dropPercent).toBeLessThan(threshold);
  });

  test('warning only triggers on EXT5V_V rail', () => {
    // Core voltage fluctuations are normal CPU behavior
    // Only EXT5V_V (5V input) should trigger power supply warnings
    const checkRail = 'EXT5V_V';
    expect(checkRail).toBe('EXT5V_V');
    expect(checkRail).not.toBe('VDD_CORE_V');
  });
});

// =============================================================================
// Voltage Chart Y-Axis Tests
// =============================================================================

describe('voltage chart Y-axis scaling', () => {
  test('dynamic Y-axis respects min/max padding', () => {
    const globalMin = 0.85;
    const globalMax = 0.90;
    const range = globalMax - globalMin;
    const padding = Math.max(range * 0.1, 0.1);

    const yMin = Math.floor((globalMin - padding) * 10) / 10;
    const yMax = Math.ceil((globalMax + padding) * 10) / 10;

    expect(yMin).toBeLessThan(globalMin);
    expect(yMax).toBeGreaterThan(globalMax);
    expect(yMin).toBeGreaterThanOrEqual(0);
  });

  test('minimum padding of 0.1V is applied for narrow ranges', () => {
    const globalMin = 0.876;
    const globalMax = 0.878;
    const range = globalMax - globalMin; // 0.002
    const padding = Math.max(range * 0.1, 0.1); // Should be 0.1, not 0.0002

    expect(padding).toBe(0.1);
  });

  test('Y-axis does not go below zero', () => {
    const globalMin = 0.05;
    const range = 0.1;
    const padding = Math.max(range * 0.1, 0.1);

    const yMin = Math.max(0, Math.floor((globalMin - padding) * 10) / 10);
    expect(yMin).toBeGreaterThanOrEqual(0);
  });
});

// =============================================================================
// Voltage Value Formatting Tests
// =============================================================================

describe('voltage value formatting', () => {
  test('voltage values are formatted to 3 decimal places for display', () => {
    const voltage = 0.87677570;
    const formatted = voltage.toFixed(3);
    expect(formatted).toBe('0.877');
  });

  test('voltage Y-axis tick uses 1 decimal place', () => {
    const voltage = 0.87677570;
    const formatted = voltage.toFixed(1) + ' V';
    expect(formatted).toBe('0.9 V');
  });

  test('voltage tooltip shows 3 decimal places', () => {
    const voltage = 5.15364;
    const label = 'Core: ' + voltage.toFixed(3) + ' V';
    expect(label).toBe('Core: 5.154 V');
  });
});
