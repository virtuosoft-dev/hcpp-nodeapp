#!/bin/bash

# Ensure nvm is loaded (important if running from a script)
export NVM_DIR="/opt/nvm"
[ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"

# Initialize JSON output
output="{\"versions\":["

# List installed Node.js versions and compare them with the latest available
nvm_output=$(nvm list --no-colors)

# Extract unique installed versions
installed_versions=$(echo "$nvm_output" | grep -oP '^\s*v\d+\.\d+\.\d+\s+\*|->\s*v\d+\.\d+\.\d+\s+\*' | grep -oP 'v\d+\.\d+\.\d+' | sort -u)

# Process each installed version
while read -r version; do
  version=$(echo $version | xargs)  # Trim leading and trailing spaces
  version=$(echo $version | sed 's/^v//')  # Remove leading 'v'
  
  major_version=$(echo $version | grep -oP '^\d+')
  latest_output=$(nvm ls-remote --no-colors | grep -E "v${major_version}\." | grep -oP 'v\d+\.\d+\.\d+' | sort -V)
  latest=$(echo "$latest_output" | tail -1 | sed 's/^v//')  # Remove leading 'v' from latest version
  if [ -z "$latest" ] || [ "$latest" == "$version" ]; then
    latest=$version
  fi
  output+="{\"installed\":\"$version\",\"latest\":\"$latest\"},"
done <<< "$installed_versions"

# Remove trailing comma and close JSON array
output="${output%,}]}"
echo "$output"