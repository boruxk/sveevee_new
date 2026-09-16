<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Sveevee\Worker\Config\ResearchTarget;
use Sveevee\Worker\Domain\BusinessMerger;
use Sveevee\Worker\Domain\BusinessNormalizer;
use Sveevee\Worker\Domain\OpeningHoursParser;
use Sveevee\Worker\Pipeline\ResearchService;
use Sveevee\Worker\Reporting\RunReport;
use Sveevee\Worker\Research\SourceAdapterInterface;
use Sveevee\Worker\Storage\Database;
use Sveevee\Worker\Storage\WorkerRepository;
use Sveevee\Worker\Support\Clock;
use Sveevee\Worker\Support\Logger;
use Sveevee\Worker\Support\SourceFingerprint;
use Sveevee\Worker\Support\Uuid;

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$checks = 0;
$assert = static function (bool $value, string $message) use (&$checks): void {
    $checks++;
    if (! $value) {
        throw new RuntimeException($message);
    }
};
$fixture = static function (string $provider): array {
    $database = new Database(':memory:');
    $normalizer = new BusinessNormalizer(new OpeningHoursParser, ['Haifa']);
    $repository = new WorkerRepository($database, $normalizer, new BusinessMerger, [$provider]);
    $id = $provider === 'foursquare_places' ? str_repeat('a', 24) : '11111111-2222-3333-4444-555555555555';
    $raw = ['type' => 'business', 'name' => 'Delta cafe', 'category_key' => null,
        'address' => ['city' => 'Haifa', 'street' => 'Example 1'], 'phone' => '+97235550100',
        'source_name' => $provider, 'source_checked_at' => '2020-01-01T00:00:00Z',
        'source_url' => $provider === 'foursquare_places' ? 'https://foursquare.com/placemakers/review-place/'.$id : 'https://explore.overturemaps.org/?gers='.$id,
        'source_metadata' => $provider === 'foursquare_places' ? [
            'source_id' => $id, 'country' => 'IL', 'date_closed' => null, 'snapshot_id' => '123', 'release' => '2026-08-19',
            'source_city' => 'Haifa', 'source_categories' => [['key' => 'unlisted', 'label' => 'Original activity', 'catalog_key' => null]],
            'original_record' => ['name' => 'Delta cafe', 'date_refreshed' => '2026-08-19', 'hours' => '09:00-17:00'],
        ] : [
            'gers_id' => $id, 'overture_id' => $id, 'release' => '2026-08-19.0',
            'address' => ['country' => 'IL', 'locality' => 'Haifa'], 'taxonomy' => ['primary' => 'unlisted'],
            'sources' => [['dataset' => 'Foursquare', 'record_id' => str_repeat('a', 24), 'update_time' => '2026-08-19', 'version' => '2026-08-19', 'confidence' => 0.9], ['dataset' => 'OpenStreetMap', 'record_id' => 'node/123']],
        ]];
    $target = $provider === 'overture_places' ? ResearchTarget::overtureAll() : ResearchTarget::sourceAll($provider);
    $business = $repository->upsertCandidate($normalizer->normalize($raw, $target, $provider));
    $database->pdo->exec("UPDATE businesses SET status='imported',sveevee_page_id=100");

    return [$database, $repository, $normalizer, $raw, $target, $business];
};
$tests = [];
$tests['old cached rows and changed snapshot timestamps do not become imports'] = static function () use ($fixture, $assert): void {
    foreach (['overture_places', 'foursquare_places'] as $provider) {
        [$db, $repo, , $raw] = $fixture($provider);
        $before = $db->pdo->query('SELECT raw_hash,raw_json FROM business_sources')->fetch();
        $assert($repo->shouldProcessUrl($provider, $raw['source_url'], 30, SourceFingerprint::hash($raw)), 'Legacy time-based check should be expired in fixture.');
        $assert(! $repo->shouldProcessSnapshotRow($provider, $raw['source_url'], $raw), 'Unchanged source was reimported because of its age.');
        $new = $raw;
        $new['source_checked_at'] = Clock::now();
        $new['source_metadata']['release'] = '2026-09-16.0';
        $new['source_metadata']['snapshot_id'] = '456';
        $new['source_metadata']['prepared_at'] = Clock::now();
        if ($provider === 'foursquare_places') {
            $new['source_metadata']['original_record']['date_refreshed'] = '2026-09-16';
        } else {
            $new['source_metadata']['sources'][0]['update_time'] = '2026-09-16';
            $new['source_metadata']['sources'][0]['version'] = '2026-09-16';
            $new['source_metadata']['sources'][0]['confidence'] = 0.99;
            $new['source_metadata']['sources'] = array_reverse($new['source_metadata']['sources']);
        }
        $assert(! $repo->shouldProcessSnapshotRow($provider, $raw['source_url'], $new), 'Audit-only release change caused an import.');
        $assert($before === $db->pdo->query('SELECT raw_hash,raw_json FROM business_sources')->fetch(), 'Comparison changed legacy source evidence.');
    }
};
$tests['material city category alias contact and hours changes remain eligible'] = static function () use ($fixture, $assert): void {
    foreach (['overture_places', 'foursquare_places'] as $provider) {
        [, $repo, , $raw] = $fixture($provider);
        $variants = [];
        $new = $raw;
        $new['phone'] = '+97235550200';
        $variants[] = $new;
        $new = $raw;
        if ($provider === 'foursquare_places') {
            $new['source_metadata']['source_categories'][0]['label'] = 'Corrected category';
            $variants[] = $new;
            $new = $raw;
            $new['source_metadata']['original_record']['hours'] = '08:00-18:00';
            $variants[] = $new;
            $new = $raw;
            $new['source_metadata']['date_closed'] = '2026-09-15';
            $variants[] = $new;
            $new = $raw;
            $new['source_metadata']['source_city'] = 'New source locality';
        } else {
            $new['source_metadata']['taxonomy']['primary'] = 'new_category';
            $variants[] = $new;
            $new = $raw;
            $new['source_metadata']['sources'][0]['record_id'] = str_repeat('b', 24);
            $variants[] = $new;
            $new = $raw;
            $new['source_metadata']['address']['locality'] = 'New source locality';
        }
        $variants[] = $new;
        foreach ($variants as $variant) {
            $assert($repo->shouldProcessSnapshotRow($provider, $raw['source_url'], $variant), 'A real source-fact change was lost.');
        }
        $assert($repo->shouldProcessSnapshotRow($provider, $raw['source_url'].'-new', $raw), 'New source was skipped.');
    }
};
$tests['closed claimed and unresolved-review states are preserved'] = static function () use ($fixture, $assert): void {
    foreach (['overture_places', 'foursquare_places'] as $provider) {
        [$db, $repo, $normalizer, $raw, $target] = $fixture($provider);
        $changed = $raw;
        $changed['phone'] = '+97235550300';
        foreach (['closed', 'claimed'] as $status) {
            $db->pdo->exec("UPDATE businesses SET status='$status'");
            $assert(! $repo->shouldProcessSnapshotRow($provider, $raw['source_url'], $changed), 'Protected business was selected.');
        }
        if ($provider === 'foursquare_places') {
            $db->pdo->exec("UPDATE businesses SET status='review',last_error_code='review_required',sveevee_page_id=NULL");
            $assert(! $repo->shouldProcessSnapshotRow($provider, $raw['source_url'], $raw), 'Unchanged review selected again.');
            $assert($repo->shouldProcessSnapshotRow($provider, $raw['source_url'], $changed), 'Changed review evidence was ignored.');
            $repo->upsertCandidate($normalizer->normalize($changed, $target, $provider));
            $assert($db->pdo->query('SELECT status FROM businesses')->fetchColumn() === 'review', 'Changed evidence automatically requeued an unresolved review.');
        }
    }
};
$tests['metadata-only delta passes the second ResearchService gate before thirty days'] = static function () use ($fixture, $assert): void {
    foreach (['overture_places', 'foursquare_places'] as $provider) {
        [$db, $repo, $normalizer, $raw, $target, $business] = $fixture($provider);
        $db->pdo->prepare('UPDATE researched_urls SET checked_at=?')->execute([Clock::now()]);
        $changed = $raw;
        if ($provider === 'foursquare_places') {
            $changed['source_metadata']['source_categories'][0]['label'] = 'New exact source label';
        } else {
            $changed['source_metadata']['taxonomy']['primary'] = 'new_unmapped_activity';
        }
        $assert(SourceFingerprint::hash($raw) === SourceFingerprint::hash($changed), 'Fixture must have equal legacy fingerprint.');
        $source = new class($provider, $changed) implements SourceAdapterInterface
        {
            public function __construct(private readonly string $provider, private readonly array $raw) {}

            public function name(): string
            {
                return $this->provider;
            }

            public function refreshAfterDays(): int
            {
                return 30;
            }

            public function research(ResearchTarget $target, int $limit): iterable
            {
                yield $this->raw;
            }
        };
        $path = tempnam(sys_get_temp_dir(), 'sveevee-delta-');
        try {
            $report = new RunReport(Uuid::v4(), 'run', false);
            $repo->startRun($report->runId, 'run', false, 'delta-fixture');
            $service = new ResearchService([$source], [], [$target], $normalizer, $repo, new Logger($path, false));
            $candidates = iterator_to_array($service->candidates($report->runId, $target, $report));
            $assert(count($candidates) === 1, 'ResearchService dropped a material metadata-only update.');
            $assert($report->metric('failed') === 0, 'Metadata delta failed research.');
            $assert($repo->business($business['business_id'])['status'] === 'pending', 'Material change was not queued.');
            $assert(! $repo->shouldProcessSnapshotRow($provider, $raw['source_url'], $changed), 'Confirmed new raw facts were not cached.');
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
};
$tests['legacy rejected and missing-provenance records remain retryable without changing gov cache rules'] = static function () use ($fixture, $assert): void {
    [$db, $repo, , $raw] = $fixture('overture_places');
    $db->pdo->exec("UPDATE businesses SET status='invalid',payload_json='{}'");
    $assert($repo->shouldProcessSnapshotRow('overture_places', $raw['source_url'], $raw), 'Legacy full-mode recovery was disabled.');
    $db->pdo->exec("UPDATE researched_urls SET status='rejected'");
    $assert($repo->shouldProcessSnapshotRow('overture_places', $raw['source_url'], $raw), 'Legacy rejection did not get reconsidered.');
    $assert($repo->shouldProcessUrl('data_gov_ckan', 'https://example.test/new-gov-source', 30), 'Gov behavior changed.');
};
foreach ($tests as $name => $test) {
    $test();
    echo '[PASS] '.$name.PHP_EOL;
}
echo count($tests).' tests, '.$checks.' assertions passed.'.PHP_EOL;
