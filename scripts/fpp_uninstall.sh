#!/bin/bash

sudo apt remove -y collectd-core rrdtool
sudo apt autoremove -y

# Include common FPP functions
. ${FPPDIR}/scripts/common

# Remove Content-Security-Policy entries added during install
${FPPDIR}/scripts/ManageApacheContentPolicy.sh remove script-src https://cdn.jsdelivr.net

# Optional: Clean up log files (uncomment if desired)
# rm -f /home/fpp/media/logs/fpp-plugin-watcher.log
# rm -f /home/fpp/media/logs/fpp-plugin-watcher-ping-metrics.log

# Optional: Remove configuration file (uncomment if desired)
# rm -f /opt/fpp/media/config/plugin.fpp-plugin-watcher

setSetting restartFlag 1