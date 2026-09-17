<?php

namespace Tests\Feature;

use App\Models\Ad;
use App\Models\Page;
use App\Models\PageEvent;
use App\Models\PageProduct;
use App\Models\PageService;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SearchPaginationPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-17 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_deep_cursor_loads_only_the_final_twenty_page_models_and_bounds_ordered_candidate_queries(): void
    {
        $ids = $this->pages(105);
        $response = $this->discover()->assertOk();
        $earlierIds = $this->ids($response, 'pages');
        for ($page = 2; $page <= 4; $page++) {
            $response = $this->next($response)->assertOk();
            $earlierIds = [...$earlierIds, ...$this->ids($response, 'pages')];
        }

        $hydrated = [];
        $queries = [];
        $recording = true;
        Page::retrieved(function (Page $page) use (&$hydrated, &$recording): void {
            if ($recording && array_key_exists('setup', $page->getAttributes())) {
                $hydrated[] = $page->id;
            }
        });
        DB::listen(function (QueryExecuted $query) use (&$queries, &$recording): void {
            if ($recording) {
                $queries[] = $query->sql;
            }
        });
        try {
            $fifth = $this->next($response)->assertOk()
                ->assertJsonPath('data.pagination.current_page', 5)
                ->assertJsonPath('data.pagination.total', 105)
                ->assertJsonCount(20, 'data.pages');
        } finally {
            $recording = false;
        }
        $displayed = $this->ids($fifth, 'pages');
        $this->assertSame(array_slice(array_reverse($ids), 80, 20), $displayed);
        $this->assertSame([], array_values(array_intersect($earlierIds, $displayed)));
        $this->assertCount(20, $hydrated, 'Only displayed full page models should be hydrated, never prior cursor pages.');
        $this->assertEqualsCanonicalizing($displayed, $hydrated);

        $ordered = array_values(array_filter($queries, static fn (string $sql): bool => preg_match('/^select\b.*\bfrom ["`]?pages["`]?\s/si', $sql) === 1
            && str_contains(strtolower($sql), 'order by') && ! str_contains(strtolower($sql), 'count(')));
        $this->assertNotEmpty($ordered, 'The request should seek through bounded page candidates.');
        foreach ($ordered as $sql) {
            $this->assertMatchesRegularExpression('/\blimit\s+(\d+)/i', $sql);
            preg_match('/\blimit\s+(\d+)/i', $sql, $limit);
            $this->assertLessThanOrEqual(21, (int) $limit[1], $sql);
            $this->assertStringNotContainsString(' offset ', strtolower($sql));
        }
    }

    public function test_legacy_page_two_returns_the_same_items_as_cursor_with_exact_totals(): void
    {
        $ids = $this->pages(45);
        $first = $this->discover()->assertOk()
            ->assertJsonPath('data.pagination.total', 45)
            ->assertJsonPath('data.pagination.scope_totals.pages', 45)
            ->assertJsonPath('data.pagination.last_page', 3)
            ->assertJsonPath('data.pagination.next_page', 2);
        $cursor = $this->next($first)->assertOk();
        $legacy = $this->discover(['page' => 2])->assertOk()
            ->assertJsonPath('data.pagination.current_page', 2)
            ->assertJsonPath('data.pagination.total', 45);
        $this->assertSame($this->ids($cursor, 'pages'), $this->ids($legacy, 'pages'));
        $this->assertSame(array_slice(array_reverse($ids), 20, 20), $this->ids($legacy, 'pages'));
        $third = $this->next($cursor)->assertOk()->assertJsonCount(5, 'data.pages')
            ->assertJsonPath('data.pagination.has_more', false)
            ->assertJsonPath('data.pagination.next_cursor', null);
        $this->assertSame(array_slice(array_reverse($ids), 40), $this->ids($third, 'pages'));
    }

    public function test_mixed_content_with_identical_timestamps_pages_one_combined_feed_without_duplicates(): void
    {
        $owner = User::factory()->create();
        $pageIds = $this->pages(8, owner: $owner);
        $parent = Page::findOrFail($pageIds[0]);
        $expected = ['products' => [], 'services' => [], 'events' => [], 'ads' => []];
        for ($number = 1; $number <= 7; $number++) {
            $expected['products'][] = $this->product($parent, (string) $number)->id;
            $expected['services'][] = $this->service($parent, (string) $number)->id;
            $expected['events'][] = $this->event($parent, (string) $number)->id;
            $expected['ads'][] = $this->ad($owner, (string) $number)->id;
        }
        $first = $this->discover()->assertOk()->assertJsonPath('data.pagination.total', 36)
            ->assertJsonPath('data.pagination.scope_totals', ['pages' => 8, 'products' => 7, 'services' => 7, 'events' => 7, 'ads' => 7]);
        $second = $this->next($first)->assertOk()->assertJsonPath('data.pagination.has_more', false)
            ->assertJsonPath('data.pagination.next_cursor', null);
        // Existing tie order is service, product, page, event, ad, then ID descending.
        $this->assertSame(array_reverse($expected['services']), $this->ids($first, 'services'));
        $this->assertSame(array_reverse($expected['products']), $this->ids($first, 'products'));
        $this->assertSame(array_slice(array_reverse($pageIds), 0, 6), $this->ids($first, 'pages'));
        $this->assertSame([], $this->ids($first, 'events'));
        $this->assertSame([], $this->ids($first, 'ads'));
        $this->assertSame(array_slice(array_reverse($pageIds), 6), $this->ids($second, 'pages'));
        $this->assertSame(array_reverse($expected['events']), $this->ids($second, 'events'));
        $this->assertSame(array_reverse($expected['ads']), $this->ids($second, 'ads'));
        $this->assertSame([], $this->ids($second, 'services'));
        $this->assertSame([], $this->ids($second, 'products'));
    }

    public function test_preferred_neighborhood_city_and_remaining_tiers_continue_across_cursor_boundaries(): void
    {
        $local = $this->pages(23, city: 'Jerusalem', neighborhood: 'Ramot', timestamp: '2026-09-10 12:00:00');
        $city = $this->pages(9, city: 'Jerusalem', neighborhood: 'Gilo', timestamp: '2026-09-11 12:00:00');
        $other = $this->pages(8, city: 'Haifa', timestamp: '2026-09-12 12:00:00');
        $unknown = $this->pages(3, city: null, timestamp: '2026-09-13 12:00:00');
        $preferences = ['preferred_city' => 'Jerusalem', 'preferred_neighborhood' => 'Ramot'];
        $first = $this->discover($preferences)->assertOk()->assertJsonPath('data.pagination.total', 43);
        $second = $this->next($first, $preferences)->assertOk();
        $third = $this->next($second, $preferences)->assertOk()->assertJsonPath('data.pagination.has_more', false);
        $expected = [...array_reverse($local), ...array_reverse($city), ...array_reverse($unknown), ...array_reverse($other)];
        $this->assertSame(array_slice($expected, 0, 20), $this->ids($first, 'pages'));
        $this->assertSame(array_slice($expected, 20, 20), $this->ids($second, 'pages'));
        $this->assertSame(array_slice($expected, 40), $this->ids($third, 'pages'));
    }

    public function test_newer_insert_between_requests_never_repeats_or_skips_existing_results(): void
    {
        $existing = $this->pages(45, timestamp: '2026-09-16 12:00:00');
        $first = $this->discover()->assertOk();
        // One later timestamp and one tied timestamp both sort before the cursor.
        $newer = $this->pages(1, timestamp: '2026-09-17 12:00:00');
        $tied = $this->pages(1, timestamp: '2026-09-16 12:00:00');
        $seen = $this->ids($first, 'pages');
        $response = $first;
        for ($safety = 0; $response->json('data.pagination.has_more') && $safety < 5; $safety++) {
            $response = $this->next($response)->assertOk();
            $seen = [...$seen, ...$this->ids($response, 'pages')];
        }
        $this->assertFalse($response->json('data.pagination.has_more'));
        $this->assertSame(array_reverse($existing), $seen);
        $this->assertSame([], array_values(array_intersect([...$newer, ...$tied], $seen)));
        $this->assertSame(count($seen), count(array_unique($seen)));
    }

    public function test_invalid_or_tampered_cursor_and_changed_preferences_are_rejected(): void
    {
        $this->pages(25, city: 'Jerusalem', neighborhood: 'Ramot');
        $preferences = ['preferred_city' => 'Jerusalem', 'preferred_neighborhood' => 'Ramot'];
        $first = $this->discover($preferences)->assertOk();
        $token = $first->json('data.pagination.next_cursor');
        $this->assertIsString($token);
        $this->assertNotSame('', $token);
        $this->discover([...$preferences, 'cursor' => 'not-a-valid-cursor'])->assertUnprocessable();
        $offset = intdiv(strlen($token), 2);
        $tampered = substr_replace($token, $token[$offset] === 'A' ? 'B' : 'A', $offset, 1);
        $this->discover([...$preferences, 'cursor' => $tampered])->assertUnprocessable();
        $this->discover(['cursor' => $token, 'preferred_city' => 'Haifa', 'preferred_neighborhood' => 'Ramot'])->assertUnprocessable();
        $this->discover(['cursor' => $token, 'preferred_city' => 'Jerusalem', 'preferred_neighborhood' => 'Gilo'])->assertUnprocessable();
        $this->discover(['cursor' => $token])->assertUnprocessable();
        $this->next($first, $preferences)->assertOk()->assertJsonCount(5, 'data.pages');
    }

    public function test_lean_discovery_keeps_banned_owner_and_unclaimed_child_visibility_rules(): void
    {
        $owner = User::factory()->create();
        $bannedOwner = User::factory()->create(['banned_at' => now()]);
        $managed = Page::findOrFail($this->pages(1, owner: $owner)[0]);
        $unclaimed = Page::findOrFail($this->pages(1, owner: $owner, unclaimed: true)[0]);
        $banned = Page::findOrFail($this->pages(1, owner: $bannedOwner)[0]);
        $visible = ['products' => [], 'services' => [], 'events' => []];
        foreach ([$managed, $unclaimed, $banned] as $page) {
            $product = $this->product($page, 'visibility');
            $service = $this->service($page, 'visibility');
            $event = $this->event($page, 'visibility');
            if ($page->is($managed)) {
                $visible = ['products' => [$product->id], 'services' => [$service->id], 'events' => [$event->id]];
            }
        }
        $ad = $this->ad($owner, 'visible');
        $this->ad($bannedOwner, 'hidden');
        $response = $this->discover()->assertOk()->assertJsonPath('data.pagination.total', 6)
            ->assertJsonPath('data.pagination.next_cursor', null);
        $this->assertEqualsCanonicalizing([$managed->id, $unclaimed->id], $this->ids($response, 'pages'));
        foreach ($visible as $scope => $ids) {
            $this->assertSame($ids, $this->ids($response, $scope));
        }
        $this->assertSame([$ad->id], $this->ids($response, 'ads'));
    }

    public function test_keyword_page_search_keeps_text_location_and_owner_filters_and_returns_only_latest_twenty(): void
    {
        $matching = $this->pages(23, city: 'Haifa');
        foreach ($matching as $offset => $id) {
            DB::table('pages')->where('id', $id)->update([
                'name' => $offset % 2 === 0 ? 'Piano studio '.$id : 'Music studio '.$id,
                'public_description' => $offset % 2 === 0 ? 'Music lessons.' : 'Private piano lessons.',
                'created_at' => Carbon::parse('2026-09-16 10:00:00')->addMinutes($offset),
            ]);
        }
        // The most recent valid result has only the legacy free-text address.
        $legacyId = $matching[count($matching) - 1];
        DB::table('pages')->where('id', $legacyId)->update([
            'setup' => json_encode(['address' => ['city' => null]], JSON_THROW_ON_ERROR),
            'address' => '10 Herzl Street, Haifa',
        ]);

        $banned = User::factory()->create(['banned_at' => now()]);
        $hidden = $this->pages(2, city: 'Haifa', owner: $banned);
        $wrongCity = $this->pages(2, city: 'Jerusalem');
        DB::table('pages')->whereIn('id', [...$hidden, ...$wrongCity])->update([
            'name' => 'Piano search match', 'public_description' => 'Piano lessons.',
        ]);
        $this->pages(2, city: 'Haifa'); // Newer visible records that do not match the text.

        $response = $this->getJson('/api/v1/search?'.http_build_query([
            'scope' => 'pages', 'q' => 'piano', 'city' => 'Haifa',
        ]))->assertOk()->assertJsonCount(20, 'data.pages');
        $this->assertSame(array_slice(array_reverse($matching), 0, 20), $this->ids($response, 'pages'));
        $this->assertSame($legacyId, $response->json('data.pages.0.id'));
        $this->assertSame([], array_values(array_intersect($hidden, $this->ids($response, 'pages'))));
        $this->assertSame([], $this->ids($response, 'products'));
    }

    private function discover(array $query = []): TestResponse
    {
        return $this->getJson('/api/v1/search?'.http_build_query(['discover' => 1, ...$query]));
    }

    private function next(TestResponse $previous, array $preferences = []): TestResponse
    {
        $cursor = $previous->json('data.pagination.next_cursor');
        $this->assertIsString($cursor, 'Every non-final discovery result must provide its continuation cursor.');
        $this->assertNotSame('', $cursor);

        return $this->discover([...$preferences, 'cursor' => $cursor]);
    }

    private function ids(TestResponse $response, string $scope): array
    {
        return array_column($response->json('data.'.$scope), 'id');
    }

    private function pages(int $count, ?string $city = null, ?string $neighborhood = null,
        string $timestamp = '2026-09-17 12:00:00', ?User $owner = null, bool $unclaimed = false): array
    {
        $owner ??= User::factory()->create();
        $ids = [];
        for ($index = 0; $index < $count; $index++) {
            // Direct fixture inserts avoid identity observer work unrelated to search.
            $ids[] = DB::table('pages')->insertGetId([
                'user_id' => $owner->id, 'created_by_user_id' => $owner->id,
                'type' => Page::TYPE_BUSINESS, 'is_unclaimed' => $unclaimed,
                'name' => 'Search fixture '.$owner->id.'-'.$index,
                'public_description' => str_repeat('Source description. ', 50),
                'setup' => json_encode(['address' => ['city' => $city, 'neighborhood' => $neighborhood],
                    'source_details' => str_repeat('Retained business data. ', 100)], JSON_THROW_ON_ERROR),
                'created_at' => $timestamp, 'updated_at' => $timestamp,
            ]);
        }

        return $ids;
    }

    private function product(Page $page, string $name): PageProduct
    {
        return PageProduct::create(['page_id' => $page->id, 'name' => 'Product '.$name,
            'description' => 'Product details', 'image_path' => 'products/fixture.webp',
            'price' => 10, 'link' => 'https://example.org/product']);
    }

    private function service(Page $page, string $name): PageService
    {
        return PageService::create(['page_id' => $page->id, 'name' => 'Service '.$name,
            'description' => 'Service details', 'image_path' => 'services/fixture.webp']);
    }

    private function event(Page $page, string $name): PageEvent
    {
        return PageEvent::create(['page_id' => $page->id, 'name' => 'Event '.$name,
            'description' => 'Event details', 'image_path' => 'events/fixture.webp',
            'event_date' => '2026-09-20', 'event_time' => '18:00', 'address' => 'Local venue']);
    }

    private function ad(User $owner, string $name): Ad
    {
        return Ad::create(['user_id' => $owner->id, 'type' => Ad::TYPE_PRIVATE,
            'title' => 'Ad '.$name, 'text' => 'Advertisement details', 'status' => 'active']);
    }
}
