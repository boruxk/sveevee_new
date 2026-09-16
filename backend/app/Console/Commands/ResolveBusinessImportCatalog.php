<?php

namespace App\Console\Commands;

use App\Models\BusinessImportCategory;
use App\Models\BusinessImportCity;
use App\Services\ImportSourceCatalogService;
use Illuminate\Console\Command;
use Throwable;

final class ResolveBusinessImportCatalog extends Command
{
    protected $signature = 'business-import:resolve-catalog
        {--kind= : cities or categories}
        {--provider= : Optionally restrict to one import provider}
        {--after=0 : Resume after this staging-row ID}
        {--limit=9000 : Inspect at most 1..9000 rows}
        {--apply : Record approved mappings; otherwise preview only}';

    protected $description = 'Record reviewed catalog mappings without changing source labels or business pages';

    public function handle(ImportSourceCatalogService $catalog): int
    {
        try {
            $kind = $this->option('kind');
            $provider = $this->option('provider');
            $after = filter_var($this->option('after'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 9000]]);
            if (! in_array($kind, ['cities', 'categories'], true) || $after === false || $limit === false
                || ($provider !== null && ! in_array($provider, ['overture_places', 'foursquare_places', 'data_gov_ckan', 'tel_aviv_business_licenses', 'osm_places'], true))) {
                throw new \InvalidArgumentException('Choose kind cities|categories, a known provider, non-negative cursor and limit 1..9000.');
            }
            $apply = (bool) $this->option('apply');
            $class = $kind === 'cities' ? BusinessImportCity::class : BusinessImportCategory::class;
            $column = $kind === 'cities' ? 'mapped_city' : 'mapped_category_key';
            $counts = array_fill_keys(['processed', 'would_map', 'mapped', 'unchanged', 'unresolved', 'conflict'], 0);
            $query = $class::where('id', '>', $after)->when($provider, fn ($query) => $query->where('provider', $provider));
            foreach ($query->lazyById(100)->take($limit) as $row) {
                $counts['processed']++;
                $target = $kind === 'cities' ? $catalog->knownCity($row->raw_value) : $catalog->knownCategory($row->provider, $row->raw_value);
                if ($target === null) {
                    $counts['unresolved']++;
                } elseif ($row->{$column} === $target) {
                    $counts['unchanged']++;
                } elseif ($row->{$column} !== null) {
                    $counts['conflict']++;
                } else {
                    $counts['would_map']++;
                    if ($apply) {
                        $changed = $class::whereKey($row->id)->whereNull($column)->update([$column => $target]);
                        $counts[$changed === 1 ? 'mapped' : 'conflict']++;
                    }
                }
                $after = $row->id;
            }
            $remaining = $class::where('id', '>', $after)->when($provider, fn ($query) => $query->where('provider', $provider))->exists();
            $this->line(json_encode(['dry_run' => ! $apply, 'kind' => $kind, 'provider' => $provider,
                'counts' => $counts, 'next_after' => $after, 'eof' => ! $remaining], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
