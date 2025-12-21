/**
 * Watcher Plugin - Main Entry Point
 *
 * This is the main entry point for the bundled watcher.js file.
 * It handles page detection and initialization based on data-watcher-page attribute.
 */

import { utils, charts, api, CHART_COLORS } from './core/index.js';
import { pages } from './pages/index.js';

/**
 * Initialize the appropriate page module based on data-watcher-page attribute
 */
function initPage() {
  // FPP plugin pages don't have their own body tag - they're included into FPP's template
  // So we look for any element with the data-watcher-page attribute
  const pageElement = document.querySelector('[data-watcher-page]');
  const pageId = pageElement?.dataset?.watcherPage;
  const pageConfig = window.watcherConfig || {};

  if (pageId && pages[pageId]) {
    // Initialize the page module
    pages[pageId].init(pageConfig);

    // Expose page shorthand for onclick handlers
    window.page = pages[pageId];
  }

  // Expose utilities globally for inline handlers and debugging
  window.watcher = {
    utils,
    charts,
    api,
    CHART_COLORS,
    pages,
  };
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initPage);
} else {
  initPage();
}

// Export for module usage
export { utils, charts, api, CHART_COLORS, pages };
