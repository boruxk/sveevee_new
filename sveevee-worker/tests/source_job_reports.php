<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Sveevee\Worker\Support\Json;

$root = dirname(__DIR__);
$directory = sys_get_temp_dir().'/sveevee-source-jobs-'.bin2hex(random_bytes(8));
mkdir($directory, 0700, true);
$directory = (string) realpath($directory);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$database = null;
try {
    $emptyEnvironment = $directory.'/empty.env';
    file_put_contents($emptyEnvironment, '');
    // Only child processes receive these overrides; the caller's environment is untouched.
    $environment = getenv();
    foreach ([
        'SVEVEE_API_URL', 'SVEEVEE_BUSINESS_IMPORT_API_URL',
        'SVEVEE_TOKEN_URL', 'SVEEVEE_OAUTH_TOKEN_URL',
        'SVEVEE_CLIENT_ID', 'SVEEVEE_BUSINESS_IMPORT_CLIENT_ID',
        'SVEVEE_CLIENT_SECRET', 'SVEEVEE_BUSINESS_IMPORT_CLIENT_SECRET',
    ] as $name) {
        unset($environment[$name]);
    }
    $environment['SVEVEE_WORKER_DATA_DIR'] = $directory.'/data';
    $runIds = [];
    $databasePaths = [];
    foreach ([
        'data_gov_ckan' => 'worker.rotation.json',
        'tel_aviv_business_licenses' => 'worker.tel-aviv.json',
    ] as $source => $profile) {
        $config = Json::decode((string) file_get_contents($root.'/config/'.$profile));
        $enabled = array_keys(array_filter($config['sources'], static fn (array $settings): bool => ($settings['enabled'] ?? false) === true));
        $assert($enabled === [$source], 'The job profile must enable only its own source: '.$source);
        // Neither adapter supports this city, so successful research requires no HTTP request.
        $config['sources'][$source]['import_mode'] = 'catalog';
        $config['cities'] = ['CLI Fixture City'];
        $config['neighborhoods'] = [];
        $config['categories'] = ['food_catering.restaurants'];
        $config['sources'][$source]['min_interval_seconds'] = 0;
        $config['sources'][$source]['max_retries'] = 0;
        // Rotation is a deployment overlay; complete its required storage settings in the fixture.
        $config['storage'] = ($config['storage'] ?? []) + [
            'database' => 'var/worker.sqlite', 'reports_dir' => 'var/reports', 'log_file' => 'var/logs/worker.log',
        ];
        $configPath = $directory.'/'.$profile;
        file_put_contents($configPath, Json::encode($config));
        $namespace = $config['storage']['data_subdirectory'] ?? '';
        $jobDirectory = $environment['SVEVEE_WORKER_DATA_DIR'].($namespace === '' ? '' : '/'.$namespace);
        $databasePaths[] = $jobDirectory.'/worker.sqlite';

        foreach (['research' => 0, 'import' => 1] as $command => $expectedExit) {
            $outputPath = $directory.'/'.$source.'-'.$command.'.stdout';
            $errorPath = $directory.'/'.$source.'-'.$command.'.stderr';
            // A future regression must fail offline rather than contact a real source or API.
            $process = proc_open([
                PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'disable_functions=curl_exec',
                $root.'/bin/worker', $command, '--config='.$configPath, '--env-file='.$emptyEnvironment,
            ], [1 => ['file', $outputPath, 'w'], 2 => ['file', $errorPath, 'w']], $pipes, $root, $environment);
            if (! is_resource($process)) {
                throw new RuntimeException('Could not start the worker CLI fixture.');
            }
            $exit = proc_close($process);
            $assert($exit === $expectedExit, $source.' '.$command.' returned an unexpected exit code: '.file_get_contents($errorPath));

            $database = new PDO('sqlite:'.$jobDirectory.'/worker.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $statement = $database->prepare('SELECT report_json, report_path FROM runs WHERE command = ?');
            $statement->execute([$command]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            $assert(count($rows) === 1, 'Each CLI invocation must persist one report for '.$source.' '.$command);
            $report = Json::decode($rows[0]['report_json']);
            $assert($report === Json::decode((string) file_get_contents($rows[0]['report_path'])), 'Database and report file must agree.');
            $assert(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $report['run_id']) === 1, 'Run ID must be a v4 UUID.');
            $assert($report['used_sources'] === [$source], 'A job report lost or mixed its configured source.');
            $assert($report['source_counts'] === [$source => 0], 'Source counts must retain exactly the configured source at zero.');
            $assert($report['source_requests'] === 0 && $report['source_errors'] === 0, 'These CLI fixtures must make zero source HTTP requests.');
            $assert($report['command'] === $command && $report['status'] === ($command === 'research' ? 'completed' : 'failed'), 'Wrong command or report status.');
            if ($command === 'research') {
                $assert($report['found'] === 0 && $report['failed'] === 0 && $report['errors'] === [], 'Unsupported targets must produce a clean empty report.');
                $assert($report['empty_target_combinations'] === 1, 'The unsupported combination must be reported as empty.');
            } else {
                // This protects report-start provenance: ResearchService never runs here.
                $assert($report['target_combinations'] === 0, 'Import must fail before any target is researched.');
                $assert(count($report['errors']) === 1 && $report['errors'][0]['stage'] === 'fatal'
                    && str_contains($report['errors'][0]['message'], 'SVEVEE_API_URL'), 'Import must fail at missing API configuration, before HTTP.');
            }
            $outbox = $database->prepare('SELECT payload_json FROM run_log_outbox WHERE run_id = ?');
            $outbox->execute([$report['run_id']]);
            $assert(Json::decode((string) $outbox->fetchColumn()) === $report, 'The independently queued admin log must preserve the report.');
            $runIds[] = $report['run_id'];
            unset($statement, $outbox, $database);
        }
    }
    $assert(count(array_unique($runIds)) === 4, 'Independent jobs and invocations must never share a run UUID.');
    $assert(count(array_unique($databasePaths)) === 2, 'Government and Tel Aviv jobs must use separate databases.');
    fwrite(STDOUT, 'Source job reports: 4 CLI runs, '.$assertions." assertions passed.\n");
} finally {
    unset($statement, $outbox, $database);
    gc_collect_cycles();
    $cleanupRoot = realpath($directory);
    $expectedParent = realpath(sys_get_temp_dir());
    if ($cleanupRoot === false || dirname($cleanupRoot) !== $expectedParent || ! str_starts_with(basename($cleanupRoot), 'sveevee-source-jobs-')) {
        throw new RuntimeException('Refusing to clean an unexpected test directory.');
    }
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cleanupRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        if ($file->isDir() && ! $file->isLink()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    unset($files);
    rmdir($cleanupRoot);
}
