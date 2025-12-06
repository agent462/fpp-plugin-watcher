#!/bin/sh

# Manage collectd service based on plugin configuration
# This runs on every FPP startup to ensure collectd state matches configuration

PLUGIN_DIR="/home/fpp/media/plugins/fpp-plugin-watcher"
CONFIG_FILE="/home/fpp/media/config/plugin.fpp-plugin-watcher"
DATA_DIR="/home/fpp/media/plugin-data/fpp-plugin-watcher"

# Create data directories for metrics storage
echo "Watcher: Ensuring data directories exist..."
mkdir -p "$DATA_DIR/ping" "$DATA_DIR/multisync-ping" "$DATA_DIR/network-quality" "$DATA_DIR/mqtt" "$DATA_DIR/connectivity"
chown -R fpp:fpp "$DATA_DIR"

# Run one-time data migration (script checks marker file internally)
if [ -f "$PLUGIN_DIR/scripts/migrateData.php" ]; then
    /usr/bin/php "$PLUGIN_DIR/scripts/migrateData.php"
fi

if [ -f "$CONFIG_FILE" ]; then
    # Read collectdEnabled setting from config file (trim whitespace and quotes)
    # Match both "collectdEnabled=value" and "collectdEnabled = value" formats
    # FPP wraps values in quotes, so we need to remove them
    COLLECTD_ENABLED=$(grep -E "^collectdEnabled" "$CONFIG_FILE" | cut -d'=' -f2 | tr -d ' \t\r\n"')

    # Check if collectd should be enabled (true, 1, or yes)
    if [ "$COLLECTD_ENABLED" = "true" ] || [ "$COLLECTD_ENABLED" = "1" ] || [ "$COLLECTD_ENABLED" = "yes" ]; then
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
