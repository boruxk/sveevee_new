# Geprüfte Städte und Geschäftskategorien

Die lokalen Listen werden aus dem nur lesend exportierten Stand der Import-Vergleichstabellen vom 16.09.2026 ergänzt. Die Originaltabellen enthalten 4.736 Ortsbeobachtungen und 2.287 Kategoriebeobachtungen. Eine Beobachtung ist kein eindeutiger neuer Ort beziehungsweise keine neue Dropdownkategorie.

Ergebnis: **883 neue Orte (966 insgesamt)** und **88 neue Geschäftskategorien**. Die vollständigen Entscheidungen und Quellen stehen im [Ortsaudit](import-city-catalog.md) und im [Kategorieaudit](import-category-curation.md). Ungeklärt bleiben 2.299 Ortsbeobachtungen (einschließlich zwei Mehrdeutigkeiten) und 55 Kategoriebeobachtungen; sie bleiben erhalten.

## Zuordnungen und öffentliche Listen

- `config/locations.php` und die ergänzenden Ortsdaten liefern die kanonischen Orte. `config/import_city_aliases.php` ordnet geprüfte Schreibvarianten diesen Orten zu.
- `config/import_category_aliases.php` ordnet je Quellenanbieter den exakten ursprünglichen Kategorieschlüssel einem gültigen Geschäftskatalogschlüssel zu. Die Kategorie-API liefert die passenden Gruppen und Sprachbeschriftungen.
- Nicht verifizierte Ortsangaben, Regionen, Adressen, mehrdeutige Aktivitäten und unpassende POI-Typen werden nicht automatisch als öffentliche Listenwerte angelegt. Ihre ursprünglichen Beobachtungen bleiben zur weiteren Prüfung erhalten.
- Der Quellenimport und die getrennte Anlage genehmigter Foursquare-Prüffälle nutzen diese Zuordnungen für fehlende Kategorien und bekannte Ortsvarianten. Die ursprünglichen Quellenmetadaten werden dabei unverändert gespeichert.

## Vergleichstabellen nach dem späteren Deployment markieren

Die neuen Listen sind dateibasiert. Dieser zusätzliche, standardmäßig nur lesende Befehl vermerkt die geprüften Zuordnungen in den vorhandenen Vergleichstabellen:

```bash
php artisan business-import:resolve-catalog --kind=cities --limit=9000
php artisan business-import:resolve-catalog --kind=categories --limit=9000
```

Nach Prüfung derselbe Aufruf mit `--apply`. Ein vorhandener abweichender manueller Mappingwert wird als `conflict` gezählt und nicht überschrieben. `unresolved` bleibt offen. Optional grenzen `--provider` und `--after` den Durchgang ein; `next_after` und `eof` erlauben eine begrenzte Fortsetzung. Der Befehl verändert keine Geschäftsseiten oder ursprünglichen Labels.

## Bestehende importierte Geschäftsseiten nachtragen

Der bereits vorhandene `business-import:backfill-catalog` nutzt nun ebenfalls die geprüften Zuordnungen. Er ergänzt fehlende Kategorien und vereinheitlicht Ortsnamen nur, wenn der bestehende und der ursprüngliche Quellenort nachweislich dieselbe kanonische Stadt bezeichnen. Abweichende Orte, bestätigte Besitzer und gelöschte oder geschlossene Quellen bleiben geschützt.

```bash
php artisan business-import:backfill-catalog --provider=overture_places --limit=9000
php artisan business-import:backfill-catalog --provider=foursquare_places --limit=9000
```

Auch hier ist die Vorschau der Standard; ein Schreibdurchgang benötigt `--apply`. Die Begrenzung und Cursor-Fortsetzung bleiben unverändert. Diese Nachtragung ist von der monatlichen Suche nach neuen Quelldaten getrennt: Ein unveränderter Quellenstand wird durch den normalen Import nicht erneut vollständig importiert.

In diesem Arbeitsschritt werden ausschließlich lokale Dateien geändert und isolierte Tests ausgeführt. Kein Push, Deployment, Live-Mapping oder Live-Backfill wird automatisch gestartet.
