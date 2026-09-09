<?php

namespace Tests\Feature;

use App\Models\BusinessImportApiLog;
use App\Models\BusinessImportClient;
use App\Models\Page;
use App\Services\PageIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\TestCase;

class BusinessImportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_registered_clients_with_the_required_scope_can_use_the_api(): void
    {
        $genericClient = Client::factory()->asClientCredentials()->create();
        Passport::actingAsClient($genericClient, [BusinessImportClient::SCOPE_READ]);

        $this->getJson('/api/v1/business-import/businesses')->assertForbidden();

        $readOnlyClient = $this->businessClient([BusinessImportClient::SCOPE_READ]);
        Passport::actingAsClient($readOnlyClient, [BusinessImportClient::SCOPE_READ]);

        $this->getJson('/api/v1/business-import/businesses')->assertOk();
        $this->postJson('/api/v1/business-import/businesses', $this->businessPayload())
            ->assertForbidden();

        Passport::actingAsClient($readOnlyClient, ['*']);
        $this->getJson('/api/v1/business-import/businesses')->assertForbidden();

        Passport::actingAsClient($readOnlyClient, [BusinessImportClient::SCOPE_READ]);
        $this->getJson('/api/v1/admin/users')->assertUnauthorized();
    }

    public function test_client_command_creates_a_dedicated_client_and_revoke_command_disables_it(): void
    {
        $this->artisan('business-import:client', [
            'name' => 'Automation Worker',
            '--read-only' => true,
        ])->assertSuccessful();

        $registration = BusinessImportClient::query()->sole();
        $this->assertSame([BusinessImportClient::SCOPE_READ], $registration->allowed_scopes);
        $this->assertTrue($registration->active);

        $this->artisan('business-import:revoke-client', [
            'client_id' => $registration->oauth_client_id,
        ])->assertSuccessful();

        $this->assertFalse($registration->fresh()->active);
        $this->assertTrue($registration->oauthClient()->firstOrFail()->revoked);
    }

    public function test_shared_contacts_allow_distinct_locations_and_contact_only_lookup_still_finds_pages(): void
    {
        $client = $this->businessClient();
        Passport::actingAsClient($client, [
            BusinessImportClient::SCOPE_READ,
            BusinessImportClient::SCOPE_WRITE,
        ]);

        $created = $this->postBusiness($this->businessPayload())
            ->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'false')
            ->assertHeader('X-Request-ID')
            ->assertJsonPath('data.operation', 'created')
            ->assertJsonPath('data.business.type', Page::TYPE_BUSINESS)
            ->assertJsonPath('data.business.is_unclaimed', true)
            ->assertJsonPath('data.business.address.city', 'Tel Aviv');

        $pageId = $created->json('data.business.id');
        $this->assertDatabaseHas('business_import_pages', [
            'page_id' => $pageId,
            'created_by_oauth_client_id' => $client->getKey(),
        ]);
        $this->assertDatabaseHas('page_identity_keys', [
            'page_id' => $pageId,
            'normalized_email' => 'info@example.com',
            'normalized_phone' => '97230000000',
        ]);

        $sameEmail = $this->businessPayload();
        $sameEmail['name'] = 'Different Display Name';
        $sameEmail['phone'] = '03-9999999';
        $sameEmail['address']['city'] = 'Jerusalem';
        $sameEmail['address']['neighborhood'] = null;

        $this->postBusiness($sameEmail)
            ->assertCreated()
            ->assertJsonPath('data.business.address.city', 'Jerusalem');

        $this->postJson('/api/v1/business-import/businesses/duplicates', [
            'phone' => '(03) 000-0000',
        ])->assertOk()
            ->assertJsonPath('data.duplicate', true)
            ->assertJsonPath('data.matches.0.id', $pageId)
            ->assertJsonPath('data.matches.0.matched_on.0', 'phone');

        $this->assertSame(3, BusinessImportApiLog::query()->count());
        $this->assertDatabaseHas('business_import_api_logs', [
            'oauth_client_id' => $client->getKey(),
            'route_name' => 'business-import.businesses.store',
            'status_code' => 201,
        ]);
    }

    public function test_single_create_requires_and_replays_an_idempotency_key(): void
    {
        $client = $this->businessClient();
        Passport::actingAsClient($client, [BusinessImportClient::SCOPE_WRITE]);
        $payload = $this->businessPayload();

        $this->postJson('/api/v1/business-import/businesses', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');
        $this->postJson('/api/v1/business-import/businesses', $payload, [
            'Idempotency-Key' => 'not-a-uuid',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');

        $idempotencyKey = (string) Str::uuid();
        $created = $this->postBusiness($payload, $idempotencyKey)
            ->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'false')
            ->assertJsonPath('data.replayed', false)
            ->json('data.business');

        $this->postBusiness($payload, $idempotencyKey)
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true')
            ->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.business.id', $created['id']);

        $changedPayload = $payload;
        $changedPayload['name'] = 'Different payload';
        $this->postBusiness($changedPayload, $idempotencyKey)
            ->assertStatus(409)
            ->assertJsonPath(
                'errors.idempotency_key.0',
                'The Idempotency-Key was already used for a different payload.'
            );

        $this->assertDatabaseCount('pages', 1);
        $this->assertDatabaseHas('business_import_idempotency_keys', [
            'oauth_client_id' => $client->getKey(),
            'idempotency_key' => $idempotencyKey,
            'status' => 'completed',
            'response_status' => 201,
        ]);
    }

    public function test_search_uses_existing_categories_and_locations(): void
    {
        $client = $this->businessClient();
        Passport::actingAsClient($client, [BusinessImportClient::SCOPE_READ, BusinessImportClient::SCOPE_WRITE]);
        $pageId = $this->postBusiness($this->businessPayload())
            ->assertCreated()
            ->json('data.business.id');

        $this->getJson('/api/v1/business-import/businesses?'.http_build_query([
            'contact_email' => 'INFO@example.com',
            'category_key' => 'professionals.electricians',
            'city' => 'tel-aviv',
            'neighborhood' => 'ramat-aviv',
        ]))->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.businesses.0.id', $pageId);

        $this->getJson('/api/v1/business-import/businesses?city=Unknown')->assertUnprocessable();
        $this->getJson('/api/v1/business-import/businesses?neighborhood=Ramat+Aviv')->assertUnprocessable();
    }

    public function test_partial_update_preserves_omitted_fields_and_claimed_pages_are_protected(): void
    {
        $client = $this->businessClient();
        Passport::actingAsClient($client, [BusinessImportClient::SCOPE_WRITE]);
        $created = $this->postBusiness($this->businessPayload())
            ->assertCreated()
            ->json('data.business');

        $this->patchJson('/api/v1/business-import/businesses/'.$created['id'], [
            'public_description' => 'Updated description only.',
        ])->assertOk()
            ->assertJsonPath('data.business.public_description', 'Updated description only.')
            ->assertJsonPath('data.business.contact_email', $created['contact_email'])
            ->assertJsonPath('data.business.phone', $created['phone'])
            ->assertJsonPath('data.business.socials.instagram', $created['socials']['instagram'])
            ->assertJsonPath('data.business.opening_hours.1.opens_at', '09:00');

        $this->postJson('/api/v1/business-import/businesses', [
            'id' => $created['id'],
            'phone' => '03-1111111',
        ])->assertOk()
            ->assertJsonPath('data.operation', 'updated')
            ->assertJsonPath('data.business.name', $created['name'])
            ->assertJsonPath('data.business.phone', '03-1111111');

        Page::query()->findOrFail($created['id'])->update(['is_unclaimed' => false]);

        $this->patchJson('/api/v1/business-import/businesses/'.$created['id'], [
            'phone' => '03-2222222',
        ])->assertStatus(409)
            ->assertJsonPath('errors.claimed.0', 'Claimed business pages cannot be changed by the import API.');
    }

    public function test_batch_accepts_one_hundred_businesses_and_is_idempotent(): void
    {
        $client = $this->businessClient();
        Passport::actingAsClient($client, [BusinessImportClient::SCOPE_WRITE]);
        $businesses = collect(range(1, 100))->map(function (int $number): array {
            $payload = $this->businessPayload();
            $payload['name'] = sprintf('Batch Business %03d', $number);
            $payload['contact_email'] = sprintf('batch-%03d@example.com', $number);
            $payload['phone'] = sprintf('050-100-%04d', $number);

            return $payload;
        })->all();
        $request = [
            'client_import_id' => (string) Str::uuid(),
            'businesses' => $businesses,
        ];

        $this->postJson('/api/v1/business-import/businesses/batch', $request)
            ->assertCreated()
            ->assertJsonPath('data.input_count', 100)
            ->assertJsonPath('data.created_count', 100)
            ->assertJsonPath('data.updated_count', 0)
            ->assertJsonPath('data.replayed', false)
            ->assertJsonCount(100, 'data.items');

        $this->assertDatabaseCount('pages', 100);
        $this->assertDatabaseCount('business_import_pages', 100);

        $this->postJson('/api/v1/business-import/businesses/batch', $request)
            ->assertOk()
            ->assertJsonPath('data.created_count', 100)
            ->assertJsonPath('data.replayed', true);
        $this->assertDatabaseCount('pages', 100);

        $request['businesses'][0]['name'] = 'A changed retry payload';
        $this->postJson('/api/v1/business-import/businesses/batch', $request)
            ->assertStatus(409)
            ->assertJsonValidationErrors('client_import_id');
    }

    public function test_create_requires_only_the_fields_needed_for_a_valid_business_page(): void
    {
        $client = $this->businessClient();
        Passport::actingAsClient($client, [BusinessImportClient::SCOPE_WRITE]);

        $minimal = [
            'name' => 'Minimal Business',
            'category_key' => 'professionals.electricians',
            'address' => ['city' => 'Tel Aviv'],
        ];

        $this->postBusiness($minimal)
            ->assertCreated()
            ->assertJsonPath('data.business.name', 'Minimal Business')
            ->assertJsonPath('data.business.contact_email', null)
            ->assertJsonPath('data.business.opening_hours', []);

        $this->postBusiness([
            'name' => 'Incomplete Business',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['category_key', 'address.city']);
    }

    public function test_every_chain_location_is_imported_and_duplicate_lookup_finds_branches_beyond_ten_matches(): void
    {
        Passport::actingAsClient($this->businessClient(), [BusinessImportClient::SCOPE_READ, BusinessImportClient::SCOPE_WRITE]);
        $ids = [];
        foreach (range(1, 12) as $number) {
            $payload = $this->businessPayload();
            $payload['address']['number'] = (string) $number;
            $ids[$number] = $this->postBusiness($payload)->assertCreated()->json('data.business.id');
        }
        $payload = $this->businessPayload();
        $payload['address']['number'] = '12';
        $this->postJson('/api/v1/business-import/businesses/duplicates', $payload)
            ->assertOk()->assertJsonCount(1, 'data.matches')
            ->assertJsonPath('data.matches.0.id', $ids[12])
            ->assertJsonPath('data.matches.0.address.number', '12');
        $this->postBusiness($payload)->assertStatus(409)->assertJsonPath('data.matches.0.id', $ids[12]);

        $this->patchJson('/api/v1/business-import/businesses/'.$ids[12], ['public_description' => 'Only the twelfth branch.'])
            ->assertOk()->assertJsonPath('data.business.public_description', 'Only the twelfth branch.');
        $this->assertSame('Short public description.', Page::findOrFail($ids[1])->public_description);
        $this->getJson('/api/v1/business-import/businesses?id='.$ids[12])
            ->assertOk()->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.businesses.0.id', $ids[12]);
        $this->getJson('/api/v1/business-import/businesses?id=999999')
            ->assertOk()->assertJsonCount(0, 'data.businesses');
        $this->getJson('/api/v1/business-import/businesses?id=0')->assertUnprocessable();

        $payload['address']['city'] = 'Jerusalem';
        $payload['address']['neighborhood'] = null;
        $this->postBusiness($payload)->assertCreated();
        $payload['address']['street'] = 'Different Street';
        $this->postBusiness($payload)->assertCreated();
        $this->assertDatabaseCount('pages', 14);
    }

    public function test_same_location_is_recognized_after_company_suffix_cleanup_and_address_formatting(): void
    {
        Passport::actingAsClient($this->businessClient(), [BusinessImportClient::SCOPE_READ, BusinessImportClient::SCOPE_WRITE]);
        $payload = $this->businessPayload();
        $payload['name'] = 'קפה בדיקה בע״מ';
        unset($payload['phone'], $payload['contact_email'], $payload['website']);
        $pageId = $this->postBusiness($payload)->assertCreated()->json('data.business.id');
        $payload['name'] = 'קפה בדיקה';
        $payload['address'] = ['city' => 'Tel Aviv', 'street' => 'Example Street 10'];

        $this->postJson('/api/v1/business-import/businesses/duplicates', $payload)
            ->assertOk()->assertJsonPath('data.matches.0.id', $pageId)
            ->assertJsonPath('data.matches.0.matched_on.0', 'address');
        $this->postBusiness($payload)->assertStatus(409);
        $payload['address']['street'] = 'Example Street';
        $this->postBusiness($payload)->assertStatus(409)->assertJsonPath('data.matches.0.id', $pageId);
        $this->assertDatabaseCount('pages', 1);
    }

    public function test_confirmed_location_has_priority_over_ten_older_incomplete_addresses(): void
    {
        Passport::actingAsClient($this->businessClient(), [BusinessImportClient::SCOPE_READ, BusinessImportClient::SCOPE_WRITE]);
        $payload = $this->businessPayload();
        $firstId = $this->postBusiness($payload)->assertCreated()->json('data.business.id');
        $first = Page::findOrFail($firstId);
        // Reproduce existing legacy rows, which can predate the import API's duplicate rules.
        foreach (range(1, 10) as $number) {
            $legacy = $first->replicate();
            $legacy->setup = array_replace($first->setup, ['address' => ['city' => 'Tel Aviv']]);
            $legacy->save();
            app(PageIdentityService::class)->sync($legacy);
        }
        $confirmed = $first->replicate();
        $confirmed->setup = array_replace($first->setup, ['address' => ['city' => 'Tel Aviv', 'street' => 'Example Street', 'number' => '12']]);
        $confirmed->save();
        app(PageIdentityService::class)->sync($confirmed);
        $payload['address']['number'] = '12';
        $this->postJson('/api/v1/business-import/businesses/duplicates', $payload)
            ->assertOk()->assertJsonCount(1, 'data.matches')
            ->assertJsonPath('data.matches.0.id', $confirmed->id);
    }

    public function test_missing_house_number_is_ambiguous_and_claimed_branch_does_not_block_a_different_location(): void
    {
        Passport::actingAsClient($this->businessClient(), [BusinessImportClient::SCOPE_READ, BusinessImportClient::SCOPE_WRITE]);
        $payload = $this->businessPayload();
        $pageId = $this->postBusiness($payload)->assertCreated()->json('data.business.id');
        unset($payload['address']['number']);
        $this->postBusiness($payload)->assertStatus(409)->assertJsonPath('data.matches.0.id', $pageId);

        Page::findOrFail($pageId)->update(['is_unclaimed' => false]);
        $payload['address']['number'] = '11';
        $this->postBusiness($payload)->assertCreated();
        $this->patchJson('/api/v1/business-import/businesses/'.$pageId, ['phone' => '03-2222222'])
            ->assertStatus(409)->assertJsonValidationErrors('claimed');
        $this->assertDatabaseCount('pages', 2);
    }

    private function businessClient(array $allowedScopes = [
        BusinessImportClient::SCOPE_READ,
        BusinessImportClient::SCOPE_WRITE,
    ]): Client
    {
        $client = Client::factory()->asClientCredentials()->create();
        BusinessImportClient::query()->create([
            'oauth_client_id' => $client->getKey(),
            'name' => 'Test Business Import Client',
            'allowed_scopes' => $allowedScopes,
            'active' => true,
        ]);

        return $client;
    }

    private function postBusiness(array $payload, ?string $idempotencyKey = null)
    {
        return $this->postJson('/api/v1/business-import/businesses', $payload, [
            'Idempotency-Key' => $idempotencyKey ?? (string) Str::uuid(),
        ]);
    }

    private function businessPayload(): array
    {
        return [
            'type' => Page::TYPE_BUSINESS,
            'name' => 'Example Business',
            'public_description' => 'Short public description.',
            'contact_email' => 'info@example.com',
            'phone' => '03-0000000',
            'whatsapp' => '97230000000',
            'website' => 'https://example.com',
            'category_key' => 'professionals.electricians',
            'address' => [
                'street' => 'Example Street',
                'number' => '10',
                'city' => 'Tel Aviv',
                'neighborhood' => 'Ramat Aviv',
            ],
            'socials' => [
                'facebook' => 'https://facebook.com/example',
                'instagram' => 'https://instagram.com/example',
                'tiktok' => null,
                'telegram' => null,
                'x' => null,
            ],
            'service_areas' => ['Tel Aviv', 'Jerusalem'],
            'specialties' => ['Electrical repairs', 'Lighting installation', 'Fault detection'],
            'opening_hours' => [
                ['weekday' => 'sunday', 'is_open' => false, 'opens_at' => null, 'closes_at' => null],
                ['weekday' => 'monday', 'is_open' => true, 'opens_at' => '09:00', 'closes_at' => '17:00'],
                ['weekday' => 'tuesday', 'is_open' => true, 'opens_at' => '09:00', 'closes_at' => '17:00'],
                ['weekday' => 'wednesday', 'is_open' => true, 'opens_at' => '09:00', 'closes_at' => '17:00'],
                ['weekday' => 'thursday', 'is_open' => true, 'opens_at' => '09:00', 'closes_at' => '17:00'],
                ['weekday' => 'friday', 'is_open' => true, 'opens_at' => '09:00', 'closes_at' => '13:00'],
                ['weekday' => 'saturday', 'is_open' => false, 'opens_at' => null, 'closes_at' => null],
            ],
        ];
    }
}
