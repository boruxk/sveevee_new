<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommunityReport;
use App\Models\LocalQuestion;
use App\Models\Page;
use App\Models\PublicComment;
use App\Models\SocialLike;
use App\Models\User;
use App\Rules\CleanContent;
use App\Services\ApiResponseService;
use App\Services\CommunityContentService;
use App\Services\NearbyFeedService;
use App\Support\CatalogTopics;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CommunityController extends Controller
{
    public function __construct(private readonly CommunityContentService $content) {}

    public function feed(Request $request, NearbyFeedService $feed)
    {
        return ApiResponseService::success($feed->feed($request));
    }

    public function question(Request $request, int $id)
    {
        return ApiResponseService::success($this->content->value('question', $this->content->resolve('question', $id), $request->user('sanctum')));
    }

    public function event(Request $request, int $id)
    {
        return ApiResponseService::success($this->content->value('event', $this->content->resolve('event', $id), $request->user('sanctum')));
    }

    public function service(Request $request, int $id)
    {
        $service = $this->content->query('service')->with('page.user')->findOrFail($id);

        return ApiResponseService::success($this->content->value('service', $service, $request->user('sanctum')));
    }

    public function myQuestions(Request $request)
    {
        $viewer = $this->writer($request);
        $request->validate(['cursor' => ['nullable', 'integer', 'min:1']]);
        $rows = LocalQuestion::query()->visible()->where('user_id', $viewer->id)
            ->when($request->filled('cursor'), fn ($q) => $q->where('id', '<', $request->integer('cursor')))
            ->with('user.profile')->orderByDesc('id')->limit(21)->get();
        $hasMore = $rows->count() > 20;
        $rows = $rows->take(20);
        $states = $this->content->stateMap($rows->map(fn ($q) => ['question', $q->id])->all(), $viewer);

        return ApiResponseService::success(['items' => $rows->map(fn ($q) => $this->content->value('question', $q, $viewer, $states['question:'.$q->id]))->values(),
            'next_cursor' => $hasMore ? (string) $rows->last()->id : null, 'has_more' => $hasMore]);
    }

    public function createQuestion(Request $request)
    {
        $viewer = $this->writer($request);
        $data = $this->questionData($request);
        $question = LocalQuestion::create(['user_id' => $viewer->id, 'status' => 'open', ...$data]);

        return ApiResponseService::success($this->content->value('question', $question, $viewer), 'Question created.', 201);
    }

    public function updateQuestion(Request $request, int $id)
    {
        $viewer = $this->writer($request);
        $question = $this->content->resolve('question', $id);
        $this->owns($viewer, $question->user_id);
        $question->update($this->questionData($request, true));

        return ApiResponseService::success($this->content->value('question', $question, $viewer));
    }

    public function deleteQuestion(Request $request, int $id)
    {
        $viewer = $this->writer($request);
        $question = $this->content->resolve('question', $id);
        $this->owns($viewer, $question->user_id);
        $question->forceFill(['hidden_at' => now()])->save();

        return ApiResponseService::success(null);
    }

    public function comments(Request $request, string $type, int $id)
    {
        abort_unless(in_array($type, CommunityContentService::THREAD_TYPES, true), 404);
        $thread = $this->content->resolve($type, $id);
        $request->validate(['cursor' => ['nullable', 'integer', 'min:0']]);
        $viewer = $request->user('sanctum');
        $rows = PublicComment::query()->visible()->where('target_type', $type)->where('target_id', $id)
            ->where('id', '>', $request->integer('cursor'))
            ->with(['user.profile', 'recommendedPage.user'])->orderBy('id')->limit(31)->get();
        $more = $rows->count() > 30;
        $rows = $rows->take(30);
        $states = $this->content->stateMap($rows->map(fn ($c) => ['comment', $c->id])->all(), $viewer);

        return ApiResponseService::success(['items' => $rows->map(fn ($c) => $this->content->value('comment', $c, $viewer, $states['comment:'.$c->id], $this->content->ownerId($thread)))->values(),
            'next_cursor' => $more ? (string) $rows->last()->id : null, 'has_more' => $more]);
    }

    public function createComment(Request $request, string $type, int $id)
    {
        $viewer = $this->writer($request);
        abort_unless(in_array($type, CommunityContentService::THREAD_TYPES, true), 404);
        $data = $request->validate([
            'body' => ['bail', 'required', 'string', 'max:3000', new CleanContent],
            'parent_id' => ['nullable', 'integer', 'min:1'], 'recommended_page_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $comment = DB::transaction(function () use ($viewer, $type, $id, $data) {
            // Moderation and comment creation acquire the same target row lock.
            $target = $this->content->query($type)->lockForUpdate()->findOrFail($id);
            if (! empty($data['parent_id'])) {
                PublicComment::query()->visible()->where('target_type', $type)->where('target_id', $id)
                    ->whereNull('parent_id')->lockForUpdate()->findOrFail($data['parent_id']);
            }
            if (! empty($data['recommended_page_id'])) {
                $page = Page::query()->where('type', Page::TYPE_BUSINESS)->whereHas('user', fn ($q) => $q->whereNull('banned_at'))->findOrFail($data['recommended_page_id']);
            }
            $comment = PublicComment::create(['target_type' => $type, 'target_id' => $id, 'user_id' => $viewer->id, ...$data]);
            $this->content->notifyAuthor($type, $target, $viewer, 'community_reply', 'reply:'.$comment->id);
            if ($comment->parent_id) {
                $parent = $comment->parent;
                if ($parent->user_id !== $this->content->ownerId($target)) {
                    $this->content->notifyAuthor('comment', $parent, $viewer, 'community_reply', 'reply:'.$comment->id);
                }
            }

            return $comment;
        }, 3);

        return ApiResponseService::success($this->content->value('comment', $comment, $viewer), 'Reply created.', 201);
    }

    public function deleteComment(Request $request, int $id)
    {
        $viewer = $this->writer($request);
        $comment = $this->content->resolve('comment', $id);
        $this->owns($viewer, $comment->user_id);
        $comment->forceFill(['hidden_at' => now(), 'helpful' => false])->save();

        return ApiResponseService::success(null);
    }

    public function helpful(Request $request, int $id, int $commentId)
    {
        $viewer = $this->writer($request);
        $comment = DB::transaction(function () use ($viewer, $request, $id, $commentId) {
            $question = $this->content->resolveLocked('question', $id);
            if (! ($question->user_id === $viewer->id)) {
                throw new AuthorizationException;
            }
            $comment = $this->content->resolveLocked('comment', $commentId);
            abort_unless($comment->target_type === 'question' && $comment->target_id === $id, 404);
            if ($comment->user_id === $viewer->id) {
                throw new AuthorizationException;
            }
            $helpful = $request->isMethod('PUT');
            $comment->forceFill(['helpful' => $helpful])->save();
            if ($helpful) {
                $this->content->notifyAuthor('comment', $comment, $viewer, 'community_helpful', 'helpful:'.$comment->id);
            }

            return $comment;
        }, 3);

        return ApiResponseService::success($this->content->value('comment', $comment, $viewer));
    }

    public function social(Request $request, string $type, int $id)
    {
        $this->content->resolve($type, $id);

        return ApiResponseService::success($this->content->stateMap([[$type, $id]], $request->user('sanctum'))[$type.':'.$id]);
    }

    public function socialBatch(Request $request)
    {
        $data = $request->validate(['targets' => ['required', 'array', 'max:100'], 'targets.*' => ['required', 'string', 'regex:/\A(question|ad|event|product|service|comment):[1-9][0-9]{0,18}\z/']]);
        $wanted = array_unique($data['targets']);
        $valid = [];
        foreach (CommunityContentService::TYPES as $type) {
            $ids = array_map(fn ($key) => (int) explode(':', $key)[1], array_filter($wanted, fn ($key) => str_starts_with($key, $type.':')));
            if (! $ids) {
                continue;
            }
            $targets = $this->content->query($type)->whereIn('id', $ids)->get();
            $parents = [];
            if ($type === 'comment') {
                foreach (CommunityContentService::THREAD_TYPES as $parentType) {
                    $parentIds = $targets->where('target_type', $parentType)->pluck('target_id')->unique();
                    if ($parentIds->isNotEmpty()) {
                        foreach ($this->content->query($parentType)->whereIn('id', $parentIds)->pluck('id') as $parentId) {
                            $parents[$parentType.':'.$parentId] = true;
                        }
                    }
                }
            }
            foreach ($targets as $target) {
                if ($type === 'comment' && ! isset($parents[$target->target_type.':'.$target->target_id])) {
                    continue;
                }
                $valid[] = [$type, $target->id];
            }
        }
        $states = $this->content->stateMap($valid, $request->user('sanctum'));

        return ApiResponseService::success(['items' => array_map(fn ($item) => ['type' => $item[0], 'id' => $item[1], ...$states[$item[0].':'.$item[1]]], $valid)]);
    }

    public function like(Request $request, string $type, int $id)
    {
        $viewer = $this->writer($request);
        DB::transaction(function () use ($viewer, $request, $type, $id) {
            $target = $this->content->resolveLocked($type, $id);
            $query = SocialLike::where('user_id', $viewer->id)->where('target_type', $type)->where('target_id', $id);
            if ($request->isMethod('PUT')) {
                $created = SocialLike::query()->insertOrIgnore(['user_id' => $viewer->id, 'target_type' => $type, 'target_id' => $id, 'created_at' => now()]);
                if ($created) {
                    $this->content->notifyAuthor($type, $target, $viewer, 'community_like', 'like:'.$type.':'.$id.':'.today()->toDateString());
                }
            } else {
                $query->delete();
            }
        }, 3);

        return $this->social($request, $type, $id);
    }

    public function report(Request $request, string $type, int $id)
    {
        $viewer = $this->writer($request);
        $this->content->resolve($type, $id);
        $data = $request->validate(['reason' => ['bail', 'required', 'string', 'max:1000']]);
        $report = CommunityReport::firstOrCreate(['user_id' => $viewer->id, 'target_type' => $type, 'target_id' => $id], $data);

        return ApiResponseService::success(['id' => $report->id, 'status' => $report->status], 'Report received.', $report->wasRecentlyCreated ? 201 : 200);
    }

    public function pageOptions(Request $request)
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);
        $pages = Page::query()->where('type', Page::TYPE_BUSINESS)->whereHas('user', fn ($q) => $q->whereNull('banned_at'))
            ->where('name', 'like', '%'.addcslashes($data['q'], '%_\\').'%')->with('user')->orderBy('name')->limit(20)->get();

        return ApiResponseService::success(['items' => $pages->map(fn ($page) => $this->content->pageCard($page, $request->user('sanctum')))->values()]);
    }

    private function questionData(Request $request, bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'title' => ['bail', $presence, 'required', 'string', 'max:180', new CleanContent],
            'body' => ['bail', $presence, 'required', 'string', 'max:5000', new CleanContent],
            'city' => [$presence, 'required', 'string', 'max:120'], 'neighborhood' => ['nullable', 'string', 'max:120'],
            'category_key' => ['nullable', 'string', Rule::in(CatalogTopics::all()->pluck('key')->all())],
            'resolved' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('resolved', $data)) {
            $data['status'] = $data['resolved'] ? 'resolved' : 'open';
            unset($data['resolved']);
        }

        return $data;
    }

    private function writer(Request $request): User
    {
        $user = $request->user();
        if (! ($user && ! $user->banned_at && $user->hasAnyRole(['user', 'admin']))) {
            throw new AuthorizationException;
        }

        return $user;
    }

    private function owns(User $user, int $ownerId): void
    {
        if (! ($user->id === $ownerId || $user->hasRole('admin'))) {
            throw new AuthorizationException;
        }
    }
}
