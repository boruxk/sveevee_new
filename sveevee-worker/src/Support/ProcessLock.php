<?php

declare(strict_types=1);

namespace Sveevee\Worker\Support;

use RuntimeException;

final class ProcessLock
{
    private mixed $handle = null;

    public function __construct(private readonly string $path) {}

    public function acquire(): void
    {
        $directory = dirname($this->path);
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $this->handle = fopen($this->path, 'c+');
        if ($this->handle === false || ! flock($this->handle, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another worker process is already running.');
        }
        ftruncate($this->handle, 0);
        fwrite($this->handle, (string) getmypid());
    }

    public function __destruct()
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
    }
}
