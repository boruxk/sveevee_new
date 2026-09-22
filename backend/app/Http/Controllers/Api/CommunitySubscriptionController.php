<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommunitySubscription;
use App\Models\Page;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\CommunityContentService;
use App\Support\CatalogTopics;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CommunitySubscriptionController extends Controller
{
    public function __construct(private readonly CommunityContentService $content) {}

    public function index(Request $request)
    {
        $viewer = $this->writer($request);
        $items = CommunitySubscription::where('user_id', $viewer->id)->with('page.user')->latest('id')->limit(100)->get();

        return ApiResponseService::success(['items' => $items->map(fn ($s) => $this->payload($s, $viewer))->values()]);
    }

    public function status(Request $request)
    {
        $data = $this->scope($request);
        $viewer = $request->user('sanctum');
        $subscription = $viewer ? CommunitySubscription::where('user_id', $viewer->id)->where('scope_key', $data['scope_key'])->with('page.user')->first() : null;

        return ApiResponseService::success(['subscribed' => (bool) $subscription, 'subscription' => $subscription ? $this->payload($subscription, $viewer) : null]);
    }

    public function store(Request $request)
    {
        $viewer = $this->writer($request);
        $data = $this->scope($request);
        $preferences = $request->validate(['notifications_enabled' => ['sometimes', 'boolean']]);
        $subscription = DB::transaction(function () use ($viewer, $data, $preferences) {
            User::query()->whereKey($viewer->id)->lockForUpdate()->firstOrFail();
            $existing = CommunitySubscription::where('user_id', $viewer->id)->where('scope_key', $data['scope_key'])->first();
            if ($existing) {
                return $existing;
            }
            if (CommunitySubscription::where('user_id', $viewer->id)->count() >= 100) {
                throw ValidationException::withMessages(['subscriptions' => 'You can follow up to 100 pages or local topics.']);
            }

            return CommunitySubscription::create(['user_id' => $viewer->id, 'notifications_enabled' => true, ...$data, ...$preferences]);
        }, 3);

        return ApiResponseService::success($this->payload($subscription, $viewer), 'Subscription saved.', $subscription->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $request, int $id)
    {
        $viewer = $this->writer($request);
        $subscription = CommunitySubscription::where('user_id', $viewer->id)->findOrFail($id);
        $subscription->update($request->validate(['notifications_enabled' => ['required', 'boolean']]));

        return ApiResponseService::success($this->payload($subscription, $viewer));
    }

    public function destroy(Request $request, int $id)
    {
        $viewer = $this->writer($request);
        CommunitySubscription::where('user_id', $viewer->id)->findOrFail($id)->delete();

        return ApiResponseService::success(null);
    }

    private function scope(Request $request): array
    {
        $data = $request->validate([
            'page_id' => ['nullable', 'integer', 'min:1', 'required_without:category_key'],
            'category_key' => ['nullable', 'string', 'required_without:page_id', Rule::in(CatalogTopics::all()->pluck('key')->all())],
            'city' => ['nullable', 'string', 'max:120', 'required_without:page_id'],
            'neighborhood' => ['nullable', 'string', 'max:120'],
        ]);
        if (! empty($data['page_id'])) {
            if (! empty($data['category_key']) || ! empty($data['city']) || ! empty($data['neighborhood'])) {
                throw ValidationException::withMessages(['page_id' => 'Choose a page or a local topic.']);
            }
            Page::query()->whereHas('user', fn ($q) => $q->whereNull('banned_at'))->findOrFail($data['page_id']);
            $data = ['page_id' => (int) $data['page_id']];
        } else {
            $data = ['category_key' => $data['category_key'], 'city' => trim($data['city']), 'neighborhood' => filled($data['neighborhood'] ?? null) ? trim($data['neighborhood']) : null];
        }

        return [...$data, 'scope_key' => hash('sha256', json_encode($data))];
    }

    private function payload(CommunitySubscription $subscription, ?User $viewer): array
    {
        $page = $subscription->page;

        return [
            'id' => $subscription->id, 'type' => $subscription->page_id ? 'page' : 'area', 'page_id' => $subscription->page_id,
            'page' => $page && $page->user && ! $page->user->banned_at ? ['id' => $page->id, 'name' => $page->name, 'public_path' => $page->public_path, 'type' => $page->type] : null,
            'category_key' => $subscription->category_key, 'city' => $subscription->city, 'neighborhood' => $subscription->neighborhood,
            'notifications_enabled' => (bool) $subscription->notifications_enabled, 'created_at' => $subscription->created_at?->toISOString(),
        ];
    }

    private function writer(Request $request): User
    {
        $user = $request->user();
        if (! ($user && ! $user->banned_at && $user->hasAnyRole(['user', 'admin']))) {
            throw new AuthorizationException;
        }

        return $user;
    }
}
