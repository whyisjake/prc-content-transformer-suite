#!/bin/bash
# Build FA Free sprite SVGs from @fortawesome/free-*-svg-icons packages.
# Run after `npm install` from the repo root.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(dirname "$SCRIPT_DIR")"
SPRITES_DIR="$PLUGIN_DIR/build/icons/sprites"

mkdir -p "$SPRITES_DIR"

echo "Building @prc/icon-library (Font Awesome Free)"

# Generate sprite SVGs from the three FA Free icon sets.
# Each set's index exports arrays of icon definition objects with { iconName, icon: [w, h, _, _, svgPathData] }.

for STYLE in solid regular brands; do
    PACKAGE="node_modules/@fortawesome/free-${STYLE}-svg-icons"
    OUTPUT="$SPRITES_DIR/${STYLE}.svg"

    if [ ! -d "$PLUGIN_DIR/$PACKAGE" ]; then
        echo "  Missing $PACKAGE — run npm install first."
        exit 1
    fi

    node - <<JSEOF > "$OUTPUT"
const pkg = require('$PLUGIN_DIR/$PACKAGE');
const icons = Object.values(pkg).filter(
    (v) => v && typeof v === 'object' && v.iconName && Array.isArray(v.icon)
);
const symbols = icons
    .map(({ iconName, icon: [w, h, , , path] }) => {
        const d = Array.isArray(path) ? path.join(' ') : path;
        return \`  <symbol id="\${iconName}" viewBox="0 0 \${w} \${h}"><path d="\${d}"/></symbol>\`;
    })
    .join('\n');
process.stdout.write(\`<svg xmlns="http://www.w3.org/2000/svg" style="display:none">\n\${symbols}\n</svg>\n\`);
JSEOF

    echo "  $STYLE: $(grep -c '<symbol' "$OUTPUT") icons → $OUTPUT"
done

echo "Done."
