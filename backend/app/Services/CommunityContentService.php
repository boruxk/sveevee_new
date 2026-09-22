<?php

namespace App\Services;

use App\Models\Ad;
use App\Models\LocalQuestion;
use App\Models\Page;
use App\Models\PageEvent;
use App\Models\PageProduct;
use App\Models\PageService;
use App\Models\PublicComment;
use App\Models\SocialLike;
use App\Models\User;
use App\Support\CatalogTopics;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CommunityContentService
{
    public const THREAD_TYPES = ['question', 'ad', 'event', 'product', 'service'];

    public const TYPES = [...self::THREAD_TYPES, 'comment'];

    public function __construct(private readonly PayloadService $payloads) {}

    public function query(string $type, bool $includeHidden = false): Builder
    {
        return match ($type) {
            'question' => $includeHidden ? LocalQuestion::query() : LocalQuestion::query()->visible(),
            'ad' => $includeHidden ? Ad::withoutGlobalScope('community_visibility') : Ad::query()->active()
                ->whereHas('user', fn ($q) => $q->whereNull('banned_at')),
            'event' => $includeHidden ? PageEvent::withoutGlobalScope('community_visibility') : PageEvent::query()
                ->publiclyVisible(),
            'product', 'service' => $this->pageItemQuery($type, $includeHidden),
            'comment' => $includeHidden ? PublicComment::query() : PublicComment::query()->visible(),
            default => throw new NotFoundHttpException,
        };
    }

    private function pageItemQuery(string $type, bool $includeHidden): Builder
    {
        $query = $type === 'product' ? PageProduct::query() : PageService::query();
        if ($includeHidden) {
            return $query->withoutGlobalScope('community_visibility');
        }

        return $query->whereHas('page', fn (Builder $page) => $page->managed()
            ->where('type', Page::TYPE_BUSINESS)->whereHas('user', fn (Builder $owner) => $owner->whereNull('banned_at')));
    }

    public function resolve(string $type, int $id, bool $includeHidden = false): Model
    {
        $target = $this->query($type, $includeHidden)->findOrFail($id);
        if ($target instanceof PublicComment && ! $includeHidden) {
            $this->resolve($target->target_type, $target->target_id);
        }

        return $target;
    }

    /** Call only inside a transaction; thread locks precede comment locks. */
    public function resolveLocked(string $type, int $id): Model
    {
        if ($type === 'comment') {
            $comment = $this->query('comment')->findOrFail($id);
            $this->query($comment->target_type)->lockForUpdate()->findOrFail($comment->target_id);
            if ($comment->parent_id) {
                $this->query('comment')->lockForUpdate()->findOrFail($comment->parent_id);
            }
        }

        return $this->query($type)->lockForUpdate()->findOrFail($id);
    }

    public function actor(?User $user): ?array
    {
        if (! $user) {
            return null;
        }
        $user->loadMissing('profile');

        return ['id' => $user->id, 'display_name' => $user->display_name, 'photo_url' => $user->profile?->photo_url];
    }

    public function pageCard(?Page $page, ?User $viewer = null): ?array
    {
        if (! $page || $page->type !== Page::TYPE_BUSINESS || ! $page->user || $page->user->banned_at) {
            return null;
        }

        return [
            'id' => $page->id, 'name' => $page->name, 'public_path' => $page->public_path,
            'logo_url' => $page->is_unclaimed ? null : $page->logo_url,
            'category_key' => $page->category_key, 'city' => data_get($page->setup, 'address.city'),
            'can_rate' => (bool) ($viewer && ! $viewer->banned_at && ! $page->is_unclaimed && $page->user_id !== $viewer->id),
        ];
    }

    public function ownerId(Model $target): ?int
    {
        return ($target instanceof PageEvent || $target instanceof PageProduct || $target instanceof PageService) && $target->page_id
            ? $target->page?->user_id : $target->user_id;
    }

    public function path(string $type, Model $target): string
    {
        if ($target instanceof PublicComment) {
            $parent = $this->resolve($target->target_type, $target->target_id, true);

            return $this->path($target->target_type, $parent).'#discussion';
        }

        return match ($type) {
            'question' => '/questions/'.$target->id, 'ad' => '/ads/'.$target->public_slug, 'event' => '/events/'.$target->id,
            'product' => '/product/'.$target->public_slug, 'service' => '/services/'.$target->id
        };
    }

    public function context(string $type, Model $target): array
    {
        $page = $target instanceof Ad || $target instanceof PageEvent || $target instanceof PageProduct || $target instanceof PageService ? $target->page : null;
        $profile = $target->user?->profile;

        return [
            'page_id' => $page?->id,
            'category_key' => $target instanceof Ad ? CatalogTopics::keyForAdCategory($target->category) : $target->category_key,
            'city' => $target->city ?? ($page ? data_get($page->setup, 'address.city') : $profile?->city),
            'neighborhood' => $target->neighborhood ?? ($page ? data_get($page->setup, 'address.neighborhood') : $profile?->neighborhood),
        ];
    }

    public function stateMap(array $targets, ?User $viewer): array
    {
        $states = [];
        foreach ($targets as [$type, $id]) {
            $states[$type.':'.$id] = ['likes_count' => 0, 'liked' => false, 'comments_count' => 0];
        }
        foreach (self::TYPES as $type) {
            $ids = array_values(array_unique(array_map(fn ($target) => (int) $target[1], array_filter($targets, fn ($target) => $target[0] === $type))));
            if (! $ids) {
                continue;
            }
            foreach (SocialLike::query()->where('target_type', $type)->whereIn('target_id', $ids)
                ->selectRaw('target_id, count(*) as total')->groupBy('target_id')->get() as $row) {
                $states[$type.':'.$row->target_id]['likes_count'] = (int) $row->total;
            }
            if ($viewer) {
                foreach (SocialLike::query()->where('target_type', $type)->whereIn('target_id', $ids)->where('user_id', $viewer->id)->pluck('target_id') as $id) {
                    $states[$type.':'.$id]['liked'] = true;
                }
            }
            $comments = PublicComment::query()->visible();
            $column = $type === 'comment' ? 'parent_id' : 'target_id';
            $comments->when($type !== 'comment', fn ($q) => $q->where('target_type', $type))->whereIn($column, $ids);
            foreach ($comments->selectRaw($column.', count(*) as total')->groupBy($column)->get() as $row) {
                $states[$type.':'.$row->{$column}]['comments_count'] = (int) $row->total;
            }
        }

        return $states;
    }

    public function value(string $type, Model $target, ?User $viewer, ?array $social = null, ?int $threadOwnerId = null): array
    {
        $social ??= $this->stateMap([[$type, $target->id]], $viewer)[$type.':'.$target->id];
        if ($target instanceof LocalQuestion) {
            return [
                'id' => $target->id, 'title' => $target->title, 'body' => $target->body,
                'category_key' => $target->category_key, 'city' => $target->city, 'neighborhood' => $target->neighborhood,
                'status' => $target->status, 'resolved' => $target->status === 'resolved',
                'author' => $this->actor($target->user), 'can_edit' => $viewer && ($target->user_id === $viewer->id || $viewer->hasRole('admin')),
                'created_at' => $target->created_at?->toISOString(), 'updated_at' => $target->updated_at?->toISOString(),
                'public_path' => $this->path($type, $target), 'social' => $social,
            ];
        }
        if ($target instanceof PublicComment) {
            return [
                'id' => $target->id, 'body' => $target->body, 'parent_id' => $target->parent_id,
                'author' => $this->actor($target->user), 'created_at' => $target->created_at?->toISOString(),
                'is_owner' => $target->user_id === ($threadOwnerId ?? $this->ownerId($this->resolve($target->target_type, $target->target_id))),
                'can_delete' => (bool) ($viewer && ($viewer->id === $target->user_id || $viewer->hasRole('admin'))),
                'helpful' => (bool) $target->helpful, 'recommended_page' => $this->pageCard($target->recommendedPage, $viewer), 'social' => $social,
            ];
        }
        if ($target instanceof PageProduct || $target instanceof PageService) {
            $page = $target->page;
            $value = $target instanceof PageProduct ? $this->payloads->product($target) : $this->payloads->service($target);

            return [...$value, 'page' => $this->itemPage($page), 'social' => $social, 'public_path' => $this->path($type, $target)];
        }
        // Keep existing card fields, without recursively serializing the owner's
        // complete page, products, events and private profile contact data.
        $author = $target->user;
        $page = $target->page;
        $context = $this->context($type, $target);
        $value = [
            'id' => $target->id, 'user_id' => $target->user_id, 'page_id' => $target->page_id,
            'user' => $author ? [...$this->actor($author), 'slug' => $author->public_slug,
                'public_path' => '/users/'.$author->public_slug, 'name' => $author->display_name,
                'profile' => ['photo_url' => $author->profile?->photo_url, 'city' => $author->profile?->city, 'neighborhood' => $author->profile?->neighborhood]] : null,
            'page' => $page && $page->user && ! $page->user->banned_at ? [
                'id' => $page->id, 'name' => $page->name, 'slug' => $page->public_slug, 'public_path' => $page->public_path,
                'type' => $page->type, 'logo_url' => $page->logo_url, 'user_id' => $page->user_id,
            ] : null,
            'image_url' => $target->image_url, 'image_name' => $target->image_original_name,
            ...$this->payloads->publicImageMeta('image', $target->image_path, $target->title ?? $target->name, '(max-width: 700px) calc(100vw - 36px), 360px'),
            'created_at' => $target->created_at?->toISOString(), 'updated_at' => $target->updated_at?->toISOString(),
        ];
        if ($target instanceof Ad) {
            $value += ['slug' => $target->public_slug, 'type' => $target->type, 'title' => $target->title, 'text' => $target->text,
                'category' => $target->category, 'status' => $target->status, 'city' => $context['city'], 'neighborhood' => $context['neighborhood'],
                'expires_at' => $target->expires_at?->toISOString()];
        } else {
            $value += ['owner_type' => $target->page_id ? 'page' : 'user', 'is_personal' => ! $target->page_id,
                'name' => $target->name, 'description' => $target->description, 'category_key' => $target->category_key,
                'date' => $target->event_date?->format('Y-m-d'), 'time' => $target->event_time ? substr($target->event_time, 0, 5) : null,
                'end_time' => $target->event_end_time ? substr($target->event_end_time, 0, 5) : null, 'address' => $target->address];
        }

        return [...$value, 'social' => $social, 'public_path' => $this->path($type, $target)];
    }

    private function itemPage(?Page $page): ?array
    {
        if (! $page || ! $page->user || $page->user->banned_at || $page->is_unclaimed) {
            return null;
        }

        return ['id' => $page->id, 'name' => $page->name, 'slug' => $page->public_slug, 'public_path' => $page->public_path,
            'type' => $page->type, 'logo_url' => $page->logo_url, 'user_id' => $page->user_id,
            'address_details' => array_intersect_key((array) data_get($page->setup, 'address', []), array_flip(['city', 'neighborhood', 'street', 'number'])),
        ];
    }

    public function notifyOnce(User $recipient, string $type, string $eventKey, array $data): void
    {
        if ($recipient->banned_at) {
            return;
        }
        DB::transaction(function () use ($recipient, $type, $eventKey, $data) {
            $inserted = DB::table('community_notification_receipts')->insertOrIgnore([
                'user_id' => $recipient->id, 'event_key' => $eventKey, 'created_at' => now(),
            ]);
            if ($inserted) {
                app(AccountNotificationService::class)->create($recipient, $type, $data);
            }
        });
    }

    public function notifyAuthor(string $type, Model $target, User $actor, string $notificationType, string $key): void
    {
        $ownerId = $this->ownerId($target);
        if (! $ownerId || $ownerId === $actor->id) {
            return;
        }
        $recipient = User::find($ownerId);
        if (! $recipient) {
            return;
        }
        $this->notifyOnce($recipient, $notificationType, $key, [
            'actor_name' => $actor->display_name, 'target_type' => $type, 'target_id' => $target->id,
            'title' => $target->title ?? $target->name ?? mb_substr($target->body ?? '', 0, 100),
            'action_path' => $this->path($type, $target),
        ]);
    }
}
