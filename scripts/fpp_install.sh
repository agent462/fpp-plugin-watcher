#!/bin/bash

# fpp-plugin-watcher install script
apt-get update
apt-get -y install collectd-core rrdtool
sudo systemctl disable --now collectd.service

# Copy our custom collectd configuration
PLUGIN_DIR="${FPPDIR}/../media/plugins/fpp-plugin-watcher"
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
# Examples: 
# ${FPPDIR}/scripts/ManageApacheContentPolicy.sh add connect-src https://domaintotrust.co.uk
# ${FPPDIR}/scripts/ManageApacheContentPolicy.sh add img-src https://anotherdomain.com
${FPPDIR}/scripts/ManageApacheContentPolicy.sh add script-src https://cdn.jsdelivr.net

setSetting restartFlag 1