#!/bin/sh

# Manage collectd service based on plugin configuration
# This runs on every FPP startup to ensure collectd state matches configuration

CONFIG_FILE="/home/fpp/media/config/plugin.fpp-plugin-watcher"

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
    echo "Watcher: Configuration file not found, skipping collectd management"
fi

# Start the Connectivity Checker in the background
/usr/bin/php /home/fpp/media/plugins/fpp-plugin-watcher/connectivityCheck.php &
