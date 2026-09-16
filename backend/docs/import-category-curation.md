# Imported business category curation

This is a local catalog change. It does not start an import, deploy the application,
change timers, delete businesses, or discard original source descriptors.

## Reviewed observation snapshot

The input is the category portion of the existing import comparison-table export
dated **2026-09-16 16:06:07 UTC**. The complete decision ledger is
`resources/data/import-category-curation-2026-09-16.json`; it records each exact
provider/source key, original label, decision, target category where applicable,
and reason. Its category-input SHA-256 and observation count identify the reviewed
snapshot without depending on mutable production observation IDs.

| Source | Observations | Mapped | Excluded from business categories | Pending |
| --- | ---: | ---: | ---: | ---: |
| Overture | 642 | 530 | 98 | 14 |
| Foursquare | 619 | 413 | 185 | 21 |
| Data.gov.il | 1,026 | 968 | 38 | 20 |
| **Total** | **2,287** | **1,911** | **321** | **55** |

**88 business categories** were added to the central catalog, with Hebrew,
English, Russian and French labels. They cover missing business types such as
schools, universities, cinemas, museums, gyms, tattoo studios, fuel stations,
car washes, postal services, printing, laundry, optical shops and garden centers.
The new **Industry and agriculture** group contains 12 categories for production,
farms, industrial equipment, raw materials, wholesale food, storage and utilities.
The remaining additions use existing groups. The ledger lists every new key and
its group.

## Mapping rules and boundaries

- `config/import_category_aliases.php` contains only explicit reviewed mappings:
  `[provider => [exact_source_key => canonical_business_category_key]]`.
  A Foursquare category ID can never resolve an Overture key or a Gov description.
  Unknown future keys remain unmapped; there is no runtime fuzzy matching or
  matching based on part of a company name.
- Equivalent types share a useful catalog category: gelato, ice cream and frozen
  yogurt shops share the dessert category; school levels share Schools; vehicle
  dealer specializations share Vehicle dealerships. Existing suitable categories
  remain in use, including doctors, restaurants, garages and grocery stores.
  Fish markets and fishmongers use Fish and seafood shops, separately from restaurants.
- Foursquare hierarchy labels supplied with the observations are used to identify
  parents such as Physician. They are not displayed verbatim as new menu options.
  Overture primary and alternate category keys resolve independently through the
  same exact source-key map; their original metadata remains intact.
- Gov licence descriptions identify activities, not necessarily clean categories.
  Freight records mentioning food remain freight; waste transport maps to waste
  services. Incidental registration numbers never become category names.
  Explicit production, retail, repair or rental activity takes precedence over
  incidental storage in reviewed compound descriptions. Clearly unrelated mixed
  activities remain pending when one target cannot be justified.
- `excluded` means **not added to business-category menus**, not a deleted or
  rejected business. Geographic features, administrative areas, public transport
  infrastructure, religious/community institutions, campus subfacilities, events,
  teams and licence-only identifiers are retained in the ledger. Existing public
  source text and business records are unaffected by this category-only decision.
- `pending` retains broad or ambiguous labels such as Office, Travel and
  transportation, Rental service, an unspecified repair activity, or a licence
  listing several unrelated activities. They require more source/business context;
  creating a misleading specific category would lose meaning.

## Central catalog and frontend

`App\Support\CatalogTopics` defines the new topics and groups;
`CatalogTopicTranslations` defines all four language labels. New topics are
explicitly scoped to **business pages**, so they do not accidentally expand
product, service or event menus simply by inheriting a group's default scopes.

Frontend selectors already consume this shared catalog through
`frontend/src/composables/useCatalogTopics.js` and `/api/v1/catalog`.
There is no separate hardcoded frontend topic list or additional locale-message
copy to maintain for these entries. The backend catalog also supplies the topic
keys, localized labels and slugs used for catalog navigation and SEO pages.

The alias config is intended for the normal import normalization and the bounded
existing-data backfill. Resolving a label must preserve source metadata and must
not overwrite an owner-selected category. These integration protections are
tested separately with the import/backfill service.

## Validation

Run from `backend`:

```console
php artisan test --compact --filter='CatalogLocalizationTest|ImportCategoryCurationTest'
```

The tests require complete, unique decisions for all 2,287 observations, agreement
between the ledger and every runtime alias, valid business-scope targets, correct
new-topic group membership, unique catalog slugs, all four translations, and
representative cross-provider synonym/negative cases.

## Source taxonomy references

- [Overture Places schema](https://docs.overturemaps.org/schema/reference/places/place/)
  distinguishes basic category from the primary, hierarchy and alternate taxonomy
  entries. The snapshot's existing source keys are the evidence used here.
- [Foursquare Categories](https://docs.foursquare.com/data-products/docs/categories)
  describes category IDs, labels and the granular taxonomy used by its data
  products. Mappings use the labels attached to the observed IDs.

These are application catalog decisions, not corrections to either provider's
source taxonomy or changes to source licensing.
