#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Sveevee\Worker\Config\WorkerConfig;
use Sveevee\Worker\Config\WorkerPaths;
use Sveevee\Worker\Storage\FoursquareReviewReconciliation;
use Sveevee\Worker\Support\Environment;
use Sveevee\Worker\Support\Json;
use Sveevee\Worker\Support\ProcessLock;

try {
    $options = [];
    for ($index = 1; $index < count($argv); $index++) {
        $argument = $argv[$index];
        if ($argument === '--help') {
            fwrite(STDOUT, "Reconcile explicitly approved Foursquare pages into the local worker.\n\n"
                ."php bin/reconcile-foursquare-reviews.php --config=FILE --manifest=FILE [--env-file=FILE] [--limit=9000] [--apply|--dry-run]\n"
                ."Default: read-only database preview. No API requests, queue retries, cursor resets or timer changes.\n");
            exit(0);
        }
        if (in_array($argument, ['--apply', '--dry-run'], true)) {
            $options[substr($argument, 2)] = true;

            continue;
        }
        if (! preg_match('/^--(config|manifest|env-file|limit)(?:=(.*))?$/D', $argument, $match)) {
            throw new RuntimeException('Unsupported reconciliation option. Use --help.');
        }
        $value = $match[2] ?? ($argv[++$index] ?? null);
        if ($value === null || $value === '' || str_starts_with($value, '--')) {
            throw new RuntimeException('A reconciliation option is missing its value.');
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
    if (! isset($options['manifest']) || ! is_file($options['manifest']) || ! is_readable($options['manifest']) || filesize($options['manifest']) > 32 * 1024 * 1024) {
        throw new RuntimeException('Provide a readable backend decision manifest no larger than 32 MiB.');
    }
    $manifest = Json::decode((string) file_get_contents($options['manifest']));
    if (! is_array($manifest)) {
        throw new RuntimeException('The decision manifest must be a JSON object.');
    }
    $root = dirname(__DIR__);
    Environment::load($options['env-file'] ?? $root.'/.env');
    $config = WorkerConfig::load($options['config'] ?? $root.'/config/worker.foursquare.json', $root);
    if ($config->fullSourceProvider() !== 'foursquare_places') {
        throw new RuntimeException('Use the dedicated Foursquare full-source config.');
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
    $result = (new FoursquareReviewReconciliation($database))->run($manifest, $apply, (int) $limit);
    fwrite(STDOUT, Json::encode($result, true).PHP_EOL);
} catch (Throwable $error) {
    fwrite(STDERR, 'Foursquare reconciliation failed: '.$error->getMessage().PHP_EOL);
    exit(1);
}
