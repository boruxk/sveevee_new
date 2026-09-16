<?php

namespace Tests\Feature;

use App\Models\BusinessImportClient;
use App\Models\BusinessImportSource;
use App\Models\Page;
use App\Models\PageIdentityKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\TestCase;

class BusinessImportIdentityLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $client = Client::factory()->asClientCredentials()->create();
        $scopes = [BusinessImportClient::SCOPE_READ, BusinessImportClient::SCOPE_WRITE];
        BusinessImportClient::create(['oauth_client_id' => $client->getKey(), 'name' => 'Identity lookup test', 'allowed_scopes' => $scopes, 'active' => true]);
        Passport::actingAsClient($client, $scopes);
    }

    public function test_known_page_lookup_does_not_scan_or_backfill_unrelated_pages(): void
    {
        $page = $this->page('Known linked business');
        $other = $this->page('Unrelated legacy business');
        $other->identityKey()->delete();
        $before = $page->fresh()->getAttributes();
        DB::enableQueryLog();
        try {
            $this->getJson('/api/v1/business-import/businesses?id='.$page->id.'&per_page=1')
                ->assertOk()->assertJsonCount(1, 'data.businesses')
                ->assertJsonPath('data.businesses.0.id', $page->id)
                ->assertJsonPath('data.businesses.0.can_update', true);
            $queries = collect(DB::getQueryLog())->pluck('query');
        } finally {
            DB::disableQueryLog();
        }
        $this->assertFalse($queries->contains(fn (string $query): bool => str_contains(strtolower($query), 'not exists') && str_contains($query, 'page_identity_keys')), $queries->implode("\n"));
        $this->assertDatabaseMissing('page_identity_keys', ['page_id' => $other->id]);
        $this->assertSame($before, $page->fresh()->getAttributes());
    }

    public function test_missing_identity_key_is_repaired_only_for_the_requested_page_before_filtering(): void
    {
        $page = $this->page('Requested legacy business');
        $other = $this->page('Unrelated legacy business');
        PageIdentityKey::query()->delete();
        $before = $page->fresh()->getAttributes();
        $query = http_build_query(['id' => $page->id, 'city' => 'Tel Aviv', 'phone' => '03-5555555', 'per_page' => 1]);
        $this->getJson('/api/v1/business-import/businesses?'.$query)->assertOk()
            ->assertJsonCount(1, 'data.businesses')->assertJsonPath('data.businesses.0.id', $page->id);
        $this->assertDatabaseHas('page_identity_keys', ['page_id' => $page->id, 'normalized_city' => 'tel aviv', 'normalized_phone' => '97235555555']);
        $this->assertDatabaseMissing('page_identity_keys', ['page_id' => $other->id]);
        $this->assertSame($before, $page->fresh()->getAttributes());
    }

    public function test_missing_id_does_not_repair_other_pages_and_claimed_lookup_preserves_write_protection(): void
    {
        $page = $this->page('Owned business', true);
        $page->identityKey()->delete();
        $this->getJson('/api/v1/business-import/businesses?id='.($page->id + 1000).'&per_page=1')
            ->assertOk()->assertJsonCount(0, 'data.businesses')->assertJsonPath('data.pagination.total', 0);
        $this->assertDatabaseMissing('page_identity_keys', ['page_id' => $page->id]);
        $this->getJson('/api/v1/business-import/businesses?id='.$page->id.'&per_page=1')
            ->assertOk()->assertJsonPath('data.businesses.0.id', $page->id)
            ->assertJsonPath('data.businesses.0.can_update', false)
            ->assertJsonPath('data.businesses.0.is_unclaimed', false);
        $this->assertDatabaseHas('page_identity_keys', ['page_id' => $page->id]);
    }

    public function test_non_id_identity_search_still_repairs_legacy_keys_and_finds_matching_pages(): void
    {
        $page = $this->page('Legacy contact search');
        $page->identityKey()->delete();
        $this->getJson('/api/v1/business-import/businesses?'.http_build_query(['phone' => '03-5555555']))
            ->assertOk()->assertJsonCount(1, 'data.businesses')->assertJsonPath('data.businesses.0.id', $page->id);
        $this->assertDatabaseHas('page_identity_keys', ['page_id' => $page->id]);
    }

    public function test_overture_lookup_and_batch_update_never_backfill_the_unrelated_catalog(): void
    {
        $page = $this->page('Linked Overture branch');
        $other = $this->page('Other legacy branch');
        $other->identityKey()->delete();
        $source = ['provider' => 'overture_places', 'id' => 'indexed-source',
            'url' => 'https://explore.overturemaps.org/?gers=indexed-source',
            'metadata' => ['overture_id' => 'indexed-source', 'release' => '2026-08-19.0']];
        BusinessImportSource::create(['page_id' => $page->id, 'provider' => $source['provider'],
            'source_id' => $source['id'], 'url' => $source['url'], 'metadata' => $source['metadata']]);
        DB::enableQueryLog();
        try {
            $this->postJson('/api/v1/business-import/businesses/duplicates', ['name' => $page->name, 'source' => $source])
                ->assertOk()->assertJsonPath('data.matches.0.id', $page->id);
            $this->getJson('/api/v1/business-import/businesses?id='.$page->id.'&per_page=1')
                ->assertOk()->assertJsonPath('data.businesses.0.id', $page->id);
            $this->postJson('/api/v1/business-import/businesses/batch', [
                'client_import_id' => (string) Str::uuid(),
                'businesses' => [['id' => $page->id, 'source' => $source, 'phone' => '03-5555556']],
            ])->assertCreated()->assertJsonPath('data.updated_count', 1)
                ->assertJsonPath('data.items.0.business.id', $page->id)
                ->assertJsonPath('data.items.0.business.phone', '03-5555556');
            $queries = collect(DB::getQueryLog())->pluck('query');
        } finally {
            DB::disableQueryLog();
        }
        $this->assertFalse($queries->contains(fn (string $query): bool => str_contains(strtolower($query), 'not exists') && str_contains($query, 'page_identity_keys')), $queries->implode("\n"));
        $this->assertDatabaseMissing('page_identity_keys', ['page_id' => $other->id]);
        $this->assertDatabaseHas('page_identity_keys', ['page_id' => $page->id, 'normalized_phone' => '97235555556']);
        $this->assertDatabaseCount('pages', 2);
        $this->assertSame($page->id, BusinessImportSource::query()->sole()->page_id);
    }

    private function page(string $name, bool $claimed = false): Page
    {
        $owner = $claimed ? User::factory()->create() : User::where('role', 'ai_worker')->firstOrFail();

        return Page::create(['user_id' => $owner->id, 'created_by_user_id' => $owner->id,
            'type' => Page::TYPE_BUSINESS, 'is_unclaimed' => ! $claimed, 'name' => $name,
            'phone' => '03-5555555', 'setup' => ['address' => ['city' => 'Tel Aviv', 'street' => 'Example Street', 'number' => '1']]]);
    }
}
