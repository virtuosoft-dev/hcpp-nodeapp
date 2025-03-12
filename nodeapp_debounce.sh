#!/bin/bash
#
# Debounce script executes once every $TIMEOUT seconds; or resets the timer if already running
#
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

# Invoke the plugin with nodeapp_debounce argument
/usr/local/hestia/bin/v-invoke-plugin nodeapp_debounce

# Lockfile will be removed automatically due to trap
exit 0
