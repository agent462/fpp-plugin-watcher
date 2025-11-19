# Watcher Plugin for FPP

Automatically monitors network connectivity and system health, resetting the network adapter when connection failures are detected. Includes dual real-time dashboards for visualizing network connectivity metrics and comprehensive system performance metrics.

## Features

- **Network Connectivity Monitoring**: Continuously checks network connectivity at configurable intervals (default: 20 seconds)
- **Configurable Test Hosts**: Ping any combination of hostnames or IP addresses (default: 8.8.8.8, 1.1.1.1)
- **Automatic Network Reset**: Resets network adapter via FPP API after consecutive failures
- **System Metrics Dashboard**: Real-time CPU, memory, disk, and network interface monitoring via collectd integration
- **Connectivity Metrics Dashboard**: Visualize ping latency, success rates, and per-host statistics with Chart.js graphs
- **Comprehensive Metrics**: Tracks connectivity history and system performance with 24-hour retention
- **REST API**: Access connectivity and system metrics programmatically via plugin API endpoints
- **Configurable Web Interface**: Easy setup through FPP's web UI

## Installation

1. Navigate to **Content Setup → Plugin Manager** in the FPP web interface
2. Search for "Watcher" or install from repository: `https://github.com/agent462/fpp-plugin-watcher`
3. Click **Install**
4. FPP will automatically install dependencies and configure the plugin

## Configuration

### Access Configuration Page

Navigate to **Content Setup → Watcher - Config** in the FPP web interface.

### Settings

- **Enable Watcher Service**: Toggle to enable/disable the connectivity monitor
- **Check Interval**: How often to test connectivity (5-3600 seconds, default: 20)
- **Max Failures Before Reset**: Number of consecutive failures before resetting adapter (1-100, default: 3)
- **Network Adapter**: Select your network interface (eth0, wlan0, etc.)
- **Test Hosts/IPs**: Add one or more hosts to ping for connectivity checks
  - Examples: `8.8.8.8`, `1.1.1.1`, `google.com`, `your-gateway-ip`
  - The monitor tests each host in order until one succeeds
  - Recommended: 2-3 hosts maximum to avoid excessive network traffic

### Saving Configuration

1. Click **Save Settings**
2. FPP will restart to apply changes
3. Enable the plugin in **Plugin Manager** if not already enabled

## Dashboards

### System Monitor Dashboard
Access at **Content Setup → Watcher - Display**

Displays real-time system performance metrics:
- **CPU Usage**: Current and historical CPU utilization across all cores
- **Memory Statistics**: Free, cached, and buffered memory usage
- **Disk Space**: Free disk space on mounted volumes
- **Load Average**: System load over time
- **Network Bandwidth**: Interface bandwidth utilization and packet rates

Charts auto-refresh every 30 seconds with historical data spanning 24 hours.

### Connectivity Metrics Dashboard
Access at **Content Setup → Watcher - Metrics**

Displays network connectivity details:
- **Connectivity Statistics**: Success rate, average latency, total checks
- **Ping Latency Graph**: Real-time chart showing latency over the last 24 hours
- **Success/Failure Distribution**: Visual breakdown of connectivity status
- **Per-Host Metrics**: Individual statistics for each test host

Charts auto-refresh every 30 seconds to show the latest data.

## How It Works

1. **Background Service**: The `connectivityCheck.php` daemon runs continuously when the plugin is enabled
2. **Connectivity Checks**: Every `checkInterval` seconds, the service pings each test host in order
3. **Success**: If any host responds, the failure counter resets and latency is logged
4. **Failure**: If all hosts fail, the failure counter increments
5. **Network Reset**: When failures reach `maxFailures`, the FPP API resets the network adapter
6. **Metrics Logging**: Each ping result is logged as JSON to the metrics file for dashboard visualization
7. **Continuous Monitoring**: The service continues monitoring after reset (does not stop)

## API Endpoints

The plugin exposes REST API endpoints for retrieving metrics:

### Connectivity Metrics

- **GET** `/api/plugin/fpp-plugin-watcher/version`
  Returns plugin version information

- **GET** `/api/plugin/fpp-plugin-watcher/metrics`
  Returns ping metrics from the last 24 hours
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

### System Metrics

- **GET** `/api/plugin/fpp-plugin-watcher/metrics/cpu/average?hours=24`
  Returns CPU average usage (supports optional `hours` parameter, default: 24)

- **GET** `/api/plugin/fpp-plugin-watcher/metrics/memory/free?hours=24`
  Returns free memory metrics (supports optional `hours` parameter, default: 24)

- **GET** `/api/plugin/fpp-plugin-watcher/metrics/disk/free?hours=24`
  Returns free disk space metrics (supports optional `hours` parameter, default: 24)

- **GET** `/api/plugin/fpp-plugin-watcher/metrics/load/average?hours=24`
  Returns system load average (supports optional `hours` parameter, default: 24)

- **GET** `/api/plugin/fpp-plugin-watcher/metrics/interface/bandwidth?hours=24`
  Returns network interface bandwidth metrics (supports optional `hours` parameter, default: 24)

- **GET** `/api/plugin/fpp-plugin-watcher/metrics/interface/list`
  Returns list of available network interfaces with detailed statistics

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

View connectivity metrics in real-time through the **Watcher - Metrics** page.
View system metrics in real-time through the **Watcher - Display** page.

---

**Last Updated**: November 17, 2025
**Version**: 1.1.0

## Troubleshooting

### Plugin Not Starting

1. Check plugin is enabled in **Plugin Manager**
2. Verify script permissions: `ls -la /home/fpp/media/plugins/fpp-plugin-watcher/scripts/`
3. Check FPP logs: `tail -f /home/fpp/media/logs/fppd.log`
4. Check plugin logs: `tail -f /home/fpp/media/logs/fpp-plugin-watcher.log`

### Connectivity Not Being Monitored

1. Check if service is running: `ps aux | grep connectivityCheck`
2. Verify `enabled=true` in config: `cat /opt/fpp/media/config/plugin.fpp-plugin-watcher`
3. Check for errors in log file
4. Test ping manually: `ping -I eth0 -c 1 8.8.8.8`

### Network Not Resetting

1. Verify FPP API is accessible: `curl http://127.0.0.1/api/system/status`
2. Check network adapter name matches system: `ip link show`
3. Test API reset manually: `curl -X POST http://127.0.0.1/api/network/interface/eth0/apply`
4. Review logs for API error responses

### Dashboard Not Showing Data

1. Verify metrics file exists: `ls -la /home/fpp/media/logs/fpp-plugin-watcher-ping-metrics.log`
2. Check metrics file has data: `tail /home/fpp/media/logs/fpp-plugin-watcher-ping-metrics.log`
3. Test API endpoint: `curl http://127.0.0.1/api/plugin/fpp-plugin-watcher/metrics`
4. Check browser console for JavaScript errors
5. Verify Chart.js CDN is accessible (requires internet connection)

### Configuration Not Saving

1. Check file permissions: `ls -la /opt/fpp/media/config/`
2. Verify FPP has write access to config directory
3. Toggle plugin off/on in **Plugin Manager** to reload configuration

## Requirements

- **FPP Version**: 9.0 or higher
- **PHP**: 7.0 or higher (included with FPP)
- **Internet Access**: Required for Chart.js CDN (dashboard only)

## Support

For issues, questions, or contributions:
- **GitHub Issues**: https://github.com/agent462/fpp-plugin-watcher/issues
- **Repository**: https://github.com/agent462/fpp-plugin-watcher

## License

MIT License - Free to use and modify

## Version History

### v1.1.0 (Current)
- Added real-time dashboard with Chart.js visualizations
- Added ping metrics tracking and API endpoints
- Added configurable test hosts through web UI
- Improved configuration interface
- Added About page

### v1.0.0
- Initial release
- Basic connectivity monitoring
- FPP API integration for network reset
- Configurable web interface
- Background service daemon