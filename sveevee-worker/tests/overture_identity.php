<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Sveevee\Worker\Api\SveeveeGateway;
use Sveevee\Worker\Config\ResearchTarget;
use Sveevee\Worker\Domain\BusinessLocationIdentity;
use Sveevee\Worker\Domain\BusinessMerger;
use Sveevee\Worker\Domain\BusinessNormalizer;
use Sveevee\Worker\Domain\OpeningHoursParser;
use Sveevee\Worker\Pipeline\ImportService;
use Sveevee\Worker\Pipeline\ResearchService;
use Sveevee\Worker\Reporting\RunReport;
use Sveevee\Worker\Research\SourceAdapterInterface;
use Sveevee\Worker\Storage\Database;
use Sveevee\Worker\Storage\IdentityConflictException;
use Sveevee\Worker\Storage\WorkerRepository;
use Sveevee\Worker\Support\Logger;
use Sveevee\Worker\Support\SourceFingerprint;
use Sveevee\Worker\Support\Uuid;

final class OvertureIdentityGateway implements SveeveeGateway
{
    public array $requests = [];

    public function __construct(public array $remote) {}

    public function checkDuplicate(array $business): array
    {
        return ['matches' => [['id' => $this->remote['id'], 'matched_on' => ['phone']]]];
    }

    public function searchBusinesses(array $filters): array
    {
        return ['businesses' => [$this->remote]];
    }

    public function importBatch(array $request): array
    {
        $this->requests[] = $request;

        return ['items' => array_map(static fn (array $row, int $index): array => [
            'position' => $index + 1, 'status' => 'updated', 'business' => ['id' => $row['id']],
        ], $request['businesses'], array_keys($request['businesses']))];
    }

    public function reportRun(array $report): array
    {
        return [];
    }
}

$directories = [];
$context = static function () use (&$directories): array {
    $directory = sys_get_temp_dir().'/sveevee-overture-identity-'.bin2hex(random_bytes(8));
    $database = new Database($directory.'/worker.sqlite');
    $directories[] = $directory;
    $normalizer = new BusinessNormalizer(new OpeningHoursParser, ['Tel Aviv', 'Jerusalem']);

    return [new WorkerRepository($database, $normalizer, new BusinessMerger), $database, $normalizer, new Logger($directory.'/worker.log', false)];
};
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$raw = static fn (array $overrides = []): array => array_replace([
    'name' => 'Cafe Example', 'category_key' => 'food_catering.cafes',
    'address' => ['city' => 'Tel Aviv', 'street' => 'Example Street', 'number' => '10'],
    'phone' => '+97235550100', 'website' => 'https://chain.example.org/branch',
    'source_name' => 'Fixture', 'source_url' => 'https://example.org/source/'.bin2hex(random_bytes(4)),
], $overrides);
$target = new ResearchTarget('Tel Aviv', 'food_catering.cafes');
$tests = [];
$tests['location identity permits suffix cleanup and explicit formatting but rejects other names cities or numbers'] = static function () use ($raw, $assert): void {
    $legacy = $raw(['name' => 'קפה בדיקה בע״מ']);
    $incoming = $raw(['name' => 'קפה בדיקה', 'address' => ['city' => 'Tel Aviv', 'street' => 'example street 10']]);
    $assert(BusinessLocationIdentity::conflict($legacy, $incoming) === null, 'Same place with cleaned company suffix and freeform house number was rejected.');
    foreach ([
        ['name' => 'Different Cafe'],
        ['address' => ['city' => 'Jerusalem', 'street' => 'Example Street', 'number' => '10']],
        ['address' => ['city' => 'Tel Aviv', 'street' => 'Example Street', 'number' => '11']],
        ['address' => ['city' => 'Tel Aviv', 'street' => 'Example Street']],
    ] as $difference) {
        $assert(BusinessLocationIdentity::conflict($raw(), $raw($difference)) !== null, 'Conflicting or unconfirmed location was accepted.');
    }
};
$tests['local shared domains phones and emails preserve every distinct business location'] = static function () use ($context, $raw, $target, $assert): void {
    foreach (['city', 'name', 'street', 'email'] as $case) {
        [$repository, $database, $normalizer] = $context();
        $first = $raw(['contact_email' => 'chain@example.org']);
        if ($case === 'name') {
            unset($first['phone'], $first['contact_email']); // Host alone, even inside one city.
        }
        if ($case === 'email') {
            unset($first['phone'], $first['website']); // Shared chain email alone.
        }
        $stored = $repository->upsertCandidate($normalizer->normalize($first, $target, 'data_gov_ckan'));
        $before = $repository->business($stored['business_id']);
        $keysBefore = $database->pdo->query('SELECT * FROM business_identity_keys ORDER BY key_type')->fetchAll();
        $next = $first;
        $next['source_url'] .= '-next';
        if ($case === 'city') {
            $next['address']['city'] = 'Jerusalem';
        } elseif ($case === 'street') {
            $next['address']['number'] = '11';
        } else {
            $next['name'] = 'Other Business';
        }
        $second = $repository->upsertCandidate($normalizer->normalize($next, $target, 'overture_places'));
        $assert($second['is_new'] && $second['business_id'] !== $stored['business_id'], $case.' location was not created separately.');
        $assert($repository->business($stored['business_id']) === $before, 'Conflict changed the existing business.');
        $assert($database->pdo->query('SELECT * FROM business_identity_keys WHERE business_id = '.$stored['business_id'].' ORDER BY key_type')->fetchAll() === $keysBefore, 'Another location changed the original identity hints.');
        $assert(! $repository->hasSource($stored['business_id'], 'overture_places'), 'The new source was attached to an unrelated business.');
        $assert($repository->hasSource($second['business_id'], 'overture_places'), 'New place provenance was lost.');
    }
};
$tests['later government records from another location do not overwrite an existing Overture branch'] = static function () use ($context, $raw, $target, $assert): void {
    [$repository, , $normalizer] = $context();
    $first = $raw();
    $stored = $repository->upsertCandidate($normalizer->normalize($first, $target, 'overture_places'));
    $second = $repository->upsertCandidate($normalizer->normalize($raw(['name' => 'Other Business']), $target, 'data_gov_ckan'));
    $assert($second['is_new'], 'Another government business was not created separately.');
    $assert($repository->business($stored['business_id'])['payload']['name'] === 'Cafe Example', 'Existing Overture identity changed.');
};
$tests['compatible government and Overture records can enrich the same local business'] = static function () use ($context, $raw, $target, $assert): void {
    [$repository, , $normalizer] = $context();
    $first = $raw(['name' => 'קפה בדיקה בע״מ']);
    $stored = $repository->upsertCandidate($normalizer->normalize($first, $target, 'data_gov_ckan'));
    $next = $raw(['name' => 'קפה בדיקה', 'contact_email' => 'new@example.org']);
    $next['address'] = ['city' => 'Tel Aviv', 'street' => 'Example Street 10'];
    $merged = $repository->upsertCandidate($normalizer->normalize($next, $target, 'overture_places'));
    $assert($merged['business_id'] === $stored['business_id'] && ! $merged['is_new'], 'Same place was not merged.');
    $assert($repository->business($stored['business_id'])['payload']['contact_email'] === 'new@example.org', 'Useful new contact was lost.');
    $assert($repository->hasSource($stored['business_id'], 'overture_places'), 'Compatible Overture provenance was lost.');
    $repeated = $repository->upsertCandidate($normalizer->normalize($next, $target, 'overture_places'));
    $assert(! $repeated['changed'], 'Repeated freeform address changed the merged business.');
    $assert($repository->business($stored['business_id'])['payload']['address'] === $first['address'], 'Merge duplicated the house number or lost the existing address representation.');
};
$tests['research quarantines an ambiguous missing address without assigning it to a known branch'] = static function () use ($context, $raw, $target, $assert): void {
    [$repository, $database, $normalizer, $logger] = $context();
    $repository->upsertCandidate($normalizer->normalize($raw(), $target, 'data_gov_ckan'));
    $source = new class($raw(['address' => ['city' => 'Tel Aviv']])) implements SourceAdapterInterface
    {
        public function __construct(private readonly array $raw) {}

        public function name(): string
        {
            return 'overture_places';
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
    $report = new RunReport(Uuid::v4(), 'research', false);
    $repository->startRun($report->runId, 'research', false, 'fixture');
    $research = new ResearchService([$source], [], [$target], $normalizer, $repository, $logger);
    $assert(iterator_to_array($research->candidates($report->runId, $target, $report)) === [], 'Conflict produced an import candidate.');
    $assert($report->metric('failed') === 1, 'Identity failure was not reported.');
    $assert($database->pdo->query('SELECT error_code FROM research_failures')->fetchColumn() === 'identity_conflict', 'Conflict was not quarantined.');
};
$tests['remote name city and street collisions never produce an Overture patch, including dry runs'] = static function () use ($context, $raw, $target, $assert): void {
    foreach ([false, true] as $dryRun) {
        foreach ([['name' => 'Other Business'], ['address' => ['city' => 'Jerusalem']], ['address' => ['city' => 'Tel Aviv', 'street' => 'Example Street', 'number' => '99']]] as $difference) {
            [$repository, , $normalizer, $logger] = $context();
            $stored = $repository->upsertCandidate($normalizer->normalize($raw(['contact_email' => 'new@example.org']), $target, 'overture_places'));
            $gateway = new OvertureIdentityGateway(array_replace($raw(), ['id' => 123, 'can_update' => true], $difference));
            $report = new RunReport(Uuid::v4(), 'import', $dryRun);
            $repository->startRun($report->runId, 'import', $dryRun, 'fixture');
            (new ImportService($gateway, $repository, new BusinessMerger, $logger, 100))->import($report->runId, 100, $dryRun, $report);
            $assert($gateway->requests === [], 'Another remote place was updated.');
            $assert($report->metric('failed') === 1 && $report->metric('planned_updates') === 0, 'Unsafe update was planned.');
            $status = $repository->business($stored['business_id']);
            $assert($dryRun ? $status['status'] === 'pending' : $status['last_error_code'] === 'duplicate_unresolved', 'Unexpected collision status.');
        }
    }
};
$tests['remote matching places can receive missing contacts while claimed pages remain protected'] = static function () use ($context, $raw, $target, $assert): void {
    foreach ([true, false] as $canUpdate) {
        [$repository, , $normalizer, $logger] = $context();
        $stored = $repository->upsertCandidate($normalizer->normalize($raw(['contact_email' => 'new@example.org']), $target, 'overture_places'));
        $remote = $raw(['id' => 123, 'can_update' => $canUpdate, 'address' => ['city' => 'Tel Aviv', 'street' => 'Example Street 10']]);
        $gateway = new OvertureIdentityGateway($remote);
        $report = new RunReport(Uuid::v4(), 'import', false);
        $repository->startRun($report->runId, 'import', false, 'fixture');
        (new ImportService($gateway, $repository, new BusinessMerger, $logger, 100))->import($report->runId, 100, false, $report);
        if ($canUpdate) {
            $assert($gateway->requests[0]['businesses'][0] === ['id' => 123, 'contact_email' => 'new@example.org'], 'Compatible update changed existing information.');
            $assert($report->metric('updated') === 1, 'Compatible update was not sent.');
        } else {
            $assert($gateway->requests === [] && $repository->business($stored['business_id'])['status'] === 'claimed', 'Claimed page was updated.');
        }
    }
};
$tests['shared contacts do not merge differently named government or seed businesses'] = static function () use ($context, $raw, $target, $assert): void {
    [$repository, , $normalizer] = $context();
    $first = $repository->upsertCandidate($normalizer->normalize($raw(), $target, 'data_gov_ckan'));
    $second = $repository->upsertCandidate($normalizer->normalize($raw(['name' => 'New Name']), $target, 'json_seed'));
    $assert($first['business_id'] !== $second['business_id'], 'Shared contacts merged different business names.');
};

$tests['twelve same-city chain locations share contacts without sharing business IDs'] = static function () use ($context, $raw, $target, $assert): void {
    [$repository, $database, $normalizer] = $context();
    $ids = [];
    $records = [];
    for ($number = 1; $number <= 12; $number++) {
        $record = $raw([
            'contact_email' => 'chain@example.org',
            'address' => ['city' => 'Tel Aviv', 'street' => 'Example Street', 'number' => (string) $number],
            'source_url' => 'https://explore.overturemaps.org/?feature=places.place.fixture-'.$number,
        ]);
        $candidate = $normalizer->normalize($record, $target, 'overture_places');
        $stored = $repository->upsertCandidate($candidate);
        $assert($stored['is_new'], 'Known chain location '.$number.' was collapsed.');
        $ids[] = $stored['business_id'];
        $records[] = $record;
    }
    $assert(count(array_unique($ids)) === 12, 'Chain locations share an internal business ID.');
    foreach ($records as $index => $record) {
        $record['source_url'] .= '-second-provider-record';
        $record['address'] = ['city' => 'Tel Aviv', 'street' => 'Example Street '.($index + 1)];
        $stored = $repository->upsertCandidate($normalizer->normalize($record, $target, 'data_gov_ckan'));
        $assert(! $stored['is_new'] && $stored['business_id'] === $ids[$index], 'Same location from another source was not matched exactly.');
    }
    foreach (['phone', 'email', 'website', 'place_name_city'] as $key) {
        $statement = $database->pdo->prepare('SELECT COUNT(DISTINCT business_id) FROM business_identity_keys WHERE key_type = ?');
        $statement->execute([$key]);
        $assert((int) $statement->fetchColumn() === 12, 'A shared '.$key.' signal remained globally exclusive.');
    }
};
$tests['stable GERS identity survives a corrected name contact and location without duplicating the place'] = static function () use ($context, $raw, $target, $assert): void {
    [$repository, $database, $normalizer] = $context();
    $record = $raw(['source_url' => 'https://explore.overturemaps.org/?feature=places.place.fixture-stable']);
    $first = $repository->upsertCandidate($normalizer->normalize($record, $target, 'overture_places'));
    $record['name'] = 'Corrected Cafe Name';
    $record['phone'] = '+97235550999';
    $record['address'] = ['city' => 'Jerusalem', 'street' => 'Different Street 25'];
    $updated = $repository->upsertCandidate($normalizer->normalize($record, $target, 'overture_places'));
    $assert($first['business_id'] === $updated['business_id'] && ! $updated['is_new'], 'A stable GERS place was duplicated after a source correction.');
    $assert((int) $database->pdo->query('SELECT COUNT(*) FROM businesses')->fetchColumn() === 1, 'Source correction created another local place.');
    $assert($repository->business($first['business_id'])['payload']['address'] === $record['address'], 'A previous house number leaked into a corrected address.');
};
$tests['missing addresses and house numbers never select an arbitrary chain branch'] = static function () use ($context, $raw, $target, $assert): void {
    foreach ([[], ['street' => 'Example Street']] as $missing) {
        [$repository, $database, $normalizer] = $context();
        foreach (['10', '11'] as $number) {
            $repository->upsertCandidate($normalizer->normalize($raw([
                'address' => ['city' => 'Tel Aviv', 'street' => 'Example Street', 'number' => $number],
            ]), $target, 'overture_places'));
        }
        $before = $database->pdo->query('SELECT id, payload_json FROM businesses ORDER BY id')->fetchAll();
        $conflict = false;
        try {
            $repository->upsertCandidate($normalizer->normalize($raw([
                'address' => ['city' => 'Tel Aviv', ...$missing],
            ]), $target, 'data_gov_ckan'));
        } catch (IdentityConflictException) {
            $conflict = true;
        }
        $assert($conflict, 'Incomplete address selected one chain branch.');
        $assert($database->pdo->query('SELECT id, payload_json FROM businesses ORDER BY id')->fetchAll() === $before, 'Incomplete input changed or duplicated a branch.');
    }
    [$repository, , $normalizer] = $context();
    $addressless = $raw(['address' => ['city' => 'Tel Aviv']]);
    $first = $repository->upsertCandidate($normalizer->normalize($addressless, $target, 'json_seed'));
    $second = $repository->upsertCandidate($normalizer->normalize($addressless, $target, 'json_seed'));
    $assert($first['business_id'] === $second['business_id'], 'A unique existing addressless record was duplicated.');
};
$tests['legacy identity migration preserves IDs payloads queued batches and sources while retrying old branch rejections once'] = static function () use ($context, $raw, $target, $assert): void {
    [$repository, $database, $normalizer] = $context();
    $original = $raw(['name' => 'קפה בדיקה בע״מ']);
    $first = $repository->upsertCandidate($normalizer->normalize($original, $target, 'data_gov_ckan'));
    $business = $repository->business($first['business_id']);
    $runId = Uuid::v4();
    $repository->startRun($runId, 'import', false, 'legacy-fixture');
    $repository->createBatch($runId, [['business_id' => $business['id'], 'payload' => $business['payload']]]);
    $rejected = $raw(['name' => 'Rejected Chain', 'source_url' => 'https://explore.overturemaps.org/?feature=places.place.legacy-rejected']);
    $repository->recordResearchFailure($runId, 'overture_places', $rejected['source_url'], $rejected, 'identity_conflict', 'Former shared-contact conflict.', false);
    $assert(! $repository->shouldProcessUrl('overture_places', $rejected['source_url'], 30, SourceFingerprint::hash($rejected)), 'Legacy rejection fixture is not quarantined.');

    // Recreate the actual old globally unique key schema and omit the new backfill keys.
    $database->pdo->exec('DELETE FROM worker_migrations WHERE name = \'business_location_identity_v1\'');
    $database->pdo->exec('ALTER TABLE business_identity_keys RENAME TO branch_keys_fixture');
    $database->pdo->exec('CREATE TABLE business_identity_keys (
        business_id INTEGER NOT NULL, key_type TEXT NOT NULL, key_value TEXT NOT NULL,
        PRIMARY KEY (key_type, key_value), FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
    )');
    $database->pdo->exec("INSERT INTO business_identity_keys SELECT business_id, key_type, key_value FROM branch_keys_fixture WHERE key_type NOT IN ('place_name_city','name_location')");
    $database->pdo->exec('DROP TABLE branch_keys_fixture');
    $beforeBusinesses = $database->pdo->query('SELECT * FROM businesses ORDER BY id')->fetchAll();
    $beforeBatches = $database->pdo->query('SELECT * FROM import_batches')->fetchAll();
    $beforeItems = $database->pdo->query('SELECT * FROM import_batch_items')->fetchAll();
    $beforeSources = $database->pdo->query('SELECT * FROM business_sources')->fetchAll();
    $path = $database->pdo->query('PRAGMA database_list')->fetch()['file'];
    $migratedDatabase = new Database($path);
    $migrated = new WorkerRepository($migratedDatabase, $normalizer, new BusinessMerger);
    $assert($migratedDatabase->pdo->query('SELECT * FROM businesses ORDER BY id')->fetchAll() === $beforeBusinesses, 'Migration changed existing business IDs, payloads or queued status.');
    $assert($migratedDatabase->pdo->query('SELECT * FROM import_batches')->fetchAll() === $beforeBatches, 'Migration changed an immutable batch UUID or request.');
    $assert($migratedDatabase->pdo->query('SELECT * FROM import_batch_items')->fetchAll() === $beforeItems, 'Migration changed a queued business association.');
    $assert($migratedDatabase->pdo->query('SELECT * FROM business_sources')->fetchAll() === $beforeSources, 'Migration changed provenance.');
    $assert($migratedDatabase->pdo->query('PRAGMA foreign_key_check')->fetchAll() === [], 'Migration broke a foreign key.');
    $assert($migrated->shouldProcessUrl('overture_places', $rejected['source_url'], 30, SourceFingerprint::hash($rejected)), 'Former global-contact rejection was not reconsidered.');

    $incoming = $raw(['name' => 'קפה בדיקה', 'phone' => null, 'website' => null, 'contact_email' => 'new@example.org']);
    $incoming['address'] = ['city' => 'Tel Aviv', 'street' => 'Example Street 10'];
    $same = $migrated->upsertCandidate($normalizer->normalize($incoming, $target, 'overture_places'));
    $assert($same['business_id'] === $business['id'] && ! $same['is_new'], 'Backfill could not match a cleaned government name and equivalent address.');
    $assert($migratedDatabase->pdo->query('SELECT * FROM import_batches')->fetchAll() === $beforeBatches, 'Enrichment after migration rewrote a queued request.');
    $branch = $original;
    $branch['address']['number'] = '11';
    $branch['source_url'] .= '-branch';
    $newBranch = $migrated->upsertCandidate($normalizer->normalize($branch, $target, 'overture_places'));
    $assert($newBranch['is_new'], 'Legacy shared contact keys still blocked another branch.');

    $migrated->recordResearchFailure($runId, 'overture_places', $rejected['source_url'], $rejected, 'identity_conflict', 'Still ambiguous after review.', false);
    $secondOpen = new WorkerRepository(new Database($path), $normalizer, new BusinessMerger);
    $assert(! $secondOpen->shouldProcessUrl('overture_places', $rejected['source_url'], 30, SourceFingerprint::hash($rejected)), 'Repeated startup repeatedly released an unresolved rejection.');
};

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        fwrite(STDOUT, "PASS {$name}\n");
    } catch (Throwable $error) {
        $failures++;
        fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n");
    }
}
$count = count($tests);
unset($tests, $test);
gc_collect_cycles();
foreach ($directories as $directory) {
    foreach (['worker.sqlite-wal', 'worker.sqlite-shm', 'worker.sqlite', 'worker.log'] as $filename) {
        if (is_file($directory.'/'.$filename)) {
            @unlink($directory.'/'.$filename);
        }
    }
    @rmdir($directory);
}
fwrite(STDOUT, "{$count} tests, {$failures} failures\n");
exit($failures === 0 ? 0 : 1);
