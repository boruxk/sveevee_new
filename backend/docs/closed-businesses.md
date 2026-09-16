# Remove closed businesses

The backend accepts explicit Foursquare closure evidence. A missing record in a later snapshot is never evidence of closure. Only a valid `date_closed` on or before the snapshot release (and no future release) is accepted, with `country: IL` and a lowercase 24-character Foursquare ID.

This feature is prepared locally. Deployment, applying removals and activating a monthly timer are separate operations requiring the user's authorization.

## Preview and apply

`POST /api/v1/business-import/closed-businesses` uses the existing registered OAuth client and `business:write` scope. Omit `dry_run` or set it to `true` to preview. Only explicit `false` applies changes.

```json
{
  "snapshot_id": "123456789",
  "release": "2026-09-01",
  "dry_run": true,
  "businesses": [
    {
      "source_id": "0123456789abcdef01234567",
      "date_closed": "2026-08-15",
      "country": "IL",
      "address": {"city": "Haifa", "street": "Example Street 10"}
    }
  ]
}
```

A request contains 1–100 distinct IDs. Optional original address fields are compared for conflicts, not used to guess matches. Evidence permits street 300, city/neighborhood 120 and number 40 characters. The normal import field limits remain unchanged.

Results contain `dry_run`, `snapshot_id`, `counts` and per-source `items` with one of: `would_remove`, `removed`, `already_removed`, `protected_claimed`, `review_required`, `unmatched`. Review/protected results remain public and require a separate human decision. Preview changes no pages or closure records; ordinary API access auditing still occurs.

The local artisan alternative reads JSONL records of the same shape:

```sh
php artisan business-import:remove-closed /path/closures.jsonl --snapshot=123456789 --release=2026-09-01 --limit=100
```

It defaults to preview. `--apply` enables removal; `--after=N` resumes after an input line, and `--limit` bounds each invocation to 1–1,000 records, processed in batches of at most 100. Output includes `next_after`, `eof` and counts.

The worker's `remove-closed-businesses` command reads its validated, complete local Foursquare snapshot and sends these batches. Its monthly template refreshes the snapshot first; an old snapshot alone is not a current closure check.

## Matching, protection and audit

Exact direct Foursquare associations and record-level Overture Foursquare aliases must resolve to one business page. Conflicting or missing association targets go to review. No name-only, shared-contact or fuzzy matches authorize deletion. Claimed pages, approved ownership requests, transferred users and pages no longer owned by an import service account are protected. Apply locks the relevant sources, aliases and page before the final decision.

Eligible pages use the existing `PageDeletionService` inside a database transaction. Closure state, a page/source archive and an immutable snapshot event are committed together with deletion; media cleanup occurs after commit. Existing source mappings retain their IDs after page deletion.

Tables added by `2026_09_16_000100_create_business_import_closures_tables.php`:

- `business_import_closures`: first/last observation, current outcome and removed page archive.
- `business_import_closure_events`: original evidence, snapshot/release, actor and outcome, one event per source/snapshot.
- `business_import_source_tombstones`: every source ID and alias associated with a removed page; unmatched confirmed closed Foursquare IDs are also suppressed.

Replaying identical evidence is safe and reports `already_removed` for a previously removed page. Changed evidence for the same source and immutable snapshot returns `closure_snapshot_conflict`. Requests commit per record, so after a transport failure prior rows can be replayed safely. New snapshots retain separate audit events. Reopening a source is not automatic.

All source imports and duplicate preflights check tombstones, including new Overture IDs carrying a suppressed Foursquare/OpenStreetMap alias. A blocked import returns HTTP 409 with `source_closed`; batch items retain this terminal status.

## OpenStreetMap enrichment

OpenStreetMap currently remains a **private preview/comparison only**. Public writes are disabled by default through `business_import.osm_public_import_enabled` (`BUSINESS_IMPORT_OSM_PUBLIC_IMPORT_ENABLED=false`); they return `source_disabled`. Duplicate preflight remains available. Enabling public imports requires the user's later explicit approval of the ODbL publication consequences. The following enrichment behavior is implemented and tested locally behind this gate.

`osm_places` uses exact `node/ID`, `way/ID` or `relation/ID` identifiers and matching official OpenStreetMap object URLs. Existing records use the conservative Foursquare matching rules and record-level Overture OpenStreetMap aliases; shared names/contacts alone remain review cases. Missing fields can be added, while existing data and claimed pages remain protected.

Raw opening hours remain in `setup.imported_opening_hours` and public `opening_hours_raw`. Complex expressions are retained without inventing a partial weekly schedule. Public `source_attributions` contains the fixed OpenStreetMap copyright and ODbL links; caller-provided link values cannot override them. The `2026_09_16_000200_backfill_overture_openstreetmap_aliases.php` migration backfills existing exact aliases in chunks of 200 source rows.
