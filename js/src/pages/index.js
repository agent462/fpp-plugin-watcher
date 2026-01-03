/**
 * Page modules index
 *
 * Registry of all page modules for the watcher plugin.
 * Each page module follows a standard interface:
 *   - pageId: string - Matches data-watcher-page attribute
 *   - init(config): function - Initialize with config from PHP
 *   - destroy(): function - Cleanup and reset state
 */

import { efuseMonitor } from './efuseMonitor.js';
import { falconMonitor } from './falconMonitor.js';
import { config } from './config.js';
import { localMetrics } from './localMetrics.js';
import { connectivity } from './connectivity.js';
import { remoteMetrics } from './remoteMetrics.js';
import { remotePing } from './remotePing.js';
import { events } from './events.js';
import { remoteControl } from './remoteControl/index.js';
import { multiSyncMetrics } from './multiSyncMetrics/index.js';

/**
 * Page registry - maps pageId to page module
 */
export const pages = {
  efuseMonitorUI: efuseMonitor,
  falconMonitorUI: falconMonitor,
  configUI: config,
  localMetricsUI: localMetrics,
  connectivityUI: connectivity,
  remoteMetricsUI: remoteMetrics,
  remotePingUI: remotePing,
  eventsUI: events,
  remoteControlUI: remoteControl,
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
