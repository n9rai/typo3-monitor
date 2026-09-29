# N9C Inside Monitor für TYPO3 (`n9c_monitor`)

Meldet sicherheitsrelevante Kennzahlen einer TYPO3-Installation an den
[N9C Inside Monitor](https://n9c.io). Dort werden sie ausgewertet: bekannte
Sicherheitslücken in Core und Extensions, fehlende Sicherheitsupdates, riskante
Konfiguration, Admin-Konten ohne Zwei-Faktor-Anmeldung und mehr – mit Verlauf,
Benachrichtigung bei neuen kritischen Befunden und ergänzender Außensicht.

- **TYPO3 12.4, 13.4 und 14.3** · PHP ≥ 8.1 · Composer- und klassischer Modus
- **Nur Zahlen und Flags** – keine Benutzernamen, E-Mail-Adressen, IP-Adressen oder Inhalte.
  Was genau gesendet wird, zeigt `n9c:monitor:report --dry-run` bzw. die Datenvorschau im Backend-Modul.
- **Nur ausgehende HTTPS-Verbindungen**, die Extension bietet keinen eingehenden Endpunkt
- **Funktioniert ohne Cronjob** – der Report wird bei normalen Seitenaufrufen im Hintergrund gesendet
- Offen dokumentiertes Protokoll: [Documentation/Protocol.md](Documentation/Protocol.md)

Für die Nutzung ist ein Konto im N9C-Dashboard nötig – **kostenlos registrieren:
<https://dashboard.n9c.io/dashboard/register>**. 14 Tage voller Umfang, danach
dauerhaft gratis für eine Installation (Score und offene Befunde); Verlauf,
E-Mail-Alerts, wöchentliche Außenscans und weitere Installationen mit dem Abo.
Den Verbindungscode erzeugen Sie im Dashboard unter *Verbindungscode*.

## Installation

**Composer-Modus**

```bash
composer require n9c/typo3-monitor
vendor/bin/typo3 extension:setup
```

**Klassischer Modus**

Im Backend unter *Admin Tools → Extensions* die Extension `n9c_monitor` aus dem
TER installieren, oder die ZIP-Datei eines [Releases](../../releases) über
„Upload Extension“ hochladen. Anschließend aktivieren.

## Verbinden

Am einfachsten im Backend-Modul: *System → N9C Inside Monitor* (TYPO3 12/13)
bzw. *Admin → N9C Inside Monitor* (TYPO3 14), Verbindungscode eingeben, „Verbinden“.

Alternativ auf der Kommandozeile:

```bash
vendor/bin/typo3 n9c:monitor:report --dry-run    # Vorschau, sendet nichts
vendor/bin/typo3 n9c:monitor:connect n9c-enroll-…
vendor/bin/typo3 n9c:monitor:report
vendor/bin/typo3 n9c:monitor:status
```

Im klassischen Modus statt `vendor/bin/typo3`: `php typo3/sysext/core/bin/typo3`.

Die Zugangsdaten werden in `config/n9c_monitor.php` (Composer-Modus) bzw.
`typo3conf/n9c_monitor.php` (klassisch) gespeichert – nicht in der Datenbank.
Liegt die Datei in einem Git-Repository, bitte in die `.gitignore` aufnehmen.
Alternativ lassen sie sich als Umgebungsvariablen setzen: `N9C_MONITOR_INSTANCE`,
`N9C_MONITOR_SECRET`, optional `N9C_MONITOR_ENDPOINT`.

## Befehle

| Befehl | Zweck |
|---|---|
| `n9c:monitor:connect <code> [--force]` | Verbindungscode gegen Zugangsdaten tauschen. `--force` verbindet eine bereits verbundene Installation neu – Instanz und Verlauf bleiben erhalten. |
| `n9c:monitor:report --dry-run` | Vorschau: zeigt das JSON, sendet nichts |
| `n9c:monitor:report` | Report senden (auch als Scheduler-Task nutzbar) |
| `n9c:monitor:status` | Verbindung und Ergebnis des letzten Reports |

## Backend-Modul

Für Administratoren:

- letzte Auswertung mit Score, offenen Befunden im Klartext und Empfehlungen
- Report jetzt senden
- Datenvorschau – genau das JSON, das gesendet wird
- Verbinden bzw. neu verbinden per Code
- Zeitplan des automatischen Reports

## Automatischer Report

Bei normalen Seitenaufrufen (Frontend oder Backend) prüft die Extension, ob der
letzte Report älter als das Intervall ist (Standard 6 Stunden), und sendet dann
**nach** Auslieferung der Seite im Hintergrund. Kosten pro Seitenaufruf: ein
Blick auf den Zeitstempel einer Datei, keine Datenbankabfrage. Parallele
Aufrufe senden dank Dateisperre nur einmal, nach einem Fehlschlag wird erst nach
30 Minuten erneut versucht.

Unterstützt der Server `fastcgi_finish_request()` (php-fpm) oder LiteSpeed,
merken Besucher nichts. Sonst wartet der eine auslösende Seitenaufruf, bis der
Report gesendet ist – einmal pro Intervall.

Einstellungen unter *Admin Tools → Settings → Extension Configuration →
n9c_monitor*: automatischer Report an/aus, Intervall in Stunden.

**Mit Cronjob:** Wer lieber den Scheduler nutzt, legt einen Task „Execute
console commands“ mit `n9c:monitor:report` an (z. B. `15 */6 * * *`). Das
verträgt sich mit dem automatischen Report.

## Was übertragen wird

- TYPO3-, PHP- und Datenbank-Version, Application Context
- installierte Extensions mit Version (Extension-Key und Composer-Name, für den
  Abgleich mit Sicherheitsmeldungen)
- Basis-URLs der Sites
- Konfigurationsprüfungen, jeweils nur als Zahl oder Ja/Nein:
  - Administratoren ohne Zwei-Faktor-Anmeldung, Anzahl Administratoren,
    Standard-Benutzername „admin“, inaktive Administrator-Konten
  - fehlgeschlagene Backend-Anmeldungen der letzten 24 Stunden (Anzahl)
  - öffentliche Fehler-/Debug-Ausgabe, Backend nur per HTTPS,
    `trustedHostsPattern`, Install Tool freigeschaltet
  - PHP-Dateien im Upload-Verzeichnis (relative Pfade, höchstens 20)
  - letzter Lauf des Schedulers

## Deinstallation

Extension im Backend unter *Admin Tools → Extensions* deaktivieren bzw.
`composer remove n9c/typo3-monitor`, danach `n9c_monitor.php` (siehe oben) und
`var/n9c_monitor/` bzw. `typo3temp/var/n9c_monitor/` löschen.

## Entwicklung

- `Build/build-zip.sh` – ZIP für den Upload im Extension Manager
- `Build/testenv/` – Docker-Testumgebung: TYPO3 12/13/14 × Composer/klassisch
- CI (GitHub Actions) testet jeden Push in allen sechs Varianten, ein Tag
  `v<version>` erzeugt ein Release mit ZIP und veröffentlicht im TER.

## Lizenz

GPL-2.0-or-later, siehe [LICENSE](LICENSE).
