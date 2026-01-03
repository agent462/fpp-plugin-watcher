# Watcher Plugin for FPP

A comprehensive monitoring and control plugin for Falcon Player (FPP) that provides network connectivity monitoring with automatic recovery, system metrics dashboards, multi-sync host monitoring, Falcon controller management, eFuse current monitoring with heatmap visualization, and remote FPP control.

**Requires**: FPP 9.0+

**Hardware Platforms**:
- Raspberry Pi 3, 4, 5
- BeagleBone Black (BBB)
- PocketBeagle 2 (PB2)
- PocketBeagle (PB)

**eFuse Hardware Support**: Kulp Lights, f16v5, SRv5 (auto-detected)

**Performance Note**: BBB and PB are single-core devices with limited memory. While this plugin is optimized for low CPU and memory usage, be mindful of overall system performance when running multiple intensive plugins or FPP features. The System Monitor Dashboard makes it easy to track resource usage in real-time.

## Operating Modes

Watcher adapts its features based on your FPP's operating mode:

| Feature | Player Mode | Remote Mode |
|---------|:-----------:|:-----------:|
| Connectivity Check | ✓ | ✓ |
| System Metrics (collectd) | ✓ | ✓ |
| Falcon Controller Monitor | ✓ | ✓ |
| eFuse Monitor | ✓ | ✓ |
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
- **Falcon Controllers**: Monitor and control Falcon hardware on your network (F4V2, F16V2, F4V3, F16V3, F48)
- **Sync Metrics**: View sync timing, jitter, and packet statistics (player shows all remotes, remotes show their own sync status)
- **eFuse Monitor**: Real-time current monitoring for eFuse-equipped capes with historical data and heatmap visualization, efuse control

### Player Mode Only

- **Remote Metrics**: Aggregate system metrics from all remote FPP systems (requires Watcher + collectd on remotes)
- **Remote Ping**: Track latency and availability to all remote multi-sync hosts
- **Remote Control**: Restart FPPD, reboot systems, trigger FPP upgrades across all remotes
- **MQTT Events**: Capture and view sequence, playlist, and status events

## Installation

1. Go to **Content Setup > Plugin Manager** in FPP
2. Install via the plugin JSON URL: `https://raw.githubusercontent.com/agent462/fpp-plugin-watcher/main/pluginInfo.json`

## Configuration

Navigate to **Content Setup > Watcher - Config** to enable features. Each feature adds a corresponding dashboard under the **Status** menu when enabled.

### Configuration Options

| Setting | Default | Description |
|---------|---------|-------------|
| **Connectivity Check** | Off | Monitor network and auto-reset adapter on failures |
| Check Interval | 20s | Time between ping tests |
| Max Failures | 3 | Consecutive failures before adapter reset |
| Test Hosts | 8.8.8.8, 1.1.1.1 | Comma-separated IPs to ping |
| **System Metrics** | On | Enable collectd for local metrics |
| **Multi-Sync Metrics** | Off | Aggregate metrics from remote systems |
| **Multi-Sync Ping** | Off | Track ping to remote hosts |
| Ping Interval | 60s | Time between multi-sync pings |
| **Falcon Monitor** | Off | Discover and monitor Falcon controllers |
| **eFuse Monitor** | Off | Monitor eFuse current readings |
| Collection Interval | 5s | eFuse reading frequency (1-60s) |
| Retention | 7 days | eFuse data retention (1-90 days) |
| **Remote Control** | On | Enable remote system control panel |
| **MQTT Events** | Off | Capture FPP events via MQTT |
| Event Retention | 60 days | MQTT event retention (1-365 days) |

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

### Efuses
![Efuse View](https://github.com/agent462/fpp-watcher-images/blob/main/efuse-1.png)
![Efuse Metrics](https://github.com/agent462/fpp-watcher-images/blob/main/efuse-2.png)

### Falcon Controllers
![Falcon Controllers](https://github.com/agent462/fpp-watcher-images/blob/main/falcon-controllers.png)

### MQTT
![MQTT](https://github.com/agent462/fpp-watcher-images/blob/main/mqtt-events.png)

## Architecture

### Background Services

Watcher runs several background daemons managed by FPP:

- **connectivityCheck.php**: Network monitoring with automatic adapter reset
- **efuseCollector.php**: eFuse current readings collection (when hardware detected)
- **mqttSubscriber.php**: MQTT event capture (when enabled)
- **libfpp-plugin-watcher.so**: C++ shared library for MultiSync status integration

### Data Storage

Metrics are stored in `/home/fpp/media/plugindata/fpp-plugin-watcher/`:
- Ping metrics with automatic rollup (1min, 5min, 30min, 2hour tiers)
- Multi-sync ping metrics per remote host
- eFuse current readings and heatmap data
- MQTT events log

### Codebase Structure

```
classes/           # PSR-4 namespaced PHP classes
  Watcher/
    Core/          # Logger, FileManager, Settings
    Http/          # ApiClient, CurlMultiHandler
    Metrics/       # Collectors and rollup processors
    Controllers/   # Falcon, eFuse, RemoteControl
    MultiSync/     # SyncStatus, Comparator, ClockDrift
    UI/            # ViewHelpers
ui/                # Dashboard PHP pages
js/src/            # ES6 JavaScript modules (bundled via esbuild)
src/               # C++ source for MultiSync integration
tests/             # PHPUnit and Jest test suites
```

### API Endpoints

The plugin exposes a REST API at `/api/plugin/fpp-plugin-watcher/`:

| Endpoint | Description |
|----------|-------------|
| `GET /version` | Plugin version info |
| `GET /metrics/all` | Local system metrics |
| `GET /remotes` | List of remote multi-sync systems |
| `GET /ping/metrics?hours=N` | Connectivity ping metrics |
| `GET /efuse/supported` | eFuse hardware detection |
| `GET /efuse/current` | Current eFuse readings |
| `GET /efuse/heatmap?hours=N` | eFuse heatmap data |
| `GET /efuse/history?port=X&hours=N` | Per-port history |
| `POST /efuse/port/toggle` | Toggle port on/off |
| `POST /efuse/port/reset` | Reset tripped port |
| `GET /falcon/status` | Falcon controller status |
| `GET /multisync/status` | Multi-sync timing data |
| `POST /remote/restart` | Restart remote FPPD |
| `POST /remote/reboot` | Reboot remote system |

## Troubleshooting

**Log Files**:
- `/home/fpp/media/logs/fpp-plugin-watcher.log` - Main plugin log
- `/home/fpp/media/logs/fpp-plugin-watcher-ping-metrics.log` - Ping metrics log
- `/home/fpp/media/logs/fpp-plugin-watcher-efuse.log` - eFuse collector log

**Common Issues**:

| Issue | Solution |
|-------|----------|
| No metrics displayed | Verify collectd is enabled in config and wait 1-2 minutes for data |
| Remote systems not showing | Ensure Watcher is installed on remotes with collectd enabled |
| eFuse not available | eFuse monitoring requires supported hardware (detected automatically) |
| MQTT events empty | Enable MQTT in FPP settings and configure broker connection |

## Contributing

```bash
# Run PHP tests
./phpunit                          # All tests with coverage
./phpunit --testsuite Unit         # Unit tests only (fast)
./phpunit --testsuite Integration  # Integration tests (requires FPP)

# Run JavaScript tests
npm test                           # All JS tests
npm run test:coverage              # With coverage report

# Build JavaScript bundle (required after JS changes)
npm run build

# Check PHP syntax
find . -name "*.php" -exec php -l {} \;
```

## Support

- **Issues**: https://github.com/agent462/fpp-plugin-watcher/issues
- **Repository**: https://github.com/agent462/fpp-plugin-watcher

## License

MIT License
