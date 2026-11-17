#!/usr/bin/env bash
#
# Prepares a screenshot for the README: docs/screenshots/<name>.png
#
#   tools/screenshot.sh <image> [name]
#
# <image> is a screenshot in any format macOS reads, usually HEIC or PNG,
# also with the colour profile Display P3 or as HDR. [name] defaults to the
# file name of <image> without its extension.
#
# Steps:
#   1. convert to sRGB with ColorSync (sips). Without it, Display P3 looks
#      dull and HDR grey in browsers that ignore the profile;
#   2. scale down to at most 1280 px on the longer side;
#   3. reduce to 256 colours with dithering (libimagequant, as pngquant),
#      which keeps a screenshot at a tenth of its size or less.
#
# Needs macOS (sips) and python3. Pillow and imagequant are installed on the
# first run into a virtual environment of their own, by default in
# ~/.cache/like_bravo-tools/venv (LB_TOOLS_VENV chooses another place).
#
# A maintenance tool: not part of the release archive (.gitattributes).
#
set -euo pipefail

if [ $# -lt 1 ] || [ $# -gt 2 ]; then
  echo "usage: $0 <image> [name]" >&2
  exit 1
fi

input="$1"
name="${2:-$(basename "${input%.*}")}"
name="${name%.png}"

if [ ! -f "$input" ]; then
  echo "No such file: $input" >&2
  exit 1
fi
if ! command -v sips >/dev/null; then
  echo "sips not found: this tool needs macOS" >&2
  exit 1
fi

repo="$(cd "$(dirname "$0")/.." && pwd)"
target="$repo/docs/screenshots/$name.png"
venv="${LB_TOOLS_VENV:-${XDG_CACHE_HOME:-$HOME/.cache}/like_bravo-tools/venv}"

if [ ! -x "$venv/bin/python" ]; then
  echo "Installing Pillow and imagequant into $venv" >&2
  python3 -m venv "$venv"
  "$venv/bin/pip" install --quiet --disable-pip-version-check pillow imagequant
fi

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

sips -s format png --matchTo "/System/Library/ColorSync/Profiles/sRGB Profile.icc" \
  "$input" --out "$work/srgb.png" >/dev/null

"$venv/bin/python" -I - "$work/srgb.png" "$work/small.png" <<'PYTHON'
import sys
from PIL import Image
import imagequant

source, target = sys.argv[1:3]
image = Image.open(source).convert('RGBA')
image.thumbnail((1280, 1280), Image.LANCZOS)
reduced = imagequant.quantize_pil_image(image, dithering_level=1.0, max_colors=256,
                                        min_quality=0, max_quality=95)
reduced.save(target, optimize=True)
print('%dx%d' % image.size)
PYTHON

mkdir -p "$(dirname "$target")"
cp "$work/small.png" "$target"

echo "$target: $(( $(wc -c < "$input") / 1024 )) KB -> $(( $(wc -c < "$target") / 1024 )) KB"
