/**
 * Tests for js/src/pages/config.js
 */

const {
  config,
  calculateEfuseStorage,
  updateEfuseStorageEstimate,
  toggleEfuseOptions,
  watcherTogglePanel,
  watcherHandleTagKeypress,
  watcherAddTag,
  watcherRemoveTag,
  validateForm,
} = require('../../../js/src/pages/config.js');

// =============================================================================
// eFuse Storage Calculator Tests
// =============================================================================

describe('calculateEfuseStorage', () => {
  test('calculates storage for minimal settings', () => {
    // 60 second interval, 1 day retention, 4 ports
    const result = calculateEfuseStorage(60, 1, 4);
    expect(result).toBeGreaterThan(0);
    expect(typeof result).toBe('number');
  });

  test('higher interval means less storage', () => {
    const fast = calculateEfuseStorage(1, 7, 16);  // 1 second interval
    const slow = calculateEfuseStorage(60, 7, 16); // 60 second interval
    expect(fast).toBeGreaterThan(slow);
  });

  test('more days means more storage', () => {
    const short = calculateEfuseStorage(5, 1, 16);  // 1 day
    const long = calculateEfuseStorage(5, 30, 16);  // 30 days
    expect(long).toBeGreaterThan(short);
  });

  test('more ports means more storage', () => {
    const fewPorts = calculateEfuseStorage(5, 7, 4);   // 4 ports
    const manyPorts = calculateEfuseStorage(5, 7, 32); // 32 ports
    expect(manyPorts).toBeGreaterThan(fewPorts);
  });

  test('returns consistent results', () => {
    const result1 = calculateEfuseStorage(5, 7, 16);
    const result2 = calculateEfuseStorage(5, 7, 16);
    expect(result1).toBe(result2);
  });

  test('handles edge case of 1 second interval', () => {
    const result = calculateEfuseStorage(1, 7, 16);
    expect(result).toBeGreaterThan(0);
  });
});

describe('updateEfuseStorageEstimate', () => {
  beforeEach(() => {
    document.body.innerHTML = `
      <select id="efuseCollectionInterval">
        <option value="5" selected>5 seconds</option>
      </select>
      <select id="efuseRetentionDays">
        <option value="7" selected>7 days</option>
      </select>
      <div id="efuseStorageEstimate"></div>
    `;
  });

  test('populates storage estimate container', () => {
    updateEfuseStorageEstimate();

    const container = document.getElementById('efuseStorageEstimate');
    expect(container.innerHTML).toContain('Estimated Storage');
    expect(container.innerHTML).toContain('4 ports');
    expect(container.innerHTML).toContain('8 ports');
    expect(container.innerHTML).toContain('16 ports');
    expect(container.innerHTML).toContain('32 ports');
  });

  test('handles missing elements gracefully', () => {
    document.body.innerHTML = '';
    expect(() => updateEfuseStorageEstimate()).not.toThrow();
  });
});

describe('toggleEfuseOptions', () => {
  beforeEach(() => {
    document.body.innerHTML = `
      <input type="checkbox" id="efuseMonitorEnabled" />
      <div id="efuseOptionsContainer" style="display: none;"></div>
      <select id="efuseCollectionInterval"><option value="5">5</option></select>
      <select id="efuseRetentionDays"><option value="7">7</option></select>
      <div id="efuseStorageEstimate"></div>
    `;
  });

  test('shows options when checkbox is checked', () => {
    const checkbox = document.getElementById('efuseMonitorEnabled');
    const container = document.getElementById('efuseOptionsContainer');

    checkbox.checked = true;
    toggleEfuseOptions();

    expect(container.style.display).toBe('');
  });

  test('hides options when checkbox is unchecked', () => {
    const checkbox = document.getElementById('efuseMonitorEnabled');
    const container = document.getElementById('efuseOptionsContainer');

    container.style.display = 'block';
    checkbox.checked = false;
    toggleEfuseOptions();

    expect(container.style.display).toBe('none');
  });
});

// =============================================================================
// Panel Toggle Tests
// =============================================================================

describe('watcherTogglePanel', () => {
  beforeEach(() => {
    document.body.innerHTML = `
      <div class="settingsPanel">
        <div class="panelHeader" id="header">Header</div>
        <div class="panelBody">Body</div>
      </div>
    `;
  });

  test('toggles collapsed class on panel', () => {
    const header = document.getElementById('header');
    const panel = header.closest('.settingsPanel');

    watcherTogglePanel(header);
    expect(panel.classList.contains('collapsed')).toBe(true);

    watcherTogglePanel(header);
    expect(panel.classList.contains('collapsed')).toBe(false);
  });
});

// =============================================================================
// Tag Input Tests
// =============================================================================

describe('watcherHandleTagKeypress', () => {
  beforeEach(() => {
    document.body.innerHTML = `
      <div id="testHostsContainer">
        <input type="text" id="newHostInput" value="8.8.8.8">
      </div>
    `;
  });

  test('calls watcherAddTag on Enter key', () => {
    const event = {
      key: 'Enter',
      preventDefault: jest.fn()
    };

    watcherHandleTagKeypress(event);
    expect(event.preventDefault).toHaveBeenCalled();
  });

  test('does nothing for other keys', () => {
    const event = {
      key: 'a',
      preventDefault: jest.fn()
    };

    watcherHandleTagKeypress(event);
    expect(event.preventDefault).not.toHaveBeenCalled();
  });
});

describe('watcherAddTag', () => {
  beforeEach(() => {
    document.body.innerHTML = `
      <div id="testHostsContainer">
        <input type="text" class="tagInput" id="newHostInput" value="8.8.8.8">
      </div>
    `;
  });

  test('does nothing for empty input', () => {
    document.getElementById('newHostInput').value = '';
    watcherAddTag();

    const tags = document.querySelectorAll('.tag');
    expect(tags.length).toBe(0);
  });

  test('creates tag element for valid host', () => {
    watcherAddTag();

    const tags = document.querySelectorAll('.tag');
    expect(tags.length).toBe(1);
    expect(tags[0].textContent).toContain('8.8.8.8');
  });

  test('clears input after adding tag', () => {
    watcherAddTag();

    const input = document.getElementById('newHostInput');
    expect(input.value).toBe('');
  });

  test('prevents duplicate hosts', () => {
    // Add first host
    watcherAddTag();

    // Try to add same host again
    document.getElementById('newHostInput').value = '8.8.8.8';

    // Mock alert
    const alertMock = jest.spyOn(window, 'alert').mockImplementation(() => {});
    watcherAddTag();

    expect(alertMock).toHaveBeenCalledWith('This host is already in the list');
    alertMock.mockRestore();
  });

  test('creates hidden input for form submission', () => {
    watcherAddTag();

    const hiddenInputs = document.querySelectorAll('input[name="testHosts[]"]');
    expect(hiddenInputs.length).toBe(1);
    expect(hiddenInputs[0].value).toBe('8.8.8.8');
  });
});

describe('watcherRemoveTag', () => {
  beforeEach(() => {
    document.body.innerHTML = `
      <div id="testHostsContainer">
        <span class="tag">
          8.8.8.8
          <i class="tagRemove" id="removeBtn"></i>
          <input type="hidden" name="testHosts[]" value="8.8.8.8">
        </span>
      </div>
    `;
  });

  test('removes tag from DOM', () => {
    const removeBtn = document.getElementById('removeBtn');

    watcherRemoveTag(removeBtn);

    const tags = document.querySelectorAll('.tag');
    expect(tags.length).toBe(0);
  });
});

// =============================================================================
// Form Validation Tests
// =============================================================================

describe('validateForm', () => {
  beforeEach(() => {
    document.body.innerHTML = `
      <form id="watcherSettingsForm">
        <div id="testHostsContainer"></div>
      </form>
    `;
  });

  test('returns false when no test hosts', () => {
    const event = { preventDefault: jest.fn() };
    const alertMock = jest.spyOn(window, 'alert').mockImplementation(() => {});

    const result = validateForm(event);

    expect(result).toBe(false);
    expect(event.preventDefault).toHaveBeenCalled();
    expect(alertMock).toHaveBeenCalledWith('Please add at least one test host');

    alertMock.mockRestore();
  });

  test('returns true when test hosts exist', () => {
    document.getElementById('testHostsContainer').innerHTML = `
      <input type="hidden" name="testHosts[]" value="8.8.8.8">
    `;
    const event = { preventDefault: jest.fn() };

    const result = validateForm(event);

    expect(result).toBe(true);
    expect(event.preventDefault).not.toHaveBeenCalled();
  });
});

// =============================================================================
// Page Module Interface Tests
// =============================================================================

describe('config page module', () => {
  test('has required pageId', () => {
    expect(config.pageId).toBe('configUI');
  });

  test('has init method', () => {
    expect(typeof config.init).toBe('function');
  });

  test('has destroy method', () => {
    expect(typeof config.destroy).toBe('function');
  });

  test('exports eFuse storage methods', () => {
    expect(typeof config.calculateEfuseStorage).toBe('function');
    expect(typeof config.updateEfuseStorageEstimate).toBe('function');
    expect(typeof config.toggleEfuseOptions).toBe('function');
  });

  test('exports panel toggle method', () => {
    expect(typeof config.watcherTogglePanel).toBe('function');
  });

  test('exports tag input methods', () => {
    expect(typeof config.watcherHandleTagKeypress).toBe('function');
    expect(typeof config.watcherAddTag).toBe('function');
    expect(typeof config.watcherRemoveTag).toBe('function');
  });

  test('exports data management methods', () => {
    expect(typeof config.loadDataStats).toBe('function');
    expect(typeof config.toggleDataAccordion).toBe('function');
    expect(typeof config.clearDataCategory).toBe('function');
    expect(typeof config.clearDataFile).toBe('function');
  });

  test('exports config editor methods', () => {
    expect(typeof config.viewCollectdConfig).toBe('function');
    expect(typeof config.openWatcherEditor).toBe('function');
    expect(typeof config.saveWatcherConfig).toBe('function');
    expect(typeof config.closeWatcherEditor).toBe('function');
  });

  test('exports form validation', () => {
    expect(typeof config.validateForm).toBe('function');
  });
});
