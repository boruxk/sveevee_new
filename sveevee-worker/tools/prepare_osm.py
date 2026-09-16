#!/usr/bin/env python3
"""Bounded regional PBF -> country-filtered, complete JSONL; never sends imports.

Requires osmium and shapely. Disk-backed node locations and a SQLite work file
keep the regional scan independent of the number of source OSM nodes.
"""
import argparse
import collections
import datetime
import gc
import hashlib
import json
import os
from pathlib import Path
import sqlite3
import sys
import tempfile
import urllib.request

import osmium
from shapely import from_wkb
from shapely.geometry import LineString, MultiPoint, Point
from shapely.ops import unary_union

MAX_PBF_BYTES = 1024 * 1024 * 1024
MAX_EXPORT_BYTES = 1024 * 1024 * 1024
MAX_ROWS = 1_000_000
EXTRACT_URL = 'https://download.geofabrik.de/asia/israel-and-palestine-latest.osm.pbf'
LIFECYCLES = ('disused', 'abandoned', 'demolished', 'razed', 'removed', 'construction', 'proposed')
DOMAINS = ('shop', 'office', 'craft', 'healthcare', 'amenity', 'tourism', 'leisure')
AMENITIES = set('restaurant cafe fast_food food_court ice_cream bar pub biergarten pharmacy hospital clinic doctors dentist veterinary bank bureau_de_change money_transfer post_office school kindergarten college university language_school music_school driving_school childcare nursing_home social_facility fuel car_rental car_wash vehicle_inspection charging_station cinema theatre arts_centre community_centre coworking_space marketplace public_bath internet_cafe gambling casino ferry_terminal bus_station'.split())
TOURISM = set('hotel motel hostel guest_house apartment chalet camp_site caravan_site museum gallery attraction information'.split())
LEISURE = set('fitness_centre sports_centre fitness_station swimming_pool dance bowling_alley golf_course miniature_golf escape_game trampoline_park amusement_arcade water_park sauna marina horse_riding'.split())


def domain_tags(tags, prefix=''):
    for key in DOMAINS:
        value = tags.get(prefix + key, '')
        if not value or value in ('no', 'vacant', 'empty', 'disused', 'abandoned', 'construction', 'proposed'):
            continue
        if key == 'amenity' and value not in AMENITIES:
            continue
        if key == 'tourism' and value not in TOURISM:
            continue
        if key == 'leisure' and value not in LEISURE:
            continue
        yield key, value


def candidate(tags):
    return any(domain_tags(tags)) or any(any(domain_tags(tags, state + ':')) for state in LIFECYCLES)


def lifecycle(tags):
    # A former tenant tag beside an active shop is not evidence the active shop closed.
    for state in LIFECYCLES:
        if tags.get(state) in ('yes', 'true', '1'):
            return state
    if any(domain_tags(tags)):
        return 'active'
    for state in LIFECYCLES:
        if any(domain_tags(tags, state + ':')):
            return state
    return 'inactive'


def record(entity, kind):
    return {'id': f'{kind}/{entity.id}', 'tags': dict(entity.tags),
            'version': entity.version, 'timestamp': entity.timestamp.isoformat()}


def digest(path):
    result = hashlib.sha256()
    with open(path, 'rb') as source:
        for block in iter(lambda: source.read(1024 * 1024), b''):
            result.update(block)
    return result.hexdigest()


def download(destination):
    """One bounded request; TLS and a content SHA-256 identify the published file."""
    stage = destination.with_name(destination.name + '.download')
    try:
        request = urllib.request.Request(EXTRACT_URL, headers={'User-Agent': 'Sveevee-OSM-Snapshot/1.0 (+https://sveevee.co.il)'})
        with urllib.request.urlopen(request, timeout=120) as response, open(stage, 'wb') as target:
            expected = int(response.headers.get('Content-Length', '0'))
            if expected > MAX_PBF_BYTES:
                raise RuntimeError('OSM download exceeds the 1 GiB bound')
            total = 0
            while block := response.read(1024 * 1024):
                total += len(block)
                if total > MAX_PBF_BYTES:
                    raise RuntimeError('OSM download exceeds the 1 GiB bound')
                target.write(block)
            if total == 0 or (expected and total != expected):
                raise RuntimeError('Incomplete OSM download')
        os.replace(stage, destination)
    finally:
        stage.unlink(missing_ok=True)


def extract(pbf, output, manifest, scratch):
    db = sqlite3.connect(str(scratch / 'geometry.sqlite'))
    resources = []
    try:
        return _extract(pbf, output, manifest, scratch, db, resources)
    finally:
        db.close()
        for processor in resources:
            if processor.node_location_storage is not None:
                processor.node_location_storage.clear()
        resources.clear()
        gc.collect()


def _extract(pbf, output, manifest, scratch, db, resources):
    if not pbf.is_file() or not 0 < pbf.stat().st_size <= MAX_PBF_BYTES:
        raise RuntimeError('Missing or oversized OSM input')
    snapshot_id = digest(pbf)
    counts = collections.Counter()
    db.executescript('PRAGMA journal_mode=OFF; PRAGMA synchronous=OFF; CREATE TABLE relations(id INTEGER PRIMARY KEY, data TEXT, members TEXT); CREATE TABLE candidates(id TEXT PRIMARY KEY,data TEXT,lon REAL,lat REAL,method TEXT); CREATE TABLE points(id TEXT PRIMARY KEY,lon REAL,lat REAL);')
    needed = set()

    class Relations(osmium.SimpleHandler):
        def relation(self, relation):
            data = record(relation, 'relation')
            members = [(m.type, m.ref, m.role) for m in relation.members]
            # Saving relation membership permits nested site relations without keeping all ways/nodes.
            db.execute('INSERT INTO relations VALUES (?,?,?)', (relation.id, json.dumps(data), json.dumps(members)))
            if candidate(data['tags']):
                needed.add(('r', relation.id))

    print('OSM: scanning relation membership', file=sys.stderr, flush=True)
    Relations().apply_file(str(pbf))
    pending = list(needed)
    while pending:
        kind, ref = pending.pop()
        if kind != 'r':
            continue
        row = db.execute('SELECT members FROM relations WHERE id=?', (ref,)).fetchone()
        if row:
            for child_kind, child_ref, _role in json.loads(row[0]):
                if (child_kind, child_ref) not in needed:
                    needed.add((child_kind, child_ref))
                    pending.append((child_kind, child_ref))
        if len(needed) > 2_000_000:
            raise RuntimeError('OSM candidate relation expansion exceeds its bound')
    db.commit()
    boundaries = []
    boundary_ids = []
    factory = osmium.geom.WKBFactory()

    def save(data, point=None, method=None):
        coords = (point.x, point.y) if point is not None else (None, None)
        db.execute('INSERT INTO candidates VALUES (?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET lon=COALESCE(excluded.lon,candidates.lon),lat=COALESCE(excluded.lat,candidates.lat),method=COALESCE(excluded.method,candidates.method)',
                   (data['id'], json.dumps(data, ensure_ascii=False), *coords, method))

    print('OSM: streaming nodes, ways and assembled areas with a disk location index', file=sys.stderr, flush=True)
    # The Windows pyosmium wheel keeps sparse_file_array handles open until exit.
    # Its compact memory index avoids an undeletable staging cache there; Linux
    # production preparation uses a disk index for bounded resident memory.
    location_index = 'sparse_mem_array' if os.name == 'nt' else f'sparse_file_array,{scratch / "nodes.idx"}'
    processor = osmium.FileProcessor(str(pbf)).with_locations(location_index).with_areas()
    resources.append(processor)
    for entity in processor:
        if entity.is_node():
            tags = dict(entity.tags)
            if not candidate(tags) and ('n', entity.id) not in needed:
                continue
            point = Point(entity.location.lon, entity.location.lat) if entity.location.valid() else None
            if candidate(tags):
                save(record(entity, 'node'), point, 'node' if point is not None else None)
            if point is not None and ('n', entity.id) in needed:
                db.execute('INSERT OR REPLACE INTO points VALUES (?,?,?)', (f'n/{entity.id}', point.x, point.y))
        elif entity.is_way():
            tags = dict(entity.tags)
            if not candidate(tags) and ('w', entity.id) not in needed:
                continue
            coords = [(n.lon, n.lat) for n in entity.nodes if n.location.valid()]
            point = LineString(coords).interpolate(0.5, normalized=True) if len(coords) > 1 else (Point(coords[0]) if coords else None)
            if candidate(tags):
                save(record(entity, 'way'), point, 'way_midpoint' if point is not None else None)
            if point is not None and ('w', entity.id) in needed:
                db.execute('INSERT OR REPLACE INTO points VALUES (?,?,?)', (f'w/{entity.id}', point.x, point.y))
        elif entity.is_relation():
            if candidate(dict(entity.tags)):
                save(record(entity, 'relation'))
        elif entity.is_area():
            tags = dict(entity.tags)
            is_country = tags.get('boundary') == 'administrative' and tags.get('admin_level') == '2' and (tags.get('ISO3166-1') == 'IL' or tags.get('ISO3166-1:alpha2') == 'IL')
            kind = 'way' if entity.from_way() else 'relation'
            code = 'w' if entity.from_way() else 'r'
            if not is_country and not candidate(tags) and (code, entity.orig_id()) not in needed:
                continue
            try:
                shape = from_wkb(bytes.fromhex(factory.create_multipolygon(entity)))
                if shape.is_empty or not shape.is_valid:
                    raise ValueError('invalid polygon')
                point = shape.representative_point()
            except (RuntimeError, ValueError):
                counts['invalid_area'] += 1
                if is_country:
                    raise RuntimeError('Israel administrative boundary could not be assembled')
                continue
            if is_country:
                boundaries.append(shape)
                boundary_ids.append(f'{kind}/{entity.orig_id()}')
            if candidate(tags):
                # Original object identity; area IDs are synthetic and never leave the extractor.
                data = record(entity, kind)
                data['id'] = f'{kind}/{entity.orig_id()}'
                save(data, point, 'area_interior')
            if (code, entity.orig_id()) in needed:
                db.execute('INSERT OR REPLACE INTO points VALUES (?,?,?)', (f'{code}/{entity.orig_id()}', point.x, point.y))
    db.commit()
    if not boundaries:
        raise RuntimeError('Israel ISO3166-1=IL administrative boundary missing; export refused')
    country = unary_union(boundaries)
    if not country.is_valid or country.is_empty:
        raise RuntimeError('Invalid Israel administrative boundary; export refused')

    def relation_point(ref, visiting=None):
        visiting = set() if visiting is None else visiting
        if ref in visiting or len(visiting) >= 20:
            return None
        visiting.add(ref)
        existing = db.execute('SELECT lon,lat FROM points WHERE id=?', (f'r/{ref}',)).fetchone()
        if existing:
            return Point(*existing)
        row = db.execute('SELECT members FROM relations WHERE id=?', (ref,)).fetchone()
        points = []
        if row:
            for kind, child, _role in json.loads(row[0]):
                stored = db.execute('SELECT lon,lat FROM points WHERE id=?', (f'{kind}/{child}',)).fetchone()
                point = Point(*stored) if stored else (relation_point(child, visiting.copy()) if kind == 'r' else None)
                if point is not None:
                    points.append(point)
        if not points:
            return None
        # Select an actual member location nearest the center, never an invented address/city.
        center = MultiPoint(points).centroid
        point = min(points, key=lambda p: p.distance(center))
        db.execute('INSERT OR REPLACE INTO points VALUES (?,?,?)', (f'r/{ref}', point.x, point.y))
        return point

    stage = output.with_name(output.name + '.stage')
    manifest_stage = manifest.with_name(manifest.name + '.stage')
    try:
        with open(stage, 'wb') as target:
            for identity, raw, lon, lat, method in db.execute('SELECT * FROM candidates ORDER BY id'):
                counts['candidates'] += 1
                data = json.loads(raw)
                point = Point(lon, lat) if lon is not None and lat is not None else None
                if point is None and identity.startswith('relation/'):
                    point = relation_point(int(identity.split('/')[1]))
                    method = 'relation_member' if point is not None else None
                if point is None:
                    counts['missing_geometry'] += 1
                    continue
                explicit_country = data['tags'].get('addr:country', '').strip().upper()
                if explicit_country and explicit_country not in ('IL', 'ISR', 'ISRAEL', 'ישראל'):
                    counts['explicit_other_country'] += 1
                    continue
                if not country.covers(point):
                    counts['outside_IL_boundary'] += 1
                    continue
                data.update(country='IL', country_filter='osm_admin_boundary_IL', longitude=point.x,
                            latitude=point.y, geometry_method=method, lifecycle_status=lifecycle(data['tags']))
                encoded = (json.dumps(data, ensure_ascii=False, separators=(',', ':')) + '\n').encode('utf-8')
                target.write(encoded)
                counts['accepted'] += 1
                counts[identity.split('/')[0]] += 1
                if counts['accepted'] > MAX_ROWS or target.tell() > MAX_EXPORT_BYTES:
                    raise RuntimeError('OSM export exceeds its row or byte bound')
            target.flush()
            os.fsync(target.fileno())
        if not counts['accepted']:
            raise RuntimeError('OSM country-filtered export is empty')
        reader = osmium.io.Reader(str(pbf))
        try:
            timestamp = reader.header().get('osmosis_replication_timestamp')
        finally:
            reader.close()
        # The input content hash is the cursor identity; preparation date is only a display label fallback.
        release = timestamp[:10] if timestamp else datetime.datetime.now(datetime.timezone.utc).date().isoformat()
        metadata = {'provider': 'osm_places', 'status': 'complete', 'country': 'IL',
                    'country_filter': 'osm_admin_boundary_IL', 'boundary_ids': boundary_ids,
                    'snapshot_id': snapshot_id, 'release': release, 'source_timestamp': timestamp or None,
                    'extract_url': EXTRACT_URL, 'input_bytes': pbf.stat().st_size,
                    'jsonl_sha256': digest(stage), 'row_count': counts['accepted'], 'counts': dict(counts)}
        manifest_stage.write_text(json.dumps(metadata, indent=2), encoding='utf-8')
        os.replace(stage, output)
        os.replace(manifest_stage, manifest)
        print(json.dumps(metadata, indent=2), flush=True)
        return metadata
    finally:
        stage.unlink(missing_ok=True)
        manifest_stage.unlink(missing_ok=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--pbf', type=Path, required=True)
    parser.add_argument('--download', action='store_true', help='Download Geofabrik latest once before preparation')
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--manifest', type=Path, required=True)
    args = parser.parse_args()
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.manifest.parent.mkdir(parents=True, exist_ok=True)
    if args.download:
        args.pbf.parent.mkdir(parents=True, exist_ok=True)
        download(args.pbf)
    with tempfile.TemporaryDirectory(prefix='osm-prepare-', dir=args.output.parent) as temporary:
        extract(args.pbf, args.output, args.manifest, Path(temporary))


if __name__ == '__main__':
    main()
