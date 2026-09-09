<?php

declare(strict_types=1);

namespace Sveevee\Worker\Config;

use Closure;
use RuntimeException;
use Sveevee\Worker\Support\Json;

/** Validate every job first; roll back already published files if a later rename fails. */
final class ConfigFileTransaction
{
    public function __construct(private readonly ?Closure $publish = null) {}

    public function write(array $documents, string $root, bool $apply): array
    {
        $stages = [];
        $backups = [];
        $published = [];
        $changed = [];
        $reference = array_key_first($documents);
        try {
            foreach ($documents as $path => $document) {
                if (! is_dir(dirname($path)) || (file_exists($path) && ! is_file($path))) {
                    throw new RuntimeException('Configuration destination must be a file in an existing directory: '.$path);
                }
                $stage = tempnam(dirname($path), '.rotation-');
                if ($stage === false) {
                    throw new RuntimeException('Unable to create configuration staging file.');
                }
                $stages[$path] = $stage;
                $json = Json::encode($document, true).PHP_EOL;
                if (file_put_contents($stage, $json, LOCK_EX) === false) {
                    throw new RuntimeException('Unable to stage configuration.');
                }
                $validated = WorkerConfig::load($stage, $root);
                WorkerPaths::resolve((array) $validated->get('storage', []), $root);
                $changed[$path] = ! is_file($path) || file_get_contents($path) !== $json;
            }
            if (! $apply) {
                return ['changed' => $changed, 'backups' => []];
            }
            foreach ($stages as $path => $stage) {
                if (! $changed[$path]) {
                    continue;
                }
                $permissionsSource = is_file($path) ? $path : $reference;
                $this->permissions($stage, $permissionsSource);
                if (is_file($path)) {
                    $backup = $path.'.before-rotation-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(4));
                    if (! copy($path, $backup)) {
                        throw new RuntimeException('Unable to back up configuration: '.$path);
                    }
                    $this->permissions($backup, $path);
                    $backups[$path] = $backup;
                }
            }
            foreach ($stages as $path => $stage) {
                if (! $changed[$path]) {
                    continue;
                }
                $success = $this->publish === null ? @rename($stage, $path) : ($this->publish)($stage, $path);
                if (! $success) {
                    throw new RuntimeException('Unable to publish configuration: '.$path);
                }
                $published[] = $path;
            }

            return ['changed' => $changed, 'backups' => $backups];
        } catch (\Throwable $error) {
            $rollbackFailures = [];
            foreach (array_reverse($published) as $path) {
                if (isset($backups[$path])) {
                    $restore = false;
                    try {
                        $restore = tempnam(dirname($path), '.rotation-restore-');
                        if ($restore === false || ! copy($backups[$path], $restore)) {
                            throw new RuntimeException('Cannot stage configuration rollback.');
                        }
                        $this->permissions($restore, $backups[$path]);
                        if (! @rename($restore, $path)) {
                            throw new RuntimeException('Cannot publish configuration rollback.');
                        }
                    } catch (\Throwable) {
                        $rollbackFailures[] = $path;
                    } finally {
                        if (is_string($restore) && is_file($restore)) {
                            unlink($restore);
                        }
                    }
                } elseif (! @unlink($path)) {
                    $rollbackFailures[] = $path;
                }
            }
            if ($rollbackFailures !== []) {
                throw new RuntimeException('Configuration publish failed and rollback needs manual recovery for '.implode(', ', $rollbackFailures).'; backups: '.Json::encode($backups), 0, $error);
            }
            throw $error;
        } finally {
            foreach ($stages as $stage) {
                if (is_file($stage)) {
                    unlink($stage);
                }
            }
        }
    }

    private function permissions(string $path, string $reference): void
    {
        if (! chmod($path, fileperms($reference) & 0777)) {
            throw new RuntimeException('Unable to preserve configuration permissions.');
        }
        if (PHP_OS_FAMILY !== 'Windows' && (! chown($path, fileowner($reference)) || ! chgrp($path, filegroup($reference)))) {
            throw new RuntimeException('Unable to preserve configuration ownership.');
        }
    }
}
