<?php

namespace Tests\Feature;

use App\Models\Ad;
use App\Models\Page;
use App\Models\User;
use App\Services\PayloadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminUserPageLoadingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_user_lists_load_only_the_first_page_of_each_type_and_keep_search_and_private_fields(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$user, $businessIds, $communityId] = $this->userWithManyPages();
        Sanctum::actingAs($admin);
        $retrieved = 0;
        Page::retrieved(function () use (&$retrieved): void {
            $retrieved++;
        });

        foreach ([0 => 'data.0', 1 => 'data.items.0'] as $paginated => $path) {
            $retrieved = 0;
            $response = $this->getJson('/api/v1/admin/users?'.http_build_query([
                'paginated' => $paginated, 'per_page' => 50, 'page' => 1, 'q' => $user->email,
            ]))->assertOk()
                ->assertJsonPath($path.'.id', $user->id)
                ->assertJsonPath($path.'.email', $user->email)
                ->assertJsonPath($path.'.created_at', $user->created_at->toISOString())
                ->assertJsonPath($path.'.role', 'ai_worker')
                ->assertJsonPath($path.'.role_names', ['ai_worker'])
                ->assertJsonPath($path.'.locale', 'he')
                ->assertJsonPath($path.'.business_page.id', $businessIds[0])
                ->assertJsonPath($path.'.community_page.id', $communityId)
                ->assertJsonPath($path.'.business_page.user_id', null);
            $this->assertSame(2, $retrieved, 'A user summary loaded additional imported pages.');
            $this->assertArrayNotHasKey('password', $response->json($path));
            if ($paginated) {
                $response->assertJsonCount(1, 'data.items')->assertJsonPath('data.pagination.total', 1)
                    ->assertJsonPath('data.total_users', User::count());
            } else {
                $response->assertJsonCount(1, 'data');
            }
        }
        $retrieved = 0;
        $this->getJson('/api/v1/admin/users?paginated=1&q=missing-user-fixture')
            ->assertOk()->assertJsonCount(0, 'data.items')->assertJsonPath('data.pagination.total', 0);
        $this->assertSame(0, $retrieved);
    }

    public function test_admin_user_detail_pages_are_bounded_and_ordered_independently_of_first_page_summaries(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$user, $businessIds, $communityId] = $this->userWithManyPages();
        Sanctum::actingAs($admin);
        $allIds = [...$businessIds, $communityId];
        $retrieved = 0;
        Page::retrieved(function () use (&$retrieved): void {
            $retrieved++;
        });

        foreach ([
            [[], 1, 25],
            [['pages_page' => 2], 2, 25],
            [['pages_page' => 25], 25, 25],
            [['pages_page' => 2, 'per_page' => 999], 2, 50],
            [['pages_page' => 999], 999, 25],
        ] as [$query, $currentPage, $perPage]) {
            $retrieved = 0;
            $expectedIds = array_slice($allIds, ($currentPage - 1) * $perPage, $perPage);
            $response = $this->getJson('/api/v1/admin/users/'.$user->id.'?'.http_build_query($query))->assertOk()
                ->assertJsonPath('data.login', $user->login)
                ->assertJsonPath('data.email', $user->email)
                ->assertJsonPath('data.created_at', $user->created_at->toISOString())
                ->assertJsonPath('data.business_page.id', $businessIds[0])
                ->assertJsonPath('data.community_page.id', $communityId)
                ->assertJsonPath('data.pages_pagination', [
                    'current_page' => $currentPage, 'last_page' => (int) ceil(count($allIds) / $perPage),
                    'per_page' => $perPage, 'total' => count($allIds),
                ])
                ->assertJsonCount(count($expectedIds), 'data.pages');
            $this->assertSame($expectedIds, array_column($response->json('data.pages'), 'id'));
            $this->assertSame(count($expectedIds) + 2, $retrieved, 'User details loaded pages outside this slice and its two summaries.');
        }
    }

    public function test_user_payload_stays_private_by_default_and_supports_preloaded_page_relations(): void
    {
        [$user, $businessIds, $communityId] = $this->userWithManyPages();
        $retrieved = 0;
        Page::retrieved(function () use (&$retrieved): void {
            $retrieved++;
        });
        $fresh = $user->fresh();
        $payload = app(PayloadService::class)->user($fresh);
        $this->assertSame($businessIds[0], $payload['business_page']['id']);
        $this->assertSame($communityId, $payload['community_page']['id']);
        $this->assertSame(2, $retrieved);
        $this->assertFalse($fresh->relationLoaded('pages'));
        foreach (['email', 'locale', 'has_password', 'banned_at', 'unread_messages_count', 'presence'] as $privateField) {
            $this->assertArrayNotHasKey($privateField, $payload);
        }

        $fresh->setRelation('pages', Page::whereIn('id', [$businessIds[0], $communityId])->get());
        $retrieved = 0;
        $preloaded = app(PayloadService::class)->user($fresh);
        $this->assertSame($payload, $preloaded);
        $this->assertSame(0, $retrieved, 'A supplied page relation was ignored.');
    }

    public function test_admin_access_and_ban_restore_responses_remain_protected_and_bounded(): void
    {
        [$user, $businessIds, $communityId] = $this->userWithManyPages('user');
        $this->getJson('/api/v1/admin/users')->assertUnauthorized();
        $this->getJson('/api/v1/admin/users/'.$user->id)->assertUnauthorized();
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/admin/users')->assertForbidden();
        $this->getJson('/api/v1/admin/users/'.$user->id)->assertForbidden();
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);
        $retrieved = 0;
        Page::retrieved(function () use (&$retrieved): void {
            $retrieved++;
        });
        $this->patchJson('/api/v1/admin/users/'.$user->id.'/ban', ['reason' => 'Fixture reason'])
            ->assertOk()->assertJsonPath('data.banned_at', fn ($value) => filled($value))
            ->assertJsonPath('data.business_page.id', $businessIds[0]);
        $this->assertSame(2, $retrieved);
        $retrieved = 0;
        $this->patchJson('/api/v1/admin/users/'.$user->id.'/restore')
            ->assertOk()->assertJsonPath('data.banned_at', null)
            ->assertJsonPath('data.community_page.id', $communityId);
        $this->assertSame(2, $retrieved);
        $service = User::factory()->create(['role' => 'ai_worker']);
        $this->patchJson('/api/v1/admin/users/'.$service->id.'/ban')->assertUnprocessable();
    }

    public function test_admin_detail_keeps_visible_ads_on_managed_pages(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create();
        $page = Page::create(['user_id' => $user->id, 'type' => Page::TYPE_BUSINESS, 'name' => 'Managed page']);
        $ad = Ad::create([
            'user_id' => $user->id, 'page_id' => $page->id, 'type' => Ad::TYPE_BUSINESS,
            'title' => 'Visible fixture ad', 'text' => 'Public ad content', 'status' => 'active',
        ]);
        Ad::create([
            'user_id' => $user->id, 'page_id' => $page->id, 'type' => Ad::TYPE_BUSINESS,
            'title' => 'Hidden fixture ad', 'text' => 'Inactive ad content', 'status' => 'draft',
        ]);
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/users/'.$user->id)->assertOk()
            ->assertJsonPath('data.pages.0.id', $page->id)
            ->assertJsonPath('data.pages.0.ads.0.id', $ad->id)
            ->assertJsonCount(1, 'data.pages.0.ads')
            ->assertJsonPath('data.pages_pagination.total', 1);
    }

    private function userWithManyPages(string $role = 'ai_worker'): array
    {
        $user = User::factory()->create([
            'email' => 'many-pages@example.test', 'login' => 'many-pages-importer',
            'role' => $role, 'locale' => 'he', 'created_at' => '2025-01-15 10:30:00',
        ]);
        // Insert the community page first to distinguish ordering by type from ordering by ID alone.
        $communityId = DB::table('pages')->insertGetId([
            'user_id' => $user->id, 'type' => Page::TYPE_COMMUNITY, 'name' => 'First community',
            'is_unclaimed' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (array_chunk(range(1, 600), 100) as $numbers) {
            DB::table('pages')->insert(array_map(fn ($number) => [
                'user_id' => $user->id, 'type' => Page::TYPE_BUSINESS, 'name' => 'Imported business '.$number,
                'is_unclaimed' => true, 'created_at' => now(), 'updated_at' => now(),
            ], $numbers));
        }
        $businessIds = DB::table('pages')->where('user_id', $user->id)->where('type', Page::TYPE_BUSINESS)->orderBy('id')->pluck('id')->all();

        return [$user, $businessIds, $communityId];
    }
}
