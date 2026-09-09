<?php

namespace Tests\Feature;

use App\Models\BusinessImportCategory;
use App\Models\BusinessImportCity;
use App\Models\BusinessImportClient;
use App\Models\BusinessImportSource;
use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\TestCase;

class BusinessImportCatalogReviewApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $client = Client::factory()->asClientCredentials()->create();
        BusinessImportClient::query()->create([
            'oauth_client_id' => $client->getKey(), 'name' => 'Catalog review import test',
            'allowed_scopes' => [BusinessImportClient::SCOPE_READ, BusinessImportClient::SCOPE_WRITE], 'active' => true,
        ]);
        Passport::actingAsClient($client, [BusinessImportClient::SCOPE_READ, BusinessImportClient::SCOPE_WRITE]);
    }

    public function test_all_registered_sources_record_unknown_labels_and_expose_them_on_the_business_page(): void
    {
        foreach (['overture_places', 'data_gov_ckan', 'tel_aviv_business_licenses'] as $provider) {
            $metadata = [
                'source_city' => 'Original unlisted village',
                'source_categories' => [
                    ['key' => 'mapped_source_type', 'label' => 'Mapped category', 'catalog_key' => 'food_catering.cafes'],
                    ['key' => 'unknown_source_type', 'label' => 'Original category label', 'catalog_key' => null],
                    ['key' => 'unknown_secondary_type', 'label' => 'Original secondary label', 'catalog_key' => null],
                ],
            ];
            $source = $this->source($provider, 1, $metadata);
            $payload = ['name' => 'Source catalog fixture', 'source' => $source, 'address' => ['city' => 'Original unlisted village']];
            // Unknown alternates must be retained even when another source category is mapped.
            if ($provider === 'data_gov_ckan') {
                $payload['category_key'] = 'food_catering.cafes';
            }
            $id = $this->postBusiness($payload)->assertCreated()->json('data.business.id');
            $city = BusinessImportCity::where('provider', $provider)->sole();
            $this->assertSame('Original unlisted village', $city->raw_value);
            $this->assertSame($source['id'], $city->first_source_id);
            $this->assertSame($id, $city->example_page_id);
            $this->assertNull($city->mapped_city);
            $this->assertNotNull($city->first_seen_at);
            $this->assertSame(2, BusinessImportCategory::where('provider', $provider)->count());
            $category = BusinessImportCategory::where('provider', $provider)->where('raw_value', 'unknown_source_type')->sole();
            $this->assertSame('Original category label', $category->label);
            $this->assertSame($source['id'], $category->first_source_id);
            $this->assertSame($id, $category->example_page_id);
            $this->assertNull($category->mapped_category_key);
            $this->assertSame($source['metadata'], BusinessImportSource::where('provider', $provider)->sole()->metadata);
            $this->getJson('/api/v1/pages/'.$id)->assertOk()
                ->assertJsonPath('data.address_details.city', 'Original unlisted village')
                ->assertJsonPath('data.catalog_city', null)
                ->assertJsonCount(2, 'data.source_categories')
                ->assertJsonPath('data.source_categories.0', ['provider' => $provider, 'key' => 'unknown_source_type', 'label' => 'Original category label'])
                ->assertJsonPath('data.category_key', $payload['category_key'] ?? null);
        }
        $this->assertDatabaseCount('business_import_cities', 3);
        $this->assertDatabaseCount('business_import_categories', 6);
    }

    public function test_known_city_aliases_mapped_categories_and_missing_source_data_do_not_create_review_rows(): void
    {
        $source = $this->source('overture_places', 1, [
            'source_city' => 'תל אביב-יפו',
            'source_categories' => [
                ['key' => 'cafe', 'label' => 'Cafe', 'catalog_key' => 'food_catering.cafes'],
                ['key' => 'food_catering.cafes', 'label' => 'Known catalog key', 'catalog_key' => null],
            ],
        ]);
        $id = $this->postBusiness([
            'name' => 'Source catalog fixture', 'source' => $source, 'category_key' => 'food_catering.cafes', 'address' => ['city' => 'Tel Aviv'],
        ])->assertCreated()->json('data.business.id');
        $this->getJson('/api/v1/pages/'.$id)->assertOk()->assertJsonPath('data.catalog_city', 'Tel Aviv')->assertJsonPath('data.source_categories', []);
        $this->postBusiness(['name' => 'Source without source categories', 'source' => $this->source('data_gov_ckan', 2, [
            'source_categories' => [], 'original_record' => ['מטרת החברה' => 'Any lawful business activity', 'תאור חברה' => 'A company description'],
        ])])->assertCreated();
        $this->postBusiness(['name' => 'Source without location', 'source' => $this->source('tel_aviv_business_licenses', 3)])
            ->assertCreated();
        $this->assertDatabaseCount('business_import_cities', 0);
        $this->assertDatabaseCount('business_import_categories', 0);
    }

    public function test_normalized_variants_and_idempotent_replays_preserve_first_observation_without_inflated_rows(): void
    {
        $this->travelTo(now()->startOfSecond());
        $metadata = ['source_city' => 'Unlisted Village', 'source_categories' => [
            ['key' => 'Unknown_Type', 'label' => 'Original label', 'catalog_key' => null],
            ['key' => ' UNKNOWN_TYPE ', 'label' => ' ORIGINAL   LABEL ', 'catalog_key' => null],
        ]];
        $source = $this->source('overture_places', 1, $metadata);
        $payload = ['name' => 'Source catalog fixture', 'source' => $source, 'address' => ['city' => 'Unlisted Village']];
        $uuid = (string) Str::uuid();
        $id = $this->postBusiness($payload, $uuid)->assertCreated()->json('data.business.id');
        $city = BusinessImportCity::query()->sole();
        $category = BusinessImportCategory::query()->sole();
        $firstSeen = $city->first_seen_at->toDateTimeString();
        $this->assertCount(1, Page::findOrFail($id)->setup['imported_categories']);
        $this->travel(1)->hours();
        $this->postBusiness($payload, $uuid)->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.business.id', $id);
        $this->assertSame($firstSeen, $city->fresh()->last_seen_at->toDateTimeString());
        $this->assertSame($firstSeen, $category->fresh()->last_seen_at->toDateTimeString());

        $variant = $this->source('overture_places', 2, [
            'source_city' => ' UNLISTED   VILLAGE ', 'source_categories' => [
                ['key' => 'unknown_type', 'label' => 'Original label', 'catalog_key' => null],
            ],
        ]);
        $this->postBusiness(['name' => 'Another source fixture', 'source' => $variant, 'address' => ['city' => 'UNLISTED VILLAGE']])->assertCreated();
        $this->assertDatabaseCount('business_import_cities', 1);
        $this->assertDatabaseCount('business_import_categories', 1);
        $this->assertSame('Unlisted Village', $city->fresh()->raw_value);
        $this->assertSame('Unknown_Type', $category->fresh()->raw_value);
        $this->assertSame($source['id'], $city->fresh()->first_source_id);
        $this->assertSame($source['id'], $category->fresh()->first_source_id);
        $this->assertSame($firstSeen, $city->fresh()->first_seen_at->toDateTimeString());
        $this->assertSame(now()->toDateTimeString(), $city->fresh()->last_seen_at->toDateTimeString());
        $this->assertSame(now()->toDateTimeString(), $category->fresh()->last_seen_at->toDateTimeString());
    }

    public function test_source_only_updates_preserve_raw_city_and_categories_and_future_review_mappings(): void
    {
        $source = $this->source('overture_places', 1, [
            'source_city' => 'Unlisted Village',
            'source_categories' => [['key' => 'unmapped_type', 'label' => 'Original activity', 'catalog_key' => null]],
        ]);
        $id = $this->postBusiness(['name' => 'Source catalog fixture', 'source' => $source, 'address' => ['city' => 'Unlisted Village']])
            ->assertCreated()->json('data.business.id');
        BusinessImportCity::query()->sole()->update(['mapped_city' => 'Haifa']);
        BusinessImportCategory::query()->sole()->update(['mapped_category_key' => 'food_catering.cafes']);
        $this->patchJson('/api/v1/business-import/businesses/'.$id, ['source' => $source])->assertOk()
            ->assertJsonPath('data.business.address.city', 'Unlisted Village')->assertJsonPath('data.business.category_key', null);
        $this->getJson('/api/v1/pages/'.$id)->assertOk()
            ->assertJsonPath('data.address_details.city', 'Unlisted Village')
            ->assertJsonPath('data.source_categories.0.label', 'Original activity');
        $this->assertSame('Haifa', BusinessImportCity::query()->sole()->mapped_city);
        $this->assertSame('food_catering.cafes', BusinessImportCategory::query()->sole()->mapped_category_key);
        $this->assertDatabaseCount('business_import_cities', 1);
        $this->assertDatabaseCount('business_import_categories', 1);
    }

    public function test_label_corrections_and_mapping_transitions_rebuild_fallbacks_from_all_current_sources(): void
    {
        $overture = $this->source('overture_places', 1, ['source_categories' => [
            ['key' => 'unmapped_type', 'label' => 'Old category label', 'catalog_key' => null],
        ]]);
        $payload = [
            'name' => 'Source catalog fixture', 'source' => $overture,
            'address' => ['city' => 'Tel Aviv', 'street' => 'Main Street', 'number' => '10'],
        ];
        $id = $this->postBusiness($payload)->assertCreated()->json('data.business.id');
        $gov = $this->source('data_gov_ckan', 1, ['source_categories' => [
            ['key' => 'another_type', 'label' => 'Another source category', 'catalog_key' => null],
        ]]);
        $this->postBusiness(array_replace($payload, ['source' => $gov]))->assertOk()->assertJsonPath('data.business.id', $id);
        $overture['metadata']['source_categories'][0]['label'] = 'Corrected category label';
        $overture['metadata']['source_categories'][0]['catalog_key'] = 'removed.or.invalid_mapping';
        foreach (range(1, 2) as $repeat) {
            $this->patchJson('/api/v1/business-import/businesses/'.$id, ['source' => $overture])->assertOk();
            $categories = collect(Page::findOrFail($id)->setup['imported_categories']);
            $this->assertCount(2, $categories);
            $this->assertSame('Corrected category label', $categories->firstWhere('provider', 'overture_places')['label']);
            $this->assertSame('Another source category', $categories->firstWhere('provider', 'data_gov_ckan')['label']);
        }
        $this->assertDatabaseCount('business_import_categories', 2);
        $overture['metadata']['source_categories'][0]['catalog_key'] = 'food_catering.cafes';
        $this->patchJson('/api/v1/business-import/businesses/'.$id, ['source' => $overture])->assertOk();
        $this->getJson('/api/v1/pages/'.$id)->assertOk()->assertJsonCount(1, 'data.source_categories')
            ->assertJsonPath('data.source_categories.0.label', 'Another source category');
        $gov['metadata']['source_categories'] = [];
        $this->patchJson('/api/v1/business-import/businesses/'.$id, ['source' => $gov])->assertOk();
        $this->getJson('/api/v1/pages/'.$id)->assertOk()->assertJsonPath('data.source_categories', []);
        $this->assertDatabaseCount('business_import_categories', 2);
    }

    public function test_claimed_or_moved_source_updates_cannot_change_review_rows_or_page_fallbacks(): void
    {
        $source = $this->source('overture_places', 1, [
            'source_city' => 'Original Village',
            'source_categories' => [['key' => 'original_type', 'label' => 'Original category', 'catalog_key' => null]],
        ]);
        $payload = ['name' => 'Source catalog fixture', 'source' => $source, 'address' => ['city' => 'Original Village']];
        $id = $this->postBusiness($payload)->assertCreated()->json('data.business.id');
        $originalSetup = Page::findOrFail($id)->setup;
        $originalCity = BusinessImportCity::query()->sole()->getAttributes();
        $originalCategory = BusinessImportCategory::query()->sole()->getAttributes();
        $changed = $source;
        $changed['metadata']['source_city'] = 'Another Village';
        $changed['metadata']['source_categories'][] = ['key' => 'new_type', 'label' => 'New category', 'catalog_key' => null];
        $this->patchJson('/api/v1/business-import/businesses/'.$id, ['source' => $changed, 'address' => ['city' => 'Another Village']])
            ->assertStatus(409)->assertJsonValidationErrors('source_conflict');
        $owner = User::factory()->create();
        Page::findOrFail($id)->update(['is_unclaimed' => false, 'user_id' => $owner->id]);
        $this->patchJson('/api/v1/business-import/businesses/'.$id, ['source' => $changed])
            ->assertStatus(409)->assertJsonValidationErrors('claimed');
        $this->assertSame($originalSetup, Page::findOrFail($id)->setup);
        $this->assertSame($originalCity, BusinessImportCity::query()->sole()->getAttributes());
        $this->assertSame($originalCategory, BusinessImportCategory::query()->sole()->getAttributes());
        $this->assertSame($source['metadata'], BusinessImportSource::query()->sole()->metadata);
        $this->assertDatabaseCount('business_import_cities', 1);
        $this->assertDatabaseCount('business_import_categories', 1);
    }

    public function test_legacy_metadata_retains_actual_taxonomy_and_license_values_without_inventing_company_categories(): void
    {
        $metadataByProvider = [
            'overture_places' => ['address' => ['country' => 'IL', 'locality' => 'Legacy Village'], 'taxonomy' => [
                'primary' => 'unmapped_primary', 'alternates' => ['unmapped_secondary'], 'hierarchy' => ['broad_ancestor'],
            ]],
            'data_gov_ckan' => ['profile' => 'israel_companies', 'original_record' => ['שם עיר' => 'Legacy Village', 'מטרת החברה' => 'Any lawful purpose']],
            'tel_aviv_business_licenses' => ['license' => ['mahuiot' => '999001', 't_hesber_mahut_esek' => 'Original licensed activity']],
            'beer_sheva_business_licenses' => ['profile' => 'beer_sheva_business_licenses', 'original_record' => ['תאור רישיון' => 'Original municipal license']],
        ];
        foreach ($metadataByProvider as $provider => $metadata) {
            $source = $this->source($provider, 1, $metadata);
            $id = $this->postBusiness([
                'name' => 'Source catalog fixture', 'source' => $source, 'address' => ['city' => 'Legacy Village'],
                // A mapped page category does not prove that the original license text is mapped.
                'category_key' => $provider === 'beer_sheva_business_licenses' ? 'food_catering.restaurants' : null,
            ])
                ->assertCreated()->json('data.business.id');
            $categories = Page::findOrFail($id)->setup['imported_categories'] ?? [];
            if ($provider === 'overture_places') {
                $this->assertSame(['unmapped_primary', 'unmapped_secondary'], array_column($categories, 'key'));
            } elseif ($provider === 'data_gov_ckan') {
                $this->assertSame([], $categories);
            } elseif ($provider === 'tel_aviv_business_licenses') {
                $this->assertSame('license_code:999001', $categories[0]['key']);
                $this->assertSame('Original licensed activity', $categories[0]['label']);
            } else {
                $this->assertSame('license_description:'.hash('sha256', 'Original municipal license'), $categories[0]['key']);
            }
        }
        $this->assertDatabaseCount('business_import_categories', 4);
        $this->assertDatabaseCount('business_import_cities', 3);
    }

    private function postBusiness(array $payload, ?string $uuid = null)
    {
        return $this->postJson('/api/v1/business-import/businesses', $payload, ['Idempotency-Key' => $uuid ?? (string) Str::uuid()]);
    }

    private function source(string $provider, int $number, array $metadata = []): array
    {
        if ($provider === 'overture_places') {
            $id = 'review-'.$number;
            $url = 'https://explore.overturemaps.org/?feature=places.place.'.$id;
        } elseif ($provider === 'tel_aviv_business_licenses') {
            $where = 'ms_esek_rashi='.$number." AND ms_esek_mishne=0 AND mahuiot='999001'";
            $id = '964:'.hash('sha256', $where);
            $url = 'https://gisn.tel-aviv.gov.il/arcgis/rest/services/IView2/MapServer/964/query?'.http_build_query([
                'where' => $where, 'outFields' => '*', 'returnGeometry' => 'false', 'f' => 'json',
            ], '', '&', PHP_QUERY_RFC3986);
        } else {
            $beerSheva = $provider === 'beer_sheva_business_licenses';
            $provider = 'data_gov_ckan';
            $resource = $beerSheva ? '7d4c61e2-2416-453e-8efb-bd02ec89db35' : 'f004176c-b85f-4542-8901-7b3176f9a054';
            $record = $beerSheva ? $number : 511000000 + $number;
            $id = $resource.':'.$record;
            $url = 'https://data.gov.il/api/3/action/datastore_search?'.http_build_query([
                'resource_id' => $resource, 'limit' => 1,
                'filters' => json_encode([$beerSheva ? '_id' : 'מספר חברה' => $record], JSON_UNESCAPED_UNICODE),
            ], '', '&', PHP_QUERY_RFC3986);
        }

        return ['provider' => $provider, 'id' => $id, 'url' => $url, 'metadata' => [
            'source_id' => $id, 'original_name' => 'Source catalog fixture', ...$metadata,
        ]];
    }
}
