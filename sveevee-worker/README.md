# Sveevee Automation/Research Worker

Der Worker laeuft unabhaengig von Frontend und Laravel-Web-Requests. Er recherchiert ueber austauschbare Source-Adapter, speichert seinen Zustand in SQLite, prueft Dubletten ueber die bestehende Business-Import-API und sendet Schreibvorgaenge in Bloecken von hoechstens 100 Businesses.

## Eigenschaften

- OAuth-2.0 Client Credentials mit automatischer Token-Erneuerung
- Lokaler, persistenter Status fuer Businesses, Identitaetssignale, Quellen-URLs, Fehler, Batches und Runs
- Persistente Quellencursor fuer vollstaendige Register; optionale Stadt-Kategorie-Rotation im bisherigen Katalogmodus
- Getrennte Gov- und Tel-Aviv-Jobs: alle Datensaetze ihrer konfigurierten Ressourcen, jeweils hoechstens 10 erfolgreiche Neuanlagen oder Aktualisierungen pro Lauf
- Gov und Tel Aviv haben jeweils ein eigenes Budget von hoechstens 10 Quell-HTTP-Abfragen pro Lauf, mit 10er-Seiten, ohne Retries und mindestens zwei Sekunden Abstand
- Separater Overture-Job: alle Israel-Places der vorbereiteten Ausgabe fortlaufend nach GERS-ID, hoechstens 9000 erfolgreiche Neuanlagen oder Aktualisierungen pro Lauf
- Separater Foursquare-Job: alle Israel-Datensaetze der vorbereiteten Ausgabe fortlaufend nach FSQ-ID, hoechstens 9000 erfolgreiche Neuanlagen oder Aktualisierungen pro Lauf; geschlossene Orte und unklare Ueberschneidungen werden separat gezaehlt
- Unveraenderte Dubletten und unbrauchbare Kandidaten verbrauchen keine erfolgreichen Schreibplaetze; ihre Quellabfragen zaehlen zum HTTP-Budget
- Gov alle zehn Minuten, Tel Aviv einmal taeglich um 03:05 Uhr israelischer Zeit; Overture weiterhin alle 30 Minuten und Foursquare stuendlich, jeweils ohne Tageslimit
- Getrennte Import-Batches pro Job: maximal 10 Eintraege bei Gov/Tel und 100 bei Overture/Foursquare
- Idempotente Batch-Retries mit vor dem Request gespeicherter `client_import_id`
- Einzelne Fehler stoppen die restlichen Batch-Eintraege nicht
- Fehlende Update-Felder loeschen keine vorhandenen Daten
- Beanspruchte Seiten werden erkannt und niemals veraendert
- Eigene JSON-Reports, JSON-Line-Logs und Admin-Logeintraege fuer jeden Job und Lauf
- Idempotente Uebertragung jedes abgeschlossenen Laufs in den Admin-Tab `Logs`, mit persistenter Outbox fuer spaetere Retries
- Getrennte Datenbanken, Fortschrittsstaende und Prozesssperren je Job; keine parallelen Instanzen desselben Jobs
- `robots.txt`, Rate Limits, HTTPS-Pruefung und Schutz vor Requests in private Netze beim Website-Enrichment

Die Quellenfelder `source_name`, `source_url` und `source_checked_at` bleiben mitsamt einem Hash und dem recherchierten Rohobjekt in der lokalen SQLite. Im Vollmodus sendet der Worker ausserdem `source` mit validiertem Provider, stabiler Quellen-ID, URL und Metadaten an die Import-API. Diese Zuordnung erhaelt vorhandene Seiten auch bei spaeteren Namenskorrekturen; technische Quellenfelder werden nicht in oeffentliche Beschreibungstexte kopiert.

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

`research` schreibt nur in den lokalen Status. `import` verarbeitet bereits recherchierte Eintraege. `run` recherchiert und importiert bis zur Erfolgsgrenze, zum Ende der aktuellen Quelle oder zum Quell-HTTP-Budget. Die Vollmodi setzen beim gespeicherten Datensatz fort; der optionale Katalogmodus arbeitet je Kombination. `--dry-run` darf die Read-Endpunkte zur Dublettenpruefung verwenden, sendet aber keinen Create-, Update- oder Batch-Request an Sveevee. Im Dry-Run zaehlen geplante Schreibvorgaenge; beim echten Import zaehlen erfolgreiche Neuanlagen und Aktualisierungen.

## Quellen

Mitgeliefert werden:

- `overture_places`: lokal vorbereitete Israel-Daten aus Overture Maps Places mit Kategorien, Adressen und vorhandenen Kontaktdaten. Diese Quelle laeuft ausschliesslich im separaten Overture-Profil.
- `foursquare_places`: lokal vorbereitete Israel-Daten aus Foursquare Open Source Places, im eigenen Profil mit Abgleich gegen vorhandene Seiten und belegte Foursquare-IDs aus Overture.
- `data_gov_ckan`: vollstaendiger paginierter Durchlauf der konfigurierten Beersheba-Gewerbelizenzen und des landesweiten Firmenregisters.
- `tel_aviv_business_licenses`: vollstaendiger paginierter Durchlauf des offiziellen Tel-Aviv-ArcGIS-Layers, einschliesslich unbekannter Lizenzarten und abgelaufener Lizenzen.
- `json_seed`: JSON-Array, JSONL oder `{ "businesses": [...]` fuer lizenzierte Exporte und manuell vorbereitete Daten.
- `overpass`: OpenStreetMap-Recherche ueber konfigurierbare OSM-Tag-Zuordnungen.
- `official_website`: optionale Anreicherung der vom Discovery-Adapter gefundenen offiziellen Website. Verarbeitet werden die Startseite, JSON-LD, Meta-Daten und oeffentliche Kontaktlinks.

B144 und Easy sind bewusst nicht fest eingebaut. Ein direkter Crawler sollte erst ergaenzt werden, wenn die jeweilige Quelle automatisierten Zugriff und die dauerhafte Weiterverwendung der Daten ausdruecklich erlaubt. Ein neuer Adapter implementiert lediglich `SourceAdapterInterface`; Import, Normalisierung und Statusverwaltung bleiben unveraendert.

Die oeffentliche Overpass-Instanz ist standardmaessig gesperrt. Ihre Betreiber beschreiben sie als Ressource fuer kleine beziehungsweise einmalige Nutzung und empfehlen fuer regelmaessige oder kommerzielle Last eine eigene oder autorisierte Instanz: https://wiki.openstreetmap.org/wiki/Overpass_API und https://dev.overpass-api.de/overpass-doc/en/preface/commons.html. Fuer produktive OSM-Daten muessen ausserdem ODbL und Attribution geprueft werden. Der Website-Adapter beachtet RFC 9309 (`robots.txt`), doch robots.txt ersetzt keine Pruefung der Nutzungsbedingungen: https://www.rfc-editor.org/rfc/rfc9309.html.

### Foursquare Open Source Places

Die aktuellen [offenen Foursquare-Daten](https://docs.foursquare.com/data-products/docs/access-fsq-os-places) sind kostenlos unter Apache 2.0, erfordern fuer den Download aber ein Places-Portal-Konto und einen Access-Token. Der Token steht ausschliesslich in der ignorierten lokalen `sveevee-worker/.env` als `FOURSQUARE_ACCESS_TOKEN`. Er gehoert weder in Config-JSON, Git, Reports noch Befehlsargumente. Die normalen Importlaeufe lesen nur den vorbereiteten SQLite-Snapshot und brauchen keinen Foursquare-Token.

```bash
php bin/prepare-foursquare.php --config=config/worker.foursquare.json --duckdb=/path/to/duckdb
php bin/worker research --config=config/worker.foursquare.json --limit=10
php bin/worker run --config=config/worker.foursquare.json --limit=10 --dry-run
```

`--env-file`, `--output` und `--duckdb` waehlen abweichende Pfade. Fuer einen vorhandenen, vollstaendigen JSONL-Ausschnitt ist keine Netzwerkabfrage erforderlich:

```bash
php bin/prepare-foursquare.php --config=config/worker.foursquare.json --jsonl=/path/to/israel.jsonl --rows=N --release=YYYY-MM-DD --snapshot-id=N
```

Die Vorbereitung pinnt einen offiziellen Snapshot und exportiert ausschliesslich `country=IL`. Ungueltige Quell-IDs, Schemafehler oder eine unvollstaendige Datei verhindern die Veroeffentlichung des neuen Snapshots; eine vorhandene gute Datei bleibt erhalten. Unbrauchbare Namen bleiben dagegen mit ihren Originaldaten und `preparation_error=invalid_business_name` im Snapshot; sie erhalten keinen oeffentlichen Ersatznamen. Die fertige Datei liegt ohne expliziten `database_path` neben der Foursquare-Worker-Datenbank als `foursquare.sqlite`, auf dem Server also unter `/var/lib/sveevee-worker/foursquare/foursquare.sqlite`.

Der vorbereitete Snapshot liegt unter dem von Git ausgeschlossenen `var`-Verzeichnis. Ein Push uebertraegt ihn deshalb nicht. Beim freigegebenen Deployment muss die vollstaendige Datei gesondert uebertragen und fuer `sveevee-worker` lesbar gemacht werden; der Download-Token wird dafuer nicht auf dem Server benoetigt. Vor dem ersten Foursquare-Lauf die Backend-Migrationen ausfuehren, damit Quellen-Aliasse und die Dubletten-Prueftabelle vorhanden sind.

Der einmalige Download kann trotz Israel-Filter grosse Teile der globalen Parquet-Dateien lesen. Er darf bis zu 60 Minuten dauern; seine lokale JSONL-Ausgabe ist auf 1 GiB begrenzt. Diese Vorbereitungsgrenzen sind unabhaengig vom stuendlichen Import-Timer, der ausschliesslich den fertigen lokalen Snapshot liest.

Alle Originaldaten bleiben in den Quellenmetadaten, darunter FSQ-ID, Datumsfelder, Koordinaten, Originalstadt, Kategorien und Qualitaetsmeldungen. Bekannte Kategorien werden konservativ zugeordnet; unbekannte Kategorien und Orte bleiben fuer die Katalogpruefung erhalten. Fehlende Adresse oder Kategorie schliesst einen Ort nicht aus. `date_closed` markierte Orte bleiben im Snapshot, werden aber nicht veroeffentlicht. Ihr Cursor wird bestaetigt und sie erscheinen separat unter `foursquare_progress.closed`. Unbestaetigte `unresolved_flags` werden unveraendert dokumentiert und fuehren nicht automatisch zum Ausschluss.

Overture enthaelt bereits Foursquare-Daten. Belegte FSQ-IDs aus dessen Quellenprovenienz und bereits gespeicherte FSQ-Zuordnungen werden zuerst abgeglichen; danach folgen die vorhandenen Standortpruefungen. Sichere Ueberschneidungen ergaenzen nur fehlende Felder vorhandener unbeanspruchter Seiten. Beanspruchte Seiten bleiben geschuetzt. Unklare Treffer kommen in die Backend-Pruefliste; der Worker speichert den terminalen Status `review`, zaehlt ihn separat und importiert weitere Kandidaten. Solche Eintraege verbrauchen keinen erfolgreichen Schreibplatz und werden durch `retry-failed` nicht erneut eingereiht. Im Dry-Run wird der Reviewbedarf nur berechnet, ohne eine Backend-Pruefzeile zu speichern.

Der Report enthaelt `foursquare_progress` mit `release`, `total`, `scanned`, `remaining`, `closed`, `invalid`, `pending`, `failed` und `review`. Markierte unbrauchbare Namen werden vor jedem API-Aufruf uebersprungen und im Cursor bestaetigt. `invalid` zaehlt nur bereits gescannte, nicht geschlossene Eintraege ohne brauchbaren Namen; geschlossene Eintraege zaehlen ausschliesslich unter `closed`. Die Gesamtzahl des Snapshots bleibt dabei erhalten. Bei regulaeren Kandidaten geht der Cursor erst weiter, nachdem der Kandidat beziehungsweise die Ablehnung lokal gespeichert wurde. Bereits abgeschickte Batches werden mit identischer Anfrage und UUID wiederholt; ein abgeschlossener Snapshot beginnt nicht beim naechsten Timerlauf von vorn. Ein neuer vorbereiteter Snapshot erhaelt einen eigenen Scanfortschritt, vorhandene erfolgreiche Quellenzuordnungen bleiben erhalten.

Foursquare wird ausdruecklich und unabhaengig von den bestehenden Jobs zur Installation hinzugefuegt:

```bash
php deploy/configure-rotation.php --add-foursquare
php deploy/configure-rotation.php --add-foursquare --apply
```

Dabei bleiben bereits vorhandene Gov-, Tel-Aviv-, Overture- und Foursquare-Konfigurationen bytegleich. Nur eine fehlende Foursquare-Konfiguration wird erzeugt. `--foursquare-config` und `--foursquare-profile` erlauben abweichende Pfade. Die Ergaenzung setzt keinen Cursor zurueck und startet keine Timer. Der Installer installiert die neue Unit ebenfalls ohne Aktivierung. Nach einem spaeter ausdruecklich freigegebenen Deployment kann `sveevee-foursquare.timer` aktiviert werden; der Zeitplan lautet jede Stunde zur Minute 15 in `Asia/Jerusalem`, mit maximal 9000 Erfolgen und 100er-Batches. Pro Job verhindern systemd und die Prozesssperre parallele Instanzen.

Die gemeinsame Sveevee-API begrenzt alle Jobs zusammen. 9000 ist eine Obergrenze, keine garantierte Menge pro Stunde: Antwortzeiten, Dubletten, Reviews und Rate Limits beeinflussen die Laufzeit. Bei einem voruebergehenden API-Ausfall bleiben Kandidaten und Batches fuer den naechsten Lauf erhalten. Die vorhandenen Overture-, Gov- und Tel-Aviv-Einstellungen werden durch die neue Quelle nicht angepasst.

### Overture Places

Die offiziellen [GeoParquet-Downloads](https://docs.overturemaps.org/getting-data/cloud-sources/) sind ohne Konto, API-Schluessel oder kostenpflichtigen Abfragedienst zugaenglich. Speicher, Datenverkehr und Verarbeitung laufen auf dem eigenen Rechner/Server. Fuer die Vorbereitung wird zusaetzlich die [DuckDB CLI](https://duckdb.org/docs/stable/clients/cli/overview) benoetigt (getestet mit 1.5.5); die normalen PHP-Importlaeufe brauchen DuckDB nicht.

`bin/prepare-overture.php` liest den offiziellen STAC-Katalog fuer die gewaehlte Ausgabe, ermittelt alle Partitionen im Israel-Fenster (34–36 Grad Ost, 29–34 Grad Nord) und exportiert ausschliesslich Datensaetze mit einer IL-Adresse. Auch die konkret uebernommene Adresse muss `country=IL` tragen. Das aktuelle Overture-Profil verwendet `sources.overture_places.import_mode=all_places` und bereitet alle Israel-Datensaetze dieser Auswahl vor; beim lokal geprueften Stand `2026-08-19.0` sind dies 162913 eindeutige GERS-IDs. Diese Sammlung enthaelt neben Unternehmen auch Einrichtungen und andere Orte.

Im Vollmodus bleibt jede IL-Zeile erhalten, unabhaengig von Confidence, Betriebsstatus, Stadt oder Kategorie. `min_confidence=0.0` dokumentiert diese Auswahl; die originale Confidence und der Betriebsstatus bleiben Quellmetadaten und behaupten keine Aktualitaet oder Oeffnung. Bekannte Ortsvarianten werden wie bisher vereinheitlicht, weitere vorhandene Ortsnamen bleiben als Quellwert erhalten. Es gibt keine Zuordnung zur naechstgelegenen Stadt. Eine ausdrueckliche Taxonomiezuordnung deckt zusaetzliche Sveevee-Kategorien ab; unklare Kategorien sowie fehlende oder unbrauchbare Strassen/Ortsnamen bleiben `NULL`. Telefonnummern, E-Mails, Websites und unterstuetzte Social-Media-Links werden weiterhin validiert. Fehlende Angaben, Bilder und Oeffnungszeiten werden nicht erfunden.

Der bisherige Modus `import_mode=catalog` bleibt fuer bestehende Konfigurationen ohne explizite Umstellung erhalten: Confidence-Schwelle, geoeffnet/Status unbekannt, bekannte Stadt/Strasse und die urspruenglichen zehn Kategorien. Ein Vollsnapshot verwendet Schema 2 mit nullable Kategorie, Stadt, Strasse und Confidence; gefilterte Katalogsnapshots verwenden weiterhin Schema 1.

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

Der Export ist auf 768 MB DuckDB-Arbeitsspeicher, 300000 Israel-Zeilen, 1 GiB JSONL und 30 Minuten begrenzt. Die `httpfs`-Erweiterung wird bei einem Download im Ausgabeordner installiert. Eine neue SQLite-Datei wird vollstaendig aufgebaut, auf Zeilenzahl und Integritaet geprueft und erst dann atomar veroeffentlicht. Im Vollmodus muessen `read` und `accepted` exakt uebereinstimmen und `skipped` leer sein; schon eine unerwartet verworfene Zeile verhindert die Veroeffentlichung. Leere, abgeschnittene, doppelte oder fehlerhafte Exporte ersetzen keinen bestehenden Datenstand. Auch ein aelterer Stand, ein Rueckgang um mehr als die Haelfte oder ein versehentlicher Wechsel von Vollsnapshot zu gefiltertem Katalog wird abgewiesen und kann zunaechst unter einem anderen `--output` untersucht werden.

Der PHP-Adapter oeffnet die fertige Datei schreibgeschuetzt. Im Vollmodus liest er fortlaufend nach GERS-ID ueber den gesamten Israel-Snapshot und speichert seinen Fortschritt fuer den naechsten Lauf. Die 83 konfigurierten Staedte und zehn bisherigen Kategorien begrenzen diese Auswahl nicht mehr. Stabile Overture-GERS-IDs bilden weiterhin die Quellenlinks. Jeder Lauf erlaubt hoechstens 9000 erfolgreiche Neuanlagen oder Aktualisierungen, jeder API-Schreibrequest hoechstens 100 Eintraege. Bereits bekannte unveraenderte Quellen, Dubletten und beanspruchte Seiten erzeugen keine zusaetzlichen Neuanlagen; die Quellzeilenzahl ist deshalb keine Zusage gleich vieler neuer Seiten. Versions- und Lizenzmetadaten bleiben in `source_metadata` erhalten. Die Event-Spalte im Admin-Log zeigt `Overture Places`.

`overture_progress` im Laufbericht nennt Ausgabe, Gesamtzahl, bereits gepruefte Quellen-IDs und noch zu pruefende IDs sowie offene und fehlgeschlagene Eintraege. `remaining=0` bedeutet, dass der Snapshot vollstaendig durchgesehen wurde; die Fehler-/Warteschlangenzaehler und tatsaechlichen Importzahlen bleiben separat. Bei einem Abbruch bleiben gespeicherte Kandidaten und unveraenderliche Batches fuer den naechsten Lauf erhalten. Beim erstmaligen Vollscan werden auch alte, noch ohne Vollimport-Provenienz abgewiesene Overture-Eintraege erneut geprueft.

Nur bei validierter Overture-Provenienz darf der Quellenname den allgemeinen Wortfilter fuer Namen umgehen. Damit bleiben Originalnamen erhalten, auch wenn sie in einer anderen Sprache ein gesperrtes Wort enthalten oder inhaltlich fragwuerdig sind. Die normale Import-API und Beschreibungen behalten ihre bestehenden Pruefungen. Originalname und urspruengliche Website stehen in den Quellenmetadaten; eine von der API abgewiesene optionale Website verhindert die Uebernahme des Ortes nicht.

Gemeinsame Telefonnummern, E-Mail-Adressen, Namen und Website-Domains sperren keine anderen Filialen mehr. Bekannte unterschiedliche Staedte, Strassen oder Hausnummern ergeben separate Businesses und Seiten. Derselbe bereinigte Name am selben Standort kann aus mehreren Quellen angereichert werden; eine bestaetigte Standortuebereinstimmung hat Vorrang vor gemeinsamen Kontaktdaten. Unvollstaendige oder mehrdeutige Adressen werden nicht zufaellig einer Filiale zugeordnet. Die lokale SQLite-Migration macht Kontaktschluessel nicht exklusiv und erhaelt bestehende IDs, Quellen und unveraenderliche Batchanfragen. Die Backend-Migration ergaenzt einen Standortindex auch fuer bestehende Seiten. Beanspruchte Seiten bleiben geschuetzt.

Places verwendet je nach Zulieferer CDLA-Permissive 2.0, Apache 2.0 oder CC0. Overture-Provenienz und Ausgabe bleiben im Snapshot und im Worker-Rohdatensatz erhalten. Die mitgelieferten Lizenztexte, der vollstaendige Foursquare-Hinweis und die Beschreibung unserer Datenanpassungen liegen unter `frontend/public/data-sources/` und sind ueber den Footer erreichbar. Beim Rollout muessen diese Frontend-Dateien zusammen mit dem Worker veroeffentlicht werden. [Offizielle Quellen-/Lizenzliste](https://docs.overturemaps.org/attribution/#places).

Bei einer bestehenden Live-Installation den Vollmodus nur gezielt mit `deploy/configure-rotation.php --update-overture` ansehen und danach mit `--update-overture --apply` uebernehmen. Die normale Gov/Tel-Konfigurationsmigration veraendert eine vorhandene Overture-Konfiguration weiterhin nicht. Vor einem Rollout muessen Backend und Worker den Vollmodus unterstuetzen und der vollstaendige Snapshot vorbereitet sein. Worker-Zustandsdatenbank, Quellenzuordnungen, Fortschritt und offene Batches bleiben erhalten; nur die separate Snapshot-Datei wird ersetzt. Den laufenden Overture-Prozess nicht durch Datei-/Runtime-Wechsel unterbrechen.

Der Vorbereitungsbefehl auf dem Server lautet:

```bash
sudo runuser -u sveevee-worker -- php /var/www/sveevee-worker/bin/prepare-overture.php \
  --config=/etc/sveevee-worker/worker.overture.json \
  --env-file=/etc/sveevee-worker/worker.env --duckdb=/usr/local/bin/duckdb
```

Bei der erstmaligen Installation bleiben die Import-Timer bis zum abgeschlossenen Test angehalten. Eine fehlende oder beschaedigte Overture-Datei wird als Quellenfehler gemeldet.

### Data.gov.il / CKAN

Das Gov-Profil verwendet `sources.data_gov_ckan.import_mode=all_records`. Es liest die ausdruecklich konfigurierten Ressourcen vollstaendig und in ihrer Konfigurationsreihenfolge: zuerst Beersheba-Gewerbelizenzen (`7d4c61e2-2416-453e-8efb-bd02ec89db35`), danach das landesweite Firmenregister (`f004176c-b85f-4542-8901-7b3176f9a054`). Es gibt keine automatische Suche nach weiteren Datensaetzen auf data.gov.il. Ein weiterer Feed braucht ein passendes Mapping und einen ausdruecklichen Konfigurationseintrag.

CKAN und Tel Aviv werden in getrennten Jobs mit `page_size=10`, `max_retries=0` und `min_interval_seconds=2.0` abgefragt. `research.max_http_requests_per_run=10` begrenzt die Quell-HTTP-Abfragen jedes Jobs fuer seinen gesamten Lauf, einschliesslich aller Ressourcen. Gov und Tel Aviv haben jeweils ein eigenes Zehnerbudget. Auch Zaehlerabfragen, leere Antworten und fehlgeschlagene Requests verbrauchen dieses Budget; bei ausdruecklich abweichender Retry-Konfiguration zaehlt jeder Versuch. Abfragen an Sveevees Import-API und der separate Overture-Job zaehlen nicht dazu. Jeder Gov- oder Tel-Aviv-Importlauf schreibt hoechstens zehn erfolgreiche Neuanlagen oder Aktualisierungen, in Paketen von maximal zehn.

Der erste HTTP-, API- oder Paginationfehler pausiert die betroffene Quelle fuer den Rest ihres Laufs. Bei einem Fehler der ersten Abfrage entsteht damit nur eine Quellabfrage und ein Quellenfehler in diesem Job. Der andere Job hat einen eigenen Zeitplan und bleibt davon unabhaengig. Sobald das jeweilige Budget verbraucht ist, werden in diesem Lauf keine weiteren Quell-HTTP-Abfragen gestartet. Diese Laufbegrenzung deaktiviert keinen Timer dauerhaft; der naechste Lauf hat wieder ein eigenes Budget.

Jede Quellseite wird vollstaendig validiert und atomar in einer kleinen persistenten Warteschlange gespeichert, bevor die erste Zeile ausgegeben wird. `next_offset` bezeichnet heruntergeladene, `consumed_offset` bereits dauerhaft verarbeitete Zeilen. Erst nach gespeichertem Kandidaten, Dublettenentscheid oder protokollierter Ablehnung wird eine Zeile bestaetigt (ACK) und ihr Warteschlangen-JSON geloescht. Es liegt hoechstens eine Zehnerseite pro aktivem Ressourcenscan bereit. Ein Lauf verarbeitet zuerst deren Rest und laedt erst danach weiter. Budgetende und HTTP-Fehler behalten beide Positionen und den Seitenrest; unvollstaendige Scans werden auch nach laengerer Pause fortgesetzt.

Ein neuer Gesamtzyklus beginnt standardmaessig fruehestens 24 Stunden nach vollstaendigem Herunterladen und Bestaetigen aller konfigurierten Ressourcen (`cache_refresh_seconds=86400`). Gov schliesst deshalb auch das Firmenregister ab, bevor Beersheba erneut beginnt. Manuelle und Timerlaeufe benutzen denselben Cursor. Alte Katalogcaches und deren Verlauf bleiben getrennt erhalten. Bereits importierte unveraenderte Quellen werden anhand stabiler IDs und Inhalts-Hashes uebersprungen; bisher wegen fehlender Katalogfelder abgewiesene Eintraege koennen im Vollmodus erneut geprueft werden.

Diese APIs liefern veraenderliche Register und keinen eingefrorenen Snapshot. Einfuegungen oder Loeschungen vor dem aktuellen Offset koennen die Reihenfolge zwischen Laeufen verschieben; der naechste vollstaendige Zyklus liest wieder ab Anfang. Wiederholte oder rueckwaerts sortierte Zeilen sowie Schemafehler werden sichtbar gemeldet, ohne einen unbestaetigten Seitenrest zu verwerfen. Fehlende erwartete Name-/Kennungsspalten stoppen die Seite als Quellenfehler; ein vorhandener `NULL`-Wert ist dagegen ein einzelner ungueltiger Datensatz. `max_records_per_full_dataset=2000000` begrenzt jede komplette Gov-Ressource; eine Ueberschreitung ist ein ausdruecklicher Fehler, keine still gekuerzte Auswahl. Die alten `max_records_per_city`-/`max_records_per_dataset`-Werte betreffen den Katalogmodus.

Das Profil `beer_sheva_business_licenses` uebernimmt vorhandene Namen, Telefonnummern, E-Mails und Adressen. Die belegten Lizenzarten erhalten eine passende Kategorie; unbekannte Kategorien und fehlende Strassen bleiben optional. Status und Ablaufdatum filtern im Vollmodus keine Zeilen. Lizenzbeschreibung, Status und Ablaufdatum bleiben im originalen Quellobjekt erhalten. Fehlende Angaben, oeffentliche Beschreibungen und Einzugsgebiete werden nicht erfunden; ein alter Registereintrag behauptet keinen aktuell geoeffneten Betrieb.

### Landesweites Firmenregister

Das Profil `israel_companies` liest das [Firmenregister der israelischen Justizbehoerde](https://data.gov.il/datasets/ministry_of_justice/ica_companies), Ressource `f004176c-b85f-4542-8901-7b3176f9a054`, fortlaufend nach `_id` ohne Stadt-, Kategorie- oder Aktivstatusfilter. Bekannte Schreibvarianten werden auf Katalogstaedte vereinheitlicht; weitere vorhandene Ortsnamen bleiben erhalten. Die bisherigen 83 Staedte und zehn Kategorien begrenzen die Auswahl nicht.

Firmenname, englischer Name und konkrete Taetigkeitsangaben koennen weiterhin eine konservative Kategoriezuordnung liefern. Allgemeine Gesellschaftszwecke, mehrdeutige Taetigkeiten und nicht zuordenbare Firmen bleiben mit leerer Kategorie erhalten. Fuer die Quellenidentitaet werden die stabile Firmennummer und ein brauchbarer Name benoetigt; Stadt, Strasse und weitere Kontaktdaten sind optional. Fehlende Telefonnummern, E-Mails, Bilder oder Oeffnungszeiten werden nicht erfunden. Der gesamte urspruengliche Registerdatensatz bleibt als Quellenmetadaten erhalten, einschliesslich Rechtsform, Status und gegebenenfalls Gruendungsdatum. Der Registerstatus bestaetigt keine geoeffnete Filiale oder gueltige Gewerbelizenz.

Die Firma behaelt ihre bestehende Quellen-URL und Firmennummer als stabile Identitaet; die CKAN-Zeilennummer dient nur zur Pagination. Bestehende Worker-IDs, Quellenzuordnungen, Seiten-IDs und offene Batches werden durch die Umstellung nicht ersetzt. Fuer einen reproduzierbaren Datenstand waere zusaetzlich ein versionierter Gesamtexport erforderlich; der fortlaufende Live-Scan garantiert keinen Snapshot zu einem einzigen Zeitpunkt.

### Tel Aviv / kommunale Gewerbelizenzen

Die zweite kommunale Quelle ist [ArcGIS-Layer 964 der Stadt Tel Aviv-Yafo](https://gisn.tel-aviv.gov.il/arcgis/rest/services/IView2/MapServer/964). Ihr eigenes Profil nutzt `sources.tel_aviv_business_licenses.import_mode=all_records` und laeuft einmal taeglich um 03:05 Uhr (`Asia/Jerusalem`). Alle Zeilen werden nach `oid_rishayon` durchlaufen, unabhaengig von Kategorie, Lizenzstatus, Ablaufdatum oder vorhandener Strasse. Tel Aviv ist durch die kommunale Quelle belegt; andere Ortsnamen werden nicht erfunden. Bekannte Lizenzarten erhalten eine Kategorie, unbekannte bleiben leer. Originale Lizenzangaben bleiben in `source_metadata.license` erhalten. Telefonnummern und Hausnummern werden nicht ergaenzt, wenn die Quelle sie nicht liefert.

Der ArcGIS-Gesamtzaehler wird pro Scan gespeichert und verbraucht eine der zehn erlaubten Quellabfragen. Die Gesamtzahl ist dynamisch; eine frueher beobachtete Zahl wie 571 ist kein festes Importlimit und keine Zusage gleich vieler neuer Seiten. Zehnerseiten und ACK-Cursor funktionieren wie bei Gov. Bei einer unerwartet leeren Seite wird die Gesamtzahl erneut budgetiert abgefragt, damit ein inzwischen geschrumpfter Layer den Cursor nicht dauerhaft blockiert. `max_records_per_scan=2000000` ist die ausdrueckliche Sicherheitsgrenze des Vollscans; eine groessere Quelle fuehrt zu einem Fehler. Die normale Erfolgsgrenze bleibt zehn Eintraege pro taeglichem Lauf.

Die Quellenkennung verwendet Geschaeftsnummer, Untergeschaeftsnummer und Lizenzart: `964:` plus SHA-256 der unveraenderten WHERE-Bedingung aus dem bisherigen Quellenlink. Die fluechtige ArcGIS-Zeilennummer gehoert nicht zur Business-Identitaet. Fehlende oder umbenannte Name-/Geschaeftsnummernspalten stoppen die gesamte Seite als Schemafehler; vorhandene leere Werte werden mit dem Originalobjekt zur individuellen Ablehnung weitergegeben. HTTP-, API- und Paginationfehler werden als Fehler gemeldet und nicht als erfolgreiche leere Recherche ausgegeben.

Fuer bestehende oder bewusst eingeschraenkte Konfigurationen bleibt `import_mode=catalog` verfuegbar. Dieser optionale Modus behaelt die bisherige Stadt-Kategorie-Rotation sowie ihre Status-, Lizenzgueltigkeits- und Pflichtfeldfilter. Die mitgelieferten Gov- und Tel-Aviv-Profile verwenden den Vollmodus.

### Unbekannte Staedte und Kategorien

Alle drei Vollimporte erhalten Originalwerte in `source.metadata.source_city` und `source.metadata.source_categories` (`key`, `label`, `catalog_key`). Das Backend sammelt bisher unbekannte Werte in den separaten Tabellen `business_import_cities` und `business_import_categories`: Quelle, Originalwert, Beispielseite, erste Quellenkennung sowie erster und letzter Sichtungszeitpunkt. Die Felder `mapped_city` beziehungsweise `mapped_category_key` bleiben fuer einen spaeteren Abgleich frei. Wiederholungen erzeugen keine neuen Pruefeintraege; der oeffentliche Katalog wird nicht automatisch erweitert.

Unbekannte Ortsnamen bleiben in der Business-Adresse. Nicht zugeordnete Originalkategorien werden am Business als `setup.imported_categories` gespeichert und ueber `source_categories` an die Seite geliefert. Sie erscheinen dort als Text ohne ungueltige Kataloglinks. Eine fehlende Kategorie oder Stadt verhindert den Vollimport nicht. Wenn die Quelle keine Geschaeftskategorie liefert, bleibt diese Angabe leer: insbesondere sind allgemeiner Firmenzweck oder Gesellschaftsform keine erfundene Kategorie.

Beim Rollout muessen vor den neuen Importlaeufen die Backend-Migrationen fuer Quellenzuordnungen und beide Prueftabellen ausgefuehrt werden. Vorhandene vollstaendige Overture-Snapshots enthalten bereits die Rohmetadaten; der Worker ergaenzt die Vergleichsfelder beim Lesen ohne erneuten Download.

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

Im optionalen `catalog`-Modus bildet der Worker aus `cities` und `categories` alle Kombinationen. Der persistente Scheduler sortiert zuerst unbesuchte und danach die am laengsten nicht bearbeiteten Kombinationen. Leere Kombinationen verbrauchen keinen produktiven Platz. Die aktuellen Gov-/Tel-Profile verwenden stattdessen je ein globales Quellenziel mit einem fortsetzbaren Datensatzcursor.

`target_per_run` begrenzt erfolgreiche Neuanlagen und Aktualisierungen pro Lauf. Gov und Tel Aviv verwenden je ein Quellenziel mit maximal zehn Erfolgen, Overture ein globales Israel-Ziel mit maximal 9000. Nach Dubletten, beanspruchten Seiten oder ungueltigen Eintraegen wird weitergesucht, soweit Quelle und HTTP-Budget es erlauben. Gov-/Tel-Batches enthalten maximal zehn fortlaufende Datensaetze verschiedener Orte und Kategorien; Overture-Batches maximal 100. Offene Batches behalten Anfrage und Idempotenz-ID und verbrauchen dieselben Laufgrenzen. Ein groesserer Alt-Batch wird unveraendert zurueckgestellt, wenn er nicht ins aktuelle Budget passt. `businesses_per_combination` bleibt fuer den optionalen Katalogmodus erhalten. Ein altes `quotas.max_new_per_day` wird nicht mehr angewendet; das Deployment entfernt den Schluessel.

Der Fortschritt liegt in SQLite. Manuelle Laeufe bewegen denselben Cursor wie Timerlaeufe. `worker status` zeigt zusaetzlich den Zielplan; bei den Vollscans besteht dieser aus genau einem Quellenziel.

Das Gov-Profil `config/worker.rotation.json` aktiviert nur `data_gov_ckan` im Vollmodus fuer beide angeschlossenen Ressourcen. `config/worker.tel-aviv.json` aktiviert nur `tel_aviv_business_licenses` im Vollmodus. Vorhandene Stadt- und Kategorielisten bleiben als Zuordnungshilfen und fuer den optionalen Katalogmodus erhalten; sie begrenzen diese Jobs nicht.

Das separate Profil `config/worker.overture.json` aktiviert nur Overture im Vollmodus. Seine vorhandene Stadt-/Kategorieliste bleibt fuer bekannte Zuordnungen erhalten; recherchiert wird ein globales Israel-Ziel. `storage.data_subdirectory` wird nach dem gemeinsamen `SVEVEE_WORKER_DATA_DIR` angewendet. Zugelassen sind der leere Namespace fuer Gov sowie `tel-aviv`, `overture` und `foursquare`. Damit bleiben Datenbank, offene Batches, Laufposition, Quellcache, Reports, Log, Admin-Log-Outbox und Lock getrennt, auch wenn alle Services dieselbe Credential-Datei laden.

| Job | Installierte Konfiguration | Datenverzeichnis bei `SVEVEE_WORKER_DATA_DIR=/var/lib/sveevee-worker` | Systemd-Service |
| --- | --- | --- | --- |
| Gov / data.gov.il | `/etc/sveevee-worker/worker.json` | `/var/lib/sveevee-worker` | `sveevee-worker.service` |
| Tel Aviv | `/etc/sveevee-worker/worker.tel-aviv.json` | `/var/lib/sveevee-worker/tel-aviv` | `sveevee-tel-aviv.service` |
| Overture Places | `/etc/sveevee-worker/worker.overture.json` | `/var/lib/sveevee-worker/overture` | `sveevee-overture.service` |
| Foursquare Places | `/etc/sveevee-worker/worker.foursquare.json` | `/var/lib/sveevee-worker/foursquare` | `sveevee-foursquare.service` |

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

Im Stadt-Kategorie-Ablauf muss jede Stadt und jeder `category_key` einem vorhandenen Sveevee-Katalogwert entsprechen; `neighborhoods` bleibt leer. Der separate Overture-Vollmodus erlaubt vorhandene weitere Ortsnamen und fehlende optionale Adress-/Kategoriefelder. Fuer jede neue OSM-Kategorie wird unter `sources.overpass.category_tags` eine Zuordnung gepflegt. Fuer groessere regelmaessige OSM-Laeufe `OVERPASS_API_URL` auf einen selbst betriebenen oder ausdruecklich autorisierten Endpunkt setzen.

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

Der Installer kopiert den Worker nach `/var/www/sveevee-worker`, erstellt den Systemnutzer und installiert die vier Services mit ihren vier Timern sowie die getrennten Datenverzeichnisse. Er verweigert ein Update, solange einer der Jobs oder Timer aktiv ist, und startet oder aktiviert nichts automatisch. Die optionale Foursquare-Konfiguration wird separat mit `--add-foursquare` erzeugt.

Der Installer sichert ein vorhandenes `/etc/systemd/system/sveevee-worker.timer.d/schedule.conf` unter `/var/backups/sveevee` und ersetzt es durch den Zehn-Minuten-Zeitplan. Damit bleiben alte taegliche oder stuendliche Overrides nicht versehentlich wirksam.

Das Profil fuer alle Katalogstaedte zuerst anzeigen, dann anwenden:

```bash
sudo php /var/www/sveevee-worker/deploy/configure-rotation.php
sudo php /var/www/sveevee-worker/deploy/configure-rotation.php --apply
```

Die Umstellung richtet `worker.json` fuer Gov mit ausschliesslich CKAN und `worker.tel-aviv.json` fuer die kommunalen Tel-Aviv-Lizenzen ein. Beide erhalten jeweils zehn Eintraege, eine produktive Kombination, Zehnerseiten, ein eigenes Budget von hoechstens zehn Quell-HTTP-Abfragen, keine Retries und zwei Sekunden Abstand. Die Tel-Aviv-Konfiguration nutzt den eigenen Namespace `tel-aviv`. Eine bereits vorhandene `worker.overture.json` bleibt standardmaessig vollstaendig erhalten. Nur wenn sie fehlt, wird sie mit dem aktuellen Overture-Profil erzeugt: Vollmodus `all_places`, hoechstens 9000 Eintraege, ausschliesslich Overture und `api.request_interval_ms=150`.

Eine vorhandene Overture-Konfiguration wird ausschliesslich mit dem ausdruecklichen Update-Flag an das aktuelle Profil angepasst:

```bash
sudo php /var/www/sveevee-worker/deploy/configure-rotation.php --update-overture
sudo php /var/www/sveevee-worker/deploy/configure-rotation.php --update-overture --apply
```

Dabei bleiben die Pfade zur Worker-Datenbank und zum Snapshot sowie weitere installationsspezifische Quellen-/Zugangseinstellungen erhalten. Das Werkzeug oeffnet keine Zustandsdatenbank und setzt keinen Cursor zurueck. Es erstellt den Snapshot nicht selbst und aendert oder startet keine Timer.

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

Vorher den vollstaendigen Overture-Snapshot wie oben beschrieben vorbereiten und den Overture-Job zunaechst mit `--limit=10 --dry-run` pruefen. Beim 9000er-Lauf bleiben einzelne API-Batches auf maximal 100 begrenzt. 9000 ist ein Maximum erfolgreicher Neuanlagen und Aktualisierungen; das Ende des Snapshots, unveraenderte Dubletten oder beanspruchte Seiten fuehren zu weniger Schreibvorgaengen. Fehlende optionale Adresse oder Kategorie ist im Vollmodus kein Ausschlussgrund.

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

Gov laeuft auf Minute 00, 10, 20, 30, 40 und 50, Tel Aviv einmal taeglich um 03:05 Uhr. Overture bleibt auf 00 und 30. Alle Zeitplaene verwenden `Asia/Jerusalem` ohne Zufallsverzoegerung. Dauert ein Job laenger als sein Intervall, startet keine zweite Instanz desselben Jobs parallel; systemd und seine Prozesssperre verhindern Ueberlappungen. Unterschiedliche Jobs koennen gleichzeitig laufen. `Persistent=true` bleibt aktiv. Die erreichbare Eintragszahl und Laufzeit haengen auch von Quell- und API-Antwortzeiten ab.

Gov-SQLite, Logs und Reports liegen unter `/var/lib/sveevee-worker`, Tel Aviv unter `/var/lib/sveevee-worker/tel-aviv` und Overture weiterhin unter `/var/lib/sveevee-worker/overture`. Diese Daten muessen erhalten bleiben, damit jeder Job erfolgreiche Imports, sichere Batch-Retries und noch nicht an den Admin-Log uebertragene Laufberichte kennt. Die drei Service-Namen erlauben zusaetzlich eine getrennte Kontrolle ueber `journalctl`.

## Tests

```bash
php -d xdebug.mode=off tests/run.php
php -d xdebug.mode=off tests/deploy-config.php
php -d xdebug.mode=off tests/job-config.php
php -d xdebug.mode=off tests/source_budget.php
php -d xdebug.mode=off tests/source_job_reports.php
php -d xdebug.mode=off tests/source_pagination.php
php -d xdebug.mode=off tests/source_records.php
php -d xdebug.mode=off tests/source_catalog_metadata.php
php -d xdebug.mode=off tests/government_pipeline.php
php -d xdebug.mode=off tests/tel_aviv_all_records.php
php -d xdebug.mode=off tests/tel_aviv_source.php
php -d xdebug.mode=off tests/company_categories.php
php -d xdebug.mode=off tests/companies_source.php
php -d xdebug.mode=off tests/overture_source.php
php -d xdebug.mode=off tests/overture_prepare.php
php -d xdebug.mode=off tests/overture_mapper.php
php -d xdebug.mode=off tests/overture_all_places.php
php -d xdebug.mode=off tests/overture_identity.php
php -d xdebug.mode=off tests/foursquare.php
php -d xdebug.mode=off tests/foursquare_prepare.php
php -d xdebug.mode=off tests/import_jobs.php
php -d xdebug.mode=off tests/import_locations.php
```
