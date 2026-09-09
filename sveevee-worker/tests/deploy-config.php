<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Sveevee\Worker\Config\ConfigFileTransaction;
use Sveevee\Worker\Config\ResearchTarget;
use Sveevee\Worker\Research\Ckan\IsraelCompaniesProfile;
use Sveevee\Worker\Support\Json;

$directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sveevee-rotation-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
$configPath = $directory.DIRECTORY_SEPARATOR.'worker.json';
$profilePath = $directory.DIRECTORY_SEPARATOR.'profile.json';
$overtureConfigPath = $directory.DIRECTORY_SEPARATOR.'worker.overture.json';
$overtureProfilePath = $directory.DIRECTORY_SEPARATOR.'overture-profile.json';
$original = Json::decode((string) file_get_contents(dirname(__DIR__).'/config/worker.example.json'));
$original['cities'] = ['Beersheba'];
$original['quotas']['max_new_per_day'] = 1000;
$original['api']['installation_setting'] = 'preserved';
$original['storage']['database'] = '/var/lib/example/worker.sqlite';
$original['sources']['json_seed']['paths'] = ['/private/installation-seed.json'];
$original['sources']['data_gov_ckan']['installation_setting'] = 'preserved';
$originalJson = Json::encode($original, true).PHP_EOL;
file_put_contents($configPath, $originalJson);
copy(dirname(__DIR__).'/config/worker.rotation.json', $profilePath);
copy(dirname(__DIR__).'/config/worker.overture.json', $overtureProfilePath);
$catalogCities = array_column((require dirname(__DIR__, 2).'/backend/config/locations.php')['cities'], 'name');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$run = static function (bool $apply) use ($configPath, $profilePath, $overtureProfilePath): array {
    $arguments = [
        PHP_BINARY, '-d', 'xdebug.mode=off', dirname(__DIR__).'/deploy/configure-rotation.php',
        '--config='.$configPath, '--profile='.$profilePath, '--overture-profile='.$overtureProfilePath,
    ];
    if ($apply) {
        $arguments[] = '--apply';
    }
    $process = proc_open($arguments, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Unable to execute the configuration migration.');
    }
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $output, $error];
};

try {
    [$code, $output, $error] = $run(false);
    $assert($code === 0, 'Preview failed: '.$error);
    $preview = Json::decode($output);
    $assert($preview['applied'] === false && $preview['configured_combinations'] === count($catalogCities) * 10, 'Preview must describe every catalog city with ten categories.');
    $assert(file_get_contents($configPath) === $originalJson, 'Preview must preserve the existing configuration byte for byte.');
    $assert(glob($configPath.'.before-rotation-*') === [], 'Preview must not create a backup.');
    $assert(! file_exists($overtureConfigPath) && $preview['overture']['successful_entries_per_run'] === 9000, 'Preview must describe the separate Overture job without creating it.');

    [$code, $output, $error] = $run(true);
    $assert($code === 0, 'Apply failed: '.$error);
    $result = Json::decode($output);
    $changed = Json::decode((string) file_get_contents($configPath));
    $assert(file_get_contents($result['backup']) === $originalJson, 'Backup must preserve the complete original configuration.');
    $assert($changed['cities'] === $catalogCities && count($changed['categories']) === 10, 'Rotation must include the complete application city catalog in catalog order.');
    $assert($changed['target_per_run'] === 100 && $changed['targets_per_run'] === 1 && $changed['businesses_per_combination'] === 100, 'Government job must allow 100 successes across one productive combination.');
    $assert(! array_key_exists('max_new_per_day', $changed['quotas']), 'Old daily limit must be removed.');
    $assert($changed['api'] === $original['api'] && $changed['storage'] === $original['storage'], 'Installation API and storage settings must survive.');
    $assert($changed['sources']['json_seed'] === $original['sources']['json_seed'], 'Unrelated source settings must survive.');
    $assert($changed['sources']['data_gov_ckan']['installation_setting'] === 'preserved', 'Unspecified source settings must survive.');
    $assert($changed['sources']['data_gov_ckan']['enabled'] && $changed['sources']['tel_aviv_business_licenses']['enabled'], 'Both real municipal sources must be enabled.');
    $assert(! $changed['sources']['overture_places']['enabled'], 'Overture must not enter the government job.');
    $assert($changed['sources']['data_gov_ckan']['page_size'] === 100 && $changed['sources']['tel_aviv_business_licenses']['page_size'] === 100, 'Both government sources must request at most 100 records per API page.');
    $overture = Json::decode((string) file_get_contents($overtureConfigPath));
    $assert($overture['target_per_run'] === 9000 && $overture['targets_per_run'] === count($catalogCities) * 10 && $overture['businesses_per_combination'] === 9000 && $overture['batch_size'] === 100, 'Overture must have its separate 9000-entry budget and 100-item API batches.');
    $assert($overture['storage']['data_subdirectory'] === 'overture' && $changed['storage']['data_subdirectory'] === '', 'The job states must use separate namespaces.');
    $assert($overture['api']['request_interval_ms'] === 150 && $overture['api']['installation_setting'] === 'preserved', 'Overture pacing must change while installation API settings survive.');
    $assert(array_keys(array_filter($overture['sources'], static fn (array $source): bool => $source['enabled'])) === ['overture_places'], 'Only Overture may be enabled in the Overture job.');
    $assert($overture['cities'] === $changed['cities'] && $overture['categories'] === $changed['categories'], 'Both jobs must cover the same catalog targets.');

    $beforeRepeat = [file_get_contents($configPath), file_get_contents($overtureConfigPath)];
    [$code, $output, $error] = $run(true);
    $repeat = Json::decode($output);
    $assert($code === 0 && array_filter($repeat['changed']) === [] && $repeat['backups'] === [], 'Repeated apply must be idempotent and avoid redundant backups: '.$error);
    $assert($beforeRepeat === [file_get_contents($configPath), file_get_contents($overtureConfigPath)], 'Repeated apply changed a configuration.');

    $datasets = $changed['sources']['data_gov_ckan']['datasets'];
    $national = array_values(array_filter($datasets, static fn (array $dataset): bool => $dataset['profile'] === 'israel_companies'));
    $municipal = array_values(array_filter($datasets, static fn (array $dataset): bool => $dataset['profile'] === 'beer_sheva_business_licenses'));
    $assert(count($national) === 1 && $national[0]['resource_id'] === 'f004176c-b85f-4542-8901-7b3176f9a054', 'The official national companies register must be installed exactly once.');
    $assert(count($municipal) === 1 && $municipal[0]['resource_id'] === '7d4c61e2-2416-453e-8efb-bd02ec89db35', 'The existing Beersheba license dataset must survive.');
    $assert(array_keys($national[0]['city_names']) === $catalogCities, 'Every configured city needs an explicit registry-name mapping.');
    $assert($changed['sources']['data_gov_ckan']['max_records_per_city'] >= 100000, 'The city-scoped register limit must accommodate Tel Aviv, which exceeds 50,000 active companies.');

    $registerProfile = new IsraelCompaniesProfile;
    $unsupported = [];
    $incorrectFilters = [];
    foreach ($catalogCities as $city) {
        foreach ($changed['categories'] as $category) {
            $target = new ResearchTarget($city, $category);
            if (! $registerProfile->supports($national[0], $target)) {
                $unsupported[] = $city.'|'.$category;

                continue;
            }
            $filters = Json::decode($registerProfile->searchParameters($national[0], $target)['filters']);
            if ($filters['שם עיר'] !== $national[0]['city_names'][$city] || $filters['סטטוס חברה'] !== 'פעילה') {
                $incorrectFilters[] = $city.'|'.$category;
            }
        }
    }
    $assert($unsupported === [], 'A real data-source profile must support every configured city/category: '.implode(', ', $unsupported));
    $assert($incorrectFilters === [], 'Each registry request must filter by exact configured city aliases and active status: '.implode(', ', $incorrectFilters));

    $beforeInvalid = file_get_contents($configPath);
    $badProfile = Json::decode((string) file_get_contents($profilePath));
    $badProfile['cities'] = [];
    file_put_contents($profilePath, Json::encode($badProfile));
    [$code] = $run(true);
    $assert($code !== 0, 'Invalid profile must be rejected.');
    $assert(file_get_contents($configPath) === $beforeInvalid, 'Invalid profile must not overwrite the configuration.');
    $assert(file_get_contents($overtureConfigPath) === $beforeRepeat[1], 'Invalid government profile must not publish Overture either.');
    copy(dirname(__DIR__).'/config/worker.rotation.json', $profilePath);
    $badOverture = Json::decode((string) file_get_contents($overtureProfilePath));
    $badOverture['cities'] = [];
    file_put_contents($overtureProfilePath, Json::encode($badOverture));
    [$code] = $run(true);
    $assert($code !== 0 && [file_get_contents($configPath), file_get_contents($overtureConfigPath)] === $beforeRepeat, 'Invalid second job must leave both configurations unchanged.');

    $governmentMutation = $changed;
    $governmentMutation['api']['installation_setting'] = 'changed';
    $overtureMutation = $overture;
    $overtureMutation['api']['installation_setting'] = 'changed';
    $publishCount = 0;
    $transaction = new ConfigFileTransaction(static function (string $stage, string $path) use (&$publishCount): bool {
        return ++$publishCount === 2 ? false : rename($stage, $path);
    });
    $failed = false;
    try {
        $transaction->write([$configPath => $governmentMutation, $overtureConfigPath => $overtureMutation], dirname(__DIR__), true);
    } catch (RuntimeException) {
        $failed = true;
    }
    $assert($failed && $publishCount === 2, 'Second publication failure was not exercised.');
    $assert([file_get_contents($configPath), file_get_contents($overtureConfigPath)] === $beforeRepeat, 'Failure publishing the second job must roll back the first job byte for byte.');
    $assert(glob($directory.DIRECTORY_SEPARATOR.'.rotation-*') === [], 'Staging files must be removed after success and failure.');

    fwrite(STDOUT, "Deployment configuration: {$assertions} assertions passed.\n");
} finally {
    foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    rmdir($directory);
}
