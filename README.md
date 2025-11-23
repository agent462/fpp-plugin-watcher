# Watcher Plugin for FPP

A comprehensive monitoring solution for Falcon Player (FPP) that provides network connectivity monitoring with automatic recovery and real-time system health dashboards. When network connectivity fails, Watcher automatically resets the network adapter via the FPP API. The plugin includes dual real-time dashboards for visualizing both network connectivity metrics and comprehensive system performance data.

## Supported Platforms

**FPP Version**: 9.0 or higher

**Hardware Platforms**:
- Raspberry Pi 3, 4, 5
- BeagleBone Black (BBB)
- PocketBeagle 2 (PB2)
- PocketBeagle (PB) - To be tested

**Performance Note**: BBB and PB are single-core devices with limited memory. While this plugin is optimized for low CPU and memory usage, be mindful of overall system performance when running multiple intensive plugins or FPP features. The System Monitor Dashboard makes it easy to track resource usage in real-time.

## Features

### Network Monitoring
- **Smart Network Interface Detection**: Automatically detects and configures the active network interface (Ethernet or WiFi)
- **Continuous Connectivity Monitoring**: Checks network connectivity at configurable intervals (default: 20 seconds)
- **Configurable Test Hosts**: Ping any combination of hostnames or IP addresses (default: 8.8.8.8, 1.1.1.1)
- **Automatic Network Reset**: Intelligently resets network adapter via FPP API after consecutive failures
- **Connectivity Metrics Dashboard**: Visualize ping latency, success rates, and per-host statistics over 24 hours

### System Health Monitoring
- **collectd Integration**: Leverages FPP's collectd service for comprehensive system metrics
- **System Metrics Dashboard**: Real-time CPU, memory, disk, and network interface monitoring
- **Historical Data**: Tracks system performance with customizable time ranges (up to 24 hours)
- **Network Bandwidth Tracking**: Monitor interface throughput, packets, errors, and drops

### Data & API
- **Comprehensive Metrics**: Automatic log rotation with configurable intervals
- **REST API**: Access connectivity and system metrics programmatically via plugin endpoints
- **JSON Metrics Export**: All data available in machine-readable format
- **Configurable Web Interface**: Easy setup through FPP's intuitive web UI

## Installation

1. Navigate to **Content Setup → Plugin Manager** in the FPP web interface
2. Search for "Watcher" or install from repository: `https://github.com/agent462/fpp-plugin-watcher`
3. Click **Install**
4. FPP will automatically install dependencies and configure the plugin

## Configuration

### Access Configuration Page

Navigate to **Content Setup → Watcher - Config** in the FPP web interface.

### Settings

#### Connectivity Monitoring

- **Enable Connectivity Check**: Toggle to enable/disable the connectivity monitor
- **Check Interval**: How often to test connectivity (5-3600 seconds, default: 20)
- **Max Failures Before Reset**: Number of consecutive failures before resetting adapter (1-100, default: 3)
- **Network Adapter**: Select your network interface
  - **Auto-detect**: Automatically detects the active interface with an IP address (recommended)
  - On first run or save, auto-detect finds the active interface and saves it permanently
  - Manual selection available: eth0 (Ethernet), wlan0 (WiFi), or other detected interfaces
  - You can switch back to "Auto-detect" at any time to re-detect the active interface
- **Test Hosts/IPs**: Add one or more hosts to ping for connectivity checks
  - Examples: `8.8.8.8`, `1.1.1.1`, `google.com`, `your-gateway-ip`
  - The monitor tests each host in order until one succeeds
  - Recommended: 2-3 hosts maximum to avoid excessive network traffic

#### System Metrics

- **Enable collectd Service**: Toggle to enable/disable collectd for system metrics collection (default: disabled)
  - **Required** for System Monitor Dashboard to display CPU, memory, disk, and network data
  - When enabled, collectd stores metrics in RRD (Round-Robin Database) format
  - Minimal performance impact, but can be disabled on resource-constrained devices
- **Metrics Rotation Interval**: How often to rotate ping metrics logs (300-86400 seconds, default: 1800)
  - Prevents log files from growing too large
  - Older metrics are automatically archived

### Saving Configuration

1. Click **Save Settings**
2. FPP will restart to apply changes
3. Enable the plugin in **Plugin Manager** if not already enabled

## Dashboards

The plugin provides two real-time monitoring dashboards accessible from the FPP web interface **Content Setup** menu:

### System Monitor Dashboard
**Access**: **Content Setup → Watcher - Display**

Displays comprehensive real-time system performance metrics collected via collectd:

**Core System Metrics**:
- **CPU Usage**: Current and historical CPU utilization across all cores
- **Memory Statistics**: Free, cached, and buffered memory in MB/GB
- **Disk Space**: Free disk space on all mounted volumes
- **Load Average**: System load average over time

**Network Metrics**:
- **Network Bandwidth**: Interface throughput (bytes/packets in/out)
- **Network Errors & Drops**: Packet errors and dropped packets per interface

**Thermal Monitoring** (NEW):
- **Temperature Sensors**: Real-time temperature monitoring from all available thermal zones
- **Heat Tracking**: Historical temperature data to identify thermal issues

**Wireless Metrics** (NEW - for WiFi interfaces):
- **Signal Strength**: WiFi signal quality and strength
- **Link Quality**: Connection quality metrics
- **Noise Level**: Wireless interference tracking

**Requirements**: collectd service must be enabled in plugin configuration.

Charts auto-refresh every 30 seconds with customizable historical data (default: 24 hours).

### Connectivity Metrics Dashboard
**Access**: **Content Setup → Watcher - Metrics**

Displays detailed network connectivity monitoring with advanced rollup aggregations:

**Connection Statistics**:
- **Summary Stats**: Success rate, average latency, total connectivity checks
- **Ping Latency Graph**: Real-time chart showing latency trends
- **Success/Failure Distribution**: Visual breakdown of connectivity status
- **Per-Host Metrics**: Individual statistics for each configured test host

**Advanced Features** (NEW):
- **Ping Data Rollups**: Automatically aggregated metrics at multiple time intervals (1min, 5min, 15min, 1hour)
- **Efficient Data Retrieval**: Optimized for long-term historical analysis without performance impact
- **Raw Data Access**: Direct access to individual ping results for detailed troubleshooting

Charts auto-refresh every 30 seconds to display the latest connectivity data.

## How It Works

### Connectivity Monitoring

1. **Initial Setup**: On first run, the plugin automatically detects the active network interface (eth0, wlan0, etc.) using the FPP API and saves it to the configuration
2. **Background Service**: The `connectivityCheck.php` daemon runs continuously when the plugin is enabled
3. **Connectivity Checks**: Every `checkInterval` seconds, the service pings each test host in order
4. **Success**: If any host responds, the failure counter resets and latency is logged
5. **Failure**: If all hosts fail, the failure counter increments
6. **Network Reset**: When failures reach `maxFailures`, the FPP API resets the network adapter
7. **Metrics Logging**: Each ping result is logged as JSON and automatically aggregated into rollup tiers
8. **Continuous Monitoring**: The service continues monitoring after reset (does not stop)

### System Metrics Collection

When collectd is enabled:
1. **collectd Service**: Continuously collects system metrics (CPU, memory, disk, network, thermal, wireless)
2. **RRD Storage**: Metrics are stored in Round-Robin Database format for efficient historical data storage
3. **API Access**: Plugin APIs read RRD files and convert data to JSON format
4. **Dashboard Display**: Chart.js visualizes the metrics in real-time with auto-refresh

### Data Aggregation

- **Raw Ping Data**: Individual ping results stored for detailed analysis
- **Rollup Tiers**: Automatic aggregation at 1min, 5min, 15min, and 1hour intervals
- **Automatic Selection**: API automatically selects optimal tier based on requested time range
- **Efficient Storage**: Older data automatically rolled up to reduce storage and improve performance

## API Endpoints

The plugin exposes comprehensive REST API endpoints for retrieving metrics programmatically:

### General

- **GET** `/api/plugin/fpp-plugin-watcher/version`

  Returns plugin version information

### Connectivity Metrics

- **GET** `/api/plugin/fpp-plugin-watcher/metrics/ping/raw?hours=24`

  Returns raw ping metrics from individual connectivity checks
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

- **GET** `/api/plugin/fpp-plugin-watcher/metrics/ping/rollup?hours=24` **(NEW)**

  Returns automatically aggregated ping metrics using optimal rollup tier based on time range
  - Efficiently retrieves historical data without performance impact
  - Automatically selects appropriate aggregation level (1min, 5min, 15min, 1hour)

- **GET** `/api/plugin/fpp-plugin-watcher/metrics/ping/rollup/tiers` **(NEW)**

  Returns information about available rollup tiers and their configurations

- **GET** `/api/plugin/fpp-plugin-watcher/metrics/ping/rollup/:tier?hours=24` **(NEW)**

  Returns ping metrics for a specific rollup tier (e.g., `1min`, `5min`, `15min`, `1hour`)

### System Metrics

- **GET** `/api/plugin/fpp-plugin-watcher/metrics/cpu/average?hours=24`

  Returns CPU average usage across all cores

- **GET** `/api/plugin/fpp-plugin-watcher/metrics/memory/free?hours=24`

  Returns free memory metrics in MB/GB

- **GET** `/api/plugin/fpp-plugin-watcher/metrics/disk/free?hours=24`

  Returns free disk space metrics for all mounted volumes

- **GET** `/api/plugin/fpp-plugin-watcher/metrics/load/average?hours=24`

  Returns system load average over time

### Network Metrics

- **GET** `/api/plugin/fpp-plugin-watcher/metrics/interface/bandwidth?interface=eth0&hours=24`

  Returns network interface bandwidth metrics (bytes in/out, packets, errors, drops)

- **GET** `/api/plugin/fpp-plugin-watcher/metrics/interface/list`

  Returns list of available network interfaces with detailed statistics

### Thermal Metrics

- **GET** `/api/plugin/fpp-plugin-watcher/metrics/thermal?hours=24`

  Returns temperature metrics from all available thermal zones

- **GET** `/api/plugin/fpp-plugin-watcher/metrics/thermal/zones`

  Returns list of available thermal zones on the system

### Wireless Metrics

- **GET** `/api/plugin/fpp-plugin-watcher/metrics/wireless?hours=24`

  Returns WiFi metrics including signal strength, quality, and noise levels

- **GET** `/api/plugin/fpp-plugin-watcher/metrics/wireless/interfaces`

  Returns list of available wireless interfaces

**Note**: All endpoints support an optional `hours` parameter to specify the time range for historical data (default: 24 hours).

## File Locations

- **Config**: `/opt/fpp/media/config/plugin.fpp-plugin-watcher`
- **Main Log**: `/home/fpp/media/logs/fpp-plugin-watcher.log`
- **Metrics Log**: `/home/fpp/media/logs/fpp-plugin-watcher-ping-metrics.log`

## Viewing Logs

### Via Command Line

```bash
# Main log
tail -f /home/fpp/media/logs/fpp-plugin-watcher.log

### Metrics log (JSON format)
tail -f /home/fpp/media/logs/fpp-plugin-watcher-ping-metrics.log
```

### Via Dashboards

View connectivity metrics in real-time through the **Watcher - Connectivity** page.
View system metrics in real-time through the **Watcher - Metrics** page.

---

**Last Updated**: November 23, 2025
**Version**: 1.1.0

## Troubleshooting

### Plugin Not Starting

1. Check plugin is enabled in **Plugin Manager**
2. Verify script permissions: `ls -la /home/fpp/media/plugins/fpp-plugin-watcher/scripts/`
3. Check FPP logs: `tail -f /home/fpp/media/logs/fppd.log`
4. Check plugin logs: `tail -f /home/fpp/media/logs/fpp-plugin-watcher.log`

### Connectivity Not Being Monitored

1. Check if service is running: `ps aux | grep connectivityCheck`
2. Verify `connectivityCheckEnabled=true` in config: `cat /opt/fpp/media/config/plugin.fpp-plugin-watcher`
3. Check for errors in log file
4. Verify the correct network interface was detected: Check logs for "Auto-detected network adapter" message
5. Test ping manually with detected interface: `ping -I eth0 -c 1 8.8.8.8` (replace eth0 with your interface)
6. If auto-detection failed, manually select the correct interface in the configuration page

### Network Not Resetting

1. Verify FPP API is accessible: `curl http://127.0.0.1/api/system/status`
2. Check network adapter name matches system: `ip link show`
3. Test API reset manually: `curl -X POST http://127.0.0.1/api/network/interface/eth0/apply`
4. Review logs for API error responses

### Dashboard Not Showing Data

**Connectivity Metrics Dashboard**:
1. Verify metrics file exists: `ls -la /home/fpp/media/logs/fpp-plugin-watcher-ping-metrics.log`
2. Check metrics file has data: `tail /home/fpp/media/logs/fpp-plugin-watcher-ping-metrics.log`
3. Test API endpoints:
   - `curl http://127.0.0.1/api/plugin/fpp-plugin-watcher/metrics/ping/raw`
   - `curl http://127.0.0.1/api/plugin/fpp-plugin-watcher/metrics/ping/rollup`
4. Check browser console for JavaScript errors

**System Metrics Dashboard**:
1. Verify collectd is enabled in plugin configuration
2. Check collectd is running: `systemctl status collectd`
3. Verify RRD files exist: `ls -la /var/lib/collectd/rrd/`
4. Test API endpoints:
   - `curl http://127.0.0.1/api/plugin/fpp-plugin-watcher/metrics/cpu/average`
   - `curl http://127.0.0.1/api/plugin/fpp-plugin-watcher/metrics/memory/free`
5. Check browser console for JavaScript errors

**General**:
- Verify Chart.js CDN is accessible (requires internet connection for dashboards)

### Configuration Not Saving

1. Check file permissions: `ls -la /opt/fpp/media/config/`
2. Verify FPP has write access to config directory
3. Toggle plugin off/on in **Plugin Manager** to reload configuration

## Requirements

- **FPP Version**: 9.0 or higher
- **PHP**: 7.0 or higher (included with FPP)
- **collectd**: Optional, for system metrics dashboard (can be enabled in plugin configuration)
- **Internet Access**: Required for Chart.js CDN (dashboards only, metrics collection works offline)

## Support

For issues, questions, or contributions:
- **GitHub Issues**: https://github.com/agent462/fpp-plugin-watcher/issues
- **Repository**: https://github.com/agent462/fpp-plugin-watcher

## License

MIT License - Free to use and modify

## Version History

### v1.1.0 (Current)

**Network Monitoring**:
- Added smart network interface auto-detection using FPP API
- Added configurable test hosts through web UI
- Added ping metrics tracking with rollup aggregations (1min, 5min, 15min, 1hour tiers)
- Added raw ping data API endpoint for detailed analysis

**System Metrics**:
- Added collectd integration for comprehensive system monitoring
- Added thermal monitoring with multi-zone temperature tracking
- Added wireless metrics (signal strength, quality, noise level)
- Added network interface bandwidth tracking (bytes, packets, errors, drops)
- Added CPU, memory, disk, and load average metrics

**Dashboards**:
- Added System Monitor Dashboard with real-time Chart.js visualizations
- Added Connectivity Metrics Dashboard with historical data

**API Endpoints**:
- 15+ REST API endpoints for programmatic access to all metrics
- Support for customizable time ranges (hours parameter)
- JSON format for easy integration

**Configuration**:
- Added collectd service toggle

### v1.0.0
- Initial release
- Basic connectivity monitoring
- FPP API integration for network reset
- Configurable web interface
- Background service daemon