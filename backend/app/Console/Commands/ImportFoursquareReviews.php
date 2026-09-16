<?php

namespace App\Console\Commands;

use App\Models\BusinessImportMatchReview;
use App\Services\BusinessImportService;
use Illuminate\Console\Command;
use Throwable;

final class ImportFoursquareReviews extends Command
{
    protected $signature = 'business-import:import-foursquare-reviews
        {--after=0 : Resume after this review ID}
        {--through= : Last review ID in the approved cohort; defaults to the current maximum}
        {--limit=9000 : Process at most 1..9000 pending reviews}
        {--decisions= : Write verified creation receipts to a new JSON manifest file}
        {--apply : Explicitly approve creating separate businesses; otherwise read-only preview}';

    protected $description = 'Create separate businesses from explicitly approved pending Foursquare reviews';

    public function handle(BusinessImportService $imports): int
    {
        $after = 0;
        $through = null;
        $dryRun = ! $this->option('apply');
        $counts = array_fill_keys(['processed', 'would_create', 'created', 'already_associated', 'closed', 'invalid', 'skipped'], 0);
        $decisions = [];
        try {
            $after = filter_var($this->option('after'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 9000]]);
            $through = $this->option('through') === null ? (int) BusinessImportMatchReview::where('provider', 'foursquare_places')->max('id')
                : filter_var($this->option('through'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($after === false || $limit === false || $through === false) {
                throw new \InvalidArgumentException('Use non-negative review cursors and limit 1..9000.');
            }
            $path = $this->option('decisions');
            if ($path !== null && (! is_string($path) || $path === '' || file_exists($path) || ! is_dir(dirname($path)) || ! is_writable(dirname($path)))) {
                throw new \InvalidArgumentException('Choose a new decision manifest file in a writable existing directory.');
            }
            $query = BusinessImportMatchReview::where('provider', 'foursquare_places')->whereIn('status', ['pending', 'imported_separately'])
                ->where('id', '>', $after)->where('id', '<=', $through)->select('id');
            foreach ($query->lazyById(100)->take($limit) as $candidate) {
                $result = $imports->importApprovedFoursquareReview($candidate->id, $dryRun);
                $counts['processed']++;
                $counts[$result['status']]++;
                if (isset($result['decision'])) {
                    $decisions[] = $result['decision'];
                }
                $after = $candidate->id;
            }
            if ($path !== null) {
                $handle = @fopen($path, 'x');
                if ($handle === false) {
                    throw new \RuntimeException('Cannot exclusively create the decision manifest; receipts can be re-exported using the same starting cursor.');
                }
                try {
                    $json = json_encode(['version' => 1, 'provider' => 'foursquare_places', 'decisions' => $decisions], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    if (fwrite($handle, $json."\n") !== strlen($json) + 1) {
                        throw new \RuntimeException('Decision manifest write failed; replay to a new file to recover the receipts.');
                    }
                } finally {
                    fclose($handle);
                }
            }
            $remaining = BusinessImportMatchReview::where('provider', 'foursquare_places')->whereIn('status', ['pending', 'imported_separately'])
                ->where('id', '>', $after)->where('id', '<=', $through)->exists();
            $this->line(json_encode(['dry_run' => $dryRun, 'counts' => $counts, 'decisions_exported' => $path === null ? 0 : count($decisions),
                'next_after' => $after, 'through' => $through, 'eof' => ! $remaining], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->line(json_encode(['dry_run' => $dryRun, 'counts' => $counts, 'next_after' => $after,
                'through' => $through, 'eof' => false, 'error' => $error->getMessage()], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
    }
}
