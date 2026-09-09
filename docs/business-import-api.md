# Sveevee Business Import API

Die Business Import API ist eine reine Machine-to-Machine-API. Sie verwendet den OAuth-2.0-Client-Credentials-Grant und akzeptiert keine normalen Benutzer- oder Admin-Tokens.

## Hinweise zu Overture-Quelldaten

Geschaeftsdaten koennen aus Overture Maps Places stammen und von Sveevee hinsichtlich Auswahl, Kategorien, Namen, Adressen und Kontakten angepasst oder mit bestehenden Seiten zusammengefuehrt sein. Fuer die jeweiligen Quelldaten gelten [CDLA-Permissive 2.0](../frontend/public/data-sources/CDLA-Permissive-2.0.txt), [Apache 2.0](../frontend/public/data-sources/Apache-2.0.txt) beziehungsweise [CC0 1.0](https://creativecommons.org/publicdomain/zero/1.0/legalcode.en). Die [vollstaendige Foursquare-NOTICE](../frontend/public/data-sources/Foursquare-NOTICE.txt) ist Bestandteil dieser Dokumentation; sie ist zusammen mit den zutreffenden Lizenztexten bei einer Weitergabe zu erhalten. Copyright 2024 Foursquare Labs, Inc. All rights reserved. Overture hat die Foursquare-Daten in sein Schema ueberfuehrt (Aenderung vom 18.03.2026).

Die oeffentlichen Hinweise liegen nach dem Deployment unter `/data-sources/overture.html`. Originalquellen, Lizenzverweise und Ausgabe werden ausserdem in den Worker-Rohdaten gespeichert. Fehlender Betriebsstatus ist kein Nachweis eines geoeffneten Geschaefts. [Offizielle Overture-Quellenliste](https://docs.overturemaps.org/attribution/#places).

## Server einrichten

Nach dem Deployment werden die Tabellen migriert und einmalig die Passport-Schluessel erzeugt:

```bash
cd /var/www/sveevee/backend
php artisan migrate --force
php artisan passport:keys
```

Die Dateien `storage/oauth-private.key` und `storage/oauth-public.key` muessen dauerhaft erhalten bleiben. Sie duerfen nicht bei jedem Deployment neu erzeugt oder in Git gespeichert werden.

Einen schreibberechtigten Import-Client erstellt man so:

```bash
php artisan business-import:client "Business Research Worker 001"
```

Das Kommando zeigt Client-ID und Client-Secret genau einmal an. Fuer einen Client, der nur suchen und Dubletten pruefen darf:

```bash
php artisan business-import:client "Business Search Worker" --read-only
```

Ein kompromittierter oder nicht mehr benoetigter Client wird inklusive aller Tokens gesperrt:

```bash
php artisan business-import:revoke-client CLIENT_ID
```

## OAuth-Token abrufen

Token-URL: `https://sveevee.co.il/oauth/token`

```bash
curl --request POST 'https://sveevee.co.il/oauth/token' \
  --header 'Content-Type: application/x-www-form-urlencoded' \
  --data-urlencode 'grant_type=client_credentials' \
  --data-urlencode 'client_id=CLIENT_ID' \
  --data-urlencode 'client_secret=CLIENT_SECRET' \
  --data-urlencode 'scope=business:read business:write'
```

Das zurueckgegebene `access_token` wird als Bearer-Token gesendet. Tokens sind standardmaessig 60 Minuten gueltig. Der Worker sollte vor Ablauf ein neues Token abrufen und Secrets ausschliesslich in seinem Secret Store halten.

## Endpoints

Basis-URL: `https://sveevee.co.il/api/v1/business-import`

| Methode | Pfad | Scope | Zweck |
| --- | --- | --- | --- |
| `GET` | `/businesses` | `business:read` | Businesses suchen |
| `POST` | `/businesses/duplicates` | `business:read` | Dubletten vorab pruefen |
| `POST` | `/businesses` | `business:write` | Ohne `id` und mit `Idempotency-Key` erstellen, mit `id` aktualisieren |
| `PATCH` | `/businesses/{id}` | `business:write` | Business explizit teilweise aktualisieren |
| `POST` | `/businesses/batch` | `business:write` | 1 bis 1000 Businesses importieren oder aktualisieren |
| `POST` | `/worker-runs` | `business:write` | Abgeschlossenen Automation-Worker-Lauf idempotent an den Admin-Log melden |

Jede Antwort des Importbereichs enthaelt `X-Request-ID`. Serverseitig werden Client, Route, HTTP-Status, Laufzeit, Payload-Groesse und ein SHA-256-Payload-Hash protokolliert. Der Request-Inhalt und das Bearer-Token werden nicht im Audit-Log gespeichert.

Nur unbeanspruchte Business-Seiten koennen aktualisiert werden. Sobald ein Nutzer eine Seite uebernommen hat, antwortet die Import-API mit `409 Conflict`.

`POST /worker-runs` wird intern vom Automation-Worker verwendet. Seine `run_id` dient als Idempotenzkennung. Die Laufberichte erscheinen fuer Administratoren im Tab `Logs`; ein identischer Retry wird nicht doppelt gespeichert.

## Pflichtfelder und Updates

Beim Erstellen sind nur diese fachlichen Felder erforderlich:

- `name`
- `category_key`
- `address.city`

`type` ist optional und wird auf `business` festgelegt. Alle anderen Felder sind optional. Bei einem Update bleiben nicht gesendete Felder unveraendert. Ein explizit gesendetes `null` oder eine leere Liste kann ein optionales Feld beziehungsweise eine Liste leeren.

Die stabilen Werte aus dem bestehenden Sveevee-Katalog und der Standortkonfiguration muessen verwendet werden. Beispielsweise ist `professionals.electricians` ein `category_key`; `Tel Aviv` und `Ramat Aviv` sind kanonische Standortwerte.

## Idempotenz beim Einzelimport

Jeder neue Einzelimport ueber `POST /businesses` benoetigt den HTTP-Header `Idempotency-Key` mit einer neuen UUID. Der Key gilt pro OAuth-Client.

- Gleicher Key und identischer Payload: Sveevee erstellt keine zweite Seite und liefert die gespeicherte Page-ID mit `data.replayed: true` und `Idempotency-Replayed: true` zurueck.
- Gleicher Key und veraenderter Payload: Sveevee antwortet mit `409 Conflict`.
- Fehlender oder ungueltiger Key bei einem Create: Sveevee antwortet mit `422 Unprocessable Entity`.

Bei einem Update mit Sveevee-`id` ist der Header nicht erforderlich. `PATCH /businesses/{id}` und `POST /businesses` mit `id` koennen mit demselben Payload sicher wiederholt werden, da nicht gesendete Felder unveraendert bleiben.

## Business suchen

```bash
curl --get 'https://sveevee.co.il/api/v1/business-import/businesses' \
  --header "Authorization: Bearer $ACCESS_TOKEN" \
  --data-urlencode 'q=Example Business' \
  --data-urlencode 'city=Tel Aviv' \
  --data-urlencode 'category_key=professionals.electricians' \
  --data-urlencode 'per_page=25'
```

Moegliche Filter sind `id` (positive Sveevee-Seiten-ID), `q`, `name`, `phone`, `contact_email`, `category_key`, `city`, `neighborhood`, `page` und `per_page` bis 100. `id` ermoeglicht die eindeutige Wiedererkennung bereits importierter Filialen trotz spaeterer Quellkorrekturen.

## Dubletten pruefen

```bash
curl --request POST 'https://sveevee.co.il/api/v1/business-import/businesses/duplicates' \
  --header "Authorization: Bearer $ACCESS_TOKEN" \
  --header 'Content-Type: application/json' \
  --data '{
    "name": "Example Business",
    "phone": "03-0000000",
    "contact_email": "info@example.com"
  }'
```

Telefonnummern, E-Mail-Adressen, Unicode, Satzzeichen und Leerraeume werden fuer die Pruefung normalisiert. `matches[].matched_on` nennt die uebereinstimmenden Signale; `matches[].address` liefert die vorhandene Filialadresse.

Fuer Standortpruefungen immer auch `name` und `address` mit Stadt, Strasse und vorhandener Hausnummer senden. Gemeinsam genutzte Kontakte oder Websites verhindern keine getrennten Filialen: bekannte andere Namen, Staedte oder Strassenadressen werden aus den Standorttreffern entfernt, bevor das Ergebnis auf zehn Treffer begrenzt wird. Gleicher bereinigter Name am gleichen Standort bleibt eine Dublette; die Varianten des hebraeischen Firmensuffixes und freie/splitte Hausnummern werden dabei beruecksichtigt. Ohne ausreichende Adressdaten kann ein Treffer mehrdeutig sein und darf nicht automatisch einer Filiale zugeordnet werden. Reine Telefon-/E-Mail-Suchen liefern weiterhin moegliche Treffer. Diese Standortregeln gelten fuer die Business-Import-API; die bisherigen Regeln der normalen Seitenerstellung bleiben erhalten.

## Einzelimport

```bash
curl --request POST 'https://sveevee.co.il/api/v1/business-import/businesses' \
  --header "Authorization: Bearer $ACCESS_TOKEN" \
  --header 'Content-Type: application/json' \
  --header 'Idempotency-Key: a3f53b2a-7d4c-4c31-9f22-4ebd4c96ce11' \
  --data '{
    "type": "business",
    "name": "Example Business",
    "public_description": "Short public description.",
    "contact_email": "info@example.com",
    "phone": "03-0000000",
    "whatsapp": "97230000000",
    "website": "https://example.com",
    "category_key": "professionals.electricians",
    "address": {
      "street": "Example Street",
      "number": "10",
      "city": "Tel Aviv",
      "neighborhood": "Ramat Aviv"
    },
    "socials": {
      "facebook": null,
      "instagram": null,
      "tiktok": null,
      "telegram": null,
      "x": null
    },
    "service_areas": ["Tel Aviv", "Jerusalem"],
    "specialties": [
      "Electrical repairs",
      "Lighting installation",
      "Fault detection"
    ],
    "opening_hours": [
      {"weekday":"sunday","is_open":false,"opens_at":null,"closes_at":null},
      {"weekday":"monday","is_open":true,"opens_at":"09:00","closes_at":"17:00"},
      {"weekday":"tuesday","is_open":true,"opens_at":"09:00","closes_at":"17:00"},
      {"weekday":"wednesday","is_open":true,"opens_at":"09:00","closes_at":"17:00"},
      {"weekday":"thursday","is_open":true,"opens_at":"09:00","closes_at":"17:00"},
      {"weekday":"friday","is_open":true,"opens_at":"09:00","closes_at":"13:00"},
      {"weekday":"saturday","is_open":false,"opens_at":null,"closes_at":null}
    ]
  }'
```

Wenn dasselbe Objekt eine Sveevee-`id` enthaelt, behandelt `POST /businesses` es als partielles Update. Alternativ kann `PATCH /businesses/{id}` verwendet werden.

## 100er-Batch

Jeder Batch benoetigt eine neue UUID in `client_import_id`. Ein identischer Retry mit derselben UUID liefert das gespeicherte Resultat zurueck und erstellt keine weiteren Seiten. Dieselbe UUID mit einem veraenderten Payload wird mit `409` abgelehnt.

Dieses Node.js-Beispiel erzeugt und sendet exakt 100 Businesses:

```js
import { randomUUID } from 'node:crypto'

const token = process.env.SVEEVEE_ACCESS_TOKEN
const businesses = Array.from({ length: 100 }, (_, index) => {
  const number = String(index + 1).padStart(3, '0')

  return {
    type: 'business',
    name: `Imported Business ${number}`,
    contact_email: `business-${number}@example.com`,
    phone: `050-200-${String(index + 1).padStart(4, '0')}`,
    category_key: 'professionals.electricians',
    address: { city: 'Tel Aviv', neighborhood: 'Ramat Aviv' },
    socials: {},
    opening_hours: [],
    service_areas: ['Tel Aviv'],
    specialties: ['Electrical repairs']
  }
})

const response = await fetch(
  'https://sveevee.co.il/api/v1/business-import/businesses/batch',
  {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${token}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({ client_import_id: randomUUID(), businesses })
  }
)

console.log(response.status, await response.json())
```

In einem Batch erzeugen Objekte ohne `id` neue Seiten. Objekte mit `id` aktualisieren die entsprechende unbeanspruchte Business-Seite. Fehler eines Eintrags stoppen die anderen Eintraege nicht; das Ergebnis enthaelt pro Position den Status `created`, `updated`, `duplicate`, `invalid`, `claimed` oder `not_found`.
