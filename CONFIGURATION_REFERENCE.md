# Watcher Configuration Reference

## Configuration File Location

```bash
/opt/fpp/media/config/plugin.fpp-plugin-watcher
```

## File Format

INI-style configuration with URL-encoded values

## Configuration Parameters

### 1. enabled (boolean)

**Description**: Enable or disable the Watcher service
**Values**: `0` (disabled) or `1` (enabled)
**Default**: `0`
**Example**: `enabled=1`

### 2. checkInterval (integer)

**Description**: How often to check network connectivity in seconds
**Values**: `5` to `3600` (5 seconds to 1 hour)
**Default**: `20`
**Recommended**: `20-60` for most use cases
**Example**: `checkInterval=20`

### 3. maxFailures (integer)

**Description**: Number of consecutive failures before attempting network adapter reset
**Values**: `1` to `100`
**Default**: `3`
**Recommended**: `2-5`
**Example**: `maxFailures=3`

### 4. networkAdapter (string)

**Description**: The network interface to monitor and reset
**Common Values**:

- `eth0` - Ethernet (most common)
- `eth1` - Secondary Ethernet
- `wlan0` - WiFi
- `wlan1` - Secondary WiFi
- `enp0s3` - Virtual/Bridge Ethernet
- `enp4s0u1u1` - USB Ethernet adapter

**Default**: `eth0`
**Example**: `networkAdapter=eth0`

To find your adapter, run: `ip link show` or `ifconfig`

### 5. testHosts (string - URL encoded)

**Description**: Comma-separated list of hosts/IPs to check for connectivity
**Format**: Comma-separated, URL-encoded values
**Default**: `8.8.8.8,1.1.1.1`
**Recommended Options**:

- Public DNS: `8.8.8.8`, `1.1.1.1`
- Router Gateway: `192.168.1.1`
- ISP DNS: Check your ISP documentation
- Custom: Any externally reachable host

**Example**: `testHosts=8.8.8.8,1.1.1.1,192.168.1.1`

### 6. metricsRotationInterval (integer)

**Description**: Interval in seconds for rotating/pruning old metrics to maintain file size
**Values**: `300` to `86400` (5 minutes to 24 hours)
**Default**: `1800` (30 minutes)
**Recommended**: `1800-3600` for most use cases
**Example**: `metricsRotationInterval=1800`

## Sample Configuration Files

### Basic Setup

```ini
enabled=1
checkInterval=20
maxFailures=3
networkAdapter=eth0
testHosts=8.8.8.8,1.1.1.1
metricsRotationInterval=1800
```

### Aggressive Monitoring (Fast Detection)

```ini
enabled=1
checkInterval=10
maxFailures=2
networkAdapter=eth0
testHosts=gateway.local,8.8.8.8
metricsRotationInterval=1800
```

### Conservative Monitoring (Less Disruptive)

```ini
enabled=1
checkInterval=60
maxFailures=5
networkAdapter=eth0
testHosts=8.8.8.8,1.1.1.1
metricsRotationInterval=1800
```

### WiFi Monitoring

```ini
enabled=1
checkInterval=30
maxFailures=3
networkAdapter=wlan0
testHosts=8.8.8.8,1.1.1.1
metricsRotationInterval=1800
```

### Multi-Host Monitoring

```ini
enabled=1
checkInterval=25
maxFailures=3
networkAdapter=eth0
testHosts=gateway.local,8.8.8.8,1.1.1.1,dns.example.com
metricsRotationInterval=1800
```

## Configuration Best Practices

### Test Host Selection

1. **Always include at least one**: Use either gateway or public DNS
2. **Prefer multiple hosts**: Reduces false positives
3. **Use reliable targets**:
   - Gateway (local network) - fast, always available
   - Google DNS (8.8.8.8) - very reliable
   - Cloudflare DNS (1.1.1.1) - very reliable
4. **Avoid unreliable hosts**: Corporate proxies, filtered networks

### Check Interval Selection

- **5-10 seconds**: Very aggressive, resource intensive, for critical systems
- **10-30 seconds**: Balanced, recommended for most cases
- **30-60 seconds**: Conservative, lower overhead, slower detection
- **60+ seconds**: Very conservative, minimal overhead

### Max Failures Selection

- **1-2 failures**: Aggressive reset, may cause false positives
- **3-5 failures**: Balanced, recommended for most cases
- **5+ failures**: Conservative, requires sustained failure

### Network Adapter Selection

Check available adapters:

```bash
ip link show
# or
ifconfig -a
# or
nmcli device show
```

## Editing the Configuration

### Using FPP UI (Recommended)

1. Navigate to: Content Setup > Watcher - Config
2. Adjust settings in the UI
3. Click "Save Settings"
4. Configuration saved automatically

### Using FPP Settings API

Settings are stored as individual config file entries:

```bash
# Set a single setting
curl -X POST http://localhost/api/settings/fpp-plugin-watcher/enabled -d "1"
curl -X POST http://localhost/api/settings/fpp-plugin-watcher/checkInterval -d "20"
curl -X POST http://localhost/api/settings/fpp-plugin-watcher/testHosts -d "8.8.8.8,1.1.1.1"
```

### Network Adapter Naming Conventions

**Linux Network Naming**:

- **eth0, eth1, ...**: Ethernet interfaces (legacy)
- **enp0s3, enp4s0, ...**: PCI enumerated (modern)
- **wlan0, wlan1, ...**: WiFi interfaces (legacy)
- **wlp2s0, wlp3s0, ...**: WiFi PCI enumerated (modern)
- **docker0, veth***: Virtual/Container interfaces
- **tun0, tap0**: VPN/Tunnel interfaces

**Finding Your Adapter**:

```bash
# Show all interfaces
ip link show

# Show only up interfaces
ip link show | grep "state UP"

# Show detailed info
ip addr show

# Show network configuration
nmcli device show
```

### Test Host Recommendations

**By Network Type**:

- **Home Network**: Gateway + Public DNS (8.8.8.8)
- **Corporate Network**: Internal gateway + DNS
- **Public Network**: Multiple public DNS servers
- **Isolated Network**: Internal proxy or gateway

**Best Practices**:

```settings
Primary (Gateway):    192.168.1.1 or similar
Secondary (DNS1):     8.8.8.8
Tertiary (DNS2):      1.1.1.1
Quaternary (Public):  Custom service IP
```

## Troubleshooting

### Configuration Not Applied

1. Check file syntax: `cat /opt/fpp/media/config/plugin.fpp-plugin-watcher`
2. Verify permissions: `ls -la /opt/fpp/media/config/`
3. Restart plugin from FPP UI
4. Check logs: `tail -f /home/fpp/media/logs/fpp-plugin-watcher.log`

### Settings Reset to Defaults

- Check if config file was deleted
- Verify write permissions on config directory
- Re-save settings from UI

### Adapter Not Found

1. Run: `ip link show` to verify adapter exists
2. Check adapter spelling (case-sensitive)
3. Verify adapter name matches your device

### Test Hosts Not Reachable

1. Test manually: `ping 8.8.8.8`
2. Check firewall rules
3. Verify network connectivity
4. Try different test hosts

## Configuration Migration

### Backing Up Configuration

```bash
cp /opt/fpp/media/config/plugin.fpp-plugin-watcher /opt/fpp/media/config/plugin.fpp-plugin-watcher.backup
```

### Restoring Configuration

```bash
cp /opt/fpp/media/config/plugin.fpp-plugin-watcher.backup /opt/fpp/media/config/plugin.fpp-plugin-watcher
```

---

**Last Updated**: November 17, 2025
**Version**: 1.1.0
