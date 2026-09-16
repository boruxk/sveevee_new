#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Sveevee\Worker\Config\WorkerPaths;
use Sveevee\Worker\Research\OpenStreetMap\DatasetPreparer;
use Sveevee\Worker\Research\OpenStreetMap\PlaceMapper;
use Sveevee\Worker\Support\Json;
use Sveevee\Worker\Support\Environment;
use Sveevee\Worker\Support\ProcessLock;

try {
    $options = [];
    for ($i = 1; $i < count($argv); $i++) {
        if ($argv[$i] === '--help') {
            echo "Prepare an offline OpenStreetMap Israel snapshot. No imports or timers are started.\n\n"
                ."php bin/prepare-osm.php [--config FILE] [--env-file FILE] [--python EXECUTABLE] [--pbf FILE] [--output FILE]\n"
                ."With no --pbf, download the Geofabrik Israel-and-Palestine file once, then filter to the IL country boundary.\n"
                ."Python requires osmium and shapely. No API account or token is used.\n"
                ."Previously extracted offline input: --jsonl FILE --manifest FILE\n";
            exit(0);
        }
        if (! preg_match('/^--(config|env-file|python|pbf|output|jsonl|manifest)(?:=(.*))?$/D', $argv[$i], $match)) {
            throw new RuntimeException('Unsupported OSM preparation option; use --help.');
        }
        $value = $match[2] ?? ($argv[++$i] ?? null);
        if ($value === null || $value === '' || str_starts_with($value, '--')) {
            throw new RuntimeException('Missing OSM preparation option value.');
        }
        $options[$match[1]] = $value;
    }
    $root = dirname(__DIR__);
    Environment::load($options['env-file'] ?? $root.'/.env');
    $config = Json::decode((string) file_get_contents($options['config'] ?? $root.'/config/worker.osm.json'));
    $source = $config['sources']['osm_places'] ?? [];
    $absolute = static fn (string $path): bool => str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    $workerDatabase = WorkerPaths::resolve($config['storage'] ?? [], $root)['database'];
    $configuredDatabase = trim((string) ($source['database_path'] ?? ''));
    $destination = $options['output'] ?? ($configuredDatabase !== '' ? $configuredDatabase : dirname($workerDatabase).'/osm.sqlite');
    if (! $absolute($destination)) {
        $destination = (isset($options['output']) ? getcwd() : $root).'/'.$destination;
    }
    if (! is_dir(dirname($destination)) && ! mkdir(dirname($destination), 0750, true) && ! is_dir(dirname($destination))) {
        throw new RuntimeException('Cannot create OSM snapshot directory.');
    }
    $lock = new ProcessLock($destination.'.prepare.lock');
    $lock->acquire();
    if (isset($options['jsonl'])) {
        if (! isset($options['manifest']) || isset($options['pbf'])) {
            throw new RuntimeException('Offline JSONL requires --manifest and cannot be combined with --pbf.');
        }
        $jsonl = $options['jsonl'];
        $manifest = $options['manifest'];
    } else {
        if (isset($options['manifest'])) {
            throw new RuntimeException('--manifest requires --jsonl.');
        }
        $python = $options['python'] ?? $source['python_binary'] ?? (PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3');
        $pbf = $options['pbf'] ?? dirname($destination).'/israel-and-palestine.osm.pbf';
        $jsonl = dirname($destination).'/osm-export.jsonl';
        $manifest = dirname($destination).'/osm-export.manifest.json';
        $command = [$python, $root.'/tools/prepare_osm.py', '--pbf', $pbf, '--output', $jsonl, '--manifest', $manifest];
        if (! isset($options['pbf'])) {
            $command[] = '--download';
        }
        $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, null, null, ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new RuntimeException('Cannot start Python OSM extractor.');
        }
        $deadline = microtime(true) + 3600;
        do {
            $status = proc_get_status($process);
            if (microtime(true) > $deadline) {
                proc_terminate($process);
                proc_close($process);
                throw new RuntimeException('OSM preparation exceeded its 60-minute bound.');
            }
            if ($status['running']) {
                usleep(100000);
            }
        } while ($status['running']);
        $exit = proc_close($process);
        if (($status['exitcode'] >= 0 ? $status['exitcode'] : $exit) !== 0) {
            throw new RuntimeException('OSM extractor failed; the previous SQLite snapshot was retained.');
        }
    }
    $result = (new DatasetPreparer(PlaceMapper::fromConfig($config)))->importJsonl($jsonl, $manifest, $destination);
    echo Json::encode($result, true).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'OSM preparation failed: '.$error->getMessage().PHP_EOL);
    exit(1);
}
