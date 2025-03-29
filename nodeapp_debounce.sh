#!/bin/bash
LOCKFILE="/tmp/nodeapp_debounce.lock"
TIMEOUT=5

# Check if lockfile exists
if [ -e "$LOCKFILE" ]; then
    touch "$LOCKFILE" # Update lockfile timestamp
    exit 1
fi

# Create lockfile
touch "$LOCKFILE"

# Ensure lockfile is removed on script exit
trap 'rm -f "$LOCKFILE"' EXIT

# Wait until the lockfile is $TIMEOUT seconds old
while [ $(( $(date +%s) - $(stat -c %Y "$LOCKFILE") )) -lt $TIMEOUT ]; do
    sleep 1
done

# Your script logic goes here
/usr/local/hestia/bin/v-invoke-plugin nodeapp_debounce

# Lockfile will be removed automatically due to trap
exit 0

