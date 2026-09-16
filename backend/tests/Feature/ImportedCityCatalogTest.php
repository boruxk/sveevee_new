<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\User;
use App\Services\ImportSourceCatalogService;
use App\Support\CatalogTopics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ImportedCityCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_reviewed_city_observation_has_an_explicit_disposition_and_verified_targets(): void
    {
        $audit = $this->data('import-city-curation-2026-09-16.json');
        $reference = collect($this->data('import-city-reference-cbs-2024.json')['localities'])->keyBy('code');
        $catalog = collect($this->data('import-city-catalog.json')['cities'])->keyBy('name');
        $names = collect(config('locations.cities'))->pluck('name')->all();
        $resolver = app(ImportSourceCatalogService::class);
        $this->assertCount(4736, $audit['observations']);
        $this->assertCount(4736, array_unique(array_column($audit['observations'], 'id')));
        $this->assertSame(4736, array_sum($audit['counts']));
        foreach ($audit['observations'] as $observation) {
            $this->assertContains($observation['status'], ['new_locality', 'existing_alias', 'not_locality', 'unverified', 'ambiguous']);
            $this->assertNotEmpty($observation['reason']);
            if ($observation['canonical'] === null) {
                continue;
            }
            $city = $catalog[$observation['canonical']];
            $official = $reference[$city['official_code']];
            $this->assertSame($observation['official_code'], $city['official_code']);
            $this->assertLessThan(500, (int) $official['locality_type']);
            $this->assertNotSame('460', $official['locality_type']);
            $this->assertContains($city['name'], $names);
            $this->assertSame($city['name'], $resolver->knownCity($observation['raw_value']), $observation['raw_value']);
        }
    }

    public function test_new_localities_preserve_existing_names_and_distinct_qualified_localities(): void
    {
        $locations = collect(config('locations.cities'))->keyBy('name');
        $catalog = collect($this->data('import-city-catalog.json')['cities']);
        $this->assertCount(83, $catalog->where('added', false));
        $this->assertCount(883, $catalog->where('added', true));
        $this->assertCount(966, $locations);
        $this->assertCount(966, $locations->keys()->map(fn (string $name) => CatalogTopics::locationSlug($name))->unique());
        $this->assertArrayHasKey('Herzliya', $locations);
        $this->assertArrayHasKey('Nahariya', $locations);
        $this->assertArrayNotHasKey('Herzliyya', $locations);
        $this->assertArrayNotHasKey('Nahariyya', $locations);
        $this->assertContains('Florentin', $locations['Tel Aviv']['neighborhoods']);
        $this->assertArrayHasKey('Kinneret (Moshava)', $locations);
        $this->assertArrayHasKey('Kinneret (Qevuza)', $locations);
        $this->assertSame([], $locations['Kinneret (Moshava)']['neighborhoods']);
    }

    public function test_reference_labels_are_available_for_every_catalog_city_without_invented_neighborhoods(): void
    {
        $catalog = $this->data('import-city-catalog.json');
        $labels = json_decode(file_get_contents(base_path('../frontend/src/utils/importedCityLabels.json')), true, flags: JSON_THROW_ON_ERROR);
        $locations = collect(config('locations.cities'))->keyBy('name');
        foreach ($catalog['cities'] as $city) {
            $this->assertSame($city['name_he'], $labels[$city['name']]);
            $this->assertMatchesRegularExpression('/\p{Hebrew}/u', $city['name_he']);
            $this->assertNotEmpty($city['name']);
            if ($city['added']) {
                $this->assertSame([], $locations[$city['name']]['neighborhoods']);
            }
        }
    }

    public function test_location_options_do_not_read_pages_or_promote_unreviewed_address_values(): void
    {
        $owner = User::factory()->create();
        $owner->profile()->update(['city' => 'Unreviewed profile region', 'neighborhood' => 'Unreviewed profile area']);
        $page = Page::create([
            'user_id' => $owner->id,
            'type' => Page::TYPE_BUSINESS,
            'name' => 'Source address remains visible',
            'setup' => ['address' => ['city' => 'Unreviewed industrial area', 'neighborhood' => 'Source district', 'street' => 'Original street']],
        ]);
        DB::enableQueryLog();
        try {
            $response = $this->getJson('/api/v1/locations')->assertOk();
            $queries = collect(DB::getQueryLog())->pluck('query');
        } finally {
            DB::disableQueryLog();
        }
        $this->assertFalse($queries->contains(fn (string $query): bool => (bool) preg_match('/\bfrom\s+["`]?\b(pages|user_profiles|ads)\b/i', $query)), $queries->implode("\n"));
        $this->assertContains('Deir Hanna', $response->json('data.cities'));
        $this->assertNotContains('Unreviewed industrial area', $response->json('data.cities'));
        $this->assertNotContains('Unreviewed profile region', $response->json('data.cities'));
        $this->assertNotContains('Source district', array_column($response->json('data.neighborhoods'), 'name'));
        $this->getJson('/api/v1/pages/'.$page->id)->assertOk()
            ->assertJsonPath('data.setup.address.city', 'Unreviewed industrial area')
            ->assertJsonPath('data.setup.address.neighborhood', 'Source district');
    }

    public function test_known_city_aliases_resolve_but_ambiguous_and_non_locality_values_do_not(): void
    {
        $resolver = app(ImportSourceCatalogService::class);
        $this->assertSame('Herzliya', $resolver->knownCity('HERZLIYYA'));
        $this->assertSame('Nahariya', $resolver->knownCity('נהרייה'));
        $this->assertSame('Deir Hanna', $resolver->knownCity('דיר חנא'));
        $this->assertSame('Sitriyya', $resolver->knownCity('סתריה'));
        $this->assertSame('Tel Aviv', $resolver->knownCity('Tel Aviv-Jaffa'));
        $this->assertNull($resolver->knownCity('Zohar'));
        $this->assertNull($resolver->knownCity('Tel Aviv District'));
        $this->assertNull($resolver->knownCity('Haifa industrial zone'));
    }

    private function data(string $file): array
    {
        return json_decode(file_get_contents(resource_path('data/'.$file)), true, flags: JSON_THROW_ON_ERROR);
    }
}
