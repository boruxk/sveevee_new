# Monthly source checks and bounded continuations

These changes are prepared locally. Push and a separate deployment authorization are required before changing production units, credentials or jobs.

| Source | Monthly check (Asia/Jerusalem) | Conditional continuation |
| --- | --- | --- |
| Overture | First day, 02:10 | Hourly at minute 10 |
| Foursquare | Second day, 02:40 | Hourly at minute 40 |

The monthly service runs `worker run --refresh --limit=9000`. It checks the official source version before invoking the existing snapshot preparer: Overture's [STAC latest release](https://docs.overturemaps.org/getting-data/#get-the-latest-release) and Foursquare's [authenticated Iceberg catalog](https://docs.foursquare.com/data-products/docs/access-fsq-os-places). The monthly Overture check deliberately requests the latest version regardless of the release pinned for manual preparation in the profile. Foursquare pins the exact Iceberg snapshot returned by that check. Refresh failures are persisted locally and submitted through the usual admin run-report API; unavailable report delivery remains in the existing outbox.

An unchanged version keeps the existing SQLite file and its cursor byte-for-byte. A new version is prepared and validated before atomic publication. Failed, empty, incomplete, older or suspiciously reduced exports leave the previous snapshot intact and fail the monthly check. A failed refresh does not silently import the old snapshot as if it were current.

The full-source delta comparison uses retained source evidence. New records and changed business facts are processed; passing 30 days or changing release/download timestamps alone does not reimport every old record. Original city/category descriptions, provenance aliases and other material metadata remain part of the comparison. Existing source IDs, protected pages, closed records and unresolved review decisions retain their existing guards.

Any eligible new or changed entry can be imported immediately. **9000 is the maximum successful creations/updates per run, not a minimum batch size.** API batches remain at most 100. The remaining source cursor or pending batch/candidate queue creates `snapshot-import.pending.json` inside the existing source state directory. The separate `*-continue.service` runs only while that marker exists, using the same worker lock. The marker also records `next_import_at`: only one automatic import may start per calendar-hour slot. The fixed minute-10/minute-40 timers therefore continue next hour despite seconds of scheduler jitter, while a monthly check coinciding with an hourly trigger cannot import another 9000 in the same hour. Early triggers do no import work.

Continuations read the prepared snapshot without routine upstream requests. At source EOF with no pending candidates/batches the marker is removed. Terminal reviews, claimed/closed records and terminal failures do not create endless automatic retries. Existing retryable batches retain their original request and UUID. If the monthly check finds a newer version while an older snapshot still has work, it preserves that older snapshot and records a deferred refresh. After the old work finishes, a continuation checks/publishes the newer version once and processes it under the same limits. It does not wait another month or discard an unexamined tail.

## Configuration and deployment prerequisites

- Preserve `/etc/sveevee-worker/worker.env`, both dedicated JSON profiles and all `/var/lib/sveevee-worker/{overture,foursquare}` data. No configuration migration, initial snapshot reset or broad failed/review reset is needed.
- Both services need `/usr/local/bin/duckdb` (or an explicitly reviewed alternative executable path), available to `sveevee-worker` with the existing preparation dependencies.
- Foursquare's monthly and continuation units also read `/etc/sveevee-worker/foursquare-download.env`. It must contain the valid existing `FOURSQUARE_ACCESS_TOKEN`, owned `root:sveevee-worker`, mode `0640`. At the read-only prerequisite check this file was absent on production; the valid existing token is held only in the ignored local worker `.env`. Provision only that token after separate deployment authorization, without logging or committing it. The installer does not invent or print a token. Missing credentials fail the version check; uploading an old prepared file alone does not enable future refreshes.
- The installer installs/backups both monthly and continuation unit pairs but enables or starts none. Stop the relevant timers, then let active jobs finish naturally before updating shared PHP code or runtime paths. Inspect any manual processes using the same locks.
- Production uses versioned release directories selected by `*.service.d/runtime.conf`; `/var/www/sveevee-worker` is an older real directory. Update effective `WorkingDirectory` and cleared/replaced `ExecStart` in runtime drop-ins for both main and new continuation services. Merely replacing base unit files will not override an existing `ExecStart` drop-in. Preserve `--refresh` on monthly services and `--continue-snapshot` on continuations, together with `--duckdb` and `--limit=9000`.
- Verify effective units with `systemctl cat`/`systemctl show`, all four timers with `systemd-analyze calendar`, and the units with `systemd-analyze verify` before daemon reload and any separately authorized activation. A persistent timer can trigger immediately when enabled. Leave Gov, Tel Aviv, OSM and closure timers in their explicitly chosen states.

Monthly unit pairs: `sveevee-overture.{service,timer}` and `sveevee-foursquare.{service,timer}`. Continuation pairs: `sveevee-overture-continue.{service,timer}` and `sveevee-foursquare-continue.{service,timer}`. Continuation `ConditionPathExists` paths must follow any nonstandard source state-directory override.

Check the Foursquare token's expiry before activation and renew it through the Places Portal before it expires. The monthly worker cannot extend an expired portal access token; replacing the protected environment-file value is a separate operational step.

The separate closure job remains inactive. Its current `--refresh` implementation publishes to the same Foursquare snapshot path. Before any future activation alongside these import jobs, give closure evidence its own snapshot file or add equivalent unfinished-import protection: a shared lock prevents parallel writes but alone does not prevent replacement between unfinished import runs. This monthly-import change does not activate or redesign that separate deletion workflow.

## Checks

`worker run --refresh --dry-run` and `--continue-snapshot --dry-run` are rejected: automatic preparation changes local snapshots and these commands manage continuation state. Use isolated configuration/state and fake upstream/API fixtures for a rehearsal. Ordinary `worker run --dry-run` remains available with its documented local-state behavior; the separate closure command retains its existing preview contract.

```bash
php -d xdebug.mode=off tests/monthly_refresh.php
php -d xdebug.mode=off tests/snapshot_delta.php
php -d xdebug.mode=off tests/job-config.php
```

After an authorized run, inspect the worker log's `Source snapshot version checked` result (`unchanged`, `refreshed` or `deferred`), the usual source progress report, the existing pending batch state and the marker. Review failures separately from remaining source rows. Neither absence from a new snapshot nor a release change alone is a deletion instruction; closure processing remains a separate explicit workflow.
