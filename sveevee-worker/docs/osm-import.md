# OpenStreetMap: local preparation and comparison

Current decision: **local preview and comparison only**. OSM publication and live timer activation are not authorized. `config/worker.osm.json` has `allow_public_import: false`; `run`/`import` require `--dry-run`. The backend separately rejects OSM writes by default. The hourly systemd files are inactive templates and invoke `--dry-run`. Local previews do not publish or queue remote run logs.

## Source and scope

The input is the public [Geofabrik Israel and Palestine extract](https://download.geofabrik.de/asia/israel-and-palestine.html). No paid API, account or token is needed. Its regional file includes both Israel and Palestine; it is not an Israel-only business register. Preparation filters representative POI coordinates against the `admin_level=2`, `ISO3166-1=IL` administrative boundary assembled from that same file, and excludes explicitly non-IL `addr:country`. Missing/invalid country geometry stops preparation; coordinates do not invent a city or postal address.

Candidate selection covers shops, offices, crafts, healthcare and explicit business/service amenities, tourism and leisure tags. It does not import every map object, road, house or bus stop. The supported POI tag sets are visible in `tools/prepare_osm.py`; unknown values for shop/office/craft/healthcare still become reviewable categories. Missing business names are quarantined, not replaced with invented names.

Nodes, ways and relations keep their original identity (`node/123`, `way/123`, `relation/123`). [Pyosmium area processing](https://docs.osmcode.org/pyosmium/latest/user_manual/03-Working-with-Geometries/) assembles polygons for closed ways and multipolygon/boundary relations. Ordinary site relations use a real member location nearest their member-center. A malformed geometry is counted and excluded when its country cannot be established. Same-chain branches retain separate source IDs; later matching must prove they are the same location.

## Verified snapshot, 16 September 2026

The downloaded PBF is 119,563,616 bytes, timestamp `2026-09-15T20:20:37Z`, SHA-256 `642c18a7f186099d9aaa07a4d0fbd228cb2855c0cc15337c205ac5b68e0b4798`. The country boundary is `relation/1473946`.

| Measurement | Source objects |
| --- | ---: |
| Regional business/service candidates | 54,386 |
| Inside the IL filter | 34,217 |
| Nodes / ways / relations in IL | 22,561 / 11,588 / 68 |
| Outside boundary / explicit other country | 20,160 / 9 |
| Missing candidate geometry in this snapshot | 0 |
| Inactive source objects | 100 |
| Active objects with missing/unusable names | 9,759 |
| Active named candidates before matching | 24,358 |
| Phone / website / email present | 3,004 / 4,502 / 603 |
| Raw opening hours / exactly convertible weekly hours | 2,790 / 981 |
| Missing city tag / unmapped category | 30,580 / 19,033 |

Contact/hour counts refer to all retained source objects, including inactive/unnamed objects. They are not promised new businesses or new contact values. Duplicate representations and overlap with Overture/Foursquare may reduce the final number. A city missing from OSM stays missing; an unfamiliar supplied city/category remains available in source metadata for catalog comparison.

## Preparation

Use Python 3 with the pinned packages in `tools/requirements-osm.txt`, and PHP with the worker's normal SQLite requirements. The Python runtime may be a local virtual environment. On Linux the node-location index is disk-backed; Windows uses a compact memory index because the current Windows pyosmium file-index implementation retains open handles. Reserve space for the PBF, staging geometry, JSONL and SQLite files. Input/output are capped at 1 GiB each and one million selected rows; the PHP launcher limits preparation to 60 minutes.

From the worker directory:

```sh
python3 -m venv var/osm/python
var/osm/python/bin/python -m pip install -r tools/requirements-osm.txt
php bin/prepare-osm.php --python var/osm/python/bin/python
```

The command downloads the regional PBF once, filters it locally, writes JSONL plus a SHA-256/count manifest, then maps and atomically publishes the SQLite snapshot. It never calls the business import API. Reuse a downloaded PBF without another network request:

```sh
php bin/prepare-osm.php --python var/osm/python/bin/python --pbf var/osm/israel-and-palestine.osm.pbf
```

`--config`, `--env-file` and `--output` override the usual worker paths. A blank `sources.osm_places.database_path` derives `osm.sqlite` beside the isolated worker database. `SVEVEE_WORKER_DATA_DIR=/var/lib/sveevee-worker` plus `storage.data_subdirectory=osm` therefore resolves to `/var/lib/sveevee-worker/osm/`, matching the service sandbox. An incomplete export, wrong checksum, repeated ID or conflicting snapshot never replaces the last good SQLite file. A preparation lock prevents concurrent CLI publishers.

Re-map an already validated JSONL without Python or downloading:

```sh
php bin/prepare-osm.php --jsonl var/osm/osm-export.jsonl --manifest var/osm/osm-export.manifest.json
```

## Local preview and progress

`worker research --config=config/worker.osm.json --limit=9000` prepares candidates in the local worker queue without API lookup. `worker run --dry-run --config=config/worker.osm.json --limit=9000` also performs duplicate comparison against the configured backend; configure a local backend for an entirely local comparison. Dry-run performs no public business writes. Run-report publication is suppressed for this OSM preview profile.

The source reader performs no Geofabrik, Overpass, geocoding or website requests. It reads the prepared SQLite file in stable source-ID order. Every source row advances only after durable acknowledgement, so interruptions repeat the unacknowledged row. Inactive/unusable objects remain counted and are skipped for publication. A new PBF content hash starts a new scan; rebuilding the same PBF does not reset its cursor. The prepared profile allows up to 9,000 candidates per run, in transport batches of at most 100. Existing/pending comparisons can reduce how many fresh rows a preview reaches; a dry-run intentionally does not mark remote imports complete. Repeated previews can therefore inspect the same pending candidates again. The hourly template is a preview schedule, not an advancing public import; advancing 9,000-record imports are only verified with the isolated mock API for a possible future opt-in.

The uninstalled `deploy/systemd/sveevee-osm.timer` template schedules minute 40 of each hour in Asia/Jerusalem. Nothing in preparation installs, enables or starts it. Do not add it to live deployment until the user authorizes that separate action.

## Fields, lifecycle and opening hours

Source metadata contains `source_id`, `osm_type`, `osm_id`, `osm_version`, `osm_timestamp`, coordinates, geometry method, `source_city`, category descriptors, original tags, snapshot identity, raw hours and attribution. Street, house number, description, phone, email, website, WhatsApp and social profiles enter normal worker normalization when present. Known equivalent categories map through `OpenStreetMap/CategoryMapper.php`; unrecognized categories remain in metadata and do not reject an otherwise valid business.

[OSM lifecycle prefixes](https://wiki.openstreetmap.org/wiki/Lifecycle_prefix) distinguish inactive/former features from active ones. Explicit disused/abandoned/demolished/removed/construction/proposed evidence is retained and excludes publication. An active shop with only a former tenant's `disused:shop` tag remains active. `opening_hours=off` by itself is not proof of permanent closure. Mere absence from a new snapshot is not proof either.

[OSM opening-hours syntax](https://wiki.openstreetmap.org/wiki/Key:opening_hours) supports holidays, date rules, multiple intervals and overnight hours. Our weekly editor supports one interval per day. `WeeklyOpeningHours` accepts only an entirely representable weekly expression; unsupported clauses invalidate the whole conversion. `24/7`, `24:00`, split shifts, holiday exceptions, comments and overnight rules stay raw-only. The complete original tag is preserved, and `opening_hours_raw` plus `opening_hours_status` distinguish missing, raw-only and exact weekly data. The shared permissive OSM string parser is never used on these raw expressions.

## License and public use

[OSM's copyright page](https://www.openstreetmap.org/copyright) requires contributor credit and notice that the data is available under ODbL. Records carry `© OpenStreetMap contributors`, an attribution link and the [ODbL 1.0 license](https://opendatacommons.org/licenses/odbl/1-0/). Downloading the public file has no API fee; hosting, preparation and any eventual data distribution use our own resources.

Attribution alone does not settle share-alike obligations for a combined business catalog. The [OSMF Collective Database Guideline](https://osmfoundation.org/wiki/Licence/Community_Guidelines/Collective_Database_Guideline_Guideline) allows specified all-OSM/all-non-OSM separations for a property, feature and regional cut. Its restaurant-list example explicitly says complementing a proprietary country list with OSM and removing duplicates is outside that safe harbor. Database joins count as references even across separate physical tables. A linked supplementary OSM panel is therefore not automatically an exemption. The [Horizontal Map Layers Guideline](https://osmfoundation.org/wiki/Licence/Community_Guidelines/Horizontal_Map_Layers_-_Guideline) likewise discusses share-alike when sources complement the same feature type.

The user chose local preview rather than public OSM publication or an ODbL data export. No public export is implemented or promised here. Before any later publication, determine the actual derived database scope and meet its applicable ODbL data-availability terms; do not assume publishing only raw OSM rows covers a merged catalog, and do not include private user/account/chat information in a public data export.

## Verification

```sh
python3 tests/osm_extract.py
php tests/osm.php
php tests/osm_pipeline.php
php tests/osm_preview_guard.php
php tests/osm_catalog.php
```

The geometry fixtures use real pyosmium parsing. The pipeline test processes 9,002 records with a mocked API, exercises interrupted transport and exact run/batch limits, keeps chain branches distinct, and checks exhaustion. The catalog check uses the sibling backend's Composer dependencies and validates all 47 mappings against the actual business-page catalog. The preview guard verifies default refusal before source/API initialization and no remote-log outbox entry.
