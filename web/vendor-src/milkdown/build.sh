#!/bin/sh
# Rebuilds the Markdown editor of the library (Milkdown Crepe, MIT) into web/public/vendor/.
# The app has no build step: the result is committed. After a version change, update MILKDOWN in web/public/app.js.
set -e
cd "$(dirname "$0")"
npm install --no-audit --no-fund
V=$(node -p "require('@milkdown/crepe/package.json').version")
OUT=../../public/vendor/milkdown-$V
npx esbuild entry.js --bundle --format=esm --minify --legal-comments=eof --outfile=$OUT.js
npx esbuild entry.css --bundle --minify --outfile=$OUT.css
echo "built $OUT.js and $OUT.css"
