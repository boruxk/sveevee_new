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
$overture = WorkerConfig::load($root.'/config/worker.overture.json', $root);
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
$overtureDatabase = null;
$governmentLock = null;
$overtureLock = null;
try {
    putenv('SVEVEE_WORKER_DATA_DIR='.$directory);
    $governmentPaths = WorkerPaths::resolve(['data_subdirectory' => '', 'database' => '/ignored/worker.sqlite'], $root);
    $overturePaths = WorkerPaths::resolve((array) $overture->get('storage'), $root);
    $assert($governmentPaths['database'] === $directory.'/worker.sqlite' && $overturePaths['database'] === $directory.'/overture/worker.sqlite', 'Shared environment overrides must still yield separate job databases.');
    foreach (['database', 'reports', 'log', 'lock'] as $path) {
        $assert($governmentPaths[$path] !== $overturePaths[$path], 'Job paths overlap: '.$path);
    }
    $governmentDatabase = new Database($governmentPaths['database']);
    $overtureDatabase = new Database($overturePaths['database']);
    $governmentDatabase->pdo->exec("INSERT INTO runs (id, command, dry_run, status, config_hash, started_at) VALUES ('government-marker', 'research', 0, 'running', 'fixture', '2026-01-01T00:00:00Z')");
    $assert((int) $overtureDatabase->pdo->query('SELECT COUNT(*) FROM runs')->fetchColumn() === 0, 'Overture must not see government run state.');
    $assert((int) $governmentDatabase->pdo->query('SELECT COUNT(*) FROM runs')->fetchColumn() === 1, 'Government state must remain intact.');
    $governmentLock = new ProcessLock($governmentPaths['lock']);
    $governmentLock->acquire();
    $overtureLock = new ProcessLock($overturePaths['lock']);
    $overtureLock->acquire();
    $assert(is_file($governmentPaths['lock']) && is_file($overturePaths['lock']), 'Both jobs need their own process lock.');
    $sameJobRejected = false;
    try {
        (new ProcessLock($overturePaths['lock']))->acquire();
    } catch (RuntimeException) {
        $sameJobRejected = true;
    }
    $assert($sameJobRejected, 'Two instances of the same job may not overlap.');
    foreach (['..', '../overture', '/overture', 'government', 'overture/other'] as $namespace) {
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

    $empty = new ResearchTarget('Empty City', 'food_catering.bakery');
    $productive = new ResearchTarget('Haifa', 'food_catering.bakery');
    $next = new ResearchTarget('Tel Aviv', 'food_catering.cafes');
    $budget = new RunBudget($government['target_per_run'], $government['targets_per_run'], $government['businesses_per_combination']);
    $budget->record($empty, 0);
    $assert($budget->remaining($productive) === 100, 'Empty government combinations must not consume the productive slot.');
    $budget->record($productive, 40);
    $assert($budget->remaining($productive) === 60 && $budget->remaining($next) === 0, 'The two government sources must share one 100-entry productive combination.');
    $budget->record($productive, 60);
    $assert($budget->remaining($productive) === 0, 'The government job exceeded 100 successes.');

    $budget = new RunBudget($overture->int('target_per_run', 0), $overture->int('targets_per_run', 0), $overture->int('businesses_per_combination', 0));
    $targets = $overture->targets();
    $budget->record($targets[0], 1000);
    $assert($budget->remaining($targets[0]) === 8000, 'Overture still has the old 100-per-combination ceiling.');
    for ($index = 1; $index <= 20; $index++) {
        $assert($budget->remaining($targets[$index]) >= 400, 'Overture stopped at the old ten-combination ceiling.');
        $budget->record($targets[$index], 400);
    }
    $assert($budget->remaining($targets[21]) === 0 && $overture->int('batch_size', 0) === 100, 'Overture must stop at 9000 successes while retaining 100-item API batches.');

    $governmentTimer = (string) file_get_contents($root.'/deploy/systemd/sveevee-worker.timer');
    $overtureTimer = (string) file_get_contents($root.'/deploy/systemd/sveevee-overture.timer');
    $overtureService = (string) file_get_contents($root.'/deploy/systemd/sveevee-overture.service');
    $installer = (string) file_get_contents($root.'/deploy/install.sh');
    $assert(str_contains($governmentTimer, '*:00,10,20,30,40,50:00 Asia/Jerusalem'), 'Government schedule must remain every ten minutes.');
    $assert(str_contains($overtureTimer, '*:00,30:00 Asia/Jerusalem') && str_contains($overtureTimer, 'Unit=sveevee-overture.service'), 'Overture needs its separate half-hour timer.');
    $assert(str_contains($overtureService, 'Type=oneshot') && str_contains($overtureService, '--config=/etc/sveevee-worker/worker.overture.json') && str_contains($overtureService, 'EnvironmentFile=/etc/sveevee-worker/worker.env'), 'Overture must use a separate oneshot job with shared credentials.');
    $assert(str_contains($installer, 'sveevee-worker.timer sveevee-worker.service sveevee-overture.timer sveevee-overture.service'), 'Installer must refuse either active job or timer.');
    $assert(! preg_match('/^\s*systemctl\s+(?:enable|start|restart)\b/m', $installer), 'Installer may not activate either job.');
    fwrite(STDOUT, 'Job configuration: '.$assertions." assertions passed.\n");
} finally {
    putenv($previousDataDirectory === false ? 'SVEVEE_WORKER_DATA_DIR' : 'SVEVEE_WORKER_DATA_DIR='.$previousDataDirectory);
    $governmentLock = null;
    $overtureLock = null;
    $governmentDatabase = null;
    $overtureDatabase = null;
    foreach ([$directory.'/overture', $directory] as $path) {
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
