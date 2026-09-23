<?php

namespace Tests\Feature;

use App\Models\Ad;
use App\Models\BusinessProFeature;
use App\Models\BusinessProPayment;
use App\Models\BusinessProSubscription;
use App\Models\Page;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\BusinessProEntitlementService;
use App\Services\FeaturedAdService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LocalProTesterAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Queue::fake();
        Http::preventStrayRequests();
        $this->app['env'] = 'local';
        config()->set([
            'business_pro.local_tester_access' => true,
            'business_pro.environment' => 'sandbox',
            'business_pro.billing_enabled' => false,
            'business_pro.cardcom.terminal_number' => 1000,
            'business_pro.features.featured_ads.implemented' => true,
        ]);
        Route::middleware(['api', 'auth:sanctum', 'business-pro.feature:local_business_tool'])
            ->post('/api/v1/test-local-pro/pages/{page}', fn (Page $page) => ApiResponseService::success(['page_id' => $page->id]));
    }

    public function test_local_tester_gets_both_plans_without_creating_billing_records(): void
    {
        $user = $this->tester();
        $page = $this->page($user);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/business-pro')->assertOk()
            ->assertJsonPath('data.local_test_access', true)
            ->assertJsonPath('data.has_access', true)
            ->assertJsonPath('data.subscription', null)
            ->assertJsonPath('data.pending_payment', null)
            ->assertJsonPath('data.offers.0.plan_key', 'private_pro')
            ->assertJsonPath('data.offers.0.features.0.available', true)
            ->assertJsonPath('data.offers.1.plan_key', 'business_pro')
            ->assertJsonPath('data.offers.1.features.0.available', true);
        $this->getJson('/api/v1/business-pro/pages/'.$page->id)->assertOk()
            ->assertJsonPath('data.has_access', true)->assertJsonPath('data.local_test_access', true);
        $this->assertDatabaseCount('business_pro_subscriptions', 0);
        $this->assertDatabaseCount('business_pro_payments', 0);
        Http::assertNothingSent();
    }

    public function test_local_tester_can_create_private_and_owned_business_featured_ads(): void
    {
        $user = $this->tester();
        $page = $this->page($user);
        Sanctum::actingAs($user);

        foreach ([null, $page] as $adPage) {
            $query = $adPage ? '?page_id='.$adPage->id : '';
            $this->getJson('/api/v1/business-pro/ad-feature'.$query)->assertOk()
                ->assertJsonPath('data.available', true)->assertJsonPath('data.locked_reason', null);
            $adId = $this->postJson('/api/v1/ads', [
                ...$this->form(), 'page_id' => $adPage?->id,
            ])->assertCreated()->assertJsonPath('data.is_featured', true)
                ->assertJsonPath('data.featured_requested', true)->json('data.id');
            $this->getJson('/api/v1/ads/'.$adId)->assertOk()->assertJsonPath('data.is_featured', true);
            $this->getJson('/api/v1/business-pro/ad-feature?ad_id='.$adId)->assertOk()
                ->assertJsonPath('data.available', true);
        }

        $this->getJson('/api/v1/ads?scope=mine')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('data.0.is_featured', true)
            ->assertJsonPath('data.1.is_featured', true);
        $this->assertDatabaseCount('business_pro_subscriptions', 0);
        $this->assertDatabaseCount('business_pro_payments', 0);
    }

    public function test_preview_enables_implemented_draft_and_disabled_features_and_unions_the_plans(): void
    {
        $user = $this->tester();
        $page = $this->page($user);
        $this->feature('local_private_tool', ['private_pro']);
        $this->feature('local_business_tool', ['business_pro']);
        $this->feature('local_unfinished_tool', ['private_pro', 'business_pro'], false);
        BusinessProFeature::where('key', 'featured_ads')->update(['enabled' => false, 'lifecycle' => 'draft']);
        Sanctum::actingAs($user);

        $data = $this->getJson('/api/v1/business-pro')->assertOk()->json('data');
        $features = collect($data['features'])->keyBy('key');
        $this->assertEqualsCanonicalizing([
            'featured_ads', 'local_private_tool', 'local_business_tool', 'local_unfinished_tool',
        ], $features->keys()->all());
        foreach (['featured_ads', 'local_private_tool', 'local_business_tool'] as $key) {
            $this->assertTrue($features[$key]['available']);
            $this->assertTrue($features[$key]['local_test_access']);
            $this->assertFalse($features[$key]['enabled']);
            $this->assertNull($features[$key]['locked_reason']);
        }
        $this->assertFalse($features['local_unfinished_tool']['available']);
        $this->assertSame('not_implemented', $features['local_unfinished_tool']['locked_reason']);
        $offers = collect($data['offers'])->keyBy('plan_key');
        $this->assertEqualsCanonicalizing(['featured_ads', 'local_private_tool', 'local_unfinished_tool'],
            array_column($offers['private_pro']['features'], 'key'));
        $this->assertEqualsCanonicalizing(['featured_ads', 'local_business_tool', 'local_unfinished_tool'],
            array_column($offers['business_pro']['features'], 'key'));
        foreach ($offers as $offer) {
            $this->assertTrue(collect($offer['features'])->where('implemented', true)->every('available', true));
        }
        $this->postJson('/api/v1/test-local-pro/pages/'.$page->id)->assertOk();
        $this->getJson('/api/v1/business-pro/ad-feature')->assertOk()->assertJsonPath('data.available', true);
        $this->postJson('/api/v1/ads', $this->form())->assertCreated()->assertJsonPath('data.is_featured', true);

        config()->set('business_pro.features.local_business_tool.implemented', false);
        $this->postJson('/api/v1/test-local-pro/pages/'.$page->id)->assertForbidden();
        config()->set('business_pro.features.featured_ads.implemented', false);
        $this->getJson('/api/v1/business-pro/ad-feature')->assertOk()->assertJsonPath('data.available', false);
        $this->postJson('/api/v1/ads', $this->form())->assertStatus(402);
        $this->assertFalse(app(FeaturedAdService::class)->query()->exists());
    }

    public function test_ordinary_users_and_admins_do_not_receive_the_local_override(): void
    {
        $entitlements = app(BusinessProEntitlementService::class);
        $ordinary = User::factory()->create();
        Sanctum::actingAs($ordinary);
        $this->assertFalse($entitlements->hasLocalTesterAccess($ordinary));
        $this->getJson('/api/v1/business-pro')->assertNotFound();
        $this->getJson('/api/v1/business-pro/ad-feature')->assertOk()->assertJsonPath('data.available', false);
        $this->postJson('/api/v1/ads', $this->form())->assertStatus(402);
        $this->ad($ordinary);
        foreach ([
            User::factory()->create(['role' => 'admin']),
            $this->tester(['role' => 'admin']),
        ] as $user) {
            $page = $this->page($user);
            Sanctum::actingAs($user);
            $this->assertFalse($entitlements->hasAccess($user));
            $this->assertFalse($entitlements->hasAccess($user, $page));
            $this->getJson('/api/v1/business-pro')->assertOk()
                ->assertJsonPath('data.local_test_access', false)->assertJsonPath('data.has_access', false);
            $this->getJson('/api/v1/business-pro/ad-feature')->assertOk()->assertJsonPath('data.available', false);
            $this->postJson('/api/v1/ads', $this->form())->assertStatus(402);
            $this->ad($user);
        }
        $this->assertFalse(app(FeaturedAdService::class)->query()->exists());
    }

    public function test_nonlocal_environments_deny_override_even_with_the_flag_enabled(): void
    {
        $user = $this->tester();
        $page = $this->page($user);
        $this->ad($user);
        Sanctum::actingAs($user);

        foreach (['production', 'testing', 'staging'] as $environment) {
            $this->app['env'] = $environment;
            $this->assertFalse(app(BusinessProEntitlementService::class)->hasAccess($user));
            $this->assertFalse(app(BusinessProEntitlementService::class)->hasAccess($user, $page));
            $this->getJson('/api/v1/business-pro')->assertOk()
                ->assertJsonPath('data.local_test_access', false)->assertJsonPath('data.has_access', false);
            $this->getJson('/api/v1/business-pro/ad-feature')->assertOk()->assertJsonPath('data.available', false);
            $this->postJson('/api/v1/ads', $this->form())->assertStatus(402);
            $this->assertFalse(app(FeaturedAdService::class)->query()->exists());
        }
    }

    public function test_local_configuration_can_disable_the_override_and_remove_public_priority(): void
    {
        $user = $this->tester();
        $ad = $this->ad($user);
        $featured = app(FeaturedAdService::class);
        $this->assertTrue($featured->isFeatured($ad));
        $fingerprint = $featured->fingerprint();
        config()->set('business_pro.local_tester_access', false);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/business-pro')->assertOk()
            ->assertJsonPath('data.local_test_access', false)->assertJsonPath('data.has_access', false);
        $this->getJson('/api/v1/business-pro/ad-feature')->assertOk()->assertJsonPath('data.available', false);
        $this->postJson('/api/v1/ads', $this->form())->assertStatus(402);
        $this->assertFalse($featured->isFeatured($ad));
        $this->assertNotSame($fingerprint, $featured->fingerprint());
    }

    public function test_local_override_respects_business_ownership_claim_and_page_type(): void
    {
        $user = $this->tester();
        $otherUser = $this->tester();
        $owned = $this->page($user);
        $other = $this->page($otherUser);
        $community = $this->page($user, ['type' => Page::TYPE_COMMUNITY]);
        $unclaimed = $this->page($user, ['is_unclaimed' => true]);
        $entitlements = app(BusinessProEntitlementService::class);
        Sanctum::actingAs($user);

        $this->assertTrue($entitlements->hasAccess($user, $owned));
        foreach ([$other, $community, $unclaimed] as $page) {
            $this->assertFalse($entitlements->hasAccess($user, $page));
            $this->assertFalse($entitlements->canFeatureAd($user, $page));
            $this->getJson('/api/v1/business-pro/pages/'.$page->id)->assertNotFound();
        }
        foreach ([$other, $unclaimed] as $page) {
            $this->getJson('/api/v1/business-pro/ad-feature?page_id='.$page->id)->assertNotFound();
            $this->postJson('/api/v1/ads', [...$this->form(), 'page_id' => $page->id])->assertNotFound();
        }
        $this->getJson('/api/v1/business-pro/ad-feature?page_id='.$community->id)->assertOk()
            ->assertJsonPath('data.available', false);
        $this->postJson('/api/v1/ads', [...$this->form(), 'page_id' => $community->id])->assertStatus(402);

        $ad = $this->ad($user, ['page_id' => $owned->id, 'type' => Ad::TYPE_BUSINESS]);
        $this->assertTrue(app(FeaturedAdService::class)->isFeatured($ad));
        $owned->update(['user_id' => $otherUser->id]);
        $this->assertFalse($entitlements->hasAccess($user, $owned->fresh()));
        $this->assertFalse(app(FeaturedAdService::class)->isFeatured($ad));
        $this->getJson('/api/v1/business-pro/ad-feature?ad_id='.$ad->id)->assertOk()
            ->assertJsonPath('data.available', false);
    }

    public function test_featured_sql_only_includes_valid_unmoderated_unexpired_tester_ads(): void
    {
        $user = $this->tester();
        $page = $this->page($user);
        $private = $this->ad($user);
        $business = $this->ad($user, ['type' => Ad::TYPE_BUSINESS, 'page_id' => $page->id]);
        $community = $this->page($user, ['type' => Page::TYPE_COMMUNITY]);
        $unclaimed = $this->page($user, ['is_unclaimed' => true]);
        $other = $this->page($this->tester());
        $this->ad($user, ['type' => Ad::TYPE_COMMUNITY, 'page_id' => $community->id]);
        $this->ad($user, ['type' => Ad::TYPE_BUSINESS, 'page_id' => $unclaimed->id]);
        $this->ad($user, ['type' => Ad::TYPE_BUSINESS, 'page_id' => $other->id]);
        $this->ad($user, ['type' => Ad::TYPE_PRIVATE, 'page_id' => $page->id]);
        $this->ad($user, ['type' => Ad::TYPE_BUSINESS, 'page_id' => null]);
        $this->ad($user, ['expires_at' => now()->subSecond()]);
        $this->ad($user, ['status' => 'paused']);
        $this->ad($user)->forceFill(['community_hidden_at' => now()])->save();
        $banned = $this->tester(['banned_at' => now()]);
        $this->ad($banned);
        $this->ad(User::factory()->create());
        $this->assertFalse(app(BusinessProEntitlementService::class)->hasAccess($banned));
        $this->assertFalse(app(BusinessProEntitlementService::class)->canFeatureAd($banned));
        BusinessProFeature::where('key', 'featured_ads')->update(['enabled' => false, 'lifecycle' => 'draft']);

        $this->assertEqualsCanonicalizing([$private->id, $business->id],
            app(FeaturedAdService::class)->query()->pluck('ads.id')->all());
        $this->assertEqualsCanonicalizing([$private->id, $business->id],
            Ad::query()->withFeaturedState()->get()->where('featured_active', true)->modelKeys());
    }

    public function test_local_access_does_not_change_an_existing_pending_checkout(): void
    {
        $user = $this->tester();
        $page = $this->page($user);
        [$subscription, $payment] = $this->pendingCheckout($user, $page);
        $subscriptionBefore = $subscription->getRawOriginal();
        $paymentBefore = $payment->getRawOriginal();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/business-pro')->assertOk()
            ->assertJsonPath('data.local_test_access', true)->assertJsonPath('data.has_access', true)
            ->assertJsonPath('data.subscription.status', 'inactive')
            ->assertJsonPath('data.pending_payment.status', 'pending')
            ->assertJsonPath('data.offers.0.features.0.available', true)
            ->assertJsonPath('data.offers.1.features.0.available', true);
        $this->postJson('/api/v1/ads', $this->form())->assertCreated();
        $this->postJson('/api/v1/ads', [...$this->form(), 'page_id' => $page->id])->assertCreated();

        $this->assertSame($subscriptionBefore, $subscription->fresh()->getRawOriginal());
        $this->assertSame($paymentBefore, $payment->fresh()->getRawOriginal());
        $this->assertDatabaseCount('business_pro_subscriptions', 1);
        $this->assertDatabaseCount('business_pro_payments', 1);
        Http::assertNothingSent();
    }

    public function test_mass_assignment_cannot_grant_local_tester_access(): void
    {
        $user = User::factory()->create();
        $user->fill(['business_pro_tester' => true])->save();
        $user->refresh();
        $this->assertFalse($user->business_pro_tester);
        $this->assertFalse(app(BusinessProEntitlementService::class)->hasAccess($user));
        $this->assertArrayNotHasKey('business_pro_tester', $user->toArray());
    }

    private function tester(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->forceFill(['business_pro_tester' => true])->save();

        return $user;
    }

    private function page(User $user, array $attributes = []): Page
    {
        return Page::query()->create([
            'user_id' => $user->id, 'type' => Page::TYPE_BUSINESS, 'name' => 'Local tester business',
            'is_unclaimed' => false, ...$attributes,
        ]);
    }

    private function feature(string $key, array $plans, bool $implemented = true): void
    {
        config()->set('business_pro.features.'.$key, ['implemented' => $implemented, 'plans' => $plans]);
        BusinessProFeature::query()->create([
            'key' => $key, 'labels' => ['en' => $key], 'lifecycle' => 'draft', 'enabled' => false,
        ]);
    }

    private function form(): array
    {
        return ['title' => 'A local tester offer', 'text' => 'Details of the offer', 'is_featured' => true];
    }

    private function ad(User $user, array $attributes = []): Ad
    {
        return Ad::query()->create([
            'user_id' => $user->id, 'type' => Ad::TYPE_PRIVATE, 'title' => 'Local preview ad',
            'text' => 'Details of the offer', 'status' => 'active', 'is_featured' => true, ...$attributes,
        ]);
    }

    private function pendingCheckout(User $user, Page $page): array
    {
        $subscription = new BusinessProSubscription;
        $subscription->forceFill([
            'user_id' => $user->id, 'page_id' => $page->id, 'plan_key' => 'business_pro',
            'status' => 'inactive', 'environment' => 'sandbox', 'terminal_number' => 1000,
            'amount_minor' => 4900, 'currency' => 'ILS', 'interval' => 'monthly',
        ])->save();
        $payment = new BusinessProPayment;
        $payment->forceFill([
            'subscription_id' => $subscription->id, 'user_id' => $user->id, 'page_id' => $page->id,
            'plan_key' => 'business_pro', 'public_id' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(), 'kind' => 'initial', 'status' => 'pending',
            'environment' => 'sandbox', 'terminal_number' => 1000, 'amount_minor' => 4900,
            'currency' => 'ILS', 'checkout_url' => 'https://checkout.example.test/pending',
        ])->save();

        return [$subscription->fresh(), $payment->fresh()];
    }
}
