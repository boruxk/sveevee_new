<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Sveevee\Worker\Config\ConfigFileTransaction;
use Sveevee\Worker\Support\Json;

try {
    $options = getopt('', ['config:', 'profile:', 'tel-config:', 'tel-profile:', 'overture-config:', 'overture-profile:', 'apply']);
    $root = dirname(__DIR__);
    $configPath = realpath($options['config'] ?? '/etc/sveevee-worker/worker.json');
    if ($configPath === false || ! is_file($configPath)) {
        throw new RuntimeException('Existing worker configuration was not found. Install the worker first.');
    }
    $destination = static function (string $path): string {
        $directory = realpath(dirname($path));
        if ($directory === false) {
            throw new RuntimeException('Configuration directory does not exist: '.dirname($path));
        }

        return realpath($path) ?: $directory.DIRECTORY_SEPARATOR.basename($path);
    };
    $telPath = $destination($options['tel-config'] ?? dirname($configPath).'/worker.tel-aviv.json');
    $overturePath = $destination($options['overture-config'] ?? dirname($configPath).'/worker.overture.json');
    $paths = [$configPath, $telPath, $overturePath];
    if (PHP_OS_FAMILY === 'Windows') {
        $paths = array_map(strtolower(...), $paths);
    }
    if (count(array_unique($paths)) !== 3) {
        throw new RuntimeException('Government, Tel Aviv and Overture configurations must use different files.');
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
    $tel = is_file($telPath) ? $read($telPath) : $government;
    $overtureExists = is_file($overturePath);
    $overture = is_file($overturePath) ? $read($overturePath) : $government;
    $governmentProfile = $read($options['profile'] ?? $root.'/config/worker.rotation.json');
    $telProfile = $read($options['tel-profile'] ?? $root.'/config/worker.tel-aviv.json');
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
        if ($namespace !== 'overture') {
            $current['research'] = array_replace((array) ($current['research'] ?? []), (array) ($profile['research'] ?? []));
        } else {
            unset($current['research']['max_http_requests_per_run']);
            if (($current['research'] ?? []) === []) {
                unset($current['research']);
            }
        }
        foreach (($profile['sources'] ?? []) as $name => $source) {
            $current['sources'][$name] = array_replace((array) ($current['sources'][$name] ?? []), $source);
        }
        foreach ($current['sources'] as $name => &$source) {
            $source['enabled'] = in_array($name, $enabledSources, true);
        }
        unset($source);

        return $current;
    };
    $government = $merge($government, $governmentProfile, '', ['data_gov_ckan']);
    $tel = $merge($tel, $telProfile, 'tel-aviv', ['tel_aviv_business_licenses']);
    if (! $overtureExists) {
        $overtureProfile = $read($options['overture-profile'] ?? $root.'/config/worker.overture.json');
        $overture = $merge($overture, $overtureProfile, 'overture', ['overture_places']);
    }
    $apply = isset($options['apply']);
    $result = (new ConfigFileTransaction)->write([$configPath => $government, $telPath => $tel, $overturePath => $overture], $root, $apply);
    $summary = static fn (array $config, string $path): array => [
        'config' => $path, 'cities' => $config['cities'],
        'categories_per_city' => count($config['categories']),
        'configured_combinations' => count($config['cities']) * count($config['categories']),
        'successful_entries_per_run' => $config['target_per_run'],
        'productive_combinations_per_run' => $config['targets_per_run'],
        'successful_entries_per_combination' => $config['businesses_per_combination'],
        'batch_size' => $config['batch_size'], 'daily_limit' => null,
        'max_source_http_requests_per_run' => $config['research']['max_http_requests_per_run'] ?? null,
        'enabled_sources' => array_keys(array_filter($config['sources'], static fn (array $source): bool => $source['enabled'] === true)),
    ];
    fwrite(STDOUT, Json::encode([
        'applied' => $apply, ...$summary($government, $configPath),
        'tel_aviv' => $summary($tel, $telPath),
        'overture' => $summary($overture, $overturePath),
        'changed' => $result['changed'], 'backups' => $result['backups'],
        'backup' => $result['backups'][$configPath] ?? null,
    ], true).PHP_EOL);
} catch (Throwable $error) {
    fwrite(STDERR, '[ERROR] '.$error->getMessage().PHP_EOL);
    exit(1);
}
