<?php

namespace Tests\Feature;

use App\Models\BusinessImportClient;
use App\Models\BusinessImportSource;
use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use RuntimeException;
use Tests\TestCase;

class BusinessImportSourceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authenticateClient();
    }

    public function test_source_import_preserves_uncategorized_places_and_original_or_absent_locations(): void
    {
        $payload = $this->payload('place-without-location');
        $created = $this->postBusiness($payload)->assertCreated()
            ->assertJsonPath('data.business.category_key', null)
            ->assertJsonPath('data.business.address.city', null)
            ->assertJsonPath('data.business.address.street', null)
            ->assertJsonPath('data.business.opening_hours', [])
            ->json('data.business');
        $this->assertSame($payload['source']['metadata'], BusinessImportSource::query()->sole()->metadata);
        $this->getJson('/api/v1/pages/'.$created['id'])->assertOk()
            ->assertJsonPath('data.address_details.city', null)
            ->assertJsonPath('data.catalog_city', null);

        $payload = $this->payload('place-with-original-location');
        $payload['address'] = ['city' => 'כפר מקור', 'street' => 'Original road', 'neighborhood' => 'Original district'];
        $created = $this->postBusiness($payload)->assertCreated()
            ->assertJsonPath('data.business.address.city', 'כפר מקור')
            ->assertJsonPath('data.business.address.neighborhood', 'Original district')
            ->json('data.business');
        $this->getJson('/api/v1/pages/'.$created['id'])->assertOk()
            ->assertJsonPath('data.address_details.city', 'כפר מקור')
            ->assertJsonPath('data.catalog_city', null)
            ->assertJsonPath('data.catalog_neighborhood', null);
    }

    public function test_regular_import_validation_and_known_catalog_links_remain_unchanged(): void
    {
        $this->postBusiness(['name' => 'Regular incomplete place'])->assertUnprocessable()
            ->assertJsonValidationErrors(['category_key', 'address.city']);
        $this->postBusiness([
            'name' => 'Unknown regular city', 'category_key' => 'food_catering.cafes', 'address' => ['city' => 'Original Village'],
        ])->assertUnprocessable()->assertJsonValidationErrors('address.city');
        $payload = $this->payload('known-city');
        $payload['category_key'] = 'food_catering.cafes';
        $payload['address'] = ['city' => 'Tel Aviv', 'neighborhood' => 'Ramat Aviv'];
        $created = $this->postBusiness($payload)->assertCreated()->json('data.business');
        $this->getJson('/api/v1/pages/'.$created['id'])->assertOk()
            ->assertJsonPath('data.catalog_city', 'Tel Aviv')
            ->assertJsonPath('data.catalog_neighborhood', 'Ramat Aviv');
    }

    public function test_validated_source_imports_retain_original_names_that_match_the_moderation_filter(): void
    {
        $names = [
            '0d449b4c-42e6-49de-a92d-7bf323b88994' => 'Bagel Bite - בייגל בייט',
            '25112133-945e-49b4-958b-d9d49932b9aa' => 'חושך מטומטם',
        ];
        foreach ($names as $sourceId => $name) {
            $payload = $this->payload($sourceId);
            $payload['name'] = $name;
            $payload['source']['metadata']['original_name'] = $name;
            $this->postJson('/api/v1/business-import/businesses/duplicates', $payload)
                ->assertOk()->assertJsonPath('data.duplicate', false);
            $created = $this->postBusiness($payload)->assertCreated()
                ->assertJsonPath('data.business.name', $name)->json('data.business');
            $this->assertSame($name, BusinessImportSource::where('source_id', $sourceId)->sole()->metadata['original_name']);
            $this->postJson('/api/v1/business-import/businesses/duplicates', $payload)
                ->assertOk()->assertJsonPath('data.matches.0.id', $created['id'])
                ->assertJsonPath('data.matches.0.matched_on', ['source_id']);
            $this->patchJson('/api/v1/business-import/businesses/'.$created['id'], ['source' => $payload['source']])
                ->assertOk()->assertJsonPath('data.business.name', $name);
        }
        $this->assertDatabaseCount('pages', count($names));
    }

    public function test_original_name_exception_requires_a_valid_source_and_does_not_allow_blocked_descriptions(): void
    {
        foreach (['Bagel Bite - בייגל בייט', 'חושך מטומטם'] as $name) {
            $ordinary = ['name' => $name, 'category_key' => 'food_catering.cafes', 'address' => ['city' => 'Tel Aviv']];
            $this->postBusiness($ordinary)->assertUnprocessable()->assertJsonValidationErrors('name');
            $this->postJson('/api/v1/business-import/businesses/duplicates', $ordinary)
                ->assertUnprocessable()->assertJsonValidationErrors('name');

            foreach (['provider' => 'another_provider', 'url' => 'https://explore.overturemaps.org/?feature=places.place.mismatched-id'] as $field => $value) {
                $payload = $this->payload('invalid-source-name');
                $payload['name'] = $name;
                $payload['source'][$field] = $value;
                $this->postBusiness($payload)->assertUnprocessable()->assertJsonValidationErrors('source.'.$field);
                $this->postJson('/api/v1/business-import/businesses/duplicates', $payload)
                    ->assertUnprocessable()->assertJsonValidationErrors('source.'.$field);
            }

            $payload = $this->payload('blocked-description');
            $payload['name'] = $name;
            $payload['public_description'] = 'This description is shit.';
            $this->postBusiness($payload)->assertUnprocessable()->assertJsonValidationErrors('public_description');
        }
        $this->assertDatabaseCount('pages', 0);
        $this->assertDatabaseCount('business_import_sources', 0);
    }

    public function test_source_names_still_require_nonempty_strings_within_the_length_limit(): void
    {
        foreach ([null, '', 123, false, ['not a name'], str_repeat('x', 256)] as $name) {
            $payload = $this->payload('invalid-source-name');
            $payload['name'] = $name;
            $this->postBusiness($payload)->assertUnprocessable()->assertJsonValidationErrors('name');
            if ($name !== null && $name !== '') {
                $this->postJson('/api/v1/business-import/businesses/duplicates', $payload)
                    ->assertUnprocessable()->assertJsonValidationErrors('name');
            }
        }
        $this->postBusiness(['source' => $this->source('missing-source-name')])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertDatabaseCount('pages', 0);
    }

    public function test_invalid_source_websites_are_omitted_without_losing_provenance_or_existing_valid_websites(): void
    {
        $originalWebsite = 'http://oganim.haganenet.co.il/gan/%D7%94%D7%92%D7%9F%20%D7%A9%D7%9C%20%D7%93%D7%9C%D7%99%D7%94%20%D7%91%D7%A0%D7%9';
        $payload = $this->payload('2d760967-b36f-489a-a318-bba540b3c9a6');
        $payload['website'] = $originalWebsite;
        $payload['source']['metadata']['original_website'] = $originalWebsite;
        $this->postJson('/api/v1/business-import/businesses/duplicates', $payload)
            ->assertOk()->assertJsonPath('data.duplicate', false);
        $created = $this->postBusiness($payload)->assertCreated()
            ->assertJsonPath('data.business.website', null)->json('data.business');
        $this->assertSame($originalWebsite, BusinessImportSource::query()->sole()->metadata['original_website']);
        $this->patchJson('/api/v1/business-import/businesses/'.$created['id'], [
            'source' => $payload['source'], 'website' => 'https://example.com/business',
        ])->assertOk()->assertJsonPath('data.business.website', 'https://example.com/business');
        $this->postBusiness($payload)->assertOk()
            ->assertJsonPath('data.business.id', $created['id'])
            ->assertJsonPath('data.business.website', 'https://example.com/business');
        $this->patchJson('/api/v1/business-import/businesses/'.$created['id'], [
            'source' => $payload['source'], 'website' => $originalWebsite,
        ])->assertOk()->assertJsonPath('data.business.website', 'https://example.com/business');
        $this->postJson('/api/v1/business-import/businesses/duplicates', $payload)
            ->assertOk()->assertJsonPath('data.matches.0.id', $created['id'])
            ->assertJsonPath('data.matches.0.matched_on', ['source_id']);
        $this->assertSame($originalWebsite, BusinessImportSource::query()->sole()->metadata['original_website']);

        unset($payload['source']);
        $payload['category_key'] = 'food_catering.cafes';
        $payload['address'] = ['city' => 'Tel Aviv'];
        $this->postBusiness($payload)->assertUnprocessable()->assertJsonValidationErrors('website');
        $this->assertDatabaseCount('pages', 1);
    }

    public function test_source_descriptor_and_nonempty_category_are_validated_before_relaxing_fields(): void
    {
        foreach ([
            'source.provider' => 'another_provider',
            'source.id' => '',
            'source.url' => 'https://example.com/?feature=places.place.invalid',
            'source.metadata' => ['not', 'an', 'object'],
            'category_key' => 'invented.category',
            'address.city' => str_repeat('x', 121),
        ] as $field => $value) {
            $payload = $this->payload('invalid');
            data_set($payload, $field, $value);
            $this->postBusiness($payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $payload = $this->payload('wrong-id');
        $payload['source']['url'] = 'https://explore.overturemaps.org/?feature=places.place.another-id';
        $this->postBusiness($payload)->assertUnprocessable()->assertJsonValidationErrors('source.url');
        $payload['source']['url'] = 'http://explore.overturemaps.org/?feature=places.place.wrong-id';
        $this->postBusiness($payload)->assertUnprocessable()->assertJsonValidationErrors('source.url');
        $this->assertDatabaseCount('pages', 0);
        $this->assertDatabaseCount('business_import_sources', 0);
    }

    public function test_different_source_ids_do_not_merge_shared_names_contacts_or_incomplete_street_addresses(): void
    {
        $ids = [];
        foreach (range(1, 12) as $number) {
            $payload = $this->payload('incomplete-branch-'.$number);
            $payload['address'] = ['city' => 'Tel Aviv', 'street' => 'Same Street'];
            $this->postJson('/api/v1/business-import/businesses/duplicates', $payload)
                ->assertOk()->assertJsonPath('data.duplicate', false)->assertJsonCount(0, 'data.matches');
            $ids[] = $this->postBusiness($payload)->assertCreated()->json('data.business.id');
        }
        $this->assertCount(12, array_unique($ids));
        $this->assertDatabaseCount('business_import_sources', 12);
        $this->postJson('/api/v1/business-import/businesses/duplicates', ['source' => $this->source('incomplete-branch-12')])
            ->assertOk()->assertJsonCount(1, 'data.matches')
            ->assertJsonPath('data.matches.0.id', $ids[11])
            ->assertJsonPath('data.matches.0.matched_on', ['source_id']);
    }

    public function test_source_identity_survives_new_batch_ids_and_patch_only_updates(): void
    {
        $payload = $this->payload('stable-place');
        $request = ['client_import_id' => (string) Str::uuid(), 'businesses' => [$payload]];
        $first = $this->postJson('/api/v1/business-import/businesses/batch', $request)->assertCreated()
            ->assertJsonPath('data.created_count', 1)->json('data.items.0.business');
        $this->postJson('/api/v1/business-import/businesses/batch', $request)->assertOk()->assertJsonPath('data.replayed', true);
        $request['client_import_id'] = (string) Str::uuid();
        $this->postJson('/api/v1/business-import/businesses/batch', $request)->assertCreated()
            ->assertJsonPath('data.created_count', 0)->assertJsonPath('data.updated_count', 1)
            ->assertJsonPath('data.items.0.business.id', $first['id']);

        $source = $this->source('stable-place');
        $source['metadata']['release'] = 'next-release';
        $this->patchJson('/api/v1/business-import/businesses/'.$first['id'], ['source' => $source, 'website' => 'https://example.org/branch'])
            ->assertOk()->assertJsonPath('data.business.name', $first['name'])
            ->assertJsonPath('data.business.category_key', null)
            ->assertJsonPath('data.business.website', 'https://example.org/branch');
        $this->postBusiness(['source' => $source])->assertOk()->assertJsonPath('data.business.id', $first['id']);
        $this->assertDatabaseCount('pages', 1);
        $this->assertDatabaseCount('business_import_sources', 1);
        $this->assertSame('next-release', BusinessImportSource::query()->sole()->metadata['release']);
    }

    public function test_source_cannot_move_between_pages_or_conflicting_known_addresses(): void
    {
        $payload = $this->payload('fixed-source');
        $payload['address'] = ['city' => 'Tel Aviv', 'street' => 'Main Street', 'number' => '10'];
        $first = $this->postBusiness($payload)->assertCreated()->json('data.business');
        $second = $this->postBusiness($this->payload('another-source'))->assertCreated()->json('data.business');
        $this->patchJson('/api/v1/business-import/businesses/'.$second['id'], ['source' => $payload['source']])
            ->assertStatus(409)->assertJsonValidationErrors('source_conflict');
        foreach ([['city' => 'Jerusalem'], ['street' => 'Other Street'], ['number' => '11'], ['street' => 'Main Street', 'number' => '11']] as $address) {
            $patch = ['source' => $payload['source'], 'address' => $address];
            $this->patchJson('/api/v1/business-import/businesses/'.$first['id'], $patch)
                ->assertStatus(409)->assertJsonValidationErrors('source_conflict');
            $this->postJson('/api/v1/business-import/businesses/duplicates', $patch)
                ->assertStatus(409)->assertJsonValidationErrors('source_conflict');
        }
        $this->assertSame($first['id'], BusinessImportSource::query()->where('source_id', 'fixed-source')->sole()->page_id);
        $this->assertSame('10', Page::findOrFail($first['id'])->setup['address']['number']);
    }

    public function test_claimed_and_deleted_source_pages_are_not_overwritten_or_recreated(): void
    {
        $payload = $this->payload('claimed-source');
        $created = $this->postBusiness($payload)->assertCreated()->json('data.business');
        $owner = User::factory()->create();
        Page::findOrFail($created['id'])->update(['is_unclaimed' => false, 'user_id' => $owner->id]);
        $this->postBusiness($payload)->assertStatus(409)->assertJsonValidationErrors('claimed');
        $this->patchJson('/api/v1/business-import/businesses/'.$created['id'], ['source' => $payload['source'], 'phone' => '03-9999999'])
            ->assertStatus(409)->assertJsonValidationErrors('claimed');
        $page = Page::findOrFail($created['id']);
        $this->assertSame($owner->id, $page->user_id);
        $this->assertSame($payload['phone'], $page->phone);
        $page->delete();
        $this->postBusiness($payload)->assertStatus(409)->assertJsonValidationErrors('source_conflict');
        $this->assertDatabaseCount('pages', 0);
        $this->assertNull(BusinessImportSource::query()->sole()->page_id);
    }

    public function test_worker_can_register_its_existing_page_with_source_only_but_not_attach_another_incomplete_branch(): void
    {
        $regular = ['name' => 'Old imported place', 'category_key' => 'food_catering.cafes', 'address' => ['city' => 'Tel Aviv']];
        $created = $this->postBusiness($regular)->assertCreated()->json('data.business');
        $source = $this->source('old-import');
        $this->patchJson('/api/v1/business-import/businesses/'.$created['id'], ['source' => $source])
            ->assertOk()->assertJsonPath('data.business.name', $regular['name']);
        $this->assertSame($created['id'], BusinessImportSource::query()->sole()->page_id);
        $this->patchJson('/api/v1/business-import/businesses/'.$created['id'], ['source' => $this->source('different-incomplete-branch')])
            ->assertStatus(409)->assertJsonValidationErrors('source_conflict');

        $other = $this->postBusiness(array_replace($regular, ['name' => 'Another old place']))->assertCreated()->json('data.business');
        $this->authenticateClient();
        $this->patchJson('/api/v1/business-import/businesses/'.$other['id'], ['source' => $this->source('unverified-client-association')])
            ->assertStatus(409)->assertJsonValidationErrors('source_conflict');
        $this->assertDatabaseCount('business_import_sources', 1);
    }

    public function test_source_updates_cannot_change_a_transferred_owner_even_with_an_old_unclaimed_flag(): void
    {
        $payload = $this->payload('transferred-source');
        $created = $this->postBusiness($payload)->assertCreated()->json('data.business');
        $owner = User::factory()->create();
        Page::findOrFail($created['id'])->update(['user_id' => $owner->id]);
        $this->postBusiness($payload)->assertStatus(409)->assertJsonValidationErrors('claimed');
        $this->assertSame($owner->id, Page::findOrFail($created['id'])->user_id);
    }

    public function test_new_sources_reuse_only_a_confirmed_full_location_and_preserve_existing_details(): void
    {
        $regular = [
            'name' => 'Existing Cafe', 'phone' => '03-1234567', 'category_key' => 'food_catering.cafes',
            'address' => ['city' => 'Tel Aviv', 'street' => 'Main Street', 'number' => '10'],
        ];
        $created = $this->postBusiness($regular)->assertCreated()->json('data.business');
        $owner = Page::findOrFail($created['id'])->user_id;
        $payload = $this->payload('confirmed-place');
        $payload['name'] = 'Existing Cafe';
        $payload['address'] = ['city' => 'Tel Aviv', 'street' => 'Main Street 10'];
        $this->postJson('/api/v1/business-import/businesses/duplicates', $payload)
            ->assertOk()->assertJsonPath('data.matches.0.id', $created['id']);
        $this->postBusiness($payload)->assertOk()->assertJsonPath('data.operation', 'updated')
            ->assertJsonPath('data.business.id', $created['id'])->assertJsonPath('data.business.phone', $regular['phone']);
        $this->assertSame($owner, Page::findOrFail($created['id'])->user_id);
        $this->assertDatabaseCount('pages', 1);
        $payload['source'] = $this->source('another-confirmed-id');
        $this->postBusiness($payload)->assertOk()->assertJsonPath('data.business.id', $created['id']);
        $this->assertDatabaseCount('business_import_sources', 2);
    }

    public function test_government_sources_preserve_original_names_uncategorized_places_and_optional_locations(): void
    {
        foreach ([$this->govSource(511234567), $this->govSource(1, companies: false), $this->telSource(123)] as $source) {
            $name = 'Bagel Bite - בייגל בייט';
            $source['metadata']['original_name'] = $name;
            $source['metadata']['original_category'] = 'Unmapped original activity';
            $source['metadata']['original_website'] = 'https://example.com/%9';
            $payload = ['name' => $name, 'category_key' => null, 'source' => $source, 'website' => $source['metadata']['original_website']];
            $created = $this->postBusiness($payload)->assertCreated()
                ->assertJsonPath('data.business.name', $name)
                ->assertJsonPath('data.business.category_key', null)
                ->assertJsonPath('data.business.address.city', null)
                ->assertJsonPath('data.business.website', null)->json('data.business');
            $this->assertSame($source['metadata'], BusinessImportSource::where('source_id', $source['id'])->sole()->metadata);
            $this->postJson('/api/v1/business-import/businesses/duplicates', ['source' => $source])
                ->assertOk()->assertJsonPath('data.matches.0.id', $created['id'])
                ->assertJsonPath('data.matches.0.matched_on', ['source_id']);
            $this->patchJson('/api/v1/business-import/businesses/'.$created['id'], [
                'source' => $source, 'address' => ['city' => 'Original unlisted locality'],
            ])->assertOk()->assertJsonPath('data.business.address.city', 'Original unlisted locality');
            $this->getJson('/api/v1/pages/'.$created['id'])->assertOk()
                ->assertJsonPath('data.address_details.city', 'Original unlisted locality')
                ->assertJsonPath('data.catalog_city', null);
            $this->postBusiness($payload)->assertOk()->assertJsonPath('data.business.id', $created['id']);
            $this->patchJson('/api/v1/business-import/businesses/'.$created['id'], [
                'source' => $source, 'public_description' => 'This description is shit.',
            ])->assertUnprocessable()->assertJsonValidationErrors('public_description');
            $this->patchJson('/api/v1/business-import/businesses/'.$created['id'], [
                'source' => $source, 'category_key' => 'invented.category',
            ])->assertUnprocessable()->assertJsonValidationErrors('category_key');
        }
        $this->assertDatabaseCount('pages', 3);
        $this->assertDatabaseCount('business_import_sources', 3);
    }

    public function test_government_sources_reject_unverified_endpoints_record_ids_and_query_scopes(): void
    {
        foreach ([$this->govSource(511234567), $this->govSource(1, companies: false), $this->telSource(123)] as $source) {
            foreach ([
                str_replace('https://', 'http://', $source['url']),
                str_replace('https://', 'https://user@', $source['url']),
                preg_replace('~^(https://[^/]+)~', '$1:443', $source['url']),
                str_replace('data.gov.il', 'data.gov.il.example.com', str_replace('gisn.tel-aviv.gov.il', 'gisn.tel-aviv.gov.il.example.com', $source['url'])),
                $source['url'].'#other-record',
                $source['url'].'&unexpected=true',
                $source['url'].(str_contains($source['url'], 'data.gov.il') ? '&limit=1' : '&f=json'),
            ] as $url) {
                $invalid = [...$source, 'url' => $url];
                $this->postBusiness(['name' => 'Test source name', 'source' => $invalid])
                    ->assertUnprocessable()->assertJsonValidationErrors('source.url');
                $this->postJson('/api/v1/business-import/businesses/duplicates', ['source' => $invalid])
                    ->assertUnprocessable()->assertJsonValidationErrors('source.url');
            }
            $invalid = [...$source, 'id' => $source['id'].'0'];
            $this->postBusiness(['name' => 'Test source name', 'source' => $invalid])
                ->assertUnprocessable()->assertJsonValidationErrors('source.url');
            $invalid = [...$source, 'provider' => 'unverified_government_source'];
            $this->postBusiness(['name' => 'Test source name', 'source' => $invalid])
                ->assertUnprocessable()->assertJsonValidationErrors('source.provider');
        }
        $gov = $this->govSource(511234567);
        foreach ([
            ['resource_id' => '00000000-0000-0000-0000-000000000000'],
            ['limit' => 10],
            ['filters' => json_encode(['מספר חברה' => 511234568])],
            ['filters' => json_encode(['מספר חברה' => [511234567, 511234568]])],
            ['filters' => json_encode(['מספר חברה' => 511234567, '_id' => 1])],
            ['filters' => json_encode(['_id' => 511234567])],
            ['filters' => '{invalid json}'],
        ] as $overrides) {
            parse_str(parse_url($gov['url'], PHP_URL_QUERY), $query);
            $invalid = [...$gov, 'url' => 'https://data.gov.il/api/3/action/datastore_search?'.http_build_query(array_replace($query, $overrides), '', '&', PHP_QUERY_RFC3986)];
            $this->postBusiness(['name' => 'Test source name', 'source' => $invalid])
                ->assertUnprocessable()->assertJsonValidationErrors('source.url');
        }
        $this->assertDatabaseCount('pages', 0);
        $this->assertDatabaseCount('business_import_sources', 0);
    }

    public function test_tel_aviv_source_id_requires_the_exact_restricted_business_license_expression(): void
    {
        foreach ([
            '1=1',
            "ms_esek_rashi=0 AND ms_esek_mishne=0 AND mahuiot='402100'",
            "ms_esek_rashi=0123 AND ms_esek_mishne=0 AND mahuiot='402100'",
            "ms_esek_rashi=123 AND ms_esek_mishne=-1 AND mahuiot='402100'",
            "ms_esek_rashi=123 AND ms_esek_mishne=0 AND mahuiot='402100' OR 1=1",
            "ms_esek_rashi=123 AND ms_esek_mishne=0 AND mahuiot='unescaped'quote'",
            "ms_esek_rashi=123 AND ms_esek_mishne=0 AND mahuiot='402100\n'",
        ] as $where) {
            $source = $this->telSource(123);
            $source['id'] = '964:'.hash('sha256', $where);
            $source['url'] = 'https://gisn.tel-aviv.gov.il/arcgis/rest/services/IView2/MapServer/964/query?'.http_build_query([
                'where' => $where, 'outFields' => '*', 'returnGeometry' => 'false', 'f' => 'json',
            ], '', '&', PHP_QUERY_RFC3986);
            $this->postBusiness(['name' => 'Test source name', 'source' => $source])
                ->assertUnprocessable()->assertJsonValidationErrors('source.url');
        }
        foreach (['', "402100 'quoted' activity"] as $codes) {
            $this->postBusiness(['name' => 'Test source name', 'source' => $this->telSource(123, codes: $codes)])
                ->assertCreated();
        }
        $this->assertDatabaseCount('pages', 2);
    }

    public function test_government_source_ids_keep_ambiguous_branches_separate_and_claims_protected(): void
    {
        $ids = [];
        foreach ([$this->govSource(511234567), $this->govSource(511234568), $this->govSource(1, companies: false), $this->govSource(2, companies: false), $this->telSource(123), $this->telSource(124)] as $source) {
            $payload = [
                'name' => 'Shared chain name', 'phone' => '03-0000000', 'contact_email' => 'chain@example.com',
                'address' => ['city' => 'Tel Aviv', 'street' => 'Same Street'], 'source' => $source,
            ];
            $this->postJson('/api/v1/business-import/businesses/duplicates', $payload)
                ->assertOk()->assertJsonPath('data.duplicate', false);
            $id = $this->postBusiness($payload)->assertCreated()->json('data.business.id');
            $ids[] = $id;
            $this->postBusiness($payload)->assertOk()->assertJsonPath('data.business.id', $id);
            $owner = User::factory()->create();
            Page::findOrFail($id)->update(['is_unclaimed' => false, 'user_id' => $owner->id]);
            $this->patchJson('/api/v1/business-import/businesses/'.$id, [
                'source' => $source, 'phone' => '03-1111111',
            ])->assertStatus(409)->assertJsonValidationErrors('claimed');
            $this->assertSame('03-0000000', Page::findOrFail($id)->phone);
        }
        $this->assertCount(6, array_unique($ids));
        $this->assertDatabaseCount('business_import_sources', 6);
    }

    public function test_government_sources_reuse_a_confirmed_full_location_across_providers_without_replacing_details(): void
    {
        $existing = $this->payload('original-overture-place');
        $existing['address'] = ['city' => 'Tel Aviv', 'street' => 'Same Street', 'number' => '10'];
        $id = $this->postBusiness($existing)->assertCreated()->json('data.business.id');
        foreach ([$this->govSource(511234567), $this->govSource(1, companies: false), $this->telSource(123)] as $source) {
            $payload = array_replace($existing, [
                'source' => $source, 'phone' => '03-9999999',
                'address' => ['city' => 'Tel Aviv', 'street' => 'Same Street 10'],
            ]);
            $this->postJson('/api/v1/business-import/businesses/duplicates', $payload)
                ->assertOk()->assertJsonPath('data.matches.0.id', $id);
            $this->postBusiness($payload)->assertOk()->assertJsonPath('data.business.id', $id)
                ->assertJsonPath('data.business.phone', $existing['phone']);
            $this->assertSame($id, BusinessImportSource::where('source_id', $source['id'])->sole()->page_id);
        }
        $this->assertDatabaseCount('pages', 1);
        $this->assertDatabaseCount('business_import_sources', 4);
    }

    public function test_reused_beer_sheva_row_ids_cannot_replace_businesses_even_at_the_same_address(): void
    {
        $source = $this->govSource(25, companies: false);
        $source['metadata']['original_name'] = 'Original business בע"מ';
        $payload = [
            'name' => $source['metadata']['original_name'], 'source' => $source,
            'address' => ['city' => 'Beersheba', 'street' => 'Original Street', 'number' => '10'],
        ];
        $id = $this->postBusiness($payload)->assertCreated()->json('data.business.id');
        $payload['name'] = 'Original business';
        $this->postBusiness($payload)->assertOk()->assertJsonPath('data.business.id', $id);
        $this->patchJson('/api/v1/business-import/businesses/'.$id, ['source' => $source])
            ->assertOk()->assertJsonPath('data.business.name', 'Original business');

        $reusedSource = $source;
        $reusedSource['metadata']['original_name'] = 'Unrelated replacement business';
        foreach ([
            ['name' => 'Unrelated replacement business', 'source' => $reusedSource],
            ['source' => $reusedSource],
            ['name' => 'Unrelated replacement business', 'source' => $source],
        ] as $patch) {
            $this->patchJson('/api/v1/business-import/businesses/'.$id, $patch)
                ->assertStatus(409)->assertJsonValidationErrors('source_conflict');
            $this->postJson('/api/v1/business-import/businesses/duplicates', $patch)
                ->assertStatus(409)->assertJsonValidationErrors('source_conflict');
        }
        $moved = array_replace($payload, ['address' => ['city' => 'Beersheba', 'street' => 'Another Street', 'number' => '10']]);
        $this->postBusiness($moved)->assertStatus(409)->assertJsonValidationErrors('source_conflict');
        $namelessSource = $source;
        unset($namelessSource['metadata']['original_name']);
        $this->patchJson('/api/v1/business-import/businesses/'.$id, ['source' => $namelessSource])
            ->assertStatus(409)->assertJsonValidationErrors('source_conflict');
        $this->assertSame('Original business', Page::findOrFail($id)->name);
        $this->assertSame($source['metadata'], BusinessImportSource::query()->sole()->metadata);
        $this->assertDatabaseCount('pages', 1);
    }

    public function test_long_encoded_websites_import_without_losing_their_distinct_paths(): void
    {
        $prefix = 'https://example.com/'.str_repeat('%D7%A9', 50).'/';
        $payloads = [];
        foreach (['school-one', 'school-two'] as $id) {
            $payloads[] = [...$this->payload($id), 'website' => $prefix.$id];
        }
        $batch = ['client_import_id' => (string) Str::uuid(), 'businesses' => $payloads];
        $items = $this->postJson('/api/v1/business-import/businesses/batch', $batch)
            ->assertCreated()->assertJsonPath('data.created_count', 2)->json('data.items');
        $this->assertSame('text', Schema::getColumnType('page_identity_keys', 'normalized_website'));
        foreach ($items as $index => $item) {
            $page = Page::findOrFail($item['business']['id']);
            $this->assertSame($payloads[$index]['website'], $page->setup['website']);
            $this->assertSame(substr($payloads[$index]['website'], strlen('https://')), $page->identityKey->normalized_website);
            $this->assertGreaterThan(255, strlen($page->identityKey->normalized_website));
        }
        $this->assertNotSame(
            Page::findOrFail($items[0]['business']['id'])->identityKey->normalized_website,
            Page::findOrFail($items[1]['business']['id'])->identityKey->normalized_website,
        );
        $this->postJson('/api/v1/business-import/businesses/batch', $batch)
            ->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.items', $items);
        $this->assertDatabaseCount('pages', 2);
    }

    public function test_a_long_display_address_keeps_every_validated_component_and_replays_the_batch(): void
    {
        $payload = $this->payload('312f828f-d6d9-4f3a-88f0-febfcf3599d7');
        $payload['name'] = 'Creative Corner';
        $payload['source']['metadata']['original_name'] = $payload['name'];
        $street = 'Dizengoff Centre, 2nd floor.\n\nAcross the bridge from עגבנייה turn left after the bridge, 7th shop on the left.\nOr you can enter from \'be\' on the corner of dizengoff and king keorge, go upstairs in be and walk straight ahead, 7th shop on the left.';
        $payload['address'] = ['street' => $street, 'city' => 'Tel Aviv'];
        $expected = $street.', Tel Aviv';
        $this->assertSame(249, mb_strlen($street));
        $this->assertSame(259, mb_strlen($expected));
        $this->assertSame('text', Schema::getColumnType('pages', 'address'));
        $batch = ['client_import_id' => (string) Str::uuid(), 'businesses' => [$payload]];
        $items = $this->postJson('/api/v1/business-import/businesses/batch', $batch)
            ->assertCreated()->assertJsonPath('data.created_count', 1)
            ->assertJsonPath('data.items.0.business.address.street', $street)
            ->assertJsonPath('data.items.0.business.address.city', 'Tel Aviv')->json('data.items');
        $page = Page::findOrFail($items[0]['business']['id']);
        $this->assertSame($expected, $page->address);
        $this->assertSame($street, $page->setup['address']['street']);
        $this->getJson('/api/v1/pages/'.$page->id)->assertOk()
            ->assertJsonPath('data.address', $expected)
            ->assertJsonPath('data.address_details.street', $street)
            ->assertJsonPath('data.address_details.city', 'Tel Aviv');
        $this->postJson('/api/v1/business-import/businesses/batch', $batch)->assertOk()
            ->assertJsonPath('data.replayed', true)->assertJsonPath('data.items', $items);
        $this->patchJson('/api/v1/business-import/businesses/'.$page->id, ['source' => $payload['source']])
            ->assertOk()->assertJsonPath('data.business.address.street', $street);
        $this->assertSame($expected, $page->fresh()->address);
        $this->assertDatabaseCount('pages', 1);
        $this->assertDatabaseCount('business_import_sources', 1);
    }

    public function test_address_migration_refuses_a_lossy_rollback(): void
    {
        $payload = $this->payload('protected-address-rollback');
        $payload['address'] = ['street' => str_repeat('a', 255), 'city' => 'Tel Aviv'];
        $pageId = $this->postBusiness($payload)->assertCreated()->json('data.business.id');
        $address = Page::findOrFail($pageId)->address;
        $migration = require database_path('migrations/2026_09_10_000300_expand_page_address_column.php');
        try {
            $migration->down();
            $this->fail('The migration allowed a rollback that would truncate an existing address.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Cannot shrink the page address column while longer addresses are stored.', $exception->getMessage());
        }
        $this->assertSame('text', Schema::getColumnType('pages', 'address'));
        $this->assertSame($address, Page::findOrFail($pageId)->address);
    }

    private function govSource(int $recordId, bool $companies = true): array
    {
        $resourceId = $companies ? 'f004176c-b85f-4542-8901-7b3176f9a054' : '7d4c61e2-2416-453e-8efb-bd02ec89db35';
        $id = $resourceId.':'.$recordId;

        return [
            'provider' => 'data_gov_ckan', 'id' => $id,
            'url' => 'https://data.gov.il/api/3/action/datastore_search?'.http_build_query([
                'resource_id' => $resourceId, 'limit' => 1,
                'filters' => json_encode([$companies ? 'מספר חברה' : '_id' => $recordId], JSON_UNESCAPED_UNICODE),
            ], '', '&', PHP_QUERY_RFC3986),
            'metadata' => ['source_id' => $id, 'original_name' => 'Shared chain name'],
        ];
    }

    private function telSource(int $main, int $sub = 0, string $codes = '402100'): array
    {
        $where = "ms_esek_rashi={$main} AND ms_esek_mishne={$sub} AND mahuiot='".str_replace("'", "''", $codes)."'";
        $id = '964:'.hash('sha256', $where);

        return [
            'provider' => 'tel_aviv_business_licenses', 'id' => $id,
            'url' => 'https://gisn.tel-aviv.gov.il/arcgis/rest/services/IView2/MapServer/964/query?'.http_build_query([
                'where' => $where, 'outFields' => '*', 'returnGeometry' => 'false', 'f' => 'json',
            ], '', '&', PHP_QUERY_RFC3986),
            'metadata' => ['source_id' => $id, 'source_where' => $where, 'original_name' => 'Shared chain name'],
        ];
    }

    private function authenticateClient(): void
    {
        $client = Client::factory()->asClientCredentials()->create();
        BusinessImportClient::query()->create([
            'oauth_client_id' => $client->getKey(), 'name' => 'Overture import test',
            'allowed_scopes' => [BusinessImportClient::SCOPE_READ, BusinessImportClient::SCOPE_WRITE], 'active' => true,
        ]);
        Passport::actingAsClient($client, [BusinessImportClient::SCOPE_READ, BusinessImportClient::SCOPE_WRITE]);
    }

    private function postBusiness(array $payload)
    {
        return $this->postJson('/api/v1/business-import/businesses', $payload, ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function payload(string $id): array
    {
        return ['name' => 'Shared chain name', 'phone' => '03-0000000', 'contact_email' => 'chain@example.com', 'source' => $this->source($id)];
    }

    private function source(string $id): array
    {
        return [
            'provider' => 'overture_places', 'id' => $id,
            'url' => 'https://explore.overturemaps.org/?feature=places.place.'.$id,
            'metadata' => ['overture_id' => $id, 'category' => 'original_unmapped_category', 'release' => '2026-08-19.0', 'confidence' => 0.2],
        ];
    }
}
