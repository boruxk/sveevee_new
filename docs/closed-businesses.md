# Monthly checks for closed businesses

These files prepare a local change. Installation and activation on production require a pushed commit and separate live authorization.

`worker remove-closed-businesses --config=config/worker.foursquare.json` reads the complete prepared Foursquare Israel snapshot in read-only mode and asks the authenticated backend to preview removals. It defaults to dry-run. Only `--apply` permits removals. `--refresh` first downloads a current snapshot; `--duckdb=PATH` selects the executable for that refresh. The command never resets any import cursor, retry queue or batch. It holds the shared Foursquare worker process lock across both refresh and closure processing.

Only an explicit valid `date_closed` on the exact source ID is evidence. Dates must be between 1900-01-01 and the snapshot release; the release cannot be in the future. Invalid evidence is counted as review and is not sent for deletion. An absent record, missing closure date or unusable business name never implies closure. Requests contain at most 100 source IDs. The backend independently protects claimed pages, verifies source associations and location evidence, and records idempotent results and tombstones. Existing Foursquare aliases from Overture are eligible for that same check.

Reports use command `remove-closed-businesses`, `used_sources: ["foursquare_places"]`, and a separate `closed_businesses_progress` object. `total` and `scanned` describe all snapshot records; `closed` counts records carrying closure evidence, including invalid evidence. The result counters are `invalid_evidence`, `would_remove`, `removed`, `already_removed`, `protected_claimed`, `review_required`, `unmatched` and `failed`. A failed request stops further requests; `failed` counts that request's unconfirmed records, which may already have committed partially on the backend. Repeating the command safely replays all source IDs with the same snapshot identity and receives durable backend results. Preview never advances an apply cursor. Deletions never increase the normal `imported` count. Reports and their normal admin-log outbox use the existing Foursquare state directory.

## Refresh and monthly scheduling

The optional `sveevee-closed-businesses.service` runs the closure command with `--refresh --apply`. Under one process lock it first calls the same remote exporter used by `prepare-foursquare.php`. It pins the current official Iceberg snapshot, downloads only Israel rows, validates the complete result and atomically publishes the SQLite file. Only successful preparation permits closure processing. A failed download, missing token, invalid export or unavailable DuckDB stops the service before any removals and produces a failed worker report. Merely reusing the old snapshot cannot discover new closures.

Deployment prerequisites, to check before enabling the timer:

- Backend closure API and its migration are deployed; the existing worker OAuth client has its existing business-import write scope.
- `/usr/local/bin/duckdb` is the installed DuckDB CLI, accessible to `sveevee-worker`. If installed elsewhere, change the reviewed `--duckdb` path before installation. The exporter uses its official Iceberg/httpfs extensions and writes downloads/extensions only inside the Foursquare state directory.
- `/etc/sveevee-worker/worker.env` retains the existing API credentials and data directory. `/etc/sveevee-worker/foursquare-download.env` contains the existing `FOURSQUARE_ACCESS_TOKEN`, provisioned separately with restricted permissions. Tokens belong in neither arguments, Git, JSON config nor reports. Previously copying a prepared snapshot to production did not require this token, so its availability must be checked explicitly.
- The Foursquare config resolves to `/var/lib/sveevee-worker/foursquare/foursquare.sqlite`; that directory and its reports/locks are writable by `sveevee-worker`.
- The monthly timer is a separate optional unit. The existing importer installer does not activate it. Do not re-enable exhausted ordinary import timers as part of this installation.

After authorization, install the two unit templates from `sveevee-worker/deploy/systemd/`, run `systemd-analyze verify` on them and `systemd-analyze calendar '*-*-01 04:35:00 Asia/Jerusalem'`, then reload systemd. First manually prepare a fresh snapshot and run the command without `--apply`; inspect `would_remove`, `protected_claimed`, `review_required`, `unmatched`, and invalid evidence. Only after that review enable the monthly timer. It runs on the first day of each month at 04:35 Israel time; `Persistent=true` catches up a missed activation. Activating a persistent timer can therefore trigger work immediately.

On an authorized applied run, verify the backend audit rows, protected-owner cases, public 404s for removed pages and source tombstones. A second preview of the same snapshot must report `already_removed` for the removed source IDs and must not create replacement pages. Laravel scheduling, Reverb and queue services are unrelated and remain untouched.

The backend API, database tables, protected-owner rules and standalone Artisan command are documented in [backend/docs/closed-businesses.md](../backend/docs/closed-businesses.md).
