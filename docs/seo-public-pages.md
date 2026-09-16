# Public page HTML routing

The homepage and public business, community, and product URLs must reach Laravel before Nginx considers the frontend SPA or old prerendered HTML files. Laravel renders the correct homepage or one requested record into the current frontend shell, supplies its canonical URL and metadata, and decides redirects, visibility, and HTTP status. Browsers and crawlers use the same route; there is no User-Agent condition. These public HTML routes exclude sessions and contain no personalized payload.

This is a local deployment proposal. Deploy only after the changes have been pushed and the user separately authorizes the live update. The website's Nginx routing was inspected read-only on 2026-09-16; no live files, services, or data were changed.

## Paths and known deployment values

| Item | Value and evidence |
| --- | --- |
| Backend project | `/var/www/sveevee/backend`, documented in `docs/business-import-api.md` and `ops/realtime/supervisor/sveevee-reverb.conf` |
| Laravel entry point | `/var/www/sveevee/backend/public/index.php`, verified in the active website API and sitemap FastCGI locations |
| Frontend shell | `frontend/dist/index.html` relative to the repository; `SeoPrerenderService::resolveDistPath()` defaults to `base_path('../frontend/dist')` |
| Website frontend root | `/var/www/sveevee/frontend/dist`, verified in the active HTTPS website server block |
| Active website vhost file | `/etc/nginx/sites-enabled/sveevee`; resolve its symlink before backing up or editing the actual vhost |
| Website PHP-FPM target | `unix:/run/php/php8.4-fpm.sock`, verified in both the active website API and sitemap locations |

`.codex/live-server.md` supplies the documented SSH access alias, not the website routing. The verified values above came from a server-side filtered `nginx -T` inspection of the website vhost, without exporting credentials or unrelated service configuration. Recheck them during deployment if the PHP pool or release layout has changed.

## Nginx include

Install both reviewable files so the exact homepage location and the detail regex share one set of FastCGI parameters:

| Repository file | Installed file |
| --- | --- |
| [`ops/seo/nginx/public-pages.conf`](../ops/seo/nginx/public-pages.conf) | `/etc/nginx/snippets/sveevee-public-pages.conf` |
| [`ops/seo/nginx/public-html-fastcgi.conf`](../ops/seo/nginx/public-html-fastcgi.conf) | `/etc/nginx/snippets/sveevee-public-html-fastcgi.conf` |

Include only `sveevee-public-pages.conf` once inside the existing `sveevee.co.il` HTTPS `server` block. It includes the shared FastCGI file inside its two locations. The verified vhost allows this directly after its Reverb include and before any regex locations:

```nginx
# Place before broader regex locations, including static-file extension rules.
include /etc/nginx/snippets/sveevee-public-pages.conf;
```

The shared FastCGI snippet already contains the verified PHP socket and backend paths. Do not alter the website's frontend root to the backend public directory: only the homepage and public-detail locations execute the backend entry point. The inspected configuration currently uses `location / { ... try_files $uri $uri/ /index.html; }`; retain that prefix for all other SPA paths. It has no conflicting exact homepage location or locale/public-detail `^~` prefixes.

| URL shape | Handler |
| --- | --- |
| `/` | Laravel public homepage HTML; exact match only |
| `/{he\|en\|ru\|fr}/business/{slug}` | Laravel public business HTML |
| `/{he\|en\|ru\|fr}/community/{slug}` | Laravel public community HTML |
| `/{he\|en\|ru\|fr}/product/{slug}` | Laravel public product HTML |
| `/business/{slug}`, `/community/{slug}`, `/product/{slug}` | Laravel legacy canonical redirect |
| `/pages/{slug}` | Laravel legacy page redirect using the stored page type |
| `/{he\|en\|ru\|fr}/pages/{slug}` | Laravel legacy page redirect preserving the explicit locale |
| Any listed shape with one trailing slash | Laravel canonical handling |
| `/business`, `/community`, `/admin`, `/api/...`, `/assets/...`, `/catalog/...`, `/sitemap.xml` | Existing handlers |

`{slug}` is one path segment and may be a numeric legacy ID. Query parameters remain available to Laravel through `QUERY_STRING` and the original `REQUEST_URI`; Nginx does not rewrite the public path to `/index.php` in the browser.

The shared snippet sets `SCRIPT_FILENAME` and `SCRIPT_NAME` explicitly, uses the fixed backend frontcontroller, and has no filesystem fallback. It disables inherited FastCGI caching and error interception so current visibility decisions and 404/503 responses reach the client. Application response headers determine caching.

Nginx checks the most specific prefix and then regex locations in configuration order. A matching `^~` prefix or an exact location can prevent this regex from running. During deployment inspect existing `/he/`, `/en/`, `/ru/`, `/fr/`, `/business/`, `/community/`, `/product/`, `/pages/`, and catch-all prefixes; narrow or replace only conflicting rules. Merely adding the include above a conflicting `^~` location does not fix its precedence. Also inspect server-level rewrites, `error_page` directives, and any CDN HTML cache. A stale file must not win, and missing records must not become a generic HTTP 200 SPA page. [Nginx request processing](https://nginx.org/en/docs/http/request_processing.html), [location precedence](https://nginx.org/en/docs/http/ngx_http_core_module.html#location), [FastCGI parameters and error handling](https://nginx.org/en/docs/http/ngx_http_fastcgi_module.html).

## Deployment sequence after authorization

1. Record the intended commit and back up the active website vhost, its relevant includes, and the currently deployed frontend shell/assets. Inspect the website's effective Nginx configuration locally on the server; do not copy credentials or the complete configuration into shared logs.
2. Deploy the matching backend and frontend build. The built `dist/index.html` must be readable by PHP-FPM, and every hashed CSS/JS asset it references must be available through the existing frontend static handler. Publish the shell and its assets together; do not delete old referenced assets while earlier HTML responses may still be in use.
3. Refresh the application's normal configuration/route caches. Confirm `php artisan route:list` shows the public GET/HEAD homepage at `/`; `--path=business`, `--path=community`, `--path=product`, and `--path=pages` must show the detail routes. Verify the configured public origin is `https://sveevee.co.il`. Each detail request renders one record and limited related records; do not call `seo:prerender-public-pages` from the HTTP path. The CLI command now builds only marketing/legal/catalog entry files. Imported and edited business records become visible on the next request, with no import hook, record-wide HTML export, or additional scheduler.
4. Recheck the verified upstream and paths. Install both files at the paths listed above, add the single public-pages include to the existing HTTPS website block, and resolve any precedence conflicts described above. Preserve the existing SPA prefix, API, storage, assets, sitemap, and realtime handlers. Both snippet files must exist before running `nginx -t`.
5. Run `sudo nginx -t`. Reload with `sudo systemctl reload nginx` only after this check succeeds and the backend routes/build are ready. If the running PHP deployment requires an opcode-cache/FPM refresh, use its established release procedure.
6. Run the checks below through the actual HTTPS hostname. If there is an upstream CDN cache, invalidate the affected HTML paths after the origin is correct. Keep asset caching intact. Enable and verify the Laravel routes and Nginx include before clearing obsolete static business/community/product HTML files; otherwise there is a gap where the SPA shell wins again. Limit any subsequent cleanup to the old prerender manifest's generated record files inside the verified dist root, preserving the frontend shell, assets, and current entry pages.
7. Generate and verify the sitemap using the separate [sitemap deployment instructions](sitemaps.md). Fetch `robots.txt`, the sitemap index, and a page child sitemap. Newly imported pages should appear after the next successful generation, without rebuilding all public HTML.

## Acceptance checks

Use existing public records from the deployed data, including one recently imported unclaimed business. Check the raw HTTP response before running JavaScript. The following commands are examples with an explicit placeholder:

```sh
curl --silent --show-error --dump-header /tmp/sveevee-public-page.headers \
  --output /tmp/sveevee-public-page.html \
  'https://sveevee.co.il/en/business/REPLACE_WITH_REAL_SLUG'
curl --silent --show-error --head \
  'https://sveevee.co.il/en/business/REPLACE_WITH_REAL_SLUG'
```

| Check | Required result |
| --- | --- |
| `/`, including HEAD and a request carrying an existing session cookie | HTTP 200 with the homepage heading, homepage canonical and metadata before JavaScript; no account-specific content or session-dependent response |
| Existing business, community, and visible product; repeat for all four locales | HTTP 200 HTML containing the record's heading, title, canonical URL, metadata, and visible details before JavaScript runs |
| Recently imported business without a corresponding prerender file | Same HTTP 200 HTML behavior; confirms the request does not depend on stale export files |
| Existing stale static HTML path | The public pretty URL returns the current database content, even if an old matching `dist/.../index.html` still exists |
| Page with missing city or unmapped source category | Accurate available source information; no invented locality or links to nonexistent catalog locations |
| HEAD for a valid detail URL | Same status and relevant headers as GET, with no response body |
| Conditional GET using the returned ETag | Unchanged public HTML may return 304; editing or hiding the record must return its new content/status after revalidation, with no personalized payload in a shared cache |
| Numeric ID, old slug, unlocalized detail URL, and `/pages/{slug}` | Permanent redirect to the current localized canonical URL; no redirect loop; final URL returns 200 |
| Deleted/nonexistent ID, unsupported stored page type, banned owner, or nonpublic product | HTTP 404 from the backend; no stale business body and no HTTP 200 SPA fallback |
| Query parameters and trailing slash | Canonical policy remains consistent; no duplicate canonical tags or redirects to `/index.php` |
| JavaScript enabled, then navigate to another public record | The Vue app remains usable; metadata updates to the new record without retaining the previous record's hreflang links or JSON-LD |
| Referenced CSS, JavaScript, public images, `/api/...`, `/sitemap.xml`, `/business`, `/community`, `/admin`, realtime endpoint | Existing handlers and application behavior remain functional |

A successful `nginx -t` validates syntax and resolved includes, not precedence, stale CDN responses, or PHP-FPM permissions. The HTTP checks are required. No Nginx executable is available on the local Windows PATH, so a native Nginx configuration test must be performed with the complete website configuration during the authorized deployment.

## Rollback

Restore the backed-up website vhost and both snippet files, validate with `nginx -t`, then reload Nginx. If reverting application files, restore the frontend shell together with the matching assets and backend release. Keep the generated sitemap and its stable public endpoint available. Removing the public-pages include restores the earlier SPA/static routing behavior and its freshness limitation; it does not undo database content or import jobs.
