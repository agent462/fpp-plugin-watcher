# Watcher Plugin for FPP

A monitoring and control plugin for Falcon Player (FPP) that provides network connectivity monitoring, system metrics dashboards, multi-sync host monitoring, Falcon controller management, and remote FPP control.

**Requires**: FPP 9.0+

**Hardware Platforms**:
- Raspberry Pi 3, 4, 5
- BeagleBone Black (BBB)
- PocketBeagle 2 (PB2)
- PocketBeagle (PB) - To be tested

**Performance Note**: BBB and PB are single-core devices with limited memory. While this plugin is optimized for low CPU and memory usage, be mindful of overall system performance when running multiple intensive plugins or FPP features. The System Monitor Dashboard makes it easy to track resource usage in real-time.

## Operating Modes

Watcher adapts its features based on your FPP's operating mode:

| Feature | Player Mode | Remote Mode |
|---------|:-----------:|:-----------:|
| Connectivity Check | ✓ | ✓ |
| System Metrics (collectd) | ✓ | ✓ |
| Falcon Controller Monitor | ✓ | ✓ |
| Sync Metrics Dashboard | ✓ | ✓ |
| Remote Metrics | ✓ | - |
| Remote Ping | ✓ | - |
| Remote Control | ✓ | - |
| MQTT Events | ✓ | - |

**Player Mode**: The primary FPP instance that controls playback and coordinates multi-sync. Has access to all features including the ability to monitor and control remote systems.

**Remote Mode**: FPP instances that receive sync commands from a player. Install Watcher on remotes to enable System Metrics collection, which the player can then aggregate. The Sync Metrics dashboard shows sync timing information when receiving multi-sync packets.

## Features

### All Modes
- **Connectivity Check**: Monitors network and automatically resets adapter after consecutive ping failures
- **System Metrics**: CPU, memory, disk, thermal, and wireless monitoring via collectd
- **Falcon Controllers**: Monitor and control Falcon hardware on your network
- **Sync Metrics**: View sync timing, jitter, and packet statistics (player shows all remotes, remotes show their own sync status)

### Player Mode Only
- **Remote Metrics**: Aggregate system metrics from all remote FPP systems (requires Watcher + collectd on remotes)
- **Remote Ping**: Track latency and availability to all remote multi-sync hosts
- **Remote Control**: Restart FPPD, reboot systems, trigger FPP upgrades across all remotes
- **MQTT Events**: Capture and view sequence, playlist, and status events

## Installation

1. Go to **Content Setup > Plugin Manager** in FPP
2. Watcher can only be installed by providing the json url currently until we publish a final version: (https://github.com/agent462/fpp-plugin-watcher/blob/main/pluginInfo.json)

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
