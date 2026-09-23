<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Select a small window of identities before loading any search card models. */
class SearchDiscoveryService
{
    private const PER_PAGE = 20;

    public function __construct(private readonly FeaturedAdService $featuredAds) {}

    public function paginate(array $scopes, ?string $city, ?string $neighborhood, int $page, ?string $cursor): array
    {
        $context = hash('sha256', json_encode([$city, $neighborhood, $this->featuredAds->fingerprint()], JSON_THROW_ON_ERROR));
        $after = $cursor !== null ? $this->decodeCursor($cursor, $context) : null;
        $page = $after['page'] ?? $page;
        $totals = [];
        foreach ($scopes as $scope => $definition) {
            $totals[$scope] = (clone $definition['query'])->toBase()->count();
        }

        $tiers = $city ? ($neighborhood ? [[$city, $neighborhood], [$city, null], [null, null]] : [[$city, null], [null, null]]) : [[null, null]];
        $candidates = $after === null && $page > 1
            ? $this->offsetCandidates($scopes, $totals, $tiers, $page)
            : $this->cursorCandidates($scopes, $totals, $tiers, $after);
        $hasMore = $candidates->count() > self::PER_PAGE;
        $batch = $candidates->take(self::PER_PAGE)->values();
        $last = $batch->last();
        $total = array_sum($totals);

        return [
            'candidates' => $batch,
            'pagination' => [
                'current_page' => $page,
                'per_page' => self::PER_PAGE,
                'total' => $total,
                'scope_totals' => $totals,
                'last_page' => max(1, (int) ceil($total / self::PER_PAGE)),
                'has_more' => $hasMore,
                'next_page' => $hasMore ? $page + 1 : null,
                'next_cursor' => $hasMore ? Crypt::encryptString(json_encode([
                    'version' => 2, 'context' => $context, 'page' => $page + 1, ...$last,
                ], JSON_THROW_ON_ERROR)) : null,
            ],
        ];
    }

    private function cursorCandidates(array $scopes, array $totals, array $tiers, ?array $after): Collection
    {
        $candidates = collect();
        foreach ($scopes as $scope => $definition) {
            if ($totals[$scope] === 0) {
                continue;
            }
            $remaining = self::PER_PAGE + 1;
            foreach ($tiers as $priority => $location) {
                if ($after !== null && $priority < $after['priority']) {
                    continue;
                }
                $kind = rtrim($scope, 's');
                $query = $this->tierQuery($definition, $tiers, $priority, $kind);
                if ($after !== null && $priority === $after['priority']) {
                    $this->afterPosition($query, $kind, $after);
                }
                $rows = $query->limit($remaining)->toBase()->get();
                foreach ($rows as $row) {
                    $candidates->push($this->candidate($row));
                }
                $remaining -= $rows->count();
                if ($remaining === 0) {
                    break;
                }
            }
        }

        return $candidates->sort($this->compare(...))->values()->take(self::PER_PAGE + 1);
    }

    /** Older clients may still request page=N; paginate their thin identities in SQL. */
    private function offsetCandidates(array $scopes, array $totals, array $tiers, int $page): Collection
    {
        $offset = ($page - 1) * self::PER_PAGE;
        if ($offset >= array_sum($totals)) {
            return collect();
        }
        $union = null;
        foreach ($scopes as $scope => $definition) {
            if ($totals[$scope] === 0) {
                continue;
            }
            foreach ($tiers as $priority => $location) {
                $tier = $this->tierQuery($definition, $tiers, $priority, rtrim($scope, 's'))
                    ->limit($offset + self::PER_PAGE + 1)->toBase();
                $part = DB::query()->fromSub($tier, 'discovery_tier')->select('*');
                $union = $union === null ? $part : $union->unionAll($part);
            }
        }

        return DB::query()->fromSub($union, 'discovery_candidates')
            ->orderBy('priority')->orderByDesc('featured')->orderByDesc('created_at')->orderByDesc('kind')->orderByDesc('id')
            ->offset($offset)->limit(self::PER_PAGE + 1)->get()
            ->map(fn ($row): array => $this->candidate($row));
    }

    private function tierQuery(array $definition, array $tiers, int $priority, string $kind): Builder
    {
        $query = (clone ($definition['candidates'] ?? $definition['query']))->withoutEagerLoads()->reorder();
        $id = $query->getModel()->getQualifiedKeyName();
        $createdAt = $query->getModel()->qualifyColumn('created_at');
        [$city, $neighborhood] = $tiers[$priority];
        if ($city !== null) {
            ($definition['location'])($query, $city, $neighborhood);
        }
        if ($priority > 0) {
            if (isset($definition['exclude_location'])) {
                // Pages can negate the location on the current row instead of
                // materializing every page in the previous tier again.
                ($definition['exclude_location'])($query, ...$tiers[$priority - 1]);
            } else {
                // The preceding tier includes all higher-priority tiers. NOT IN
                // also retains records with missing/null locations.
                $excluded = (clone $definition['query'])->withoutEagerLoads()->reorder()->select($id);
                ($definition['location'])($excluded, ...$tiers[$priority - 1]);
                $query->whereNotIn($id, $excluded->toBase());
            }
        }

        [$featuredSql, $featuredBindings] = $this->featuredPriority($kind);

        return $query->select([$id.' as id', $createdAt.' as created_at'])
            ->selectRaw('? as kind, ? as priority', [$kind, $priority])
            ->selectRaw($featuredSql.' as featured', $featuredBindings)
            ->when($kind === 'ad', fn (Builder $query) => $query->orderByDesc('featured'))
            ->orderByDesc($createdAt)->orderByDesc($id);
    }

    private function afterPosition(Builder $query, string $kind, array $after): void
    {
        $createdAt = $query->getModel()->qualifyColumn('created_at');
        $id = $query->getModel()->getQualifiedKeyName();
        if ($kind !== 'ad') {
            // A featured cursor always precedes ordinary content in this tier.
            // Keep the existing page index seek free of constant OR expressions.
            if ($after['featured'] === 0) {
                $this->afterCreation($query, $createdAt, $id, $kind, $after);
            }

            return;
        }
        [$featuredSql, $featuredBindings] = $this->featuredPriority($kind);
        $query->where(function (Builder $priority) use ($createdAt, $id, $kind, $after, $featuredSql, $featuredBindings): void {
            $priority->whereRaw($featuredSql.' < ?', [...$featuredBindings, $after['featured']])
                ->orWhere(function (Builder $samePriority) use ($createdAt, $id, $kind, $after, $featuredSql, $featuredBindings): void {
                    $samePriority->whereRaw($featuredSql.' = ?', [...$featuredBindings, $after['featured']]);
                    $this->afterCreation($samePriority, $createdAt, $id, $kind, $after);
                });
        });
    }

    private function afterCreation(Builder $query, string $createdAt, string $id, string $kind, array $after): void
    {
        $query->where(function (Builder $position) use ($createdAt, $id, $kind, $after): void {
            if ($after['created_at'] !== null) {
                $position->where($createdAt, '<', $after['created_at'])->orWhereNull($createdAt);
            } else {
                $position->whereRaw('1 = 0');
            }
            if (strcmp($kind, $after['kind']) <= 0) {
                $position->orWhere(function (Builder $tie) use ($createdAt, $id, $kind, $after): void {
                    $tie->where($createdAt, $after['created_at']);
                    if ($kind === $after['kind']) {
                        $tie->where($id, '<', $after['id']);
                    }
                });
            }
        });
    }

    private function candidate(object $row): array
    {
        return ['id' => (int) $row->id, 'created_at' => $row->created_at, 'kind' => $row->kind,
            'priority' => (int) $row->priority, 'featured' => (int) $row->featured];
    }

    private function compare(array $left, array $right): int
    {
        return ($left['priority'] <=> $right['priority'])
            ?: ($right['featured'] <=> $left['featured'])
            ?: strcmp($right['created_at'] ?? '', $left['created_at'] ?? '')
            ?: strcmp($right['kind'], $left['kind'])
            ?: ($right['id'] <=> $left['id']);
    }

    private function decodeCursor(string $cursor, string $context): array
    {
        try {
            if (strlen($cursor) > 4096) {
                throw new \UnexpectedValueException;
            }
            $position = json_decode(Crypt::decryptString($cursor), true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($position) || ($position['version'] ?? null) !== 2 || ($position['context'] ?? null) !== $context
                || ! is_int($position['page'] ?? null) || $position['page'] < 2
                || ! is_int($position['id'] ?? null) || $position['id'] < 1
                || ! in_array($position['kind'] ?? null, ['page', 'product', 'service', 'event', 'ad'], true)
                || ! is_int($position['priority'] ?? null) || $position['priority'] < 0 || $position['priority'] > 2
                || ! in_array($position['featured'] ?? null, [0, 1], true)
                || ! array_key_exists('created_at', $position)
                || ($position['created_at'] !== null && (! is_string($position['created_at']) || strlen($position['created_at']) > 32))) {
                throw new \UnexpectedValueException;
            }

            return $position;
        } catch (\Throwable) {
            throw ValidationException::withMessages(['cursor' => ['The search position is invalid. Start the search again.']]);
        }
    }

    private function featuredPriority(string $kind): array
    {
        return $kind === 'ad' ? $this->featuredAds->expression() : ['0', []];
    }
}
