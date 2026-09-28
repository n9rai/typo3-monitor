#!/usr/bin/env bash
# Baut die ZIP fuer den Upload im Extension Manager (Admin Tools -> Extensions
# -> Upload Extension). TYPO3 liest den Extension-Key aus dem Dateinamen
# (<key>_<version>.zip) und entpackt direkt nach typo3conf/ext/<key>/ - die
# Dateien liegen deshalb auf oberster Ebene.
#
#   Build/build-zip.sh            -> Build/dist/n9c_monitor_<version>.zip
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIST="$ROOT/Build/dist"
VERSION="$(sed -n 's/.*"version": *"\([^"]*\)".*/\1/p' "$ROOT/composer.json" | head -1)"
EMCONF="$(sed -n "s/.*'version' => '\([^']*\)'.*/\1/p" "$ROOT/ext_emconf.php" | head -1)"
if [ -z "$VERSION" ] || [ "$VERSION" != "$EMCONF" ]; then
  echo "Versionen passen nicht: composer.json='$VERSION', ext_emconf.php='$EMCONF'" >&2
  exit 1
fi
mkdir -p "$DIST"
ZIP="$DIST/n9c_monitor_${VERSION}.zip"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
# Inhalt wie im Composer-Paket: alles ausser export-ignore (.gitattributes)
( cd "$ROOT" && tar --exclude-vcs --exclude='./Build' --exclude='./.github' --exclude='./.gitattributes' \
    --exclude='./.gitignore' --exclude='./CHANGELOG.md' --exclude='.DS_Store' --exclude='._*' -cf - . ) | tar -C "$TMP" -xf -
rm -f "$ZIP"
( cd "$TMP" && zip -r -X -q "$ZIP" . )
echo "Erstellt: $ZIP ($(unzip -l "$ZIP" | tail -1 | awk '{print $2}') Dateien)"
