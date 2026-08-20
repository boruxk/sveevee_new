<?php

declare(strict_types=1);

if ($argc < 3) {
    fwrite(STDERR, "Usage: php set-env-values.php <env-file> KEY=VALUE [KEY=VALUE ...]\n");
    exit(1);
}

$path = $argv[1];

if (! is_file($path)) {
    fwrite(STDERR, "Env file not found: {$path}\n");
    exit(1);
}

$updates = [];
for ($index = 2; $index < $argc; $index++) {
    $pair = $argv[$index];
    $separator = strpos($pair, '=');

    if ($separator === false || $separator === 0) {
        fwrite(STDERR, "Invalid env assignment: {$pair}\n");
        exit(1);
    }

    $key = substr($pair, 0, $separator);
    $value = substr($pair, $separator + 1);

    if (! preg_match('/^[A-Z0-9_]+$/', $key)) {
        fwrite(STDERR, "Invalid env key: {$key}\n");
        exit(1);
    }

    $updates[$key] = $value;
}

$contents = file_get_contents($path);
if ($contents === false) {
    fwrite(STDERR, "Could not read env file: {$path}\n");
    exit(1);
}

$hasTrailingNewline = str_ends_with($contents, "\n");
$lines = preg_split('/\r\n|\n|\r/', $contents);
if ($lines === false) {
    fwrite(STDERR, "Could not parse env file: {$path}\n");
    exit(1);
}

if ($hasTrailingNewline && end($lines) === '') {
    array_pop($lines);
}

$seen = [];
foreach ($lines as $lineIndex => $line) {
    foreach ($updates as $key => $value) {
        if (str_starts_with($line, $key . '=')) {
            $lines[$lineIndex] = $key . '=' . $value;
            $seen[$key] = true;
        }
    }
}

foreach ($updates as $key => $value) {
    if (! isset($seen[$key])) {
        $lines[] = $key . '=' . $value;
    }
}

$newContents = implode(PHP_EOL, $lines) . PHP_EOL;
if (file_put_contents($path, $newContents) === false) {
    fwrite(STDERR, "Could not write env file: {$path}\n");
    exit(1);
}

