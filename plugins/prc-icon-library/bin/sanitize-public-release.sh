#!/usr/bin/env bash
# Guard: ensure only FA Free sprites are present in the sprites directory.
# Removes any file that is not one of the three FA Free sets (solid, regular, brands).
# Run as a pre-commit or CI check before any public push.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(dirname "$SCRIPT_DIR")"
SPRITES="${PLUGIN_DIR}/build/icons/sprites"

if [ ! -d "$SPRITES" ]; then
    echo "No sprites directory at ${SPRITES}; nothing to sanitize."
    exit 0
fi

find "$SPRITES" -type f -name '*.svg' \
    ! -name 'solid.svg' \
    ! -name 'regular.svg' \
    ! -name 'brands.svg' \
    -delete

echo "Remaining sprites (FA Free only):"
ls -la "$SPRITES"
