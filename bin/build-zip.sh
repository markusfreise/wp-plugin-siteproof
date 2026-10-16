#!/usr/bin/env bash
# Builds dist/siteproof-<version>.zip for the WordPress.org plugin directory.
# Usage: bin/build-zip.sh
set -euo pipefail

cd "$(dirname "$0")/.."
version="$(sed -n 's/^ \* Version: *//p' siteproof.php | tr -d '[:space:]')"
stable="$(sed -n 's/^Stable tag: *//p' readme.txt | tr -d '[:space:]')"
if [ "$version" != "$stable" ]; then
  echo "Version ($version) and Stable tag ($stable) differ" >&2
  exit 1
fi

npm run build --silent

rm -rf dist && mkdir -p dist/siteproof
rsync -a --exclude-from=<(sed 's#^/##' .distignore) --exclude='.*' ./ dist/siteproof/
(cd dist && zip -qr "siteproof-$version.zip" siteproof)
echo "dist/siteproof-$version.zip ($(du -h "dist/siteproof-$version.zip" | cut -f1))"
