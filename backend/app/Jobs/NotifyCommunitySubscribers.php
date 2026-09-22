<?php

namespace App\Jobs;

use App\Models\CommunitySubscription;
use App\Models\PageEvent;
use App\Services\CommunityContentService;
use App\Support\CatalogTopics;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifyCommunitySubscribers implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public string $type, public int $targetId) {}

    public function handle(CommunityContentService $content): void
    {
        try {
            $target = $content->resolve($this->type, $this->targetId);
        } catch (ModelNotFoundException) {
            return;
        }
        if ($target instanceof PageEvent && $target->event_date?->lt(today())) {
            return;
        }
        $context = $content->context($this->type, $target);
        $ownerId = $content->ownerId($target);
        $topicKeys = CatalogTopics::all()->filter(fn ($topic) => in_array($context['category_key'], [$topic['key'], ...($topic['aliases'] ?? [])], true))->pluck('key')->all();
        CommunitySubscription::query()->where('notifications_enabled', true)->where('user_id', '!=', $ownerId)
            ->where('created_at', '<=', $target->created_at)
            ->where(function ($query) use ($context, $topicKeys) {
                if ($context['page_id']) {
                    $query->where('page_id', $context['page_id']);
                }
                $query->orWhere(function ($area) use ($context, $topicKeys) {
                    $area->whereNull('page_id')->whereIn('category_key', $topicKeys)
                        ->where('city', $context['city'])->where(fn ($q) => $q->whereNull('neighborhood')->orWhere('neighborhood', $context['neighborhood']));
                });
            })->with('user')->chunkById(100, function ($subscriptions) use ($content, $target) {
                foreach ($subscriptions as $subscription) {
                    // Re-check opt-out after queue delays; duplicate matching subscriptions share one receipt.
                    if (! $subscription->user || $subscription->user->banned_at) {
                        continue;
                    }
                    $content->notifyOnce($subscription->user, 'community_activity', 'activity:'.$this->type.':'.$this->targetId, [
                        'actor_name' => $target->user?->display_name ?? $target->page?->name ?? '',
                        'target_type' => $this->type, 'target_id' => $target->id,
                        'title' => $target->title ?? $target->name, 'action_path' => $content->path($this->type, $target),
                    ]);
                }
            });
    }
}
