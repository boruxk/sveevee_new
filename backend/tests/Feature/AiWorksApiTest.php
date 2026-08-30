<?php

namespace Tests\Feature;

use App\Models\Ad;
use App\Models\ChatMessage;
use App\Models\Page;
use App\Models\PageClaimRequest;
use App\Models\PageConversation;
use App\Models\PageEvent;
use App\Models\PageProduct;
use App\Models\PageRating;
use App\Models\PageService;
use App\Models\User;
use App\Services\AiWorkPageService;
use App\Support\CatalogTopics;
use App\Support\PublicImageVariants;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
            'text' => 'Create a concise informational page using lawful, publicly available business information.',
        ])->assertCreated()
            ->assertJsonPath('data.title', 'Research a local business');

        $taskId = $created->json('data.id');
        $this->putJson("/api/v1/ai-works/tasks/{$taskId}", [
            'title' => 'Research and verify a local business',
            'text' => 'Create a concise informational page and verify the business details before saving.',
        ])->assertOk()
            ->assertJsonPath('data.title', 'Research and verify a local business');

        $this->getJson('/api/v1/ai-works/tasks')
            ->assertOk()
            ->assertJsonCount(1, 'data.tasks');

        $this->getJson('/api/v1/chats')->assertForbidden();

        $this->deleteJson("/api/v1/ai-works/tasks/{$taskId}")->assertOk();
        $this->assertDatabaseMissing('ai_work_tasks', ['id' => $taskId]);
    }

    public function test_dedicated_ai_login_bypasses_recaptcha_but_rejects_regular_users(): void
    {
        config()->set('recaptcha.enabled', true);
        config()->set('recaptcha.secret_key', 'test-secret');

        $worker = $this->aiWorker();
        $worker->forceFill(['password' => 'AiWorker123'])->save();

        $this->postJson('/api/v1/auth/srvfrvrvv53Ljjug5h2h9zbdw', [
            'email' => $worker->login,
            'password' => 'AiWorker123',
        ])->assertOk()
            ->assertJsonPath('data.user.role', 'ai_worker')
            ->assertJsonPath('message', 'AI Works login successful.');

        $regularUser = User::factory()->create(['password' => 'Regular123']);
        $this->postJson('/api/v1/auth/srvfrvrvv53Ljjug5h2h9zbdw', [
            'email' => $regularUser->email,
            'password' => 'Regular123',
        ])->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'The login or password is incorrect.');

        $this->postJson('/api/v1/auth/login', [
            'email' => $worker->login,
            'password' => 'AiWorker123',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.recaptcha.0', 'Missing reCAPTCHA token.');
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
            ->assertJsonPath('data.banner_url', null);

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

    public function test_only_ai_page_form_saves_bypass_recaptcha(): void
    {
        config()->set('recaptcha.enabled', true);
        config()->set('recaptcha.secret_key', 'test-secret');

        $worker = $this->aiWorker();
        Sanctum::actingAs($worker);

        $created = $this->postJson('/api/v1/ai-works/pages', $this->pagePayload())
            ->assertCreated();

        $pageId = $created->json('data.id');
        $updatedPayload = $this->pagePayload();
        $updatedPayload['name'] = 'Updated Unclaimed Business';

        $this->putJson("/api/v1/ai-works/pages/{$pageId}", $updatedPayload)
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Unclaimed Business');

        $this->postJson('/api/v1/ai-works/tasks', [
            'title' => 'Protected mutation',
            'text' => 'This mutation must still require reCAPTCHA.',
        ])->assertStatus(422)
            ->assertJsonPath('errors.recaptcha.0', 'Missing reCAPTCHA token.');

        $this->deleteJson("/api/v1/ai-works/pages/{$pageId}")
            ->assertStatus(422)
            ->assertJsonPath('errors.recaptcha.0', 'Missing reCAPTCHA token.');
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
        $secondPayload['name'] = 'Second Unclaimed Business';
        $secondPayload['address']['neighborhood'] = null;

        $this->postJson('/api/v1/ai-works/pages', $secondPayload)
            ->assertCreated()
            ->assertJsonPath('data.address_details.neighborhood', null);

        $this->assertDatabaseCount('pages', 2);
    }

    public function test_ai_worker_exports_filtered_pages_as_json_and_saves_enriched_data(): void
    {
        $worker = $this->aiWorker();
        Sanctum::actingAs($worker);

        $firstPayload = $this->pagePayload();
        $firstPayload['name'] = 'Bulk Enrichment First Business';
        $firstPayload['phone'] = null;
        $firstPayload['address']['city'] = 'Tel Aviv';
        $firstPayload['address']['neighborhood'] = 'Ramat Aviv';
        $firstId = $this->postJson('/api/v1/ai-works/pages', $firstPayload)
            ->assertCreated()
            ->json('data.id');

        $secondPayload = $this->pagePayload();
        $secondPayload['name'] = 'Bulk Enrichment Second Business';
        $secondPayload['phone'] = null;
        $secondPayload['address']['city'] = 'Tel Aviv';
        $secondPayload['address']['neighborhood'] = 'Ramat Aviv';
        $secondId = $this->postJson('/api/v1/ai-works/pages', $secondPayload)
            ->assertCreated()
            ->json('data.id');

        $outsidePayload = $this->pagePayload();
        $outsidePayload['name'] = 'Outside Bulk Enrichment Business';
        $outsideId = $this->postJson('/api/v1/ai-works/pages', $outsidePayload)
            ->assertCreated()
            ->json('data.id');

        $export = $this->getJson('/api/v1/ai-works/pages/bulk-edit?'.http_build_query([
            'city' => 'Tel Aviv',
            'neighborhood' => 'Ramat Aviv',
            'category_key' => $firstPayload['category_key'],
            'id_from' => $firstId,
            'id_to' => $secondId,
        ]))->assertOk()
            ->assertJsonPath('data.matched_count', 2)
            ->assertJsonPath('data.returned_count', 2)
            ->assertJsonPath('data.truncated', false)
            ->assertJsonPath('data.pages.0.id', $firstId)
            ->assertJsonPath('data.pages.1.id', $secondId)
            ->assertJsonMissingPath('data.pages.0.palette_key');

        $rows = $export->json('data.pages');
        $rows[0]['phone'] = '03-555-0101';
        $rows[0]['whatsapp'] = '97235550101';
        $rows[1]['phone'] = '03-555-0102';

        $this->patchJson('/api/v1/ai-works/pages/bulk-edit', ['pages' => $rows])
            ->assertOk()
            ->assertJsonPath('data.updated_count', 2)
            ->assertJsonPath('data.pages.0.phone', '03-555-0101')
            ->assertJsonPath('data.pages.1.phone', '03-555-0102');

        $this->assertDatabaseHas('pages', ['id' => $firstId, 'phone' => '03-555-0101']);
        $this->assertDatabaseHas('pages', ['id' => $secondId, 'phone' => '03-555-0102']);
        $this->assertSame('97235550101', data_get(Page::query()->findOrFail($firstId)->setup, 'contact.whatsapp'));
        $this->assertDatabaseHas('pages', ['id' => $outsideId, 'phone' => '02-555-0100']);
    }

    public function test_ai_worker_json_edit_is_atomic_and_rejects_claimed_or_invalid_pages(): void
    {
        $worker = $this->aiWorker();
        Sanctum::actingAs($worker);

        $firstPayload = $this->pagePayload();
        $firstPayload['name'] = 'Atomic Bulk First Business';
        $firstId = $this->postJson('/api/v1/ai-works/pages', $firstPayload)
            ->assertCreated()
            ->json('data.id');

        $secondPayload = $this->pagePayload();
        $secondPayload['name'] = 'Atomic Bulk Second Business';
        $secondId = $this->postJson('/api/v1/ai-works/pages', $secondPayload)
            ->assertCreated()
            ->json('data.id');

        $rows = $this->getJson('/api/v1/ai-works/pages/bulk-edit?'.http_build_query([
            'id_from' => $firstId,
            'id_to' => $secondId,
        ]))->assertOk()->json('data.pages');

        $invalidRows = $rows;
        $invalidRows[0]['phone'] = '02-555-9991';
        $invalidRows[1]['contact_email'] = 'not-an-email';

        $this->patchJson('/api/v1/ai-works/pages/bulk-edit', ['pages' => $invalidRows])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pages.1.contact_email');

        $this->assertDatabaseHas('pages', ['id' => $firstId, 'phone' => '02-555-0100']);

        Page::query()->findOrFail($secondId)->forceFill([
            'user_id' => User::factory()->create()->id,
            'is_unclaimed' => false,
            'claimed_at' => now(),
        ])->save();

        $rows[0]['phone'] = '02-555-9991';
        $this->patchJson('/api/v1/ai-works/pages/bulk-edit', ['pages' => $rows])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pages');

        $this->assertDatabaseHas('pages', ['id' => $firstId, 'phone' => '02-555-0100']);
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

    public function test_admin_permanently_deletes_a_page_with_content_chats_and_media_variants(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $visitor = User::factory()->create();
        $page = Page::query()->create([
            'user_id' => $owner->id,
            'type' => Page::TYPE_BUSINESS,
            'name' => 'Delete Complete Business',
            'category_key' => 'shopping_retail.sales_special_offers',
            'logo_path' => 'pages/logos/delete-logo.webp',
            'banner_path' => 'pages/banners/delete-banner.webp',
            'setup' => ['address' => ['city' => 'Jerusalem', 'neighborhood' => 'Ramot']],
        ]);
        $ad = Ad::query()->create([
            'user_id' => $owner->id,
            'page_id' => $page->id,
            'type' => Ad::TYPE_BUSINESS,
            'title' => 'Delete page ad',
            'text' => 'This ad belongs to the deleted page.',
            'image_path' => 'media/listings/delete-ad.webp',
            'status' => 'active',
        ]);
        $product = PageProduct::query()->create([
            'page_id' => $page->id,
            'name' => 'Delete product',
            'description' => 'Delete product description.',
            'category_key' => 'products.software.games',
            'image_path' => 'products/delete-product.webp',
            'price' => 20,
            'link' => 'https://example.test/delete-product',
        ]);
        $service = PageService::query()->create([
            'page_id' => $page->id,
            'name' => 'Delete service',
            'description' => 'Delete service description.',
            'category_key' => 'services.home_repairs.handyman',
            'image_path' => 'services/delete-service.webp',
        ]);
        $event = PageEvent::query()->create([
            'page_id' => $page->id,
            'name' => 'Delete event',
            'description' => 'Delete event description.',
            'category_key' => 'events.community_social.community_festival',
            'image_path' => 'events/delete-event.webp',
            'event_date' => now()->addWeek()->toDateString(),
            'event_time' => '18:00',
            'address' => 'Jerusalem',
        ]);
        $rating = PageRating::query()->create([
            'page_id' => $page->id,
            'user_id' => $visitor->id,
            'rating' => 5,
            'comment' => 'This rating must be removed.',
        ]);
        $conversation = PageConversation::query()->create([
            'page_id' => $page->id,
            'visitor_id' => $visitor->id,
        ]);

        $originalPaths = [
            $page->logo_path,
            $page->banner_path,
            $ad->image_path,
            $product->image_path,
            $service->image_path,
            $event->image_path,
        ];
        $allPaths = collect($originalPaths)->flatMap(fn (string $path): array => [
            $path,
            ...PublicImageVariants::variantPaths($path),
        ])->unique()->values();
        $allPaths->each(fn (string $path) => Storage::disk('public')->put($path, 'image'));

        $admin = User::query()->where('email', config('sveevee.support_admin_email'))->firstOrFail();
        Sanctum::actingAs($admin);
        $this->deleteJson("/api/v1/admin/pages/{$page->id}")->assertOk();

        $this->assertDatabaseMissing('pages', ['id' => $page->id]);
        $this->assertDatabaseMissing('ads', ['id' => $ad->id]);
        $this->assertDatabaseMissing('page_products', ['id' => $product->id]);
        $this->assertDatabaseMissing('page_services', ['id' => $service->id]);
        $this->assertDatabaseMissing('page_events', ['id' => $event->id]);
        $this->assertDatabaseMissing('page_ratings', ['id' => $rating->id]);
        $this->assertDatabaseMissing('page_conversations', ['id' => $conversation->id]);
        $allPaths->each(fn (string $path) => Storage::disk('public')->assertMissing($path));
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

    public function test_ai_worker_preferences_are_persisted_and_normalized(): void
    {
        Sanctum::actingAs($this->aiWorker());

        $this->getJson('/api/v1/ai-works/preferences')
            ->assertOk()
            ->assertJsonPath('data.page_defaults.type', Page::TYPE_BUSINESS)
            ->assertJsonPath('data.page_defaults.city', '');

        $this->patchJson('/api/v1/ai-works/preferences', [
            'page_defaults' => [
                'type' => Page::TYPE_BUSINESS,
                'city' => 'tel-aviv',
                'neighborhood' => 'ramat-aviv',
                'category_key' => 'Electricians',
                'palette_key' => 'amber-dawn',
            ],
        ])->assertOk()
            ->assertJsonPath('data.page_defaults.city', 'Tel Aviv')
            ->assertJsonPath('data.page_defaults.neighborhood', 'Ramat Aviv')
            ->assertJsonPath('data.page_defaults.category_key', 'professionals.electricians');

        $this->assertDatabaseHas('ai_work_preferences', ['user_id' => $this->aiWorker()->id]);
    }

    public function test_bulk_import_never_fills_required_row_fields_from_saved_preferences(): void
    {
        $worker = $this->aiWorker();
        Sanctum::actingAs($worker);

        $this->patchJson('/api/v1/ai-works/preferences', [
            'page_defaults' => [
                'type' => Page::TYPE_BUSINESS,
                'city' => 'Tel Aviv',
                'neighborhood' => 'Ramat Aviv',
                'category_key' => 'professionals.electricians',
                'palette_key' => 'amber-dawn',
            ],
        ])->assertOk();

        $this->postJson('/api/v1/ai-works/page-imports', [
            'client_import_id' => '1ec946a1-aa8b-4103-b0c7-aaf403bb1e8c',
            'rows' => [[
                'name' => 'Missing Required Bulk Fields',
                'public_description' => 'This row must not inherit bulk defaults.',
            ]],
        ])->assertCreated()
            ->assertJsonPath('data.created_count', 0)
            ->assertJsonPath('data.invalid_count', 1)
            ->assertJsonPath('data.skipped.0.reason', 'invalid')
            ->assertJsonStructure([
                'data' => [
                    'skipped' => [[
                        'fields' => ['type', 'category_key', 'address.city'],
                    ]],
                ],
            ]);

        $this->assertDatabaseMissing('pages', ['name' => 'Missing Required Bulk Fields']);
        $this->assertDatabaseCount('ai_page_import_rows', 0);
    }

    public function test_bulk_import_creates_valid_rows_and_does_not_store_rejected_payloads(): void
    {
        $worker = $this->aiWorker();
        Sanctum::actingAs($worker);
        $existingPayload = $this->pagePayload();
        $this->postJson('/api/v1/ai-works/pages', $existingPayload)->assertCreated();

        $valid = $this->pagePayload();
        $valid['name'] = 'Bulk Created Electrician';
        $valid['category_key'] = 'Electricians';
        $valid['address']['city'] = 'tel-aviv';
        $valid['address']['neighborhood'] = 'ramat-aviv';
        unset($valid['palette_key']);

        $invalid = $this->pagePayload();
        $invalid['name'] = 'Never Persist This Invalid Company';
        $invalid['address']['city'] = 'Definitely Not A Real City';

        $payload = [
            'client_import_id' => 'f56e9ce4-811f-4e17-8c62-e672d9e56b48',
            'rows' => [$existingPayload, $valid, $invalid],
        ];

        $this->postJson('/api/v1/ai-works/page-imports', $payload)
            ->assertCreated()
            ->assertJsonPath('data.input_count', 3)
            ->assertJsonPath('data.created_count', 1)
            ->assertJsonPath('data.duplicate_count', 1)
            ->assertJsonPath('data.invalid_count', 1)
            ->assertJsonPath('data.created_pages.0.name', 'Bulk Created Electrician')
            ->assertJsonCount(2, 'data.skipped');

        $this->assertDatabaseCount('pages', 2);
        $this->assertDatabaseCount('ai_page_import_rows', 1);
        $this->assertDatabaseMissing('pages', ['name' => 'Never Persist This Invalid Company']);
        $this->assertContains(
            Page::query()->where('name', 'Bulk Created Electrician')->value('palette_key'),
            AiWorkPageService::PALETTE_KEYS,
        );
        $this->assertStringNotContainsString(
            'Never Persist This Invalid Company',
            (string) DB::table('ai_page_import_rows')->value('payload')
        );

        $this->postJson('/api/v1/ai-works/page-imports', $payload)
            ->assertOk()
            ->assertJsonPath('data.created_count', 1);
        $this->assertDatabaseCount('pages', 2);
        $this->assertDatabaseCount('ai_page_imports', 1);
    }

    public function test_duplicate_detection_normalizes_unicode_punctuation_and_spacing(): void
    {
        Sanctum::actingAs($this->aiWorker());
        $first = $this->pagePayload();
        $first['name'] = 'A.B   Repairs';
        $this->postJson('/api/v1/ai-works/pages', $first)->assertCreated();

        $duplicate = $first;
        $duplicate['name'] = 'A B Repairs';
        $duplicate['phone'] = '(02) 555 0100';

        $this->postJson('/api/v1/ai-works/pages', $duplicate)
            ->assertStatus(409)
            ->assertJsonPath('data.matches.0.name', 'A.B   Repairs');
    }

    public function test_ai_page_list_is_paginated_and_details_are_loaded_separately(): void
    {
        Sanctum::actingAs($this->aiWorker());
        $pageId = $this->postJson('/api/v1/ai-works/pages', $this->pagePayload())
            ->assertCreated()
            ->json('data.id');

        $this->getJson('/api/v1/ai-works/pages?per_page=10')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonMissingPath('data.pages.0.opening_hours');

        $this->getJson("/api/v1/ai-works/pages/{$pageId}")
            ->assertOk()
            ->assertJsonPath('data.id', $pageId)
            ->assertJsonStructure(['data' => ['opening_hours', 'socials', 'contact']]);
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
            'name' => $type === Page::TYPE_COMMUNITY ? 'Sample Unclaimed Community' : 'Sample Unclaimed Business',
            'public_description' => 'A basic informational description for an unclaimed page.',
            'contact_email' => 'contact@example.com',
            'phone' => '02-555-0100',
            'website' => 'example.com',
            'category_key' => CatalogTopics::keysForScope($scope)[0],
            'palette_key' => 'amber-dawn',
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
