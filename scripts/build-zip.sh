#!/usr/bin/env bash
# Build branchline.zip for upload to WordPress.org (plugin files only, no dev files).
set -euo pipefail
cd "$(dirname "$0")/.."
rm -rf build && mkdir -p build/branchline
cp -r wp-branches.php readme.txt uninstall.php includes assets build/branchline/
mv build/branchline/wp-branches.php build/branchline/branchline.php
mkdir -p build/branchline/docs && cp docs/*.md build/branchline/docs/
( cd build && powershell.exe -NoProfile -Command "Compress-Archive -Path branchline -DestinationPath branchline.zip -Force" )
echo "build/branchline.zip"
unzip -l build/branchline.zip | tail -1
