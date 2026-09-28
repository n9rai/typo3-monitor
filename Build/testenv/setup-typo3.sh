#!/bin/bash
# Installiert TYPO3 im Container (einmalig) und die Extension aus /ext.
#   setup-typo3.sh <composer|classic> <12|13|14> <port>
# Datenbank: SQLite (kein DB-Container noetig). Admin-Passwort: /var/www/admin-password.txt
set -euo pipefail
MODE="$1"; MAJOR="$2"; PORT="$3"
ROOT=/var/www/t3
MARK=/var/www/.installed
PW_FILE=/var/www/admin-password.txt
export COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1
if [ "$MODE" = composer ]; then T3="$ROOT/vendor/bin/typo3"; else T3="$ROOT/typo3/sysext/core/bin/typo3"; fi

if [ -f "$MARK" ]; then
  echo "[setup] TYPO3 schon installiert - aktualisiere nur die Extension"
else
  # Reste eines abgebrochenen Versuchs entfernen
  rm -rf "$ROOT" /var/www/typo3_src-* /var/www/typo3_src-*.tar.gz
  [ -f "$PW_FILE" ] || echo "N9c-Test-$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 12)!" > "$PW_FILE"
  PW="$(cat "$PW_FILE")"
  case "$MAJOR" in 12) V="^12.4";; 13) V="^13.4";; 14) V="^14.3";; esac
  if [ "$MODE" = composer ]; then
    # Composer blockiert seit 2.9 Versionen mit bekannten Sicherheitsluecken.
    # TYPO3 12 hat oeffentlich nur noch solche (Fixes nur per ELTS) - fuer die
    # Testumgebung bewusst abschalten. Je nach Composer-Version heisst die
    # Einstellung unterschiedlich, deshalb beide setzen.
    export COMPOSER_NO_SECURITY_BLOCKING=1
    composer config -g policy.advisories.block false 2>/dev/null || true
    composer config -g audit.block-insecure false 2>/dev/null || true
    composer create-project "typo3/cms-base-distribution:$V" "$ROOT" --no-progress --no-install
    cd "$ROOT"
    composer config policy.advisories.block false 2>/dev/null || composer config audit.block-insecure false 2>/dev/null || true
    composer install --no-progress
    composer config repositories.n9c path /ext
    composer config minimum-stability dev
    composer config prefer-stable true
  else
    mkdir -p "$ROOT" && cd /var/www
    wget -q --content-disposition "https://get.typo3.org/$MAJOR"
    SRC=$(ls -d typo3_src-*.tar.gz | head -1); tar xzf "$SRC"; rm -f "$SRC"
    SRCDIR=$(ls -d /var/www/typo3_src-*/ | head -1)
    cd "$ROOT"
    ln -sfn "$SRCDIR" typo3_src && ln -sfn typo3_src/index.php index.php && ln -sfn typo3_src/typo3 typo3
  fi
  php "$T3" setup --driver=sqlite --admin-username=admin --admin-user-password="$PW" \
      --admin-email=test@example.org --project-name="n9c Test $MODE $MAJOR" \
      --server-type=apache --create-site="http://localhost:$PORT/" --force -n
  touch "$MARK"
fi

cd "$ROOT"
if [ "$MODE" = composer ]; then
  composer require "n9c/typo3-monitor:@dev" --no-progress -W
  php "$T3" extension:setup -n
else
  # nur die Extension, ohne Build-Werkzeuge und Git-Daten
  rm -rf typo3conf/ext/n9c_monitor && mkdir -p typo3conf/ext/n9c_monitor
  tar -C /ext --exclude=./.git --exclude=./.github --exclude=./Build -cf - . | tar -C typo3conf/ext/n9c_monitor -xf -
  php "$T3" extension:activate n9c_monitor -n || php "$T3" extension:setup -n
fi
rm -rf var/cache/* typo3temp/var/cache/* 2>/dev/null || true
php "$T3" cache:flush -n || true
chown -R www-data:www-data /var/www
echo "[setup] fertig: $MODE TYPO3 $MAJOR, PHP $(php -r 'echo PHP_VERSION;'), Port $PORT"
