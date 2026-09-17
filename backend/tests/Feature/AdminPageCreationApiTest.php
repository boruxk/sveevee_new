<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\User;
use App\Services\PageIdentityService;
use App\Support\AccountNotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class AdminPageCreationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('public');
    }

    public function test_creation_and_owner_options_require_an_authenticated_admin(): void
    {
        $this->postJson('/api/v1/admin/pages', $this->form())->assertUnauthorized();
        $this->getJson('/api/v1/admin/page-owner-options?without_business_page=1')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/admin/pages', $this->form())->assertForbidden();
        $this->getJson('/api/v1/admin/page-owner-options?without_business_page=1')->assertForbidden();
        $this->assertDatabaseCount('pages', 0);
    }

    public function test_admin_creates_full_business_with_selected_owner_and_uploads(): void
    {
        $admin = $this->admin();
        $owner = User::factory()->create();
        Sanctum::actingAs($admin);
        $response = $this->post('/api/v1/admin/pages', [
            ...$this->form(), 'user_id' => (string) $owner->id,
            'setup' => json_encode($this->formSetup(), JSON_THROW_ON_ERROR),
            'logo' => UploadedFile::fake()->image('logo.png', 32, 32),
            'banner' => UploadedFile::fake()->image('banner.jpg', 48, 32),
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.save_outcome', 'created')->assertJsonPath('data.type', 'business')
            ->assertJsonPath('data.user_id', $owner->id)->assertJsonPath('data.is_unclaimed', false)
            ->assertJsonPath('data.website', 'https://example.org')
            ->assertJsonPath('data.address_details.city', 'Haifa')
            ->assertJsonPath('data.features.store', true)->assertJsonPath('data.features.events', false);
        $page = Page::findOrFail($response->json('data.id'));
        $this->assertSame($admin->id, $page->created_by_user_id);
        $this->assertNotNull($page->claimed_at);
        $this->assertSame('Top contact', $page->public_description);
        $this->assertSame('business@example.org', $page->contact_email);
        $this->assertSame('0501234567', $page->phone);
        $this->assertSame('Herzl, 10, Hadar, Haifa', $page->address);
        $this->assertSame('0507654321', $page->setup['contact']['whatsapp']);
        $this->assertSame('https://instagram.com/example', $page->setup['socials']['instagram']);
        $this->assertSame(['Haifa', 'Tel Aviv'], $page->setup['service_areas']);
        $this->assertSame(['Repairs', 'Custom work'], $page->setup['specialties']);
        $this->assertCount(7, $page->setup['opening_hours']);
        $this->assertSame('09:30', $page->setup['opening_hours'][1]['opens_at']);
        $this->assertSame(['weekday' => 'tuesday', 'is_open' => false, 'opens_at' => null, 'closes_at' => null], $page->setup['opening_hours'][2]);
        $this->assertTrue($page->setup['features']['price_list']);
        $this->assertFalse($page->setup['features']['events']);
        $this->assertArrayNotHasKey('imported_categories', $page->setup);
        $this->assertSame('logo.png', $page->logo_original_name);
        $this->assertSame('banner.jpg', $page->banner_original_name);
        $this->assertStringEndsWith('.webp', $page->logo_path);
        $this->assertStringEndsWith('.webp', $page->banner_path);
        Storage::disk('public')->assertExists([$page->logo_path, $page->banner_path]);
        $this->assertDatabaseHas('page_identity_keys', ['page_id' => $page->id, 'normalized_name' => 'admin created business']);
        $this->assertSame(1, $owner->notifications()->where('type', AccountNotificationType::PAGE_ASSIGNED)->count());
        $this->assertSame(0, $admin->pages()->count());
    }

    public function test_without_owner_creates_unclaimed_page_under_worker_preserving_form_and_media(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);
        $response = $this->post('/api/v1/admin/pages', [
            ...$this->form(), 'user_id' => null,
            'logo' => UploadedFile::fake()->image('unclaimed.png', 32, 32),
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.is_unclaimed', true)->assertJsonPath('data.user_id', null)
            ->assertJsonPath('data.owner', null)->assertJsonPath('data.save_outcome', 'created');
        $page = Page::findOrFail($response->json('data.id'));
        $this->assertTrue($page->user->hasRole('ai_worker'));
        $this->assertSame($admin->id, $page->created_by_user_id);
        $this->assertNull($page->claimed_at);
        $this->assertSame('Haifa', $page->setup['address']['city']);
        $this->assertTrue($page->setup['features']['store']);
        Storage::disk('public')->assertExists($page->logo_path);
        $this->assertSame(0, $admin->pages()->count());
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_only_name_and_category_are_required_and_admin_email_is_never_used_as_contact(): void
    {
        Sanctum::actingAs($this->admin());
        $response = $this->postJson('/api/v1/admin/pages', [
            'name' => 'Minimal business', 'category_key' => 'professionals.electricians',
        ])->assertCreated()->assertJsonPath('data.contact_email', null)
            ->assertJsonPath('data.phone', null)->assertJsonPath('data.address', null)
            ->assertJsonPath('data.opening_hours', []);
        $this->assertNull(Page::findOrFail($response->json('data.id'))->contact_email);
        $this->assertSame([], Page::findOrFail($response->json('data.id'))->setup['opening_hours']);
    }

    public function test_explicit_unknown_hours_stay_empty_for_assigned_and_unclaimed_pages(): void
    {
        Sanctum::actingAs($this->admin());
        foreach ([null, User::factory()->create()->id] as $ownerId) {
            $response = $this->postJson('/api/v1/admin/pages', [
                ...$this->form(), 'user_id' => $ownerId, 'setup' => ['opening_hours' => []],
            ])->assertCreated()->assertJsonPath('data.opening_hours', []);
            $page = Page::findOrFail($response->json('data.id'));
            $this->assertSame([], $page->setup['opening_hours']);
            $this->getJson('/api/v1/pages/'.$page->id)->assertOk()->assertJsonPath('data.opening_hours', []);
        }
    }

    public function test_malformed_setup_and_open_days_without_valid_times_are_rejected(): void
    {
        Sanctum::actingAs($this->admin());
        foreach ([
            'not-json', 'null', 'true', 42, ['not-an-object'],
            ['contact' => 'bad'], ['address' => ['city' => ['bad']]],
            ['socials' => ['instagram' => ['bad']]], ['features' => ['store' => ['bad']]],
            ['opening_hours' => 'bad'], ['opening_hours' => ['monday']],
            ['opening_hours' => [['weekday' => 'monday', 'is_open' => true]]],
            ['opening_hours' => [['weekday' => 'monday', 'is_open' => 1, 'opens_at' => '09:00']]],
            ['opening_hours' => [['weekday' => 'monday', 'is_open' => '1', 'closes_at' => '17:00']]],
            ['opening_hours' => [['weekday' => 'monday', 'is_open' => true, 'opens_at' => '25:00', 'closes_at' => '17:00']]],
        ] as $setup) {
            $this->postJson('/api/v1/admin/pages', [...$this->form(), 'setup' => $setup])->assertUnprocessable();
        }
        $this->assertDatabaseCount('pages', 0);
        $this->postJson('/api/v1/admin/pages', [
            ...$this->form(), 'setup' => ['opening_hours' => [['weekday' => 'monday', 'is_open' => false]]],
        ])->assertCreated()->assertJsonPath('data.opening_hours.1.is_open', false)
            ->assertJsonPath('data.opening_hours.1.opens_at', null)
            ->assertJsonPath('data.opening_hours.1.closes_at', null);
    }

    public function test_explicit_admin_creation_never_adopts_or_overwrites_matching_or_admin_owned_pages(): void
    {
        $admin = $this->admin();
        $existingOwner = User::factory()->create();
        $existing = $this->managedPage($existingOwner, ['name' => 'Admin created business', 'phone' => '0501234567']);
        $adminPage = $this->managedPage($admin, ['name' => 'Admin private business']);
        $before = [$existing->fresh()->getAttributes(), $adminPage->fresh()->getAttributes()];
        $newOwner = User::factory()->create();
        Sanctum::actingAs($admin);
        $response = $this->postJson('/api/v1/admin/pages', [...$this->form(), 'user_id' => $newOwner->id])->assertCreated();
        $this->assertNotContains($response->json('data.id'), [$existing->id, $adminPage->id]);
        $this->assertSame($before, [$existing->fresh()->getAttributes(), $adminPage->fresh()->getAttributes()]);
        $this->assertDatabaseCount('pages', 3);
        $this->assertDatabaseCount('page_claim_requests', 0);
    }

    public function test_owner_options_filter_in_database_before_limit_and_allow_community_only_users(): void
    {
        foreach (range(1, 42) as $number) {
            $user = User::factory()->create(['name' => 'Candidate A '.sprintf('%02d', $number)]);
            $this->managedPage($user);
        }
        $free = User::factory()->create(['name' => 'Candidate Z Free']);
        $communityOnly = User::factory()->create(['name' => 'Candidate Z Community']);
        $community = $this->managedPage($communityOnly, ['type' => Page::TYPE_COMMUNITY]);
        $unclaimedOnly = User::factory()->create(['name' => 'Candidate Z Unclaimed']);
        $this->managedPage($unclaimedOnly, ['is_unclaimed' => true]);
        User::factory()->create(['name' => 'Candidate Banned', 'banned_at' => now()]);
        User::factory()->create(['name' => 'Candidate Admin', 'role' => 'admin']);
        User::factory()->create(['name' => 'Candidate Worker', 'role' => 'ai_worker']);
        Sanctum::actingAs($this->admin());
        $response = $this->getJson('/api/v1/admin/page-owner-options?without_business_page=1&q=Candidate')->assertOk();
        $this->assertEqualsCanonicalizing([$free->id, $communityOnly->id, $unclaimedOnly->id], array_column($response->json('data.items'), 'id'));
        $this->assertSame($community->id, collect($response->json('data.items'))->firstWhere('id', $communityOnly->id)['page_ids_by_type']['community']);
        $this->getJson('/api/v1/admin/page-owner-options?q=Candidate%20A')->assertOk()->assertJsonCount(40, 'data.items');
    }

    public function test_community_only_owner_receives_a_new_business_without_modifying_community(): void
    {
        $owner = User::factory()->create();
        $community = $this->managedPage($owner, ['type' => Page::TYPE_COMMUNITY, 'name' => 'Existing community']);
        $before = $community->fresh()->getAttributes();
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/admin/pages', [...$this->form(), 'user_id' => $owner->id])->assertCreated();
        $this->assertSame($before, $community->fresh()->getAttributes());
        $this->assertSame(2, $owner->pages()->count());
    }

    public function test_stale_owner_selection_is_rejected_and_staged_uploads_are_cleaned(): void
    {
        $owner = User::factory()->create();
        Sanctum::actingAs($this->admin());
        $options = $this->getJson('/api/v1/admin/page-owner-options?without_business_page=1&q='.urlencode($owner->email))->assertOk();
        $this->assertSame([$owner->id], array_column($options->json('data.items'), 'id'));
        $existing = $this->managedPage($owner, ['name' => 'Created after owner selection']);
        $before = $existing->fresh()->getAttributes();
        $this->post('/api/v1/admin/pages', [
            ...$this->form(), 'user_id' => $owner->id, 'logo' => UploadedFile::fake()->image('stale.png', 32, 32),
        ], ['Accept' => 'application/json'])->assertConflict()->assertJsonValidationErrors('user_id');
        $this->assertSame($before, $existing->fresh()->getAttributes());
        $this->assertDatabaseCount('pages', 1);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_inactive_nonregular_and_nonexistent_owner_ids_are_rejected(): void
    {
        Sanctum::actingAs($this->admin());
        foreach ([['role' => 'admin'], ['role' => 'ai_worker'], ['banned_at' => now()]] as $attributes) {
            $owner = User::factory()->create($attributes);
            $this->postJson('/api/v1/admin/pages', [...$this->form(), 'user_id' => $owner->id])
                ->assertUnprocessable()->assertJsonValidationErrors('user_id');
        }
        $this->postJson('/api/v1/admin/pages', [...$this->form(), 'user_id' => 999999])
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->assertDatabaseCount('pages', 0);
    }

    public function test_invalid_business_form_and_nonbusiness_type_never_create_pages(): void
    {
        Sanctum::actingAs($this->admin());
        foreach ([
            ['name' => ''], ['category_key' => 'not-a-category'], ['contact_email' => 'invalid-email'],
            ['type' => Page::TYPE_COMMUNITY], ['setup' => ['specialties' => [str_repeat('x', 121)]]],
            ['setup' => ['service_areas' => array_map(fn ($n) => 'City '.$n, range(1, 11))]],
        ] as $invalid) {
            $this->postJson('/api/v1/admin/pages', array_replace($this->form(), $invalid))->assertUnprocessable();
        }
        $this->post('/api/v1/admin/pages', [...$this->form(), 'logo' => UploadedFile::fake()->create('file.txt', 1, 'text/plain')],
            ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('logo');
        $this->assertDatabaseCount('pages', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_missing_active_import_holder_fails_without_creating_or_attaching_to_admin(): void
    {
        User::query()->where('role', 'ai_worker')->update(['banned_at' => now()]);
        Sanctum::actingAs($this->admin());
        $this->post('/api/v1/admin/pages', [...$this->form(), 'logo' => UploadedFile::fake()->image('unclaimed.png', 32, 32)],
            ['Accept' => 'application/json'])->assertStatus(503);
        $this->assertDatabaseCount('pages', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_transaction_failure_rolls_back_page_and_cleans_new_uploads(): void
    {
        Sanctum::actingAs($this->admin());
        $this->partialMock(PageIdentityService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sync')->andThrow(new RuntimeException('Simulated identity persistence failure'));
        });
        $this->post('/api/v1/admin/pages', [
            ...$this->form(), 'user_id' => User::factory()->create()->id,
            'logo' => UploadedFile::fake()->image('rollback.png', 32, 32),
            'banner' => UploadedFile::fake()->image('rollback-banner.png', 32, 32),
        ], ['Accept' => 'application/json'])->assertServerError();
        $this->assertDatabaseCount('pages', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function managedPage(User $owner, array $attributes = []): Page
    {
        return Page::create([
            'user_id' => $owner->id, 'type' => Page::TYPE_BUSINESS, 'is_unclaimed' => false,
            'name' => 'Existing business', 'setup' => [], ...$attributes,
        ]);
    }

    private function form(): array
    {
        return [
            'name' => 'Admin created business', 'category_key' => 'professionals.electricians',
            'public_description' => 'Top contact', 'contact_email' => 'business@example.org',
            'phone' => '0501234567', 'website' => 'example.org', 'palette_key' => 'amber-dawn',
            'setup' => $this->formSetup(),
        ];
    }

    private function formSetup(): array
    {
        return [
            'contact' => ['tel' => '0501111111', 'email' => 'setup@example.org', 'whatsapp' => '0507654321'],
            'address' => ['street' => 'Herzl', 'number' => '10', 'city' => 'Haifa', 'neighborhood' => 'Hadar'],
            'socials' => ['instagram' => 'https://instagram.com/example'],
            'opening_hours' => [['weekday' => 'monday', 'is_open' => true, 'opens_at' => '09:30', 'closes_at' => '18:00']],
            'service_areas' => ['Haifa', 'Tel Aviv'], 'specialties' => ['Repairs', 'Custom work'],
            'features' => ['store' => true, 'services' => true, 'events' => true, 'price_list' => true],
            'imported_categories' => [['key' => 'forged', 'label' => 'Must not persist']],
        ];
    }
}
