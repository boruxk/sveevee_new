#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Sveevee\Worker\Config\WorkerPaths;
use Sveevee\Worker\Http\CurlHttpClient;
use Sveevee\Worker\Research\Overture\DatasetPreparer;
use Sveevee\Worker\Research\Overture\PlaceMapper;
use Sveevee\Worker\Research\Overture\ReleaseCatalog;
use Sveevee\Worker\Support\Environment;
use Sveevee\Worker\Support\Json;

try {
    $options = [];
    for ($i = 1; $i < count($argv); $i++) {
        if ($argv[$i] === '--help') {
            fwrite(STDOUT, "Prepare an Israel-only Overture Places SQLite snapshot. Does not import businesses or start timers.\n\n"
                ."php bin/prepare-overture.php [--config FILE] [--duckdb FILE] [--release YYYY-MM-DD.N|latest]\n"
                ."  [--parquet LOCAL_FILE] [--output SQLITE_FILE] [--env-file FILE]\n\n"
                ."DuckDB must already be installed. Remote exports install only its official httpfs extension\n"
                ."inside the output directory. Existing snapshots survive failed or empty downloads.\n");
            exit(0);
        }
        if (! preg_match('/^--(config|duckdb|release|parquet|output|env-file)(?:=(.*))?$/D', $argv[$i], $match)) {
            throw new RuntimeException('Unknown option: '.$argv[$i]);
        }
        $value = $match[2] ?? ($argv[++$i] ?? null);
        if ($value === null || $value === '' || str_starts_with($value, '--')) {
            throw new RuntimeException('Missing value for --'.$match[1]);
        }
        $options[$match[1]] = $value;
    }
    if (isset($options['env-file'])) {
        Environment::load($options['env-file']);
    }
    $root = dirname(__DIR__);
    $absolute = static fn (string $path): bool => str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    $resolve = static fn (string $path): string => $absolute($path) ? $path : $root.'/'.$path;
    $configPath = $options['config'] ?? Environment::get('SVEVEE_OVERTURE_CONFIG') ?? $root.'/config/worker.overture.json';
    if (! is_file($configPath)) {
        throw new RuntimeException('Worker config not found: '.$configPath);
    }
    $config = Json::decode((string) file_get_contents($configPath));
    if (! is_array($config) || ! is_array($config['cities'] ?? null) || $config['cities'] === []) {
        throw new RuntimeException('Configure the canonical catalog cities before preparing Overture.');
    }
    $source = $config['sources']['overture_places'] ?? [];
    $workerDatabase = WorkerPaths::resolve($config['storage'] ?? [], $root)['database'];
    $configuredDatabase = trim((string) ($source['database_path'] ?? ''));
    $destination = $options['output'] ?? ($configuredDatabase !== '' ? $resolve($configuredDatabase) : dirname($workerDatabase).'/overture.sqlite');
    $destination = $absolute($destination) ? $destination : getcwd().'/'.$destination;
    $duckdb = $options['duckdb'] ?? $source['duckdb_binary'] ?? 'duckdb';
    if ($duckdb !== 'duckdb' && $duckdb !== 'duckdb.exe' && ! $absolute($duckdb)) {
        $duckdb = (isset($options['duckdb']) ? getcwd() : $root).'/'.$duckdb;
    }
    $catalog = new ReleaseCatalog(new CurlHttpClient);
    $release = $options['release'] ?? $source['release'] ?? 'latest';
    if (isset($options['parquet']) && $release === 'latest') {
        throw new RuntimeException('A local Parquet file requires its explicit --release=YYYY-MM-DD.N (or pinned config release).');
    }
    $release = $release === 'latest' ? $catalog->latest() : $release;
    ReleaseCatalog::validateRelease($release);
    $files = isset($options['parquet']) ? [realpath($options['parquet']) ?: $options['parquet']] : $catalog->files($release);
    fwrite(STDERR, 'Preparing Overture '.$release.' from '.count($files)." partition(s); only country IL inside 34–36°E / 29–34°N.\n");
    $result = (new DatasetPreparer(PlaceMapper::fromConfig($config)))->prepare(
        $duckdb, $files, $release, $destination, (float) ($source['min_confidence'] ?? 0.75),
    );
    fwrite(STDOUT, Json::encode($result, true).PHP_EOL);
} catch (Throwable $error) {
    fwrite(STDERR, 'Overture preparation failed: '.$error->getMessage().PHP_EOL);
    exit(1);
}
