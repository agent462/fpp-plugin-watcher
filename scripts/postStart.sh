#!/bin/sh

# Check if collectd is already running - if so, skip configuration check
if systemctl is-active --quiet collectd.service; then
    echo "Watcher: collectd service is already running, skipping configuration check"
else
    echo "Watcher: collectd service is not running, checking configuration..."

    # Check if collectd should be enabled based on plugin configuration
    CONFIG_FILE="/home/fpp/media/config/plugin.fpp-plugin-watcher"

    if [ -f "$CONFIG_FILE" ]; then
        # Read collectdEnabled setting from config file (trim whitespace and quotes)
        # Match both "collectdEnabled=value" and "collectdEnabled = value" formats
        # FPP wraps values in quotes, so we need to remove them
        COLLECTD_ENABLED=$(grep -E "^collectdEnabled" "$CONFIG_FILE" | cut -d'=' -f2 | tr -d ' \t\r\n"')

        # Check if collectd should be enabled (true, 1, or yes)
        if [ "$COLLECTD_ENABLED" = "true" ] || [ "$COLLECTD_ENABLED" = "1" ] || [ "$COLLECTD_ENABLED" = "yes" ]; then
            echo "Watcher: collectd is enabled in configuration, starting service..."
            sudo systemctl --now enable collectd.service
            if [ $? -eq 0 ]; then
                echo "Watcher: collectd service enabled and started successfully"
            else
                echo "Watcher: WARNING - Failed to enable collectd service"
            fi
        else
            echo "Watcher: collectd is disabled in configuration (value: '$COLLECTD_ENABLED')"
        fi
    else
        echo "Watcher: Configuration file not found, skipping collectd management"
    fi
fi

# Start the Connectivity Checker in the background
/usr/bin/php /home/fpp/media/plugins/fpp-plugin-watcher/connectivityCheck.php &
