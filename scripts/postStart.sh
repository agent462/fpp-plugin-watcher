#!/bin/sh

# Check if collectd should be enabled based on plugin configuration
CONFIG_FILE="/opt/fpp/media/config/plugin.fpp-plugin-watcher" # need to make this dynamic later

if [ -f "$CONFIG_FILE" ]; then
    # Read collectdEnabled setting from config file
    COLLECTD_ENABLED=$(grep -E "^collectdEnabled=" "$CONFIG_FILE" | cut -d'=' -f2)

    # Check if collectd should be enabled (true, 1, or yes)
    if [ "$COLLECTD_ENABLED" = "true" ] || [ "$COLLECTD_ENABLED" = "1" ] || [ "$COLLECTD_ENABLED" = "yes" ]; then
        echo "Watcher: collectd is enabled in configuration, ensuring service is running..."
        sudo systemctl --now enable collectd.service
        if [ $? -eq 0 ]; then
            echo "Watcher: collectd service enabled and started successfully"
        else
            echo "Watcher: WARNING - Failed to enable collectd service"
        fi
    else
        echo "Watcher: collectd is disabled in configuration"
    fi
else
    echo "Watcher: Configuration file not found, skipping collectd management"
fi

# Start the Connectivity Checker in the background
/usr/bin/php /home/fpp/media/plugins/fpp-plugin-watcher/connectivityCheck.php &
