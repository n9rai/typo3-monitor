# Changelog

## 0.3.4 – 2026-09-30

- Sites mit Einstiegspunkt ohne Domain (z. B. `/`): Die Extension meldet jetzt
  die Domains, unter denen die Installation tatsächlich aufgerufen wird –
  gemerkt beim fälligen automatischen Report und bei Aktionen im
  Backend-Modul, höchstens fünf, nur Schema und Host
  (`var/n9c_monitor/seen_hosts.json`). Damit funktionieren auch hier die
  wöchentlichen Außenscans.
- Basisvarianten der Sites (z. B. Produktiv-Domain unter „Variants for the
  Entry Point“) werden mitgemeldet.

## 0.3.3 – 2026-09-30

- Backend-Modul: Ohne Verbindung eine Anleitung in drei Schritten mit Link zur
  kostenlosen Registrierung im N9C-Dashboard und zum Verbindungscode.
- Verbunden: Link „Im N9C-Dashboard öffnen“ direkt zur Instanz, Anzeige des
  Tarifs (Testphase, Gratis, Abo) und Hinweis, wenn die Installation ruht.
- `n9c:monitor:status` zeigt den Dashboard-Link und den Tarif.
- Dashboard-Adresse per `N9C_MONITOR_DASHBOARD` überschreibbar.

## 0.3.2 – 2026-09-28

- Middleware für den automatischen Report sitzt jetzt weit außen im Stack und
  sieht jede Anfrage (auch 404-Seiten, Weiterleitungen, eID).
- Getestet auf TYPO3 12.4, 13.4 und 14.3, jeweils Composer- und klassischer
  Modus, mit PHP 8.1 bis 8.3.

## 0.3.1 – 2026-09-26

- Sites werden ohne Cache gelesen: Änderungen an `config/sites/` (z. B. per
  Deployment oder FTP) kommen mit dem nächsten Report an.

## 0.3.0 – 2026-09-24

- Backend-Modul: letzte Auswertung mit offenen Befunden, Report senden,
  Datenvorschau, Verbinden per Code.

## 0.2.2 – 2026-09-24

- Neu verbinden (`--force`) behält Instanz und Verlauf.

## 0.2.1 – 2026-09-24

- Middleware ohne Konstruktor-Parameter (robust bei veraltetem DI-Cache).

## 0.2.0 – 2026-09-24

- Automatischer Report bei Seitenaufrufen – funktioniert ohne Cronjob.

## 0.1.0 – 2026-09-24

- Erste Version: Befehle `connect`, `report` (inkl. `--dry-run`), `status`.
