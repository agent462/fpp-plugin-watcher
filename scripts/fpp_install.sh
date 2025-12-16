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

# Disable the default collectd service if it's enabled/running.  
# If collectd is enabled, it will start when FPP is started.
echo "Disabling default collectd service. If collectd is enabled, it will start when FPP is started..."
sudo systemctl disable --now collectd.service

# Define plugin directory
PLUGIN_DIR="/home/fpp/media/plugins/fpp-plugin-watcher"

# Compile C++ plugin if Makefile exists
if [ -f "${PLUGIN_DIR}/Makefile" ]; then
    echo "Compiling C++ plugin..."
    cd "${PLUGIN_DIR}"
    make clean 2>/dev/null || true
    make "SRCDIR=${SRCDIR:-/opt/fpp/src}"
    if [ $? -eq 0 ]; then
        echo "C++ plugin compiled successfully"
        chown fpp:fpp "${PLUGIN_DIR}/libfpp-plugin-watcher.so" 2>/dev/null || true
        chown -R fpp:fpp "${PLUGIN_DIR}/src/" 2>/dev/null || true
    else
        echo "WARNING: C++ plugin compilation failed"
    fi
fi

# Copy our custom collectd service file.  We are setting a Nice on the service.
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

# Migrate collectd RRD data from old location to new plugin-data location
OLD_RRD_DIR="/var/lib/collectd/rrd"
NEW_RRD_DIR="/home/fpp/media/plugin-data/fpp-plugin-watcher/collectd/rrd"

if [ -d "${OLD_RRD_DIR}" ] && [ "$(ls -A ${OLD_RRD_DIR} 2>/dev/null)" ]; then
    # Old data exists
    if [ -d "${NEW_RRD_DIR}" ] && [ "$(ls -A ${NEW_RRD_DIR} 2>/dev/null)" ]; then
        echo "Collectd RRD data already exists at new location; skipping migration."
    else
        echo "Migrating collectd RRD data to new location..."
        mkdir -p "${NEW_RRD_DIR}"
        cp -a "${OLD_RRD_DIR}/"* "${NEW_RRD_DIR}/"
        echo "Collectd RRD data migrated successfully"
        echo "Old data retained at ${OLD_RRD_DIR} - can be manually removed if desired"
    fi
else
    echo "No existing collectd RRD data to migrate."
    # Ensure the new directory structure exists
    mkdir -p "${NEW_RRD_DIR}"
fi

# Include common scripts functions and variables
. ${FPPDIR}/scripts/common

# Add required Apache CSP (Content-Security-Policy allowed domains
# Possible Keys are: 'default-src', 'connect-src', 'img-src', 'script-src', 'style-src', 'object-src'
${FPPDIR}/scripts/ManageApacheContentPolicy.sh add script-src https://cdn.jsdelivr.net

# Set FPP to restart to apply changes
setSetting restartFlag 1
