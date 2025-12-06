# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Watcher is an FPP (Falcon Player) plugin that provides network connectivity monitoring with automatic recovery, system metrics dashboards, multi-sync host monitoring, Falcon controller management, and remote FPP control. It runs on Raspberry Pi and BeagleBone devices.

## Common Commands

```bash
# Check PHP syntax for all plugin files
find /home/fpp/media/plugins/fpp-plugin-watcher -name "*.php" -exec php -l {} \;

# Chown files to fpp:fpp (required after editing)
sudo chown -R fpp:fpp /home/fpp/media/plugins/fpp-plugin-watcher/*

# View logs
tail -f /home/fpp/media/logs/fpp-plugin-watcher.log
tail -f /home/fpp/media/logs/fpp-plugin-watcher-ping-metrics.log

# Test API endpoints
curl http://127.0.0.1/api/plugin/fpp-plugin-watcher/version
curl http://127.0.0.1/api/plugin/fpp-plugin-watcher/metrics/all

# Restart FPP
sudo systemctl restart fppd
```

## Architecture

### Root Files
- **api.php**: REST API endpoint definitions and handlers
- **menu.inc**: Dynamic FPP menu registration based on enabled features
- **connectivityCheck.php**: Background daemon for network monitoring (runs via postStart.sh)
- **pluginInfo.json**: Plugin metadata and version info

### UI Pages (/ui/)
- **configUI.php**: Plugin configuration
- **localMetricsUI.php**: Local system metrics (CPU, memory, disk, thermal, wireless)
- **connectivityUI.php**: Ping connectivity monitoring
- **falconMonitorUI.php**: Falcon hardware controller status
- **remoteMetricsUI.php**: Multi-sync remote host system metrics
- **remotePingUI.php**: Multi-sync remote host ping statistics
- **remoteControlUI.php**: Remote FPP system control panel
- **eventsUI.php**: MQTT events dashboard
- **multiSyncMetricsUI.php**: Multi-sync status and comparison dashboard

### Library Files (/lib/)

Organized into subdirectories by function:

**lib/core/** - Foundation layer
- **watcherCommon.php**: Constants, logging, network interface detection, FPP API wrappers
- **config.php**: Configuration reading/writing, collectd service management
- **apiCall.php**: HTTP request helper with cURL

**lib/metrics/** - Metrics collection and rollup
- **rollupBase.php**: Generic RRD-style rollup functions shared across metric types
- **pingMetrics.php**: Ping metrics aggregation into tiers (1min, 5min, 30min, 2hour)
- **multiSyncPingMetrics.php**: Multi-sync host ping tracking and rollups
- **networkQualityMetrics.php**: Network quality metrics (latency, jitter, packet loss)
- **systemMetrics.php**: System metrics from collectd RRD files

**lib/multisync/** - Multi-sync functionality
- **syncStatus.php**: C++ plugin API wrapper for sync status and dashboard data
- **comparison.php**: Player vs remote sync comparison logic

**lib/controllers/** - Hardware and remote control
- **falcon.php**: Falcon hardware controller communication class
- **remoteControl.php**: Remote FPP instance control (restart, reboot, upgrade)
- **networkAdapter.php**: Network adapter reset via FPP API

**lib/ui/** - User interface helpers
- **common.php**: Shared PHP functions for UI pages (CSS/JS includes, access checks)

**lib/utils/** - Utilities
- **updateCheck.php**: GitHub version checking for plugin updates
- **mqttEvents.php**: MQTT event logging and retrieval

### Shared UI Assets
- **js/commonUI.js**: Shared JavaScript utilities for all UI pages
- **css/commonUI.css**: Shared CSS styles for dashboard pages

### Scripts (/scripts/)
- **postStart.sh**: Plugin startup (manages collectd, launches connectivityCheck.php)
- **fpp_install.sh**: Installation (collectd packages, Apache CSP, config files)
- **fpp_uninstall.sh**: Cleanup

### Configuration
- Config file: `/opt/fpp/media/config/plugin.fpp-plugin-watcher` (INI format)
- Use FPP's `WriteSettingToFile()` function to save settings
- Boolean values normalized via `normalizeBoolean()` in config.php

## Key Patterns

### Code Style
- Keep api.php simple; helper functions go in lib files
- Use `watcher` prefix for JS functions and CSS classes to avoid conflicts
- Concise comments only

### UI Page Development

All UI pages should use the shared utilities in `lib/ui/common.php` and `js/commonUI.js`.

**PHP Setup (top of file in ui/ directory):**
```php
<?php
include_once __DIR__ . '/../lib/core/config.php';
include_once __DIR__ . '/../lib/core/watcherCommon.php';
include_once __DIR__ . '/../lib/ui/common.php';

$config = readPluginConfig();
$localSystem = apiCall('GET', 'http://127.0.0.1/api/fppd/status', [], true, 5) ?: [];
$access = checkDashboardAccess($config, $localSystem, 'featureEnabledKey');

renderCSSIncludes(true);  // true = include Chart.js
renderCommonJS();
?>
```

**Access Control Pattern:**
```php
<?php if (!renderAccessError($access)): ?>
    <!-- Dashboard content here -->
<?php endif; ?>
```

**Available PHP Functions (lib/ui/common.php):**
- `renderCSSIncludes($includeChartJs)` - Renders CSS and optional Chart.js includes
- `renderCommonJS()` - Renders commonUI.js script tag
- `checkDashboardAccess($config, $localSystem, $enabledKey)` - Checks if feature is enabled and in player mode
- `renderAccessError($access)` - Shows error message if access denied, returns true if error shown
- `renderEmptyMessage($icon, $title, $message)` - Renders a centered empty state message

**Available JS Functions (js/commonUI.js):**
- `escapeHtml(text)` - XSS-safe HTML escaping
- `withButtonLoading(btn, iconClass, asyncFn)` - Wraps async function with button loading state
- `showElement(id)` / `hideElement(id)` - Show/hide elements by ID
- `fetchJson(url, timeout)` - Fetch JSON with error handling
- `CHART_COLORS` - Consistent color palette object
- `buildChartOptions(hours, options)` - Build Chart.js options with time axis
- `createDataset(label, data, color, options)` - Create Chart.js dataset
- `mapChartData(response, valueKey)` - Map API response to chart data points
- `updateOrCreateChart(charts, key, canvasId, type, datasets, options)` - Update or create chart
- `getChartColor(index)` - Get color from palette by index
- `formatBytes(bytes)` / `formatLatency(ms)` / `formatPercent(value)` - Formatters

### FPP Plugin Conventions
- Include `/opt/fpp/www/common.php` for FPP functions (`json()`, `WriteSettingToFile()`, etc.)
- Use `/** @disregard P1010 */` to suppress IDE warnings for FPP globals
- API endpoints return via `json($result)` function
- Menu entries use `$menuEntries` array with type: status/content/help

### Metrics Rollup System
- Raw data aggregates into 1min, 5min, 15min, 1hour buckets
- State tracked in JSON files (last_processed, last_bucket_end timestamps)
- Automatic tier selection based on requested time range

### File Ownership
- Use `ensureFppOwnership()` for plugin-created files
- Always chown edited files to fpp:fpp

## FPP Local API (http://127.0.0.1/api)

- `GET /api/system/status` - Get current FPP status
- `GET /api/network/interface` - Get all network interfaces
- `POST /api/network/interface/:interfaceName/apply` - Reset network interface
- `GET /api/fppd/status` - Get playback status

Full API docs: http://127.0.0.1/apihelp.php

## Resources

- **FPP Documentation**: https://github.com/FalconChristmas/fpp/tree/master/docs
- **Plugin Template**: https://github.com/FalconChristmas/fpp-plugin-Template
- **Example Plugins**: https://github.com/FalconChristmas/ (search for "fpp-plugin-")
- **FPP API Reference**: Check FPP source code in `/opt/fpp/www/api/` on device
- **Support Forum**: https://falconchristmas.com/forum/
