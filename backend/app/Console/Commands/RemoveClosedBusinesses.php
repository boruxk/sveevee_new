<?php

namespace App\Console\Commands;

use App\Services\ClosedBusinessService;
use Illuminate\Console\Command;
use Throwable;

final class RemoveClosedBusinesses extends Command
{
    protected $signature = 'business-import:remove-closed
        {file : Local JSONL file containing explicit Foursquare closure records}
        {--snapshot= : Immutable Foursquare snapshot identifier}
        {--release= : Dataset release date YYYY-MM-DD}
        {--apply : Remove eligible unclaimed pages; otherwise preview only}
        {--limit=100 : Maximum records processed, between 1 and 1000}
        {--after=0 : Resume after this many input lines}';

    protected $description = 'Remove closed businesses using exact source identities; dry-run by default';

    public function handle(ClosedBusinessService $closures): int
    {
        $handle = null;
        try {
            $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
            $after = filter_var($this->option('after'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($limit === false || $after === false || ! is_file($this->argument('file')) || ! is_readable($this->argument('file'))) {
                throw new \RuntimeException('Use a readable local JSONL file, limit 1..1000 and non-negative line cursor.');
            }
            $handle = fopen($this->argument('file'), 'rb');
            $line = 0;
            $processed = 0;
            $counts = [];
            $batch = [];
            $flush = function () use ($closures, &$batch, &$counts, &$processed): void {
                if ($batch === []) {
                    return;
                }
                $result = $closures->process('artisan:business-import:remove-closed', [
                    'snapshot_id' => $this->option('snapshot'), 'release' => $this->option('release'),
                    'dry_run' => ! $this->option('apply'), 'businesses' => $batch,
                ]);
                foreach ($result['counts'] as $status => $count) {
                    $counts[$status] = ($counts[$status] ?? 0) + $count;
                }
                $processed += count($batch);
                $batch = [];
            };
            while ($processed + count($batch) < $limit && ($text = fgets($handle, 65537)) !== false) {
                $line++;
                if (strlen($text) >= 65536 && ! str_ends_with($text, "\n")) {
                    throw new \RuntimeException('Input line exceeds 64 KiB.');
                }
                if ($line <= $after || trim($text) === '') {
                    continue;
                }
                $batch[] = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
                if (count($batch) === ClosedBusinessService::MAX_BATCH) {
                    $flush();
                }
            }
            $flush();
            $this->line(json_encode([
                'dry_run' => ! $this->option('apply'), 'processed' => $processed,
                'next_after' => $line, 'eof' => feof($handle), 'counts' => $counts,
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }
}
