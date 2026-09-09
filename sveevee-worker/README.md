# Sveevee Automation/Research Worker

Der Worker laeuft unabhaengig von Frontend und Laravel-Web-Requests. Er recherchiert ueber austauschbare Source-Adapter, speichert seinen Zustand in SQLite, prueft Dubletten ueber die bestehende Business-Import-API und sendet Schreibvorgaenge in Bloecken von hoechstens 100 Businesses.

## Eigenschaften

- OAuth-2.0 Client Credentials mit automatischer Token-Erneuerung
- Lokaler, persistenter Status fuer Businesses, Identitaetssignale, Quellen-URLs, Fehler, Batches und Runs
- Persistente Round-Robin-Rotation durch die konfigurierten Stadt-Kategorie-Kombinationen
- Getrennte Gov- und Tel-Aviv-Jobs: jeweils eine produktive Stadt/Kategorie-Kombination und hoechstens 10 erfolgreiche Neuanlagen oder Aktualisierungen pro Lauf
- Gov und Tel Aviv haben jeweils ein eigenes Budget von hoechstens 10 Quell-HTTP-Abfragen pro Lauf, mit 10er-Seiten, ohne Retries und mindestens zwei Sekunden Abstand
- Separater Overture-Job: hoechstens 9000 erfolgreiche Neuanlagen oder Aktualisierungen ueber die konfigurierten Kombinationen
- Leere Kombinationen, unveraenderte Dubletten und unbrauchbare Kandidaten verbrauchen diese Grenzen nicht
- Gov und Tel Aviv jeweils alle zehn Minuten, um fuenf Minuten versetzt; Overture weiterhin alle 30 Minuten, jeweils ohne Tageslimit
- Getrennte Import-Batches pro Stadt-Kategorie-Kombination
- Idempotente Batch-Retries mit vor dem Request gespeicherter `client_import_id`
- Einzelne Fehler stoppen die restlichen Batch-Eintraege nicht
- Fehlende Update-Felder loeschen keine vorhandenen Daten
- Beanspruchte Seiten werden erkannt und niemals veraendert
- Eigene JSON-Reports, JSON-Line-Logs und Admin-Logeintraege fuer jeden Job und Lauf
- Idempotente Uebertragung jedes abgeschlossenen Laufs in den Admin-Tab `Logs`, mit persistenter Outbox fuer spaetere Retries
- Getrennte Datenbanken, Fortschrittsstaende und Prozesssperren je Job; keine parallelen Instanzen desselben Jobs
- `robots.txt`, Rate Limits, HTTPS-Pruefung und Schutz vor Requests in private Netze beim Website-Enrichment

Die Quellenfelder `source_name`, `source_url` und `source_checked_at` bleiben mitsamt einem Hash und dem recherchierten Rohobjekt im lokalen SQLite. Die vorhandene Sveevee-API akzeptiert diese Felder nicht; der Worker entfernt sie daher aus dem API-Payload.

## CLI

```bash
./bin/worker research
./bin/worker import
./bin/worker run
./bin/worker status
./bin/worker retry-failed

./bin/worker run --limit=10
./bin/worker run --dry-run --limit=10
```

`research` schreibt nur in den lokalen Status. `import` verarbeitet bereits recherchierte Eintraege. `run` recherchiert und importiert innerhalb jeder Kombination, bis deren Erfolgsgrenze erreicht oder ihre Quellen ausgeschoepft sind. `--dry-run` darf die Read-Endpunkte zur Dublettenpruefung verwenden, sendet aber keinen Create-, Update- oder Batch-Request an Sveevee. Im Dry-Run zaehlen geplante Schreibvorgaenge; beim echten Import zaehlen erfolgreiche Neuanlagen und Aktualisierungen.

## Quellen

Mitgeliefert werden:

- `overture_places`: lokal vorbereitete Israel-Daten aus Overture Maps Places mit Kategorien, Adressen und vorhandenen Kontaktdaten. Diese Quelle laeuft ausschliesslich im separaten Overture-Profil.
- `data_gov_ckan`: paginierte CKAN-Recherche mit Profilen fuer das landesweite Firmenregister und aktive Gewerbelizenzen aus Beersheba.
- `tel_aviv_business_licenses`: offizielle Gewerbelizenzen der Stadt Tel Aviv aus dem ArcGIS-Dienst, ebenfalls mit Pagination, Gueltigkeitspruefung und Zuordnung zu den zehn Kategorien.
- `json_seed`: JSON-Array, JSONL oder `{ "businesses": [...]` fuer lizenzierte Exporte und manuell vorbereitete Daten.
- `overpass`: OpenStreetMap-Recherche ueber konfigurierbare OSM-Tag-Zuordnungen.
- `official_website`: optionale Anreicherung der vom Discovery-Adapter gefundenen offiziellen Website. Verarbeitet werden die Startseite, JSON-LD, Meta-Daten und oeffentliche Kontaktlinks.

B144 und Easy sind bewusst nicht fest eingebaut. Ein direkter Crawler sollte erst ergaenzt werden, wenn die jeweilige Quelle automatisierten Zugriff und die dauerhafte Weiterverwendung der Daten ausdruecklich erlaubt. Ein neuer Adapter implementiert lediglich `SourceAdapterInterface`; Import, Normalisierung und Statusverwaltung bleiben unveraendert.

Die oeffentliche Overpass-Instanz ist standardmaessig gesperrt. Ihre Betreiber beschreiben sie als Ressource fuer kleine beziehungsweise einmalige Nutzung und empfehlen fuer regelmaessige oder kommerzielle Last eine eigene oder autorisierte Instanz: https://wiki.openstreetmap.org/wiki/Overpass_API und https://dev.overpass-api.de/overpass-doc/en/preface/commons.html. Fuer produktive OSM-Daten muessen ausserdem ODbL und Attribution geprueft werden. Der Website-Adapter beachtet RFC 9309 (`robots.txt`), doch robots.txt ersetzt keine Pruefung der Nutzungsbedingungen: https://www.rfc-editor.org/rfc/rfc9309.html.

### Overture Places

Die offiziellen [GeoParquet-Downloads](https://docs.overturemaps.org/getting-data/cloud-sources/) sind ohne Konto, API-Schluessel oder kostenpflichtigen Abfragedienst zugaenglich. Speicher, Datenverkehr und Verarbeitung laufen auf dem eigenen Rechner/Server. Fuer die Vorbereitung wird zusaetzlich die [DuckDB CLI](https://duckdb.org/docs/stable/clients/cli/overview) benoetigt (getestet mit 1.5.5); die normalen PHP-Importlaeufe brauchen DuckDB nicht.

`bin/prepare-overture.php` liest den offiziellen STAC-Katalog fuer die gewaehlte Ausgabe, ermittelt alle Partitionen im Israel-Fenster (34–36 Grad Ost, 29–34 Grad Nord) und exportiert ausschliesslich Datensaetze mit einer IL-Adresse. Auch die konkret uebernommene Adresse muss `country=IL` tragen. Ortsnamen werden exakt auf die konfigurierten Katalogstaedte und ihre expliziten Varianten abgebildet. Unbekannte Orte werden uebersprungen; es gibt keine Zuordnung zur naechstgelegenen Stadt. Die zehn bestehenden Kategorien werden aus der primaeren Overture-Taxonomie abgeleitet. Private Unterkuenfte, institutionelle Kantinen und unklare Kategorien werden ausgeschlossen.

Der konfigurierbare Startwert `min_confidence=0.75` filtert schwaechere Existenzsignale. Er bestaetigt weder die Aktualitaet der Kontaktdaten noch einen geoeffneten Betrieb. Als geschlossen markierte Eintraege werden ausgeschlossen; ein fehlender Betriebsstatus bleibt unbekannt. Erforderlich sind Name, eine Adresse mit Strasse und bekannte Stadt/Kategorie. Vorhandene Telefonnummern, E-Mails, Websites und unterstuetzte Social-Media-Links werden validiert; fehlende Kontakte, Bilder und Oeffnungszeiten werden nicht erfunden.

Vorbereitung aus dem Worker-Verzeichnis:

```bash
php bin/prepare-overture.php --config=config/worker.overture.json --duckdb=/path/to/duckdb
```

Die konfigurierte Ausgabe ist fuer einen reproduzierbaren ersten Import auf `2026-08-19.0` festgelegt. Ohne `database_path` liegt die fertige Datei neben der Overture-Worker-Datenbank als `overture.sqlite`. `SVEVEE_WORKER_DATA_DIR` und anschliessend `storage.data_subdirectory=overture` werden dabei beruecksichtigt; auf Live ist das `/var/lib/sveevee-worker/overture/overture.sqlite`. `--output` waehlt einen anderen Pfad. Fuer einen bereits vorhandenen lokalen Parquet-Ausschnitt:

```bash
php bin/prepare-overture.php --config=config/worker.overture.json \
  --parquet=/path/to/israel-places.parquet --release=2026-08-19.0 \
  --duckdb=/path/to/duckdb --output=/path/to/overture.sqlite
```

Fuer eine neue monatliche Ausgabe kann derselbe Befehl mit `--release=latest` oder einer expliziten Versionsnummer ausgefuehrt werden. Die Quelldatei wird getrennt von den halbstuendlichen Imports erneuert; der Import selbst loest keinen erneuten Download aus. Der Vorbereitungsbefehl importiert keine Seiten und startet keinen Timer.

Der Export ist auf 768 MB DuckDB-Arbeitsspeicher, 300000 vorausgewaehlte Zeilen, 256 MiB JSONL und 30 Minuten begrenzt. Die `httpfs`-Erweiterung wird bei einem Download im Ausgabeordner installiert. Eine neue SQLite-Datei wird vollstaendig aufgebaut, auf Zeilenzahl und Integritaet geprueft und erst dann atomar veroeffentlicht. Leere, abgeschnittene, doppelte oder fehlerhafte Exporte ersetzen keinen bestehenden Datenstand. Auch ein aelterer Stand oder ein Rueckgang um mehr als die Haelfte wird abgewiesen und kann zunaechst unter einem anderen `--output` untersucht werden.

Der PHP-Adapter oeffnet die fertige Datei schreibgeschuetzt und liest ueber einen Stadt/Kategorie-Index. Stabile Overture-GERS-IDs bilden die Quellenlinks. Das Overture-Profil erlaubt bis zu 9000 erfolgreiche Eintraege pro Lauf, auch aus einer einzigen Kombination, und kann bis zu allen 830 Kombinationen fortschreiten. Jeder API-Schreibrequest enthaelt trotzdem hoechstens 100 Eintraege. Versions- und Lizenzmetadaten bleiben in `source_metadata` erhalten und veraendern allein keinen Business-Inhaltshash. Die Event-Spalte im Admin-Log zeigt `Overture Places`.

Gemeinsame Telefonnummern, E-Mail-Adressen, Namen und Website-Domains sperren keine anderen Filialen mehr. Bekannte unterschiedliche Staedte, Strassen oder Hausnummern ergeben separate Businesses und Seiten. Derselbe bereinigte Name am selben Standort kann aus mehreren Quellen angereichert werden; eine bestaetigte Standortuebereinstimmung hat Vorrang vor gemeinsamen Kontaktdaten. Unvollstaendige oder mehrdeutige Adressen werden nicht zufaellig einer Filiale zugeordnet. Die lokale SQLite-Migration macht Kontaktschluessel nicht exklusiv und erhaelt bestehende IDs, Quellen und unveraenderliche Batchanfragen. Die Backend-Migration ergaenzt einen Standortindex auch fuer bestehende Seiten. Beanspruchte Seiten bleiben geschuetzt.

Places verwendet je nach Zulieferer CDLA-Permissive 2.0, Apache 2.0 oder CC0. Overture-Provenienz und Ausgabe bleiben im Snapshot und im Worker-Rohdatensatz erhalten. Die mitgelieferten Lizenztexte, der vollstaendige Foursquare-Hinweis und die Beschreibung unserer Datenanpassungen liegen unter `frontend/public/data-sources/` und sind ueber den Footer erreichbar. Beim Rollout muessen diese Frontend-Dateien zusammen mit dem Worker veroeffentlicht werden. [Offizielle Quellen-/Lizenzliste](https://docs.overturemaps.org/attribution/#places).

Bei einer bestehenden Live-Installation nach dem Push/Pull und der Worker-Installation zuerst DuckDB bereitstellen und den Snapshot erzeugen, bevor das Rotationsprofil aktiviert wird:

```bash
sudo runuser -u sveevee-worker -- php /var/www/sveevee-worker/bin/prepare-overture.php \
  --config=/etc/sveevee-worker/worker.overture.json \
  --env-file=/etc/sveevee-worker/worker.env --duckdb=/usr/local/bin/duckdb
```

Bei der erstmaligen Installation bleiben die Import-Timer bis zum abgeschlossenen Test angehalten. Eine fehlende oder beschaedigte Overture-Datei wird als Quellenfehler gemeldet.

### Data.gov.il / CKAN

Der Adapter liest CKAN-Ressourcen seitenweise, beim landesweiten Register eingeschraenkt auf aktive Firmen der jeweiligen Stadt. Verarbeitete Datensatz-IDs und stabile Inhalts-Hashes bleiben in SQLite gespeichert. Dadurch ueberspringt ein spaeterer Lauf unveraenderte Eintraege und setzt bei noch nicht verarbeiteten Datensaetzen fort, statt immer wieder am Anfang des Exports zu stoppen. API- und Paginationfehler werden als Fehler gemeldet und nicht als erfolgreiche Recherche mit null Treffern.

CKAN und Tel Aviv werden in getrennten Jobs mit `page_size=10`, `max_retries=0` und `min_interval_seconds=2.0` abgefragt. `research.max_http_requests_per_run=10` begrenzt die Quell-HTTP-Abfragen jedes Jobs fuer seinen gesamten Lauf, einschliesslich aller Staedte, Kategorien und Ressourcen. Gov und Tel Aviv haben jeweils ein eigenes Zehnerbudget. Auch leere Abfragen verbrauchen dieses Budget. Abfragen an Sveevees Import-API und der separate Overture-Job zaehlen nicht dazu. Jeder Gov- oder Tel-Aviv-Importlauf schreibt hoechstens zehn Eintraege aus einer produktiven Kombination in Paketen von maximal zehn.

Der erste HTTP-, API- oder Paginationfehler pausiert die betroffene Quelle fuer den Rest ihres Laufs. Bei einem Fehler der ersten Abfrage entsteht damit nur eine Quellabfrage und ein Quellenfehler in diesem Job. Der andere Job hat einen eigenen Zeitplan und bleibt davon unabhaengig. Sobald das jeweilige Budget verbraucht ist, werden in diesem Lauf keine weiteren Quell-HTTP-Abfragen gestartet. Diese Laufbegrenzung deaktiviert keinen Timer dauerhaft; der naechste Lauf hat wieder ein eigenes Budget.

Dauerhafte Identitaetskonflikte und unveraendert unvollstaendige Quelldatensaetze werden bis zu einer Aenderung ihres Inhalts quarantiniert. Dadurch versucht der naechste Lauf dieselben nicht aufloesbaren Datensaetze nicht immer wieder.

Das Profil `beer_sheva_business_licenses` uebernimmt Name, Telefon, E-Mail, Strasse, Hausnummer, Lizenzbeschreibung, Status und Ablaufdatum. Abgelaufene oder nicht aktive Lizenzen werden verworfen. Unterstuetzt werden derzeit Restaurants, Cafes, Baeckereien, Catering, Fast Food, Lebensmittelgeschaefte, Fleischereien, Bars, Veranstaltungsorte und Hotels. Nicht eindeutig zuordenbare Lizenzarten werden nicht importiert.

Die Quelle wird intern mit URL und Pruefzeitpunkt gespeichert, aber nicht in den oeffentlichen Beschreibungstext der Business-Seite geschrieben. Eine technische Lizenz-Allowlist ist noch nicht aktiv; vor produktiven automatischen Laeufen bleibt die Nutzungs- und Lizenzpruefung daher Aufgabe des Betreibers.

### Landesweites Firmenregister

Das Profil `israel_companies` liest das [Firmenregister der israelischen Justizbehoerde](https://data.gov.il/datasets/ministry_of_justice/ica_companies), Ressource `f004176c-b85f-4542-8901-7b3176f9a054`. Die Konfiguration bildet alle 83 Staedte des Anwendungskatalogs auf die exakten Ortsnamen des Registers ab, einschliesslich vorhandener Schreibvarianten. CKAN filtert bereits auf dem Server nach diesen Ortsnamen und dem Status `פעילה`; der Worker prueft Stadt und aktiven Status nochmals pro Datensatz.

Firmenname, englischer Name und gegebenenfalls konkrete Taetigkeitsangaben werden konservativ den zehn Kategorien zugeordnet. Allgemeine Gesellschaftszwecke, unklare oder mehrdeutige Taetigkeiten sowie beispielsweise Ausruester und Holdinggesellschaften werden uebersprungen. Fuer eine Seite sind eine stabile Firmennummer, ein Name und eine Strasse erforderlich. Fehlende Telefonnummern, E-Mails oder Oeffnungszeiten werden nicht erfunden. Der Beschreibungstext kennzeichnet die Firma und ihre Registeradresse; ein aktiver Registereintrag bestaetigt keine geoeffnete Filiale oder gueltige Gewerbelizenz.

Validierte Quellseiten und Rohdatensatz-IDs werden atomar in der Worker-SQLite gespeichert. Je Quelle und Stadt bleiben der naechste Offset und die exakte Gesamtzahl erhalten. Der Adapter gibt zuerst passende gespeicherte Zeilen aus und laedt danach die naechste Zehnerseite, soweit das HTTP-Budget seines Jobs reicht. Verschiedene Kategorien nutzen denselben Stadtscan, auch fuer Kandidaten aus frueheren Seiten. Budgetende oder HTTP-Fehler behalten den letzten erfolgreich gespeicherten Offset; spaetere Laeufe setzen dort fort. Vollstaendige Scans werden standardmaessig nach 24 Stunden erneuert, unvollstaendige bleiben bis zum Abschluss erhalten. Inkonsistente Pagination verwirft den betroffenen Cache mit einem ausdruecklichen Fehler. Die genaue Trefferzahl, stabile Sortierung und wiederholte Zeilen werden kontrolliert. `max_records_per_city` bleibt als Gesamtgrenze eines Stadtscans auf 100000 gesetzt; diese Zahl ist kein Abfragebudget pro Lauf. Die Quellenkennung verwendet die Firmennummer, damit eine Neunummerierung der CKAN-Zeilen unveraenderte Firmen nicht erneut importiert.

### Tel Aviv / kommunale Gewerbelizenzen

Die zweite kommunale Quelle ist [ArcGIS-Layer 964 der Stadt Tel Aviv-Yafo](https://gisn.tel-aviv.gov.il/arcgis/rest/services/IView2/MapServer/964). Sie laeuft ausschliesslich im eigenen Tel-Aviv-Profil mit zehn Kategorien in Tel Aviv. Auch sie nutzt gespeicherte Zehnerseiten mit fortsetzbarem Offset und hat ein eigenes Budget von zehn Quell-HTTP-Abfragen pro Lauf. Die anfaengliche ArcGIS-Zaehlerabfrage zaehlt dazu und bleibt ebenfalls gespeichert. Bereits gelesene Seiten stehen allen Kategorien zur Verfuegung. Unterstuetzte Lizenzcodes und ergaenzende Beschreibungen werden konservativ den zehn Kategorien zugeordnet. Abgelaufene Lizenzen sowie Datensaetze ohne Name, Strasse oder stabile Geschaeftskennung werden uebersprungen. Telefonnummern und Hausnummern sind in diesem Feed nicht enthalten und werden nicht ergaenzt.

Die Quellenkennung verwendet Geschaeftsnummer, Untergeschaeftsnummer und Lizenzart; die fluechtige ArcGIS-Zeilennummer wird nur zur Pagination verwendet. Damit erzeugt eine Neunummerierung der Tabelle keine erneute Verarbeitung unveraenderter Eintraege. HTTP-, API- und Paginationfehler werden als Fehler gemeldet und nicht als leere Kombination ausgegeben.

## Konfiguration

```bash
cp config/worker.example.json config/worker.json
cp .env.example .env
```

Beispiel fuer feinere Ziele:

```json
{
  "target_per_run": 10,
  "targets_per_run": 1,
  "businesses_per_combination": 10,
  "batch_size": 10,
  "research": {"max_http_requests_per_run": 10},
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

Der Worker bildet aus `cities` und `categories` alle Kombinationen. Der persistente Scheduler sortiert zuerst unbesuchte und danach die am laengsten nicht bearbeiteten Kombinationen. `targets_per_run` begrenzt die Anzahl produktiver Kombinationen, nicht die Anzahl gepruefter Kombinationen. Leere Quellen oder ausschliesslich unbrauchbare Kandidaten verbrauchen keinen produktiven Platz. Pro Lauf wird jede konfigurierte Kombination hoechstens einmal besucht; wenn alle ausgeschoepft sind, endet der Lauf unterhalb seines konfigurierten Maximums.

`businesses_per_combination` begrenzt erfolgreiche Neuanlagen und Aktualisierungen pro Kombination; `target_per_run` begrenzt deren Gesamtzahl pro Lauf. Gov und Tel Aviv verwenden jeweils 10/1/10 fuer Gesamtzahl/produktive Kombinationen/pro Kombination, Overture bleibt bei 9000/830/9000. Nach Dubletten, beanspruchten Seiten oder ungueltigen Eintraegen wird weitergesucht, soweit die Quelle weitere Daten liefert und ihr HTTP-Budget noch nicht erschoepft ist. Eine Kombination mit wenigstens einem erfolgreichen Eintrag zaehlt als produktiv, auch wenn weniger als das Maximum verfuegbar sind. Batches enthalten ausschliesslich Eintraege derselben Kombination: maximal zehn bei Gov und Tel Aviv und weiterhin maximal 100 bei Overture. Offene Batches werden mit ihrer bestehenden Idempotenz-ID wiederholt und verbrauchen dieselben Laufgrenzen. Ein bestehender groesserer Gov-Batch wird unveraendert zurueckgestellt, wenn er nicht ins neue Zehnerbudget passt; vor einem Rollout daher offene Alt-Batches pruefen. Ein altes `quotas.max_new_per_day` wird nicht mehr angewendet; das Deployment entfernt den Schluessel.

Der Fortschritt liegt in SQLite. Neue Kombinationen werden zuerst bearbeitet, danach beginnt automatisch der naechste Umlauf bei den am laengsten nicht verarbeiteten Kombinationen. `worker status` zeigt Gesamtzahl, bereits besuchte und die als Naechstes vorgesehenen Kombinationen. Manuelle Laeufe bewegen denselben Cursor wie Timerlaeufe.

Das mitgelieferte Gov-Profil `config/worker.rotation.json` aktiviert **alle 83 Staedte des Anwendungskatalogs mit jeweils denselben zehn Kategorien**, also 830 Kombinationen, und ausschliesslich `data_gov_ckan`. Das landesweite Firmenregister deckt diese Staedte ab; die kommunalen Gewerbelizenzen aus Beersheba bleiben zusaetzlich aktiv. Eine unterstuetzte Kombination kann trotzdem leer sein, wenn keine aktiven, ausreichend vollstaendigen und eindeutig zuordenbaren Firmen vorliegen. Das neue Profil `config/worker.tel-aviv.json` aktiviert nur `tel_aviv_business_licenses`, die Stadt Tel Aviv und dieselben zehn Kategorien.

Das separate Profil `config/worker.overture.json` nutzt weiterhin alle 83 Staedte und zehn Kategorien und aktiviert nur Overture. `storage.data_subdirectory` wird nach dem gemeinsamen `SVEVEE_WORKER_DATA_DIR` angewendet. Zugelassen sind der leere Namespace fuer Gov sowie `tel-aviv` und `overture`. Damit bleiben Datenbank, offene Batches, Laufposition, Quellcache, Reports, Log, Admin-Log-Outbox und Lock getrennt, auch wenn alle drei Services dieselbe Credential-Datei laden.

| Job | Installierte Konfiguration | Datenverzeichnis bei `SVEVEE_WORKER_DATA_DIR=/var/lib/sveevee-worker` | Systemd-Service |
| --- | --- | --- | --- |
| Gov / data.gov.il | `/etc/sveevee-worker/worker.json` | `/var/lib/sveevee-worker` | `sveevee-worker.service` |
| Tel Aviv | `/etc/sveevee-worker/worker.tel-aviv.json` | `/var/lib/sveevee-worker/tel-aviv` | `sveevee-tel-aviv.service` |
| Overture Places | `/etc/sveevee-worker/worker.overture.json` | `/var/lib/sveevee-worker/overture` | `sveevee-overture.service` |

In jedem Datenverzeichnis liegen `worker.sqlite`, `worker.lock`, `reports/` und `logs/worker.log`. Gov behaelt seinen bisherigen Zustand; die neue Tel-Aviv-Datenbank beginnt mit eigenem Cursor und prueft vorhandene Seiten ueber die Import-API. Die vorhandene Overture-Datenbank und ihr Zeitplan bleiben erhalten. Die alte gemeinsame Datenbank nicht in den neuen Tel-Aviv-Pfad kopieren: offene Batchanfragen und ihre Idempotenz-IDs gehoeren weiterhin zu ihrem bisherigen Zustand. Alte Reports und noch ausstehende Admin-Logs bleiben unveraendert als Historie erhalten.

Gov- und Tel-Aviv-Profile verarbeiten vorhandene lokale Kandidaten nur, wenn mindestens eine ihrer Quellen im jeweiligen Job aktiv ist. Das gilt auch fuer `import`, `--dry-run` und `retry-failed`. Enthaelt ein offener Alt-Batch mindestens einen Kandidaten ohne aktive Jobquelle, bleibt der gesamte Batch mit unveraenderter Anfrage, Idempotenz-ID, Status und Versuchszahl zur Pruefung erhalten. Der Lauf meldet `source_scope_deferred` mit der Batch-ID und verarbeitet danach passende eigene Kandidaten weiter. Historische Unternehmen und Cursor werden bei der Trennung weder geloescht noch neu nummeriert.

Die Reports enthalten weiterhin `target_combinations` und zusaetzlich `scanned_target_combinations`, `productive_target_combinations`, `empty_target_combinations` und `unproductive_target_combinations`. Je Ziel werden `found`, `successful` und `planned` gespeichert. Auch die Admin-Logs erhalten diese Werte sowie die verwendeten Quellen. Jeder Job erzeugt eine eigene Logzeile mit seiner Quellenkennung; Gov und Tel Aviv stehen damit getrennt im Admin-Log, auch bei einem Lauf ohne Treffer oder mit Quellenfehler. `source_requests` und `source_errors` zaehlen ausschliesslich den jeweiligen Gov- oder Tel-Aviv-Lauf.

Beispiel fuer eine einzelne kommunale Quelle (fuer alle Katalogstaedte das Deployment-Profil verwenden):

```json
{
  "target_per_run": 10,
  "targets_per_run": 1,
  "businesses_per_combination": 10,
  "batch_size": 10,
  "research": {"max_http_requests_per_run": 10},
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
      "page_size": 10,
      "max_retries": 0,
      "min_interval_seconds": 2.0,
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

Bei einer bestehenden Installation zuerst die bereits installierten Timer anhalten und warten, bis die aktuellen Imports beendet sind. Den Tel-Aviv-Timer erst hinzunehmen, wenn er bereits installiert ist:

```bash
sudo systemctl stop sveevee-worker.timer sveevee-overture.timer
if systemctl cat sveevee-tel-aviv.timer >/dev/null 2>&1; then
  sudo systemctl stop sveevee-tel-aviv.timer
fi
systemctl show sveevee-worker.service sveevee-overture.service --property=ActiveState --property=SubState
systemctl show sveevee-tel-aviv.service --property=LoadState --property=ActiveState --property=SubState
```

Erst bei `ActiveState=inactive` (oder nach einem bereits fehlgeschlagenen Lauf) installieren. Der Installer verweigert ein Update, solange Timer oder Worker aktiv sind, damit keine PHP-Dateien waehrend eines Imports ersetzt werden.

Vor dem Update konsistente Sicherungen der vorhandenen Worker-SQLite-Datenbanken anlegen. Beim ersten Start migriert der Worker die Standortschluessel und ergaenzt lokale Metadaten fuer offene Import-Batches; vorhandene Businesses, Laufpositionen und Batch-IDs bleiben erhalten.

```bash
sudo apt update
sudo apt install php-cli php-sqlite3 php-curl php-xml php-mbstring php-intl rsync
cd /var/www/sveevee
sudo bash sveevee-worker/deploy/install.sh
```

Der Installer kopiert den Worker nach `/var/www/sveevee-worker`, erstellt den Systemnutzer und installiert die drei Services mit ihren drei Timern sowie das neue Tel-Aviv-Datenverzeichnis. Er verweigert ein Update, solange einer der Jobs oder Timer aktiv ist, und startet oder aktiviert nichts automatisch.

Der Installer sichert ein vorhandenes `/etc/systemd/system/sveevee-worker.timer.d/schedule.conf` unter `/var/backups/sveevee` und ersetzt es durch den Zehn-Minuten-Zeitplan. Damit bleiben alte taegliche oder stuendliche Overrides nicht versehentlich wirksam.

Das Profil fuer alle Katalogstaedte zuerst anzeigen, dann anwenden:

```bash
sudo php /var/www/sveevee-worker/deploy/configure-rotation.php
sudo php /var/www/sveevee-worker/deploy/configure-rotation.php --apply
```

Die Umstellung richtet `worker.json` fuer Gov mit ausschliesslich CKAN und `worker.tel-aviv.json` fuer die kommunalen Tel-Aviv-Lizenzen ein. Beide erhalten jeweils zehn Eintraege, eine produktive Kombination, Zehnerseiten, ein eigenes Budget von hoechstens zehn Quell-HTTP-Abfragen, keine Retries und zwei Sekunden Abstand. Die Tel-Aviv-Konfiguration nutzt den eigenen Namespace `tel-aviv`. Eine bereits vorhandene `worker.overture.json` bleibt vollstaendig erhalten. Nur wenn sie fehlt, wird sie mit dem bisherigen Overture-Profil erzeugt: 9000 Eintraege, alle 830 Kombinationen, ausschliesslich Overture und `api.request_interval_ms=150`.

Alle drei Konfigurationen werden vor dem Schreiben validiert und zusammen veroeffentlicht; scheitert eine Veroeffentlichung, werden bereits geschriebene Dateien zurueckgesetzt. Vorhandene Konfigurationen werden gesichert und Zugangseinstellungen erhalten. Gov behaelt seine Geschwindigkeit fuer Sveevee-API-Aufrufe. Das Gov-/Tel-HTTP-Budget wird nicht in Overture uebernommen. Ohne `--apply` wird nichts veraendert. `--tel-config=/path/to/worker.tel-aviv.json` und `--tel-profile=/path/to/profile.json` waehlen abweichende Tel-Aviv-Pfade. Zugangsdaten aus `worker.env` werden weder gelesen noch ausgegeben.

Auch das Laravel-Backend mitdeployen und `php artisan migrate --force` ausfuehren: Die neue Migration ergaenzt Standortschluessel fuer bestehende Seiten; die Import-API erlaubt getrennte Filialen und die Suche nach einer gespeicherten Seiten-ID. Vor Aktivierung der Jobs in `/var/www/sveevee/backend/.env` explizite alte Limits durch `BUSINESS_IMPORT_REQUESTS_PER_MINUTE=480` und `BUSINESS_IMPORT_REQUESTS_PER_HOUR=28800` ersetzen und den Laravel-Konfigurationscache erneuern. Das sind API-Request-Grenzen fuer alle Jobs zusammen, kein Tageslimit fuer Seiten. Zwei volle Overture-Laeufe benoetigen allein mindestens 18000 Dublettenpruefungen pro Stunde, zuzueglich Suche, Batches, Gov, Tel Aviv und Logs.

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

### 5. Getrennte Gov- und Tel-Aviv-Laeufe mit jeweils maximal zehn Businesses

```bash
sudo -u sveevee-worker /var/www/sveevee-worker/bin/worker run \
  --config=/etc/sveevee-worker/worker.json \
  --env-file=/etc/sveevee-worker/worker.env \
  --limit=10

sudo -u sveevee-worker /var/www/sveevee-worker/bin/worker run \
  --config=/etc/sveevee-worker/worker.tel-aviv.json \
  --env-file=/etc/sveevee-worker/worker.env \
  --limit=10
```

Den neuen Tel-Aviv-Job zunaechst mit zusaetzlichem `--dry-run` pruefen. Jeder der beiden Befehle erzeugt einen eigenen Laufbericht und Admin-Logeintrag.

### 6. Separater Overture-Lauf mit maximal 9000 Businesses

```bash
sudo -u sveevee-worker /var/www/sveevee-worker/bin/worker run \
  --config=/etc/sveevee-worker/worker.overture.json \
  --env-file=/etc/sveevee-worker/worker.env \
  --limit=9000
```

Vorher den Overture-Snapshot wie oben beschrieben vorbereiten und den Overture-Job zunaechst mit `--limit=10 --dry-run` pruefen. Beim 9000er-Lauf bleiben einzelne API-Batches auf maximal 100 begrenzt. 9000 ist ein Maximum erfolgreicher Neuanlagen und Aktualisierungen; weniger geeignete neue Quelldaten, unveraenderte Dubletten oder unaufloesbare Adressen fuehren zu weniger Schreibvorgaengen.

### 7. Zeitplaene aktivieren

Erst nach kontrolliertem Dry-Run, echtem Test und Freigabe aller aktiven Quellen:

```bash
sudo systemctl enable --now sveevee-worker.timer sveevee-tel-aviv.timer
sudo systemctl restart sveevee-worker.timer sveevee-tel-aviv.timer
systemctl list-timers sveevee-worker.timer sveevee-tel-aviv.timer sveevee-overture.timer
```

Overture behaelt seinen bisherigen Zeitplan und seine Freigabe. Bei einer erstmaligen Installation oder wenn sein Timer fuer das gemeinsame Code-Update angehalten wurde, den bereits geprueften Overture-Job danach wieder aktivieren:

```bash
sudo systemctl enable --now sveevee-overture.timer
```

Manueller systemd-Lauf und Kontrolle:

```bash
sudo systemctl start sveevee-worker.service
sudo journalctl -u sveevee-worker.service -n 100 --no-pager
sudo systemctl start sveevee-tel-aviv.service
sudo journalctl -u sveevee-tel-aviv.service -n 100 --no-pager
sudo -u sveevee-worker /var/www/sveevee-worker/bin/worker status \
  --config=/etc/sveevee-worker/worker.json \
  --env-file=/etc/sveevee-worker/worker.env
sudo -u sveevee-worker /var/www/sveevee-worker/bin/worker status \
  --config=/etc/sveevee-worker/worker.tel-aviv.json \
  --env-file=/etc/sveevee-worker/worker.env
```

Gov laeuft auf Minute 00, 10, 20, 30, 40 und 50, Tel Aviv auf 05, 15, 25, 35, 45 und 55. Overture bleibt auf 00 und 30. Alle Zeitplaene verwenden `Asia/Jerusalem` ohne Zufallsverzoegerung. Der fuenfminuetige Versatz verteilt die Gov- und Tel-Aviv-Abfragen. Dauert ein Job laenger als sein Intervall, startet keine zweite Instanz desselben Jobs parallel; systemd und seine Prozesssperre verhindern Ueberlappungen. Unterschiedliche Jobs koennen gleichzeitig laufen. `Persistent=true` bleibt aktiv. Die erreichbare Eintragszahl und Laufzeit haengen auch von Quell- und API-Antwortzeiten ab.

Gov-SQLite, Logs und Reports liegen unter `/var/lib/sveevee-worker`, Tel Aviv unter `/var/lib/sveevee-worker/tel-aviv` und Overture weiterhin unter `/var/lib/sveevee-worker/overture`. Diese Daten muessen erhalten bleiben, damit jeder Job erfolgreiche Imports, sichere Batch-Retries und noch nicht an den Admin-Log uebertragene Laufberichte kennt. Die drei Service-Namen erlauben zusaetzlich eine getrennte Kontrolle ueber `journalctl`.

## Tests

```bash
php -d xdebug.mode=off tests/run.php
php -d xdebug.mode=off tests/deploy-config.php
php -d xdebug.mode=off tests/job-config.php
php -d xdebug.mode=off tests/source_budget.php
php -d xdebug.mode=off tests/source_job_reports.php
php -d xdebug.mode=off tests/source_pagination.php
php -d xdebug.mode=off tests/tel_aviv_source.php
php -d xdebug.mode=off tests/company_categories.php
php -d xdebug.mode=off tests/companies_source.php
php -d xdebug.mode=off tests/overture_source.php
php -d xdebug.mode=off tests/overture_prepare.php
php -d xdebug.mode=off tests/overture_identity.php
php -d xdebug.mode=off tests/import_jobs.php
php -d xdebug.mode=off tests/import_locations.php
```
