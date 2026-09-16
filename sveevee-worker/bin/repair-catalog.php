#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Sveevee\Worker\Config\WorkerConfig;
use Sveevee\Worker\Config\WorkerPaths;
use Sveevee\Worker\Domain\BusinessMerger;
use Sveevee\Worker\Domain\BusinessNormalizer;
use Sveevee\Worker\Domain\OpeningHoursParser;
use Sveevee\Worker\Research\OverturePlacesSource;
use Sveevee\Worker\Storage\CatalogRepairService;
use Sveevee\Worker\Storage\Database;
use Sveevee\Worker\Storage\WorkerRepository;
use Sveevee\Worker\Support\Environment;
use Sveevee\Worker\Support\Json;
use Sveevee\Worker\Support\ProcessLock;

try {
    $options = [];
    for ($index = 1; $index < count($argv); $index++) {
        $argument = $argv[$index];
        if ($argument === '--help') {
            fwrite(STDOUT, "Preview or stage targeted local catalog repairs. No API calls or timer changes.\n\n"
                ."php bin/repair-catalog.php --config=FILE [--env-file=FILE] [--limit=9000] [--apply|--dry-run]\n"
                ."Default: read-only database preview. --apply only queues local repairs.\n"
                ."Overture: verified legacy/missing source metadata with an existing page ID.\n"
                ."Foursquare: only failed city-validation candidates; reviews/closed/claimed stay protected.\n"
                ."Import staged work separately with worker import, then repeat repair to confirm and advance sibling IDs.\n");
            exit(0);
        }
        if (in_array($argument, ['--apply', '--dry-run'], true)) {
            $options[substr($argument, 2)] = true;

            continue;
        }
        if (! preg_match('/^--(config|env-file|limit)(?:=(.*))?$/D', $argument, $match)) {
            throw new RuntimeException('Unsupported repair option. Use --help.');
        }
        $value = $match[2] ?? ($argv[++$index] ?? null);
        if ($value === null || $value === '' || str_starts_with($value, '--')) {
            throw new RuntimeException('A repair option is missing its value.');
        }
        $options[$match[1]] = $value;
    }
    if (isset($options['apply'], $options['dry-run'])) {
        throw new RuntimeException('Choose --apply or --dry-run.');
    }
    $limit = $options['limit'] ?? '9000';
    if (! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 9000) {
        throw new RuntimeException('--limit must be between 1 and 9000.');
    }
    $root = dirname(__DIR__);
    Environment::load($options['env-file'] ?? $root.'/.env');
    $config = WorkerConfig::load($options['config'] ?? $root.'/config/worker.overture.json', $root);
    $provider = $config->fullSourceProvider();
    if (! in_array($provider, ['overture_places', 'foursquare_places'], true)) {
        throw new RuntimeException('Use the dedicated full-source Overture or Foursquare config for repair.');
    }
    $paths = WorkerPaths::resolve((array) $config->get('storage', []), $root);
    if (! is_file($paths['database']) || ! is_readable($paths['database'])) {
        throw new RuntimeException('The existing worker database is missing or unreadable.');
    }
    $lock = new ProcessLock($paths['lock']);
    $lock->acquire();
    $apply = (bool) ($options['apply'] ?? false);
    $database = new PDO('sqlite:'.$paths['database'], options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::SQLITE_ATTR_OPEN_FLAGS => $apply ? PDO::SQLITE_OPEN_READWRITE : PDO::SQLITE_OPEN_READONLY]);
    $database->exec('PRAGMA busy_timeout=5000');
    $database->exec('PRAGMA foreign_keys=ON');
    if (! $apply) {
        $database->exec('PRAGMA query_only=ON');
    }
    $normalizer = new BusinessNormalizer(new OpeningHoursParser, (array) $config->get('cities', []));
    $merger = new BusinessMerger;
    $source = null;
    if ($provider === 'overture_places') {
        $sourceConfig = $config->source($provider);
        $configured = trim((string) ($sourceConfig['database_path'] ?? ''));
        $sourceConfig['database_path'] = $configured === '' ? dirname($paths['database']).'/overture.sqlite' : $config->resolvePath($configured);
        // The reader's repository is deliberately in memory: lookup does not need or touch import progress.
        $source = new OverturePlacesSource($sourceConfig, $root,
            new WorkerRepository(new Database(':memory:'), $normalizer, $merger, [$provider]));
    }
    $summary = (new CatalogRepairService($database, $normalizer, $merger, $source))->run($provider, $apply, (int) $limit);
    fwrite(STDOUT, Json::encode($summary, true).PHP_EOL);
} catch (Throwable $error) {
    fwrite(STDERR, 'Catalog repair failed: '.$error->getMessage().PHP_EOL);
    exit(1);
}
