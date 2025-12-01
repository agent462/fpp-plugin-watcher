# Watcher Plugin for FPP

A monitoring and control plugin for Falcon Player (FPP) that provides network connectivity monitoring with automatic recovery, system metrics dashboards, multi-sync host monitoring, Falcon controller management, and remote FPP control.

**Requires**: FPP 9.0+

**Hardware Platforms**:
- Raspberry Pi 3, 4, 5
- BeagleBone Black (BBB)
- PocketBeagle 2 (PB2)
- PocketBeagle (PB) - To be tested

**Performance Note**: BBB and PB are single-core devices with limited memory. While this plugin is optimized for low CPU and memory usage, be mindful of overall system performance when running multiple intensive plugins or FPP features. The System Monitor Dashboard makes it easy to track resource usage in real-time.

## Features

### Local Monitoring
- **Connectivity Check**: Automatic network adapter reset after consecutive ping failures
- **System Metrics**: CPU, memory, disk, load, thermal, and wireless metrics via collectd
- **Falcon Controllers**: Monitor and control Falcon hardware controllers on your network

### Multi-Sync Monitoring
- **Remote Metrics**: View historical system metrics from all FPP hosts in your multi-sync setup (Watcher required on remotes)
- **Remote Ping**: Track historical connectivity across all remote hosts
- **Playback Sync**: Monitor playback status across all systems

### Remote Control
- **Remote Control Panel**: Restart FPPD, reboot systems, and manage plugins across all FPP hosts
- **Plugin Updates**: Check and install plugin updates on remote systems
- **FPP Upgrades**: Trigger FPP upgrades on remote hosts with live output streaming

## Installation

1. Go to **Content Setup > Plugin Manager** in FPP
2. Find "Watcher" and click **Install**

## Configuration

Navigate to **Content Setup > Watcher - Config** to enable features:

| Feature | Description |
|---------|-------------|
| Connectivity Check | Monitor network and auto-reset adapter on failures |
| System Metrics (collectd) | Enable local CPU/memory/disk/thermal monitoring |
| Multi-Sync Metrics | Pull system metrics from remote FPP hosts |
| Multi-Sync Ping | Monitor connectivity to remote hosts |
| Falcon Monitor | Monitor Falcon hardware controllers |
| Remote Control | Enable remote system control panel |

## Dashboards

All dashboards appear under **Status** menus when their corresponding feature is enabled:

- **Local Metrics**: Real-time system performance charts
- **Connectivity**: Ping latency and success rate over time
- **Falcon Monitor**: Controller status, temperature, test mode controls
- **Remote Metrics**: System metrics from all multi-sync hosts
- **Remote Ping**: Connectivity graphs for all remote hosts
- **Remote Control**: Control panel for managing all FPP systems

### Remote Control
![Remote Control](https://github.com/agent462/fpp-watcher-images/blob/main/remote-control.png)
![Remote Control](https://github.com/agent462/fpp-watcher-images/blob/main/remote-control-2.png)
![Upgrades](https://github.com/agent462/fpp-watcher-images/blob/main/upgrade-watcher-2.png)

### Metrics
![Local Metrics](https://github.com/agent462/fpp-watcher-images/blob/main/local-metrics.png)
![Remote Metrics](https://github.com/agent462/fpp-watcher-images/blob/main/remote-metrics.png)

### Connectivity
![Ping Host View](https://github.com/agent462/fpp-watcher-images/blob/main/ping-host-view.png)
![Connectivity Metrics](https://github.com/agent462/fpp-watcher-images/blob/main/connectivity-metrics.png)

## Falcon Controllers
![Falcon Controllers](https://github.com/agent462/fpp-watcher-images/blob/main/falcon-controllers.png)

## API

All metrics endpoints support `?hours=N` parameter (default: 24).

```
GET  /api/plugin/fpp-plugin-watcher/version
GET  /api/plugin/fpp-plugin-watcher/metrics/all
GET  /api/plugin/fpp-plugin-watcher/metrics/cpu/average
GET  /api/plugin/fpp-plugin-watcher/metrics/memory/free
GET  /api/plugin/fpp-plugin-watcher/metrics/disk/free
GET  /api/plugin/fpp-plugin-watcher/metrics/ping/rollup
GET  /api/plugin/fpp-plugin-watcher/falcon/status
GET  /api/plugin/fpp-plugin-watcher/remote/status?host=IP
GET  /api/plugin/fpp-plugin-watcher/update/check
```

## Files

| Path | Description |
|------|-------------|
| `/opt/fpp/media/config/plugin.fpp-plugin-watcher` | Configuration |
| `/home/fpp/media/logs/fpp-plugin-watcher.log` | Main log |
| `/home/fpp/media/logs/fpp-plugin-watcher-ping-metrics.log` | Ping metrics |

## Troubleshooting

**Plugin not starting**: Check logs at `/home/fpp/media/logs/fpp-plugin-watcher.log`

**No metrics data**: Ensure collectd is enabled in config and running (`systemctl status collectd`)

**Connectivity monitor not working**: Verify `connectivityCheckEnabled=true` in config and check `ps aux | grep connectivityCheck`

## Support

- **Issues**: https://github.com/agent462/fpp-plugin-watcher/issues
- **Repository**: https://github.com/agent462/fpp-plugin-watcher

## License

MIT License
