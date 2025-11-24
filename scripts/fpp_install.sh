#!/bin/bash

if [ "${EUID}" -ne 0 ]; then
    echo "This install script must be run as root."
    exit 1
fi

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
    apt-get -y install --no-install-recommends "${missing_packages[@]}"
fi
sudo systemctl disable --now collectd.service

# Copy our custom collectd service file.  We are setting a Nice on the service.
PLUGIN_DIR="/home/fpp/media/plugins/fpp-plugin-watcher"
if [ -f "${PLUGIN_DIR}/config/collectd.service" ]; then
    if [ -f "/lib/systemd/system/collectd.service" ] && cmp -s "${PLUGIN_DIR}/config/collectd.service" /lib/systemd/system/collectd.service; then
        echo "Custom collectd service file already up to date; skipping copy."
    else
        echo "Installing custom collectd service file..."
        cp "${PLUGIN_DIR}/config/collectd.service" /lib/systemd/system/collectd.service
        echo "Collectd service file installed successfully"
    fi
else
    echo "WARNING: Custom collectd.service not found at ${PLUGIN_DIR}/config/collectd.service"
fi

# Copy our custom collectd configuration
if [ -f "${PLUGIN_DIR}/config/collectd.conf" ]; then
    if [ -f "/etc/collectd/collectd.conf" ] && cmp -s "${PLUGIN_DIR}/config/collectd.conf" /etc/collectd/collectd.conf; then
        echo "Custom collectd configuration already up to date; skipping copy."
    else
        echo "Installing custom collectd configuration..."
        cp "${PLUGIN_DIR}/config/collectd.conf" /etc/collectd/collectd.conf
        echo "Collectd configuration installed successfully"
    fi
else
    echo "WARNING: Custom collectd.conf not found at ${PLUGIN_DIR}/config/collectd.conf"
fi

echo ${FPPDIR}

# Include common scripts functions and variables
. ${FPPDIR}/scripts/common

# Add required Apache CSP (Content-Security-Policy allowed domains
# Possible Keys are: 'default-src', 'connect-src', 'img-src', 'script-src', 'style-src', 'object-src'
${FPPDIR}/scripts/ManageApacheContentPolicy.sh add script-src https://cdn.jsdelivr.net

# Set FPP to restart to apply changes
setSetting restartFlag 1
