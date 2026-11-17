#!/usr/bin/env bash
#
# Builds the release archive for piwigo.org/ext: dist/like_bravo-<version>.zip
# It unpacks into a folder "like_bravo" (the plugin id) and holds the last
# commit without the development files listed in .gitattributes.
#
#   tools/build.sh
#
set -euo pipefail

cd "$(dirname "$0")/.."

plugin_id="like_bravo"
version="$(sed -n 's/^Version:[[:space:]]*//p' main.inc.php | head -n 1)"

if [ -z "$version" ]; then
  echo "No version found in main.inc.php" >&2
  exit 1
fi

if [ -n "$(git status --porcelain)" ]; then
  echo "Warning: uncommitted changes are not part of the archive." >&2
fi

archive="dist/${plugin_id}-${version}.zip"
mkdir -p dist
rm -f "$archive"

git archive --format=zip --worktree-attributes --prefix="${plugin_id}/" -o "$archive" HEAD

# nothing but the plugin folder, and none of the development files
content="$(unzip -Z1 "$archive")"
if echo "$content" | grep -qvE "^${plugin_id}/"; then
  echo "Archive holds files outside ${plugin_id}/" >&2
  exit 1
fi
if echo "$content" | grep -qE "^${plugin_id}/(CLAUDE\.md|KONZEPT\.md|tests/|tools/|\.git)"; then
  echo "Archive holds development files" >&2
  exit 1
fi
if ! echo "$content" | grep -qx "${plugin_id}/main.inc.php"; then
  echo "Archive has no ${plugin_id}/main.inc.php" >&2
  exit 1
fi

echo "$archive ($(echo "$content" | grep -vc '/$') files, commit $(git rev-parse --short HEAD))"
