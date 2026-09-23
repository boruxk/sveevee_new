<?php

namespace App\Services;

use App\Models\CommunitySubscription;
use App\Models\PublicComment;
use App\Support\CatalogTopics;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class NearbyFeedService
{
    private const RANK = ['question' => 3, 'ad' => 2, 'event' => 1];

    private const CURSOR_VERSION = 3;

    public function __construct(private readonly CommunityContentService $content, private readonly FeaturedAdService $featuredAds) {}

    public function feed(Request $request): array
    {
        $data = $request->validate([
            'kind' => ['sometimes', 'in:all,question,ad,event,following'],
            'city' => ['nullable', 'string', 'max:120'], 'neighborhood' => ['nullable', 'string', 'max:120'],
            'category_key' => ['nullable', 'string', Rule::in(CatalogTopics::all()->pluck('key')->all())], 'cursor' => ['nullable', 'string', 'max:2000'],
        ]);
        $viewer = $request->user('sanctum');
        $kind = $data['kind'] ?? 'all';
        if ($kind === 'following' && ! $viewer) {
            throw new AuthenticationException;
        }
        $filters = [$kind, $data['city'] ?? '', $data['neighborhood'] ?? '', $data['category_key'] ?? '', $kind === 'following' ? $viewer?->id : null,
            in_array($kind, ['all', 'ad', 'following'], true) ? $this->featuredAds->fingerprint() : null];
        $fingerprint = hash('sha256', json_encode($filters));
        $cursor = null;
        if (! empty($data['cursor'])) {
            try {
                $cursor = json_decode(Crypt::decryptString($data['cursor']), true, 8, JSON_THROW_ON_ERROR);
                if (! is_array($cursor) || ($cursor['version'] ?? null) !== self::CURSOR_VERSION
                    || ($cursor['filter'] ?? null) !== $fingerprint || ! isset($cursor['at'], $cursor['rank'], $cursor['id'], $cursor['snapshot'])
                    || ! in_array($cursor['featured'] ?? null, [0, 1], true)
                    || ! $this->validSnapshot($cursor['snapshot'])) {
                    throw new \RuntimeException;
                }
            } catch (\Throwable) {
                throw ValidationException::withMessages(['cursor' => 'This result cursor is invalid for these filters.']);
            }
        }
        $snapshot = $cursor['snapshot'] ?? $this->snapshot();
        $subscriptions = $kind === 'following' ? CommunitySubscription::where('user_id', $viewer->id)->limit(100)->get() : collect();
        $candidates = [];
        foreach (self::RANK as $type => $rank) {
            if (! in_array($kind, ['all', 'following', $type], true)) {
                continue;
            }
            $query = $this->content->query($type);
            $table = $query->getModel()->getTable();
            $query->where($table.'.id', '<=', $snapshot[$type])->where($table.'.created_at', '<=', $snapshot['at']);
            [$activitySql, $activityBindings] = $this->activity($type, $table, $snapshot);
            [$featuredSql, $featuredBindings] = $type === 'ad' ? $this->featuredAds->expression($table) : ['0', []];
            $query->select($table.'.*')->selectRaw($activitySql.' as activity_at', $activityBindings)
                ->selectRaw($featuredSql.' as featured_active', $featuredBindings)
                ->withCasts(['activity_at' => 'datetime']);
            if ($type === 'event') {
                $query->whereDate('event_date', '>=', today());
            }
            $this->location($query, $type, $data['city'] ?? null, $data['neighborhood'] ?? null);
            if (! empty($data['category_key'])) {
                $query->whereIn($type === 'ad' ? 'category' : 'category_key', $type === 'ad' ? CatalogTopics::adCategoriesForTopic($data['category_key']) : CatalogTopics::keysForTopic($data['category_key']));
            }
            if ($kind === 'following') {
                if ($subscriptions->isEmpty()) {
                    continue;
                }
                $query->where(function ($any) use ($subscriptions, $type) {
                    foreach ($subscriptions as $subscription) {
                        if ($subscription->page_id && $type === 'question') {
                            continue;
                        }
                        $any->orWhere(function ($matches) use ($subscription, $type) {
                            if ($subscription->page_id) {
                                $matches->where('page_id', $subscription->page_id);
                            } else {
                                $matches->whereIn($type === 'ad' ? 'category' : 'category_key', $type === 'ad' ? CatalogTopics::adCategoriesForTopic($subscription->category_key) : CatalogTopics::keysForTopic($subscription->category_key));
                                $this->location($matches, $type, $subscription->city, $subscription->neighborhood);
                            }
                        });
                    }
                    // Keep question queries empty if all subscriptions are page-only.
                    if ($type === 'question' && $subscriptions->every(fn ($s) => $s->page_id !== null)) {
                        $any->whereRaw('1 = 0');
                    }
                });
            }
            if ($cursor) {
                $this->afterPosition($query, $cursor, $rank, $table, $activitySql, $activityBindings, $featuredSql, $featuredBindings);
            }
            // At most 21 rows per kind are hydrated, never the complete catalogue.
            $query->with('user.profile');
            if ($type !== 'question') {
                $query->with(['page.user.profile']);
            }
            foreach ($query->when($type === 'ad', fn (Builder $query) => $query->orderByDesc('featured_active'))
                ->orderByDesc('activity_at')->orderByDesc($table.'.id')->limit(21)->get() as $model) {
                $candidates[] = ['type' => $type, 'rank' => $rank, 'model' => $model];
            }
        }
        usort($candidates, fn ($a, $b) => ((int) $b['model']->featured_active <=> (int) $a['model']->featured_active)
            ?: ($b['model']->activity_at <=> $a['model']->activity_at)
            ?: ($b['rank'] <=> $a['rank']) ?: ($b['model']->id <=> $a['model']->id));
        $hasMore = count($candidates) > 20;
        $selected = array_slice($candidates, 0, 20);
        $states = $this->content->stateMap(array_map(fn ($item) => [$item['type'], $item['model']->id], $selected), $viewer);
        $items = array_map(fn ($item) => [
            'type' => $item['type'], 'id' => $item['model']->id, 'created_at' => $item['model']->created_at?->toISOString(),
            'activity_at' => $item['model']->activity_at?->toISOString(),
            'value' => $this->content->value($item['type'], $item['model'], $viewer, $states[$item['type'].':'.$item['model']->id]),
            'social' => $states[$item['type'].':'.$item['model']->id],
        ], $selected);
        $last = end($selected);
        $next = $hasMore && $last ? Crypt::encryptString(json_encode([
            'version' => self::CURSOR_VERSION, 'snapshot' => $snapshot,
            'featured' => (int) $last['model']->featured_active,
            'at' => $last['model']->activity_at->format('Y-m-d H:i:s'), 'rank' => $last['rank'], 'id' => $last['model']->id, 'filter' => $fingerprint,
        ])) : null;

        return ['items' => $items, 'next_cursor' => $next, 'has_more' => $hasMore];
    }

    private function afterPosition(Builder $query, array $cursor, int $rank, string $table, string $activitySql, array $activityBindings, string $featuredSql, array $featuredBindings): void
    {
        $query->where(function ($priority) use ($cursor, $rank, $table, $activitySql, $activityBindings, $featuredSql, $featuredBindings) {
            $priority->whereRaw($featuredSql.' < ?', [...$featuredBindings, $cursor['featured']])
                ->orWhere(function ($samePriority) use ($cursor, $rank, $table, $activitySql, $activityBindings, $featuredSql, $featuredBindings) {
                    $samePriority->whereRaw($featuredSql.' = ?', [...$featuredBindings, $cursor['featured']])
                        ->where(function ($position) use ($cursor, $rank, $table, $activitySql, $activityBindings) {
                            $position->whereRaw($activitySql.' < ?', [...$activityBindings, $cursor['at']])
                                ->orWhere(function ($same) use ($cursor, $rank, $table, $activitySql, $activityBindings) {
                                    $same->whereRaw($activitySql.' = ?', [...$activityBindings, $cursor['at']]);
                                    if ($rank === (int) $cursor['rank']) {
                                        $same->where($table.'.id', '<', (int) $cursor['id']);
                                    } elseif ($rank > (int) $cursor['rank']) {
                                        $same->whereRaw('1 = 0');
                                    }
                                });
                        });
                });
        });
    }

    private function snapshot(): array
    {
        // Freeze insertion boundaries, including replies created in the same second.
        // Later pages keep their ordering; a new feed request picks up new activity.
        $query = DB::query();
        foreach (['question' => 'local_questions', 'ad' => 'ads', 'event' => 'page_events', 'comment' => 'public_comments'] as $type => $table) {
            $query->selectSub(DB::table($table)->selectRaw('coalesce(max(id), 0)'), $type);
        }

        return ['at' => now()->format('Y-m-d H:i:s'), ...array_map('intval', (array) $query->first())];
    }

    private function validSnapshot(mixed $snapshot): bool
    {
        if (! is_array($snapshot) || ! is_string($snapshot['at'] ?? null)) {
            return false;
        }
        foreach (['question', 'ad', 'event', 'comment'] as $type) {
            if (! isset($snapshot[$type]) || ! is_int($snapshot[$type]) || $snapshot[$type] < 0) {
                return false;
            }
        }

        return true;
    }

    private function activity(string $type, string $table, array $snapshot): array
    {
        if ($type !== 'question') {
            return [$table.'.created_at', []];
        }
        // The existing target/id index bounds each lookup to this discussion.
        // Reuse public visibility so removed answers, banned authors and replies
        // under hidden parents never promote a question. Edits/likes do not count.
        $latestReply = PublicComment::query()->visible()->where('target_type', 'question')
            ->whereColumn('target_id', $table.'.id')->where('public_comments.id', '<=', $snapshot['comment'])
            ->whereColumn('public_comments.created_at', '>=', $table.'.created_at')
            ->where('public_comments.created_at', '<=', $snapshot['at'])->selectRaw('max(public_comments.created_at)');

        return ['coalesce(('.$latestReply->toSql().'), '.$table.'.created_at)', $latestReply->getBindings()];
    }

    private function location(Builder $query, string $type, ?string $city, ?string $neighborhood): void
    {
        if ($type === 'ad') {
            $query->inLocation($city, $neighborhood);
        } elseif ($type === 'event') {
            $query->inOwnerLocation($city, $neighborhood);
        } else {
            $query->when($city, fn ($q) => $q->where('city', $city))
                ->when($neighborhood, fn ($q) => $q->where('neighborhood', $neighborhood));
        }
    }
}
