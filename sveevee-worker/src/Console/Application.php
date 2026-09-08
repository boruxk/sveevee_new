<?php

declare(strict_types=1);

namespace Sveevee\Worker\Console;

use RuntimeException;
use Sveevee\Worker\Api\OAuthTokenProvider;
use Sveevee\Worker\Api\SveeveeApiClient;
use Sveevee\Worker\Config\WorkerConfig;
use Sveevee\Worker\Domain\BusinessMerger;
use Sveevee\Worker\Domain\BusinessNormalizer;
use Sveevee\Worker\Domain\OpeningHoursParser;
use Sveevee\Worker\Http\CurlHttpClient;
use Sveevee\Worker\Http\SafeWebClient;
use Sveevee\Worker\Http\UrlGuard;
use Sveevee\Worker\Pipeline\ImportService;
use Sveevee\Worker\Pipeline\ResearchService;
use Sveevee\Worker\Reporting\RunReport;
use Sveevee\Worker\Research\JsonSeedSource;
use Sveevee\Worker\Research\OfficialWebsiteEnricher;
use Sveevee\Worker\Research\OverpassSource;
use Sveevee\Worker\Research\RobotsPolicy;
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
            $paths = $this->paths($config);
            $this->assertExtensions($config, $command);

            $logger = new Logger($paths['log']);
            $openingHours = new OpeningHoursParser;
            $merger = new BusinessMerger;
            $normalizer = new BusinessNormalizer($openingHours, (array) $config->get('cities', []));
            $repository = new WorkerRepository(new Database($paths['database']), $normalizer, $merger);

            if ($command === 'status') {
                fwrite(STDOUT, Json::encode($repository->statusSummary(), true).PHP_EOL);

                return 0;
            }

            $lock = new ProcessLock($paths['lock']);
            $lock->acquire();
            if ($command === 'retry-failed') {
                $count = $repository->resetFailed($options['limit'] ?? 1000);
                $logger->info('Failed businesses returned to the pending queue.', ['count' => $count]);

                return 0;
            }

            $dryRun = (bool) ($options['dry_run'] ?? false);
            $limit = max(1, (int) ($options['limit'] ?? $config->int('target_per_run', 1000)));
            $runId = Uuid::v4();
            $report = new RunReport($runId, $command, $dryRun);
            $repository->startRun($runId, $command, $dryRun, $config->hash());

            try {
                if (in_array($command, ['research', 'run'], true)) {
                    [$sources, $enrichers] = $this->researchComponents(
                        $config, $repository, $normalizer, $merger, $openingHours
                    );
                    if ($sources === []) {
                        throw new RuntimeException('No research source is enabled in the worker config.');
                    }
                    (new ResearchService(
                        $sources,
                        $enrichers,
                        $config->targets(),
                        $normalizer,
                        $repository,
                        $logger,
                        (array) $config->get('quotas', []),
                    ))->research($runId, $limit, $report);
                }

                if (in_array($command, ['import', 'run'], true)) {
                    $api = $this->api($config);
                    $dailyLimit = $config->get('quotas.max_new_per_day');
                    (new ImportService(
                        $api,
                        $repository,
                        $merger,
                        $logger,
                        min(100, $config->int('batch_size', 100)),
                        is_numeric($dailyLimit) && (int) $dailyLimit > 0 ? (int) $dailyLimit : null,
                    ))->import($runId, $limit, $dryRun, $report);
                }

                $written = $report->write($paths['reports']);
                $repository->finishRun($runId, 'completed', $written['path'], $written['report']);
                $logger->info('Worker run completed.', [
                    'run_id' => $runId,
                    'report' => $written['path'],
                    'summary' => array_intersect_key($written['report'], array_flip([
                        'found', 'new', 'existing', 'updated', 'duplicates',
                        'incomplete', 'failed', 'imported', 'duration_seconds',
                    ])),
                ]);

                return 0;
            } catch (\Throwable $exception) {
                $report->increment('failed');
                $report->error('fatal', $exception->getMessage());
                $written = $report->write($paths['reports'], 'failed');
                $repository->finishRun($runId, 'failed', $written['path'], $written['report'], $exception->getMessage());
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

    private function researchComponents(
        WorkerConfig $config,
        WorkerRepository $repository,
        BusinessNormalizer $normalizer,
        BusinessMerger $merger,
        OpeningHoursParser $openingHours,
    ): array {
        $http = new CurlHttpClient;
        $userAgent = Environment::get(
            'SVEVEE_WORKER_USER_AGENT',
            'SveeveeResearchWorker/1.0 (+https://sveevee.co.il; mailto:info@sveevee.co.il)'
        );
        $sources = [];
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
        $dataDirectory = Environment::get('SVEVEE_WORKER_DATA_DIR');
        if ($dataDirectory !== null) {
            $dataDirectory = rtrim($dataDirectory, '/\\');

            return [
                'database' => $dataDirectory.'/worker.sqlite',
                'reports' => $dataDirectory.'/reports',
                'log' => $dataDirectory.'/logs/worker.log',
                'lock' => $dataDirectory.'/worker.lock',
            ];
        }

        $database = $config->resolvePath((string) $config->get('storage.database'));

        return [
            'database' => $database,
            'reports' => $config->resolvePath((string) $config->get('storage.reports_dir')),
            'log' => $config->resolvePath((string) $config->get('storage.log_file')),
            'lock' => dirname($database).DIRECTORY_SEPARATOR.'worker.lock',
        ];
    }

    private function assertExtensions(WorkerConfig $config, string $command): void
    {
        foreach (['pdo_sqlite', 'json', 'mbstring'] as $extension) {
            if (! extension_loaded($extension)) {
                throw new RuntimeException("Required PHP extension is missing: {$extension}");
            }
        }
        $needsResearchHttp = in_array($command, ['research', 'run'], true)
            && ($config->bool('sources.overpass.enabled')
                || $config->bool('sources.official_website.enabled'));
        $needsHttp = in_array($command, ['import', 'run'], true) || $needsResearchHttp;
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
        if (! in_array($command, ['research', 'import', 'run', 'status', 'retry-failed'], true)) {
            throw new RuntimeException("Unknown command: {$command}");
        }

        $options = [];
        for ($index = 2; $index < count($arguments); $index++) {
            $argument = $arguments[$index];
            if ($argument === '--dry-run') {
                $options['dry_run'] = true;
                continue;
            }
            foreach (['limit', 'config', 'env-file'] as $name) {
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
  worker status
  worker retry-failed [--limit=100]

Options:
  --config=PATH    Worker JSON config (default: config/worker.json)
  --env-file=PATH Environment file (default: .env)
  --limit=N        Maximum businesses for this work block
  --dry-run        Permit reads and local state, but no Sveevee writes

TEXT);
    }
}
