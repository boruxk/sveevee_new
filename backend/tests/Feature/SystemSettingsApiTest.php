<?php

namespace Tests\Feature;

use App\Models\Ad;
use App\Models\Page;
use App\Models\PageEvent;
use App\Models\PageProduct;
use App\Models\PageRating;
use App\Models\User;
use App\Services\BlockedTermService;
use App\Services\SystemSettingsService;
use App\Support\CatalogTopics;
use App\Support\ContentModeration;
use App\Support\PublicImageVariants;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SystemSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        app(SystemSettingsService::class)->clearCache();
        app(BlockedTermService::class)->clearCache();
    }

    public function test_admin_can_read_validate_and_update_cached_settings(): void
    {
        $settings = app(SystemSettingsService::class);
        $this->assertSame(7, $settings->integer('ads.visibility_days', 0));

        $regularUser = User::factory()->create();
        Sanctum::actingAs($regularUser);
        $this->getJson('/api/v1/admin/settings')->assertForbidden();

        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/settings')
            ->assertOk()
            ->assertJsonPath('data.settings.ads.visibility_days', 7)
            ->assertJsonPath('data.settings.chat.messages_per_minute', 30)
            ->assertJsonPath('data.catalog_topics.0.key', fn ($value) => filled($value));

        $this->patchJson('/api/v1/admin/settings/ads', [
            'visibility_days' => 0,
            'private_active_limit' => 10,
            'page_active_limit' => 30,
            'purge_after_expiry_days' => 30,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('visibility_days');

        $this->patchJson('/api/v1/admin/settings/ads', [
            'visibility_days' => 14,
            'private_active_limit' => 12,
            'page_active_limit' => 35,
            'purge_after_expiry_days' => 45,
        ])->assertOk()
            ->assertJsonPath('data.settings.visibility_days', 14);

        $this->assertSame(14, $settings->integer('ads.visibility_days', 0));
        $this->assertDatabaseHas('system_settings', [
            'key' => 'ads',
            'updated_by_user_id' => $admin->id,
        ]);
    }

    public function test_admin_blocked_terms_apply_in_every_ui_language_and_can_be_disabled(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $created = $this->postJson('/api/v1/admin/blocked-terms', [
            'term' => 'secret spam phrase',
            'locale' => 'he',
            'active' => true,
        ])->assertCreated()
            ->assertJsonPath('data.locale', 'he');

        $termId = $created->json('data.id');
        $this->assertTrue(ContentModeration::containsBlockedLanguage('An English secret spam phrase appears here.'));

        $this->putJson("/api/v1/admin/blocked-terms/{$termId}", [
            'term' => 'secret spam phrase',
            'locale' => 'he',
            'active' => false,
        ])->assertOk()
            ->assertJsonPath('data.active', false);

        $this->assertFalse(ContentModeration::containsBlockedLanguage('An English secret spam phrase appears here.'));

        $this->deleteJson("/api/v1/admin/blocked-terms/{$termId}")->assertOk();
        $this->assertDatabaseMissing('blocked_terms', ['id' => $termId]);
    }

    public function test_maintenance_blocks_visitors_and_regular_users_but_keeps_login_and_admin_accessible(): void
    {
        $settings = app(SystemSettingsService::class);
        $settings->updateSection('platform', [
            'maintenance_enabled' => true,
            'maintenance_messages' => [
                'he' => 'תחזוקה',
                'en' => 'Scheduled maintenance',
                'ru' => 'Технические работы',
                'fr' => 'Maintenance planifiee',
            ],
        ], null);

        $this->getJson('/api/v1/platform-status')
            ->assertOk()
            ->assertJsonPath('data.maintenance.enabled', true)
            ->assertJsonPath('data.maintenance.messages.en', 'Scheduled maintenance');

        $this->getJson('/api/v1/locations')
            ->assertStatus(503)
            ->assertHeader('Retry-After', '300')
            ->assertJsonPath('data.reason', 'maintenance');

        config()->set('recaptcha.enabled', true);
        $this->postJson('/api/v1/auth/register', [
            'email' => 'new@example.test',
        ])->assertStatus(503);
        config()->set('recaptcha.enabled', false);

        $user = User::factory()->create([
            'email' => 'member@example.test',
            'password' => 'password',
        ]);
        $admin = User::factory()->create(['role' => 'admin']);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->withToken($login->json('data.token'))
            ->getJson('/api/v1/home-feed')
            ->assertStatus(503);

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/settings')->assertOk();
        $this->getJson('/api/v1/locations')->assertOk();
    }

    public function test_new_ads_use_configured_lifetime_and_enforce_private_and_page_limits(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-26 10:00:00'));

        try {
            app(SystemSettingsService::class)->updateSection('ads', [
                'visibility_days' => 5,
                'private_active_limit' => 1,
                'page_active_limit' => 1,
                'purge_after_expiry_days' => 30,
            ], null);

            $owner = User::factory()->create();
            $existingExpiry = now()->addDays(20);
            $existing = Ad::query()->create([
                'user_id' => $owner->id,
                'type' => Ad::TYPE_PRIVATE,
                'title' => 'Paused existing ad',
                'text' => 'Its expiry must remain unchanged.',
                'status' => 'paused',
                'expires_at' => $existingExpiry,
            ]);
            $page = Page::query()->create([
                'user_id' => $owner->id,
                'type' => Page::TYPE_BUSINESS,
                'name' => 'Limit Test Business',
            ]);

            Sanctum::actingAs($owner);

            $firstPrivate = $this->postJson('/api/v1/ads', [
                'title' => 'First private ad',
                'text' => 'Visible for five days.',
            ])->assertCreated();

            $this->assertSame(now()->addDays(5)->toISOString(), $firstPrivate->json('data.expires_at'));

            $this->postJson('/api/v1/ads', [
                'title' => 'Second private ad',
                'text' => 'This exceeds the active limit.',
            ])->assertUnprocessable()
                ->assertJsonPath('data.reason', 'active_ad_limit')
                ->assertJsonPath('data.limit', 1);

            $this->postJson('/api/v1/ads', [
                'page_id' => $page->id,
                'title' => 'First page ad',
                'text' => 'The one allowed page ad.',
            ])->assertCreated();

            $this->postJson('/api/v1/ads', [
                'page_id' => $page->id,
                'title' => 'Second page ad',
                'text' => 'This exceeds the page limit.',
            ])->assertUnprocessable()
                ->assertJsonPath('data.reason', 'active_ad_limit');

            $this->assertTrue($existing->fresh()->expires_at->equalTo($existingExpiry));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_prune_removes_only_retained_ads_with_originals_and_variants(): void
    {
        Storage::fake('public');

        $owner = User::factory()->create();
        $oldPath = 'media/listings/old.webp';
        $recentPath = 'media/listings/recent.webp';
        Storage::disk('public')->put($oldPath, 'old');
        Storage::disk('public')->put($recentPath, 'recent');

        foreach (PublicImageVariants::variantPaths($oldPath) as $variant) {
            Storage::disk('public')->put($variant, 'variant');
        }

        $oldAd = Ad::query()->create([
            'user_id' => $owner->id,
            'type' => Ad::TYPE_PRIVATE,
            'title' => 'Old expired ad',
            'text' => 'Delete this ad and every image.',
            'image_path' => $oldPath,
            'status' => 'active',
            'expires_at' => now()->subDays(31),
        ]);
        $recentAd = Ad::query()->create([
            'user_id' => $owner->id,
            'type' => Ad::TYPE_PRIVATE,
            'title' => 'Recent expired ad',
            'text' => 'Keep this during retention.',
            'image_path' => $recentPath,
            'status' => 'active',
            'expires_at' => now()->subDays(20),
        ]);

        $this->artisan('ads:prune-expired')
            ->expectsOutput('Deleted 1 expired ads.')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('ads', ['id' => $oldAd->id]);
        $this->assertDatabaseHas('ads', ['id' => $recentAd->id]);
        $this->assertFalse(Storage::disk('public')->exists($oldPath));
        $this->assertTrue(Storage::disk('public')->exists($recentPath));

        foreach (PublicImageVariants::variantPaths($oldPath) as $variant) {
            $this->assertFalse(Storage::disk('public')->exists($variant));
        }
    }

    public function test_configured_product_and_future_event_limits_block_only_new_content(): void
    {
        app(SystemSettingsService::class)->updateSection('moderation', [
            'products_per_business_page' => 1,
            'future_events_per_community_page' => 1,
        ], null);

        $owner = User::factory()->create();
        $business = Page::query()->create([
            'user_id' => $owner->id,
            'type' => Page::TYPE_BUSINESS,
            'name' => 'Full Store',
        ]);
        $community = Page::query()->create([
            'user_id' => $owner->id,
            'type' => Page::TYPE_COMMUNITY,
            'name' => 'Full Community',
        ]);

        PageProduct::query()->create([
            'page_id' => $business->id,
            'name' => 'Existing product',
            'description' => 'Already stored.',
            'category_key' => 'products.home_garden.furniture',
            'image_path' => 'products/existing.webp',
            'price' => 10,
            'link' => 'https://example.test/existing',
        ]);
        PageEvent::query()->create([
            'page_id' => $community->id,
            'name' => 'Existing event',
            'description' => 'Already scheduled.',
            'category_key' => 'events.community_social.neighborhood_meeting',
            'image_path' => 'events/existing.webp',
            'event_date' => now()->addDays(2)->toDateString(),
            'event_time' => '18:00',
            'address' => 'Jerusalem',
        ]);

        Sanctum::actingAs($owner);

        $this->post("/api/v1/pages/{$business->id}/products", [
            'name' => 'One too many',
            'description' => 'Should be blocked by the configured limit.',
            'category_key' => 'products.home_garden.furniture',
            'image' => UploadedFile::fake()->image('product.jpg'),
            'price' => 25,
            'link' => 'https://example.test/product',
        ], ['Accept' => 'application/json'])->assertUnprocessable()
            ->assertJsonPath('data.reason', 'product_limit')
            ->assertJsonPath('data.limit', 1);

        $this->post("/api/v1/pages/{$community->id}/events", [
            'name' => 'One too many',
            'description' => 'Should be blocked by the configured limit.',
            'category_key' => 'events.community_social.neighborhood_meeting',
            'image' => UploadedFile::fake()->image('event.jpg'),
            'date' => now()->addDays(3)->toDateString(),
            'time' => '19:00',
            'address' => 'Jerusalem',
        ], ['Accept' => 'application/json'])->assertUnprocessable()
            ->assertJsonPath('data.reason', 'event_limit')
            ->assertJsonPath('data.limit', 1);

        $this->assertSame(1, $business->products()->count());
        $this->assertSame(1, $community->events()->count());
    }

    public function test_product_labels_use_current_admin_thresholds(): void
    {
        app(SystemSettingsService::class)->updateSection('labels', [
            'new_days' => 1,
            'popular_views' => 2,
            'popular_contacts' => 100,
            'highly_rated_average' => 4.5,
            'highly_rated_min_ratings' => 1,
        ], null);

        $owner = User::factory()->create();
        $reviewer = User::factory()->create();
        $page = Page::query()->create([
            'user_id' => $owner->id,
            'type' => Page::TYPE_BUSINESS,
            'name' => 'Rated Store',
        ]);
        PageRating::query()->create([
            'page_id' => $page->id,
            'user_id' => $reviewer->id,
            'rating' => 5,
        ]);
        $product = PageProduct::query()->create([
            'page_id' => $page->id,
            'name' => 'Known product',
            'description' => 'A popular product.',
            'category_key' => 'products.home_garden.furniture',
            'image_path' => 'products/known.webp',
            'price' => 20,
            'views_count' => 2,
            'contacts_count' => 0,
            'link' => 'https://example.test/known',
        ]);
        $product->forceFill([
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ])->saveQuietly();

        $response = $this->getJson("/api/v1/products/{$product->id}")->assertOk();
        $labels = $response->json('data.labels');

        $this->assertContains('popular', $labels);
        $this->assertContains('highly_rated', $labels);
        $this->assertNotContains('new', $labels);
    }

    public function test_chat_limits_and_popular_topic_order_use_current_settings(): void
    {
        $settings = app(SystemSettingsService::class);
        $settings->updateSection('chat', [
            'new_recipients_per_day' => 1,
            'messages_per_minute' => 30,
        ], null);

        $sender = User::factory()->create();
        $firstRecipient = User::factory()->create();
        $secondRecipient = User::factory()->create();
        Sanctum::actingAs($sender);

        $this->postJson("/api/v1/chats/users/{$firstRecipient->id}/messages", [
            'body' => 'Hello first recipient',
        ])->assertCreated();

        $this->postJson("/api/v1/chats/users/{$secondRecipient->id}/messages", [
            'body' => 'Hello second recipient',
        ])->assertStatus(429)
            ->assertJsonPath('errors.reason', 'daily_limit');

        $keys = [CatalogTopics::POPULAR_KEYS[2], CatalogTopics::POPULAR_KEYS[0]];
        $settings->updateSection('platform', ['popular_topic_keys' => $keys], null);

        $this->getJson('/api/v1/catalog')
            ->assertOk()
            ->assertJsonPath('data.popular_topics.0.key', $keys[0])
            ->assertJsonPath('data.popular_topics.1.key', $keys[1]);

        $settings->updateSection('platform', ['popular_topic_keys' => []], null);
        $this->getJson('/api/v1/catalog')
            ->assertOk()
            ->assertJsonCount(0, 'data.popular_topics');
    }

    public function test_message_rate_limit_uses_admin_setting(): void
    {
        app(SystemSettingsService::class)->updateSection('chat', [
            'new_recipients_per_day' => 10,
            'messages_per_minute' => 1,
        ], null);

        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        Sanctum::actingAs($sender);

        $this->postJson("/api/v1/chats/users/{$recipient->id}/messages", [
            'body' => 'First message',
        ])->assertCreated();

        $this->postJson("/api/v1/chats/users/{$recipient->id}/messages", [
            'body' => 'Second message',
        ])->assertStatus(429);
    }
}
