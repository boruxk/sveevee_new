# Public sitemaps

`https://sveevee.co.il/sitemap.xml` remains the submitted sitemap address. Its content is now a sitemap index. It references every generated child sitemap; no public page URLs or slugs change during this migration.

## Addresses

The sitemap index publishes these stable child URLs, which return the latest available part after deployment and initial generation:

| Content | First part |
| --- | --- |
| Static pages and scope hubs | `https://sveevee.co.il/sitemap.xml?part=static-0001` |
| Public user profiles | `https://sveevee.co.il/sitemap.xml?part=users-0001` |
| Business and community pages, all four locales | `https://sveevee.co.il/sitemap.xml?part=pages-0001` |
| Products, all four locales | `https://sveevee.co.il/sitemap.xml?part=products-0001` |
| Active public ads | `https://sveevee.co.il/sitemap.xml?part=ads-0001` |
| Catalog topics and nonempty locations | `https://sveevee.co.il/sitemap.xml?part=catalog-0001` |
| Nonempty localized market pages | `https://sveevee.co.il/sitemap.xml?part=market-0001` |

Additional parts use `-0002`, `-0003`, etc. Empty families have no part and return 404. The index contains the exact complete list and does **not** add a `generation` parameter. Each stable URL follows the current completed snapshot, so the index's child links do not expire when old generation directories are cleaned up. If a family shrinks and a numbered part no longer exists, that part returns 404 and is omitted from the latest index.

Older links with both `part` and `generation` remain supported while that generation is retained. They serve the specified historical snapshot; they can return 404 after retention cleanup and are not published in the current index. Separate requests to stable child URLs may cross a generation change, so stable URLs do not promise a single pinned snapshot across an entire crawl.

## Caching and content dates

| Response | Cache policy | Validator |
| --- | --- | --- |
| `/sitemap.xml` index | `public, max-age=300` | ETag identifies the current generation's index |
| Stable child, for example `?part=pages-0001` | `public, max-age=300, must-revalidate` | ETag identifies the current generation and part |
| Retained legacy `?part=pages-0001&generation=...` | `public, max-age=3600` | ETag identifies that fixed generation and part |

`If-None-Match` returns 304 only when the requested response still has the same ETag. Publishing a new generation changes the index and current-child validators even when their URLs, XML text, or filesystem modification second happen to match. The index may send `Last-Modified`, but its conditional response decision deliberately requires `If-None-Match`; a timestamp alone must not hide a newly published snapshot. GET and HEAD expose the appropriate validators, while HEAD and 304 responses contain no body.

URL-level `<lastmod>` comes from known content modification dates. Static entries without a reliable content date omit it; catalog/market location entries use the relevant records' dates. Regenerating the sitemap alone does not invent a newer content modification date.

## Generation and limits

Run from `backend`:

```sh
php artisan sitemap:generate
```

The existing Laravel scheduler runs this command hourly. New users, pages and listings enter the next successful generation automatically; edits, bans, deletions and expired ads are reflected then as well. No sitemap needs to be added manually when another part becomes necessary.

Every file is split **before** exceeding either 50,000 URLs or 52,428,800 bytes (50 MB uncompressed). The byte count includes UTF-8, XML escaping, image metadata and the XML wrapper. A single impossible-to-fit entry fails generation explicitly, preserving the previous generation rather than truncating URLs.

Database rows are read in batches of 200, with maximum IDs captured at the start so concurrent imports cannot extend a run indefinitely. Catalog/location deduplication uses a temporary on-disk SQLite database. The web route reads generated files and never rebuilds the sitemap or loads public records during a crawler request. Parts are compressed on disk and streamed as ordinary XML to clients.

Only a complete generation replaces `storage/app/sitemaps/current.json`, using an atomic rename. A filesystem lock prevents concurrent builders. Previous generations remain available for seven days, then generation removes expired snapshots. Settings are in `backend/config/sitemap.php`.

## Deployment

Deploy only after the user's push and explicit live-update request.

1. Ensure PHP CLI and PHP-FPM have SQLite/PDO SQLite and zlib support. The backend web/scheduler user must be able to write `storage/app/sitemaps`.
2. Refresh Laravel's configuration cache to include `config/sitemap.php`.
3. Generate the first snapshot as the backend user before completing the deployment:

   ```sh
   sudo -u www-data php artisan sitemap:generate
   ```

4. Verify the existing scheduler invokes `php artisan schedule:run` every minute as the backend user. `php artisan schedule:list` must include the hourly `sitemap:generate` command. If the scheduler is absent, install its minute trigger during the authorized deployment; declaring a Laravel schedule alone does not start it.
5. Fetch `/sitemap.xml`, follow all its child links, and confirm HTTP 200, valid XML and both limits. Published child links must contain `part` without `generation`. Check the cache headers and ETags above; unchanged conditional requests should return 304, and requests with an older generation's ETag should return current content after regeneration. Query-parameter children use the existing `/sitemap.xml` Nginx location, so no new XML path routing is required.

Before the first successful generation, `/sitemap.xml` deliberately returns 503 with `Retry-After: 300`, rather than attempting the previous memory-intensive request-time build. Generation failures keep the last successful index available.

Keep the existing `robots.txt` sitemap line and the existing Search Console sitemap entry. After fixing the previous HTTP 500, resubmit the same `/sitemap.xml` URL in Search Console. Existing indexed page URLs are unchanged; the sitemap split itself does not remove them from Google's index.

References: [Google sitemap index guidance](https://developers.google.com/search/docs/crawling-indexing/sitemaps/large-sitemaps), [Google sitemap report and resubmission](https://support.google.com/webmasters/answer/7451001).

## Validation

```sh
php -d xdebug.mode=off vendor/bin/phpunit --filter Sitemap
```

The tests cover count/byte boundaries, escaping and images, all language URLs, automatic inclusion on regeneration, visibility and deletion, stable child addresses after old-generation cleanup, retained legacy links, ETag revalidation even within the same filesystem second, truthful content dates, failed-publication recovery, response streaming and malformed parameters.

An isolated SQLite scale check on 2026-09-10 generated all 681,048 localized URLs for 170,262 synthetic pages under `memory_limit=128M`. Generation took 91.75 seconds and peaked at 38 MB of PHP memory. The 14 page parts contained at most 50,000 URLs and 9,800,172 uncompressed bytes each. XMLReader verified every page/locale exactly once and checked each file's actual decoded byte count. This is a local synthetic benchmark, not a production timing guarantee.
