<?php

namespace Tests\Feature;

use App\Models\Ad;
use App\Models\BusinessProFeature;
use App\Models\BusinessProPayment;
use App\Models\BusinessProSubscription;
use App\Models\Page;
use App\Models\User;
use App\Services\BusinessProEntitlementService;
use App\Services\FeaturedAdService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FeaturedAdsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Queue::fake();
        Http::preventStrayRequests();
        config()->set([
            'business_pro.environment' => 'sandbox',
            'business_pro.cardcom.terminal_number' => 1000,
            'business_pro.features.featured_ads.implemented' => true,
        ]);
    }

    public function test_ordinary_users_see_locked_control_but_cannot_forge_promotion(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/business-pro/ad-feature')->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.required_plans', ['private_pro', 'business_pro']);
        $this->postJson('/api/v1/ads', $this->form(true))->assertStatus(402)
            ->assertJsonPath('data.reason', 'pro_feature_required');
        $this->assertDatabaseCount('ads', 0);
        $id = $this->postJson('/api/v1/ads', $this->form(false))->assertCreated()
            ->assertJsonPath('data.is_featured', false)->json('data.id');
        $this->putJson('/api/v1/ads/'.$id, $this->form(true))->assertStatus(402);
        $this->assertFalse(Ad::findOrFail($id)->is_featured);
    }

    public function test_hiding_offers_preserves_existing_paid_customer_feature_access(): void
    {
        config()->set(['business_pro.rollout' => 'private', 'business_pro.environment' => 'production']);
        foreach (['private_pro', 'business_pro'] as $plan) {
            [$user, $subscription, $page] = $this->paid($plan);
            $user->forceFill(['business_pro_tester' => false])->save();
            $subscription->forceFill(['environment' => 'production'])->save();
            $subscription->payments()->update(['environment' => 'production']);
            Sanctum::actingAs($user);
            $this->getJson('/api/v1/business-pro')->assertNotFound();
            $this->getJson('/api/v1/business-pro/ad-feature'.($page ? '?page_id='.$page->id : ''))
                ->assertOk()->assertJsonPath('data.available', true);
            $id = $this->postJson('/api/v1/ads', [...$this->form(true), 'page_id' => $page?->id])
                ->assertCreated()->assertJsonPath('data.is_featured', true)->json('data.id');
            $this->assertTrue(app(FeaturedAdService::class)->query()->whereKey($id)->exists());
            if ($page) {
                app(BusinessProEntitlementService::class)->assertFeature($user, $page, 'featured_ads');
            }
        }
    }

    public function test_private_pro_can_create_edit_and_disable_private_featured_ads(): void
    {
        [$user] = $this->paid('private_pro');
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/business-pro/ad-feature')->assertOk()->assertJsonPath('data.available', true);
        $id = $this->postJson('/api/v1/ads', $this->form(true))->assertCreated()
            ->assertJsonPath('data.is_featured', true)->assertJsonPath('data.featured_requested', true)->json('data.id');
        $this->getJson('/api/v1/ads/'.$id)->assertOk()->assertJsonPath('data.is_featured', true);
        $this->getJson('/api/v1/ads?scope=mine')->assertOk()->assertJsonPath('data.0.is_featured', true);
        $this->putJson('/api/v1/ads/'.$id, $this->form(false))->assertOk()
            ->assertJsonPath('data.is_featured', false)->assertJsonPath('data.featured_requested', false);
    }

    public function test_business_pro_covers_private_ads_and_only_its_selected_business_page(): void
    {
        [$user, , $page] = $this->paid('business_pro');
        $other = Page::create(['user_id' => $user->id, 'name' => 'Other business', 'type' => Page::TYPE_BUSINESS, 'is_unclaimed' => false]);
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/ads', $this->form(true))->assertCreated()->assertJsonPath('data.is_featured', true);
        $this->postJson('/api/v1/ads', [...$this->form(true), 'page_id' => $page->id])->assertCreated()->assertJsonPath('data.is_featured', true);
        $this->getJson('/api/v1/business-pro/ad-feature?page_id='.$other->id)->assertOk()->assertJsonPath('data.available', false);
        $this->postJson('/api/v1/ads', [...$this->form(true), 'page_id' => $other->id])->assertStatus(402);
        [$private] = $this->paid('private_pro');
        $privatePage = Page::create(['user_id' => $private->id, 'name' => 'Private owner business', 'type' => Page::TYPE_BUSINESS, 'is_unclaimed' => false]);
        Sanctum::actingAs($private);
        $this->postJson('/api/v1/ads', [...$this->form(true), 'page_id' => $privatePage->id])->assertStatus(402);
    }

    public function test_expired_or_disabled_features_lose_public_badge_and_priority_without_deleting_ads(): void
    {
        [$user, $subscription] = $this->paid('private_pro');
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/v1/ads', $this->form(true))->assertCreated()->json('data.id');
        $service = app(FeaturedAdService::class);
        $initial = $service->fingerprint();
        $this->assertSame([$id], $service->query()->pluck('ads.id')->all());
        $this->travel(32)->days();
        // Keep the ad active longer than its normal visibility period to isolate Pro expiry.
        Ad::findOrFail($id)->update(['expires_at' => now()->addDay()]);
        $this->getJson('/api/v1/ads/'.$id)->assertOk()
            ->assertJsonPath('data.is_featured', false)->assertJsonPath('data.featured_requested', true);
        $this->assertNotSame($initial, $service->fingerprint());
        $this->putJson('/api/v1/ads/'.$id, ['title' => 'Normal edit after expiry', 'text' => 'Still a normal ad'])->assertOk()
            ->assertJsonPath('data.is_featured', false);
        $this->putJson('/api/v1/ads/'.$id, $this->form(true))->assertStatus(402);
        $this->travelBack();
        BusinessProFeature::where('key', 'featured_ads')->update(['enabled' => false]);
        $this->assertFalse($service->query()->whereKey($id)->exists());
        $this->getJson('/api/v1/business-pro/ad-feature')->assertOk()->assertJsonPath('data.available', false);
        $this->assertSame('active', $subscription->fresh()->status);
    }

    public function test_cancelled_renewal_keeps_promotion_until_paid_end_but_missing_payment_cannot_grant_it(): void
    {
        [$user, $subscription] = $this->paid('private_pro');
        $subscription->forceFill(['cancel_at_period_end' => true, 'cancelled_at' => now(), 'next_charge_at' => null])->save();
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/business-pro/ad-feature')->assertOk()->assertJsonPath('data.available', true);
        $subscription->payments()->update(['status' => 'pending']);
        $this->getJson('/api/v1/business-pro/ad-feature')->assertOk()->assertJsonPath('data.available', false);
        $this->postJson('/api/v1/ads', $this->form(true))->assertStatus(402);
    }

    public function test_admin_cannot_promote_an_unpaid_owners_ad_using_admins_own_plan(): void
    {
        $owner = User::factory()->create();
        $ad = Ad::create(['user_id' => $owner->id, 'type' => Ad::TYPE_PRIVATE, 'title' => 'Owned by unpaid member', 'text' => 'Details', 'status' => 'active']);
        [$admin] = $this->paid('private_pro');
        $admin->update(['role' => 'admin']);
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/business-pro/ad-feature?ad_id='.$ad->id)->assertOk()->assertJsonPath('data.available', false);
        $this->putJson('/api/v1/ads/'.$ad->id, $this->form(true))->assertStatus(402);
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/business-pro/ad-feature?ad_id='.$ad->id)->assertNotFound();
        $this->putJson('/api/v1/ads/'.$ad->id, $this->form(false))->assertForbidden();
    }

    public function test_database_flag_does_not_promote_banned_hidden_expired_or_wrong_environment_ads(): void
    {
        [$user, $subscription] = $this->paid('private_pro');
        $ad = Ad::create(['user_id' => $user->id, 'type' => Ad::TYPE_PRIVATE, 'title' => 'Flag alone is insufficient', 'text' => 'Details', 'status' => 'active', 'is_featured' => true]);
        $query = fn () => app(FeaturedAdService::class)->query()->whereKey($ad->id)->exists();
        $this->assertTrue($query());
        $user->forceFill(['banned_at' => now()])->save();
        $this->assertFalse($query());
        $user->forceFill(['banned_at' => null])->save();
        $ad->forceFill(['community_hidden_at' => now()])->save();
        $this->assertFalse($query());
        $ad->forceFill(['community_hidden_at' => null, 'expires_at' => now()->subSecond()])->save();
        $this->assertFalse($query());
        $ad->update(['expires_at' => null]);
        $subscription->forceFill(['environment' => 'production', 'terminal_number' => 999])->save();
        $this->assertFalse($query());
    }

    private function form(bool $featured): array
    {
        return ['title' => 'A useful local offer', 'text' => 'Details of the offer', 'is_featured' => $featured];
    }

    private function paid(string $plan): array
    {
        $user = User::factory()->create();
        $user->forceFill(['business_pro_tester' => true])->save();
        $page = $plan === 'business_pro' ? Page::create(['user_id' => $user->id, 'name' => 'Covered business', 'type' => Page::TYPE_BUSINESS, 'is_unclaimed' => false]) : null;
        $subscription = BusinessProSubscription::forceCreate([
            'user_id' => $user->id, 'page_id' => $page?->id, 'plan_key' => $plan, 'included_pages' => $page ? 1 : 0,
            'status' => 'active', 'environment' => 'sandbox', 'terminal_number' => 1000,
            'amount_minor' => $page ? 4900 : 1900, 'currency' => 'ILS', 'interval' => 'monthly',
            'current_period_start' => now()->subDay(), 'current_period_end' => now()->addMonth(),
        ]);
        $payment = BusinessProPayment::forceCreate([
            'public_id' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid(),
            'subscription_id' => $subscription->id, 'user_id' => $user->id, 'page_id' => $page?->id,
            'plan_key' => $plan, 'kind' => 'initial', 'status' => 'paid', 'environment' => 'sandbox',
            'terminal_number' => 1000, 'amount_minor' => $subscription->amount_minor, 'currency' => 'ILS',
            'period_start' => $subscription->current_period_start, 'period_end' => $subscription->current_period_end, 'paid_at' => now()->subDay(),
        ]);
        $subscription->forceFill(['last_payment_id' => $payment->id])->save();

        return [$user, $subscription, $page];
    }
}
