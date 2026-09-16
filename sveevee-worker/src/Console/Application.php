<?php

declare(strict_types=1);

namespace Sveevee\Worker\Console;

use RuntimeException;
use Sveevee\Worker\Api\OAuthTokenProvider;
use Sveevee\Worker\Api\SveeveeApiClient;
use Sveevee\Worker\Config\WorkerConfig;
use Sveevee\Worker\Config\WorkerPaths;
use Sveevee\Worker\Domain\BusinessMerger;
use Sveevee\Worker\Domain\BusinessNormalizer;
use Sveevee\Worker\Domain\OpeningHoursParser;
use Sveevee\Worker\Http\BudgetedHttpClient;
use Sveevee\Worker\Http\CurlHttpClient;
use Sveevee\Worker\Http\SafeWebClient;
use Sveevee\Worker\Http\UrlGuard;
use Sveevee\Worker\Pipeline\ClosedBusinessRemovalService;
use Sveevee\Worker\Pipeline\FullSnapshotRefresh;
use Sveevee\Worker\Pipeline\ImportService;
use Sveevee\Worker\Pipeline\ResearchService;
use Sveevee\Worker\Pipeline\ResearchTargetScheduler;
use Sveevee\Worker\Pipeline\RunLogPublisher;
use Sveevee\Worker\Reporting\RunReport;
use Sveevee\Worker\Research\DataGovCkanSource;
use Sveevee\Worker\Research\Foursquare\DatasetPreparer as FoursquareDatasetPreparer;
use Sveevee\Worker\Research\Foursquare\PlaceMapper as FoursquarePlaceMapper;
use Sveevee\Worker\Research\Foursquare\PlacesSource;
use Sveevee\Worker\Research\JsonSeedSource;
use Sveevee\Worker\Research\OfficialWebsiteEnricher;
use Sveevee\Worker\Research\OpenStreetMap\PlacesSource as OsmPlacesSource;
use Sveevee\Worker\Research\OverpassSource;
use Sveevee\Worker\Research\OverturePlacesSource;
use Sveevee\Worker\Research\RobotsPolicy;
use Sveevee\Worker\Research\TelAvivBusinessLicenseSource;
use Sveevee\Worker\Storage\Database;
use Sveevee\Worker\Storage\WorkerRepository;
use Sveevee\Worker\Support\Environment;
use Sveevee\Worker\Support\Json;
use Sveevee\Worker\Support\Logger;
use Sveevee\Worker\Support\ProcessLock;
use Sveevee\Worker\Support\Uuid;

final class Application
{
    public function __construct(private readonly string $root) {}

    public function run(array $arguments): int
    {
        try {
            [$command, $options] = $this->parse($arguments);
            if ($command === 'help') {
                $this->usage();

                return 0;
            }

            Environment::load($options['env_file'] ?? $this->root.'/.env');
            $configPath = $options['config']
                ?? Environment::get('SVEVEE_WORKER_CONFIG')
                ?? $this->root.'/config/worker.json';
            $config = WorkerConfig::load($configPath, $this->root);
            $localOnlyRunLogs = $config->fullSourceProvider() === 'osm_places'
                && ! $config->bool('sources.osm_places.allow_public_import', false);
            $paths = $this->paths($config);
            if (($options['continue_snapshot'] ?? false) || (($options['refresh'] ?? false) && $command === 'run')) {
                if (! in_array($config->fullSourceProvider(), ['overture_places', 'foursquare_places'], true)) {
                    throw new RuntimeException('Import snapshot refresh/continuation requires full-source Overture or Foursquare.');
                }
            }
            if (($options['continue_snapshot'] ?? false) && ! is_file(FullSnapshotRefresh::marker($paths))) {
                return 0;
            }
            $this->assertExtensions($config, $command);
            if ($command === 'remove-closed-businesses' && $config->fullSourceProvider() !== 'foursquare_places') {
                throw new RuntimeException('Closed-business removal requires the dedicated Foursquare full-source config.');
            }

            $logger = new Logger($paths['log']);
            $openingHours = new OpeningHoursParser;
            $merger = new BusinessMerger;
            $normalizer = new BusinessNormalizer($openingHours, (array) $config->get('cities', []));
            $enabledSources = array_keys(array_filter((array) $config->get('sources', []),
                static fn (array $source): bool => ($source['enabled'] ?? false) === true));
            $governmentSources = array_values(array_intersect($enabledSources, ['data_gov_ckan', 'tel_aviv_business_licenses']));
            // The original database can contain candidates from the formerly combined job.
            $sourceScope = $governmentSources !== [] && array_diff($enabledSources, [...$governmentSources, 'official_website']) === []
                ? $governmentSources : null;
            if (($fullSource = $config->fullSourceProvider()) !== null) {
                $sourceScope = [$fullSource];
            }
            $repository = new WorkerRepository(new Database($paths['database']), $normalizer, $merger, $sourceScope);
            $targets = $config->targets();
            $targetsPerRun = $config->int('targets_per_run', count($targets));
            $targetScheduler = new ResearchTargetScheduler($repository);

            if ($command === 'status') {
                $status = $repository->statusSummary();
                $status['target_schedule'] = $targetScheduler->summary($targets, $targetsPerRun);
                fwrite(STDOUT, Json::encode($status, true).PHP_EOL);

                return 0;
            }

            $lock = new ProcessLock($paths['lock']);
            $lock->acquire();
            if (($options['continue_snapshot'] ?? false) && ! (new FullSnapshotRefresh($config, $repository, $paths))->canStartImport()) {
                $logger->info('Snapshot continuation is waiting for the next hourly import window.');

                return 0;
            }
            if ($command === 'retry-failed') {
                $count = $repository->resetFailed($options['limit'] ?? 1000);
                $logger->info('Failed businesses returned to the pending queue.', ['count' => $count]);

                return 0;
            }

            $dryRun = $command === 'remove-closed-businesses'
                ? ! ($options['apply'] ?? false) : (bool) ($options['dry_run'] ?? false);
            $limit = max(1, (int) ($options['limit'] ?? $config->int('target_per_run', 1000)));
            if (in_array($config->fullSourceProvider(), ['overture_places', 'foursquare_places'], true)) {
                $limit = min(9000, $limit);
            }
            $runId = Uuid::v4();
            $report = new RunReport($runId, $command, $dryRun);
            if (in_array('foursquare_places', $enabledSources, true)) {
                $report->source('foursquare_places', 0);
                $report->increment('review', 0);
            }
            if (in_array('osm_places', $enabledSources, true)) {
                $report->source('osm_places', 0);
            }
            if ($governmentSources !== []) {
                $report->enableSourceMetrics();
                foreach ($governmentSources as $source) {
                    // Keep separate job labels even for empty runs or local pending imports.
                    $report->source($source, 0);
                }
            }
            $repository->startRun($runId, $command, $dryRun, $config->hash());
            $api = null;

            try {
                $research = null;
                $snapshotRefresh = null;
                $skipSnapshotImport = false;
                if ($command === 'run' && (($options['refresh'] ?? false) || ($options['continue_snapshot'] ?? false))) {
                    // Construction performs no HTTP; refresh failures can still publish their admin report.
                    $api = $this->api($config);
                    $snapshotRefresh = new FullSnapshotRefresh($config, $repository, $paths);
                    if (($options['refresh'] ?? false) || $snapshotRefresh->deferredRefreshReady()) {
                        $source = $config->fullSourceProvider();
                        $report->source($source, 0);
                        $duckdb = (string) ($options['duckdb'] ?? $config->source($source)['duckdb_binary'] ?? 'duckdb');
                        if (! in_array($duckdb, ['duckdb', 'duckdb.exe'], true)) {
                            $duckdb = $config->resolvePath($duckdb);
                        }
                        $refreshed = $snapshotRefresh->refresh($duckdb);
                        $logger->info('Source snapshot version checked.', $refreshed);
                        if ($refreshed['status'] === 'deferred') {
                            $report->error('snapshot_refresh_deferred', 'A newer source version is waiting for the current snapshot to finish.', $refreshed);
                        }
                        if (! $dryRun) {
                            $snapshotRefresh->updateContinuation($refreshed['status'] === 'deferred');
                        }
                    }
                    $skipSnapshotImport = ! $snapshotRefresh->canStartImport();
                    if (! $dryRun && ! $skipSnapshotImport) {
                        $snapshotRefresh->markImportStarted();
                    }
                    if ($skipSnapshotImport) {
                        $logger->info('Snapshot import is deferred until the next hourly import window.');
                    }
                }
                if ($config->fullSourceProvider() === 'osm_places' && in_array($command, ['run', 'import'], true)
                    && ! $dryRun && ! $config->bool('sources.osm_places.allow_public_import', false)) {
                    throw new RuntimeException('OpenStreetMap is configured for preview only; use --dry-run.');
                }
                if ($command === 'remove-closed-businesses') {
                    $api = $this->api($config);
                    $configuredDatabase = trim((string) ($config->source('foursquare_places')['database_path'] ?? ''));
                    $snapshot = $configuredDatabase === '' ? dirname($paths['database']).'/foursquare.sqlite'
                        : $config->resolvePath($configuredDatabase);
                    if ($options['refresh'] ?? false) {
                        $duckdb = (string) ($options['duckdb'] ?? $config->source('foursquare_places')['duckdb_binary'] ?? 'duckdb');
                        if (! in_array($duckdb, ['duckdb', 'duckdb.exe'], true)) {
                            $duckdb = $config->resolvePath($duckdb);
                        }
                        // Keep the shared worker lock across refresh and removal; a failed refresh never uses the old snapshot.
                        (new FoursquareDatasetPreparer(FoursquarePlaceMapper::fromConfig([
                            'cities' => $config->get('cities', []), 'sources' => $config->get('sources', []),
                        ])))->prepare(
                            $duckdb, Environment::require('FOURSQUARE_ACCESS_TOKEN'), $snapshot,
                        );
                    }
                    (new ClosedBusinessRemovalService($api))->run($snapshot, $dryRun, $report);
                }
                // Scan the complete ordered schedule; empty combinations do not use a productive slot.
                $selectedTargets = $command === 'remove-closed-businesses' || $skipSnapshotImport ? []
                    : $targetScheduler->next($targets, max(1, count($targets)));
                if (in_array($command, ['research', 'run'], true) && ! $skipSnapshotImport) {
                    [$sources, $enrichers] = $this->researchComponents(
                        $config, $repository, $normalizer, $merger, $openingHours, $report
                    );
                    if ($sources === []) {
                        throw new RuntimeException('No research source is enabled in the worker config.');
                    }
                    $research = new ResearchService(
                        $sources, $enrichers, $selectedTargets, $normalizer, $repository, $logger,
                        $config->int('businesses_per_combination', 100), $targetsPerRun,
                    );
                    if ($command === 'research') {
                        $research->research($runId, $limit, $report);
                    }
                }

                if (in_array($command, ['import', 'run'], true) && ! $skipSnapshotImport) {
                    $api = $this->api($config);
                    (new ImportService(
                        $api, $repository, $merger, $logger,
                        min(100, $config->int('batch_size', 100)),
                    ))->runTargets(
                        $runId, $selectedTargets, $limit, $targetsPerRun,
                        $config->int('businesses_per_combination', 100), $dryRun, $report, $research,
                    );
                }
                $research?->reportProgress($report);
                if ($snapshotRefresh !== null && ! $dryRun) {
                    $snapshotRefresh->updateContinuation();
                }
                $written = $report->write($paths['reports']);
                $repository->finishRun($runId, 'completed', $written['path'], $written['report']);
                if (! $localOnlyRunLogs) {
                    $repository->queueRunLog($runId, $written['report']);
                    $this->publishRunLogs($api, $repository, $logger);
                }
                $logger->info('Worker run completed.', [
                    'run_id' => $runId,
                    'report' => $written['path'],
                    'summary' => array_intersect_key($written['report'], array_flip([
                        'found', 'new', 'existing', 'updated', 'duplicates',
                        'incomplete', 'failed', 'imported', 'target_combinations',
                        'productive_target_combinations', 'empty_target_combinations',
                        'duration_seconds',
                    ])),
                ]);

                return 0;
            } catch (\Throwable $exception) {
                $report->increment('failed');
                $report->error('fatal', $exception->getMessage());
                $written = $report->write($paths['reports'], 'failed');
                $repository->finishRun($runId, 'failed', $written['path'], $written['report'], $exception->getMessage());
                if (! $localOnlyRunLogs) {
                    $repository->queueRunLog($runId, $written['report']);
                    $this->publishRunLogs($api, $repository, $logger);
                }
                $logger->error('Worker run failed.', [
                    'run_id' => $runId,
                    'error' => $exception->getMessage(),
                    'report' => $written['path'],
                ]);

                return 1;
            }
        } catch (\Throwable $exception) {
            fwrite(STDERR, '[ERROR] '.$exception->getMessage().PHP_EOL);

            return 1;
        }
    }

    private function publishRunLogs(
        ?SveeveeApiClient $api,
        WorkerRepository $repository,
        Logger $logger,
    ): void {
        if ($api === null) {
            return;
        }

        (new RunLogPublisher($api, $repository, $logger))->publishPending();
    }

    private function researchComponents(
        WorkerConfig $config,
        WorkerRepository $repository,
        BusinessNormalizer $normalizer,
        BusinessMerger $merger,
        OpeningHoursParser $openingHours,
        RunReport $report,
    ): array {
        $http = new CurlHttpClient;
        $governmentHttp = new BudgetedHttpClient(
            $http, $config->int('research.max_http_requests_per_run', 10),
            static fn () => $report->increment('source_requests'),
        );
        $userAgent = Environment::get(
            'SVEVEE_WORKER_USER_AGENT',
            'SveeveeResearchWorker/1.0 (+https://sveevee.co.il; mailto:info@sveevee.co.il)'
        );
        $sources = [];
        $foursquare = $config->source('foursquare_places');
        if (($foursquare['enabled'] ?? false) === true) {
            $configuredDatabase = trim((string) ($foursquare['database_path'] ?? ''));
            $foursquare['database_path'] = $configuredDatabase !== ''
                ? $config->resolvePath($configuredDatabase)
                : dirname($this->paths($config)['database']).'/foursquare.sqlite';
            $sources[] = new PlacesSource($foursquare, $this->root, $repository);
        }
        $osm = $config->source('osm_places');
        if (($osm['enabled'] ?? false) === true) {
            $configuredDatabase = trim((string) ($osm['database_path'] ?? ''));
            $osm['database_path'] = $configuredDatabase !== ''
                ? $config->resolvePath($configuredDatabase)
                : dirname($this->paths($config)['database']).'/osm.sqlite';
            $sources[] = new OsmPlacesSource($osm, $this->root, $repository);
        }
        $overture = $config->source('overture_places');
        if (($overture['enabled'] ?? false) === true) {
            $configuredDatabase = trim((string) ($overture['database_path'] ?? ''));
            $overture['database_path'] = $configuredDatabase !== ''
                ? $config->resolvePath($configuredDatabase)
                : dirname($this->paths($config)['database']).'/overture.sqlite';
            $sources[] = new OverturePlacesSource($overture, $this->root, $repository);
        }
        $dataGov = $config->source('data_gov_ckan');
        if (($dataGov['enabled'] ?? false) === true) {
            $sources[] = new DataGovCkanSource($dataGov, $governmentHttp, $repository, (string) $userAgent);
        }
        $telAviv = $config->source('tel_aviv_business_licenses');
        if (($telAviv['enabled'] ?? false) === true) {
            $sources[] = new TelAvivBusinessLicenseSource($telAviv, $governmentHttp, $repository, (string) $userAgent);
        }
        $json = $config->source('json_seed');
        if (($json['enabled'] ?? false) === true) {
            $sources[] = new JsonSeedSource($json, $this->root);
        }
        $overpass = $config->source('overpass');
        if (($overpass['enabled'] ?? false) === true) {
            $sources[] = new OverpassSource($overpass, $http, (string) $userAgent);
        }

        $enrichers = [];
        $website = $config->source('official_website');
        if (($website['enabled'] ?? false) === true) {
            $safeWeb = new SafeWebClient($http, new UrlGuard, (string) $userAgent);
            $robots = new RobotsPolicy($safeWeb, $repository, (string) $userAgent);
            $enrichers[] = new OfficialWebsiteEnricher(
                $website, $safeWeb, $robots, $merger, $openingHours
            );
        }

        return [$sources, $enrichers];
    }

    private function api(WorkerConfig $config): SveeveeApiClient
    {
        $http = new CurlHttpClient;
        $timeout = max(5, $config->int('api.timeout_seconds', 30));
        $userAgent = (string) Environment::get(
            'SVEVEE_WORKER_USER_AGENT',
            'SveeveeResearchWorker/1.0 (+https://sveevee.co.il; mailto:info@sveevee.co.il)'
        );
        $baseUrl = $this->requiredEnvironment(
            'SVEVEE_API_URL',
            'SVEEVEE_BUSINESS_IMPORT_API_URL'
        );
        $tokenUrl = $this->requiredEnvironment(
            'SVEVEE_TOKEN_URL',
            'SVEEVEE_OAUTH_TOKEN_URL'
        );
        $this->assertSecureUrl($baseUrl);
        $this->assertSecureUrl($tokenUrl);
        $tokens = new OAuthTokenProvider(
            $http,
            $tokenUrl,
            $this->requiredEnvironment('SVEVEE_CLIENT_ID', 'SVEEVEE_BUSINESS_IMPORT_CLIENT_ID'),
            $this->requiredEnvironment('SVEVEE_CLIENT_SECRET', 'SVEEVEE_BUSINESS_IMPORT_CLIENT_SECRET'),
            $timeout,
            $userAgent,
        );

        return new SveeveeApiClient(
            $http,
            $tokens,
            $baseUrl,
            $timeout,
            max(0, $config->int('api.request_interval_ms', 550)),
            max(0, min(8, $config->int('api.max_retries', 4))),
            $userAgent,
        );
    }

    private function paths(WorkerConfig $config): array
    {
        return WorkerPaths::resolve((array) $config->get('storage', []), $this->root);
    }

    private function assertExtensions(WorkerConfig $config, string $command): void
    {
        foreach (['pdo_sqlite', 'json', 'mbstring'] as $extension) {
            if (! extension_loaded($extension)) {
                throw new RuntimeException("Required PHP extension is missing: {$extension}");
            }
        }
        $needsResearchHttp = in_array($command, ['research', 'run'], true)
            && ($config->bool('sources.data_gov_ckan.enabled')
                || $config->bool('sources.tel_aviv_business_licenses.enabled')
                || $config->bool('sources.overpass.enabled')
                || $config->bool('sources.official_website.enabled'));
        $needsHttp = in_array($command, ['import', 'run', 'remove-closed-businesses'], true) || $needsResearchHttp;
        if ($needsHttp && ! extension_loaded('curl')) {
            throw new RuntimeException('Required PHP extension is missing: curl');
        }
        if (in_array($command, ['research', 'run'], true)
            && $config->bool('sources.official_website.enabled')
            && ! extension_loaded('dom')) {
            throw new RuntimeException('Required PHP extension is missing: dom');
        }
    }

    private function assertSecureUrl(string $url): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($scheme !== 'https' && ! in_array($host, ['127.0.0.1', 'localhost'], true)) {
            throw new RuntimeException('Sveevee API and OAuth URLs must use HTTPS.');
        }
    }

    private function requiredEnvironment(string $primary, string $legacy): string
    {
        $value = Environment::get($primary) ?? Environment::get($legacy);
        if ($value === null) {
            throw new RuntimeException("Missing required environment variable: {$primary}");
        }

        return $value;
    }

    private function parse(array $arguments): array
    {
        $command = $arguments[1] ?? 'help';
        if (in_array($command, ['-h', '--help', 'help'], true)) {
            return ['help', []];
        }
        if (! in_array($command, ['research', 'import', 'run', 'status', 'retry-failed', 'remove-closed-businesses'], true)) {
            throw new RuntimeException("Unknown command: {$command}");
        }

        $options = [];
        for ($index = 2; $index < count($arguments); $index++) {
            $argument = $arguments[$index];
            if ($argument === '--dry-run') {
                $options['dry_run'] = true;

                continue;
            }
            if ($argument === '--apply' && $command === 'remove-closed-businesses') {
                $options['apply'] = true;

                continue;
            }
            if ($argument === '--refresh' && in_array($command, ['run', 'remove-closed-businesses'], true)) {
                $options['refresh'] = true;

                continue;
            }
            if ($argument === '--continue-snapshot' && $command === 'run') {
                $options['continue_snapshot'] = true;

                continue;
            }
            foreach (['limit', 'config', 'env-file', ...(in_array($command, ['run', 'remove-closed-businesses'], true) ? ['duckdb'] : [])] as $name) {
                if ($argument === '--'.$name && isset($arguments[$index + 1])) {
                    $options[str_replace('-', '_', $name)] = $arguments[++$index];

                    continue 2;
                }
                if (str_starts_with($argument, '--'.$name.'=')) {
                    $options[str_replace('-', '_', $name)] = substr($argument, strlen($name) + 3);

                    continue 2;
                }
            }
            throw new RuntimeException("Unknown option: {$argument}");
        }
        if (isset($options['limit']) && (! ctype_digit((string) $options['limit']) || (int) $options['limit'] < 1)) {
            throw new RuntimeException('--limit must be a positive integer.');
        }
        if ($command === 'remove-closed-businesses' && isset($options['limit'])) {
            throw new RuntimeException('Closure checks scan the complete snapshot; --limit is not supported.');
        }
        if (isset($options['apply'], $options['dry_run'])) {
            throw new RuntimeException('Choose either --apply or --dry-run.');
        }
        if (isset($options['refresh'], $options['continue_snapshot'])) {
            throw new RuntimeException('Choose --refresh or --continue-snapshot.');
        }
        if ($command === 'run' && ($options['dry_run'] ?? false)
            && (($options['refresh'] ?? false) || ($options['continue_snapshot'] ?? false))) {
            throw new RuntimeException('Automatic snapshot refresh/continuation changes local snapshots; use an isolated normal run instead of --dry-run.');
        }
        if (isset($options['duckdb']) && ! ($options['refresh'] ?? false) && ! ($options['continue_snapshot'] ?? false)) {
            throw new RuntimeException('--duckdb requires --refresh or --continue-snapshot.');
        }

        return [$command, $options];
    }

    private function usage(): void
    {
        fwrite(STDOUT, <<<'TEXT'
Sveevee Automation/Research Worker

Usage:
  worker research [--limit=100] [--dry-run]
  worker import [--limit=100] [--dry-run]
  worker run [--limit=100] [--dry-run]
  worker run --config=config/worker.overture.json --refresh [--duckdb=PATH]
  worker run --config=config/worker.foursquare.json --continue-snapshot [--duckdb=PATH]
  worker status
  worker retry-failed [--limit=100]
  worker remove-closed-businesses --config=config/worker.foursquare.json [--refresh] [--apply]

Options:
  --config=PATH    Worker JSON config (default: config/worker.json)
  --env-file=PATH Environment file (default: .env)
  --limit=N        Maximum businesses for this work block
  --dry-run        Permit reads and local state, but no Sveevee writes
  --apply          Apply closed-business removals (that command otherwise defaults to dry-run)
  --refresh        Check the latest OV/FSQ version before import, or refresh Foursquare closure evidence
  --continue-snapshot  Continue only explicitly marked OV/FSQ backlog, without routine downloads
  --duckdb=PATH    DuckDB executable for a snapshot refresh (default: source config)

TEXT);
    }
}
