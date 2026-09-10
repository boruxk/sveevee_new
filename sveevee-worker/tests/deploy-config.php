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
$foursquareConfigPath = $directory.DIRECTORY_SEPARATOR.'worker.foursquare.json';
$foursquareProfilePath = $directory.DIRECTORY_SEPARATOR.'foursquare-profile.json';
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
copy(dirname(__DIR__).'/config/worker.foursquare.json', $foursquareProfilePath);
$catalogCities = array_column((require dirname(__DIR__, 2).'/backend/config/locations.php')['cities'], 'name');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$run = static function (bool $apply, array $extra = []) use ($configPath, $profilePath, $overtureProfilePath, $telConfigPath, $telProfilePath, $foursquareProfilePath): array {
    $arguments = [
        PHP_BINARY, '-d', 'xdebug.mode=off', dirname(__DIR__).'/deploy/configure-rotation.php',
        '--config='.$configPath, '--profile='.$profilePath, '--overture-profile='.$overtureProfilePath,
        '--tel-config='.$telConfigPath, '--tel-profile='.$telProfilePath,
        '--foursquare-profile='.$foursquareProfilePath,
    ];
    foreach ($extra as $argument) {
        $prefix = explode('=', $argument, 2)[0].'=';
        $arguments = array_values(array_filter($arguments, static fn (string $value): bool => ! str_starts_with($value, $prefix)));
    }
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
    $assert($preview['applied'] === false && $preview['configured_combinations'] === 1
        && $preview['source_import_modes']['data_gov_ckan'] === 'all_records', 'Preview must describe the complete connected Gov resources as one source scan.');
    $assert(file_get_contents($configPath) === $originalJson, 'Preview must preserve the existing configuration byte for byte.');
    $assert(glob($configPath.'.before-rotation-*') === [], 'Preview must not create a backup.');
    $assert(! file_exists($overtureConfigPath) && $preview['overture']['successful_entries_per_run'] === 9000, 'Preview must describe the separate Overture job without creating it.');
    $assert(! file_exists($telConfigPath) && $preview['tel_aviv']['cities'] === ['Tel Aviv'] && $preview['tel_aviv']['configured_combinations'] === 1, 'Preview must describe the separate Tel Aviv job without creating it.');
    $assert(! file_exists($foursquareConfigPath) && $preview['foursquare'] === null, 'The ordinary migration must not implicitly add Foursquare.');
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
    $assert($tel['sources']['tel_aviv_business_licenses']['import_mode'] === 'all_records'
        && $result['tel_aviv']['configured_combinations'] === 1, 'Tel Aviv must scan every municipal record as one source scan.');
    $assert($tel['storage']['data_subdirectory'] === 'tel-aviv' && $tel['storage']['database'] === $original['storage']['database'], 'Tel Aviv must isolate its state beneath the existing data directory.');
    $assert($tel['api'] === $original['api'] && $tel['sources']['tel_aviv_business_licenses']['installation_setting'] === 'preserved', 'New Tel Aviv job must inherit the existing access settings.');
    $assert(array_keys(array_filter($tel['sources'], static fn (array $source): bool => $source['enabled'])) === ['tel_aviv_business_licenses'], 'Only Tel Aviv may be enabled in its job.');
    $assert($tel['research']['max_http_requests_per_run'] === 10 && $result['tel_aviv']['max_source_http_requests_per_run'] === 10, 'Tel Aviv must retain its own ten-request source budget.');
    $overture = Json::decode((string) file_get_contents($overtureConfigPath));
    $assert($overture['sources']['overture_places']['import_mode'] === 'all_places' && (float) $overture['sources']['overture_places']['min_confidence'] === 0.0, 'A newly created Overture job must use the complete Israel dataset.');
    $assert($overture['target_per_run'] === 9000 && $overture['targets_per_run'] === 1 && $overture['businesses_per_combination'] === 9000 && $overture['batch_size'] === 100, 'Overture must have one global source scope, a separate 9000-entry budget and 100-item API batches.');
    $assert($overture['storage']['data_subdirectory'] === 'overture' && $changed['storage']['data_subdirectory'] === '', 'The job states must use separate namespaces.');
    $assert($overture['api']['request_interval_ms'] === 150 && $overture['api']['installation_setting'] === 'preserved', 'Overture pacing must change while installation API settings survive.');
    $assert(array_keys(array_filter($overture['sources'], static fn (array $source): bool => $source['enabled'])) === ['overture_places'], 'Only Overture may be enabled in the Overture job.');
    $assert(! array_key_exists('max_http_requests_per_run', $overture['research'] ?? []) && $overture['research']['installation_setting'] === 'preserved', 'Overture must not inherit the government source HTTP budget.');
    $assert($overture['cities'] === $changed['cities'] && $overture['categories'] === $changed['categories'], 'Both jobs must cover the same catalog targets.');

    // Existing job-specific settings and even Overture formatting must survive the split.
    $tel['api']['installation_setting'] = 'tel-specific';
    file_put_contents($telConfigPath, Json::encode($tel, true).PHP_EOL);
    $overture['api']['request_interval_ms'] = 177;
    $overture['sources']['overture_places']['import_mode'] = 'catalog';
    $overture['sources']['overture_places']['min_confidence'] = 0.75;
    $overture['sources']['overture_places']['database_path'] = $directory.'/installed/overture.sqlite';
    $overture['sources']['overture_places']['installation_setting'] = 'preserved';
    file_put_contents($overtureConfigPath, Json::encode($overture)."\n\n");
    $beforeRepeat = [file_get_contents($configPath), file_get_contents($telConfigPath), file_get_contents($overtureConfigPath)];
    [$code, $output, $error] = $run(true);
    $assert($code === 0, 'Repeated apply failed: '.$error);
    $repeat = Json::decode($output);
    $assert($code === 0 && array_filter($repeat['changed']) === [] && $repeat['backups'] === [], 'Repeated apply must be idempotent and avoid redundant backups: '.$error);
    $assert($beforeRepeat === [file_get_contents($configPath), file_get_contents($telConfigPath), file_get_contents($overtureConfigPath)], 'Repeated apply must preserve existing Tel Aviv access settings and Overture byte for byte.');
    $assert($repeat['overture']['overture_import_mode'] === 'catalog', 'A Gov/Tel migration must not implicitly upgrade existing Overture jobs.');

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
    $badProfile['sources']['data_gov_ckan']['import_mode'] = 'invalid';
    file_put_contents($profilePath, Json::encode($badProfile));
    [$code] = $run(true);
    $assert($code !== 0, 'Invalid profile must be rejected.');
    $assert(file_get_contents($configPath) === $beforeInvalid, 'Invalid profile must not overwrite the configuration.');
    $assert([file_get_contents($telConfigPath), file_get_contents($overtureConfigPath)] === array_slice($beforeRepeat, 1), 'Invalid government profile must not publish another job either.');
    copy(dirname(__DIR__).'/config/worker.rotation.json', $profilePath);
    $badTel = Json::decode((string) file_get_contents($telProfilePath));
    $badTel['sources']['tel_aviv_business_licenses']['import_mode'] = 'invalid';
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
    $badOvertureProfile['batch_size'] = 101;
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
    copy(dirname(__DIR__).'/config/worker.overture.json', $overtureProfilePath);
    [$code, $output, $error] = $run(false, ['--update-overture']);
    $upgradePreview = Json::decode($output);
    $assert($code === 0 && $upgradePreview['overture']['overture_import_mode'] === 'all_places' && $upgradePreview['changed'][$overtureConfigPath], 'Explicit upgrade preview must describe the complete Overture mode: '.$error);
    $assert($upgradePreview['overture']['configured_combinations'] === 1, 'Full-mode deployment summary must describe one global source scope, not the old 830-target matrix.');
    $assert([file_get_contents($configPath), file_get_contents($telConfigPath), file_get_contents($overtureConfigPath)] === $beforeRepeat, 'Explicit upgrade preview may not modify an existing job.');
    [$code, $output, $error] = $run(true, ['--update-overture']);
    $upgrade = Json::decode($output);
    $upgradedOverture = Json::decode((string) file_get_contents($overtureConfigPath));
    $assert($code === 0 && $upgradedOverture['sources']['overture_places']['import_mode'] === 'all_places' && (float) $upgradedOverture['sources']['overture_places']['min_confidence'] === 0.0, 'The explicit update flag must upgrade an existing catalog job: '.$error);
    $assert($upgradedOverture['storage'] === $overture['storage'] && $upgradedOverture['sources']['overture_places']['database_path'] === $overture['sources']['overture_places']['database_path'] && $upgradedOverture['sources']['overture_places']['installation_setting'] === 'preserved', 'Overture upgrade must preserve worker/cache paths and installation settings.');
    $assert($upgradedOverture['target_per_run'] === 9000 && $upgradedOverture['batch_size'] === 100 && file_get_contents($upgrade['backups'][$overtureConfigPath]) === $beforeRepeat[2], 'The upgrade must preserve run/batch limits and back up the prior catalog configuration.');
    $assert([file_get_contents($configPath), file_get_contents($telConfigPath)] === array_slice($beforeRepeat, 0, 2), 'Overture upgrade may not change the already configured Gov/Tel jobs.');
    [$code, $output, $error] = $run(true, ['--update-overture']);
    $assert($code === 0 && array_filter(Json::decode($output)['changed']) === [], 'Repeating the explicit Overture upgrade must be idempotent: '.$error);

    // Foursquare addition is independent of legacy profile migrations, including custom budgets and formatting.
    $customGovernment = Json::decode((string) file_get_contents($configPath));
    $customGovernment['target_per_run'] = 37;
    $customGovernment['quotas']['max_new_per_day'] = 123;
    $customTel = Json::decode((string) file_get_contents($telConfigPath));
    $customTel['sources']['tel_aviv_business_licenses']['page_size'] = 17;
    $customOverture = Json::decode((string) file_get_contents($overtureConfigPath));
    $customOverture['api']['request_interval_ms'] = 177;
    foreach ([$configPath => $customGovernment, $telConfigPath => $customTel, $overtureConfigPath => $customOverture] as $path => $document) {
        file_put_contents($path, Json::encode($document)."\n\n");
    }
    $beforeFoursquare = [file_get_contents($configPath), file_get_contents($telConfigPath), file_get_contents($overtureConfigPath)];
    [$code, $output, $error] = $run(false, ['--add-foursquare']);
    $foursquarePreview = Json::decode($output);
    $assert($code === 0 && $foursquarePreview['foursquare']['configured_combinations'] === 1 && $foursquarePreview['foursquare']['source_import_modes'] === ['foursquare_places' => 'all_records'], 'Foursquare preview must describe one complete source scan: '.$error);
    $assert($foursquarePreview['daily_limit'] === 123 && $foursquarePreview['foursquare']['daily_limit'] === null, 'Foursquare preview must accurately report preserved government limits and the independent uncapped Foursquare job.');
    $assert(array_keys(array_filter($foursquarePreview['changed'])) === [$foursquareConfigPath] && ! file_exists($foursquareConfigPath), 'Explicit addition preview may change only the new Foursquare document and must not publish it.');
    $assert([file_get_contents($configPath), file_get_contents($telConfigPath), file_get_contents($overtureConfigPath)] === $beforeFoursquare, 'Foursquare preview must preserve all prior files byte for byte.');

    $badFoursquare = Json::decode((string) file_get_contents($foursquareProfilePath));
    $badFoursquare['sources']['foursquare_places']['import_mode'] = 'invalid';
    file_put_contents($foursquareProfilePath, Json::encode($badFoursquare));
    [$code] = $run(true, ['--add-foursquare']);
    $assert($code !== 0 && ! file_exists($foursquareConfigPath), 'Invalid Foursquare configuration must not be published.');
    $assert([file_get_contents($configPath), file_get_contents($telConfigPath), file_get_contents($overtureConfigPath)] === $beforeFoursquare, 'Invalid fourth job must preserve every existing configuration.');
    unset($badFoursquare['sources']['foursquare_places']);
    file_put_contents($foursquareProfilePath, Json::encode($badFoursquare));
    [$code] = $run(true, ['--add-foursquare']);
    $assert($code !== 0 && ! file_exists($foursquareConfigPath), 'A profile without its Foursquare source must not create a nonfunctional job.');
    copy(dirname(__DIR__).'/config/worker.foursquare.json', $foursquareProfilePath);
    foreach ([$configPath, $telConfigPath, $overtureConfigPath] as $collision) {
        [$code] = $run(true, ['--add-foursquare', '--foursquare-config='.$collision]);
        $assert($code !== 0 && ! file_exists($foursquareConfigPath), 'Foursquare must reject every existing job configuration path.');
    }
    [$code] = $run(true, ['--add-foursquare', '--update-overture']);
    $assert($code !== 0 && ! file_exists($foursquareConfigPath), 'A Foursquare addition must not simultaneously migrate Overture.');

    // An unrelated legacy profile is not even read while adding Foursquare.
    file_put_contents($profilePath, '{invalid legacy profile');
    [$code, $output, $error] = $run(true, ['--add-foursquare']);
    $foursquareResult = Json::decode($output);
    $assert($code === 0, 'Explicit Foursquare addition failed: '.$error);
    $assert(array_keys(array_filter($foursquareResult['changed'])) === [$foursquareConfigPath] && $foursquareResult['backups'] === [], 'Adding Foursquare may publish only its new file and must not back up or rewrite untouched jobs.');
    $assert([file_get_contents($configPath), file_get_contents($telConfigPath), file_get_contents($overtureConfigPath)] === $beforeFoursquare, 'Foursquare addition must preserve every existing job, including custom budgets and formatting.');
    $foursquare = Json::decode((string) file_get_contents($foursquareConfigPath));
    $assert($foursquare['target_per_run'] === 9000 && $foursquare['targets_per_run'] === 1 && $foursquare['businesses_per_combination'] === 9000 && $foursquare['batch_size'] === 100, 'Foursquare must have an independent 9000-entry global budget and 100-item batches.');
    $assert($foursquare['storage']['data_subdirectory'] === 'foursquare' && $foursquare['storage']['database'] === $original['storage']['database'], 'Foursquare must inherit the installation base directory while isolating its state.');
    $assert($foursquare['api']['request_interval_ms'] === 150 && $foursquare['api']['installation_setting'] === 'preserved', 'Foursquare pacing must preserve installation API settings.');
    $assert(! isset($foursquare['quotas']['max_new_per_day']) && ! isset($foursquare['research']['max_http_requests_per_run']) && $foursquare['research']['installation_setting'] === 'preserved', 'Foursquare must not inherit government daily or HTTP limits, and must retain unrelated settings.');
    $assert(array_keys(array_filter($foursquare['sources'], static fn (array $source): bool => $source['enabled'])) === ['foursquare_places'], 'Only Foursquare may be enabled in its isolated job.');
    $assert(array_keys($foursquare['sources']['foursquare_places']['city_names']) === $catalogCities, 'Foursquare city aliases must cover the canonical catalog.');

    $foursquare['api']['request_interval_ms'] = 193;
    $foursquare['sources']['foursquare_places']['database_path'] = $directory.'/custom/foursquare.sqlite';
    file_put_contents($foursquareConfigPath, Json::encode($foursquare)."\n\n");
    $foursquareBytes = file_get_contents($foursquareConfigPath);
    [$code, $output, $error] = $run(true, ['--add-foursquare']);
    $assert($code === 0 && array_filter(Json::decode($output)['changed']) === [] && file_get_contents($foursquareConfigPath) === $foursquareBytes, 'Repeating Foursquare addition must preserve installation settings and formatting without redundant backups: '.$error);
    $assert([file_get_contents($configPath), file_get_contents($telConfigPath), file_get_contents($overtureConfigPath)] === $beforeFoursquare, 'Repeating Foursquare addition must not migrate legacy jobs.');
    copy(dirname(__DIR__).'/config/worker.rotation.json', $profilePath);

    $allBefore = [...$beforeFoursquare, $foursquareBytes];
    $fourDocuments = [$configPath => $customGovernment, $telConfigPath => $customTel, $overtureConfigPath => $customOverture, $foursquareConfigPath => $foursquare];
    foreach ($fourDocuments as &$document) {
        $document['api']['installation_setting'] = 'transaction-mutated';
    }
    unset($document);
    foreach ([2, 3, 4] as $failurePosition) {
        $publishCount = 0;
        $transaction = new ConfigFileTransaction(static function (string $stage, string $path) use (&$publishCount, $failurePosition): bool {
            return ++$publishCount === $failurePosition ? false : rename($stage, $path);
        });
        $failed = false;
        try {
            $transaction->write($fourDocuments, dirname(__DIR__), true);
        } catch (RuntimeException) {
            $failed = true;
        }
        $assert($failed && $publishCount === $failurePosition, 'Four-job transaction must exercise publication failure at position '.$failurePosition.'.');
        $assert(array_map(file_get_contents(...), array_keys($fourDocuments)) === $allBefore, 'Four-job rollback must restore every earlier document byte for byte.');
    }
    $freshFoursquarePath = $directory.DIRECTORY_SEPARATOR.'fresh-foursquare.json';
    $publishCount = 0;
    $transaction = new ConfigFileTransaction(static function (string $stage, string $path) use (&$publishCount): bool {
        return ++$publishCount === 3 ? false : rename($stage, $path);
    });
    $failed = false;
    try {
        $transaction->write([$configPath => $fourDocuments[$configPath], $freshFoursquarePath => $foursquare, $telConfigPath => $fourDocuments[$telConfigPath], $overtureConfigPath => $fourDocuments[$overtureConfigPath]], dirname(__DIR__), true);
    } catch (RuntimeException) {
        $failed = true;
    }
    $assert($failed && $publishCount === 3 && ! file_exists($freshFoursquarePath), 'A new Foursquare file must be removed if later publication fails.');
    $assert(array_map(file_get_contents(...), array_keys($fourDocuments)) === $allBefore, 'Rolling back a newly created Foursquare job must preserve all preexisting configurations.');

    $badExistingFoursquare = $foursquare;
    $badExistingFoursquare['batch_size'] = 101;
    file_put_contents($foursquareConfigPath, Json::encode($badExistingFoursquare));
    [$code] = $run(true);
    $assert($code !== 0 && [file_get_contents($configPath), file_get_contents($telConfigPath), file_get_contents($overtureConfigPath)] === $beforeFoursquare, 'An invalid existing Foursquare job must prevent publication during an ordinary migration too.');
    file_put_contents($foursquareConfigPath, $foursquareBytes);
    [$code, $output, $error] = $run(true);
    $assert($code === 0 && file_get_contents($foursquareConfigPath) === $foursquareBytes && ! Json::decode($output)['changed'][$foursquareConfigPath], 'Ordinary legacy migration must leave existing Foursquare settings and formatting untouched: '.$error);

    $absentTel = $directory.DIRECTORY_SEPARATOR.'absent-tel.json';
    $absentOverture = $directory.DIRECTORY_SEPARATOR.'absent-overture.json';
    $minimalFoursquarePath = $directory.DIRECTORY_SEPARATOR.'minimal-foursquare.json';
    $governmentBeforeMinimal = file_get_contents($configPath);
    [$code, $output, $error] = $run(true, ['--add-foursquare', '--tel-config='.$absentTel, '--overture-config='.$absentOverture, '--foursquare-config='.$minimalFoursquarePath]);
    $assert($code === 0, 'Foursquare addition with absent legacy jobs failed: '.$error);
    $minimalResult = Json::decode($output);
    $assert($code === 0 && is_file($minimalFoursquarePath) && $minimalResult['foursquare']['config'] === $minimalFoursquarePath, 'Foursquare addition must honor an explicit destination when other jobs are not installed: '.$error);
    $assert(! file_exists($absentTel) && ! file_exists($absentOverture) && $minimalResult['tel_aviv'] === null && $minimalResult['overture'] === null && file_get_contents($configPath) === $governmentBeforeMinimal, 'Adding Foursquare must neither create absent legacy jobs nor modify the existing base configuration.');
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
