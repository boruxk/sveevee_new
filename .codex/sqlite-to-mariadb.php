<?php

declare(strict_types=1);

$sourcePath = getenv('SOURCE_SQLITE') ?: '';
$destDsn = getenv('DEST_DSN') ?: '';
$destUser = getenv('DEST_USER') ?: '';
$destPass = getenv('DEST_PASS') ?: '';

if ($sourcePath === '' || $destDsn === '' || $destUser === '') {
    fwrite(STDERR, "Required env vars: SOURCE_SQLITE, DEST_DSN, DEST_USER, DEST_PASS\n");
    exit(1);
}

if (! is_file($sourcePath)) {
    fwrite(STDERR, "SQLite source not found: {$sourcePath}\n");
    exit(1);
}

$source = new PDO('sqlite:' . $sourcePath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$dest = new PDO($destDsn, $destUser, $destPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$quoteIdentifier = static function (string $identifier): string {
    return '`' . str_replace('`', '``', $identifier) . '`';
};

$tables = $source
    ->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
    ->fetchAll(PDO::FETCH_COLUMN);

$dest->beginTransaction();

try {
    $dest->exec('SET FOREIGN_KEY_CHECKS=0');

    foreach ($tables as $table) {
        $dest->exec('DELETE FROM ' . $quoteIdentifier($table));
    }

    foreach ($tables as $table) {
        $columns = $source
            ->query('PRAGMA table_info(' . $quoteIdentifier($table) . ')')
            ->fetchAll();

        $columnNames = array_map(static fn (array $column): string => $column['name'], $columns);

        if ($columnNames === []) {
            continue;
        }

        $quotedColumns = array_map($quoteIdentifier, $columnNames);
        $placeholders = array_map(static fn (string $column): string => ':' . $column, $columnNames);
        $insert = $dest->prepare(
            'INSERT INTO ' . $quoteIdentifier($table)
            . ' (' . implode(', ', $quotedColumns) . ') VALUES ('
            . implode(', ', $placeholders) . ')'
        );

        $rows = $source->query('SELECT * FROM ' . $quoteIdentifier($table));
        $count = 0;

        while ($row = $rows->fetch()) {
            $params = [];
            foreach ($columnNames as $columnName) {
                $params[':' . $columnName] = $row[$columnName];
            }

            $insert->execute($params);
            $count++;
        }

        fwrite(STDOUT, "{$table}: {$count}\n");
    }

    $dest->exec('SET FOREIGN_KEY_CHECKS=1');
    $dest->commit();
} catch (Throwable $exception) {
    $dest->rollBack();

    try {
        $dest->exec('SET FOREIGN_KEY_CHECKS=1');
    } catch (Throwable) {
        // Keep the original failure visible.
    }

    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
