<?php

namespace Tests\Feature;

use App\Models\Ad;
use App\Models\ChatMessage;
use App\Models\Page;
use App\Models\PageClaimRequest;
use App\Models\PageEvent;
use App\Models\PageProduct;
use App\Models\PageService;
use App\Models\User;
use App\Support\CatalogTopics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AiWorksApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_worker_account_and_task_crud_are_isolated_from_regular_user_apis(): void
    {
        $worker = $this->aiWorker();

        $this->assertMatchesRegularExpression('/^\$2y\$\d{2}\$/', $worker->password);
        $this->assertSame('en', $worker->locale);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/ai-works/tasks')->assertForbidden();

        Sanctum::actingAs($worker);
        $created = $this->postJson('/api/v1/ai-works/tasks', [
            'title' => 'Research a local business',
            'text' => 'Use an official public source and record the source URL and check date.',
        ])->assertCreated()
            ->assertJsonPath('data.title', 'Research a local business');

        $taskId = $created->json('data.id');
        $this->putJson("/api/v1/ai-works/tasks/{$taskId}", [
            'title' => 'Research and verify a local business',
            'text' => 'Use an official public source and record the source URL and check date.',
        ])->assertOk()
            ->assertJsonPath('data.title', 'Research and verify a local business');

        $this->getJson('/api/v1/ai-works/tasks')
            ->assertOk()
            ->assertJsonCount(1, 'data.tasks');

        $this->getJson('/api/v1/chats')->assertForbidden();

        $this->deleteJson("/api/v1/ai-works/tasks/{$taskId}")->assertOk();
        $this->assertDatabaseMissing('ai_work_tasks', ['id' => $taskId]);
    }

    public function test_ai_worker_creates_information_only_unclaimed_pages(): void
    {
        $worker = $this->aiWorker();
        Sanctum::actingAs($worker);

        $created = $this->postJson('/api/v1/ai-works/pages', $this->pagePayload())
            ->assertCreated()
            ->assertJsonPath('data.is_unclaimed', true)
            ->assertJsonPath('data.user_id', null)
            ->assertJsonPath('data.owner', null)
            ->assertJsonPath('data.features.store', false)
            ->assertJsonPath('data.features.services', false)
            ->assertJsonPath('data.logo_url', null)
            ->assertJsonPath('data.banner_url', null)
            ->assertJsonPath('data.source_url', 'https://example.com/business-directory/sample-business');

        $pageId = $created->json('data.id');
        $this->assertDatabaseHas('pages', [
            'id' => $pageId,
            'user_id' => $worker->id,
            'created_by_user_id' => $worker->id,
            'is_unclaimed' => true,
        ]);

        $this->getJson("/api/v1/pages/{$pageId}")
            ->assertOk()
            ->assertJsonPath('data.user_id', null)
            ->assertJsonPath('data.rating_summary.count', 0)
            ->assertJsonCount(0, 'data.products')
            ->assertJsonCount(0, 'data.services');

        $page = Page::query()->findOrFail($pageId);
        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('/he/business/'.$page->public_slug, false);

        Sanctum::actingAs(User::factory()->create());
        $this->putJson("/api/v1/pages/{$pageId}/ratings/me", [
            'rating' => 5,
            'comment' => 'A rating must not be accepted yet.',
        ])->assertStatus(409);

        $this->getJson("/api/v1/pages/{$pageId}/chat")->assertStatus(409);
    }

    public function test_ai_worker_can_create_a_community_page_and_a_business_owner_can_claim_it(): void
    {
        $worker = $this->aiWorker();
        Sanctum::actingAs($worker);

        $created = $this->postJson(
            '/api/v1/ai-works/pages',
            $this->pagePayload(Page::TYPE_COMMUNITY)
        )->assertCreated()
            ->assertJsonPath('data.type', Page::TYPE_COMMUNITY)
            ->assertJsonPath('data.features.events', false)
            ->assertJsonPath('data.user_id', null);

        $pageId = $created->json('data.id');
        $communityPage = Page::query()->findOrFail($pageId);

        $this->assertSame('/community/'.$communityPage->public_slug, $communityPage->public_path);
        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('/he/community/'.$communityPage->public_slug, false);

        $claimant = User::factory()->create();
        Page::query()->create([
            'user_id' => $claimant->id,
            'type' => Page::TYPE_BUSINESS,
            'name' => 'Existing Business',
            'is_unclaimed' => false,
        ]);

        Sanctum::actingAs($claimant);
        $claimId = $this->postJson("/api/v1/pages/{$pageId}/claim-requests", [
            'message' => 'I coordinate this community and can verify it through its official contact details.',
        ])->assertCreated()
            ->assertJsonPath('data.page.type', Page::TYPE_COMMUNITY)
            ->json('data.id');

        $admin = User::query()->where('email', config('sveevee.support_admin_email'))->firstOrFail();
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/page-claims/{$claimId}/approve")
            ->assertOk()
            ->assertJsonPath('data.page.type', Page::TYPE_COMMUNITY);

        Sanctum::actingAs($claimant);
        $this->getJson('/api/v1/pages/community/mine')
            ->assertOk()
            ->assertJsonPath('data.id', $pageId)
            ->assertJsonPath('data.type', Page::TYPE_COMMUNITY);
    }

    public function test_ai_worker_can_create_multiple_pages_without_a_neighborhood(): void
    {
        $worker = $this->aiWorker();
        Sanctum::actingAs($worker);

        $firstPayload = $this->pagePayload();
        $firstPayload['address']['neighborhood'] = null;

        $this->postJson('/api/v1/ai-works/pages', $firstPayload)
            ->assertCreated()
            ->assertJsonPath('data.address_details.neighborhood', null);

        $secondPayload = $this->pagePayload();
        $secondPayload['name'] = 'Second Public Business';
        $secondPayload['source_url'] = 'https://example.com/business-directory/second-business';
        $secondPayload['address']['neighborhood'] = null;

        $this->postJson('/api/v1/ai-works/pages', $secondPayload)
            ->assertCreated()
            ->assertJsonPath('data.address_details.neighborhood', null);

        $this->assertDatabaseCount('pages', 2);
    }

    public function test_admin_can_list_assign_and_detach_business_and_community_pages(): void
    {
        $worker = $this->aiWorker();
        Sanctum::actingAs($worker);
        $pageId = $this->postJson(
            '/api/v1/ai-works/pages',
            $this->pagePayload(Page::TYPE_COMMUNITY)
        )->assertCreated()->json('data.id');

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/admin/pages')->assertForbidden();

        $target = User::factory()->create();
        $admin = User::query()->where('email', config('sveevee.support_admin_email'))->firstOrFail();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/pages?type=community&ownership=unclaimed')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $pageId)
            ->assertJsonPath('data.items.0.type', Page::TYPE_COMMUNITY)
            ->assertJsonPath('data.items.0.owner', null);

        $this->patchJson("/api/v1/admin/pages/{$pageId}/owner", ['user_id' => $target->id])
            ->assertOk()
            ->assertJsonPath('data.owner.id', $target->id)
            ->assertJsonPath('data.is_unclaimed', false);

        $this->assertDatabaseHas('pages', [
            'id' => $pageId,
            'user_id' => $target->id,
            'type' => Page::TYPE_COMMUNITY,
            'is_unclaimed' => false,
        ]);

        $this->patchJson("/api/v1/admin/pages/{$pageId}/owner", ['user_id' => null])
            ->assertOk()
            ->assertJsonPath('data.owner', null)
            ->assertJsonPath('data.is_unclaimed', true);

        $this->assertDatabaseHas('pages', [
            'id' => $pageId,
            'user_id' => $worker->id,
            'type' => Page::TYPE_COMMUNITY,
            'is_unclaimed' => true,
        ]);

        Sanctum::actingAs($worker);
        $this->getJson('/api/v1/ai-works/pages')
            ->assertOk()
            ->assertJsonPath('data.pages.0.id', $pageId);
    }

    public function test_detached_pages_keep_their_content_private_until_they_are_assigned_again(): void
    {
        $worker = $this->aiWorker();
        $owner = User::factory()->create();
        $business = Page::query()->create([
            'user_id' => $owner->id,
            'type' => Page::TYPE_BUSINESS,
            'name' => 'Managed Business',
            'is_unclaimed' => false,
            'logo_path' => 'pages/managed-logo.webp',
            'banner_path' => 'pages/managed-banner.webp',
            'setup' => [
                'address' => ['city' => 'Jerusalem', 'neighborhood' => 'Ramot'],
                'features' => ['store' => true, 'services' => true, 'events' => false, 'price_list' => false],
            ],
        ]);
        $community = Page::query()->create([
            'user_id' => $owner->id,
            'type' => Page::TYPE_COMMUNITY,
            'name' => 'Managed Community',
            'is_unclaimed' => false,
            'setup' => [
                'address' => ['city' => 'Jerusalem', 'neighborhood' => 'Ramot'],
                'features' => ['store' => false, 'services' => false, 'events' => true, 'price_list' => false],
            ],
        ]);
        $product = PageProduct::query()->create([
            'page_id' => $business->id,
            'name' => 'Hidden Product',
            'description' => 'Visible only while the page is managed.',
            'category_key' => 'products.electronics_computers.phones_tablets',
            'image_path' => 'products/hidden-product.webp',
            'price' => 100,
            'link' => 'https://example.test/hidden-product',
        ]);
        PageService::query()->create([
            'page_id' => $business->id,
            'name' => 'Hidden Service',
            'description' => 'Visible only while the page is managed.',
            'category_key' => 'professionals.electricians',
            'image_path' => 'services/hidden-service.webp',
        ]);
        PageEvent::query()->create([
            'page_id' => $community->id,
            'name' => 'Hidden Event',
            'description' => 'Visible only while the page is managed.',
            'category_key' => 'events.community_social.neighborhood_meeting',
            'image_path' => 'events/hidden-event.webp',
            'event_date' => now()->addWeek()->toDateString(),
            'event_time' => '18:00',
            'address' => 'Ramot, Jerusalem',
        ]);
        $ad = Ad::query()->create([
            'user_id' => $owner->id,
            'page_id' => $business->id,
            'type' => Ad::TYPE_BUSINESS,
            'title' => 'Hidden Page Ad',
            'text' => 'Visible only while the page is managed.',
            'status' => 'active',
            'expires_at' => now()->addWeek(),
            'city' => 'Jerusalem',
            'neighborhood' => 'Ramot',
        ]);

        $admin = User::query()->where('email', config('sveevee.support_admin_email'))->firstOrFail();
        Sanctum::actingAs($admin);
        $this->patchJson("/api/v1/admin/pages/{$business->id}/owner", ['user_id' => null])->assertOk();
        $this->patchJson("/api/v1/admin/pages/{$community->id}/owner", ['user_id' => null])->assertOk();

        $this->assertDatabaseHas('ads', ['id' => $ad->id, 'user_id' => $worker->id]);
        $this->getJson("/api/v1/pages/{$business->id}")
            ->assertOk()
            ->assertJsonPath('data.logo_url', null)
            ->assertJsonCount(0, 'data.products')
            ->assertJsonCount(0, 'data.services');
        $this->getJson("/api/v1/pages/{$community->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.events');
        $this->getJson('/api/v1/products/'.$product->public_slug)->assertNotFound();
        $this->getJson('/api/v1/ads/'.$ad->public_slug)->assertNotFound();

        $discovery = $this->getJson('/api/v1/search')->assertOk()->json('data');
        $this->assertNotContains($product->id, collect($discovery['products'])->pluck('id'));
        $this->assertNotContains($ad->id, collect($discovery['ads'])->pluck('id'));

        $sitemap = $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('/he/business/'.$business->public_slug, $sitemap);
        $this->assertStringContainsString('/he/community/'.$community->public_slug, $sitemap);
        $this->assertStringNotContainsString('/he/product/'.$product->public_slug, $sitemap);
        $this->assertStringNotContainsString('/ads/'.$ad->public_slug, $sitemap);

        $this->patchJson("/api/v1/admin/pages/{$business->id}/owner", ['user_id' => $owner->id])->assertOk();
        $this->patchJson("/api/v1/admin/pages/{$community->id}/owner", ['user_id' => $owner->id])->assertOk();
        $this->getJson('/api/v1/products/'.$product->public_slug)->assertOk();
        $this->getJson('/api/v1/ads/'.$ad->public_slug)->assertOk();
        $this->assertDatabaseHas('ads', ['id' => $ad->id, 'user_id' => $owner->id]);
    }

    public function test_claim_request_appears_in_support_and_admin_can_approve_it(): void
    {
        $worker = $this->aiWorker();
        Sanctum::actingAs($worker);
        $pageId = $this->postJson('/api/v1/ai-works/pages', $this->pagePayload())
            ->assertCreated()
            ->json('data.id');

        $claimant = User::factory()->create();
        Sanctum::actingAs($claimant);
        $claim = $this->postJson("/api/v1/pages/{$pageId}/claim-requests", [
            'message' => 'I own this business and can verify it through the official business email.',
        ])->assertCreated()
            ->assertJsonPath('data.status', PageClaimRequest::STATUS_PENDING)
            ->assertJsonPath('data.page.id', $pageId);

        $claimId = $claim->json('data.id');
        $request = PageClaimRequest::query()->findOrFail($claimId);
        $this->assertDatabaseHas('chat_messages', [
            'conversation_id' => $request->conversation_id,
            'sender_id' => $claimant->id,
        ]);
        $this->assertStringContainsString(
            "[PAGE CLAIM REQUEST #{$claimId}]",
            ChatMessage::query()->where('conversation_id', $request->conversation_id)->latest('id')->value('body')
        );

        $this->getJson('/api/v1/chats')
            ->assertOk()
            ->assertJsonPath('data.conversations.0.is_support', true);

        $admin = User::query()->where('email', config('sveevee.support_admin_email'))->firstOrFail();
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/support-chats')
            ->assertOk()
            ->assertJsonPath('data.conversations.0.pending_claim_count', 1)
            ->assertJsonPath('data.conversations.0.claim_requests.0.id', $claimId);

        $this->postJson("/api/v1/admin/page-claims/{$claimId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', PageClaimRequest::STATUS_APPROVED)
            ->assertJsonPath('data.page.is_unclaimed', false);

        $this->assertDatabaseHas('pages', [
            'id' => $pageId,
            'user_id' => $claimant->id,
            'created_by_user_id' => $worker->id,
            'is_unclaimed' => false,
        ]);
        $this->assertDatabaseHas('page_claim_requests', [
            'id' => $claimId,
            'status' => PageClaimRequest::STATUS_APPROVED,
            'reviewed_by_user_id' => $admin->id,
        ]);

        Sanctum::actingAs($claimant);
        $this->getJson('/api/v1/pages/business/mine')
            ->assertOk()
            ->assertJsonPath('data.id', $pageId)
            ->assertJsonPath('data.is_unclaimed', false);
    }

    public function test_admin_can_reject_a_claim_without_transferring_the_page(): void
    {
        $worker = $this->aiWorker();
        Sanctum::actingAs($worker);
        $pageId = $this->postJson('/api/v1/ai-works/pages', $this->pagePayload())
            ->assertCreated()
            ->json('data.id');

        $claimant = User::factory()->create();
        Sanctum::actingAs($claimant);
        $claimId = $this->postJson("/api/v1/pages/{$pageId}/claim-requests", [
            'message' => 'Please review my ownership request.',
        ])->assertCreated()->json('data.id');

        $admin = User::query()->where('email', config('sveevee.support_admin_email'))->firstOrFail();
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/page-claims/{$claimId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', PageClaimRequest::STATUS_CANCELLED);

        $page = Page::query()->findOrFail($pageId);
        $this->assertTrue($page->is_unclaimed);
        $this->assertSame($worker->id, $page->user_id);
    }

    private function aiWorker(): User
    {
        return User::query()->where('login', 'spfksfmbvpt')->firstOrFail();
    }

    private function pagePayload(string $type = Page::TYPE_BUSINESS): array
    {
        $scope = $type === Page::TYPE_COMMUNITY
            ? CatalogTopics::SCOPE_COMMUNITY_PAGES
            : CatalogTopics::SCOPE_BUSINESS_PAGES;

        return [
            'type' => $type,
            'name' => $type === Page::TYPE_COMMUNITY ? 'Sample Public Community' : 'Sample Public Business',
            'public_description' => 'A basic public description from an official public source.',
            'contact_email' => 'contact@example.com',
            'phone' => '02-555-0100',
            'website' => 'example.com',
            'category_key' => CatalogTopics::keysForScope($scope)[0],
            'palette_key' => 'amber-dawn',
            'source_url' => 'example.com/business-directory/sample-business',
            'source_checked_at' => now()->format('Y-m-d'),
            'address' => [
                'street' => 'Jaffa Street',
                'number' => '1',
                'city' => 'Jerusalem',
                'neighborhood' => 'City Center',
            ],
            'socials' => [],
            'opening_hours' => [],
        ];
    }
}
