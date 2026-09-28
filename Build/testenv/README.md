# Testumgebung (TYPO3 12 / 13 / 14)

Sechs Docker-Container: jede unterstützte TYPO3-Version einmal im Composer- und
einmal im klassischen Modus, jeweils mit der kleinsten passenden PHP-Version.
Datenbank ist SQLite. Die Extension wird direkt aus diesem Repo eingebunden.

| Container | TYPO3 | Modus | PHP | Port (nur 127.0.0.1) |
|---|---|---|---|---|
| `t3v12-composer` | 12.4 | Composer | 8.1 | 8121 |
| `t3v12-classic` | 12.4 | klassisch | 8.1 | 8122 |
| `t3v13-composer` | 13.4 | Composer | 8.2 | 8131 |
| `t3v13-classic` | 13.4 | klassisch | 8.2 | 8132 |
| `t3v14-composer` | 14.3 | Composer | 8.3 | 8141 |
| `t3v14-classic` | 14.3 | klassisch | 8.3 | 8142 |

```sh
Build/testenv/run-tests.sh                      # alle sechs
Build/testenv/run-tests.sh t3v12-classic        # nur einzelne
```

Geprüft wird pro Variante:

- Installation und PHP-Syntax
- Registrierung der Befehle
- `report --dry-run` (gültiges JSON, Sites gemeldet)
- Frontend und Backend-Login ohne Fehler: kein HTTP 500, kein „PHP Fatal“ im Log

Eine frische Installation ohne TypoScript liefert im Frontend eine
TYPO3-Fehlerseite (404/503), das ist in Ordnung.

Die Composer-Sicherheitssperre (Composer ≥ 2.9) ist in den Test-Containern
abgeschaltet: TYPO3 12 hat öffentlich nur noch Versionen mit bekannten Lücken,
weil Korrekturen nur per ELTS kommen.

## Mit N9C-Backend

Mit Zugriff auf ein N9C-Backend (Betreiber) werden die Instanzen zusätzlich
verbunden, und je ein Report wird gesendet:

```sh
N9C_BACKEND_DIR=/pfad/zum/exposure-monitor/backend Build/testenv/run-tests.sh
```

Die Instanzen heißen „[TEST] …“, lösen keine Benachrichtigungen aus und werden
am Ende gesperrt (`KEEP=1` lässt sie bestehen).

## Backend-Modul im Browser

Admin-Passwort auslesen, z. B. für TYPO3 12 klassisch:

```sh
cd Build/testenv && docker compose exec t3v12-classic cat /var/www/admin-password.txt
```

Dann `http://localhost:8122/typo3/` öffnen (bei einem entfernten Server per
SSH-Tunnel `ssh -L 8122:127.0.0.1:8122 <server>`), Benutzer `admin`.

## Aufräumen

```sh
docker compose down        # Container stoppen, Installationen bleiben
docker compose down -v     # alles löschen
```

Platzbedarf: etwa 3 GB.
