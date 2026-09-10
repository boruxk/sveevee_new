<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Sveevee\Worker\Config\ResearchTarget;
use Sveevee\Worker\Config\WorkerConfig;
use Sveevee\Worker\Config\WorkerPaths;
use Sveevee\Worker\Pipeline\RunBudget;
use Sveevee\Worker\Storage\Database;
use Sveevee\Worker\Support\Json;
use Sveevee\Worker\Support\ProcessLock;

$root = dirname(__DIR__);
$government = Json::decode((string) file_get_contents($root.'/config/worker.rotation.json'));
$tel = WorkerConfig::load($root.'/config/worker.tel-aviv.json', $root);
$overture = WorkerConfig::load($root.'/config/worker.overture.json', $root);
$foursquare = WorkerConfig::load($root.'/config/worker.foursquare.json', $root);
$directory = sys_get_temp_dir().'/sveevee-jobs-'.bin2hex(random_bytes(8));
mkdir($directory, 0700, true);
$previousDataDirectory = getenv('SVEVEE_WORKER_DATA_DIR');
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$governmentDatabase = null;
$telDatabase = null;
$overtureDatabase = null;
$foursquareDatabase = null;
$governmentLock = null;
$telLock = null;
$overtureLock = null;
$foursquareLock = null;
try {
    putenv('SVEVEE_WORKER_DATA_DIR='.$directory);
    $governmentPaths = WorkerPaths::resolve(['data_subdirectory' => '', 'database' => '/ignored/worker.sqlite'], $root);
    $overturePaths = WorkerPaths::resolve((array) $overture->get('storage'), $root);
    $telPaths = WorkerPaths::resolve((array) $tel->get('storage'), $root);
    $foursquarePaths = WorkerPaths::resolve((array) $foursquare->get('storage'), $root);
    $assert($governmentPaths['database'] === $directory.'/worker.sqlite' && $overturePaths['database'] === $directory.'/overture/worker.sqlite', 'Shared environment overrides must still yield separate job databases.');
    foreach (['database', 'reports', 'log', 'lock'] as $path) {
        $assert(count(array_unique([$governmentPaths[$path], $telPaths[$path], $overturePaths[$path], $foursquarePaths[$path]])) === 4, 'Job paths overlap: '.$path);
    }
    $assert($telPaths['database'] === $directory.'/tel-aviv/worker.sqlite', 'Shared environment must place Tel Aviv in its own data subdirectory.');
    $assert($foursquarePaths['database'] === $directory.'/foursquare/worker.sqlite', 'Shared environment must place Foursquare in its own data subdirectory.');
    $governmentDatabase = new Database($governmentPaths['database']);
    $overtureDatabase = new Database($overturePaths['database']);
    $telDatabase = new Database($telPaths['database']);
    $foursquareDatabase = new Database($foursquarePaths['database']);
    $governmentDatabase->pdo->exec("INSERT INTO runs (id, command, dry_run, status, config_hash, started_at) VALUES ('government-marker', 'research', 0, 'running', 'fixture', '2026-01-01T00:00:00Z')");
    $assert((int) $overtureDatabase->pdo->query('SELECT COUNT(*) FROM runs')->fetchColumn() === 0, 'Overture must not see government run state.');
    $assert((int) $telDatabase->pdo->query('SELECT COUNT(*) FROM runs')->fetchColumn() === 0, 'Tel Aviv must not inherit government run state.');
    $assert((int) $foursquareDatabase->pdo->query('SELECT COUNT(*) FROM runs')->fetchColumn() === 0, 'Foursquare must not inherit government run state.');
    $telDatabase->pdo->exec("INSERT INTO runs (id, command, dry_run, status, config_hash, started_at) VALUES ('tel-marker', 'research', 0, 'running', 'fixture', '2026-01-01T00:00:00Z')");
    $assert((int) $governmentDatabase->pdo->query('SELECT COUNT(*) FROM runs')->fetchColumn() === 1, 'Government state must remain intact.');
    $governmentLock = new ProcessLock($governmentPaths['lock']);
    $governmentLock->acquire();
    $overtureLock = new ProcessLock($overturePaths['lock']);
    $overtureLock->acquire();
    $telLock = new ProcessLock($telPaths['lock']);
    $telLock->acquire();
    $foursquareLock = new ProcessLock($foursquarePaths['lock']);
    $foursquareLock->acquire();
    $assert(is_file($governmentPaths['lock']) && is_file($telPaths['lock']) && is_file($overturePaths['lock']) && is_file($foursquarePaths['lock']), 'All four jobs need their own process lock.');
    $sameJobRejected = false;
    try {
        (new ProcessLock($overturePaths['lock']))->acquire();
    } catch (RuntimeException) {
        $sameJobRejected = true;
    }
    $assert($sameJobRejected, 'Two instances of the same job may not overlap.');
    foreach (['..', '../overture', '/overture', 'government', 'overture/other', '../tel-aviv', 'tel-aviv/other', 'tel-aviv\\other', '../foursquare', '/foursquare', 'foursquare/other', 'foursquare\\other'] as $namespace) {
        $rejected = false;
        try {
            WorkerPaths::resolve(['data_subdirectory' => $namespace], $root);
        } catch (RuntimeException) {
            $rejected = true;
        }
        $assert($rejected, 'Unexpected state namespace was accepted: '.$namespace);
    }
    putenv('SVEVEE_WORKER_DATA_DIR');
    $baseStorage = ['database' => $directory.'/custom.sqlite', 'reports_dir' => $directory.'/original-reports', 'log_file' => $directory.'/original.log'];
    $paths = WorkerPaths::resolve($baseStorage, $root);
    $assert($paths['database'] === $baseStorage['database'] && $paths['reports'] === $baseStorage['reports_dir'] && $paths['log'] === $baseStorage['log_file'], 'Existing government paths must survive without an environment override.');
    $assert(WorkerPaths::resolve($baseStorage + ['data_subdirectory' => 'overture'], $root)['database'] === $directory.'/overture/worker.sqlite', 'Overture must be isolated beside a custom government database as well.');
    $assert(WorkerPaths::resolve($baseStorage + ['data_subdirectory' => 'tel-aviv'], $root)['database'] === $directory.'/tel-aviv/worker.sqlite', 'Tel Aviv must be isolated beside a custom government database as well.');
    $assert(WorkerPaths::resolve($baseStorage + ['data_subdirectory' => 'foursquare'], $root)['database'] === $directory.'/foursquare/worker.sqlite', 'Foursquare must be isolated beside a custom government database as well.');

    $empty = new ResearchTarget('Empty City', 'food_catering.bakery');
    $productive = new ResearchTarget('Haifa', 'food_catering.bakery');
    $next = new ResearchTarget('Tel Aviv', 'food_catering.cafes');
    $budget = new RunBudget($government['target_per_run'], $government['targets_per_run'], $government['businesses_per_combination']);
    $budget->record($empty, 0);
    $assert($budget->remaining($productive) === 10, 'Empty government combinations must not consume the productive slot.');
    $budget->record($productive, 4);
    $assert($budget->remaining($productive) === 6 && $budget->remaining($next) === 0, 'The government job must stop after one productive combination.');
    $budget->record($productive, 6);
    $assert($budget->remaining($productive) === 0, 'The government job exceeded ten successes.');
    $assert($government['research']['max_http_requests_per_run'] === 10 && $government['batch_size'] === 10, 'The government profile must cap both source HTTP requests and API batch size at ten.');
    foreach (['data_gov_ckan' => $government['sources']['data_gov_ckan'], 'tel_aviv_business_licenses' => $tel->source('tel_aviv_business_licenses')] as $name => $settings) {
        $assert($settings['page_size'] === 10 && $settings['max_retries'] === 0 && (float) $settings['min_interval_seconds'] === 2.0, 'Incorrect cautious government source settings: '.$name);
    }
    $assert(array_keys(array_filter($government['sources'], static fn (array $source): bool => $source['enabled'])) === ['data_gov_ckan'], 'Government profile must enable only Gov.');
    $assert(array_keys(array_filter($tel->get('sources'), static fn (array $source): bool => $source['enabled'])) === ['tel_aviv_business_licenses'], 'Tel Aviv profile must enable only its municipal source.');
    $assert($tel->fullSourceProvider() === 'tel_aviv_business_licenses' && count($tel->targets()) === 1
        && $tel->targets()[0]->isFullSource(), 'Tel Aviv must scan every municipal record without a category matrix.');
    $telBudget = new RunBudget($tel->int('target_per_run', 0), $tel->int('targets_per_run', 0), $tel->int('businesses_per_combination', 0));
    $telTargets = $tel->targets();
    $telBudget->record($telTargets[0], 0);
    $assert($telBudget->remaining($telTargets[0]) === 10, 'Empty Tel Aviv pages must not use its write budget.');
    $telBudget->record($telTargets[0], 10);
    $assert($telBudget->remaining($telTargets[0]) === 0 && $tel->int('batch_size', 0) === 10 && $tel->int('research.max_http_requests_per_run', 0) === 10, 'Tel Aviv must independently cap successes, batches and source HTTP calls at ten.');
    $govFixturePath = $directory.'/government-full.json';
    file_put_contents($govFixturePath, Json::encode(array_replace_recursive($government, ['storage' => ['database' => 'var/worker.sqlite', 'reports_dir' => 'var/reports', 'log_file' => 'var/logs/worker.log']])));
    $govConfig = WorkerConfig::load($govFixturePath, $root);
    $assert($govConfig->fullSourceProvider() === 'data_gov_ckan' && count($govConfig->targets()) === 1
        && $govConfig->targets()[0]->isFullSource(), 'Gov must scan all connected resources without city/category restrictions.');
    $assert($overture->get('research.max_http_requests_per_run') === null, 'Overture must not acquire a government source HTTP budget.');
    $validationFile = $directory.'/validate-config.json';
    $validationConfig = Json::decode((string) file_get_contents($root.'/config/worker.overture.json'));
    foreach ([0, -1, 1.5, '10', null, false, []] as $invalidBudget) {
        $validationConfig['research']['max_http_requests_per_run'] = $invalidBudget;
        file_put_contents($validationFile, Json::encode($validationConfig));
        $rejected = false;
        try {
            WorkerConfig::load($validationFile, $root);
        } catch (RuntimeException) {
            $rejected = true;
        }
        $assert($rejected, 'Source HTTP budget must be a positive JSON integer.');
    }
    foreach ([1, 10, 100] as $validBudget) {
        $validationConfig['research']['max_http_requests_per_run'] = $validBudget;
        file_put_contents($validationFile, Json::encode($validationConfig));
        $assert(WorkerConfig::load($validationFile, $root)->get('research.max_http_requests_per_run') === $validBudget, 'A positive source HTTP budget must be accepted.');
    }

    $budget = new RunBudget($overture->int('target_per_run', 0), $overture->int('targets_per_run', 0), $overture->int('businesses_per_combination', 0));
    $targets = $overture->targets();
    $assert($overture->overtureAllPlaces() && count($targets) === 1 && $overture->get('sources.overture_places.import_mode') === 'all_places' && (float) $overture->get('sources.overture_places.min_confidence') === 0.0, 'Full Overture must scan one global IL source instead of the city/category matrix.');
    $budget->record($targets[0], 1000);
    $assert($budget->remaining($targets[0]) === 8000, 'Overture still has the old 100-per-combination ceiling.');
    $budget->record($targets[0], 8000);
    $assert($budget->remaining($targets[0]) === 0 && $overture->int('batch_size', 0) === 100, 'The global Overture run must stop at 9000 successes with 100-item batches.');
    // Keep coverage that the general budget has no accidental ten-combination ceiling.
    $budget = new RunBudget(9000, 830, 9000);
    $targets = array_map(static fn (int $id): ResearchTarget => new ResearchTarget('City '.$id, 'food_catering.bakery'), range(0, 21));
    $budget->record($targets[0], 1000);
    for ($index = 1; $index <= 20; $index++) {
        $assert($budget->remaining($targets[$index]) >= 400, 'Overture stopped at the old ten-combination ceiling.');
        $budget->record($targets[$index], 400);
    }
    $assert($budget->remaining($targets[21]) === 0 && $overture->int('batch_size', 0) === 100, 'Overture must stop at 9000 successes while retaining 100-item API batches.');

    $foursquareTargets = $foursquare->targets();
    $assert($foursquare->fullSourceProvider() === 'foursquare_places' && count($foursquareTargets) === 1 && $foursquareTargets[0]->isFullSource(), 'Foursquare must scan the complete Israel snapshot as one global source.');
    $assert(array_keys(array_filter($foursquare->get('sources'), static fn (array $source): bool => $source['enabled'])) === ['foursquare_places'], 'Foursquare must enable only its own source.');
    $assert($foursquare->get('research.max_http_requests_per_run') === null && $foursquare->get('quotas.max_new_per_day') === null, 'Foursquare must not inherit government HTTP or daily budgets.');
    $foursquareBudget = new RunBudget($foursquare->int('target_per_run', 0), $foursquare->int('targets_per_run', 0), $foursquare->int('businesses_per_combination', 0));
    $foursquareBudget->record($foursquareTargets[0], 0);
    $assert($foursquareBudget->remaining($foursquareTargets[0]) === 9000, 'Empty Foursquare records may not consume the successful-entry budget.');
    $foursquareBudget->record($foursquareTargets[0], 8999);
    $assert($foursquareBudget->remaining($foursquareTargets[0]) === 1, 'Foursquare must count every successful entry toward its independent 9000-entry budget.');
    $foursquareBudget->record($foursquareTargets[0], 1);
    $assert($foursquareBudget->remaining($foursquareTargets[0]) === 0 && $foursquare->int('batch_size', 0) === 100 && $foursquare->int('api.request_interval_ms', 0) === 150, 'Foursquare must stop at 9000 successes using 100-item API batches and 150ms pacing.');
    $catalogCities = array_column((require dirname(__DIR__, 2).'/backend/config/locations.php')['cities'], 'name');
    $assert($foursquare->get('cities') === $catalogCities && array_keys($foursquare->get('sources.foursquare_places.city_names')) === $catalogCities, 'Foursquare must retain canonical catalog cities and their Hebrew aliases for location mapping.');

    $governmentTimer = (string) file_get_contents($root.'/deploy/systemd/sveevee-worker.timer');
    $overtureTimer = (string) file_get_contents($root.'/deploy/systemd/sveevee-overture.timer');
    $overtureService = (string) file_get_contents($root.'/deploy/systemd/sveevee-overture.service');
    $telTimer = (string) file_get_contents($root.'/deploy/systemd/sveevee-tel-aviv.timer');
    $telService = (string) file_get_contents($root.'/deploy/systemd/sveevee-tel-aviv.service');
    $foursquareTimer = (string) file_get_contents($root.'/deploy/systemd/sveevee-foursquare.timer');
    $foursquareService = (string) file_get_contents($root.'/deploy/systemd/sveevee-foursquare.service');
    $installer = (string) file_get_contents($root.'/deploy/install.sh');
    $assert(str_contains($governmentTimer, '*:00,10,20,30,40,50:00 Asia/Jerusalem'), 'Government schedule must remain every ten minutes.');
    $assert(str_contains($overtureTimer, '*:00,30:00 Asia/Jerusalem') && str_contains($overtureTimer, 'Unit=sveevee-overture.service'), 'Overture needs its separate half-hour timer.');
    $assert(str_contains($overtureService, 'Type=oneshot') && str_contains($overtureService, '--config=/etc/sveevee-worker/worker.overture.json') && str_contains($overtureService, 'EnvironmentFile=/etc/sveevee-worker/worker.env'), 'Overture must use a separate oneshot job with shared credentials.');
    preg_match_all('/^OnCalendar=(.+)$/m', $telTimer, $telSchedule);
    $assert(array_map('trim', $telSchedule[1]) === ['*-*-* 03:05:00 Asia/Jerusalem'] && str_contains($telTimer, 'Unit=sveevee-tel-aviv.service'), 'Tel Aviv must run independently once daily at 03:05 Israel time.');
    $assert(str_contains($telService, 'Type=oneshot') && str_contains($telService, '--config=/etc/sveevee-worker/worker.tel-aviv.json') && str_contains($telService, 'EnvironmentFile=/etc/sveevee-worker/worker.env'), 'Tel Aviv must use a separate oneshot job with shared credentials.');
    $assert(str_contains($telService, 'StateDirectory=sveevee-worker/tel-aviv') && str_contains($telService, 'ReadWritePaths=/var/lib/sveevee-worker/tel-aviv'), 'Tel Aviv service must write to its own state directory.');
    preg_match_all('/^OnCalendar=(.+)$/m', $foursquareTimer, $foursquareSchedule);
    $assert(array_map('trim', $foursquareSchedule[1]) === ['*-*-* *:15:00 Asia/Jerusalem'] && str_contains($foursquareTimer, 'Unit=sveevee-foursquare.service'), 'Foursquare must use its own hourly timer at minute fifteen.');
    $assert(str_contains($foursquareService, 'Type=oneshot') && str_contains($foursquareService, '--config=/etc/sveevee-worker/worker.foursquare.json') && str_contains($foursquareService, 'EnvironmentFile=/etc/sveevee-worker/worker.env'), 'Foursquare must use its own oneshot job with existing shared API credentials.');
    $assert(str_contains($foursquareService, 'StateDirectory=sveevee-worker/foursquare') && str_contains($foursquareService, 'ReadWritePaths=/var/lib/sveevee-worker/foursquare'), 'Foursquare service must write to its own state directory.');
    foreach (['sveevee-worker', 'sveevee-tel-aviv', 'sveevee-overture', 'sveevee-foursquare'] as $job) {
        $assert(str_contains($installer, $job.'.timer '.$job.'.service'), 'Installer must refuse an active job or timer: '.$job);
        $assert(str_contains($installer, $job.'.service '.$job.'.timer'), 'Installer must install and back up both units for '.$job);
    }
    $assert(str_contains($installer, '"${DATA_DIR}/tel-aviv"'), 'Installer must provision the isolated Tel Aviv directory.');
    $assert(str_contains($installer, '"${DATA_DIR}/foursquare"'), 'Installer must provision the isolated Foursquare directory.');
    $assert(! preg_match('/^\s*systemctl\s+(?:enable|start|restart)\b/m', $installer), 'Installer may not activate any job.');
    fwrite(STDOUT, 'Job configuration: '.$assertions." assertions passed.\n");
} finally {
    putenv($previousDataDirectory === false ? 'SVEVEE_WORKER_DATA_DIR' : 'SVEVEE_WORKER_DATA_DIR='.$previousDataDirectory);
    $governmentLock = null;
    $telLock = null;
    $overtureLock = null;
    $foursquareLock = null;
    $governmentDatabase = null;
    $telDatabase = null;
    $overtureDatabase = null;
    $foursquareDatabase = null;
    foreach ([$directory.'/overture', $directory.'/tel-aviv', $directory.'/foursquare', $directory] as $path) {
        foreach (glob($path.'/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($path)) {
            rmdir($path);
        }
    }
}
