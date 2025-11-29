# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Watcher is an FPP (Falcon Player) plugin that provides network connectivity monitoring with automatic recovery, system metrics dashboards, multi-sync host monitoring, and Falcon controller management. It runs on Raspberry Pi and BeagleBone devices.

## Common Commands

Make sure you chown all new files and files you have edited.

```bash
# Check PHP syntax for all plugin files
for file in /home/fpp/media/plugins/fpp-plugin-watcher/*.php /home/fpp/media/plugins/fpp-plugin-watcher/lib/*.php; do php -l "$file"; done

# Chown new files and files you edit to fpp:fpp
cd /home/fpp/media/plugins/fpp-plugin-watcher/
chown -R fpp:fpp *

# View plugin logs
tail -f /home/fpp/media/logs/fpp-plugin-watcher.log

# View ping metrics log
tail -f /home/fpp/media/logs/fpp-plugin-watcher-ping-metrics.log

# Test API endpoints locally
curl http://127.0.0.1/api/plugin/fpp-plugin-watcher/version
curl http://127.0.0.1/api/plugin/fpp-plugin-watcher/metrics/ping/raw
curl http://127.0.0.1/api/plugin/fpp-plugin-watcher/metrics/all

# Restart FPP to reload plugin
sudo systemctl restart fppd
```

## Architecture

### File Structure
- **api.php**: REST API endpoint definitions and handlers. All endpoints registered via `getEndpointsfpppluginwatcher()`
- **\*UI.php files**: Web interface pages (configUI, metricsUI, connectivityUI, falconMonitorUI, allMetricsUI, multiSyncPingUI)
- **menu.inc**: Dynamic FPP menu registration based on enabled features
- **connectivityCheck.php**: Background daemon for network monitoring loop (runs via postStart.sh)

### Library Files (/lib/)
- **watcherCommon.php**: Constants, logging, network interface detection, FPP API wrappers
- **config.php**: Configuration reading/writing, collectd service management
- **metrics.php**: System metrics from collectd RRD files (CPU, memory, disk, thermal, wireless)
- **pingMetricsRollup.php**: Ping metrics aggregation into tiers (1min, 5min, 15min, 1hour)
- **multiSyncPingMetrics.php**: Multi-sync host ping tracking and rollups
- **falconController.php**: Falcon hardware controller communication class
- **rollupBase.php**: Generic RRD-style rollup functions shared across metric types
- **apiCall.php**: HTTP request helper with cURL
- **resetNetworkAdapter.php**: Network adapter reset via FPP API

### Scripts (/scripts/)
- **postStart.sh**: Plugin startup (manages collectd, launches connectivityCheck.php)
- **fpp_install.sh**: Installation (collectd packages, Apache CSP, config files)
- **fpp_uninstall.sh**: Cleanup

### Configuration
- Config file location: `/opt/fpp/media/config/plugin.fpp-plugin-watcher` (INI format)
- Use FPP's `WriteSettingToFile()` function to save settings
- Boolean values stored as strings, normalized via `normalizeBoolean()` in config.php

## Key Patterns

### FPP Plugin Conventions
- Include `/opt/fpp/www/common.php` for FPP functions (`json()`, `WriteSettingToFile()`, etc.)
- Use `/** @disregard P1010 */` comment to suppress IDE warnings for FPP globals
- API endpoints return via `json($result)` function
- Menu entries use `$menuEntries` array with type: status/content/help

### Metrics Rollup System
Metrics are aggregated into time-based tiers for efficient historical queries:
- Raw data → 1min → 5min → 15min → 1hour buckets
- State tracked in JSON files (last_processed, last_bucket_end timestamps)
- Automatic tier selection based on requested time range

### File Ownership
Files created by the plugin use `ensureFppOwnership()` to set fpp:fpp ownership for web access.

#### FPP Local API (http://127.0.0.1/api)

Used by the plugin to interact with FPP:

- `GET /api/system/status` - Get current FPP status
- `GET /api/network/interface` - Get all network interfaces
- `GET /api/network/interface/:interfaceName` - Get specific interface settings
- `POST /api/network/interface/:interfaceName/apply` - Reset network interface (used by plugin)
- `GET /api/plugin/:RepoName/settings/:SettingName` - Get plugin setting (not currently used)
- `POST /api/plugin/:RepoName/settings/:SettingName` - Update plugin setting (not currently used)

Full list of FPP API's located at http://127.0.0.1/apihelp.php

## Additional Resources

- **FPP Documentation**: https://github.com/FalconChristmas/fpp/tree/master/docs
- **Plugin Template**: https://github.com/FalconChristmas/fpp-plugin-Template
- **Example Plugins**: https://github.com/FalconChristmas/ (search for "fpp-plugin-")
- **FPP API Reference**: Check FPP source code in `/opt/fpp/www/api/` on device
- **Support Forum**: https://falconchristmas.com/forum/