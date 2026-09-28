#!/bin/bash
# Kompatibilitaetstest n9c_monitor: TYPO3 12/13/14 je im Composer- und
# klassischen Modus (6 Docker-Container).
#
#   Build/testenv/run-tests.sh                      # alle Varianten
#   Build/testenv/run-tests.sh t3v12-classic t3v14-composer
#
# Pro Variante: TYPO3 installieren (einmalig), Extension einspielen,
# PHP-Syntax, Befehle, Dry-Run, Frontend/Backend ohne Fehler.
#
# Optional mit einem N9C-Backend (Verbinden + Report senden):
#   N9C_BACKEND_DIR=/pfad/zum/backend  -> Verbindungscodes per admin-CLI,
#                                         Test-Instanzen werden danach gesperrt
#   N9C_API=http://host.docker.internal:8000   (Standard)
#   KEEP=1                              -> Test-Instanzen nicht sperren
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
BACKEND="${N9C_BACKEND_DIR:-}"
API="${N9C_API:-http://host.docker.internal:8000}"
ALL="t3v12-composer t3v12-classic t3v13-composer t3v13-classic t3v14-composer t3v14-classic"
VARIANTS="${*:-$ALL}"
cd "$HERE"

dc() { docker compose "$@"; }
admin() { (cd "$BACKEND" && venv/bin/python3 -m app.inside.admin "$@"); }

echo "== Images bauen und Container starten"
dc up -d --build $VARIANTS || exit 1

declare -A RESULT
IDS=()
for v in $VARIANTS; do
  mode=${v#*-}; major=${v#t3v}; major=${major%%-*}
  port=$(dc exec -T "$v" printenv T3_PORT | tr -d '\r')
  if [ "$mode" = composer ]; then T3=/var/www/t3/vendor/bin/typo3; else T3=/var/www/t3/typo3/sysext/core/bin/typo3; fi
  t3() { dc exec -T -u www-data "$v" php "$T3" "$@"; }
  step=""; fail() { RESULT[$v]="FEHLER bei: $step"; echo "!! $v: $step"; }
  echo; echo "================ $v (Port $port) ================"

  step="TYPO3-Installation"
  dc exec -T "$v" setup-typo3.sh "$mode" "$major" "$port" > "/tmp/n9c-t3test-$v.log" 2>&1 \
    || { tail -30 "/tmp/n9c-t3test-$v.log"; fail; continue; }
  tail -1 "/tmp/n9c-t3test-$v.log"

  step="PHP-Syntax"
  lint=$(dc exec -T "$v" sh -c 'find /ext -path /ext/.git -prune -o -name "*.php" -exec php -l {} \; 2>&1 | grep -v "^No syntax errors"')
  [ -z "$lint" ] || { echo "$lint"; fail; continue; }

  step="Befehle registriert"
  t3 list n9c 2>&1 | grep -q "n9c:monitor:report" || { t3 list n9c; fail; continue; }

  step="Dry-Run"
  t3 n9c:monitor:report --dry-run > "/tmp/n9c-t3test-$v.json" 2>&1
  python3 - "/tmp/n9c-t3test-$v.json" <<'PY' || { tail -20 "/tmp/n9c-t3test-$v.json"; fail; continue; }
import json, sys
raw = open(sys.argv[1]).read()
data = json.loads(raw[raw.index("{"):raw.rindex("}") + 1])
cms, checks = data["cms"], [c for c in data["checks"] if c.get("value") is not None]
print(f"   Dry-Run: {cms['type']} {cms['version']}, {len(data['packages'])} Pakete, "
      f"{len(checks)}/{len(data['checks'])} Checks mit Wert, Sites {data['sites']}")
assert data["sites"], "keine Sites gemeldet"
PY

  if [ -z "$BACKEND" ]; then
    echo "   (ohne N9C-Backend: Verbinden/Report uebersprungen)"
  else
  step="Backend erreichbar"
  dc exec -T "$v" sh -c "curl -sf -m 10 $API/health >/dev/null" || { fail; continue; }

  step="Verbinden"
  code=$(admin create-token --label "[TEST] TYPO3 $major $mode" --hours 1 | grep -o 'n9c-enroll-[0-9a-f]*' | head -1)
  t3 n9c:monitor:connect "$code" --endpoint "$API" --force 2>&1 | tail -3
  id=$(t3 n9c:monitor:status 2>&1 | grep -oE '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}' | head -1)
  [ -n "$id" ] || { fail; continue; }
  IDS+=("$id")

  step="Report"
  t3 n9c:monitor:report 2>&1 | tee "/tmp/n9c-t3test-$v.report" | grep -q "Report angenommen" \
    || { cat "/tmp/n9c-t3test-$v.report"; fail; continue; }
  grep -o "Score.*" "/tmp/n9c-t3test-$v.report" | head -1 | sed 's/^/   /'
  fi

  # Frische Installation ohne TypoScript-Template: TYPO3 antwortet dann mit
  # einer eigenen Fehlerseite (404/503) - das ist ok. Ein Problem der
  # Middleware zeigt sich als HTTP 500 bzw. "PHP Fatal" im Container-Log.
  step="Frontend (Middleware)"
  since=$(date -u +%Y-%m-%dT%H:%M:%SZ)
  fe=$(curl -s -o /dev/null -w '%{http_code}' -m 20 "http://127.0.0.1:$port/")
  echo "   Frontend HTTP $fe"
  case "$fe" in 000|500|502) fail; continue;; esac

  step="Backend-Login-Seite"
  be=$(curl -s -o /dev/null -w '%{http_code}' -m 20 "http://127.0.0.1:$port/typo3/")
  echo "   Backend-Login HTTP $be"
  case "$be" in 200|302|303) ;; *) fail; continue;; esac

  step="Keine PHP-Fehler im Log"
  if dc logs --since "$since" "$v" 2>&1 | grep -iE "PHP (Fatal|Parse) error|Uncaught" | grep -v "^$"; then fail; continue; fi

  RESULT[$v]="OK"
done

if [ -n "$BACKEND" ] && [ "${KEEP:-0}" != 1 ]; then
  for id in "${IDS[@]}"; do admin revoke "$id" >/dev/null && echo "Test-Instanz gesperrt: $id"; done
fi

echo; echo "================ Ergebnis ================"
rc=0
for v in $VARIANTS; do printf '%-16s %s\n' "$v" "${RESULT[$v]:-nicht gelaufen}"; [ "${RESULT[$v]:-}" = OK ] || rc=1; done
echo; echo "Backend-Modul von Hand pruefen: Build/testenv/README.md"
exit $rc
