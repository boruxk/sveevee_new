"""Offline geometry/lifecycle coverage using real pyosmium parsing, no network."""
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('prepare_osm', Path(__file__).parents[1] / 'tools' / 'prepare_osm.py')
osm = importlib.util.module_from_spec(spec)
spec.loader.exec_module(osm)


def tags(values):
    from xml.sax.saxutils import quoteattr
    return ''.join(f'<tag k={quoteattr(k)} v={quoteattr(v)}/>' for k, v in values.items())


class ExtractTest(unittest.TestCase):
    def fixture(self, path, boundary=True):
        entities = []
        points = {1: (34.3, 31.3), 2: (34.4, 31.4), 3: (34.6, 31.4), 4: (34.6, 31.6), 5: (34.4, 31.6),
                  6: (36, 31.5), 7: (34.8, 31.5), 8: (34.7, 31.7), 9: (34.7, 31.8),
                  91: (34, 31), 92: (35, 31), 93: (35, 32), 94: (34, 32)}
        point_tags = {1: {'name': 'Branch', 'shop': 'bakery', 'opening_hours': 'Mo-Fr 08:00-17:00; PH off'},
                      6: {'name': 'Outside', 'shop': 'bakery'},
                      7: {'name': 'Other country', 'shop': 'bakery', 'addr:country': 'PS'},
                      8: {'name': 'Closed', 'disused:shop': 'bakery'},
                      9: {'name': 'New tenant', 'shop': 'bakery', 'disused:shop': 'clothes'}}
        for identity, (lon, lat) in points.items():
            entities.append(f'<node id="{identity}" version="1" timestamp="2026-09-15T00:00:00Z" lon="{lon}" lat="{lat}">{tags(point_tags.get(identity, {}))}</node>')
        for identity, refs, values in [(100, [2, 3, 4, 5, 2], {'name': 'Branch', 'shop': 'bakery'}),
                                        (101, [2, 3], {}), (900, [91, 92, 93, 94, 91], {})]:
            entities.append(f'<way id="{identity}" version="1" timestamp="2026-09-15T00:00:00Z">' + ''.join(f'<nd ref="{r}"/>' for r in refs) + tags(values) + '</way>')
        entities.append('<relation id="200" version="1" timestamp="2026-09-15T00:00:00Z"><member type="way" ref="101" role=""/>' + tags({'type': 'site', 'name': 'Service site', 'office': 'lawyer'}) + '</relation>')
        entities.append('<relation id="300" version="1" timestamp="2026-09-15T00:00:00Z"><member type="way" ref="100" role="outer"/>' + tags({'type': 'multipolygon', 'name': 'Relation business', 'amenity': 'clinic'}) + '</relation>')
        if boundary:
            entities.append('<relation id="999" version="1" timestamp="2026-09-15T00:00:00Z"><member type="way" ref="900" role="outer"/>' + tags({'type': 'boundary', 'boundary': 'administrative', 'admin_level': '2', 'ISO3166-1': 'IL'}) + '</relation>')
        path.write_text('<?xml version="1.0"?><osm version="0.6">' + ''.join(entities) + '</osm>', encoding='utf-8')

    def test_all_entity_types_and_country_filter(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self.fixture(root / 'fixture.osm')
            scratch = root / 'scratch'
            scratch.mkdir()
            result = osm.extract(root / 'fixture.osm', root / 'out.jsonl', root / 'manifest.json', scratch)
            rows = {row['id']: row for row in map(json.loads, (root / 'out.jsonl').read_text(encoding='utf-8').splitlines())}
            self.assertEqual(set(rows), {'node/1', 'node/8', 'node/9', 'way/100', 'relation/200', 'relation/300'})
            self.assertEqual(rows['node/8']['lifecycle_status'], 'disused')
            self.assertEqual(rows['node/9']['lifecycle_status'], 'active')
            self.assertEqual(rows['relation/200']['geometry_method'], 'relation_member')
            self.assertEqual(rows['relation/300']['geometry_method'], 'area_interior')
            self.assertEqual(rows['way/100']['geometry_method'], 'area_interior')
            self.assertEqual(rows['node/1']['tags']['opening_hours'], 'Mo-Fr 08:00-17:00; PH off')
            self.assertEqual(result['row_count'], 6)
            self.assertEqual(result['counts']['outside_IL_boundary'], 1)
            self.assertEqual(result['counts']['explicit_other_country'], 1)
            self.assertEqual(result['jsonl_sha256'], osm.digest(root / 'out.jsonl'))

    def test_missing_country_boundary_preserves_previous_export(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self.fixture(root / 'fixture.osm', boundary=False)
            (root / 'out.jsonl').write_text('previous snapshot', encoding='utf-8')
            scratch = root / 'scratch'
            scratch.mkdir()
            with self.assertRaisesRegex(RuntimeError, 'boundary missing'):
                osm.extract(root / 'fixture.osm', root / 'out.jsonl', root / 'manifest.json', scratch)
            self.assertEqual((root / 'out.jsonl').read_text(encoding='utf-8'), 'previous snapshot')

    def test_lifecycle_is_conservative(self):
        self.assertEqual(osm.lifecycle({'shop': 'bakery', 'opening_hours': 'off'}), 'active')
        self.assertEqual(osm.lifecycle({'shop': 'bakery', 'disused:shop': 'clothes'}), 'active')
        self.assertEqual(osm.lifecycle({'shop': 'bakery', 'disused': 'yes'}), 'disused')
        self.assertFalse(osm.candidate({'highway': 'bus_stop', 'name': 'A stop'}))
        self.assertTrue(osm.candidate({'office': 'unknown_future_service', 'name': 'Service'}))


if __name__ == '__main__':
    unittest.main()
