/**
 * Tests for js/src/pages/remoteControl/issues.js
 */

const {
  fetchIssues,
  collectFppWarnings,
  renderIssues,
  toggleIssuesDetails,
  resetIssuesState
} = require('../../../../js/src/pages/remoteControl/issues.js');

const {
  resetState,
  initConfig,
  localCache,
  bulkStatusCache
} = require('../../../../js/src/pages/remoteControl/state.js');

// =============================================================================
// Module Exports Tests
// =============================================================================

describe('issues module exports', () => {
  test('exports fetchIssues', () => {
    expect(typeof fetchIssues).toBe('function');
  });

  test('exports collectFppWarnings', () => {
    expect(typeof collectFppWarnings).toBe('function');
  });

  test('exports renderIssues', () => {
    expect(typeof renderIssues).toBe('function');
  });

  test('exports toggleIssuesDetails', () => {
    expect(typeof toggleIssuesDetails).toBe('function');
  });

  test('exports resetIssuesState', () => {
    expect(typeof resetIssuesState).toBe('function');
  });
});

// =============================================================================
// collectFppWarnings Tests
// =============================================================================

describe('collectFppWarnings', () => {
  beforeEach(() => {
    resetState();
    initConfig({
      remoteAddresses: ['192.168.1.100'],
      remoteHostnames: { '192.168.1.100': 'remote1' },
      localHostname: 'player'
    });
  });

  afterEach(() => {
    resetState();
  });

  test('returns empty array when no warnings', () => {
    const warnings = collectFppWarnings();
    expect(warnings).toEqual([]);
  });

  test('collects local warnings', () => {
    localCache.status = {
      warnings: ['Warning 1', 'Warning 2']
    };

    const warnings = collectFppWarnings();
    expect(warnings).toHaveLength(2);
    expect(warnings[0].type).toBe('fpp_warning');
    expect(warnings[0].address).toBe('localhost');
    expect(warnings[0].message).toBe('Warning 1');
  });

  test('collects remote warnings', () => {
    bulkStatusCache.set('192.168.1.100', {
      hostname: 'remote1',
      sysStatus: {
        warnings: ['Remote warning']
      }
    });

    const warnings = collectFppWarnings();
    expect(warnings).toHaveLength(1);
    expect(warnings[0].type).toBe('fpp_warning');
    expect(warnings[0].address).toBe('192.168.1.100');
    expect(warnings[0].hostname).toBe('remote1');
    expect(warnings[0].message).toBe('Remote warning');
  });

  test('combines local and remote warnings', () => {
    localCache.status = {
      warnings: ['Local warning']
    };
    bulkStatusCache.set('192.168.1.100', {
      hostname: 'remote1',
      sysStatus: {
        warnings: ['Remote warning']
      }
    });

    const warnings = collectFppWarnings();
    expect(warnings).toHaveLength(2);
  });

  test('handles missing warnings array', () => {
    localCache.status = {};
    bulkStatusCache.set('192.168.1.100', {
      sysStatus: {}
    });

    const warnings = collectFppWarnings();
    expect(warnings).toEqual([]);
  });

  test('sets severity to warning', () => {
    localCache.status = {
      warnings: ['Test warning']
    };

    const warnings = collectFppWarnings();
    expect(warnings[0].severity).toBe('warning');
  });
});

// =============================================================================
// renderIssues Tests
// =============================================================================

describe('renderIssues', () => {
  let mockBanner, mockCountEl, mockListEl;

  beforeEach(() => {
    resetState();

    // Create mock DOM elements
    mockBanner = {
      classList: {
        add: jest.fn(),
        remove: jest.fn()
      }
    };
    mockCountEl = { textContent: '' };
    mockListEl = { innerHTML: '' };

    document.getElementById = jest.fn((id) => {
      switch (id) {
        case 'issuesBanner': return mockBanner;
        case 'issuesCount': return mockCountEl;
        case 'issuesList': return mockListEl;
        default: return null;
      }
    });
  });

  afterEach(() => {
    resetState();
    jest.restoreAllMocks();
  });

  test('hides banner when no issues', () => {
    renderIssues({ discrepancies: [] });
    expect(mockBanner.classList.remove).toHaveBeenCalledWith('visible');
  });

  test('shows banner when issues present', () => {
    renderIssues({
      discrepancies: [
        { type: 'channel_mismatch', message: 'Test', severity: 'warning', playerRange: '1-100', remoteRange: '1-50' }
      ]
    });
    expect(mockBanner.classList.add).toHaveBeenCalledWith('visible');
  });

  test('updates count element', () => {
    renderIssues({
      discrepancies: [
        { type: 'channel_mismatch', message: 'Test', severity: 'warning', playerRange: '1-100', remoteRange: '1-50' }
      ]
    });
    expect(mockCountEl.textContent).toBe(1);
  });

  test('renders channel mismatch issues', () => {
    renderIssues({
      discrepancies: [
        { type: 'channel_mismatch', message: 'Mismatch', severity: 'warning', playerRange: '1-100', remoteRange: '1-50', address: '192.168.1.100' }
      ]
    });
    expect(mockListEl.innerHTML).toContain('Mismatch');
    expect(mockListEl.innerHTML).toContain('fa-not-equal');
  });

  test('renders output_to_remote issues', () => {
    renderIssues({
      discrepancies: [
        { type: 'output_to_remote', message: 'Output', severity: 'warning', startChannel: 1, channelCount: 100, address: '192.168.1.100' }
      ]
    });
    expect(mockListEl.innerHTML).toContain('Output');
    expect(mockListEl.innerHTML).toContain('fa-exclamation-triangle');
  });

  test('renders inactive_output issues', () => {
    renderIssues({
      discrepancies: [
        { type: 'inactive_output', message: 'Inactive', severity: 'info', startChannel: 1, channelCount: 100, address: '192.168.1.100' }
      ]
    });
    expect(mockListEl.innerHTML).toContain('Inactive');
    expect(mockListEl.innerHTML).toContain('fa-info-circle');
  });

  test('renders missing_sequences issues', () => {
    renderIssues({
      discrepancies: [
        { type: 'missing_sequences', message: 'Missing', severity: 'warning', sequences: ['seq1.fseq', 'seq2.fseq'], address: '192.168.1.100' }
      ]
    });
    expect(mockListEl.innerHTML).toContain('Missing');
    expect(mockListEl.innerHTML).toContain('fa-file-audio');
  });

  test('renders output_host_not_in_sync issues', () => {
    renderIssues({
      discrepancies: [
        { type: 'output_host_not_in_sync', message: 'Not in sync', severity: 'warning', description: 'Output 1', address: '192.168.1.100' }
      ]
    });
    expect(mockListEl.innerHTML).toContain('Not in sync');
    expect(mockListEl.innerHTML).toContain('fa-unlink');
  });

  test('renders fpp_warning issues', () => {
    renderIssues({
      discrepancies: [
        { type: 'fpp_warning', message: 'FPP Warning', severity: 'warning', address: 'localhost', hostname: 'player' }
      ]
    });
    expect(mockListEl.innerHTML).toContain('FPP Warning');
    expect(mockListEl.innerHTML).toContain('fa-exclamation-circle');
  });

  test('handles null data', () => {
    renderIssues(null);
    expect(mockBanner.classList.remove).toHaveBeenCalledWith('visible');
  });

  test('handles missing elements gracefully', () => {
    document.getElementById = jest.fn(() => null);
    expect(() => renderIssues({ discrepancies: [] })).not.toThrow();
  });
});

// =============================================================================
// toggleIssuesDetails Tests
// =============================================================================

describe('toggleIssuesDetails', () => {
  let mockBody, mockToggle;

  beforeEach(() => {
    resetIssuesState();

    mockBody = { style: { display: 'none' } };
    mockToggle = { innerHTML: '' };

    document.getElementById = jest.fn((id) => {
      switch (id) {
        case 'issuesBody': return mockBody;
        case 'issuesToggle': return mockToggle;
        default: return null;
      }
    });
  });

  afterEach(() => {
    resetIssuesState();
    jest.restoreAllMocks();
  });

  test('expands details on first call', () => {
    toggleIssuesDetails();
    expect(mockBody.style.display).toBe('block');
    expect(mockToggle.innerHTML).toContain('fa-chevron-up');
    expect(mockToggle.innerHTML).toContain('Hide');
  });

  test('collapses details on second call', () => {
    toggleIssuesDetails(); // expand
    toggleIssuesDetails(); // collapse
    expect(mockBody.style.display).toBe('none');
    expect(mockToggle.innerHTML).toContain('fa-chevron-down');
    expect(mockToggle.innerHTML).toContain('Details');
  });

  test('handles missing elements gracefully', () => {
    document.getElementById = jest.fn(() => null);
    expect(() => toggleIssuesDetails()).not.toThrow();
  });
});

// =============================================================================
// resetIssuesState Tests
// =============================================================================

describe('resetIssuesState', () => {
  test('resets expanded state', () => {
    const mockBody = { style: { display: 'block' } };
    const mockToggle = { innerHTML: '' };

    document.getElementById = jest.fn((id) => {
      switch (id) {
        case 'issuesBody': return mockBody;
        case 'issuesToggle': return mockToggle;
        default: return null;
      }
    });

    toggleIssuesDetails(); // expand
    resetIssuesState();
    toggleIssuesDetails(); // should expand again (not collapse)

    expect(mockBody.style.display).toBe('block');
  });

  test('does not throw', () => {
    expect(() => resetIssuesState()).not.toThrow();
  });
});

// =============================================================================
// fetchIssues Tests
// =============================================================================

describe('fetchIssues', () => {
  beforeEach(() => {
    resetState();
    resetIssuesState();
    global.fetch = jest.fn();
  });

  afterEach(() => {
    resetState();
    jest.restoreAllMocks();
  });

  test('returns cached data when not time to fetch', async () => {
    // Simulate already fetched
    const mockData = { success: true, discrepancies: [{ type: 'test' }] };
    global.fetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve(mockData)
    });

    // First fetch
    await fetchIssues();

    // Second fetch should use cache
    const result = await fetchIssues();
    expect(global.fetch).toHaveBeenCalledTimes(1);
  });

  test('handles fetch error gracefully', async () => {
    global.fetch.mockRejectedValueOnce(new Error('Network error'));

    const result = await fetchIssues();
    // Should not throw, returns cached data (null initially)
    expect(result).toBeNull();
  });

  test('handles non-ok response', async () => {
    global.fetch.mockResolvedValueOnce({ ok: false });

    const result = await fetchIssues();
    expect(result).toBeNull();
  });
});
