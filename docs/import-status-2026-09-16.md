# Importprüfung am 16.09.2026

Die letzten fünf geprüften Läufe hatten jeweils null neue Imports, aber unterschiedliche Ursachen. Die Prüfung erfolgte live lesend; es wurden keine Businesses gelöscht oder Importcursors zurückgesetzt.

| Quelle | Tatsächlicher Stand | Live-Timer nach der Prüfung |
| --- | --- | --- |
| Overture | Aktueller Snapshot vollständig verarbeitet: 162.913/162.913, keine offenen oder fehlerhaften Kandidaten | `sveevee-overture.timer` weiterhin disabled/inactive |
| Foursquare | Aktueller Snapshot vollständig gescannt: 109.757/109.757; 5.247 geschlossen und 14 ohne brauchbaren Namen. Weitere Ergebnisse: 68.928 neu, 2.179 aktualisiert, 33.372 zur Prüfung und 17 Validierungsfehler; keine offene Importqueue | `sveevee-foursquare.timer` auf Benutzerauftrag disabled/inactive gesetzt |
| Tel Aviv | Noch nicht durchlaufen. Abruf endet bei HTTP 571 mit ausdrücklicher geografischer Zugriffsbeschränkung auf Israel. Cursor unverändert bei 0 | `sveevee-tel-aviv.timer` auf Benutzerauftrag disabled/inactive gesetzt |
| data.gov.il | Beersheba 3.046/3.046 verarbeitet. Landesweites Register noch bei Offset 0: der Import lehnte geschätzte Gesamtzahlen ab. Eine kleine Live-Probe lieferte HTTP 200, einen Datensatz und geschätzt 731.119 Gesamtzeilen | `sveevee-worker.timer` bleibt aktiv, alle zehn Minuten; Codekorrektur zunächst lokal |

Foursquare ist damit durchgesehen, aber nicht jeder Datensatz wurde als neue Seite angelegt: geschlossene/ungültige Einträge, Überschneidungen und Fehler sind getrennt zu betrachten. Neue Datenstände benötigen einen neuen vorbereiteten Snapshot. Ein Timer allein erneuert keinen abgeschlossenen Snapshot.

## Lokale Korrekturen

- Gov akzeptiert `total_was_estimated=true`. Eine Schätzung bestimmt niemals das Ende des Scans: er liest bis zur leeren Antwort weiter, mit weiterhin höchstens zehn Datensätzen je Quellenabfrage, zehn Abfragen und zehn erfolgreichen Imports je Lauf. Dauerhafte Seitenqueues, ACKs, Cursor und Wiederholungsprüfungen bleiben erhalten. Exakte Gesamtzahlen behalten ihre bisherigen Konsistenzprüfungen.
- Foursquare und OSM laden die vom Dubletten-Endpunkt bestätigte Seite direkt über ihre ID. Dadurch löst eine nicht im Stadtkatalog vorhandene Quellenstadt keine zweite, unzulässige Stadtsuche aus. Die anschließende Prüfung der tatsächlichen Seitenadresse und des Besitzers bleibt bestehen. Das behebt den gefundenen Codepfad für FSQ-Fehler `city`; die 17 bereits fehlgeschlagenen Live-Kandidaten sind damit noch nicht erneut verarbeitet.
- `source_closed` ist ein eigener terminaler Workerstatus und wird nicht als wiederholbarer Importfehler gezählt.

Laravel-Scheduler, Queue, Reverb und sonstige Timer wurden nicht gestoppt. Neue OSM- und Monats-Timer sind nur Vorlagen; keine Aktivierung oder Bereinigung fand live statt. OSM bleibt laut anschließender Benutzerentscheidung ausschließlich für lokale Vorschau und Vergleich vorgesehen. Öffentliche OSM-Imports sind standardmäßig gesperrt; ein öffentlicher ODbL-Export wurde nicht beauftragt.

Die CKAN-Schnittstelle dokumentiert geschätzte Gesamtzahlen ausdrücklich: [CKAN DataStore API](https://docs.ckan.org/en/2.11/maintaining/datastore.html).

## Prüfung der Änderungen

Die Gov-Cursorfälle wurden mit unterschätzten/überschätzten Zählwerten, Unterbrechungen und maximal zehn Datensätzen je Anfrage getestet. Ein zusätzlicher lokaler Abruf mit dem korrigierten Adapter endete bei genau einer Quellenanfrage mit HTTP 404; eine erfolgreiche Integration mit dem echten Gov-Endpunkt konnte damit lokal nicht bestätigt werden. Die frühere kleine Probe vom Live-Server lieferte HTTP 200. Der behobene Schätzwertfehler und vorübergehende HTTP-Fehler sind getrennte Ursachen; nach dem späteren Deployment ist ein begrenzter Live-Lauf zu prüfen.

Backend-Schutz-, Import-, API-Log- und SEO-Tests sowie Worker-Regressionen und Frontend-Build wurden lokal geprüft. Drei SEO-Symlinkfälle sind unter dieser Windows-Umgebung mangels Symlink-Rechten übersprungen.
