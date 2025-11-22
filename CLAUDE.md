# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Watcher is an FPP (Falcon Player) plugin that enables comprehensive system monitoring including network connectivity, CPU, memory, disk, and network interface metrics. The plugin automatically resets the network adapter when consecutive connectivity failures are detected. It runs as a background service on FPP hardware, communicating with the FPP local API, collectd RRD database, and Linux system. It includes a web UI for configuration and dual real-time dashboards for viewing connectivity and system metrics.

## Technology Stack

- **Language**: PHP 7+
- **Platform**: FPP (Falcon Player) - Linux-based show controller
- **Frontend**: Vanilla JavaScript, HTML5, CSS3
- **Charts**: Chart.js for real-time metrics visualization
- **APIs**: FPP REST API (local), cURL for HTTP requests
- **Logging**: File-based logging with JSON metrics

## Repository Structure

### Core Files

- `connectivityCheck.php` - Main background service that continuously monitors network connectivity, tracks failures, and triggers network adapter reset via FPP API
- `api.php` - REST API endpoints exposed by the plugin (version, connectivity metrics, system metrics)
- `pluginInfo.json` - FPP plugin manifest with version compatibility and metadata

### UI Files

- `configUI.php` - Configuration interface for plugin settings (connectivityCheckEnabled, interval, failures, adapter, hosts)
- `displayUI.php` - Real-time system monitor dashboard with CPU, memory, disk, and network interface metrics
- `metricsUI.php` - Connectivity metrics dashboard with detailed ping statistics and charts
- `about.php` - Plugin information and credits page
- `menu.inc` - Menu entries for FPP web interface integration

### Library Files (`lib/`)

- `lib/watcherCommon.php` - Global constants, version info, default settings, and logging function
- `lib/config.php` - Configuration file management (read, write, bootstrap, prepare)
- `lib/resetNetworkAdapter.php` - Network adapter reset function using FPP API
- `lib/apiCall.php` - Generic API call helper for FPP API interactions
- `lib/metrics.php` - Ping metrics retrieval and processing (last 24 hours)

### Commands (`commands/`)

- `commands/on.sh` - Enable plugin command (placeholder)
- `commands/off.sh` - Disable plugin command (placeholder)
- `commands/descriptions.json` - Command definitions for FPP integration

### Scripts (`scripts/`)

- `scripts/fpp_install.sh` - Installation hook (adds CSP for CDN resources)
- `scripts/postStart.sh` - Starts connectivityCheck.php background process
- `scripts/postStop.sh` - Cleanup after plugin stops
- `scripts/fpp_uninstall.sh` - Uninstallation cleanup

### Documentation

- `README.md` - User-facing documentation and setup guide
- `CONFIGURATION_REFERENCE.md` - Comprehensive configuration parameter reference
- `CLAUDE.md` - This file - AI assistant guidance

## Architecture

FPP Plugins are designed to work with Falcon Pi Player (FPP). The FPP repository is located at https://github.com/FalconChristmas/fpp. A template plugin repository is located at: https://github.com/FalconChristmas/fpp-plugin-Template/. There are other example templates located in https://github.com/FalconChristmas/ and https://github.com/Remote-Falcon/remote-falcon-plugin.

All plugin design should seamlessly integrate within FPP and use the FPP REST API when possible. There are also common functions and variables that can be used from FPP directly (available in `/opt/fpp/www/common.php`).

### Plugin Lifecycle

1. **Installation** (`fpp_install.sh`):
   - Adds Content-Security-Policy entries for CDN resources (Chart.js)
   - Sets restart flag to reload FPP

2. **Startup** (`postStart.sh`):
   - Launches `connectivityCheck.php` as background daemon
   - Daemon runs continuously until plugin stops

3. **Runtime**:
   - Background service monitors connectivity
   - Web UI provides configuration and metrics
   - API endpoints serve data to dashboard

4. **Shutdown** (`postStop.sh`):
   - Kills background connectivity check process

5. **Uninstall** (`fpp_uninstall.sh`):
   - Cleanup and removal

### Watcher Service (connectivityCheck.php)

The connectivity checker runs as a continuous PHP process managed by FPP:

1. **Initialization**:
   - Includes required libraries from `lib/` directory
   - Loads configuration from `/opt/fpp/media/config/plugin.fpp-plugin-watcher`
   - Exits if `connectivityCheckEnabled` setting is false
   - Logs startup information

2. **Main Loop**:
   - Continuously polls network by pinging test hosts using `ping` command
   - Tests each host in order until one succeeds
   - Records latency metrics in JSON format to separate log file
   - Increments failure counter on failure, resets on success
   - When failures >= maxFailures, calls `resetNetworkAdapter()` function
   - Sleeps for `checkInterval` seconds between checks

3. **Metrics Collection**:
   - Each successful ping logs JSON entry: `{timestamp, host, latency, status}`
   - Metrics logged to `/home/fpp/media/logs/fpp-plugin-watcher-ping-metrics.log`
   - Accessible via API endpoint for dashboard visualization

4. **Network Reset**:
   - Calls FPP API: `POST /api/network/interface/{adapter}/apply`
   - Only resets once per run (prevents endless loops)
   - Logs reset attempt and API response

### Key Configuration Settings

Stored in INI-format file: `/opt/fpp/media/config/plugin.fpp-plugin-watcher`

Managed through web UI or FPP API:

- `connectivityCheckEnabled` (boolean) - Enable/disable the connectivity checker
- `checkInterval` (integer) - Seconds between connectivity checks (default: 20)
- `maxFailures` (integer) - Consecutive failures before reset (default: 3)
- `networkAdapter` (string) - Interface to monitor/reset (default: 'eth0')
- `testHosts` (comma-separated string) - Hosts to ping (default: '8.8.8.8,1.1.1.1')
- `collectdEnabled` (boolean) - Enable/disable collectd service for system metrics (default: false)
- `metricsRotationInterval` (integer) - Interval in seconds for rotating metrics logs (default: 1800)

Configuration is automatically bootstrapped with defaults if file doesn't exist.

### Constants and Paths

Defined in `lib/watcherCommon.php`:

```php
WATCHERVERSION = 'v1.0.0'
WATCHERPLUGINNAME = 'fpp-plugin-watcher'
WATCHERPLUGINDIR = '/home/fpp/media/plugins/fpp-plugin-watcher/'
WATCHERCONFIGFILELOCATION = '/opt/fpp/media/config/plugin.fpp-plugin-watcher'
WATCHERLOGFILE = '/home/fpp/media/logs/fpp-plugin-watcher.log'
WATCHERPINGMETRICSFILE = '/home/fpp/media/logs/fpp-plugin-watcher-ping-metrics.log'
```

### API Integration

#### Plugin API Endpoints

Exposed via `api.php` and accessible at `/api/plugin/fpp-plugin-watcher/*`:

- `GET /api/plugin/fpp-plugin-watcher/version` - Returns plugin version
- `GET /api/plugin/fpp-plugin-watcher/metrics` - Returns ping metrics from last 24 hours
- `GET /api/plugin/fpp-plugin-watcher/metrics/memory/free` - Returns free memory metrics (supports `?hours=N` parameter)
- `GET /api/plugin/fpp-plugin-watcher/metrics/disk/free` - Returns free disk metrics (supports `?hours=N` parameter)
- `GET /api/plugin/fpp-plugin-watcher/metrics/cpu/average` - Returns CPU average metrics (supports `?hours=N` parameter)
- `GET /api/plugin/fpp-plugin-watcher/metrics/load/average` - Returns load average metrics (supports `?hours=N` parameter)
- `GET /api/plugin/fpp-plugin-watcher/metrics/interface/bandwidth` - Returns network interface bandwidth metrics
- `GET /api/plugin/fpp-plugin-watcher/metrics/interface/list` - Returns list of available network interfaces

Response format for connectivity metrics:
```json
{
  "success": true,
  "count": 1234,
  "data": [
    {"timestamp": 1234567890, "host": "8.8.8.8", "latency": 12.34, "status": "success"}
  ],
  "period": "24h"
}
```

Response format for system metrics (CPU, memory, disk, load):
```json
{
  "success": true,
  "data": [
    {"timestamp": 1234567890, "value": 45.2},
    {"timestamp": 1234571490, "value": 48.7}
  ],
  "unit": "percent|MB|GB|loadavg"
}
```

#### FPP Local API (http://127.0.0.1/api)

Used by the plugin to interact with FPP:

- `GET /api/system/status` - Get current FPP status
- `GET /api/network/interface` - Get all network interfaces
- `GET /api/network/interface/:interfaceName` - Get specific interface settings
- `POST /api/network/interface/:interfaceName/apply` - Reset network interface (used by plugin)
- `GET /api/plugin/:RepoName/settings/:SettingName` - Get plugin setting (not currently used)
- `POST /api/plugin/:RepoName/settings/:SettingName` - Update plugin setting (not currently used)

Note: Plugin currently uses FPP's native `ReadSettingFromFile()` and `WriteSettingToFile()` functions from `common.php` instead of API endpoints.

### Menu Integration

Menu entries defined in `menu.inc`:

- **Content Menu**: "Watcher - Config" → `configUI.php`
- **Content Menu**: "Watcher - Display" → `displayUI.php` (System monitor dashboard)
- **Content Menu**: "Watcher - Metrics" → `metricsUI.php` (Connectivity metrics dashboard)
- **Help Menu**: "Watcher - Home" → GitHub repository (external link)
- **Help Menu**: "Watcher - About" → `about.php`

FPP automatically integrates these into its web interface menu structure.

### Version Support

The plugin supports FPP version 9.0 and above:

- FPP 9.0+: `main` branch (current)

Legacy FPP 8.x is not supported by this plugin.

## Development Workflow

### Local Development

Since this plugin runs on FPP hardware (Raspberry Pi, BeagleBone, etc.), local development is limited. The typical workflow is:

1. Edit files locally on your development machine
2. Commit and push changes to GitHub
3. Update plugin on FPP hardware via web interface
4. Test and monitor logs

### Testing Changes

1. **Edit locally**: Make code changes in your preferred editor
2. **Commit**: `git add .` and `git commit -m "description"`
3. **Push**: `git push origin main` (or feature branch)
4. **Update on FPP**:
   - Navigate to: Content Setup → Plugin Manager
   - Find "Watcher" plugin
   - Click "Update" button or toggle off/on to reload
5. **Monitor logs**:
   - Main log: `/home/fpp/media/logs/fpp-plugin-watcher.log`
   - Metrics log: `/home/fpp/media/logs/fpp-plugin-watcher-ping-metrics.log`
   - FPP log: `/home/fpp/media/logs/fppd.log`

### Debugging

Enable verbose logging:
- Set `verboseLogging=1` in config file
- Or enable via web UI configuration page
- Watch logs in real-time: `tail -f /home/fpp/media/logs/fpp-plugin-watcher.log`

Check if background process is running:
```bash
ps aux | grep connectivityCheck.php
```

Test network reset manually:
```bash
curl -X POST http://127.0.0.1/api/network/interface/eth0/apply
```

### Common FPP Functions

Available from `/opt/fpp/www/common.php` (automatically included):

```php
ReadSettingFromFile($setting, $plugin = "")
WriteSettingToFile($setting, $value, $plugin = "")
logEntry($data)
json($data) // Return JSON response
```

Global variables:
```php
$settings['pluginDirectory']  // /home/fpp/media/plugins
$settings['configDirectory']  // /opt/fpp/media/config
$settings['logDirectory']     // /home/fpp/media/logs
```

## Important Patterns

### Configuration Management

1. **Reading Configuration**:
   - Use `readPluginConfig()` from `lib/config.php`
   - Returns associative array with all settings
   - Auto-creates config file with defaults if missing
   - Processes `testHosts` into array format

2. **Writing Configuration**:
   - Use FPP's `WriteSettingToFile($name, $value, 'fpp-plugin-watcher')`
   - Values are URL-encoded automatically by FPP
   - File is INI format: `key=value`

3. **Bootstrap Process**:
   - `lib/config.php` checks if config exists on load
   - If missing, calls `setDefaultWatcherSettings()`
   - Default values defined in `WATCHERDEFAULTSETTINGS` constant

### Logging

Two logging methods available:

```php
// Main application logging (always logs)
logMessage($message, $file = WATCHERLOGFILE);

// Metrics logging (JSON format)
$metricsEntry = json_encode(['timestamp' => time(), 'host' => $host, 'latency' => $ms]);
logMessage($metricsEntry, WATCHERPINGMETRICSFILE);
```

Log format: `[YYYY-MM-DD HH:MM:SS] message`

### Watcher Control

The watcher service is controlled by FPP's plugin system:

**Starting**:
- Enable plugin in Plugin Manager → triggers `postStart.sh`
- `postStart.sh` launches PHP daemon: `/usr/bin/php .../connectivityCheck.php &`

**Stopping**:
- Disable plugin in Plugin Manager → triggers `postStop.sh`
- Process is killed by FPP's plugin management

**Status Check**:
- Check if running: `ps aux | grep connectivityCheck.php`
- View logs: `tail -f /home/fpp/media/logs/fpp-plugin-watcher.log`

**Configuration Changes**:
- Require restart of background process to take effect
- Toggle plugin off/on in Plugin Manager

### Execution Flow

#### Startup Flow

1. User enables plugin in FPP Plugin Manager
2. FPP executes `scripts/postStart.sh`
3. postStart.sh launches `connectivityCheck.php` as background daemon
4. connectivityCheck.php:
   - Loads `lib/watcherCommon.php` (constants, logging)
   - Loads `lib/resetNetworkAdapter.php` (reset function)
   - Loads `lib/config.php` (which reads/creates config)
   - Checks if `connectivityCheckEnabled` is true, exits if false
   - Enters infinite loop

#### Monitoring Loop Flow

```
while (true):
  1. checkConnectivity(testHosts, networkAdapter)
     - Try pinging each host until one succeeds
     - Extract latency from ping output
     - Log metrics to JSON file
     - Return true if any host responds

  2. If success:
     - Reset failureCount to 0
     - Log "Connection OK" with latency

  3. If failure:
     - Increment failureCount
     - Log "Connection FAILED"
     - If failureCount >= maxFailures AND not yet reset:
       - Call resetNetworkAdapter()
       - Set hasResetAdapter flag

  4. Sleep for checkInterval seconds
```

#### Reset Flow

1. `resetNetworkAdapter($adapter)` called from main loop
2. Builds API URL: `http://127.0.0.1/api/network/interface/{adapter}/apply`
3. Sends POST request using cURL
4. Waits 30 seconds timeout
5. Checks HTTP response code (200-299 = success)
6. Logs result and sleeps 10 seconds
7. Returns to main loop (reset flag prevents repeated resets)

#### System Monitor Dashboard Flow (displayUI.php)

1. User navigates to "Watcher - Display" menu
2. FPP loads `displayUI.php`
3. Page renders with system metrics cards and Chart.js visualizations
4. JavaScript polls multiple API endpoints every 30 seconds:
   - `/api/plugin/fpp-plugin-watcher/metrics/cpu/average`
   - `/api/plugin/fpp-plugin-watcher/metrics/memory/free`
   - `/api/plugin/fpp-plugin-watcher/metrics/disk/free`
   - `/api/plugin/fpp-plugin-watcher/metrics/load/average`
   - `/api/plugin/fpp-plugin-watcher/metrics/interface/bandwidth`
5. Each endpoint fetches data from collectd RRD database:
   - Reads `/var/lib/collectd/rrd/{hostname}/{category}/{metric}.rrd`
   - Parses RRD format using `rrdtool fetch` command
   - Filters to requested time period (default: 24 hours)
   - Returns time-series data
6. JavaScript displays real-time graphs with latest statistics

#### Connectivity Metrics Dashboard Flow (metricsUI.php)

1. User navigates to "Watcher - Metrics" menu
2. FPP loads `metricsUI.php`
3. Page renders with connectivity statistics and Chart.js visualizations
4. JavaScript polls `/api/plugin/fpp-plugin-watcher/metrics` every 30 seconds
5. Metrics endpoint (`api.php` → `getPingMetrics()`):
   - Reads `fpp-plugin-watcher-ping-metrics.log`
   - Parses JSON from each log line
   - Filters to last 24 hours
   - Returns sorted array with per-host statistics
6. JavaScript updates charts with connectivity data (latency, success rate, per-host metrics)

## Plugin Installation Hooks

FPP calls these scripts at specific lifecycle events:

- `fpp_install.sh` - Adds required Content-Security-Policy for CDN (Chart.js from jsdelivr), sets restart flag
- `postStart.sh` - Launches connectivityCheck.php background daemon
- `postStop.sh` - Cleanup when plugin stops (process killed by FPP)
- `fpp_uninstall.sh` - Cleanup on plugin removal

## Commands

FPP supports custom commands that can be triggered from sequences or schedules.

Each command is defined in `commands/descriptions.json`:
- `on.sh` - Placeholder "On" command
- `off.sh` - Placeholder "Off" command

**Note**: These commands are currently placeholders and don't perform any actions beyond logging. They could be extended to enable/disable the watcher service programmatically.

## Code Style and Conventions

### PHP

- Use `include_once` for library files to prevent duplicate inclusion
- Always include `/opt/fpp/www/common.php` for FPP functions
- Use `/** @disregard P1010 */` to suppress IDE warnings for FPP functions
- Functions use camelCase: `checkConnectivity()`, `logMessage()`
- Global constants in UPPERCASE: `WATCHERVERSION`, `WATCHERLOGFILE`
- Configuration arrays use snake_case keys: `max_failures`, `check_interval`

### JavaScript

- Vanilla JavaScript (no frameworks)
- Use `fetch()` API for HTTP requests
- Chart.js for visualizations
- Keep code inline in PHP files (no separate JS files currently)

### Logging

- Always timestamp log entries
- Use descriptive messages
- Include relevant context (host, latency, adapter)
- JSON format for metrics data
- Human-readable format for main log

### Error Handling

- Check return codes from `exec()` calls
- Validate cURL responses and HTTP codes
- Log all errors with context
- Fail gracefully (don't crash daemon on errors)

## Security Considerations

- All API calls are local (127.0.0.1) to FPP
- No external network calls except ping tests
- Configuration file in protected FPP directory
- No user input validation needed (FPP handles in UI)
- Command execution uses validated inputs only

## Troubleshooting Guide

### Plugin Not Starting

1. Check plugin is enabled in Plugin Manager
2. Verify postStart.sh has execute permissions: `ls -la scripts/`
3. Check FPP logs: `tail -f /home/fpp/media/logs/fppd.log`
4. Look for PHP errors: `tail -f /home/fpp/media/logs/fpp-plugin-watcher.log`

### Connectivity Not Being Monitored

1. Check if process is running: `ps aux | grep connectivityCheck`
2. Verify `connectivityCheckEnabled=1` in config: `cat /opt/fpp/media/config/plugin.fpp-plugin-watcher`
3. Check for errors in log file
4. Test ping manually: `ping -I eth0 -c 1 8.8.8.8`

### Network Not Resetting

1. Verify FPP API is accessible: `curl http://127.0.0.1/api/system/status`
2. Check network adapter name: `ip link show`
3. Test API call manually: `curl -X POST http://127.0.0.1/api/network/interface/eth0/apply`
4. Review logs for API error messages
5. Check if already reset (only resets once): look for "hasResetAdapter" flag in logic

### Dashboard Not Showing Data

1. Verify metrics file exists: `ls -la /home/fpp/media/logs/fpp-plugin-watcher-ping-metrics.log`
2. Check metrics file has data: `tail /home/fpp/media/logs/fpp-plugin-watcher-ping-metrics.log`
3. Test API endpoint: `curl http://127.0.0.1/api/plugin/fpp-plugin-watcher/metrics`
4. Check browser console for JavaScript errors
5. Verify Chart.js CDN is accessible (CSP must be configured)

### Configuration Not Saving

1. Check file permissions: `ls -la /opt/fpp/media/config/`
2. Verify FPP's `WriteSettingToFile()` works for other plugins
3. Check FPP logs for permission errors
4. Restart plugin after config changes

## Additional Resources

- **FPP Documentation**: https://github.com/FalconChristmas/fpp/tree/master/docs
- **Plugin Template**: https://github.com/FalconChristmas/fpp-plugin-Template
- **Example Plugins**: https://github.com/FalconChristmas/ (search for "fpp-plugin-")
- **FPP API Reference**: Check FPP source code in `/opt/fpp/www/api/` on device
- **Support Forum**: https://falconchristmas.com/forum/

## Development Tips for AI Assistants

1. **Always check file paths**: FPP uses specific directory structures (`/opt/fpp/`, `/home/fpp/`)
2. **Test on actual hardware**: Many features can't be simulated locally
3. **Use FPP functions**: Don't reinvent configuration/logging when FPP provides it
4. **Follow plugin conventions**: Look at other FPP plugins for patterns
5. **Log verbosely during development**: Makes debugging on remote hardware easier
6. **Remember the daemon pattern**: Main service runs as long-lived background process
7. **Version compatibility**: Check FPP version when using API features
8. **CSP requirements**: Any external resources need CSP entries in fpp_install.sh

## System Metrics Collection

The plugin integrates with collectd (system metrics collector) which stores data in RRD (Round-Robin Database) format:

### Supported Metrics

**Memory**:
- `memory-free` - Free memory in bytes
- `memory-cached` - Cached memory in bytes
- `memory-buffered` - Buffered memory in bytes

**CPU**:
- `cpu-{N}-idle`, `cpu-{N}-user`, `cpu-{N}-system` - Per-core CPU stats

**Disk**:
- `df-{mount}/df_complex-free` - Free space on mount point
- `df-root` - Free space on root filesystem

**Load**:
- `load` - System load average

**Network Interfaces**:
- `interface-{name}/if_octets` - Bytes in/out
- `interface-{name}/if_packets` - Packets in/out
- `interface-{name}/if_errors` - Errors in/out
- `interface-{name}/if_dropped` - Dropped packets in/out

### RRD File Locations

```
/var/lib/collectd/rrd/{hostname}/memory/memory-*.rrd
/var/lib/collectd/rrd/{hostname}/cpu-*/cpu-*.rrd
/var/lib/collectd/rrd/{hostname}/df-*/df_complex-*.rrd
/var/lib/collectd/rrd/{hostname}/load/load.rrd
/var/lib/collectd/rrd/{hostname}/interface-*/if_*.rrd
```

### Metrics Retrieval

The `lib/metrics.php` file provides functions to retrieve RRD data:

```php
getCollectdMetrics($category, $metric, $consolidationFunction, $hoursBack)
getMemoryFreeMetrics($hoursBack)
getDiskFreeMetrics($hoursBack)
getCPUAverageMetrics($hoursBack)
getLoadAverageMetrics($hoursBack)
getInterfaceBandwidthMetrics($hoursBack)
getInterfaceList()
```

---

**Last Updated**: November 2025
**Plugin Version**: 1.1.0
**Maintained By**: Bryan Brandau (agent462)
