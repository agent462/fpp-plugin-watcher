#!/bin/sh

# Manage collectd service based on plugin configuration
# This runs on every FPP startup to ensure collectd state matches configuration

PLUGIN_DIR="/home/fpp/media/plugins/fpp-plugin-watcher"
CONFIG_FILE="/home/fpp/media/config/plugin.fpp-plugin-watcher"
DATA_DIR="/home/fpp/media/plugindata/fpp-plugin-watcher"

# Create data directories for metrics storage
echo "Watcher: Ensuring data directories exist..."
mkdir -p "$DATA_DIR/ping" "$DATA_DIR/multisync-ping" "$DATA_DIR/network-quality" "$DATA_DIR/mqtt" "$DATA_DIR/connectivity" "$DATA_DIR/efuse" "$DATA_DIR/voltage"
chown -R fpp:fpp "$DATA_DIR"

if [ -f "$CONFIG_FILE" ]; then
    # Read collectdEnabled setting from config file (trim whitespace and quotes)
    # Match both "collectdEnabled=value" and "collectdEnabled = value" formats
    # FPP wraps values in quotes, so we need to remove them
    COLLECTD_ENABLED=$(grep -E "^collectdEnabled" "$CONFIG_FILE" | cut -d'=' -f2 | tr -d ' \t\r\n"')

    # Check if collectd should be enabled (true, 1, or yes)
    if [ "$COLLECTD_ENABLED" = "true" ] || [ "$COLLECTD_ENABLED" = "1" ] || [ "$COLLECTD_ENABLED" = "yes" ]; then
        # Sanity check: Verify required packages exist (may be wiped by OS upgrades)
        # fpp_install.sh only runs on plugin updates, so we need to detect this here
        PACKAGES_MISSING=0
        for pkg in collectd-core rrdtool; do
            if ! dpkg-query -W -f='${Status}' "$pkg" 2>/dev/null | grep -q "^install ok"; then
                echo "Watcher: Required package '$pkg' is missing (possibly removed by OS upgrade)"
                PACKAGES_MISSING=1
            fi
        done

        if [ "$PACKAGES_MISSING" -eq 1 ]; then
            echo "Watcher: Re-running install script to restore missing packages..."
            if [ -f "$PLUGIN_DIR/scripts/fpp_install.sh" ]; then
                sudo "$PLUGIN_DIR/scripts/fpp_install.sh"
                if [ $? -eq 0 ]; then
                    echo "Watcher: Install script completed successfully"
                else
                    echo "Watcher: WARNING - Install script failed, collectd may not work"
                fi
            else
                echo "Watcher: WARNING - Install script not found"
            fi
        fi

        # collectd should be enabled - check if already running
        if systemctl is-active --quiet collectd.service; then
            echo "Watcher: collectd service is already running"
        else
            echo "Watcher: collectd is enabled in configuration, starting service..."
            sudo systemctl --now enable collectd.service
            if [ $? -eq 0 ]; then
                echo "Watcher: collectd service enabled and started successfully"
            else
                echo "Watcher: WARNING - Failed to enable collectd service"
            fi
        fi
    else
        # collectd should be disabled - check if currently running
        if systemctl is-active --quiet collectd.service; then
            echo "Watcher: collectd is disabled in configuration, stopping service..."
            sudo systemctl --now disable collectd.service
            if [ $? -eq 0 ]; then
                echo "Watcher: collectd service disabled and stopped successfully"
            else
                echo "Watcher: WARNING - Failed to disable collectd service"
            fi
        else
            echo "Watcher: collectd is disabled in configuration (already stopped)"
        fi
    fi
else
    echo "Watcher: Configuration file not found, skipping startup"
    exit 0
fi

# Start Connectivity Checker if enabled
CONNECTIVITY_ENABLED=$(grep -E "^connectivityCheckEnabled" "$CONFIG_FILE" | cut -d'=' -f2 | tr -d ' \t\r\n"')
if [ "$CONNECTIVITY_ENABLED" = "true" ] || [ "$CONNECTIVITY_ENABLED" = "1" ] || [ "$CONNECTIVITY_ENABLED" = "yes" ]; then
    echo "Watcher: Connectivity Checker is enabled, starting daemon..."
    /usr/bin/php /home/fpp/media/plugins/fpp-plugin-watcher/connectivityCheck.php &
else
    echo "Watcher: Connectivity Checker is disabled"
fi

# Start MQTT Subscriber if enabled
MQTT_ENABLED=$(grep -E "^mqttMonitorEnabled" "$CONFIG_FILE" | cut -d'=' -f2 | tr -d ' \t\r\n"')
if [ "$MQTT_ENABLED" = "true" ] || [ "$MQTT_ENABLED" = "1" ] || [ "$MQTT_ENABLED" = "yes" ]; then
    echo "Watcher: MQTT Monitor is enabled, starting subscriber..."
    /usr/bin/php /home/fpp/media/plugins/fpp-plugin-watcher/mqttSubscriber.php &
else
    echo "Watcher: MQTT Monitor is disabled"
fi

# Start eFuse Collector if enabled (collector checks hardware on startup and exits if unsupported)
EFUSE_ENABLED=$(grep -E "^efuseMonitorEnabled" "$CONFIG_FILE" | cut -d'=' -f2 | tr -d ' \t\r\n"')
if [ "$EFUSE_ENABLED" = "true" ] || [ "$EFUSE_ENABLED" = "1" ] || [ "$EFUSE_ENABLED" = "yes" ]; then
    echo "Watcher: eFuse Monitor is enabled, starting collector..."
    /usr/bin/php /home/fpp/media/plugins/fpp-plugin-watcher/efuseCollector.php &
else
    echo "Watcher: eFuse Monitor is disabled"
fi

# Start Voltage Collector if enabled (collector checks hardware on startup and exits if unsupported)
VOLTAGE_ENABLED=$(grep -E "^voltageMonitorEnabled" "$CONFIG_FILE" | cut -d'=' -f2 | tr -d ' \t\r\n"')
if [ "$VOLTAGE_ENABLED" = "true" ] || [ "$VOLTAGE_ENABLED" = "1" ] || [ "$VOLTAGE_ENABLED" = "yes" ]; then
    echo "Watcher: Voltage Monitor is enabled, starting collector..."
    /usr/bin/php /home/fpp/media/plugins/fpp-plugin-watcher/voltageCollector.php &
else
    echo "Watcher: Voltage Monitor is disabled"
fi
