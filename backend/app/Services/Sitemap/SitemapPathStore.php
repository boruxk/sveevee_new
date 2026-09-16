<?php

namespace App\Services\Sitemap;

use Generator;
use Illuminate\Support\Carbon;
use PDO;
use PDOStatement;

/** Scratch storage keeps deduplication of catalog/location URLs out of PHP memory. */
final class SitemapPathStore
{
    private PDO $database;

    private PDOStatement $upsert;

    public function __construct(string $path)
    {
        $this->database = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->database->exec('PRAGMA journal_mode = OFF');
        $this->database->exec('PRAGMA synchronous = OFF');
        $this->database->exec('PRAGMA cache_size = -4096');
        $this->database->exec('PRAGMA temp_store = FILE');
        $this->database->exec('CREATE TABLE paths (family TEXT NOT NULL, path TEXT NOT NULL, lastmod TEXT, PRIMARY KEY (family, path)) WITHOUT ROWID');
        $this->upsert = $this->database->prepare('INSERT INTO paths (family, path, lastmod) VALUES (?, ?, ?) ON CONFLICT (family, path) DO UPDATE SET lastmod = CASE WHEN excluded.lastmod IS NULL THEN paths.lastmod WHEN paths.lastmod IS NULL THEN excluded.lastmod ELSE MAX(paths.lastmod, excluded.lastmod) END');
    }

    public function register(string $family, string $path, ?Carbon $lastModified): void
    {
        if (! $this->database->inTransaction()) {
            $this->database->beginTransaction();
        }
        $this->upsert->execute([$family, $path, $lastModified?->toDateString()]);
    }

    public function entries(string $family): Generator
    {
        if ($this->database->inTransaction()) {
            $this->database->commit();
        }
        $query = $this->database->prepare('SELECT path, lastmod FROM paths WHERE family = ? ORDER BY path');
        $query->execute([$family]);
        while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
            yield $row;
        }
        $query->closeCursor();
    }
}
