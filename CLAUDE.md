# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Watcher is an FPP (Falcon Player) plugin that provides network connectivity monitoring with automatic recovery, system metrics dashboards, multi-sync host monitoring, Falcon controller management, and remote FPP control. It runs on Raspberry Pi and BeagleBone devices.

## Common Commands

```bash
# Check PHP syntax for all plugin files
for file in /home/fpp/media/plugins/fpp-plugin-watcher/*.php /home/fpp/media/plugins/fpp-plugin-watcher/lib/*.php; do php -l "$file"; done

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

### UI Pages
- **configUI.php**: Plugin configuration
- **localMetricsUI.php**: Local system metrics (CPU, memory, disk, thermal, wireless)
- **connectivityUI.php**: Ping connectivity monitoring
- **falconMonitorUI.php**: Falcon hardware controller status
- **remoteMetricsUI.php**: Multi-sync remote host system metrics
- **remotePingUI.php**: Multi-sync remote host ping statistics
- **remoteControlUI.php**: Remote FPP system control panel

### Library Files (/lib/)
- **watcherCommon.php**: Constants, logging, network interface detection, FPP API wrappers
- **config.php**: Configuration reading/writing, collectd service management
- **metrics.php**: System metrics from collectd RRD files
- **pingMetricsRollup.php**: Ping metrics aggregation into tiers (1min, 5min, 15min, 1hour)
- **multiSyncPingMetrics.php**: Multi-sync host ping tracking and rollups
- **falconController.php**: Falcon hardware controller communication class
- **rollupBase.php**: Generic RRD-style rollup functions shared across metric types
- **apiCall.php**: HTTP request helper with cURL
- **resetNetworkAdapter.php**: Network adapter reset via FPP API
- **remoteControl.php**: Remote FPP instance control (restart, reboot, upgrade)
- **updateCheck.php**: GitHub version checking for plugin updates

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
