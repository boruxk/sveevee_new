<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Sveevee\Worker\Console\Application;
use Sveevee\Worker\Support\Json;

$directory = sys_get_temp_dir().'/sveevee-osm-guard-'.bin2hex(random_bytes(8));
mkdir($directory, 0700, true);
$config = Json::decode((string) file_get_contents(dirname(__DIR__).'/config/worker.osm.json'));
$config['storage'] = ['database' => $directory.'/worker.sqlite', 'reports_dir' => $directory.'/reports', 'log_file' => $directory.'/worker.log'];
$config['sources']['osm_places']['database_path'] = $directory.'/does-not-exist.sqlite';
file_put_contents($directory.'/config.json', Json::encode($config));
file_put_contents($directory.'/.env', '');
$oldData = getenv('SVEVEE_WORKER_DATA_DIR');
putenv('SVEVEE_WORKER_DATA_DIR');
try {
    // No credentials, source snapshot or API service exists. Guard must run before all three.
    $result = (new Application($directory))->run(['worker', 'run', '--config='.$directory.'/config.json', '--env-file='.$directory.'/.env']);
    $reports = glob($directory.'/reports/*.json');
    $report = count($reports) === 1 ? Json::decode((string) file_get_contents($reports[0])) : [];
    if ($result !== 1 || ! str_contains(Json::encode($report), 'configured for preview only')) {
        throw new RuntimeException('Default OSM run was not stopped by the preview guard before source/API initialization.');
    }
    $database = new PDO('sqlite:'.$directory.'/worker.sqlite');
    if ((int) $database->query('SELECT COUNT(*) FROM run_log_outbox')->fetchColumn() !== 0) {
        throw new RuntimeException('Local OSM preview guard must not queue a future remote log write.');
    }
    $database = null;
    echo "OSM preview guard: run without --dry-run rejected before source/API initialization.\n";
} finally {
    $oldData === false ? putenv('SVEVEE_WORKER_DATA_DIR') : putenv('SVEVEE_WORKER_DATA_DIR='.$oldData);
    gc_collect_cycles();
    foreach (glob($directory.'/reports/*') as $path) { if (is_file($path)) { unlink($path); } }
    if (is_dir($directory.'/reports')) { rmdir($directory.'/reports'); }
    foreach (glob($directory.'/*') as $path) { if (is_file($path)) { unlink($path); } }
    unlink($directory.'/.env');
    rmdir($directory);
}
