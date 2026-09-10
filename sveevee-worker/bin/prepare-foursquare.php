#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Sveevee\Worker\Config\WorkerPaths;
use Sveevee\Worker\Research\Foursquare\DatasetPreparer;
use Sveevee\Worker\Research\Foursquare\PlaceMapper;
use Sveevee\Worker\Support\Environment;
use Sveevee\Worker\Support\Json;
use Sveevee\Worker\Support\ProcessLock;

try {
    $options = [];
    for ($i = 1; $i < count($argv); $i++) {
        if ($argv[$i] === '--help') {
            fwrite(STDOUT, "Prepare the complete Israel Foursquare Open Source Places snapshot locally.\n"
                ."Does not import into Sveevee or start timers. Closed places remain in the snapshot.\n\n"
                ."php bin/prepare-foursquare.php [--config FILE] [--env-file FILE] [--duckdb FILE] [--output FILE]\n"
                ."  Offline: --jsonl FILE --rows NUMBER --release YYYY-MM-DD --snapshot-id NUMBER\n\n"
                ."Remote preparation uses FOURSQUARE_ACCESS_TOKEN from the ignored worker .env (default)\n"
                ."or the supplied env file. Tokens are never command-line options or snapshot metadata.\n");
            exit(0);
        }
        if (! preg_match('/^--(config|env-file|duckdb|output|jsonl|rows|release|snapshot-id)(?:=(.*))?$/D', $argv[$i], $match)) {
            throw new RuntimeException('Unsupported preparation option. Use --help.');
        }
        $value = $match[2] ?? ($argv[++$i] ?? null);
        if ($value === null || $value === '' || str_starts_with($value, '--')) {
            throw new RuntimeException('Missing value for --'.$match[1]);
        }
        $options[$match[1]] = $value;
    }
    $root = dirname(__DIR__);
    Environment::load($options['env-file'] ?? $root.'/.env');
    $configPath = $options['config'] ?? $root.'/config/worker.foursquare.json';
    if (! is_file($configPath)) {
        throw new RuntimeException('Foursquare configuration file not found.');
    }
    $config = Json::decode((string) file_get_contents($configPath));
    $source = $config['sources']['foursquare_places'] ?? [];
    $absolute = static fn (string $path): bool => str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    $workerDatabase = WorkerPaths::resolve($config['storage'] ?? [], $root)['database'];
    $configuredDatabase = trim((string) ($source['database_path'] ?? ''));
    $destination = $options['output'] ?? ($configuredDatabase !== '' ? $configuredDatabase : dirname($workerDatabase).'/foursquare.sqlite');
    if (! $absolute($destination)) {
        $destination = (isset($options['output']) ? getcwd() : $root).'/'.$destination;
    }
    $preparer = new DatasetPreparer(PlaceMapper::fromConfig($config));
    if (isset($options['jsonl'])) {
        if (! ctype_digit($options['rows'] ?? '') || ! isset($options['release'], $options['snapshot-id'])) {
            throw new RuntimeException('An offline export requires --rows, --release and --snapshot-id.');
        }
        if (! is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0750, true);
        }
        $lock = new ProcessLock($destination.'.prepare.lock');
        $lock->acquire();
        $result = $preparer->importJsonl($options['jsonl'], $options['release'], $destination, (int) $options['rows'], $options['snapshot-id']);
    } else {
        if (isset($options['rows']) || isset($options['release']) || isset($options['snapshot-id'])) {
            throw new RuntimeException('Offline snapshot options require --jsonl. Remote exports pin the current catalog snapshot automatically.');
        }
        $duckdb = $options['duckdb'] ?? $source['duckdb_binary'] ?? 'duckdb';
        if (! in_array($duckdb, ['duckdb', 'duckdb.exe'], true) && ! $absolute($duckdb)) {
            $duckdb = (isset($options['duckdb']) ? getcwd() : $root).'/'.$duckdb;
        }
        fwrite(STDERR, "Downloading the current Foursquare Israel snapshot with an exact Iceberg version and country=IL.\n");
        $result = $preparer->prepare($duckdb, Environment::require('FOURSQUARE_ACCESS_TOKEN'), $destination);
    }
    fwrite(STDOUT, Json::encode($result, true).PHP_EOL);
} catch (Throwable $error) {
    $message = $error->getMessage();
    if (($token = Environment::get('FOURSQUARE_ACCESS_TOKEN')) !== null) {
        $message = str_replace($token, '[REDACTED]', $message);
    }
    fwrite(STDERR, 'Foursquare preparation failed: '.$message.PHP_EOL);
    exit(1);
}
