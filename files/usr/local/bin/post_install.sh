#!/bin/sh

# Log that post-installation tasks are starting
echo "Checking pre requisites ..."


# Check if wg exists
if command -v wg >/dev/null 2>&1; then
  echo "wg found at: $(command -v wg)"
  
  # Backup the existing wg binary to awg1
  cp /usr/bin/wg /usr/bin/awg1
  echo "Backup created: /usr/bin/awg1"

  # Copy the custom awg binary over to /usr/bin/wg
  cp /usr/local/bin/awg /usr/bin/wg
  echo "Custom awg binary installed to /usr/bin/wg"
else
  echo "wg not found. Skipping wg backup and update."
fi

# If nothing failed, indicate that the environment is ready
echo "Amnezia Wireguard is ready"
