# Freigegebene Foursquare-Prüffälle als zusätzliche Geschäftsseiten

Am 16.09.2026 hat der Benutzer ausdrücklich die Anlage der 33.372 bestehenden Foursquare-Prüffälle als zusätzliche Einträge beauftragt. Die bestehende Kohorte endet live bei Review-ID `33372`. Diese Entscheidung gilt für diese Kohorte; der normale Import behält seine bisherigen Abgleichregeln.

Der vorherige Commit `ba17c26` wurde bereits live bereitgestellt. Die hier beschriebenen neuen Befehle und die zusätzliche Migration benötigen einen weiteren Push und das danach freigegebene Deployment.

## Verhalten

- Eine bisher nicht direkt zugeordnete FSQ-ID erhält eine eigene unbeanspruchte Geschäftsseite aus ihrem gespeicherten Quellenpayload, auch bei Überschneidungen von Name, Kontakt oder Overture-Alias.
- Vorhandene Seiten, bestätigte Besitzer und Overture-Aliasse werden weder überschrieben noch verschoben.
- Eine bereits direkt gespeicherte FSQ-ID wird nicht nochmals angelegt. Geschlossene, gelöschte/gesperrte oder ungültige Quellen werden übersprungen und im Ergebnis gezählt.
- Unbekannte Städte/Kategorien werden wie bei normalen Vollimports als Text auf der neuen Seite und in den Vergleichstabellen gespeichert.
- Jede Anlage und ihre Quellenzuordnung sind eine atomare Transaktion. Der Review erhält `status=imported_separately` und einen dauerhaften Entscheidungsbeleg unter `resolution`.
- Die bisherigen Importberichte bleiben historische Berichte. Ein gesonderter Abgleich übernimmt anschließend die bestätigten Page-IDs in den Worker; dazu werden keine Rezensionen oder Imports erneut versucht.

## Backend

Zuerst die Migration `2026_09_16_000300_add_resolution_to_business_import_match_reviews.php` ausführen. Danach aus dem Backend-Verzeichnis eine begrenzte Vorschau, zum Beispiel:

```bash
php artisan business-import:import-foursquare-reviews --after=0 --through=33372 --limit=10
```

Erst nach erfolgreicher Prüfung der Vorschau die ausdrücklich beauftragte Anlage beginnen:

```bash
php artisan business-import:import-foursquare-reviews --after=0 --through=33372 --limit=9000 --apply --decisions=/protected/path/foursquare-decisions-0001.json
```

`--through=33372` bei sämtlichen Folgeläufen unverändert lassen und `--after` auf den ausgegebenen Wert `next_after` setzen. `eof=true` kennzeichnet das Ende dieser Kohorte. Bei maximal 9.000 neuen Einträgen pro Stunde liegen zwischen schreibenden Folgeläufen mindestens 60 Minuten. Das CLI selbst ist kein Timer und begrenzt pro Aufruf.

Der Manifestpfad muss neu sein und in einem bestehenden beschreibbaren, nicht öffentlichen Verzeichnis liegen. Nach Abbruch oder fehlgeschlagenem Export denselben Abschnitt mit einem neuen Manifestnamen wiederholen: vorhandene Quellenzuordnungen verhindern neue Dubletten; die dauerhaft gespeicherten Belege können erneut exportiert werden. Bei Fehlern den JSON-Bericht und den letzten bestätigten Cursor prüfen. Übersprungene Einträge bleiben gesondert sichtbar und werden nicht als erfolgreiche Anlagen gezählt.

## Worker-Abgleich

Das Manifest kommt ausschließlich vom bestätigten Backendlauf, nicht aus einer beliebigen Fremdquelle. Aus einem lesbaren Worker-Verzeichnis mit dem dedizierten Foursquare-Profil:

```bash
php bin/reconcile-foursquare-reviews.php --config=/etc/sveevee-worker/worker.foursquare.json --env-file=/etc/sveevee-worker/worker.env --manifest=/protected/path/foursquare-decisions-0001.json --limit=9000
```

Ohne `--apply` bleibt die Datenbank schreibgeschützt. Nach Prüfung denselben Befehl mit `--apply` ausführen. Es werden ausschließlich eindeutig passende lokale `review`-Zeilen mit unverändertem Quellen-Metadatenhash und ohne offene Batches zugeordnet. Die Änderung und der vollständige vorherige Workerzustand stehen atomar im lokalen Journal `foursquare_review_reconciliations`.

Derselbe Manifestlauf kann wiederholt werden. `already_reconciled`, `waiting`, `protected` und `blocked` sind eigene Ergebniszähler. Payloads, Forschungsdaten, abgeschlossene Batches, Cursor und historische Berichte bleiben erhalten. Die CLI führt weder Quellen- noch Sveevee-API-Aufrufe aus.

## Overture-Reparatur

Bei der Live-Vorschau fiel eine langsame SQLite-Abfrage auf: Der Quellenindex löste pro 100er-Portion einen erneuten Scan mit Sortierung aus. Live wurde für das bereits bereitgestellte Release ein zusätzlicher Index `(adapter,id)` ergänzt; danach dauerte die vollständige Vorschau nur wenige Sekunden. Die lokale Codekorrektur erzwingt für neue Installationen einen direkten Primärschlüsselbereich ohne zusätzliche Datenbankänderung, auch in der schreibgeschützten Vorschau.

## Prüfung

- Backend: 110 Tests, 1.813 Assertions bestanden, einschließlich zehn neuer Tests für die freigegebene separate Anlage.
- Worker-Abgleich: sieben Tests, 37 Assertions bestanden; Hashformat mit dem tatsächlichen Backend verglichen.
- Overture-Reparatur: elf Tests, 57 Assertions bestanden; SQL-Ausführungspläne und unveränderte Portionierung geprüft.

Es wird kein dauerhafter Import-Timer durch diese Befehle aktiviert. OSM bleibt weiterhin ausschließlich lokale Vorschau; die Entscheidung über öffentliche OSM-Nutzung und deren Lizenzpflichten ist davon getrennt.
