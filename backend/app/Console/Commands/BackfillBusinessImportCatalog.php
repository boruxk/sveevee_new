<?php

namespace App\Console\Commands;

use App\Models\BusinessImportMatchReview;
use App\Models\BusinessImportSource;
use App\Services\BusinessImportService;
use App\Services\ImportSourceCatalogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class BackfillBusinessImportCatalog extends Command
{
    protected $signature = 'business-import:backfill-catalog
        {--provider= : overture_places or foursquare_places}
        {--scope=sources : Existing source associations or unresolved reviews}
        {--after=0 : Resume after this source/review database ID}
        {--limit=9000 : Process at most this many rows, between 1 and 9000}
        {--apply : Persist changes; otherwise read-only preview}';

    protected $description = 'Backfill unknown source labels and missing unclaimed-page details from stored metadata';

    public function handle(BusinessImportService $imports, ImportSourceCatalogService $catalog): int
    {
        try {
            $provider = $this->option('provider');
            $scope = $this->option('scope');
            $after = filter_var($this->option('after'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 9000]]);
            if (! in_array($provider, ['overture_places', 'foursquare_places'], true) || ! in_array($scope, ['sources', 'reviews'], true) || $after === false || $limit === false) {
                throw new \InvalidArgumentException('Choose an allowed provider, scope sources|reviews, non-negative cursor and limit 1..9000.');
            }
            $dryRun = ! $this->option('apply');
            $class = $scope === 'sources' ? BusinessImportSource::class : BusinessImportMatchReview::class;
            $counts = array_fill_keys(['processed', 'updated', 'unchanged', 'protected', 'conflict', 'orphaned', 'review', 'invalid', 'city_observations', 'category_observations'], 0);
            $query = $class::where('provider', $provider)->where('id', '>', $after);
            if ($scope === 'reviews') {
                $query->where('status', 'pending');
            }
            foreach ($query->lazyById(100)->take($limit) as $candidate) {
                $process = function () use ($class, $candidate, $provider, $scope, $dryRun, $imports, $catalog): array {
                    $query = $class::whereKey($candidate->id)->where('provider', $provider);
                    $row = ($dryRun ? $query : $query->lockForUpdate())->first();
                    if ($row === null) {
                        return ['status' => 'invalid'];
                    }
                    if ($scope === 'sources') {
                        if (! is_array($row->metadata)) {
                            return ['status' => 'invalid'];
                        }

                        return $imports->backfillCatalogSource($row, $dryRun);
                    }
                    $input = $row->payload;
                    $source = $input['source'] ?? null;
                    if ($row->status !== 'pending' || ! is_array($input) || ! is_array($source)
                        || ($source['provider'] ?? null) !== $provider || ($source['id'] ?? null) !== $row->source_id || ! is_array($source['metadata'] ?? null)) {
                        return ['status' => 'invalid'];
                    }

                    return ['status' => 'review', ...$catalog->aggregate($source, $input, dryRun: $dryRun)];
                };
                $result = $dryRun ? $process() : DB::transaction($process, 3);
                $counts['processed']++;
                $counts[$result['status']]++;
                $counts['city_observations'] += $result['city_observations'] ?? 0;
                $counts['category_observations'] += $result['category_observations'] ?? 0;
                $after = $candidate->id;
            }
            $remaining = $class::where('provider', $provider)->where('id', '>', $after);
            if ($scope === 'reviews') {
                $remaining->where('status', 'pending');
            }
            $this->line(json_encode(['dry_run' => $dryRun, 'provider' => $provider, 'scope' => $scope,
                'counts' => $counts, 'next_after' => $after, 'eof' => ! $remaining->exists()], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
