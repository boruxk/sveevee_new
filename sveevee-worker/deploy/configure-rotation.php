<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Sveevee\Worker\Config\ConfigFileTransaction;
use Sveevee\Worker\Support\Json;

try {
    $options = getopt('', ['config:', 'profile:', 'overture-config:', 'overture-profile:', 'apply']);
    $root = dirname(__DIR__);
    $configPath = realpath($options['config'] ?? '/etc/sveevee-worker/worker.json');
    if ($configPath === false || ! is_file($configPath)) {
        throw new RuntimeException('Existing worker configuration was not found. Install the worker first.');
    }
    $overturePath = $options['overture-config'] ?? dirname($configPath).'/worker.overture.json';
    $overtureDirectory = realpath(dirname($overturePath));
    if ($overtureDirectory === false) {
        throw new RuntimeException('Overture configuration directory does not exist.');
    }
    $overturePath = realpath($overturePath) ?: $overtureDirectory.DIRECTORY_SEPARATOR.basename($overturePath);
    if ($configPath === $overturePath) {
        throw new RuntimeException('Government and Overture configurations must use different files.');
    }
    $read = static function (string $path): array {
        if (! is_file($path)) {
            throw new RuntimeException('Configuration/profile not found: '.$path);
        }
        $value = Json::decode((string) file_get_contents($path));
        if (! is_array($value) || array_is_list($value)) {
            throw new RuntimeException('Worker configuration and rotation profiles must be JSON objects.');
        }

        return $value;
    };
    $government = $read($configPath);
    $overture = is_file($overturePath) ? $read($overturePath) : $government;
    $governmentProfile = $read($options['profile'] ?? $root.'/config/worker.rotation.json');
    $overtureProfile = $read($options['overture-profile'] ?? $root.'/config/worker.overture.json');
    $merge = static function (array $current, array $profile, string $namespace, array $enabledSources): array {
        foreach (['target_per_run', 'targets_per_run', 'businesses_per_combination', 'batch_size', 'cities', 'categories', 'neighborhoods'] as $key) {
            if (! array_key_exists($key, $profile)) {
                throw new RuntimeException('Rotation profile is missing '.$key.'.');
            }
            $current[$key] = $profile[$key];
        }
        $current['quotas'] = array_replace((array) ($current['quotas'] ?? []), (array) ($profile['quotas'] ?? []));
        unset($current['quotas']['max_new_per_day']);
        $current['storage']['data_subdirectory'] = $namespace;
        $current['api'] = array_replace((array) ($current['api'] ?? []), (array) ($profile['api'] ?? []));
        foreach (($profile['sources'] ?? []) as $name => $source) {
            $current['sources'][$name] = array_replace((array) ($current['sources'][$name] ?? []), $source);
        }
        foreach ($current['sources'] as $name => &$source) {
            $source['enabled'] = in_array($name, $enabledSources, true);
        }
        unset($source);

        return $current;
    };
    $government = $merge($government, $governmentProfile, '', ['data_gov_ckan', 'tel_aviv_business_licenses']);
    $overture = $merge($overture, $overtureProfile, 'overture', ['overture_places']);
    $apply = isset($options['apply']);
    $result = (new ConfigFileTransaction)->write([$configPath => $government, $overturePath => $overture], $root, $apply);
    $summary = static fn (array $config, string $path): array => [
        'config' => $path, 'cities' => $config['cities'],
        'categories_per_city' => count($config['categories']),
        'configured_combinations' => count($config['cities']) * count($config['categories']),
        'successful_entries_per_run' => $config['target_per_run'],
        'productive_combinations_per_run' => $config['targets_per_run'],
        'successful_entries_per_combination' => $config['businesses_per_combination'],
        'batch_size' => $config['batch_size'], 'daily_limit' => null,
        'enabled_sources' => array_keys(array_filter($config['sources'], static fn (array $source): bool => $source['enabled'] === true)),
    ];
    fwrite(STDOUT, Json::encode([
        'applied' => $apply, ...$summary($government, $configPath),
        'overture' => $summary($overture, $overturePath),
        'changed' => $result['changed'], 'backups' => $result['backups'],
        'backup' => $result['backups'][$configPath] ?? null,
    ], true).PHP_EOL);
} catch (Throwable $error) {
    fwrite(STDERR, '[ERROR] '.$error->getMessage().PHP_EOL);
    exit(1);
}
