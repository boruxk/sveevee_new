<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Sveevee\Worker\Config\WorkerConfig;
use Sveevee\Worker\Support\Json;

try {
    $options = getopt('', ['config:', 'profile:', 'apply']);
    $configuredPath = $options['config'] ?? '/etc/sveevee-worker/worker.json';
    $profilePath = $options['profile'] ?? dirname(__DIR__).'/config/worker.rotation.json';
    $configPath = realpath($configuredPath);
    if ($configPath === false || ! is_file($configPath)) {
        throw new RuntimeException('Existing worker configuration was not found. Install the worker first.');
    }
    $current = Json::decode((string) file_get_contents($configPath));
    $profile = Json::decode((string) file_get_contents($profilePath));
    if (! is_array($current) || ! is_array($profile)) {
        throw new RuntimeException('Worker configuration and rotation profile must be JSON objects.');
    }

    // Only replace rotation settings and the explicitly supplied source settings.
    // API credentials, paths, and other installation-specific settings survive.
    $next = $current;
    foreach (['target_per_run', 'targets_per_run', 'businesses_per_combination', 'batch_size', 'cities', 'categories', 'neighborhoods'] as $key) {
        if (! array_key_exists($key, $profile)) {
            throw new RuntimeException("Rotation profile is missing {$key}.");
        }
        $next[$key] = $profile[$key];
    }
    $next['quotas'] = array_replace((array) ($current['quotas'] ?? []), (array) ($profile['quotas'] ?? []));
    unset($next['quotas']['max_new_per_day']);
    foreach ((array) ($profile['sources'] ?? []) as $name => $source) {
        $next['sources'][$name] = array_replace((array) ($current['sources'][$name] ?? []), $source);
    }

    $temporaryPath = tempnam(dirname($configPath), '.rotation-');
    if ($temporaryPath === false) {
        throw new RuntimeException('Unable to create a configuration staging file.');
    }
    try {
        if (file_put_contents($temporaryPath, Json::encode($next, true).PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Unable to stage rotation configuration.');
        }
        $validated = WorkerConfig::load($temporaryPath, dirname(__DIR__));
        $summary = [
            'applied' => isset($options['apply']),
            'cities' => $validated->get('cities'),
            'categories_per_city' => count($validated->get('categories')),
            'configured_combinations' => count($validated->targets()),
            'successful_entries_per_run' => $validated->int('target_per_run', 1000),
            'productive_combinations_per_run' => $validated->int('targets_per_run', 10),
            'successful_entries_per_combination' => $validated->int('businesses_per_combination', 100),
            'daily_limit' => null,
            'enabled_sources' => array_keys(array_filter(
                (array) $validated->get('sources', []),
                static fn ($source): bool => is_array($source) && ($source['enabled'] ?? false) === true,
            )),
        ];
        if (isset($options['apply'])) {
            $permissions = fileperms($configPath) & 0777;
            $backupPath = $configPath.'.before-rotation-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(4));
            if (! copy($configPath, $backupPath) || ! chmod($backupPath, $permissions)) {
                throw new RuntimeException('Unable to back up the existing configuration.');
            }
            if (PHP_OS_FAMILY !== 'Windows') {
                foreach ([$backupPath, $temporaryPath] as $path) {
                    if (! chown($path, fileowner($configPath)) || ! chgrp($path, filegroup($configPath))) {
                        throw new RuntimeException('Unable to preserve configuration ownership.');
                    }
                }
            }
            if (! chmod($temporaryPath, $permissions) || ! rename($temporaryPath, $configPath)) {
                throw new RuntimeException('Unable to install the validated rotation configuration.');
            }
            $summary['backup'] = $backupPath;
        }
        fwrite(STDOUT, Json::encode($summary, true).PHP_EOL);
    } finally {
        if (is_file($temporaryPath)) {
            unlink($temporaryPath);
        }
    }
} catch (Throwable $exception) {
    fwrite(STDERR, '[ERROR] '.$exception->getMessage().PHP_EOL);
    exit(1);
}
