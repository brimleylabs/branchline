#!/usr/bin/env bash
# Build branchline.zip for upload to WordPress.org (plugin files only, no dev files).
set -euo pipefail
cd "$(dirname "$0")/.."
rm -rf build && mkdir -p build/branchline
cp -r wp-branches.php readme.txt uninstall.php includes assets extras build/branchline/
mv build/branchline/wp-branches.php build/branchline/branchline.php
mkdir -p build/branchline/docs && cp docs/*.md build/branchline/docs/
python - <<'PY'
import os, zipfile
os.chdir("build")
with zipfile.ZipFile("branchline.zip", "w", zipfile.ZIP_DEFLATED) as z:
    for root, dirs, files in os.walk("branchline"):
        dirs.sort()
        for f in sorted(files):
            path = os.path.join(root, f)
            z.write(path, path.replace(os.sep, "/"))
PY
echo "build/branchline.zip"
unzip -l build/branchline.zip | tail -1
