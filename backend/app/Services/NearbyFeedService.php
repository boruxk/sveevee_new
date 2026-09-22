<?php

namespace App\Services;

use App\Models\CommunitySubscription;
use App\Support\CatalogTopics;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class NearbyFeedService
{
    private const RANK = ['question' => 3, 'ad' => 2, 'event' => 1];

    public function __construct(private readonly CommunityContentService $content) {}

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
        $filters = [$kind, $data['city'] ?? '', $data['neighborhood'] ?? '', $data['category_key'] ?? '', $kind === 'following' ? $viewer?->id : null];
        $fingerprint = hash('sha256', json_encode($filters));
        $cursor = null;
        if (! empty($data['cursor'])) {
            try {
                $cursor = json_decode(Crypt::decryptString($data['cursor']), true, 8, JSON_THROW_ON_ERROR);
                if (! is_array($cursor) || ($cursor['filter'] ?? null) !== $fingerprint || ! isset($cursor['at'], $cursor['rank'], $cursor['id'])) {
                    throw new \RuntimeException;
                }
            } catch (\Throwable) {
                throw ValidationException::withMessages(['cursor' => 'This result cursor is invalid for these filters.']);
            }
        }
        $subscriptions = $kind === 'following' ? CommunitySubscription::where('user_id', $viewer->id)->limit(100)->get() : collect();
        $candidates = [];
        foreach (self::RANK as $type => $rank) {
            if (! in_array($kind, ['all', 'following', $type], true)) {
                continue;
            }
            $query = $this->content->query($type);
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
                $query->where(function ($q) use ($cursor, $rank) {
                    $q->where('created_at', '<', $cursor['at'])->orWhere(function ($same) use ($cursor, $rank) {
                        $same->where('created_at', $cursor['at']);
                        if ($rank === (int) $cursor['rank']) {
                            $same->where('id', '<', (int) $cursor['id']);
                        } elseif ($rank > (int) $cursor['rank']) {
                            $same->whereRaw('1 = 0');
                        }
                    });
                });
            }
            // At most 21 rows per kind are hydrated, never the complete catalogue.
            $query->with('user.profile');
            if ($type !== 'question') {
                $query->with(['page.user.profile']);
            }
            foreach ($query->orderByDesc('created_at')->orderByDesc('id')->limit(21)->get() as $model) {
                $candidates[] = ['type' => $type, 'rank' => $rank, 'model' => $model];
            }
        }
        usort($candidates, fn ($a, $b) => ($b['model']->created_at <=> $a['model']->created_at)
            ?: ($b['rank'] <=> $a['rank']) ?: ($b['model']->id <=> $a['model']->id));
        $hasMore = count($candidates) > 20;
        $selected = array_slice($candidates, 0, 20);
        $states = $this->content->stateMap(array_map(fn ($item) => [$item['type'], $item['model']->id], $selected), $viewer);
        $items = array_map(fn ($item) => [
            'type' => $item['type'], 'id' => $item['model']->id, 'created_at' => $item['model']->created_at?->toISOString(),
            'value' => $this->content->value($item['type'], $item['model'], $viewer, $states[$item['type'].':'.$item['model']->id]),
            'social' => $states[$item['type'].':'.$item['model']->id],
        ], $selected);
        $last = end($selected);
        $next = $hasMore && $last ? Crypt::encryptString(json_encode([
            'at' => $last['model']->created_at->format('Y-m-d H:i:s'), 'rank' => $last['rank'], 'id' => $last['model']->id, 'filter' => $fingerprint,
        ])) : null;

        return ['items' => $items, 'next_cursor' => $next, 'has_more' => $hasMore];
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
