# Städte und Kategorien aus bestehenden Imports nachtragen

## Befund vom 16.09.2026

Die Prüfung von Backend und Worker erfolgte live ausschließlich lesend. Kein Snapshot wurde neu gestartet, kein Import erneut eingereiht und kein Timer für diese Reparatur verändert. Alle folgenden Codeänderungen und Werkzeuge sind zunächst lokal; Deployment und schreibende Live-Läufe folgen erst nach Push und ausdrücklichem Auftrag.

Die zusätzlichen Tabellen existieren und sind nicht leer. Die Zahlen zählen unterschiedliche Quellenbezeichnungen je Provider, nicht Businesses:

| Quelle | `business_import_cities` | `business_import_categories` |
| --- | ---: | ---: |
| Overture | 1.829 | 642 |
| Foursquare | 2.906 | 619 |

Unbekannte Städtenamen werden im Seiten-Adressfeld gespeichert; unbekannte Kategorien stehen unter `setup.imported_categories`. Beide können auf der Geschäftsseite als Text erscheinen, ohne schon Teil unseres auswählbaren Stadt-/Kategoriekatalogs zu sein. Die zusätzlichen Tabellen dienen dem späteren Vergleich und einer bewussten Zuordnung zum öffentlichen Katalog.

### Overture

- Alle 162.913 Snapshot-Datensätze wurden untersucht. Im Worker sind keine fehlgeschlagenen oder offenen Importkandidaten vorhanden.
- Bei 9.688 älteren lokalen Business-Datensätzen fehlt die spätere vollständige Quellenstruktur im Payload. Dazu gehören 9.695 belegte GERS-Quell-IDs; mehrere IDs können dieselbe Seite bezeichnen.
- Alle 9.695 IDs und die ursprünglichen Stadt-/Kategoriedaten sind im vorhandenen vorbereiteten Snapshot und in den gespeicherten Quellenrohdaten wiederzufinden. Die betroffenen Businesses haben bereits eine bekannte Seiten-ID.
- Das Backend hat zusätzlich 375 ältere Overture-Quellenzuordnungen ohne die heutigen Metadatenfelder `source_city`/`source_categories`. Alte Metadaten können dort bereits eine Adresse oder Taxonomie enthalten und werden beim Nachtragen berücksichtigt.

Diese Gruppen dürfen nicht als fehlende neue Businesses addiert werden. Es geht um Quellenzuordnungen und Beschreibungsdaten bestehender Seiten. Ein vollständiger neuer Download oder Import aller 162.913 Datensätze ist dafür nicht nötig.

### Foursquare

- Alle 109.757 Snapshot-Datensätze wurden untersucht: 5.247 geschlossen, 14 ohne brauchbaren Namen, 104.496 gültige recherchierte Kandidaten.
- Worker-Ergebnisse: 68.928 neu importiert, 2.179 aktualisiert, 33.372 zur Prüfung und 17 fehlgeschlagen.
- Alle 17 Fehler betreffen den Validierungsparameter `city`; ihre vollständigen ursprünglichen Datensätze sind noch vorhanden. Nur diese Kandidaten werden für einen gezielten erneuten Import vorgesehen.
- Die 33.372 Prüffälle bestehen aus 33.361 nicht ausreichend bestätigten Identitäten und elf Standortkonflikten bei einer Quellenverknüpfung. Sie sind keine bloßen Kategoriefehler. Ihre Städte/Kategorien können für den Vergleich nachgetragen werden; eine ungeklärte überschneidung wird dadurch nicht zur neuen Geschäftsseite.
- Bei bereits zugeordneten Foursquare-Einträgen ließ die bisherige Anreicherung fehlende Stadt-/Adressfelder und `category_key` aus. Außerdem wurden die unbekannten Bezeichnungen von Prüffällen bisher nicht in den zusätzlichen Vergleichstabellen gesammelt.

## Lokale Korrekturen

Die Importlogik ergänzt fehlende Angaben aus einer bestätigten Quelle bei unbeanspruchten Seiten. Vorhandene nichtleere Angaben, Standortprüfungen und Seiten mit bestätigtem Besitzer bleiben geschützt. Eine unbekannte Stadt oder Kategorie allein verhindert den Vollimport nicht.

Belegte Stadtaliase aus den vorhandenen Worker-Konfigurationen stehen zusätzlich in `backend/config/import_city_aliases.php`. Nur eindeutige Zuordnungen zu derzeit vorhandenen Katalogstädten werden aufgelöst. Originalbezeichnungen bleiben erhalten; unbekannte Orte werden nicht aus einem anderen Seitenfeld geraten.

Die Quellenbezeichnungen werden auch bei gespeicherten Abgleich-Prüffällen in den separaten Vergleichstabellen erfasst. Die öffentliche Seite wird bei einem ungeklärten Treffer nicht verändert und es wird keine zusätzliche Firma erzeugt. Ein Dry-Run schreibt weder Prüffälle noch Vergleichstabellen.

Die Nachholwerkzeuge haben zwei getrennte Aufgaben:

1. **Backend:** gespeicherte Quellenzuordnungen und Prüffälle auswerten und daraus Vergleichsbezeichnungen sowie fehlende darstellbare Angaben nachtragen.
2. **Worker:** die älteren Overture-Payloads anhand vorhandener Quelldaten reparieren und gezielt die 17 Foursquare-Stadtfehler erneut einreihen. Der normale Import prüft die bekannten Seiten anschließend erneut.

## Backend: begrenztes Nachtragen

Aus dem Backend-Verzeichnis, zunächst ohne `--apply`:

```bash
php artisan business-import:backfill-catalog --provider=overture_places --scope=sources --after=0 --limit=9000
php artisan business-import:backfill-catalog --provider=foursquare_places --scope=sources --after=0 --limit=9000
php artisan business-import:backfill-catalog --provider=foursquare_places --scope=reviews --after=0 --limit=9000
```

Die JSON-Ausgabe enthält Zähler, `next_after` und `eof`. Für den folgenden Abschnitt `--after` auf den zuletzt ausgegebenen Wert setzen, bis `eof=true`. `--apply` erlaubt die lokale bzw. später ausdrücklich freigegebene Live-Datenänderung; ohne dieses Flag bleibt der Aufruf eine Vorschau. Ein Vorschau-Cursor ist kein Beleg dafür, dass die Daten bereits geschrieben wurden: den Schreibdurchgang wieder mit `--after=0` beginnen und getrennt fortsetzen.

Jeder Datensatz wird einzeln atomar verarbeitet. Wiederholungen erhalten vorhandene Zuordnungen. Der Bereich `reviews` sammelt ausschließlich Bezeichnungen; er löst keine offenen Identitätsentscheidungen auf.

## Worker: gezielte Reparatur aus vorhandenen Dateien

Aus dem Worker-Verzeichnis, mit dem jeweiligen dedizierten Profil:

```bash
php bin/repair-catalog.php --config=/etc/sveevee-worker/worker.overture.json --limit=9000
php bin/repair-catalog.php --config=/etc/sveevee-worker/worker.foursquare.json --limit=9000
```

Ohne `--apply` wird nur gelesen. Der Overture-Aufruf verwendet den vorhandenen vorbereiteten Snapshot; Foursquare wählt ausschließlich fehlgeschlagene Kandidaten mit Fehlercode `city`. Das Reparaturwerkzeug selbst ruft weder Quellen- noch Sveevee-APIs auf.

Erst beim später freigegebenen Einsatz für das jeweils geprüfte Profil:

```bash
php bin/repair-catalog.php --config=/etc/sveevee-worker/worker.overture.json --limit=9000 --apply
php bin/worker import --config=/etc/sveevee-worker/worker.overture.json --limit=9000
php bin/repair-catalog.php --config=/etc/sveevee-worker/worker.overture.json --limit=9000
```

Der letzte Aufruf prüft die Ergebnisse ohne neue Einreihung. Ein weiterer Aufruf mit `--apply` bestätigt verarbeitete Quellen und reiht bei Bedarf den nächsten begrenzten Abschnitt ein. Für Foursquare dasselbe Verfahren mit dessen Profil verwenden; hier sind nur die 17 nachgewiesenen Stadtfehler vorgesehen. Die üblichen Worker-Zugangsdaten/`--env-file` werden für den eigentlichen Import benötigt.

Der Reparaturbericht unterscheidet unter anderem `queued`, `confirmed`, `waiting`, `protected` und `blocked`. Exitcode 0 bedeutet, dass der Prüflauf beendet wurde, nicht dass alle Kandidaten erfolgreich repariert sind. Ein vorhandener Fehler, geschützter Besitzer oder ein bloßes Dubletten-Ergebnis gilt nicht als bestätigte Overture-Reparatur. Ein gespeicherter Reparaturnachweis verhindert, dass mehrere GERS-IDs desselben Businesses einander in der Queue überschreiben.

## Reihenfolge beim später freigegebenen Einsatz

- Zuerst den geprüften Code nach Push deployen und sicherstellen, dass die betroffenen Worker während der Reparatur nicht parallel laufen.
- Begrenzte Vorschauen prüfen; Backend-Quellen und Prüffälle mit getrennten Fortschrittswerten nachtragen.
- Im Worker ausschließlich die ausgewählten Reparaturkandidaten einreihen, anschließend über den normalen Import mit höchstens 9.000 Erfolgen abarbeiten. Vorhandene Snapshot-Cursors und abgeschlossene Batchanfragen bleiben erhalten.
- Mehrere Overture-Quell-IDs desselben lokalen Businesses nacheinander bearbeiten. Die nächste ID darf die noch offene vorherige Reparatur nicht überschreiben. Nur eine bestätigte Verarbeitung für die erwartete Seiten-ID zählt als erledigt.
- Nach dem Worker-Durchgang den begrenzten Backend-Abgleich bei Bedarf wiederholen und offene Fehler/Prüffälle anhand des Berichts beurteilen.

9.000 pro Stunde ist als Obergrenze für einen später freigegebenen Reparaturablauf möglich. Ein wieder eingeschalteter normaler Timer beginnt einen abgeschlossenen Snapshot aber nicht von vorn und repariert diese Metadaten nicht von allein. Für die 17 Foursquare-Fehler genügt ein gezielter kleiner Wiederholungslauf. Ein dauerhaft wiederholter Vollimport ist nicht vorgesehen.

## OpenStreetMap bleibt lokale Vorschau

Die Quellenangabe ist nur ein Teil der OSM-Bedingungen. Bei einer öffentlich verwendeten Zusammenführung mit unserem Geschäftskatalog kann die ODbL zusätzlich die Bereitstellung der daraus abgeleiteten Geschäftsdaten verlangen. Technisch getrennte Tabellen mit verknüpften Daten beseitigen diese Frage nicht automatisch. Deshalb bleibt OSM entsprechend der Benutzerentscheidung ausschließlich lokale Vorschau und Vergleich; kein öffentlicher OSM-Import oder Export ist freigegeben.

Quellen: [OSM-Copyright und Attribution](https://www.openstreetmap.org/copyright), [OSMF-Leitlinie zu kombinierten Datenbanken](https://osmfoundation.org/wiki/Licence/Community_Guidelines/Collective_Database_Guideline_Guideline), [ODbL 1.0](https://opendatacommons.org/licenses/odbl/1-0/).

## Lokale Prüfung

- Gemeinsame Backend-Regression: 100 Tests mit 1.713 Assertions bestanden, darunter 15 neue Backfill-Fälle sowie bestehende Import-, Quellen-, Foursquare-, OSM- und Schließungsschutz-Tests.
- Bestehende Worker-Regression: 80 Tests bestanden (Overture-Mapper, Vorbereitung, Quelle, Identität, Vollscan, Quellenmetadaten und Foursquare).
- Zusätzliche Reparaturtests prüfen echte ImportService-Durchläufe mit zwei Quellen-IDs derselben Seite, selektive Foursquare-Stadtfehler, schreibgeschützte Vorschau, Grenzen, Rollback und die Bestätigung anhand abgeschlossener passender Batches.
- PHP-Formatprüfung und Git-Whitespace-Prüfung bestanden. Die Geschäftsseiten zeigen korrekt gespeicherte unbekannte Labels bereits als Text; dafür ist keine zusätzliche Frontend-Änderung erforderlich.

Diese Prüfungen ersetzen keinen späteren begrenzten Live-Probelauf nach dem ausdrücklich freigegebenen Deployment. Bis dahin sind die vorhandenen Live-Lücken noch nicht nachgetragen.
