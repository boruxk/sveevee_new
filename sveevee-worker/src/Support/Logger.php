<?php

declare(strict_types=1);

namespace Sveevee\Worker\Support;

use RuntimeException;

final class Logger
{
    public function __construct(private readonly string $path)
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create log directory: {$directory}");
        }
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        $record = [
            'timestamp' => Clock::now(),
            'level' => $level,
            'message' => $message,
            'context' => $this->redact($context),
        ];
        file_put_contents($this->path, Json::encode($record).PHP_EOL, FILE_APPEND | LOCK_EX);

        $line = sprintf('[%s] %s', strtoupper($level), $message);
        if ($context !== []) {
            $line .= ' '.Json::encode($this->redact($context));
        }
        fwrite($level === 'error' ? STDERR : STDOUT, $line.PHP_EOL);
    }

    private function redact(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match('/secret|token|authorization|password|credential/i', $key)) {
            return '[REDACTED]';
        }
        if (! is_array($value)) {
            return $value;
        }

        $result = [];
        foreach ($value as $itemKey => $item) {
            $result[$itemKey] = $this->redact($item, (string) $itemKey);
        }

        return $result;
    }
}

