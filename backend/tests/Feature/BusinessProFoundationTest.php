<?php

namespace Tests\Feature;

use App\Models\BusinessProFeature;
use App\Models\BusinessProPayment;
use App\Models\BusinessProSubscription;
use App\Models\Page;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\BusinessProEntitlementService;
use App\Services\PayloadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BusinessProFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set([
            'business_pro.rollout' => 'private',
            'business_pro.environment' => 'sandbox',
            'business_pro.cardcom.terminal_number' => 1000,
            'business_pro.amount_minor' => 4900,
            'business_pro.billing_enabled' => true,
            'business_pro.features' => ['demo_tool' => ['implemented' => true]],
        ]);
        Route::middleware(['api', 'auth:sanctum', 'business-pro.feature:demo_tool'])
            ->post('/api/v1/test-business-pro/pages/{page}', fn (Page $page) => ApiResponseService::success(['page_id' => $page->id]));
    }

    public function test_private_rollout_returns_404_and_no_metadata_for_other_accounts(): void
    {
        $user = User::factory()->create();
        $page = $this->page($user);
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/business-pro')->assertNotFound();
        $this->getJson("/api/v1/business-pro/pages/{$page->id}")->assertNotFound();
        $this->postJson("/api/v1/test-business-pro/pages/{$page->id}")->assertNotFound();
        $this->assertArrayNotHasKey('business_pro_preview', app(PayloadService::class)->user($user, true));
        $this->assertArrayNotHasKey('business_pro_tester', $user->toArray());
        $this->getJson('/api/v1/admin/business-pro/subscriptions')->assertForbidden();
    }

    public function test_tester_flag_is_private_and_does_not_grant_paid_features(): void
    {
        $user = $this->tester();
        $page = $this->page($user);
        $this->feature();
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/business-pro')->assertOk()
            ->assertJsonPath('data.private_preview', true)
            ->assertJsonPath('data.offer.amount_minor', 4900)->assertJsonPath('data.offer.included_pages', 1)
            ->assertJsonPath('data.has_access', false)->assertJsonPath('data.features.0.available', false)
            ->assertJsonPath('data.features.0.locked_reason', 'subscription_required');
        $this->postJson("/api/v1/test-business-pro/pages/{$page->id}")->assertStatus(402);
        $this->assertTrue(app(PayloadService::class)->user($user, true)['business_pro_preview']);
        $this->assertArrayNotHasKey('business_pro_preview', app(PayloadService::class)->user($user, false));
        $this->assertArrayNotHasKey('business_pro_tester', $user->toArray());
    }

    public function test_admin_can_inspect_offer_but_cannot_execute_features_without_payment(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $page = $this->page($admin);
        $this->feature();
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/business-pro/features')->assertOk();
        $this->postJson("/api/v1/test-business-pro/pages/{$page->id}")->assertStatus(402);
    }

    public function test_paid_tester_can_execute_draft_only_when_implemented_and_enabled(): void
    {
        $user = $this->tester();
        $page = $this->page($user);
        $feature = $this->feature();
        $this->paid($user, $page);
        Sanctum::actingAs($user);
        $this->postJson("/api/v1/test-business-pro/pages/{$page->id}")->assertOk();
        $feature->update(['enabled' => false]);
        $this->postJson("/api/v1/test-business-pro/pages/{$page->id}")->assertForbidden();
        $feature->update(['enabled' => true]);
        config()->set('business_pro.features.demo_tool.implemented', false);
        $this->postJson("/api/v1/test-business-pro/pages/{$page->id}")->assertForbidden();
    }

    public function test_status_without_verified_matching_payment_never_grants_access(): void
    {
        $user = $this->tester();
        $page = $this->page($user);
        $subscription = $this->paid($user, $page);
        $payment = $subscription->payments()->firstOrFail();
        $payment->forceFill(['status' => 'pending'])->save();
        $this->assertFalse($this->access($user, $page));
        $payment->forceFill(['status' => 'paid', 'amount_minor' => 1])->save();
        $this->assertFalse($this->access($user, $page));
        $payment->forceFill(['amount_minor' => 4900, 'terminal_number' => 1001])->save();
        $this->assertFalse($this->access($user, $page));
        $payment->forceFill(['terminal_number' => 1000, 'period_end' => now()->subDay()])->save();
        $this->assertFalse($this->access($user, $page));
    }

    public function test_expiry_environment_and_owner_transfer_revoke_entitlement(): void
    {
        $user = $this->tester();
        $page = $this->page($user);
        $subscription = $this->paid($user, $page);
        $this->assertTrue($this->access($user, $page));
        config()->set('business_pro.cardcom.terminal_number', 1001);
        $this->assertFalse($this->access($user, $page));
        config()->set('business_pro.cardcom.terminal_number', 1000);
        config()->set('business_pro.environment', 'production');
        $this->assertFalse($this->access($user, $page));
        config()->set('business_pro.environment', 'sandbox');
        $subscription->forceFill(['current_period_end' => now()->subSecond()])->save();
        $this->assertFalse($this->access($user, $page));
        $subscription->forceFill(['current_period_end' => now()->addDay()])->save();
        $buyer = $this->tester();
        $page->update(['user_id' => $buyer->id]);
        $this->assertFalse($this->access($user, $page));
        $this->assertFalse($this->access($buyer, $page));
    }

    public function test_cancellation_keeps_paid_access_only_until_period_end(): void
    {
        $user = $this->tester();
        $page = $this->page($user);
        $subscription = $this->paid($user, $page);
        $subscription->forceFill(['status' => 'cancelled', 'cancel_at_period_end' => true, 'cancelled_at' => now()])->save();
        $this->assertTrue($this->access($user, $page));
        $this->travel(32)->days();
        $this->assertFalse($this->access($user, $page));
    }

    public function test_user_cannot_access_someone_elses_page_or_community_or_unclaimed_business(): void
    {
        $user = $this->tester();
        $other = $this->page($this->tester());
        $community = $this->page($user, ['type' => 'community']);
        $unclaimed = $this->page($user, ['is_unclaimed' => true]);
        Sanctum::actingAs($user);
        foreach ([$other, $community, $unclaimed] as $page) {
            $this->getJson("/api/v1/business-pro/pages/{$page->id}")->assertNotFound();
        }
    }

    public function test_paid_subscription_is_limited_to_selected_business_page(): void
    {
        $user = $this->tester();
        $page = $this->page($user);
        $subscription = $this->paid($user, $page);
        $subscription->forceFill(['page_id' => null])->save();
        $this->assertFalse($this->access($user, $page));
        $subscription->forceFill(['page_id' => $page->id])->save();
        $page->update(['is_unclaimed' => true]);
        $this->assertFalse($this->access($user, $page));
    }

    public function test_public_rollout_still_hides_draft_features_and_rejects_sandbox_entitlement_for_ordinary_users(): void
    {
        $user = $this->tester();
        $page = $this->page($user);
        $this->paid($user, $page);
        $this->feature();
        $user->forceFill(['business_pro_tester' => false])->save();
        config()->set('business_pro.rollout', 'public');
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/business-pro')->assertOk()->assertJsonPath('data.private_preview', false)
            ->assertJsonPath('data.features', [])->assertJsonPath('data.has_access', false);
        $this->postJson("/api/v1/test-business-pro/pages/{$page->id}")->assertNotFound();
    }

    public function test_admin_price_changes_new_offer_without_modifying_existing_paid_contract(): void
    {
        $user = $this->tester();
        $subscription = $this->paid($user, $this->page($user));
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->patchJson('/api/v1/admin/business-pro/offer', ['amount_minor' => 6900, 'currency' => 'USD'])
            ->assertOk()->assertJsonPath('data.amount_minor', 6900)->assertJsonPath('data.currency', 'ILS');
        $this->assertSame(4900, $subscription->fresh()->amount_minor);
        $this->patchJson('/api/v1/admin/business-pro/offer', ['amount_minor' => -1])->assertUnprocessable();
        Sanctum::actingAs($user);
        $this->patchJson('/api/v1/admin/business-pro/offer', ['amount_minor' => 100])->assertForbidden();
    }

    public function test_admin_lists_are_paginated_and_never_expose_provider_tokens(): void
    {
        $user = $this->tester();
        $subscription = $this->paid($user, $this->page($user));
        $subscription->forceFill(['provider_token' => 'sensitive-provider-token'])->save();
        $this->assertNotSame('sensitive-provider-token', $subscription->getRawOriginal('provider_token'));
        $this->assertSame('sensitive-provider-token', $subscription->fresh()->provider_token);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/v1/admin/business-pro/subscriptions?per_page=1')->assertOk()
            ->assertJsonPath('data.pagination.per_page', 1)->assertJsonMissingPath('data.items.0.provider_token')
            ->assertJsonMissingPath('data.items.0.provider_customer_id');
        $this->getJson('/api/v1/admin/business-pro/payments?per_page=1')->assertOk()
            ->assertJsonMissingPath('data.items.0.metadata')->assertJsonMissingPath('data.items.0.checkout_url')
            ->assertJsonMissingPath('data.items.0.provider_transaction_id');
    }

    public function test_test_flags_and_paid_status_are_not_mass_assignable(): void
    {
        $user = User::factory()->create();
        $user->fill(['business_pro_tester' => true])->save();
        $this->assertFalse($user->fresh()->business_pro_tester);
        $this->assertSame([], (new BusinessProSubscription)->getFillable());
        $this->assertSame(['*'], (new BusinessProSubscription)->getGuarded());
    }

    public function test_tester_command_is_idempotent_and_never_promotes_existing_users(): void
    {
        $this->artisan('business-pro:create-tester')->assertSuccessful();
        $tester = User::query()->where('email', 'pro@sveevee.local')->firstOrFail();
        $this->assertTrue($tester->business_pro_tester);
        $this->assertTrue(Hash::check('password', $tester->password));
        $this->assertSame('user', $tester->role);
        $this->assertNotNull($tester->email_verified_at);
        $this->assertSame('Jerusalem', $tester->profile->city);
        $this->artisan('business-pro:create-tester', ['--password' => 'different-password'])->assertSuccessful();
        $this->assertTrue(Hash::check('password', $tester->fresh()->password));
        $this->assertDatabaseCount('pages', 0);
        $this->assertDatabaseCount('business_pro_subscriptions', 0);
        $existing = User::factory()->create();
        $this->artisan('business-pro:create-tester', ['--email' => $existing->email])->assertFailed();
        $this->assertFalse($existing->fresh()->business_pro_tester);
    }

    public function test_production_test_account_creation_requires_explicit_secure_credentials(): void
    {
        $existingUsers = User::query()->count();
        $this->app['env'] = 'production';
        $this->artisan('business-pro:create-tester')->assertFailed();
        $this->artisan('business-pro:create-tester', ['--allow-production' => true, '--password' => 'password'])->assertFailed();
        $this->assertDatabaseCount('users', $existingUsers);
        $this->app['env'] = 'testing';
    }

    private function tester(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['business_pro_tester' => true])->save();

        return $user;
    }

    private function page(User $user, array $extra = []): Page
    {
        return Page::query()->create(['user_id' => $user->id, 'type' => 'business', 'name' => 'Pro Test Business', 'is_unclaimed' => false, ...$extra]);
    }

    private function feature(): BusinessProFeature
    {
        return BusinessProFeature::query()->create(['key' => 'demo_tool', 'labels' => ['en' => 'Test tool'], 'lifecycle' => 'draft', 'enabled' => true]);
    }

    private function paid(User $user, Page $page): BusinessProSubscription
    {
        $subscription = new BusinessProSubscription;
        $subscription->forceFill([
            'user_id' => $user->id, 'page_id' => $page->id, 'status' => 'active', 'environment' => 'sandbox',
            'terminal_number' => 1000, 'amount_minor' => 4900, 'currency' => 'ILS', 'interval' => 'monthly',
            'current_period_start' => now()->subHour(), 'current_period_end' => now()->addMonth(),
        ])->save();
        $payment = new BusinessProPayment;
        $payment->forceFill([
            'subscription_id' => $subscription->id, 'user_id' => $user->id, 'page_id' => $page->id,
            'public_id' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid(), 'kind' => 'initial',
            'status' => 'paid', 'environment' => 'sandbox', 'terminal_number' => 1000, 'amount_minor' => 4900,
            'currency' => 'ILS', 'paid_at' => now(), 'period_start' => $subscription->current_period_start,
            'period_end' => $subscription->current_period_end,
        ])->save();

        $subscription->forceFill(['last_payment_id' => $payment->id])->save();

        return $subscription;
    }

    private function access(User $user, Page $page): bool
    {
        return app(BusinessProEntitlementService::class)->hasAccess($user, $page);
    }
}
