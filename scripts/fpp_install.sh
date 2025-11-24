#!/bin/bash

# fpp-plugin-watcher install script
packages=(collectd-core rrdtool)
missing_packages=()
for pkg in "${packages[@]}"; do
    if ! dpkg -s "$pkg" >/dev/null 2>&1; then
        missing_packages+=("$pkg")
    fi
done

if [ ${#missing_packages[@]} -gt 0 ]; then
    apt-get update
    apt-get -y install "${missing_packages[@]}"
fi
sudo systemctl disable --now collectd.service

# Copy our custom collectd service file.  We are setting a Nice on the service.
PLUGIN_DIR="/home/fpp/media/plugins/fpp-plugin-watcher"
if [ -f "${PLUGIN_DIR}/config/collectd.service" ]; then
    echo "Installing custom collectd service file..."
    cp "${PLUGIN_DIR}/config/collectd.service" /lib/systemd/system/collectd.service
    echo "Collectd service file installed successfully"
else
    echo "WARNING: Custom collectd.service not found at ${PLUGIN_DIR}/config/collectd.service"
fi

# Copy our custom collectd configuration
if [ -f "${PLUGIN_DIR}/config/collectd.conf" ]; then
    echo "Installing custom collectd configuration..."
    cp "${PLUGIN_DIR}/config/collectd.conf" /etc/collectd/collectd.conf
    echo "Collectd configuration installed successfully"
else
    echo "WARNING: Custom collectd.conf not found at ${PLUGIN_DIR}/config/collectd.conf"
fi

# Include common scripts functions and variables
. ${FPPDIR}/scripts/common

# Add required Apache CSP (Content-Security-Policy allowed domains
# Possible Keys are: 'default-src', 'connect-src', 'img-src', 'script-src', 'style-src', 'object-src'
${FPPDIR}/scripts/ManageApacheContentPolicy.sh add script-src https://cdn.jsdelivr.net

# Set FPP to restart to apply changes
setSetting restartFlag 1
