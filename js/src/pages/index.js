/**
 * Page modules index
 *
 * Registry of all page modules for the watcher plugin.
 * Each page module follows a standard interface:
 *   - pageId: string - Matches data-watcher-page attribute
 *   - init(config): function - Initialize with config from PHP
 *   - destroy(): function - Cleanup and reset state
 */

// Phase 3: Large page modules
import { efuseMonitor } from './efuseMonitor.js';
import { falconMonitor } from './falconMonitor.js';
import { config } from './config.js';

// Phase 4: Smaller page modules
import { localMetrics } from './localMetrics.js';
import { connectivity } from './connectivity.js';
import { remoteMetrics } from './remoteMetrics.js';
import { remotePing } from './remotePing.js';
import { events } from './events.js';

// Phase 7: Remote control (complex page with modular sub-structure)
import { remoteControl } from './remoteControl/index.js';

// Phase 8: MultiSync metrics (complex page with modular sub-structure)
import { multiSyncMetrics } from './multiSyncMetrics/index.js';

/**
 * Page registry - maps pageId to page module
 */
export const pages = {
  // Phase 3: Large pages
  efuseMonitorUI: efuseMonitor,
  falconMonitorUI: falconMonitor,
  configUI: config,
  // Phase 4: Smaller pages
  localMetricsUI: localMetrics,
  connectivityUI: connectivity,
  remoteMetricsUI: remoteMetrics,
  remotePingUI: remotePing,
  eventsUI: events,
  // Phase 7: Complex page
  remoteControlUI: remoteControl,
  // Phase 8: MultiSync metrics
  multiSyncMetricsUI: multiSyncMetrics,
};

// Re-export individual page modules for direct import
export {
  efuseMonitor,
  falconMonitor,
  config,
  localMetrics,
  connectivity,
  remoteMetrics,
  remotePing,
  events,
  remoteControl,
  multiSyncMetrics,
};
