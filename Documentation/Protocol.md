# N9C Inside Monitor – Agent-Protokoll v1

Vertrag zwischen CMS-Agent (diese TYPO3-Extension, später weitere CMS) und dem N9C-Backend (`api.n9c.io`). Das Protokoll ist offen dokumentiert, damit nachvollziehbar ist, was übertragen wird.

Stand: 24.09.2026 · Schema `n9c.agent.report/1`

## 1. Verbinden (einmalig)

Der Kunde bekommt einen Einmal-Code (`n9c-enroll-<32 hex>`, 72 h gültig). Der Agent tauscht ihn gegen Instanz-ID und Secret:

```
POST https://api.n9c.io/agent/v1/enroll
Content-Type: application/json

{
  "token": "n9c-enroll-…",
  "agent": {"name": "n9c_monitor", "version": "0.1.0"},
  "cms":   {"type": "typo3", "version": "13.4.20"},
  "sites": ["https://www.kunde.de"]
}
```

Antwort `200`:

```json
{"instance_id": "…uuid…", "secret": "…64 hex…", "report_url": "/agent/v1/report", "report_interval_seconds": 21600}
```

`403` = Code ungültig, abgelaufen oder schon benutzt. `503` = Backend nicht konfiguriert.

**Neu verbinden ohne neue Instanz** (Verlauf bleibt erhalten): Ist der Agent bereits verbunden, schickt er zusätzlich

```json
"previous_instance_id": "<bisherige instance_id>",
"previous_proof": "sha256=<hex(HMAC-SHA256(bisheriges_secret, \"rebind.\" + token))>"
```

Stimmt der Nachweis, verwendet das Backend die bestehende Instanz weiter, stellt ein neues Secret aus (das alte wird ungültig) und antwortet mit `"reused": true`. Stimmt er nicht, entsteht eine neue Instanz. Alternativ kann N9C den Code serverseitig an eine bestehende Instanz binden – nötig, wenn das alte Secret verloren ist (Neuinstallation).

Der Agent speichert `instance_id` und `secret` **außerhalb der Datenbank** (bevorzugt Umgebungsvariablen `N9C_MONITOR_INSTANCE` / `N9C_MONITOR_SECRET`, sonst `settings.php`/`additional.php`). Das Secret ist nur für diese Instanz gültig.

## 2. Report senden (alle 6 h, frühestens 1× pro Minute)

```
POST https://api.n9c.io/agent/v1/report
Content-Type: application/json
X-N9C-Instance:  <instance_id>
X-N9C-Timestamp: <Unix-Sekunden, UTC>
X-N9C-Signature: sha256=<hex(HMAC-SHA256(secret, "<timestamp>.<roher Body>"))>
```

Wichtig: Signiert wird **exakt der gesendete Byte-String** des Bodys (nach dem JSON-Encoding, vor dem Senden) mit vorangestelltem `"<timestamp>."`.

PHP-Beispiel:

```php
$body = json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$ts   = (string) time();
$sig  = 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, $secret);
```

Antworten:

| Code | Bedeutung | Agent-Reaktion |
|---|---|---|
| 200 | verarbeitet: `{"status":"ok","report_id","score","score_status","counts","delta","findings","next_report_in_seconds"}` – `findings`: offene Befunde (critical/medium) mit `check`, `severity`, `problem`, `risk`, `recommendation`, max. 50, kritische zuerst | Ergebnis im Backend-Modul anzeigen |
| 401 | Instanz unbekannt/gesperrt, Signatur falsch, Zeitstempel > ±300 s daneben | Hinweis „Verbindung prüfen / Serverzeit (NTP) prüfen“, nicht in Schleife wiederholen |
| 409 | Zeitstempel nicht neuer als der letzte (Replay) | ignorieren |
| 413 | Body > 1 MB | Paketliste kürzen, melden |
| 422 | Schema-Fehler (Details in `detail`) | Agent-Update nötig, melden |
| 429 | < 60 s seit dem letzten Report | später erneut |
| 503 | Backend nicht konfiguriert | später erneut |

## 3. Report-Format (`n9c.agent.report/1`)

```json
{
  "schema": "n9c.agent.report/1",
  "agent":  {"name": "n9c_monitor", "version": "0.1.0"},
  "cms":    {"type": "typo3", "version": "13.4.20", "context": "Production"},
  "runtime": {"php": "8.3.12", "db": "mariadb 10.11"},
  "packages": [
    {"ecosystem": "composer",  "name": "typo3/cms-core", "version": "13.4.20"},
    {"ecosystem": "typo3-ter", "name": "news",           "version": "12.1.0"}
  ],
  "checks": [
    {"id": "auth.admin_without_mfa", "value": 2},
    {"id": "cms.typo3.install_tool_enabled", "value": false}
  ],
  "sites": ["https://www.kunde.de"],
  "cms_specific": {}
}
```

- **`cms.type`**: aktuell nur `typo3`.
- **`packages[].ecosystem`**: `composer` (Composer-Modus: alle installierten Pakete), `typo3-ter` (klassischer Modus: Extension-Key), später `wordpress-core|wordpress-plugin|wordpress-theme|drupal`. Den TYPO3-Core im klassischen Modus nicht extra melden – das Backend leitet `typo3/cms-core` aus `cms.version` ab.
- **`checks`**: IDs und Datentypen aus [`checks.yaml`](checks.yaml). Nur **Zahlen und Flags**, nie Benutzernamen, E-Mails, IPs oder Inhalte. Nicht erhebbar → Check weglassen oder `null` (dann gilt der Check als „nicht geprüft“, offene Befunde bleiben offen).
- **`runtime.php`**: vollständige PHP-Version (`PHP_VERSION`).
- **`cms_specific`**: frei, nur zur Anzeige. Alles, was bewertet werden soll, gehört als `cms.<typ>.*`-Check in `checks`.
- Unbekannte Felder werden ignoriert (neue Agents funktionieren mit älteren Backends). Was ältere Agents brechen würde, bekommt Schema `/2`.

## 4. Datenschutz-Grundsatz

Der Agent sendet ausschließlich technische Kennzahlen. Die Backend-Modul-Vorschau zeigt dem Admin **genau das JSON**, das übertragen wird (`vendor/bin/typo3 n9c:monitor:report --dry-run`).
