<?php

namespace Tests\Feature;

use App\Models\Ad;
use App\Models\EmailBan;
use App\Models\Page;
use App\Models\PageEvent;
use App\Models\PageProduct;
use App\Models\PageService;
use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use App\Support\CatalogTopics;
use App\Support\ContentModeration;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class SveeveeApiTest extends TestCase
{
    use RefreshDatabase;

    private function enableRecaptcha(): void
    {
        config()->set('recaptcha.enabled', true);
        config()->set('recaptcha.secret_key', 'test-secret');
        config()->set('recaptcha.min_score', 0.5);
    }

    private function fakeGoogleCallback(array $attributes): void
    {
        $provider = \Mockery::mock(AbstractProvider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn(SocialiteUser::fake($attributes));

        Socialite::shouldReceive('driver')
            ->with('google')
            ->andReturn($provider);
    }

    private function tokenFromGoogleRedirect($response): string
    {
        $fragment = parse_url((string) $response->headers->get('Location'), PHP_URL_FRAGMENT) ?: '';
        parse_str($fragment, $data);

        return (string) ($data['token'] ?? '');
    }

    public function test_recaptcha_blocks_mutating_requests_without_token_when_enabled(): void
    {
        $this->enableRecaptcha();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'missing-token@example.test',
            'password' => 'password',
        ])->assertStatus(422)
            ->assertJsonPath('errors.recaptcha.0', 'Missing reCAPTCHA token.');
    }

    public function test_recaptcha_blocks_search_requests_without_token_when_enabled(): void
    {
        $this->enableRecaptcha();

        $this->getJson('/api/v1/search?q=lamp')
            ->assertStatus(422)
            ->assertJsonPath('errors.recaptcha.0', 'Missing reCAPTCHA token.');
    }

    public function test_recaptcha_allows_verified_mutating_requests(): void
    {
        $this->enableRecaptcha();

        $user = User::factory()->create([
            'email' => 'recaptcha@example.test',
            'password' => 'password',
        ]);

        Http::fake([
            'https://www.google.com/recaptcha/api/siteverify' => Http::response([
                'success' => true,
                'score' => 0.9,
                'action' => 'post_auth/login',
            ]),
        ]);

        $this->withHeaders([
            'X-Recaptcha-Action' => 'post_auth/login',
            'X-Recaptcha-Token' => 'valid-token',
        ])->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        Http::assertSent(fn ($request) => $request['secret'] === 'test-secret'
            && $request['response'] === 'valid-token');
    }

    public function test_google_callback_creates_user_and_marks_missing_city(): void
    {
        config()->set('app.frontend_url', 'https://app.example.test');

        $this->fakeGoogleCallback([
            'id' => 'google-ada',
            'email' => 'Ada@Example.TEST',
            'name' => 'Ada Lovelace',
            'given_name' => 'Ada',
            'family_name' => 'Lovelace',
        ]);

        $response = $this->get('/api/v1/auth/google/callback');

        $response->assertRedirect();
        $this->assertStringStartsWith(
            'https://app.example.test/auth/google/callback#token=',
            (string) $response->headers->get('Location')
        );

        $this->assertDatabaseHas('users', [
            'email' => 'ada@example.test',
            'google_id' => 'google-ada',
            'given_name' => 'Ada',
            'family_name' => 'Lovelace',
            'locale' => 'he',
            'role' => 'user',
        ]);
        $this->assertNull(User::query()->where('email', 'ada@example.test')->value('password'));
        $this->assertNotNull(User::query()->where('email', 'ada@example.test')->value('email_verified_at'));

        $this->withToken($this->tokenFromGoogleRedirect($response))
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.has_password', false)
            ->assertJsonPath('data.profile_complete', false)
            ->assertJsonPath('data.missing_profile_fields', ['city']);
    }

    public function test_google_callback_links_existing_email_and_fills_missing_names(): void
    {
        config()->set('app.frontend_url', 'https://app.example.test');

        $user = User::factory()->create([
            'email' => 'link@example.test',
            'given_name' => null,
            'family_name' => null,
            'name' => 'Link Account',
        ]);
        $user->profile()->update(['city' => 'Tel Aviv']);

        $this->fakeGoogleCallback([
            'id' => 'google-link',
            'email' => 'link@example.test',
            'name' => 'Mira Cohen',
            'given_name' => 'Mira',
            'family_name' => 'Cohen',
        ]);

        $response = $this->get('/api/v1/auth/google/callback');
        $response->assertRedirect();

        $this->assertSame(1, User::query()->where('email', 'link@example.test')->count());
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'google_id' => 'google-link',
            'given_name' => 'Mira',
            'family_name' => 'Cohen',
        ]);

        $this->withToken($this->tokenFromGoogleRedirect($response))
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.has_password', true)
            ->assertJsonPath('data.profile_complete', true)
            ->assertJsonPath('data.missing_profile_fields', []);
    }

    public function test_google_callback_marks_missing_required_names(): void
    {
        $this->fakeGoogleCallback([
            'id' => 'google-missing',
            'email' => 'missing@example.test',
            'name' => null,
            'given_name' => null,
            'family_name' => null,
        ]);

        $response = $this->get('/api/v1/auth/google/callback');

        $this->withToken($this->tokenFromGoogleRedirect($response))
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.profile_complete', false)
            ->assertJsonPath('data.missing_profile_fields', ['given_name', 'family_name', 'city']);
    }

    public function test_google_callback_blocks_banned_email(): void
    {
        config()->set('app.frontend_url', 'https://app.example.test');
        EmailBan::query()->create([
            'email' => 'banned@example.test',
            'banned_at' => now(),
        ]);

        $this->fakeGoogleCallback([
            'id' => 'google-banned',
            'email' => 'banned@example.test',
            'name' => 'Banned User',
        ]);

        $this->get('/api/v1/auth/google/callback')
            ->assertRedirect('https://app.example.test/auth/google/callback?error=email_banned');

        $this->assertDatabaseMissing('users', ['email' => 'banned@example.test']);
    }

    public function test_database_seeder_creates_admin_user_and_private_ad_without_prefilled_pages(): void
    {
        $this->seed();

        $this->assertDatabaseHas('users', ['email' => 'admin@sveevee.local', 'role' => 'admin']);
        $this->assertDatabaseHas('users', ['email' => 'support@sveevee.local', 'login' => 'sffSrgsrgrsgsG', 'role' => 'admin']);
        $this->assertDatabaseHas('users', ['email' => 'user@sveevee.local', 'role' => 'user']);
        $this->assertDatabaseMissing('pages', ['type' => 'business']);
        $this->assertDatabaseMissing('pages', ['type' => 'community']);
        $this->assertDatabaseHas('ads', ['type' => 'private_ad', 'title' => 'Kids chair to give away']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'user@sveevee.local',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('data.user.email', 'user@sveevee.local')
            ->assertJsonPath('data.user.role', 'user');

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@sveevee.local',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('data.user.email', 'admin@sveevee.local')
            ->assertJsonPath('data.user.role', 'admin');
    }

    public function test_login_accepts_login_identifier(): void
    {
        User::factory()->create([
            'login' => 'fixedAdminLogin',
            'email' => 'fixed-admin@example.test',
            'password' => 'password',
            'role' => 'admin',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'fixedAdminLogin',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('data.user.email', 'fixed-admin@example.test')
            ->assertJsonPath('data.user.role', 'admin');
    }

    public function test_chat_requires_reply_before_second_message_to_same_user(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();

        Sanctum::actingAs($sender);

        $this->postJson("/api/v1/chats/users/{$recipient->id}/messages", [
            'body' => 'Hallo',
        ])->assertCreated()
            ->assertJsonPath('data.composer_state.reason', 'pending_reply')
            ->assertJsonPath('data.composer_state.message', 'You can write again after this person replies to your first message.');

        $this->postJson("/api/v1/chats/users/{$recipient->id}/messages", [
            'body' => 'Noch eine Nachricht',
        ])->assertStatus(409)
            ->assertJsonPath('errors.reason', 'pending_reply')
            ->assertJsonPath('message', 'You can write again after this person replies to your first message.');

        Sanctum::actingAs($recipient);

        $this->postJson("/api/v1/chats/users/{$sender->id}/messages", [
            'body' => 'Antwort',
        ])->assertCreated();

        Sanctum::actingAs($sender);

        $this->postJson("/api/v1/chats/users/{$recipient->id}/messages", [
            'body' => 'Danke',
        ])->assertCreated();
    }

    public function test_user_can_contact_only_ten_new_users_per_day(): void
    {
        $sender = User::factory()->create();
        Sanctum::actingAs($sender);

        $recipients = User::factory()->count(11)->create();

        foreach ($recipients->take(10) as $recipient) {
            $this->postJson("/api/v1/chats/users/{$recipient->id}/messages", [
                'body' => 'Hallo',
            ])->assertCreated();
        }

        $this->postJson("/api/v1/chats/users/{$recipients->last()->id}/messages", [
            'body' => 'Hallo Nummer 11',
        ])->assertStatus(429)
            ->assertJsonPath('errors.reason', 'daily_limit')
            ->assertJsonPath('message', 'You can contact only 10 new users per day.');
    }

    public function test_support_chat_is_visible_to_admin_and_bypasses_first_reply_limit(): void
    {
        $supportAdmin = User::query()->where('email', 'support@sveevee.local')->firstOrFail();
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/chats/support/messages', [
            'body' => 'I need help.',
        ])->assertCreated()
            ->assertJsonPath('data.other_user.id', $supportAdmin->id)
            ->assertJsonPath('data.composer_state.can_send', true);

        $this->postJson('/api/v1/chats/support/messages', [
            'body' => 'Second support message.',
        ])->assertCreated()
            ->assertJsonPath('data.composer_state.can_send', true);

        Sanctum::actingAs($supportAdmin);

        $this->getJson('/api/v1/chats')
            ->assertOk()
            ->assertJsonPath('data.conversations.0.other_user.id', $user->id)
            ->assertJsonPath('data.conversations.0.latest_message.body', 'Second support message.');
    }

    public function test_chat_message_body_is_limited_to_five_thousand_characters(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();

        Sanctum::actingAs($sender);

        $this->postJson("/api/v1/chats/users/{$recipient->id}/messages", [
            'body' => str_repeat('a', 5001),
        ])->assertStatus(422)
            ->assertJsonValidationErrors('body');
    }

    public function test_content_moderation_detects_blocked_language_in_four_languages(): void
    {
        $this->assertTrue(ContentModeration::containsBlockedLanguage('what the fuck'));
        $this->assertTrue(ContentModeration::containsBlockedLanguage('איזה חרא'));
        $this->assertTrue(ContentModeration::containsBlockedLanguage('ну ты сука'));
        $this->assertTrue(ContentModeration::containsBlockedLanguage('quelle merde'));
        $this->assertTrue(ContentModeration::containsBlockedLanguage('f u c k'));
        $this->assertFalse(ContentModeration::containsBlockedLanguage('Local electrician service in Ramot.'));
    }

    public function test_chat_and_ads_reject_blocked_language(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();

        Sanctum::actingAs($sender);

        $this->postJson("/api/v1/chats/users/{$recipient->id}/messages", [
            'body' => 'what the fuck',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('body');

        $this->postJson('/api/v1/ads', [
            'title' => 'quelle merde',
            'text' => 'Clean text.',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('title');
    }

    public function test_ad_title_and_text_limits_are_enforced(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/ads', [
            'title' => str_repeat('a', 1000),
            'text' => str_repeat('b', 5000),
        ])->assertCreated();

        $this->postJson('/api/v1/ads', [
            'title' => str_repeat('a', 1001),
            'text' => str_repeat('b', 5000),
        ])->assertStatus(422)
            ->assertJsonValidationErrors('title');

        $this->postJson('/api/v1/ads', [
            'title' => str_repeat('a', 1000),
            'text' => str_repeat('b', 5001),
        ])->assertStatus(422)
            ->assertJsonValidationErrors('text');
    }

    public function test_public_ad_show_returns_only_visible_ads(): void
    {
        $user = User::factory()->create();
        $visibleAd = Ad::query()->create([
            'user_id' => $user->id,
            'type' => Ad::TYPE_PRIVATE,
            'title' => 'Visible ad',
            'text' => 'Visible text',
            'status' => 'active',
            'expires_at' => now()->addDay(),
        ]);
        $expiredAd = Ad::query()->create([
            'user_id' => $user->id,
            'type' => Ad::TYPE_PRIVATE,
            'title' => 'Expired ad',
            'text' => 'Expired text',
            'status' => 'active',
            'expires_at' => now()->subDay(),
        ]);

        $this->getJson("/api/v1/ads/{$visibleAd->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $visibleAd->id);

        $this->getJson("/api/v1/ads/{$expiredAd->id}")
            ->assertNotFound();
    }

    public function test_sitemap_includes_public_users_pages_and_active_ads_dynamically(): void
    {
        config()->set('app.url', 'https://sveevee.co.il');

        $user = User::factory()->create();
        $bannedUser = User::factory()->create(['banned_at' => now()]);
        $adminUser = User::factory()->create(['role' => 'admin']);
        $page = Page::query()->create([
            'user_id' => $user->id,
            'type' => Page::TYPE_BUSINESS,
            'name' => 'Miri Studio',
            'public_description' => 'Local help.',
        ]);
        $ad = Ad::query()->create([
            'user_id' => $user->id,
            'type' => Ad::TYPE_PRIVATE,
            'title' => 'Sitemap ad',
            'text' => 'Sitemap text',
            'status' => 'active',
            'expires_at' => now()->addDay(),
        ]);
        $expiredAd = Ad::query()->create([
            'user_id' => $user->id,
            'type' => Ad::TYPE_PRIVATE,
            'title' => 'Expired sitemap ad',
            'text' => 'Expired sitemap text',
            'status' => 'active',
            'expires_at' => now()->subDay(),
        ]);

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('content-type', 'application/xml; charset=UTF-8')
            ->assertSee('https://sveevee.co.il/users/'.$user->id, false)
            ->assertDontSee('https://sveevee.co.il/users/'.$bannedUser->id, false)
            ->assertDontSee('https://sveevee.co.il/users/'.$adminUser->id, false)
            ->assertSee('https://sveevee.co.il/pages/'.$page->public_slug, false)
            ->assertSee('https://sveevee.co.il/ads/'.$ad->id, false)
            ->assertDontSee('https://sveevee.co.il/ads/'.$expiredAd->id, false);

        $ad->delete();

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertDontSee('https://sveevee.co.il/ads/'.$ad->id, false);
    }

    public function test_catalog_topics_endpoint_lists_registry(): void
    {
        $this->getJson('/api/v1/catalog')
            ->assertOk()
            ->assertJsonPath('data.popular_topics.0.key', CatalogTopics::POPULAR_KEYS[0])
            ->assertJsonPath('data.groups.0.topics.0.slug', fn ($value) => filled($value));

        $this->getJson('/api/v1/catalog/businesses')
            ->assertOk()
            ->assertJsonPath('data.hub.slug', 'businesses')
            ->assertJsonPath('data.groups.0.topics.0.slug', fn ($value) => filled($value));

        $this->getJson('/api/v1/catalog/businesses/haifa')
            ->assertOk()
            ->assertJsonPath('data.hub.slug', 'businesses')
            ->assertJsonPath('data.city', 'Haifa');
    }

    public function test_catalog_categories_are_validated_and_saved_for_pages_and_items(): void
    {
        Storage::fake('public');

        $owner = User::factory()->create();
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/pages/business', [
            'name' => 'Electric Studio',
            'public_description' => 'Local electrical work.',
            'category_key' => 'professionals.electricians',
            'setup' => [
                'address' => [
                    'city' => 'Haifa',
                    'neighborhood' => 'Hadar',
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('data.category_key', 'professionals.electricians');

        $page = Page::query()->where('user_id', $owner->id)->where('type', Page::TYPE_BUSINESS)->firstOrFail();

        $this->postJson('/api/v1/pages/business', [
            'name' => 'Missing Category Studio',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('category_key');

        $this->postJson('/api/v1/pages/business', [
            'name' => 'Electric Studio',
            'category_key' => 'events.community',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('category_key');

        $this->post("/api/v1/pages/{$page->id}/services", [
            'name' => 'Electrical repairs',
            'description' => 'Sockets, boards, and lighting.',
            'category_key' => 'services.home_repairs',
            'image' => UploadedFile::fake()->image('repair.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.category_key', 'services.home_repairs.handyman');

        $this->post("/api/v1/pages/{$page->id}/services", [
            'name' => 'Bad service',
            'description' => 'Wrong category scope.',
            'category_key' => 'products.home_garden',
            'image' => UploadedFile::fake()->image('bad.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_catalog_api_filters_segments_by_topic_and_location(): void
    {
        Storage::fake('public');

        $owner = User::factory()->create();
        $owner->profile()->update([
            'city' => 'Haifa',
            'neighborhood' => 'Hadar',
            'user_type' => 'professionals.electricians',
        ]);

        $page = Page::query()->create([
            'user_id' => $owner->id,
            'type' => Page::TYPE_BUSINESS,
            'name' => 'Electric Studio',
            'public_description' => 'Local electrical work.',
            'category_key' => 'professionals.electricians',
            'setup' => [
                'address' => [
                    'city' => 'Haifa',
                    'neighborhood' => 'Hadar',
                ],
            ],
        ]);

        PageService::query()->create([
            'page_id' => $page->id,
            'name' => 'Electrical repairs',
            'description' => 'Sockets and boards.',
            'category_key' => 'services.home_repairs.handyman',
            'image_path' => 'services/repair.webp',
        ]);

        Ad::query()->create([
            'user_id' => $owner->id,
            'type' => Ad::TYPE_PRIVATE,
            'title' => 'Need electrician',
            'text' => 'Local help needed.',
            'category' => 'home_professionals.electrician',
            'status' => 'active',
            'city' => 'Haifa',
            'neighborhood' => 'Hadar',
            'expires_at' => now()->addDay(),
        ]);

        $this->getJson('/api/v1/catalog/electricians/haifa/hadar')
            ->assertOk()
            ->assertJsonPath('data.indexable', true)
            ->assertJsonPath('data.counts.pages', 1)
            ->assertJsonPath('data.counts.ads', 1)
            ->assertJsonPath('data.counts.users', 1)
            ->assertJsonPath('data.segments.pages.items.0.name', 'Electric Studio')
            ->assertJsonPath('data.segments.pages.items.0.slug', $page->public_slug)
            ->assertJsonPath('data.segments.ads.items.0.title', 'Need electrician');

        $this->getJson('/api/v1/catalog/home-repair-services/haifa/hadar')
            ->assertOk()
            ->assertJsonPath('data.counts.services', 1)
            ->assertJsonPath('data.segments.services.items.0.page.name', 'Electric Studio')
            ->assertJsonPath('data.segments.services.items.0.page.slug', $page->public_slug);

        $this->getJson('/api/v1/catalog/electricians/tel-aviv')
            ->assertOk()
            ->assertJsonPath('data.indexable', false)
            ->assertJsonPath('data.total_count', 0);
    }

    public function test_search_scope_limits_results_and_validates_category_scope(): void
    {
        $owner = User::factory()->create();
        $owner->profile()->update([
            'city' => 'Haifa',
            'neighborhood' => 'Hadar',
            'user_type' => 'professionals.electricians',
        ]);

        $page = Page::query()->create([
            'user_id' => $owner->id,
            'type' => Page::TYPE_BUSINESS,
            'name' => 'Electric Studio',
            'public_description' => 'Local electrical work.',
            'category_key' => 'professionals.electricians',
            'setup' => [
                'address' => [
                    'city' => 'Haifa',
                    'neighborhood' => 'Hadar',
                ],
            ],
        ]);

        PageService::query()->create([
            'page_id' => $page->id,
            'name' => 'Electrical repairs',
            'description' => 'Sockets and boards.',
            'category_key' => 'services.home_repairs.handyman',
            'image_path' => 'services/repair.webp',
        ]);

        Ad::query()->create([
            'user_id' => $owner->id,
            'type' => Ad::TYPE_PRIVATE,
            'title' => 'Electric ad',
            'text' => 'Local help needed.',
            'category' => 'home_professionals.electrician',
            'status' => 'active',
            'city' => 'Haifa',
            'neighborhood' => 'Hadar',
            'expires_at' => now()->addDay(),
        ]);

        $this->getJson('/api/v1/search?q=Electric&scope=pages')
            ->assertOk()
            ->assertJsonCount(1, 'data.pages')
            ->assertJsonCount(0, 'data.services')
            ->assertJsonCount(0, 'data.ads')
            ->assertJsonPath('data.pages.0.slug', $page->public_slug);

        $this->getJson('/api/v1/search?scope=services&category=services.home_repairs')
            ->assertOk()
            ->assertJsonCount(0, 'data.pages')
            ->assertJsonCount(1, 'data.services')
            ->assertJsonPath('data.services.0.page.slug', $page->public_slug);

        $this->getJson('/api/v1/search?scope=services&category=products.home_garden')
            ->assertStatus(422)
            ->assertJsonValidationErrors('category');
    }

    public function test_sitemap_includes_only_non_empty_catalog_pages(): void
    {
        config()->set('app.url', 'https://sveevee.co.il');

        $owner = User::factory()->create();
        Page::query()->create([
            'user_id' => $owner->id,
            'type' => Page::TYPE_BUSINESS,
            'name' => 'Electric Studio',
            'category_key' => 'professionals.electricians',
            'setup' => [
                'address' => [
                    'city' => 'Haifa',
                    'neighborhood' => 'Hadar',
                ],
            ],
        ]);

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('https://sveevee.co.il/catalog/businesses', false)
            ->assertSee('https://sveevee.co.il/catalog/communities', false)
            ->assertSee('https://sveevee.co.il/catalog/products', false)
            ->assertSee('https://sveevee.co.il/catalog/services', false)
            ->assertSee('https://sveevee.co.il/catalog/events', false)
            ->assertSee('https://sveevee.co.il/catalog/ads', false)
            ->assertSee('https://sveevee.co.il/catalog/people', false)
            ->assertSee('https://sveevee.co.il/catalog/electricians', false)
            ->assertSee('https://sveevee.co.il/catalog/electricians/haifa', false)
            ->assertSee('https://sveevee.co.il/catalog/electricians/haifa/hadar', false)
            ->assertDontSee('https://sveevee.co.il/catalog/home-repair-services', false);
    }

    public function test_user_can_create_page_with_presence_details(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['email' => 'owner@example.test']);
        Sanctum::actingAs($user);

        $createdPage = $this->postJson('/api/v1/pages/business', [
            'name' => 'Miri Studio',
            'public_description' => 'Local design help.',
            'category_key' => 'creators.graphic_designer',
            'contact_email' => 'hello@example.test',
            'phone' => '+972 50 111 2222',
            'address' => 'Herzl 10, Haifa',
            'palette_key' => 'sea-glass',
            'setup' => [
                'contact' => [
                    'tel' => '+972 50 111 2222',
                    'email' => 'hello@example.test',
                    'whatsapp' => '+972 50 111 2222',
                ],
                'address' => [
                    'street' => 'Herzl',
                    'number' => '10',
                    'city' => 'Haifa',
                    'neighborhood' => 'Hadar',
                ],
                'opening_hours' => [
                    ['weekday' => 'monday', 'is_open' => true, 'opens_at' => '08:30', 'closes_at' => '16:00'],
                ],
                'features' => [
                    'store' => false,
                    'services' => true,
                ],
            ],
        ]);

        $createdPage->assertOk()
            ->assertJsonPath('data.slug', 'miri-studio-'.$createdPage->json('data.id'))
            ->assertJsonPath('data.public_path', '/pages/miri-studio-'.$createdPage->json('data.id'))
            ->assertJsonPath('data.palette_key', 'sea-glass')
            ->assertJsonPath('data.contact.whatsapp', '+972 50 111 2222')
            ->assertJsonPath('data.address_details.street', 'Herzl')
            ->assertJsonPath('data.address_details.neighborhood', 'Hadar')
            ->assertJsonPath('data.opening_hours.1.weekday', 'monday')
            ->assertJsonPath('data.opening_hours.1.opens_at', '08:30')
            ->assertJsonPath('data.features.store', false)
            ->assertJsonPath('data.features.services', true)
            ->assertJsonPath('data.features.events', false)
            ->assertJsonCount(0, 'data.services');

        $this->getJson('/api/v1/pages/'.$createdPage->json('data.id'))
            ->assertOk()
            ->assertJsonPath('data.features.store', false)
            ->assertJsonPath('data.features.services', true)
            ->assertJsonPath('data.features.events', false)
            ->assertJsonCount(0, 'data.services');

        $this->getJson('/api/v1/pages/'.$createdPage->json('data.slug'))
            ->assertOk()
            ->assertJsonPath('data.name', 'Miri Studio');

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.business_page.name', 'Miri Studio');

        $page = Page::query()->where('user_id', $user->id)->where('type', Page::TYPE_BUSINESS)->firstOrFail();
        $page->forceFill([
            'logo_path' => 'pages/logos/logo.png',
            'logo_original_name' => 'logo.png',
            'banner_path' => 'pages/banners/banner.png',
            'banner_original_name' => 'banner.png',
        ])->save();
        Storage::disk('public')->put('pages/logos/logo.png', 'logo');
        Storage::disk('public')->put('pages/banners/banner.png', 'banner');

        $this->post('/api/v1/pages/business', [
            'name' => 'Miri Studio',
            'public_description' => 'Local design help.',
            'category_key' => 'creators.graphic_designer',
            'contact_email' => 'hello@example.test',
            'phone' => '+972 50 111 2222',
            'address' => 'Herzl 10, Haifa',
            'palette_key' => 'sea-glass',
            'logo_remove' => '1',
            'banner_remove' => '1',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.logo_url', null)
            ->assertJsonPath('data.logo_name', null)
            ->assertJsonPath('data.banner_url', null)
            ->assertJsonPath('data.banner_name', null);

        Storage::disk('public')->assertMissing('pages/logos/logo.png');
        Storage::disk('public')->assertMissing('pages/banners/banner.png');
    }

    public function test_ads_use_owner_location_and_search_by_location(): void
    {
        $owner = User::factory()->create();
        $owner->profile()->update([
            'city' => 'Jerusalem',
            'neighborhood' => 'Ramot',
        ]);

        $other = User::factory()->create();
        $other->profile()->update([
            'city' => 'Jerusalem',
            'neighborhood' => 'Ramot',
        ]);

        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/ads', [
            'title' => 'Desk lamp',
            'text' => 'Pickup today.',
            'category' => 'real_estate.for_sale',
        ])->assertCreated()
            ->assertJsonPath('data.category', 'real_estate.for_sale')
            ->assertJsonPath('data.city', 'Jerusalem')
            ->assertJsonPath('data.neighborhood', 'Ramot');

        $this->postJson('/api/v1/ads', [
            'title' => 'Catalog category ad',
            'text' => 'Stored with a catalog topic.',
            'category' => 'professionals.electricians',
        ])->assertCreated()
            ->assertJsonPath('data.category', 'professionals.electricians');

        $this->postJson('/api/v1/ads', [
            'title' => 'Invalid category ad',
            'text' => 'This should not save.',
            'category' => 'not.a.category',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('category');

        $page = Page::query()->create([
            'user_id' => $owner->id,
            'type' => Page::TYPE_BUSINESS,
            'name' => 'Florentin Studio',
            'setup' => [
                'address' => [
                    'street' => 'Herzl',
                    'number' => '10',
                    'city' => 'Tel Aviv',
                    'neighborhood' => 'Florentin',
                ],
            ],
        ]);

        $pageAd = $this->postJson('/api/v1/ads', [
            'title' => 'Desk lamp',
            'text' => 'Pickup from the studio.',
            'category' => 'jobs.job_offers',
            'page_id' => $page->id,
        ])->assertCreated()
            ->assertJsonPath('data.category', 'jobs.job_offers')
            ->assertJsonPath('data.city', 'Tel Aviv')
            ->assertJsonPath('data.neighborhood', 'Florentin');

        $this->getJson('/api/v1/pages/business/mine')
            ->assertOk()
            ->assertJsonCount(1, 'data.ads')
            ->assertJsonPath('data.ads.0.id', $pageAd->json('data.id'));

        Ad::query()->create([
            'user_id' => $other->id,
            'type' => Ad::TYPE_PRIVATE,
            'title' => 'Desk lamp',
            'text' => 'Pickup from Ramot.',
            'status' => 'active',
            'city' => 'Jerusalem',
            'neighborhood' => 'Ramot',
        ]);

        $query = http_build_query([
            'q' => 'Desk',
            'city' => 'Tel Aviv',
            'neighborhood' => 'Florentin',
        ]);

        $this->getJson("/api/v1/search?{$query}")
            ->assertOk()
            ->assertJsonCount(1, 'data.ads')
            ->assertJsonPath('data.ads.0.city', 'Tel Aviv')
            ->assertJsonPath('data.ads.0.neighborhood', 'Florentin');

        $locations = $this->getJson('/api/v1/locations')
            ->assertOk()
            ->assertJsonFragment(['city' => 'Tel Aviv', 'name' => 'Florentin']);

        $this->assertContains('Tel Aviv', $locations->json('data.cities'));
        $this->assertContains(['city' => 'Tel Aviv', 'name' => 'Ramat Aviv'], $locations->json('data.neighborhoods'));
    }

    public function test_home_feed_prioritizes_neighborhood_city_then_other_ads_and_paginates(): void
    {
        $viewer = User::factory()->create();
        $viewer->profile()->update([
            'city' => 'Jerusalem',
            'neighborhood' => 'Ramot',
        ]);

        $poster = User::factory()->create();

        $sameNeighborhoodAd = Ad::query()->create([
            'user_id' => $poster->id,
            'type' => Ad::TYPE_PRIVATE,
            'title' => 'Ramot class',
            'text' => 'In the neighborhood.',
            'status' => 'active',
            'city' => 'Jerusalem',
            'neighborhood' => 'Ramot',
        ]);

        $sameCityOtherNeighborhoodAd = Ad::query()->create([
            'user_id' => $poster->id,
            'type' => Ad::TYPE_PRIVATE,
            'title' => 'Gilo class',
            'text' => 'In another neighborhood.',
            'status' => 'active',
            'city' => 'Jerusalem',
            'neighborhood' => 'Gilo',
        ]);

        $citywideAd = Ad::query()->create([
            'user_id' => $poster->id,
            'type' => Ad::TYPE_PRIVATE,
            'title' => 'Jerusalem class',
            'text' => 'Open to the city.',
            'status' => 'active',
            'city' => 'Jerusalem',
            'neighborhood' => null,
        ]);

        Ad::query()->create([
            'user_id' => $poster->id,
            'type' => Ad::TYPE_PRIVATE,
            'title' => 'Tel Aviv class',
            'text' => 'In another city.',
            'status' => 'active',
            'city' => 'Tel Aviv',
            'neighborhood' => null,
        ]);

        collect(range(1, 21))->each(fn (int $index) => Ad::query()->create([
            'user_id' => $poster->id,
            'type' => Ad::TYPE_PRIVATE,
            'title' => "Other city {$index}",
            'text' => 'More ads for pagination.',
            'status' => 'active',
            'city' => 'Haifa',
            'neighborhood' => null,
        ]));

        Sanctum::actingAs($viewer);

        $response = $this->getJson('/api/v1/home-feed')
            ->assertOk();

        $ids = collect($response->json('data.items'))->pluck('id')->all();

        $this->assertCount(20, $response->json('data.items'));
        $this->assertSame(20, $response->json('data.pagination.per_page'));
        $this->assertSame(25, $response->json('data.pagination.total'));
        $this->assertSame(2, $response->json('data.pagination.last_page'));
        $this->assertSame($sameNeighborhoodAd->id, $response->json('data.items.0.id'));
        $this->assertContains($sameCityOtherNeighborhoodAd->id, array_slice($ids, 1, 2));
        $this->assertContains($citywideAd->id, $ids);

        $this->getJson('/api/v1/home-feed?page=2')
            ->assertOk()
            ->assertJsonCount(5, 'data.items')
            ->assertJsonPath('data.pagination.current_page', 2);
    }

    public function test_ads_expire_after_one_week_and_can_be_pruned(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 10:00:00'));

        try {
            $owner = User::factory()->create();
            Sanctum::actingAs($owner);

            $this->postJson('/api/v1/ads', [
                'title' => 'One week ad',
                'text' => 'This should expire automatically.',
            ])->assertCreated()
                ->assertJsonPath('data.expires_at', now()->addWeek()->toISOString());

            $ad = Ad::query()->firstOrFail();
            $this->assertTrue($ad->expires_at->equalTo(now()->addWeek()));

            Carbon::setTestNow(now()->addDays(8));

            $this->getJson('/api/v1/ads?scope=mine')
                ->assertOk()
                ->assertJsonCount(0, 'data');

            $this->artisan('ads:prune-expired')
                ->expectsOutput('Deleted 1 expired ads.')
                ->assertExitCode(0);

            $this->assertDatabaseMissing('ads', ['id' => $ad->id]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_owner_can_update_ad(): void
    {
        Storage::fake('public');

        $owner = User::factory()->create();
        Sanctum::actingAs($owner);

        $created = $this->post('/api/v1/ads', [
            'title' => 'Original headline',
            'text' => 'Original text.',
            'category' => 'shopping_retail.gifts',
            'image' => UploadedFile::fake()->image('ad.png'),
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.category', 'shopping_retail.gifts')
            ->assertJsonPath('data.image_name', 'ad.png');

        $adId = $created->json('data.id');
        $oldImagePath = Ad::query()->findOrFail($adId)->image_path;
        Storage::disk('public')->assertExists($oldImagePath);

        $this->post("/api/v1/ads/{$adId}", [
            '_method' => 'PUT',
            'title' => 'Updated headline',
            'text' => 'Updated text.',
            'category' => 'electronics_appliances.computers',
            'image_remove' => '1',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.title', 'Updated headline')
            ->assertJsonPath('data.text', 'Updated text.')
            ->assertJsonPath('data.category', 'electronics_appliances.computers')
            ->assertJsonPath('data.image_url', null)
            ->assertJsonPath('data.image_name', null);

        $this->assertDatabaseHas('ads', [
            'id' => $adId,
            'title' => 'Updated headline',
            'text' => 'Updated text.',
            'category' => 'electronics_appliances.computers',
            'image_path' => null,
            'image_original_name' => null,
        ]);
        Storage::disk('public')->assertMissing($oldImagePath);
    }

    public function test_user_can_upload_profile_photo_up_to_twenty_megabytes(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->post('/api/v1/profile/photo', [
            'photo_original_name' => 'konezki.png',
            'photo' => UploadedFile::fake()->image('avatar.png')->size(15000),
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.profile.photo_url', fn ($value) => is_string($value) && str_contains($value, '/storage/profiles/'))
            ->assertJsonPath('data.profile.photo_name', 'konezki.png');

        Storage::disk('public')->assertExists($user->fresh()->profile->photo_path);
        $this->assertDatabaseHas('user_profiles', [
            'user_id' => $user->id,
            'photo_original_name' => 'konezki.png',
        ]);
    }

    public function test_user_can_update_profile_locale(): void
    {
        $user = User::factory()->create(['locale' => 'he']);
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/profile/locale', [
            'locale' => 'en',
        ])->assertOk()
            ->assertJsonPath('data.user.locale', 'en')
            ->assertJsonPath('data.profile.locale', 'en');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'locale' => 'en',
        ]);
    }

    public function test_user_can_update_optional_profile_user_type(): void
    {
        $user = User::factory()->create([
            'email' => 'profile-type@example.test',
            'given_name' => 'Ada',
            'family_name' => 'Lovelace',
            'locale' => 'he',
        ]);
        Sanctum::actingAs($user);

        $payload = [
            'email' => $user->email,
            'given_name' => 'Ada',
            'family_name' => 'Lovelace',
            'phone' => '+972 50 111 2222',
            'city' => 'Haifa',
            'neighborhood' => 'Hadar',
            'locale' => 'he',
            'user_type' => 'professionals.electricians',
        ];

        $this->putJson('/api/v1/profile', $payload)
            ->assertOk()
            ->assertJsonPath('data.user_type', 'professionals.electricians');

        $this->assertDatabaseHas('user_profiles', [
            'user_id' => $user->id,
            'user_type' => 'professionals.electricians',
        ]);

        $this->putJson('/api/v1/profile', [
            ...$payload,
            'user_type' => '',
        ])->assertOk()
            ->assertJsonPath('data.user_type', null);

        $this->assertDatabaseHas('user_profiles', [
            'user_id' => $user->id,
            'user_type' => null,
        ]);

        $this->putJson('/api/v1/profile', [
            ...$payload,
            'user_type' => 'private_seller.general',
        ])->assertStatus(422);
    }

    public function test_user_can_request_password_reset_email(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'reset@example.test']);

        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'reset@example.test',
        ])->assertOk();

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    public function test_user_can_reset_password_with_email_token(): void
    {
        $user = User::factory()->create(['email' => 'reset-token@example.test']);
        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'reset-token@example.test',
            'token' => $token,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
    }

    public function test_user_can_update_profile_password_and_receive_email(): void
    {
        Notification::fake();

        $user = User::factory()->create(['password' => 'old-password']);
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/profile/password', [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        Notification::assertSentTo($user, PasswordChangedNotification::class);
    }

    public function test_user_can_delete_profile_photo(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $profile = $user->profile()->updateOrCreate([], [
            'photo_path' => 'profiles/avatar.png',
            'photo_original_name' => 'avatar.png',
        ]);
        Storage::disk('public')->put($profile->photo_path, 'image');

        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/profile/photo')
            ->assertOk()
            ->assertJsonPath('data.profile.photo_url', null)
            ->assertJsonPath('data.user.profile.photo_url', null);

        Storage::disk('public')->assertMissing('profiles/avatar.png');
        $this->assertDatabaseHas('user_profiles', [
            'user_id' => $user->id,
            'photo_path' => null,
            'photo_original_name' => null,
        ]);
    }

    public function test_business_page_owner_can_add_store_product(): void
    {
        Storage::fake('public');

        $owner = User::factory()->create();
        $businessPage = Page::query()->create([
            'user_id' => $owner->id,
            'type' => Page::TYPE_BUSINESS,
            'name' => 'Miri Studio',
        ]);
        $communityPage = Page::query()->create([
            'user_id' => $owner->id,
            'type' => Page::TYPE_COMMUNITY,
            'name' => 'Miri Community',
        ]);

        Sanctum::actingAs($owner);

        $createdProduct = $this->post("/api/v1/pages/{$businessPage->id}/products", [
            'name' => 'Ceramic cup',
            'description' => 'Handmade cup from the studio.',
            'category_key' => 'products.home_garden.kitchen_dining',
            'image' => UploadedFile::fake()->image('cup.jpg'),
            'price' => '29.90',
            'link' => 'https://seller.example/products/cup',
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.name', 'Ceramic cup')
            ->assertJsonPath('data.price', 29.9)
            ->assertJsonPath('data.link', 'https://seller.example/products/cup');

        $oldProductImagePath = PageProduct::query()->findOrFail($createdProduct->json('data.id'))->image_path;
        Storage::disk('public')->assertExists($oldProductImagePath);

        $this->post("/api/v1/products/{$createdProduct->json('data.id')}", [
            '_method' => 'PUT',
            'name' => 'Ceramic bowl',
            'description' => 'Updated product description.',
            'category_key' => 'products.home_garden.kitchen_dining',
            'price' => '39.90',
            'link' => 'https://seller.example/products/bowl',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.name', 'Ceramic bowl')
            ->assertJsonPath('data.description', 'Updated product description.')
            ->assertJsonPath('data.price', 39.9)
            ->assertJsonPath('data.link', 'https://seller.example/products/bowl');

        $this->assertDatabaseHas('page_products', [
            'id' => $createdProduct->json('data.id'),
            'name' => 'Ceramic bowl',
            'description' => 'Updated product description.',
        ]);

        $this->post("/api/v1/products/{$createdProduct->json('data.id')}", [
            '_method' => 'PUT',
            'name' => 'Ceramic bowl',
            'description' => 'Updated product description.',
            'category_key' => 'products.home_garden.kitchen_dining',
            'price' => '39.90',
            'link' => 'https://seller.example/products/bowl',
            'image_remove' => '1',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.image_url', null)
            ->assertJsonPath('data.image_name', null);

        Storage::disk('public')->assertMissing($oldProductImagePath);

        $this->getJson("/api/v1/pages/{$businessPage->id}")
            ->assertOk()
            ->assertJsonPath('data.products.0.name', 'Ceramic bowl')
            ->assertJsonPath('data.products.0.price_label', '₪39.90');

        $deletedProduct = $this->post("/api/v1/pages/{$businessPage->id}/products", [
            'name' => 'Delete me',
            'description' => 'This product should be deleted.',
            'category_key' => 'products.home_garden.kitchen_dining',
            'image' => UploadedFile::fake()->image('delete-me.jpg'),
            'price' => '12.00',
            'link' => 'https://seller.example/products/delete-me',
        ], ['Accept' => 'application/json'])->assertCreated();

        $deletedProductId = $deletedProduct->json('data.id');
        $deletedProductImagePath = PageProduct::query()->findOrFail($deletedProductId)->image_path;
        Storage::disk('public')->assertExists($deletedProductImagePath);

        $this->deleteJson("/api/v1/products/{$deletedProductId}")
            ->assertOk();

        $this->assertDatabaseMissing('page_products', ['id' => $deletedProductId]);
        Storage::disk('public')->assertMissing($deletedProductImagePath);

        $this->post("/api/v1/pages/{$communityPage->id}/products", [
            'name' => 'Community cup',
            'description' => 'Not allowed here.',
            'image' => UploadedFile::fake()->image('community-cup.jpg'),
            'price' => '19.90',
            'link' => 'https://seller.example/products/community-cup',
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_business_page_owner_can_add_service(): void
    {
        Storage::fake('public');

        $owner = User::factory()->create();
        $businessPage = Page::query()->create([
            'user_id' => $owner->id,
            'type' => Page::TYPE_BUSINESS,
            'name' => 'Miri Studio',
            'setup' => [
                'features' => [
                    'services' => true,
                ],
            ],
        ]);
        $communityPage = Page::query()->create([
            'user_id' => $owner->id,
            'type' => Page::TYPE_COMMUNITY,
            'name' => 'Miri Community',
        ]);

        Sanctum::actingAs($owner);

        $createdService = $this->post("/api/v1/pages/{$businessPage->id}/services", [
            'name' => 'Electrical repairs',
            'description' => 'Fuse boxes, sockets, lighting, and urgent visits.',
            'category_key' => 'services.home_repairs.plumbing',
            'image' => UploadedFile::fake()->image('electrician.jpg'),
            'link' => 'https://seller.example/services/electrician',
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.name', 'Electrical repairs')
            ->assertJsonPath('data.description', 'Fuse boxes, sockets, lighting, and urgent visits.')
            ->assertJsonPath('data.link', 'https://seller.example/services/electrician');

        $oldServiceImagePath = PageService::query()->findOrFail($createdService->json('data.id'))->image_path;
        Storage::disk('public')->assertExists($oldServiceImagePath);

        $this->post("/api/v1/services/{$createdService->json('data.id')}", [
            '_method' => 'PUT',
            'name' => 'Home electrical work',
            'description' => 'Repairs, installations, and safety checks.',
            'category_key' => 'services.home_repairs.plumbing',
            'link' => '',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.name', 'Home electrical work')
            ->assertJsonPath('data.description', 'Repairs, installations, and safety checks.')
            ->assertJsonPath('data.link', null);

        $this->assertDatabaseHas('page_services', [
            'id' => $createdService->json('data.id'),
            'name' => 'Home electrical work',
            'description' => 'Repairs, installations, and safety checks.',
            'link' => null,
        ]);

        $this->getJson("/api/v1/pages/{$businessPage->id}")
            ->assertOk()
            ->assertJsonPath('data.features.services', true)
            ->assertJsonPath('data.services.0.name', 'Home electrical work');

        $deletedService = $this->post("/api/v1/pages/{$businessPage->id}/services", [
            'name' => 'Delete me',
            'description' => 'This service should be deleted.',
            'category_key' => 'services.home_repairs.plumbing',
            'image' => UploadedFile::fake()->image('delete-service.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $deletedServiceId = $deletedService->json('data.id');
        $deletedServiceImagePath = PageService::query()->findOrFail($deletedServiceId)->image_path;
        Storage::disk('public')->assertExists($deletedServiceImagePath);

        $this->deleteJson("/api/v1/services/{$deletedServiceId}")
            ->assertOk();

        $this->assertDatabaseMissing('page_services', ['id' => $deletedServiceId]);
        Storage::disk('public')->assertMissing($deletedServiceImagePath);

        $this->post("/api/v1/pages/{$communityPage->id}/services", [
            'name' => 'Community service',
            'description' => 'Not allowed here.',
            'image' => UploadedFile::fake()->image('community-service.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_community_page_owner_can_add_event(): void
    {
        Storage::fake('public');

        $owner = User::factory()->create();
        $communityPage = Page::query()->create([
            'user_id' => $owner->id,
            'type' => Page::TYPE_COMMUNITY,
            'name' => 'Miri Community',
            'setup' => [
                'features' => [
                    'events' => true,
                ],
            ],
        ]);
        $businessPage = Page::query()->create([
            'user_id' => $owner->id,
            'type' => Page::TYPE_BUSINESS,
            'name' => 'Miri Studio',
        ]);

        Sanctum::actingAs($owner);

        $createdEvent = $this->post("/api/v1/pages/{$communityPage->id}/events", [
            'name' => 'Friday Picnic',
            'description' => 'Bring snacks and meet the neighbors.',
            'category_key' => 'events.community_social.neighborhood_meeting',
            'image' => UploadedFile::fake()->image('picnic.jpg'),
            'date' => '2026-08-14',
            'time' => '17:30',
            'end_time' => '19:00',
            'address' => 'Gan HaEm, Haifa',
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.name', 'Friday Picnic')
            ->assertJsonPath('data.date', '2026-08-14')
            ->assertJsonPath('data.time', '17:30')
            ->assertJsonPath('data.end_time', '19:00')
            ->assertJsonPath('data.address', 'Gan HaEm, Haifa');

        $oldEventImagePath = PageEvent::query()->findOrFail($createdEvent->json('data.id'))->image_path;
        Storage::disk('public')->assertExists($oldEventImagePath);

        $this->post("/api/v1/events/{$createdEvent->json('data.id')}", [
            '_method' => 'PUT',
            'name' => 'Friday Picnic Updated',
            'description' => 'Updated event details.',
            'category_key' => 'events.community_social.neighborhood_meeting',
            'date' => '2026-08-15',
            'time' => '18:45',
            'end_time' => '20:15',
            'address' => 'Gan HaEm, Haifa',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.name', 'Friday Picnic Updated')
            ->assertJsonPath('data.description', 'Updated event details.')
            ->assertJsonPath('data.date', '2026-08-15')
            ->assertJsonPath('data.time', '18:45')
            ->assertJsonPath('data.end_time', '20:15');

        $this->assertDatabaseHas('page_events', [
            'id' => $createdEvent->json('data.id'),
            'name' => 'Friday Picnic Updated',
            'description' => 'Updated event details.',
            'event_time' => '18:45',
            'event_end_time' => '20:15',
        ]);

        $this->post("/api/v1/events/{$createdEvent->json('data.id')}", [
            '_method' => 'PUT',
            'name' => 'Friday Picnic Updated',
            'description' => 'Updated event details.',
            'category_key' => 'events.community_social.neighborhood_meeting',
            'date' => '2026-08-15',
            'time' => '18:45',
            'end_time' => '',
            'address' => 'Gan HaEm, Haifa',
            'image_remove' => '1',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.image_url', null)
            ->assertJsonPath('data.image_name', null)
            ->assertJsonPath('data.end_time', null);

        Storage::disk('public')->assertMissing($oldEventImagePath);

        $this->getJson("/api/v1/pages/{$communityPage->id}")
            ->assertOk()
            ->assertJsonPath('data.features.events', true)
            ->assertJsonPath('data.events.0.name', 'Friday Picnic Updated')
            ->assertJsonPath('data.events.0.time', '18:45')
            ->assertJsonPath('data.events.0.end_time', null);

        $deletedEvent = $this->post("/api/v1/pages/{$communityPage->id}/events", [
            'name' => 'Delete event',
            'description' => 'This event should be deleted.',
            'category_key' => 'events.community_social.neighborhood_meeting',
            'image' => UploadedFile::fake()->image('delete-event.jpg'),
            'date' => '2026-08-16',
            'time' => '19:15',
            'address' => 'Gan HaEm, Haifa',
        ], ['Accept' => 'application/json'])->assertCreated();

        $deletedEventId = $deletedEvent->json('data.id');
        $deletedEventImagePath = PageEvent::query()->findOrFail($deletedEventId)->image_path;
        Storage::disk('public')->assertExists($deletedEventImagePath);

        $this->deleteJson("/api/v1/events/{$deletedEventId}")
            ->assertOk();

        $this->assertDatabaseMissing('page_events', ['id' => $deletedEventId]);
        Storage::disk('public')->assertMissing($deletedEventImagePath);

        $this->post("/api/v1/pages/{$businessPage->id}/events", [
            'name' => 'Business Event',
            'description' => 'Not allowed here.',
            'image' => UploadedFile::fake()->image('business-event.jpg'),
            'date' => '2026-08-14',
            'time' => '17:30',
            'address' => 'Herzl 10, Haifa',
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_page_owner_can_delete_page_and_its_page_ads(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $page = Page::query()->create([
            'user_id' => $owner->id,
            'type' => Page::TYPE_BUSINESS,
            'name' => 'Miri Studio',
        ]);
        $pageAd = Ad::query()->create([
            'user_id' => $owner->id,
            'page_id' => $page->id,
            'type' => Ad::TYPE_BUSINESS,
            'title' => 'Studio sale',
            'text' => 'Today only.',
            'status' => 'active',
        ]);

        Sanctum::actingAs($other);

        $this->deleteJson("/api/v1/pages/{$page->id}")
            ->assertStatus(403);

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/v1/pages/{$page->id}")
            ->assertOk();

        $this->assertDatabaseMissing('pages', ['id' => $page->id]);
        $this->assertDatabaseMissing('ads', ['id' => $pageAd->id]);

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.business_page', null);
    }

    public function test_user_can_rate_page_and_update_rating(): void
    {
        $owner = User::factory()->create();
        $reviewer = User::factory()->create();
        $page = Page::query()->create([
            'user_id' => $owner->id,
            'type' => Page::TYPE_BUSINESS,
            'name' => 'Miri Studio',
        ]);

        Sanctum::actingAs($reviewer);

        $this->putJson("/api/v1/pages/{$page->id}/ratings/me", [
            'rating' => 4,
            'comment' => 'Helpful and quick.',
        ])->assertOk()
            ->assertJsonPath('data.summary.count', 1)
            ->assertJsonPath('data.summary.average', 4)
            ->assertJsonPath('data.rating.comment', 'Helpful and quick.');

        $this->putJson("/api/v1/pages/{$page->id}/ratings/me", [
            'rating' => 5,
            'comment' => 'Updated after another visit.',
        ])->assertOk()
            ->assertJsonPath('data.summary.count', 1)
            ->assertJsonPath('data.summary.average', 5)
            ->assertJsonPath('data.rating.rating', 5);

        $this->getJson("/api/v1/pages/{$page->id}/ratings")
            ->assertOk()
            ->assertJsonPath('data.summary.count', 1)
            ->assertJsonPath('data.items.0.rating', 5)
            ->assertJsonPath('data.my_rating.rating', 5);
    }

    public function test_admin_can_ban_email_and_block_login(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create([
            'email' => 'blocked@example.test',
            'password' => 'password',
        ]);

        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/admin/users/{$user->id}/ban", [
            'reason' => 'Spam',
        ])->assertOk()
            ->assertJsonPath('data.banned_at', fn ($value) => filled($value));

        $this->postJson('/api/v1/auth/login', [
            'email' => 'blocked@example.test',
            'password' => 'password',
        ])->assertStatus(403);
    }
}
