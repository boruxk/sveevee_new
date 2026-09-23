<?php

namespace Tests\Feature;

use App\Models\Ad;
use App\Models\BusinessProFeature;
use App\Models\BusinessProPayment;
use App\Models\BusinessProSubscription;
use App\Models\LocalQuestion;
use App\Models\Page;
use App\Models\PublicComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class FeaturedAdOrderingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-23 12:00:00'));
        Queue::fake();
        config(['business_pro.environment' => 'sandbox', 'business_pro.cardcom.terminal_number' => 1000,
            'business_pro.features.featured_ads.implemented' => true]);
        BusinessProFeature::query()->updateOrCreate(['key' => 'featured_ads'], [
            'labels' => ['en' => 'Featured ads'], 'enabled' => true, 'lifecycle' => 'published',
        ]);
    }

    public function test_search_prioritizes_paid_featured_ads_without_bypassing_text_location_or_visibility_filters(): void
    {
        [$pro] = $this->subscriber();
        $ordinary = User::factory()->create();
        $promoted = $this->ad($pro, ['title' => 'Piano lessons', 'is_featured' => true, 'created_at' => now()->subDays(10)]);
        $normal = $this->ad($ordinary, ['title' => 'Piano lessons']);
        $unpaid = $this->ad($ordinary, ['title' => 'Piano lessons', 'is_featured' => true, 'created_at' => now()->subDay()]);
        $this->ad($pro, ['title' => 'Piano lessons', 'city' => 'Haifa', 'is_featured' => true]);
        $this->ad($pro, ['title' => 'Guitar lessons', 'is_featured' => true]);
        $this->ad($pro, ['title' => 'Piano lessons', 'is_featured' => true, 'expires_at' => now()->subMinute()]);
        $hidden = $this->ad($pro, ['title' => 'Piano lessons', 'is_featured' => true]);
        $hidden->forceFill(['community_hidden_at' => now()])->save();
        $response = $this->getJson('/api/v1/search?'.http_build_query(['q' => 'piano', 'city' => 'Jerusalem', 'scope' => 'ads']))->assertOk();
        $this->assertSame([$promoted->id, $normal->id, $unpaid->id], array_column($response->json('data.ads'), 'id'));
        $this->assertSame([true, false, false], array_column($response->json('data.ads'), 'is_featured'));
    }

    public function test_discovery_keeps_location_tiers_before_featured_priority_and_cursor_matches_legacy_pages(): void
    {
        [$pro] = $this->subscriber();
        $localFeatured = $this->ad($pro, ['is_featured' => true, 'created_at' => now()->subDays(10)]);
        $localPages = $this->pages($pro, 23, 'Ramot');
        $cityFeatured = $this->ad($pro, ['is_featured' => true, 'neighborhood' => 'Gilo', 'created_at' => now()->subDays(10)]);
        $cityPages = $this->pages($pro, 23, 'Gilo');
        $elsewhere = $this->ad($pro, ['is_featured' => true, 'city' => 'Haifa']);
        $preferences = ['preferred_city' => 'Jerusalem', 'preferred_neighborhood' => 'Ramot'];
        $first = $this->discovery($preferences)->assertOk()->assertJsonPath('data.pagination.total', 49);
        $this->assertSame([$localFeatured->id], array_column($first->json('data.ads'), 'id'));
        $this->assertSame(array_slice(array_reverse($localPages), 0, 19), array_column($first->json('data.pages'), 'id'));
        $second = $this->discovery([...$preferences, 'cursor' => $first->json('data.pagination.next_cursor')])->assertOk();
        $legacy = $this->discovery([...$preferences, 'page' => 2])->assertOk();
        $this->assertSame($second->json('data.pages'), $legacy->json('data.pages'));
        $this->assertSame($second->json('data.ads'), $legacy->json('data.ads'));
        $this->assertSame([$cityFeatured->id], array_column($second->json('data.ads'), 'id'));
        $this->assertSame([...array_slice(array_reverse($localPages), 19), ...array_slice(array_reverse($cityPages), 0, 15)], array_column($second->json('data.pages'), 'id'));
        $third = $this->discovery([...$preferences, 'cursor' => $second->json('data.pagination.next_cursor')])->assertOk()
            ->assertJsonPath('data.pagination.has_more', false);
        $this->assertSame([$elsewhere->id], array_column($third->json('data.ads'), 'id'));
        $this->assertSame(array_slice(array_reverse($cityPages), 15), array_column($third->json('data.pages'), 'id'));
    }

    public function test_nearby_paginates_featured_ads_then_preserves_reply_activity_and_creation_ties(): void
    {
        [$pro] = $this->subscriber();
        $featured = [];
        foreach (range(1, 23) as $number) {
            $featured[] = 'ad:'.$this->ad($pro, ['is_featured' => true, 'created_at' => now()->subDays(10)])->id;
        }
        $answered = $this->question($pro, now()->subDays(3));
        $newer = $this->question($pro, now()->subHour());
        PublicComment::create(['target_type' => 'question', 'target_id' => $answered->id, 'user_id' => $pro->id, 'body' => 'Recent useful reply']);
        $normal = [];
        foreach (range(1, 22) as $number) {
            $normal[] = 'ad:'.$this->ad($pro, ['created_at' => now()->subHours(2)])->id;
        }
        $this->ad($pro, ['is_featured' => true, 'city' => 'Haifa']);
        $expected = [...array_reverse($featured), 'question:'.$answered->id, 'question:'.$newer->id, ...array_reverse($normal)];
        $hydrated = 0;
        Ad::retrieved(function () use (&$hydrated): void {
            $hydrated++;
        });
        $current = $this->getJson('/api/v1/nearby?city=Jerusalem')->assertOk()->assertJsonCount(20, 'data.items');
        $this->assertLessThanOrEqual(21, $hydrated, 'The fingerprint reads only thin IDs; candidates hydrate at most 21 ads.');
        $seen = $this->nearbyIds($current);
        while ($current->json('data.has_more')) {
            $current = $this->getJson('/api/v1/nearby?'.http_build_query(['city' => 'Jerusalem', 'cursor' => $current->json('data.next_cursor')]))->assertOk();
            $seen = [...$seen, ...$this->nearbyIds($current)];
        }
        $this->assertSame($expected, $seen);
        $this->assertSame(count($seen), count(array_unique($seen)));
    }

    public function test_changed_featured_eligibility_invalidates_both_cursors_and_refresh_removes_expired_priority(): void
    {
        [$pro, $subscription] = $this->subscriber();
        $featured = $this->ad($pro, ['is_featured' => true, 'created_at' => now()->subDays(10)]);
        foreach (range(1, 22) as $number) {
            $this->ad($pro);
        }
        $nearby = $this->getJson('/api/v1/nearby')->assertOk()->assertJsonPath('data.items.0.id', $featured->id);
        $search = $this->discovery()->assertOk()->assertJsonPath('data.ads.0.id', $featured->id);
        $subscription->forceFill(['current_period_end' => now()])->save();
        $this->getJson('/api/v1/nearby?'.http_build_query(['cursor' => $nearby->json('data.next_cursor')]))->assertUnprocessable()->assertJsonValidationErrors('cursor');
        $this->discovery(['cursor' => $search->json('data.pagination.next_cursor')])->assertUnprocessable()->assertJsonValidationErrors('cursor');
        $fresh = $this->getJson('/api/v1/nearby')->assertOk();
        $this->assertNotContains('ad:'.$featured->id, $this->nearbyIds($fresh));
        $this->assertSame([false], array_values(array_unique(array_column(array_column($fresh->json('data.items'), 'value'), 'is_featured'))));
        $this->assertNotContains($featured->id, array_column($this->discovery()->assertOk()->json('data.ads'), 'id'));
    }

    public function test_disabling_feature_removes_boost_and_promotion_toggle_invalidates_cursor(): void
    {
        [$pro] = $this->subscriber();
        $featured = $this->ad($pro, ['is_featured' => true, 'created_at' => now()->subDays(10)]);
        foreach (range(1, 22) as $number) {
            $this->ad($pro);
        }
        $first = $this->getJson('/api/v1/nearby')->assertOk();
        $featured->update(['is_featured' => false]);
        $this->getJson('/api/v1/nearby?'.http_build_query(['cursor' => $first->json('data.next_cursor')]))->assertUnprocessable();
        $featured->update(['is_featured' => true]);
        BusinessProFeature::query()->where('key', 'featured_ads')->update(['enabled' => false]);
        $fresh = $this->getJson('/api/v1/nearby')->assertOk();
        $this->assertNotContains('ad:'.$featured->id, $this->nearbyIds($fresh));
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

    private function ad(User $owner, array $overrides = []): Ad
    {
        return Ad::query()->forceCreate(['user_id' => $owner->id, 'type' => Ad::TYPE_PRIVATE, 'title' => 'Local offer',
            'text' => 'Useful local offer', 'status' => 'active', 'city' => 'Jerusalem', 'neighborhood' => 'Ramot', ...$overrides]);
    }

    private function pages(User $owner, int $count, string $neighborhood): array
    {
        $ids = [];
        foreach (range(1, $count) as $number) {
            $ids[] = Page::create(['user_id' => $owner->id, 'type' => Page::TYPE_BUSINESS, 'name' => 'Local business '.$number,
                'setup' => ['address' => ['city' => 'Jerusalem', 'neighborhood' => $neighborhood]]])->id;
        }

        return $ids;
    }

    private function question(User $owner, Carbon $created): LocalQuestion
    {
        return LocalQuestion::query()->forceCreate(['user_id' => $owner->id, 'title' => 'Local question', 'body' => 'Looking for advice nearby.',
            'city' => 'Jerusalem', 'neighborhood' => 'Ramot', 'category_key' => 'professionals.electricians', 'created_at' => $created]);
    }

    private function discovery(array $params = []): TestResponse
    {
        return $this->getJson('/api/v1/search?'.http_build_query(['discover' => 1, ...$params]));
    }

    private function nearbyIds(TestResponse $response): array
    {
        return array_map(fn ($item) => $item['type'].':'.$item['id'], $response->json('data.items'));
    }
}
