<?php

declare(strict_types=1);

namespace Sveevee\Worker\Support;

use RuntimeException;

final class Environment
{
    public static function load(?string $path): void
    {
        if ($path === null || ! is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException("Unable to read environment file: {$path}");
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            if ($name === '' || getenv($name) !== false) {
                continue;
            }

            $value = trim($value);
            if (strlen($value) >= 2) {
                $quote = $value[0];
                if (($quote === '"' || $quote === "'") && str_ends_with($value, $quote)) {
                    $value = substr($value, 1, -1);
                }
            }

            putenv($name.'='.$value);
            $_ENV[$name] = $value;
        }
    }

    public static function get(string $name, ?string $default = null): ?string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $default : $value;
    }

    public static function require(string $name): string
    {
        $value = self::get($name);
        if ($value === null) {
            throw new RuntimeException("Missing required environment variable: {$name}");
        }

        return $value;
    }
}

