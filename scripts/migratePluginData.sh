#!/bin/bash
#
# Migrate plugin data from old plugin-data directory to new plugindata directory
# This script handles the one-time migration when upgrading from older versions
#

PLUGIN_NAME="fpp-plugin-watcher"
OLD_DATA_DIR="/home/fpp/media/plugin-data/${PLUGIN_NAME}"
NEW_DATA_DIR="/home/fpp/media/plugindata/${PLUGIN_NAME}"

# Check if old data directory exists
if [ ! -d "${OLD_DATA_DIR}" ]; then
    echo "No old plugin data directory found at ${OLD_DATA_DIR}; skipping migration."
    exit 0
fi

# Check if old directory has any content
if [ ! "$(ls -A ${OLD_DATA_DIR} 2>/dev/null)" ]; then
    echo "Old plugin data directory is empty; removing and skipping migration."
    rmdir "${OLD_DATA_DIR}" 2>/dev/null
    rmdir "/home/fpp/media/plugin-data" 2>/dev/null
    exit 0
fi

# Check if new directory already has data (don't overwrite)
if [ -d "${NEW_DATA_DIR}" ] && [ "$(ls -A ${NEW_DATA_DIR} 2>/dev/null)" ]; then
    echo "New plugin data directory already exists with data; skipping migration to avoid data loss."
    echo "Old data remains at: ${OLD_DATA_DIR}"
    exit 0
fi

echo "Migrating plugin data from ${OLD_DATA_DIR} to ${NEW_DATA_DIR}..."

# Create parent directory
mkdir -p "/home/fpp/media/plugindata"

# Move the entire plugin data directory (preserves ownership)
mv "${OLD_DATA_DIR}" "${NEW_DATA_DIR}"

if [ $? -eq 0 ]; then
    echo "Plugin data migrated successfully."

    # Clean up old parent directory if empty
    rmdir "/home/fpp/media/plugin-data" 2>/dev/null

    echo "Migration complete."
else
    echo "ERROR: Failed to migrate plugin data."
    exit 1
fi
