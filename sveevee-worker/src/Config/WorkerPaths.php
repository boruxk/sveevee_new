<?php

declare(strict_types=1);

namespace Sveevee\Worker\Config;

use RuntimeException;
use Sveevee\Worker\Support\Environment;

/** Job isolation is applied after the shared environment data-directory override. */
final class WorkerPaths
{
    public static function resolve(array $storage, string $root): array
    {
        $namespace = $storage['data_subdirectory'] ?? '';
        if (! is_string($namespace) || ! in_array($namespace, ['', 'overture'], true)) {
            throw new RuntimeException('storage.data_subdirectory must be empty or overture.');
        }
        $absolute = static fn (string $path): bool => str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
        $resolve = static fn (string $path): string => $absolute($path) ? $path : rtrim($root, '/\\').DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $dataDirectory = Environment::get('SVEVEE_WORKER_DATA_DIR');
        $database = $dataDirectory !== null
            ? rtrim($dataDirectory, '/\\').'/worker.sqlite'
            : $resolve((string) ($storage['database'] ?? 'var/worker.sqlite'));
        if ($namespace !== '') {
            $dataDirectory = dirname($database).'/'.$namespace;
        }
        if ($dataDirectory !== null) {
            $dataDirectory = rtrim($dataDirectory, '/\\');

            return [
                'database' => $dataDirectory.'/worker.sqlite', 'reports' => $dataDirectory.'/reports',
                'log' => $dataDirectory.'/logs/worker.log', 'lock' => $dataDirectory.'/worker.lock',
            ];
        }

        return [
            'database' => $database, 'reports' => $resolve((string) ($storage['reports_dir'] ?? 'var/reports')),
            'log' => $resolve((string) ($storage['log_file'] ?? 'var/logs/worker.log')),
            'lock' => dirname($database).DIRECTORY_SEPARATOR.'worker.lock',
        ];
    }
}
