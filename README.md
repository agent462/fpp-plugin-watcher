# Watcher Plugin for FPP

A monitoring and control plugin for Falcon Player (FPP) that provides network connectivity monitoring, system metrics dashboards, multi-sync host monitoring, Falcon controller management, and remote FPP control.

**Requires**: FPP 9.0+

**Hardware Platforms**:
- Raspberry Pi 3, 4, 5
- BeagleBone Black (BBB)
- PocketBeagle 2 (PB2)
- PocketBeagle (PB) - To be tested

**Performance Note**: BBB and PB are single-core devices with limited memory. While this plugin is optimized for low CPU and memory usage, be mindful of overall system performance when running multiple intensive plugins or FPP features. The System Monitor Dashboard makes it easy to track resource usage in real-time.

## Features

### Local Monitoring
- **Connectivity Check**: Monitors network and automatically resets adapter after consecutive ping failures
- **System Metrics**: CPU, memory, disk, thermal, and wireless monitoring
- **Falcon Controllers**: Monitor and control Falcon hardware on your network

### Multi-Sync Monitoring
- **Remote Metrics**: View system metrics from all FPP hosts in your multi-sync setup
- **Remote Ping**: Track connectivity across all remote hosts
- **Sync Status**: Monitor playback sync status across all systems

### Remote Control
- **Control Panel**: Restart FPPD, reboot systems, and manage plugins across all FPP hosts
- **FPP Upgrades**: Trigger FPP upgrades on remote hosts

### Events
- **MQTT Events**: View MQTT events published by FPP

## Installation

1. Go to **Content Setup > Plugin Manager** in FPP
2. Find "Watcher" and click **Install**

## Configuration

Navigate to **Content Setup > Watcher - Config** to enable features. Each feature adds a corresponding dashboard under the **Status** menu when enabled.

## Screenshots

### Multi-Sync
![Multisync](https://github.com/agent462/fpp-watcher-images/blob/main/multisync-1.png)
![Multisync](https://github.com/agent462/fpp-watcher-images/blob/main/multisync-2.png)

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

### Falcon Controllers
![Falcon Controllers](https://github.com/agent462/fpp-watcher-images/blob/main/falcon-controllers.png)

### MQTT
![MQTT](https://github.com/agent462/fpp-watcher-images/blob/main/mqtt-events.png)

## Troubleshooting

Check logs at `/home/fpp/media/logs/fpp-plugin-watcher.log` for any issues.

## Support

- **Issues**: https://github.com/agent462/fpp-plugin-watcher/issues
- **Repository**: https://github.com/agent462/fpp-plugin-watcher

## License

MIT License
