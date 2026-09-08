# Sveevee Automation/Research Worker

Der Worker laeuft unabhaengig von Frontend und Laravel-Web-Requests. Er recherchiert ueber austauschbare Source-Adapter, speichert seinen Zustand in SQLite, prueft Dubletten ueber die bestehende Business-Import-API und sendet Schreibvorgaenge in Bloecken von hoechstens 100 Businesses.

## Eigenschaften

- OAuth-2.0 Client Credentials mit automatischer Token-Erneuerung
- Lokaler, persistenter Status fuer Businesses, Identitaetssignale, Quellen-URLs, Fehler, Batches und Runs
- Idempotente Batch-Retries mit vor dem Request gespeicherter `client_import_id`
- Einzelne Fehler stoppen die restlichen Batch-Eintraege nicht
- Fehlende Update-Felder loeschen keine vorhandenen Daten
- Beanspruchte Seiten werden erkannt und niemals veraendert
- JSON-Reports und JSON-Line-Log nach jedem Lauf
- Prozesssperre gegen zwei gleichzeitige Worker
- `robots.txt`, Rate Limits, HTTPS-Pruefung und Schutz vor Requests in private Netze beim Website-Enrichment

Die Quellenfelder `source_name`, `source_url` und `source_checked_at` bleiben mitsamt einem Hash und dem recherchierten Rohobjekt im lokalen SQLite. Die vorhandene Sveevee-API akzeptiert diese Felder nicht; der Worker entfernt sie daher aus dem API-Payload.

## CLI

```bash
./bin/worker research
./bin/worker import
./bin/worker run
./bin/worker status
./bin/worker retry-failed

./bin/worker run --limit=100
./bin/worker run --dry-run --limit=10
```

`research` schreibt nur in den lokalen Status. `import` verarbeitet bereits recherchierte Eintraege. `run` fuehrt beides aus. `--dry-run` darf die Read-Endpunkte zur Dublettenpruefung verwenden, sendet aber keinen Create-, Update- oder Batch-Request an Sveevee.

## Quellen

Mitgeliefert werden:

- `json_seed`: JSON-Array, JSONL oder `{ "businesses": [...]` fuer lizenzierte Exporte und manuell vorbereitete Daten.
- `overpass`: OpenStreetMap-Recherche ueber konfigurierbare OSM-Tag-Zuordnungen.
- `official_website`: optionale Anreicherung der vom Discovery-Adapter gefundenen offiziellen Website. Verarbeitet werden die Startseite, JSON-LD, Meta-Daten und oeffentliche Kontaktlinks.

B144 und Easy sind bewusst nicht fest eingebaut. Ein direkter Crawler sollte erst ergaenzt werden, wenn die jeweilige Quelle automatisierten Zugriff und die dauerhafte Weiterverwendung der Daten ausdruecklich erlaubt. Ein neuer Adapter implementiert lediglich `SourceAdapterInterface`; Import, Normalisierung und Statusverwaltung bleiben unveraendert.

Die oeffentliche Overpass-Instanz ist standardmaessig gesperrt. Ihre Betreiber beschreiben sie als Ressource fuer kleine beziehungsweise einmalige Nutzung und empfehlen fuer regelmaessige oder kommerzielle Last eine eigene oder autorisierte Instanz: https://wiki.openstreetmap.org/wiki/Overpass_API und https://dev.overpass-api.de/overpass-doc/en/preface/commons.html. Fuer produktive OSM-Daten muessen ausserdem ODbL und Attribution geprueft werden. Der Website-Adapter beachtet RFC 9309 (`robots.txt`), doch robots.txt ersetzt keine Pruefung der Nutzungsbedingungen: https://www.rfc-editor.org/rfc/rfc9309.html.

## Konfiguration

```bash
cp config/worker.example.json config/worker.json
cp .env.example .env
```

Beispiel fuer feinere Ziele:

```json
{
  "target_per_run": 1000,
  "batch_size": 100,
  "cities": ["Jerusalem", "Tel Aviv"],
  "neighborhoods": {
    "Tel Aviv": ["Ramat Aviv", "Florentin"]
  },
  "categories": ["professionals.electricians"],
  "quotas": {
    "per_category": 100,
    "per_neighborhood": 100,
    "max_new_per_day": 1000
  }
}
```

Jede Stadt, Neighborhood und `category_key` muss exakt einem vorhandenen Sveevee-Katalogwert entsprechen. Fuer jede neue OSM-Kategorie wird unter `sources.overpass.category_tags` eine Zuordnung gepflegt. Fuer groessere regelmaessige OSM-Laeufe `OVERPASS_API_URL` auf einen selbst betriebenen oder ausdruecklich autorisierten Endpunkt setzen.

## Environment

```dotenv
SVEVEE_API_URL=https://sveevee.co.il/api/v1/business-import
SVEVEE_TOKEN_URL=https://sveevee.co.il/oauth/token
SVEVEE_CLIENT_ID=...
SVEVEE_CLIENT_SECRET=...
SVEVEE_WORKER_CONFIG=/etc/sveevee-worker/worker.json
SVEVEE_WORKER_DATA_DIR=/var/lib/sveevee-worker
SVEVEE_WORKER_USER_AGENT=SveeveeResearchWorker/1.0 (+https://sveevee.co.il; mailto:info@sveevee.co.il)
OVERPASS_API_URL=https://your-authorized-overpass.example/api/interpreter
```

Client-ID und Secret duerfen nur in `.env`, `/etc/sveevee-worker/worker.env` oder einem Secret Store liegen. Diese Dateien sind nicht Teil von Git. Token und Secret werden weder geloggt noch in SQLite gespeichert.

Die bereits erzeugte lokale Credential-Datei mit `SVEEVEE_BUSINESS_IMPORT_*` und `SVEEVEE_OAUTH_TOKEN_URL` kann ebenfalls direkt verwendet werden; diese aelteren Namen werden als Fallback gelesen. Neue Installationen sollten die oben dokumentierten `SVEVEE_*`-Namen verwenden.

## Installation auf Debian 13

### 1. Worker installieren

Nach dem Push und Pull des Repositories:

```bash
sudo apt update
sudo apt install php-cli php-sqlite3 php-curl php-xml php-mbstring php-intl rsync
cd /var/www/sveevee
sudo bash sveevee-worker/deploy/install.sh
```

Der Installer kopiert den Worker nach `/var/www/sveevee-worker`, erstellt den Systemnutzer und installiert Service und Timer. Er startet und aktiviert nichts automatisch.

### 2. OAuth-Zugangsdaten eintragen

Der bereits erzeugte Business-Import-Client kann verwendet werden. Falls ein neuer Client benoetigt wird:

```bash
cd /var/www/sveevee/backend
php artisan business-import:client "Sveevee Research Worker"
```

Dann ohne Ausgabe des Secrets an Terminal-Logs:

```bash
sudoedit /etc/sveevee-worker/worker.env
sudoedit /etc/sveevee-worker/worker.json
sudo chown root:sveevee-worker /etc/sveevee-worker/worker.env /etc/sveevee-worker/worker.json
sudo chmod 640 /etc/sveevee-worker/worker.env /etc/sveevee-worker/worker.json
```

Mindestens einen erlaubten Source-Adapter in `worker.json` aktivieren. Bei `json_seed` einen absoluten Pfad auf eine echte, lizenzierte Datei eintragen.

### 3. Dry-Run mit 10 Businesses

```bash
sudo -u sveevee-worker /var/www/sveevee-worker/bin/worker run \
  --config=/etc/sveevee-worker/worker.json \
  --env-file=/etc/sveevee-worker/worker.env \
  --limit=10 --dry-run
```

### 4. Echter Test mit 10 Businesses

```bash
sudo -u sveevee-worker /var/www/sveevee-worker/bin/worker run \
  --config=/etc/sveevee-worker/worker.json \
  --env-file=/etc/sveevee-worker/worker.env \
  --limit=10
```

### 5. Lauf mit 100 Businesses

```bash
sudo -u sveevee-worker /var/www/sveevee-worker/bin/worker run \
  --config=/etc/sveevee-worker/worker.json \
  --env-file=/etc/sveevee-worker/worker.env \
  --limit=100
```

### 6. Lauf mit 1000 Businesses

```bash
sudo -u sveevee-worker /var/www/sveevee-worker/bin/worker run \
  --config=/etc/sveevee-worker/worker.json \
  --env-file=/etc/sveevee-worker/worker.env \
  --limit=1000
```

Auch beim 1000er-Lauf bleiben die einzelnen API-Batches auf maximal 100 begrenzt.

### 7. Taegliche Ausfuehrung aktivieren

Erst nach kontrolliertem Dry-Run, echtem Test und Freigabe aller aktiven Quellen:

```bash
sudo systemctl enable --now sveevee-worker.timer
systemctl list-timers sveevee-worker.timer
```

Manueller systemd-Lauf und Kontrolle:

```bash
sudo systemctl start sveevee-worker.service
sudo journalctl -u sveevee-worker.service -n 100 --no-pager
sudo -u sveevee-worker /var/www/sveevee-worker/bin/worker status \
  --config=/etc/sveevee-worker/worker.json \
  --env-file=/etc/sveevee-worker/worker.env
```

SQLite, Logs und Reports liegen unter `/var/lib/sveevee-worker`. Diese Daten muessen erhalten bleiben, damit der Worker erfolgreiche Imports und sichere Retries kennt.

## Tests

```bash
php -d xdebug.mode=off tests/run.php
```
