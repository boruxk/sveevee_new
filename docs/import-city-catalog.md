# Reviewed imported localities

The local catalog now contains **966 localities: 83 existing names and 883 additions**. Existing canonical names and neighborhoods remain unchanged. New localities have no invented neighborhoods. The UI sorts choices by their displayed label; Hebrew uses the official Hebrew name, while English, Russian and French retain the application's existing international-name convention.

The complete 16 September 2026 export contains 4,736 source-city observations. Every row has a disposition in `backend/resources/data/import-city-curation-2026-09-16.json`:

| Disposition | Observations | Action |
| --- | ---: | --- |
| New verified locality | 2,110 | Map to one of the 883 added canonical names |
| Existing city / spelling alias | 228 | Map to an existing canonical name |
| Not a locality | 99 | Do not add to the dropdown |
| Ambiguous official name | 2 | Keep for manual review |
| No unique official name match | 2,297 | Keep for manual review |

These are observation counts across both providers, not numbers of distinct towns. Unresolved values are retained in the import review tables and in the original business address; they are not silently replaced or used to reject the business.

## Official reference and matching policy

The reference is the Israeli Central Bureau of Statistics [Geography Dictionary API](https://www.cbs.gov.il/en/cbsNewBrand/Pages/API-Dictionary.aspx), fetched on 16 September 2026. Its current locality table identifies the data year as **2024**. Six pages of at most 250 rows yielded 1,490 official records. The compact name/code/type snapshot is committed as `backend/resources/data/import-city-reference-cbs-2024.json`. The endpoint URLs are also recorded in the data manifests.

Only named residential locality types below 500 are eligible, excluding type 460 (tribal group codes). Types 510 (place), 520 (occupational center) and 530 (collective code) are excluded. Institutional residential villages remain eligible when CBS assigns an actual locality code. The separate official locality-types endpoint verifies these distinctions. Existing geographic coverage is preserved.

Matching uses official Hebrew/international names after case, punctuation and whitespace normalization. Explicit, limited equivalents cover common locality prefixes (Kfar/Kefar, Beit/Bet, Ein/En, Kiryat/Qiryat, Sde/Sede, Neve/Newe), a leading kibbutz/moshav descriptor, a terminal `, Israel`, and individually reviewed orthographic aliases. There is no fuzzy name or nearest-place assignment. Qualifiers remain when they distinguish official codes: for example, Kinneret (Moshava) and Kinneret (Qevuza) are separate entries. Official codes 6400 and 9100 keep the existing application names Herzliya and Nahariya.

`backend/resources/data/import-city-catalog.json` contains each accepted canonical name, CBS code/year/type, Hebrew label, whether it is new, and all accepted aliases. `config/locations.php` appends only the additions. `config/import_city_aliases.php` exposes the same canonical-to-alias contract as before. `frontend/src/utils/importedCityLabels.json` supplies Hebrew labels; existing UI labels take precedence where already defined.

## Runtime and deployment

`GET /api/v1/locations` returns only configured city and neighborhood choices. It no longer reads every page/profile/ad to construct these lists, so arbitrary imported regions or street values cannot become shared city choices. Existing page address text is still returned and displayed normally.

This change is local preparation only. No live rows, pages or timers were changed. After the user pushes and authorizes deployment, refresh the Laravel configuration cache and rebuild the frontend as usual. The separate catalog-resolution/backfill command can then apply accepted mappings to existing import observations and eligible business metadata; adding these lists alone does not mutate those records. Review the documented command's dry-run report before an authorized apply. Closed-source and confirmed-owner safeguards remain in place.

Verification covers all 4,736 dispositions and their accepted aliases, official reference types, stable canonical names and unique city slugs, bilingual data parity, distinct qualified localities, exclusion of raw database values from the locations API, preservation of raw public business addresses, and absence of page/profile/ad scans when loading choices.
