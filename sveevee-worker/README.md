# Sveevee Automation/Research Worker

Der Worker laeuft unabhaengig von Frontend und Laravel-Web-Requests. Er recherchiert ueber austauschbare Source-Adapter, speichert seinen Zustand in SQLite, prueft Dubletten ueber die bestehende Business-Import-API und sendet Schreibvorgaenge in Bloecken von hoechstens 100 Businesses.

## Eigenschaften

- OAuth-2.0 Client Credentials mit automatischer Token-Erneuerung
- Lokaler, persistenter Status fuer Businesses, Identitaetssignale, Quellen-URLs, Fehler, Batches und Runs
- Persistente Round-Robin-Rotation durch die konfigurierten Stadt-Kategorie-Kombinationen
- Bis zu zehn produktive Kombinationen pro Lauf mit jeweils bis zu 100 erfolgreichen Neuanlagen oder Aktualisierungen; insgesamt hoechstens 1000
- Leere Kombinationen, unveraenderte Dubletten und unbrauchbare Kandidaten verbrauchen diese Grenzen nicht
- Ausfuehrung alle zehn Minuten ohne Tageslimit
- Getrennte Import-Batches pro Stadt-Kategorie-Kombination
- Idempotente Batch-Retries mit vor dem Request gespeicherter `client_import_id`
- Einzelne Fehler stoppen die restlichen Batch-Eintraege nicht
- Fehlende Update-Felder loeschen keine vorhandenen Daten
- Beanspruchte Seiten werden erkannt und niemals veraendert
- JSON-Reports und JSON-Line-Log nach jedem Lauf
- Idempotente Uebertragung jedes abgeschlossenen Laufs in den Admin-Tab `Logs`, mit persistenter Outbox fuer spaetere Retries
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

`research` schreibt nur in den lokalen Status. `import` verarbeitet bereits recherchierte Eintraege. `run` recherchiert und importiert innerhalb jeder Kombination, bis deren Erfolgsgrenze erreicht oder ihre Quellen ausgeschoepft sind. `--dry-run` darf die Read-Endpunkte zur Dublettenpruefung verwenden, sendet aber keinen Create-, Update- oder Batch-Request an Sveevee. Im Dry-Run zaehlen geplante Schreibvorgaenge; beim echten Import zaehlen erfolgreiche Neuanlagen und Aktualisierungen.

## Quellen

Mitgeliefert werden:

- `data_gov_ckan`: paginierte CKAN-Recherche mit Profilen fuer das landesweite Firmenregister und aktive Gewerbelizenzen aus Beersheba.
- `tel_aviv_business_licenses`: offizielle Gewerbelizenzen der Stadt Tel Aviv aus dem ArcGIS-Dienst, ebenfalls mit Pagination, Gueltigkeitspruefung und Zuordnung zu den zehn Kategorien.
- `json_seed`: JSON-Array, JSONL oder `{ "businesses": [...]` fuer lizenzierte Exporte und manuell vorbereitete Daten.
- `overpass`: OpenStreetMap-Recherche ueber konfigurierbare OSM-Tag-Zuordnungen.
- `official_website`: optionale Anreicherung der vom Discovery-Adapter gefundenen offiziellen Website. Verarbeitet werden die Startseite, JSON-LD, Meta-Daten und oeffentliche Kontaktlinks.

B144 und Easy sind bewusst nicht fest eingebaut. Ein direkter Crawler sollte erst ergaenzt werden, wenn die jeweilige Quelle automatisierten Zugriff und die dauerhafte Weiterverwendung der Daten ausdruecklich erlaubt. Ein neuer Adapter implementiert lediglich `SourceAdapterInterface`; Import, Normalisierung und Statusverwaltung bleiben unveraendert.

Die oeffentliche Overpass-Instanz ist standardmaessig gesperrt. Ihre Betreiber beschreiben sie als Ressource fuer kleine beziehungsweise einmalige Nutzung und empfehlen fuer regelmaessige oder kommerzielle Last eine eigene oder autorisierte Instanz: https://wiki.openstreetmap.org/wiki/Overpass_API und https://dev.overpass-api.de/overpass-doc/en/preface/commons.html. Fuer produktive OSM-Daten muessen ausserdem ODbL und Attribution geprueft werden. Der Website-Adapter beachtet RFC 9309 (`robots.txt`), doch robots.txt ersetzt keine Pruefung der Nutzungsbedingungen: https://www.rfc-editor.org/rfc/rfc9309.html.

### Data.gov.il / CKAN

Der Adapter liest CKAN-Ressourcen seitenweise, beim landesweiten Register eingeschraenkt auf aktive Firmen der jeweiligen Stadt. Verarbeitete Datensatz-IDs und stabile Inhalts-Hashes bleiben in SQLite gespeichert. Dadurch ueberspringt ein spaeterer Lauf unveraenderte Eintraege und setzt bei noch nicht verarbeiteten Datensaetzen fort, statt immer wieder am Anfang des Exports zu stoppen. API- und Paginationfehler werden als Fehler gemeldet und nicht als erfolgreiche Recherche mit null Treffern.

Dauerhafte Identitaetskonflikte und unveraendert unvollstaendige Quelldatensaetze werden bis zu einer Aenderung ihres Inhalts quarantiniert. Dadurch versucht der naechste Lauf dieselben nicht aufloesbaren Datensaetze nicht immer wieder.

Das Profil `beer_sheva_business_licenses` uebernimmt Name, Telefon, E-Mail, Strasse, Hausnummer, Lizenzbeschreibung, Status und Ablaufdatum. Abgelaufene oder nicht aktive Lizenzen werden verworfen. Unterstuetzt werden derzeit Restaurants, Cafes, Baeckereien, Catering, Fast Food, Lebensmittelgeschaefte, Fleischereien, Bars, Veranstaltungsorte und Hotels. Nicht eindeutig zuordenbare Lizenzarten werden nicht importiert.

Die Quelle wird intern mit URL und Pruefzeitpunkt gespeichert, aber nicht in den oeffentlichen Beschreibungstext der Business-Seite geschrieben. Eine technische Lizenz-Allowlist ist noch nicht aktiv; vor produktiven automatischen Laeufen bleibt die Nutzungs- und Lizenzpruefung daher Aufgabe des Betreibers.

### Landesweites Firmenregister

Das Profil `israel_companies` liest das [Firmenregister der israelischen Justizbehoerde](https://data.gov.il/datasets/ministry_of_justice/ica_companies), Ressource `f004176c-b85f-4542-8901-7b3176f9a054`. Die Konfiguration bildet alle 83 Staedte des Anwendungskatalogs auf die exakten Ortsnamen des Registers ab, einschliesslich vorhandener Schreibvarianten. CKAN filtert bereits auf dem Server nach diesen Ortsnamen und dem Status `פעילה`; der Worker prueft Stadt und aktiven Status nochmals pro Datensatz.

Firmenname, englischer Name und gegebenenfalls konkrete Taetigkeitsangaben werden konservativ den zehn Kategorien zugeordnet. Allgemeine Gesellschaftszwecke, unklare oder mehrdeutige Taetigkeiten sowie beispielsweise Ausruester und Holdinggesellschaften werden uebersprungen. Fuer eine Seite sind eine stabile Firmennummer, ein Name und eine Strasse erforderlich. Fehlende Telefonnummern, E-Mails oder Oeffnungszeiten werden nicht erfunden. Der Beschreibungstext kennzeichnet die Firma und ihre Registeradresse; ein aktiver Registereintrag bestaetigt keine geoeffnete Filiale oder gueltige Gewerbelizenz.

Pro Stadt werden alle gefilterten Registerzeilen paginiert und nur geeignete Kandidaten fuer die zehn Kategorien im Arbeitsspeicher behalten. Beim Stadtwechsel wird dieser Cache freigegeben. Die genaue Trefferzahl, stabile Sortierung und wiederholte Zeilen werden kontrolliert. `max_records_per_city` ist im Deployment-Profil auf 100000 gesetzt; eine groessere Ergebnismenge wird ausdruecklich als unvollstaendig gemeldet. Die Quellenkennung verwendet die Firmennummer, damit eine Neunummerierung der CKAN-Zeilen unveraenderte Firmen nicht erneut importiert.

### Tel Aviv / kommunale Gewerbelizenzen

Die zweite kommunale Quelle ist [ArcGIS-Layer 964 der Stadt Tel Aviv-Yafo](https://gisn.tel-aviv.gov.il/arcgis/rest/services/IView2/MapServer/964). Sie wird einmal pro Lauf vollstaendig paginiert und fuer alle Kategorien desselben Laufs wiederverwendet. Unterstuetzte Lizenzcodes und ergaenzende Beschreibungen werden konservativ den zehn Kategorien zugeordnet. Abgelaufene Lizenzen sowie Datensaetze ohne Name, Strasse oder stabile Geschaeftskennung werden uebersprungen. Telefonnummern und Hausnummern sind in diesem Feed nicht enthalten und werden nicht ergaenzt.

Die Quellenkennung verwendet Geschaeftsnummer, Untergeschaeftsnummer und Lizenzart; die fluechtige ArcGIS-Zeilennummer wird nur zur Pagination verwendet. Damit erzeugt eine Neunummerierung der Tabelle keine erneute Verarbeitung unveraenderter Eintraege. HTTP-, API- und Paginationfehler werden als Fehler gemeldet und nicht als leere Kombination ausgegeben.

## Konfiguration

```bash
cp config/worker.example.json config/worker.json
cp .env.example .env
```

Beispiel fuer feinere Ziele:

```json
{
  "target_per_run": 1000,
  "targets_per_run": 10,
  "businesses_per_combination": 100,
  "batch_size": 100,
  "cities": ["Jerusalem", "Tel Aviv"],
  "neighborhoods": [],
  "categories": [
    "professionals.electricians",
    "food_catering.cafes"
  ],
  "quotas": {
    "per_category": null,
    "per_neighborhood": null
  }
}
```

Der Worker bildet aus `cities` und `categories` alle Kombinationen. Der persistente Scheduler sortiert zuerst unbesuchte und danach die am laengsten nicht bearbeiteten Kombinationen. `targets_per_run` begrenzt die Anzahl produktiver Kombinationen, nicht die Anzahl gepruefter Kombinationen. Bei leeren Quellen oder ausschliesslich unbrauchbaren Kandidaten geht der Lauf zur naechsten Kombination weiter, ohne einen der zehn Plaetze zu verbrauchen. Pro Lauf wird jede konfigurierte Kombination hoechstens einmal besucht; wenn alle ausgeschoepft sind, endet der Lauf auch unterhalb von 1000.

`businesses_per_combination` begrenzt erfolgreiche Neuanlagen und Aktualisierungen pro Kombination auf 100. `target_per_run` begrenzt deren Gesamtzahl pro Lauf auf 1000. Nach Dubletten, beanspruchten Seiten oder ungueltigen Eintraegen wird weitergesucht, soweit die Quelle weitere Daten liefert. Eine Kombination mit wenigstens einem erfolgreichen Eintrag zaehlt als produktiv, auch wenn weniger als 100 verfuegbar sind. Batches enthalten ausschliesslich Eintraege derselben Kombination und hoechstens `batch_size` Eintraege. Offene Batches werden mit ihrer bestehenden Idempotenz-ID wiederholt und verbrauchen dieselben Laufgrenzen. Ein altes `quotas.max_new_per_day` wird nicht mehr angewendet; das Deployment entfernt den Schluessel.

Der Fortschritt liegt in SQLite. Neue Kombinationen werden zuerst bearbeitet, danach beginnt automatisch der naechste Umlauf bei den am laengsten nicht verarbeiteten Kombinationen. `worker status` zeigt Gesamtzahl, bereits besuchte und die als Naechstes vorgesehenen Kombinationen. Manuelle Laeufe bewegen denselben Cursor wie Timerlaeufe.

Das mitgelieferte Deployment-Profil `config/worker.rotation.json` aktiviert **alle 83 Staedte des Anwendungskatalogs mit jeweils denselben zehn Kategorien**, also 830 Kombinationen. Das landesweite Firmenregister deckt diese Staedte ab; die kommunalen Gewerbelizenzen aus Beersheba und Tel Aviv bleiben zusaetzlich aktiv. Eine unterstuetzte Kombination kann trotzdem leer sein, wenn keine aktiven, ausreichend vollstaendigen und eindeutig zuordenbaren Firmen vorliegen.

Die Reports enthalten weiterhin `target_combinations` und zusaetzlich `scanned_target_combinations`, `productive_target_combinations`, `empty_target_combinations` und `unproductive_target_combinations`. Je Ziel werden `found`, `successful` und `planned` gespeichert. Auch die Admin-Logs erhalten diese Werte. So ist sichtbar, ob ein Lauf zehn produktive Kombinationen erreicht oder zusaetzlich leere Kombinationen geprueft hat.

Beispiel fuer eine einzelne kommunale Quelle (fuer alle Katalogstaedte das Deployment-Profil verwenden):

```json
{
  "target_per_run": 1000,
  "targets_per_run": 10,
  "businesses_per_combination": 100,
  "batch_size": 100,
  "cities": ["Beersheba"],
  "neighborhoods": [],
  "categories": [
    "food_catering.restaurants",
    "food_catering.cafes",
    "food_catering.bakery",
    "professionals.catering",
    "professionals.fast_food",
    "professionals.grocery_food",
    "food_catering.meat_deli",
    "food_catering.bars",
    "professionals.venues",
    "travel_leisure.hotels_guesthouses"
  ],
  "sources": {
    "data_gov_ckan": {
      "enabled": true,
      "api_url": "https://data.gov.il/api/3/action",
      "page_size": 1000,
      "max_records_per_dataset": 50000,
      "refresh_after_days": 365,
      "datasets": [
        {
          "profile": "beer_sheva_business_licenses",
          "resource_id": "7d4c61e2-2416-453e-8efb-bd02ec89db35",
          "city": "Beersheba",
          "city_label": "באר שבע",
          "active_statuses": [6, 7, 8]
        }
      ]
    }
  }
}
```

Jede Stadt und jeder `category_key` muss exakt einem vorhandenen Sveevee-Katalogwert entsprechen. Fuer den aktuellen Stadt-Kategorie-Ablauf bleibt `neighborhoods` leer. Fuer jede neue OSM-Kategorie wird unter `sources.overpass.category_tags` eine Zuordnung gepflegt. Fuer groessere regelmaessige OSM-Laeufe `OVERPASS_API_URL` auf einen selbst betriebenen oder ausdruecklich autorisierten Endpunkt setzen.

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

Bei einer bestehenden Installation zuerst den Timer anhalten und warten, bis der aktuelle Import beendet ist:

```bash
sudo systemctl stop sveevee-worker.timer
systemctl show sveevee-worker.service --property=ActiveState --property=SubState
```

Erst bei `ActiveState=inactive` (oder nach einem bereits fehlgeschlagenen Lauf) installieren. Der Installer verweigert ein Update, solange Timer oder Worker aktiv sind, damit keine PHP-Dateien waehrend eines Imports ersetzt werden.

Vor dem Update eine konsistente Sicherung von `/var/lib/sveevee-worker/worker.sqlite` anlegen. Beim ersten Start ergaenzt der Worker lokale Metadaten fuer offene Import-Batches; vorhandene Businesses, Laufpositionen und Batch-IDs bleiben erhalten.

```bash
sudo apt update
sudo apt install php-cli php-sqlite3 php-curl php-xml php-mbstring php-intl rsync
cd /var/www/sveevee
sudo bash sveevee-worker/deploy/install.sh
```

Der Installer kopiert den Worker nach `/var/www/sveevee-worker`, erstellt den Systemnutzer und installiert Service und Timer. Er startet und aktiviert nichts automatisch.

Der Installer sichert ein vorhandenes `/etc/systemd/system/sveevee-worker.timer.d/schedule.conf` unter `/var/backups/sveevee` und ersetzt es durch den Zehn-Minuten-Zeitplan. Damit bleiben alte taegliche oder stuendliche Overrides nicht versehentlich wirksam.

Das Profil fuer alle Katalogstaedte zuerst anzeigen, dann anwenden:

```bash
sudo php /var/www/sveevee-worker/deploy/configure-rotation.php
sudo php /var/www/sveevee-worker/deploy/configure-rotation.php --apply
```

Die Umstellung setzt 1000 Eintraege pro Lauf, zehn produktive Kombinationen, 100 Eintraege pro Kombination sowie alle 83 Katalogstaedte mit dem landesweiten Register und den beiden kommunalen Quellen. Sie entfernt das Tageslimit, sichert die bestehende `worker.json` und erhaelt API-Einstellungen, Speicherpfade und andere installationsspezifische Einstellungen. Ohne `--apply` wird die bestehende Konfiguration nicht veraendert. Zugangsdaten aus `worker.env` werden weder gelesen noch ausgegeben.

Auch das Laravel-Backend mitdeployen: Es nimmt die neuen Log-Zaehler an und setzt das Standardlimit der Import-API auf 7200 Requests pro Stunde bei weiterhin 120 pro Minute. Sechs volle Laeufe benoetigen bereits etwa 6000 Dublettenpruefungen zuzueglich Batch- und Log-Requests. Falls `/var/www/sveevee/backend/.env` noch explizit `BUSINESS_IMPORT_REQUESTS_PER_HOUR=5000` setzt, den Wert beim Update auf `7200` aendern und den Laravel-Konfigurationscache erneuern. Das ist eine Request-Grenze, kein Tageslimit fuer angelegte Seiten.

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

Auch beim 1000er-Lauf bleiben die einzelnen API-Batches auf maximal 100 begrenzt und enthalten nur Businesses derselben Stadt-Kategorie-Kombination. 1000 ist ein Maximum: Eine Kombination mit weniger neuen Quelldaten, Dubletten oder nicht aufloesbaren Datensaetzen erzeugt entsprechend weniger neue Seiten.

### 7. Ausfuehrung alle zehn Minuten aktivieren

Erst nach kontrolliertem Dry-Run, echtem Test und Freigabe aller aktiven Quellen:

```bash
sudo systemctl enable --now sveevee-worker.timer
sudo systemctl restart sveevee-worker.timer
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

Der Timer ist auf Minute 00, 10, 20, 30, 40 und 50 in `Asia/Jerusalem` eingestellt, ohne Zufallsverzoegerung. Dauert ein Lauf laenger als zehn Minuten, startet keine zweite Instanz parallel; systemd und die Prozesssperre verhindern Ueberlappungen. `Persistent=true` bleibt aktiv.

SQLite, Logs und Reports liegen unter `/var/lib/sveevee-worker`. Diese Daten muessen erhalten bleiben, damit der Worker erfolgreiche Imports, sichere Batch-Retries und noch nicht an den Admin-Log uebertragene Laufberichte kennt.

## Tests

```bash
php -d xdebug.mode=off tests/run.php
php -d xdebug.mode=off tests/deploy-config.php
php -d xdebug.mode=off tests/tel_aviv_source.php
php -d xdebug.mode=off tests/company_categories.php
php -d xdebug.mode=off tests/companies_source.php
```
