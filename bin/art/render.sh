#!/usr/bin/env bash
# Renders the WordPress.org banner and icon PNGs from the SVG sources (needs rsvg-convert).
set -euo pipefail
cd "$(dirname "$0")"
OUT=../../.wordpress-org
mkdir -p "$OUT"
rsvg-convert -w 1544 -h 500 banner.svg -o "$OUT/banner-1544x500.png"
rsvg-convert -w 772 -h 250 banner.svg -o "$OUT/banner-772x250.png"
rsvg-convert -w 256 -h 256 icon.svg -o "$OUT/icon-256x256.png"
rsvg-convert -w 128 -h 128 icon.svg -o "$OUT/icon-128x128.png"
