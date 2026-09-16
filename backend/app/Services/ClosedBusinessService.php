<?php

namespace App\Services;

use App\Exceptions\BusinessImportException;
use App\Models\BusinessImportClosure;
use App\Models\BusinessImportClosureEvent;
use App\Models\BusinessImportSource;
use App\Models\BusinessImportSourceAlias;
use App\Models\Page;
use App\Models\PageClaimRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

final class ClosedBusinessService
{
    public const PROVIDER = 'foursquare_places';

    public const MAX_BATCH = 100;

    public function __construct(
        private readonly PageDeletionService $deletions,
        private readonly PageIdentityService $identities,
    ) {}

    /** Only explicit, dated source closure records can authorize a removal. */
    public function process(string $actor, array $input): array
    {
        $data = Validator::make($input, [
            'snapshot_id' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D'],
            'release' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'dry_run' => ['sometimes', 'boolean'],
            'businesses' => ['required', 'array', 'min:1', 'max:'.self::MAX_BATCH],
            'businesses.*' => ['required', 'array:source_id,date_closed,country,address'],
            'businesses.*.source_id' => ['required', 'string', 'distinct:strict', 'regex:/^[a-f0-9]{24}$/D'],
            'businesses.*.date_closed' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:release'],
            'businesses.*.country' => ['required', 'in:IL'],
            'businesses.*.address' => ['sometimes', 'array:city,street,number,neighborhood'],
            'businesses.*.address.city' => ['nullable', 'string', 'max:120'],
            'businesses.*.address.street' => ['nullable', 'string', 'max:300'],
            'businesses.*.address.number' => ['nullable', 'string', 'max:40'],
            'businesses.*.address.neighborhood' => ['nullable', 'string', 'max:120'],
        ])->validate();
        $dryRun = (bool) ($data['dry_run'] ?? true);
        $items = [];
        $counts = array_fill_keys(['would_remove', 'removed', 'already_removed', 'protected_claimed', 'review_required', 'unmatched'], 0);
        foreach ($data['businesses'] as $record) {
            if ($dryRun) {
                $item = $this->inspect($record);
            } else {
                $key = 'business-import-source:'.hash('sha256', self::PROVIDER.'|'.$record['source_id']);
                $item = Cache::lock($key, 60)->block(10, fn (): array => DB::transaction(
                    fn (): array => $this->apply($actor, $data['snapshot_id'], $data['release'], $record), 3
                ));
            }
            $items[] = $item;
            $counts[$item['status']]++;
        }

        return ['dry_run' => $dryRun, 'snapshot_id' => $data['snapshot_id'], 'counts' => $counts, 'items' => $items];
    }

    /** Call after source validation, and again inside the import transaction before writes. */
    public function assertSourceOpen(array $source): void
    {
        $identifiers = [[$source['provider'], $source['id']]];
        if ($source['provider'] === 'overture_places') {
            foreach (FoursquareImportMatchingService::aliasIds($source['metadata'] ?? []) as $id) {
                $identifiers[] = [self::PROVIDER, $id];
            }
            foreach (FoursquareImportMatchingService::osmAliasIds($source['metadata'] ?? []) as $id) {
                $identifiers[] = ['osm_places', $id];
            }
        }
        $query = DB::table('business_import_source_tombstones')->where(function ($query) use ($identifiers): void {
            foreach ($identifiers as [$provider, $id]) {
                $query->orWhere(fn ($query) => $query->where('provider', $provider)->where('source_id', $id));
            }
        });
        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }
        if ($query->first(['id']) !== null) {
            throw new BusinessImportException('This source identifies a business reported as closed.', 409, 'source_closed');
        }
    }

    private function inspect(array $record, bool $lock = false): array
    {
        $closed = BusinessImportClosure::where('provider', self::PROVIDER)->where('source_id', $record['source_id'])->first();
        if ($closed?->removed_at !== null) {
            return $this->result($record, 'already_removed', $closed->removed_page_id);
        }
        $directQuery = BusinessImportSource::where('provider', self::PROVIDER)->where('source_id', $record['source_id']);
        $aliasQuery = BusinessImportSourceAlias::where('provider', self::PROVIDER)->where('source_id', $record['source_id'])
            ->with(['association' => fn ($query) => $lock ? $query->lockForUpdate() : $query])->limit(101);
        $direct = ($lock ? $directQuery->lockForUpdate() : $directQuery)->first();
        $aliases = ($lock ? $aliasQuery->lockForUpdate() : $aliasQuery)->get();
        $associations = $aliases->pluck('association')->filter();
        if ($direct !== null) {
            $associations->push($direct);
        }
        $ids = $associations->pluck('page_id')->filter()->unique()->sort()->values()->all();
        if ($aliases->count() > 100 || $aliases->contains(fn ($alias): bool => $alias->association === null)
            || $associations->contains(fn ($association): bool => $association->page_id === null) || count($ids) > 1) {
            return $this->result($record, 'review_required', reason: 'ambiguous_source_identity', candidates: $ids);
        }
        if ($ids === []) {
            return $this->result($record, 'unmatched', reason: 'no_exact_source_identity');
        }
        $query = Page::whereKey($ids[0]);
        $page = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($page === null || $page->type !== Page::TYPE_BUSINESS) {
            return $this->result($record, 'review_required', reason: 'invalid_source_page', candidates: $ids);
        }
        if (! $page->is_unclaimed || $page->claimed_at !== null
            || ($page->created_by_user_id !== null && $page->created_by_user_id !== $page->user_id)
            || ! $page->user?->hasRole('ai_worker')
            || $page->claimRequests()->where('status', PageClaimRequest::STATUS_APPROVED)->exists()) {
            return $this->result($record, 'protected_claimed', $page->id, 'confirmed_or_transferred_owner');
        }
        if ($this->identities->importAddressesConflict($record['address'] ?? [], $page->setup['address'] ?? [])) {
            return $this->result($record, 'review_required', $page->id, 'source_address_conflict');
        }

        return $this->result($record, 'would_remove', $page->id);
    }

    private function apply(string $actor, string $snapshot, string $release, array $record): array
    {
        $closure = BusinessImportClosure::where('provider', self::PROVIDER)->where('source_id', $record['source_id'])->lockForUpdate()->first();
        $evidence = ['provider' => self::PROVIDER, 'release' => $release, ...$record];
        $hash = hash('sha256', json_encode($this->sortKeys($evidence), JSON_THROW_ON_ERROR));
        $previous = $closure === null ? null : $closure->events()->where('snapshot_id', $snapshot)->first();
        if ($previous !== null) {
            if (! hash_equals($previous->evidence_hash, $hash)) {
                throw new BusinessImportException('The snapshot already contains different closure evidence for this source ID.', 409, 'closure_snapshot_conflict');
            }
            $result = $previous->result;
            if ($result['status'] === 'removed') {
                $result['status'] = 'already_removed';
            }

            return [...$result, 'replayed' => true];
        }
        $result = $this->inspect($record, lock: true);
        $closure ??= new BusinessImportClosure([
            'provider' => self::PROVIDER, 'source_id' => $record['source_id'],
            'date_closed' => $record['date_closed'], 'first_seen_at' => now(),
        ]);
        $closure->fill(['status' => $result['status'], 'reason' => $result['reason'] ?? null, 'last_seen_at' => now()])->save();
        // Even unmatched closed IDs must not become new businesses on a later import.
        $this->tombstone($closure, self::PROVIDER, $record['source_id']);
        if ($result['status'] === 'would_remove') {
            $page = Page::whereKey($result['page_id'])->lockForUpdate()->firstOrFail();
            $sources = BusinessImportSource::where('page_id', $page->id)->lockForUpdate()->get();
            $provenance = [];
            foreach ($sources as $source) {
                $provenance[] = $source->getAttributes();
                $this->tombstone($closure, $source->provider, $source->source_id, $page->id);
                BusinessImportSourceAlias::where('business_import_source_id', $source->id)->orderBy('id')->chunkById(100, function ($aliases) use ($closure, $page): void {
                    foreach ($aliases as $alias) {
                        $this->tombstone($closure, $alias->provider, $alias->source_id, $page->id);
                    }
                });
            }
            $closure->fill([
                'status' => 'removed', 'removed_page_id' => $page->id, 'removed_at' => now(),
                'page_snapshot' => ['page' => $page->getAttributes(), 'sources' => $provenance],
            ])->save();
            $media = $this->deletions->deleteInCurrentTransaction($page);
            DB::afterCommit(function () use ($media): void {
                try {
                    $this->deletions->deleteMedia($media);
                } catch (Throwable $error) {
                    report($error);
                }
            });
            $result['status'] = 'removed';
        }
        BusinessImportClosureEvent::create([
            'closure_id' => $closure->id, 'snapshot_id' => $snapshot, 'release' => $release,
            'actor' => mb_substr($actor, 0, 255), 'evidence_hash' => $hash,
            'evidence' => $evidence, 'result' => $result, 'created_at' => now(),
        ]);

        return [...$result, 'replayed' => false];
    }

    private function tombstone(BusinessImportClosure $closure, string $provider, string $id, ?int $pageId = null): void
    {
        DB::table('business_import_source_tombstones')->insertOrIgnore([
            'provider' => $provider, 'source_id' => $id, 'closure_id' => $closure->id,
            'removed_page_id' => $pageId, 'created_at' => now(),
        ]);
    }

    private function result(array $record, string $status, ?int $pageId = null, ?string $reason = null, array $candidates = []): array
    {
        return [
            'source_id' => $record['source_id'], 'status' => $status,
            ...($pageId === null ? [] : ['page_id' => $pageId]),
            ...($reason === null ? [] : ['reason' => $reason]),
            ...($candidates === [] ? [] : ['candidate_page_ids' => $candidates]),
        ];
    }

    private function sortKeys(array $value): array
    {
        ksort($value);
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->sortKeys($item);
            }
        }

        return $value;
    }
}
