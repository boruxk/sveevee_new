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
$telConfigPath = $directory.DIRECTORY_SEPARATOR.'installation-tel.json';
$telProfilePath = $directory.DIRECTORY_SEPARATOR.'tel-profile.json';
$overtureConfigPath = $directory.DIRECTORY_SEPARATOR.'worker.overture.json';
$overtureProfilePath = $directory.DIRECTORY_SEPARATOR.'overture-profile.json';
$original = Json::decode((string) file_get_contents(dirname(__DIR__).'/config/worker.example.json'));
$original['cities'] = ['Beersheba'];
$original['quotas']['max_new_per_day'] = 1000;
$original['api']['installation_setting'] = 'preserved';
$original['research'] = ['max_http_requests_per_run' => 99, 'installation_setting' => 'preserved'];
$original['storage']['database'] = '/var/lib/example/worker.sqlite';
$original['sources']['json_seed']['paths'] = ['/private/installation-seed.json'];
$original['sources']['data_gov_ckan']['installation_setting'] = 'preserved';
$original['sources']['tel_aviv_business_licenses']['enabled'] = true;
$original['sources']['tel_aviv_business_licenses']['installation_setting'] = 'preserved';
$originalJson = Json::encode($original, true).PHP_EOL;
file_put_contents($configPath, $originalJson);
copy(dirname(__DIR__).'/config/worker.rotation.json', $profilePath);
copy(dirname(__DIR__).'/config/worker.tel-aviv.json', $telProfilePath);
copy(dirname(__DIR__).'/config/worker.overture.json', $overtureProfilePath);
$catalogCities = array_column((require dirname(__DIR__, 2).'/backend/config/locations.php')['cities'], 'name');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$run = static function (bool $apply, array $extra = []) use ($configPath, $profilePath, $overtureProfilePath, $telConfigPath, $telProfilePath): array {
    $arguments = [
        PHP_BINARY, '-d', 'xdebug.mode=off', dirname(__DIR__).'/deploy/configure-rotation.php',
        '--config='.$configPath, '--profile='.$profilePath, '--overture-profile='.$overtureProfilePath,
        '--tel-config='.$telConfigPath, '--tel-profile='.$telProfilePath,
    ];
    array_push($arguments, ...$extra);
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
    $assert(! file_exists($telConfigPath) && $preview['tel_aviv']['cities'] === ['Tel Aviv'] && $preview['tel_aviv']['configured_combinations'] === 10, 'Preview must describe the separate Tel Aviv job without creating it.');
    $assert($preview['tel_aviv']['config'] === $telConfigPath && ! file_exists($directory.DIRECTORY_SEPARATOR.'worker.tel-aviv.json'), 'The explicit Tel Aviv configuration path must be honored.');

    [$code, $output, $error] = $run(true);
    $assert($code === 0, 'Apply failed: '.$error);
    $result = Json::decode($output);
    $changed = Json::decode((string) file_get_contents($configPath));
    $assert(file_get_contents($result['backup']) === $originalJson, 'Backup must preserve the complete original configuration.');
    $assert($changed['cities'] === $catalogCities && count($changed['categories']) === 10, 'Rotation must include the complete application city catalog in catalog order.');
    $assert($changed['target_per_run'] === 10 && $changed['targets_per_run'] === 1 && $changed['businesses_per_combination'] === 10 && $changed['batch_size'] === 10, 'Government job must allow ten successes across one productive combination and use ten-item batches.');
    $assert(! array_key_exists('max_new_per_day', $changed['quotas']), 'Old daily limit must be removed.');
    $assert($changed['api'] === $original['api'] && $changed['storage'] === $original['storage'], 'Installation API and storage settings must survive.');
    $assert($changed['sources']['json_seed'] === $original['sources']['json_seed'], 'Unrelated source settings must survive.');
    $assert($changed['sources']['data_gov_ckan']['installation_setting'] === 'preserved', 'Unspecified source settings must survive.');
    $assert(array_keys(array_filter($changed['sources'], static fn (array $source): bool => $source['enabled'])) === ['data_gov_ckan'], 'Only Gov may be enabled in the existing government job.');
    $assert(! $changed['sources']['overture_places']['enabled'], 'Overture must not enter the government job.');
    foreach (['data_gov_ckan', 'tel_aviv_business_licenses'] as $name) {
        $assert($changed['sources'][$name]['page_size'] === 10 && $changed['sources'][$name]['max_retries'] === 0 && (float) $changed['sources'][$name]['min_interval_seconds'] === 2.0, 'Government source must use ten-row pages, no retries and a two-second interval: '.$name);
    }
    $assert($changed['research']['max_http_requests_per_run'] === 10 && $changed['research']['installation_setting'] === 'preserved', 'The ten-request government budget must replace the old setting without dropping unrelated research settings.');
    $assert($result['max_source_http_requests_per_run'] === 10 && $result['overture']['max_source_http_requests_per_run'] === null, 'Deployment summary must distinguish the government HTTP budget from Overture.');
    $tel = Json::decode((string) file_get_contents($telConfigPath));
    $assert($tel['target_per_run'] === 10 && $tel['targets_per_run'] === 1 && $tel['businesses_per_combination'] === 10 && $tel['batch_size'] === 10, 'Tel Aviv must have its own ten-entry budget and batches.');
    $assert($tel['cities'] === ['Tel Aviv'] && $tel['categories'] === $changed['categories'] && $tel['neighborhoods'] === [], 'Tel Aviv must scan only its city and the same ten categories.');
    $assert($tel['storage']['data_subdirectory'] === 'tel-aviv' && $tel['storage']['database'] === $original['storage']['database'], 'Tel Aviv must isolate its state beneath the existing data directory.');
    $assert($tel['api'] === $original['api'] && $tel['sources']['tel_aviv_business_licenses']['installation_setting'] === 'preserved', 'New Tel Aviv job must inherit the existing access settings.');
    $assert(array_keys(array_filter($tel['sources'], static fn (array $source): bool => $source['enabled'])) === ['tel_aviv_business_licenses'], 'Only Tel Aviv may be enabled in its job.');
    $assert($tel['research']['max_http_requests_per_run'] === 10 && $result['tel_aviv']['max_source_http_requests_per_run'] === 10, 'Tel Aviv must retain its own ten-request source budget.');
    $overture = Json::decode((string) file_get_contents($overtureConfigPath));
    $assert($overture['target_per_run'] === 9000 && $overture['targets_per_run'] === count($catalogCities) * 10 && $overture['businesses_per_combination'] === 9000 && $overture['batch_size'] === 100, 'Overture must have its separate 9000-entry budget and 100-item API batches.');
    $assert($overture['storage']['data_subdirectory'] === 'overture' && $changed['storage']['data_subdirectory'] === '', 'The job states must use separate namespaces.');
    $assert($overture['api']['request_interval_ms'] === 150 && $overture['api']['installation_setting'] === 'preserved', 'Overture pacing must change while installation API settings survive.');
    $assert(array_keys(array_filter($overture['sources'], static fn (array $source): bool => $source['enabled'])) === ['overture_places'], 'Only Overture may be enabled in the Overture job.');
    $assert(! array_key_exists('max_http_requests_per_run', $overture['research'] ?? []) && $overture['research']['installation_setting'] === 'preserved', 'Overture must not inherit the government source HTTP budget.');
    $assert($overture['cities'] === $changed['cities'] && $overture['categories'] === $changed['categories'], 'Both jobs must cover the same catalog targets.');

    // Existing job-specific settings and even Overture formatting must survive the split.
    $tel['api']['installation_setting'] = 'tel-specific';
    file_put_contents($telConfigPath, Json::encode($tel, true).PHP_EOL);
    $overture['api']['request_interval_ms'] = 177;
    file_put_contents($overtureConfigPath, Json::encode($overture)."\n\n");
    $beforeRepeat = [file_get_contents($configPath), file_get_contents($telConfigPath), file_get_contents($overtureConfigPath)];
    [$code, $output, $error] = $run(true);
    $repeat = Json::decode($output);
    $assert($code === 0 && array_filter($repeat['changed']) === [] && $repeat['backups'] === [], 'Repeated apply must be idempotent and avoid redundant backups: '.$error);
    $assert($beforeRepeat === [file_get_contents($configPath), file_get_contents($telConfigPath), file_get_contents($overtureConfigPath)], 'Repeated apply must preserve existing Tel Aviv access settings and Overture byte for byte.');

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
    $assert([file_get_contents($telConfigPath), file_get_contents($overtureConfigPath)] === array_slice($beforeRepeat, 1), 'Invalid government profile must not publish another job either.');
    copy(dirname(__DIR__).'/config/worker.rotation.json', $profilePath);
    $badTel = Json::decode((string) file_get_contents($telProfilePath));
    $badTel['cities'] = [];
    file_put_contents($telProfilePath, Json::encode($badTel));
    [$code] = $run(true);
    $assert($code !== 0 && [file_get_contents($configPath), file_get_contents($telConfigPath), file_get_contents($overtureConfigPath)] === $beforeRepeat, 'Invalid Tel Aviv profile must leave all configurations unchanged.');
    copy(dirname(__DIR__).'/config/worker.tel-aviv.json', $telProfilePath);
    $badOverture = $overture;
    $badOverture['cities'] = [];
    file_put_contents($overtureConfigPath, Json::encode($badOverture));
    [$code] = $run(true);
    $assert($code !== 0 && [file_get_contents($configPath), file_get_contents($telConfigPath)] === array_slice($beforeRepeat, 0, 2), 'Invalid existing third job must prevent government and Tel Aviv publication.');
    file_put_contents($overtureConfigPath, $beforeRepeat[2]);
    [$code] = $run(true, ['--overture-config='.$telConfigPath]);
    $assert($code !== 0 && [file_get_contents($configPath), file_get_contents($telConfigPath), file_get_contents($overtureConfigPath)] === $beforeRepeat, 'Two jobs must never publish to the same configuration path.');
    $badOvertureProfile = Json::decode((string) file_get_contents($overtureProfilePath));
    $badOvertureProfile['cities'] = [];
    file_put_contents($overtureProfilePath, Json::encode($badOvertureProfile));
    $freshOverturePath = $directory.DIRECTORY_SEPARATOR.'fresh-overture.json';
    [$code] = $run(true, ['--overture-config='.$freshOverturePath]);
    $assert($code !== 0 && ! file_exists($freshOverturePath) && [file_get_contents($configPath), file_get_contents($telConfigPath), file_get_contents($overtureConfigPath)] === $beforeRepeat, 'An invalid profile for a newly created third job must prevent every publication.');

    $governmentMutation = $changed;
    $governmentMutation['api']['installation_setting'] = 'changed';
    $overtureMutation = $overture;
    $overtureMutation['api']['installation_setting'] = 'changed';
    $telMutation = $tel;
    $telMutation['api']['installation_setting'] = 'changed';
    foreach ([2, 3] as $failurePosition) {
        $publishCount = 0;
        $transaction = new ConfigFileTransaction(static function (string $stage, string $path) use (&$publishCount, $failurePosition): bool {
            return ++$publishCount === $failurePosition ? false : rename($stage, $path);
        });
        $failed = false;
        try {
            $transaction->write([$configPath => $governmentMutation, $telConfigPath => $telMutation, $overtureConfigPath => $overtureMutation], dirname(__DIR__), true);
        } catch (RuntimeException) {
            $failed = true;
        }
        $assert($failed && $publishCount === $failurePosition, 'Publication failure was not exercised at position '.$failurePosition.'.');
        $assert([file_get_contents($configPath), file_get_contents($telConfigPath), file_get_contents($overtureConfigPath)] === $beforeRepeat, 'Later publication failure must roll back every earlier job byte for byte.');
    }
    $freshTelPath = $directory.DIRECTORY_SEPARATOR.'fresh-tel.json';
    $publishCount = 0;
    $transaction = new ConfigFileTransaction(static function (string $stage, string $path) use (&$publishCount): bool {
        return ++$publishCount === 3 ? false : rename($stage, $path);
    });
    $failed = false;
    try {
        $transaction->write([$configPath => $governmentMutation, $freshTelPath => $telMutation, $overtureConfigPath => $overtureMutation], dirname(__DIR__), true);
    } catch (RuntimeException) {
        $failed = true;
    }
    $assert($failed && $publishCount === 3 && ! file_exists($freshTelPath), 'A newly published Tel Aviv configuration must be removed when the third publication fails.');
    $assert([file_get_contents($configPath), file_get_contents($telConfigPath), file_get_contents($overtureConfigPath)] === $beforeRepeat, 'Rollback of a newly created job must preserve every previously existing job.');
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
