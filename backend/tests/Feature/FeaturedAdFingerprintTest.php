<?php

namespace Tests\Feature;

use App\Models\Ad;
use App\Models\BusinessProPayment;
use App\Models\BusinessProSubscription;
use App\Models\User;
use App\Services\FeaturedAdService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class FeaturedAdFingerprintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Queue::fake();
        config(['business_pro.environment' => 'sandbox', 'business_pro.cardcom.terminal_number' => 1000,
            'business_pro.features.featured_ads.implemented' => true]);
    }

    public function test_large_featured_inventory_uses_one_scalar_query_and_never_hydrates_ad_models(): void
    {
        [$owner] = $this->subscriber();
        $rows = array_fill(0, 1201, $this->adValues($owner));
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('ads')->insert($chunk);
        }
        $hydrated = 0;
        Ad::retrieved(function () use (&$hydrated): void {
            $hydrated++;
        });
        DB::enableQueryLog();
        DB::flushQueryLog();
        $fingerprint = app(FeaturedAdService::class)->fingerprint();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
        $this->assertSame(0, $hydrated);
        $this->assertSame(64, strlen($fingerprint));
        $this->assertSame($fingerprint, app(FeaturedAdService::class)->fingerprint());
    }

    public function test_fingerprint_changes_when_same_size_id_set_changes_and_when_paid_access_expires(): void
    {
        [$owner, $subscription] = $this->subscriber();
        $service = app(FeaturedAdService::class);
        $empty = $service->fingerprint();
        $ads = collect(range(1, 4))->map(fn () => Ad::query()->create($this->adValues($owner)));
        $ads[1]->update(['is_featured' => false]);
        $ads[2]->update(['is_featured' => false]);
        $outsidePair = $service->fingerprint();
        $this->assertNotSame($empty, $outsidePair);

        // Both pairs have the same count and ID sum; replacing the identities
        // must still invalidate an existing pagination cursor.
        $ads[0]->update(['is_featured' => false]);
        $ads[3]->update(['is_featured' => false]);
        $ads[1]->update(['is_featured' => true]);
        $ads[2]->update(['is_featured' => true]);
        $insidePair = $service->fingerprint();
        $this->assertNotSame($outsidePair, $insidePair);
        $this->assertSame($insidePair, $service->fingerprint());

        $subscription->forceFill(['current_period_end' => now()])->save();
        $this->assertSame($empty, $service->fingerprint());
    }

    private function adValues(User $owner): array
    {
        return ['user_id' => $owner->id, 'type' => Ad::TYPE_PRIVATE, 'title' => 'Featured local offer',
            'text' => 'Useful details', 'status' => 'active', 'is_featured' => true,
            'created_at' => now(), 'updated_at' => now()];
    }

    private function subscriber(): array
    {
        $user = User::factory()->create(['business_pro_tester' => true]);
        $subscription = BusinessProSubscription::query()->forceCreate([
            'user_id' => $user->id, 'plan_key' => 'private_pro', 'page_id' => null, 'included_pages' => 0,
            'status' => 'active', 'environment' => 'sandbox', 'terminal_number' => 1000, 'amount_minor' => 1900,
            'currency' => 'ILS', 'current_period_start' => now()->subDay(), 'current_period_end' => now()->addMonth(),
        ]);
        $payment = BusinessProPayment::query()->forceCreate([
            'public_id' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid(),
            'subscription_id' => $subscription->id, 'user_id' => $user->id, 'page_id' => null, 'plan_key' => 'private_pro',
            'kind' => 'initial', 'status' => 'paid', 'environment' => 'sandbox', 'terminal_number' => 1000,
            'amount_minor' => 1900, 'currency' => 'ILS', 'paid_at' => now()->subDay(),
            'period_start' => $subscription->current_period_start, 'period_end' => $subscription->current_period_end,
        ]);
        $subscription->forceFill(['last_payment_id' => $payment->id])->save();

        return [$user, $subscription];
    }
}
