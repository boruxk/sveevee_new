<?php

namespace Tests\Feature;

use App\Models\Ad;
use App\Models\BusinessImportSource;
use App\Models\BusinessImportSourceAlias;
use App\Models\Page;
use App\Models\PageClaimRequest;
use App\Models\PagePrice;
use App\Models\PageProduct;
use App\Models\PageRating;
use App\Models\PageService;
use App\Models\User;
use App\Services\PageIdentityService;
use App\Support\CatalogTopics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class BusinessPageAutoAdoptionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_as_the_only_second_match_adopts_existing_id_and_preserves_related_data(): void
    {
        Storage::fake('public');
        $page = $this->unclaimed([
            'logo_path' => 'pages/logos/existing.webp', 'banner_path' => 'pages/banners/existing.webp',
            'public_description' => 'Original imported description.',
            'setup' => [
                'imported_attributions' => [['provider' => 'osm_places']],
                'imported_categories' => [['provider' => 'overture_places', 'key' => 'original_kind', 'label' => 'Original kind']],
                'imported_opening_hours' => 'Original source opening hours',
            ],
        ]);
        $creatorId = $page->created_by_user_id;
        Storage::disk('public')->put($page->logo_path, 'existing-logo');
        Storage::disk('public')->put($page->banner_path, 'existing-banner');
        $source = BusinessImportSource::create([
            'provider' => 'overture_places', 'source_id' => 'adoption-source', 'page_id' => $page->id,
            'url' => 'https://explore.overturemaps.org/?feature=places.place.adoption-source',
            'metadata' => ['original_name' => 'Studio Flow', 'source_categories' => [['key' => 'original_kind', 'label' => 'Original kind', 'catalog_key' => null]]],
        ]);
        $alias = BusinessImportSourceAlias::create([
            'provider' => 'foursquare_places', 'source_id' => str_repeat('a', 24), 'business_import_source_id' => $source->id,
        ]);
        $sourceBefore = $source->fresh()->getAttributes();
        $product = PageProduct::create([
            'page_id' => $page->id, 'name' => 'Existing product', 'description' => 'Original product.',
            'image_path' => 'products/existing.webp', 'price' => 20, 'link' => 'https://example.org/product',
        ]);
        $service = PageService::create([
            'page_id' => $page->id, 'name' => 'Existing service', 'description' => 'Original service.',
            'image_path' => 'services/existing.webp',
        ]);
        $price = PagePrice::create(['page_id' => $page->id, 'name' => 'Consultation', 'price' => 30]);
        $reviewer = User::factory()->create();
        $rating = PageRating::create(['page_id' => $page->id, 'user_id' => $reviewer->id, 'rating' => 4, 'comment' => 'Existing review.']);
        $ad = Ad::create([
            'page_id' => $page->id, 'user_id' => $page->user_id, 'type' => Ad::TYPE_BUSINESS,
            'title' => 'Existing ad', 'text' => 'Original ad.', 'status' => 'active', 'city' => 'Haifa',
        ]);
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->partialMock(PageIdentityService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('ensureAll');
        });

        $payload = $this->form([
            'name' => 'STUDIO FLOW', 'public_description' => 'My updated business description.',
            'phone' => '050-987-6543', 'contact_email' => 'new-owner@example.org',
            'setup' => [
                'address' => ['city' => 'Tel Aviv', 'street' => 'New Street', 'number' => '8'],
                'imported_attributions' => [['provider' => 'forged_source']],
                'imported_categories' => [['provider' => 'forged_source', 'key' => 'forged_kind', 'label' => 'Forged kind']],
                'imported_opening_hours' => 'Forged source opening hours',
                'opening_hours' => [['weekday' => 'monday', 'is_open' => true, 'opens_at' => '11:00', 'closes_at' => '18:00']],
            ],
        ]);
        $this->postJson('/api/v1/pages/business', $payload)->assertSuccessful()
            ->assertJsonPath('data.id', $page->id)->assertJsonPath('data.save_outcome', 'adopted')
            ->assertJsonPath('data.is_unclaimed', false);

        $fresh = $page->fresh();
        $this->assertSame($owner->id, $fresh->user_id);
        $this->assertSame($creatorId, $fresh->created_by_user_id);
        $this->assertNotNull($fresh->claimed_at);
        $this->assertSame($payload['public_description'], $fresh->public_description);
        $this->assertSame($payload['phone'], $fresh->phone);
        $this->assertSame('Tel Aviv', $fresh->setup['address']['city']);
        $this->assertSame($page->setup['imported_attributions'], $fresh->setup['imported_attributions']);
        $this->assertSame($page->setup['imported_categories'], $fresh->setup['imported_categories']);
        $this->assertArrayNotHasKey('imported_opening_hours', $fresh->setup);
        $this->assertSame('11:00', collect($fresh->setup['opening_hours'])->firstWhere('weekday', 'monday')['opens_at']);
        $this->assertSame($sourceBefore, $source->fresh()->getAttributes());
        $this->assertSame($source->id, $alias->fresh()->business_import_source_id);
        foreach ([$product, $service, $price, $rating, $ad] as $related) {
            $this->assertSame($page->id, $related->fresh()->page_id);
        }
        $this->assertSame($reviewer->id, $rating->fresh()->user_id);
        $this->assertSame($owner->id, $ad->fresh()->user_id);
        $this->assertSame('Tel Aviv', $ad->fresh()->city);
        $this->assertSame($page->logo_path, $fresh->logo_path);
        $this->assertSame($page->banner_path, $fresh->banner_path);
        Storage::disk('public')->assertExists([$fresh->logo_path, $fresh->banner_path]);
        $this->assertDatabaseCount('pages', 1);
        $this->assertDatabaseCount('page_claim_requests', 0);

        $this->postJson('/api/v1/pages/business', $payload)->assertSuccessful()
            ->assertJsonPath('data.id', $page->id)->assertJsonPath('data.save_outcome', 'updated');
        $this->assertDatabaseCount('pages', 1);
        $this->assertSame($owner->id, $ad->fresh()->user_id);
    }

    public function test_name_alone_and_matching_empty_fields_do_not_adopt(): void
    {
        $page = $this->unclaimed(['category_key' => null, 'phone' => null, 'contact_email' => null, 'setup' => [
            'website' => '', 'contact' => ['whatsapp' => ''], 'address' => ['city' => null, 'street' => '', 'neighborhood' => ''],
            'socials' => ['instagram' => null],
        ]]);
        Sanctum::actingAs(User::factory()->create());
        $response = $this->postJson('/api/v1/pages/business', $this->form([
            'phone' => '', 'contact_email' => '', 'website' => '',
            'setup' => ['address' => ['city' => '', 'street' => '', 'neighborhood' => ''],
                'contact' => ['whatsapp' => ''], 'socials' => ['instagram' => '']],
        ]))->assertSuccessful()->assertJsonPath('data.save_outcome', 'created');
        $this->assertNotSame($page->id, $response->json('data.id'));
        $this->assertTrue($page->fresh()->is_unclaimed);
        $this->assertDatabaseCount('pages', 2);
    }

    public function test_matching_contact_city_and_category_without_matching_name_do_not_adopt(): void
    {
        $page = $this->unclaimed(['name' => 'Another business', 'phone' => '050-123-4567', 'setup' => ['address' => ['city' => 'Haifa']]]);
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/pages/business', $this->form(['phone' => '050-123-4567', 'setup' => ['address' => ['city' => 'Haifa']]]))
            ->assertSuccessful()->assertJsonPath('data.save_outcome', 'created');
        $this->assertTrue($page->fresh()->is_unclaimed);
        $this->assertDatabaseCount('pages', 2);
    }

    public function test_account_email_fallback_is_not_a_submitted_match_signal(): void
    {
        $owner = User::factory()->create();
        $page = $this->unclaimed(['category_key' => null, 'contact_email' => $owner->email]);
        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/pages/business', $this->form())->assertSuccessful()
            ->assertJsonPath('data.save_outcome', 'created');
        $this->assertTrue($page->fresh()->is_unclaimed);
        $this->assertDatabaseCount('pages', 2);
    }

    #[DataProvider('secondarySignals')]
    public function test_nonempty_secondary_signals_adopt_without_category_match(array $existing, array $submitted): void
    {
        $page = $this->unclaimed(array_replace_recursive(['category_key' => null], $existing));
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/pages/business', $this->form($submitted))->assertSuccessful()
            ->assertJsonPath('data.id', $page->id)->assertJsonPath('data.save_outcome', 'adopted');
        $this->assertDatabaseCount('pages', 1);
    }

    public static function secondarySignals(): array
    {
        return [
            'phone country prefix and punctuation' => [['phone' => '+972 50 123 4567'], ['phone' => '050-123-4567']],
            'known Hebrew city alias' => [['setup' => ['address' => ['city' => 'Tel Aviv']]], ['setup' => ['address' => ['city' => 'תל אביב-יפו']]]],
            'plain address without structured city' => [['address' => 'Workshop Road 7'], ['address' => 'Workshop Road 7']],
            'whatsapp' => [['setup' => ['contact' => ['whatsapp' => '+972 50 123 4567']]], ['setup' => ['contact' => ['whatsapp' => '050-123-4567']]]],
            'social link' => [['setup' => ['socials' => ['instagram' => 'https://instagram.com/studio-flow']]], ['setup' => ['socials' => ['instagram' => 'https://instagram.com/studio-flow']]]],
        ];
    }

    public function test_different_social_profile_ids_do_not_count_as_the_same_link(): void
    {
        $page = $this->unclaimed(['category_key' => null, 'setup' => ['socials' => [
            'facebook' => 'https://www.facebook.com/profile.php?id=111',
        ]]]);
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/pages/business', $this->form(['setup' => ['socials' => [
            'facebook' => 'https://facebook.com/profile.php?id=222',
        ]]]))->assertOk()->assertJsonPath('data.save_outcome', 'created');
        $this->assertTrue($page->fresh()->is_unclaimed);
    }

    public function test_same_social_profile_with_tracking_parameters_is_a_matching_detail(): void
    {
        $page = $this->unclaimed(['category_key' => null, 'setup' => ['socials' => [
            'facebook' => 'https://www.facebook.com/profile.php?id=111&utm_source=old',
        ]]]);
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/pages/business', $this->form(['setup' => ['socials' => [
            'facebook' => 'http://facebook.com/profile.php?utm_source=new&id=111',
        ]]]))->assertOk()->assertJsonPath('data.id', $page->id)->assertJsonPath('data.save_outcome', 'adopted');
    }

    public function test_owned_match_returns_pending_conflict_without_changing_page_or_owner(): void
    {
        $previousOwner = User::factory()->create();
        $page = $this->unclaimed(['user_id' => $previousOwner->id, 'is_unclaimed' => false, 'claimed_at' => now(), 'public_description' => 'Existing owned data.']);
        $before = $page->getAttributes();
        $requester = User::factory()->create();
        Sanctum::actingAs($requester);
        $payload = $this->form(['public_description' => 'Requested new description.']);
        $this->postJson('/api/v1/pages/business', $payload)->assertStatus(202)
            ->assertJsonPath('data.save_outcome', 'claim_conflict')->assertJsonPath('data.pending_claim', true)
            ->assertJsonCount(1, 'data.claim_requests');
        $this->assertSame($before, $page->fresh()->getAttributes());
        $this->assertDatabaseCount('pages', 1);
        $this->assertDatabaseHas('page_claim_requests', ['page_id' => $page->id, 'user_id' => $requester->id, 'status' => PageClaimRequest::STATUS_PENDING]);
        $this->postJson('/api/v1/pages/business', $payload)->assertStatus(202);
        $this->assertDatabaseCount('page_claim_requests', 1);
        $this->assertDatabaseCount('pages', 1);
    }

    public function test_multiple_matches_are_grouped_for_review_instead_of_picking_an_owner(): void
    {
        $one = $this->unclaimed();
        $two = $this->unclaimed();
        $before = [$one->getAttributes(), $two->getAttributes()];
        $requester = User::factory()->create();
        Sanctum::actingAs($requester);
        $this->postJson('/api/v1/pages/business', $this->form())->assertStatus(202)
            ->assertJsonPath('data.save_outcome', 'claim_conflict')->assertJsonPath('data.pending_claim', true)
            ->assertJsonCount(2, 'data.claim_requests');
        $this->assertSame($before, [$one->fresh()->getAttributes(), $two->fresh()->getAttributes()]);
        $this->assertDatabaseCount('pages', 2);
        $this->assertEqualsCanonicalizing([$one->id, $two->id], PageClaimRequest::where('user_id', $requester->id)->pluck('page_id')->all());
    }

    public function test_editing_existing_business_does_not_adopt_another_matching_page(): void
    {
        $owner = User::factory()->create();
        $mine = $this->unclaimed(['name' => 'My existing business', 'user_id' => $owner->id, 'is_unclaimed' => false]);
        $other = $this->unclaimed();
        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/pages/business', $this->form())->assertSuccessful()
            ->assertJsonPath('data.id', $mine->id)->assertJsonPath('data.save_outcome', 'updated');
        $this->assertTrue($other->fresh()->is_unclaimed);
        $this->assertDatabaseCount('pages', 2);
        $this->assertDatabaseCount('page_claim_requests', 0);
    }

    public function test_community_creation_keeps_its_existing_behavior(): void
    {
        $category = CatalogTopics::keysForScope(CatalogTopics::SCOPE_COMMUNITY_PAGES)[0];
        $existing = $this->unclaimed(['type' => Page::TYPE_COMMUNITY, 'category_key' => $category]);
        Sanctum::actingAs(User::factory()->create());
        $response = $this->postJson('/api/v1/pages/community', $this->form(['category_key' => $category]))->assertSuccessful();
        $this->assertNotSame($existing->id, $response->json('data.id'));
        $this->assertTrue($existing->fresh()->is_unclaimed);
        $this->assertDatabaseCount('pages', 2);
        $this->assertDatabaseCount('page_claim_requests', 0);
    }

    public function test_explicit_uploaded_logo_and_banner_removal_apply_to_the_adopted_page(): void
    {
        Storage::fake('public');
        $page = $this->unclaimed(['logo_path' => 'pages/logos/old.webp', 'banner_path' => 'pages/banners/old.webp']);
        Storage::disk('public')->put($page->logo_path, 'old-logo');
        Storage::disk('public')->put($page->banner_path, 'old-banner');
        Sanctum::actingAs(User::factory()->create());
        $this->post('/api/v1/pages/business', $this->form([
            'setup' => '{}', 'logo' => UploadedFile::fake()->image('new-logo.png', 32, 32), 'banner_remove' => '1',
        ]), ['Accept' => 'application/json'])->assertSuccessful()
            ->assertJsonPath('data.id', $page->id)->assertJsonPath('data.save_outcome', 'adopted');
        $fresh = $page->fresh();
        $this->assertNotSame($page->logo_path, $fresh->logo_path);
        $this->assertStringEndsWith('.webp', $fresh->logo_path);
        $this->assertNull($fresh->banner_path);
        Storage::disk('public')->assertExists($fresh->logo_path);
        Storage::disk('public')->assertMissing([$page->logo_path, $page->banner_path]);
    }

    public function test_failed_adoption_keeps_existing_owner_and_media(): void
    {
        Storage::fake('public');
        $page = $this->unclaimed(['logo_path' => 'pages/logos/rollback.webp']);
        Storage::disk('public')->put($page->logo_path, 'original-logo');
        $before = $page->getAttributes();
        Sanctum::actingAs(User::factory()->create());
        $this->partialMock(PageIdentityService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sync')->andThrow(new RuntimeException('Simulated identity storage failure.'));
        });
        $this->post('/api/v1/pages/business', $this->form([
            'setup' => '{}', 'logo' => UploadedFile::fake()->image('new.png', 32, 32),
        ]), ['Accept' => 'application/json'])->assertServerError();
        $this->assertSame($before, $page->fresh()->getAttributes());
        Storage::disk('public')->assertExists($page->logo_path);
        $this->assertSame([$page->logo_path], Storage::disk('public')->allFiles());
        $this->assertDatabaseCount('pages', 1);
    }

    public function test_unavailable_support_rolls_back_conflict_and_cleans_staged_upload(): void
    {
        Storage::fake('public');
        $previousOwner = User::factory()->create();
        $page = $this->unclaimed([
            'user_id' => $previousOwner->id, 'is_unclaimed' => false, 'claimed_at' => now(),
            'logo_path' => 'pages/logos/owned.webp', 'banner_path' => 'pages/banners/owned.webp',
        ]);
        Storage::disk('public')->put($page->logo_path, 'owned-logo');
        Storage::disk('public')->put($page->banner_path, 'owned-banner');
        $before = $page->getAttributes();
        $files = Storage::disk('public')->allFiles();
        config()->set('sveevee.support_admin_email', 'missing-support@example.invalid');
        Sanctum::actingAs(User::factory()->create());
        $this->post('/api/v1/pages/business', $this->form([
            'setup' => '{}', 'public_description' => 'Unapproved data.',
            'logo' => UploadedFile::fake()->image('pending.png', 32, 32), 'banner_remove' => '1',
        ]), ['Accept' => 'application/json'])->assertStatus(503);
        $this->assertSame($before, $page->fresh()->getAttributes());
        $this->assertSame($files, Storage::disk('public')->allFiles());
        $this->assertDatabaseCount('pages', 1);
        $this->assertDatabaseCount('page_claim_requests', 0);
    }

    private function unclaimed(array $overrides = []): Page
    {
        $worker = User::query()->where('role', 'ai_worker')->firstOrFail();

        return Page::create(array_replace_recursive([
            'user_id' => $worker->id, 'created_by_user_id' => $worker->id, 'type' => Page::TYPE_BUSINESS,
            'is_unclaimed' => true, 'name' => 'Studio Flow', 'category_key' => 'professionals.electricians',
            'setup' => [],
        ], $overrides))->fresh();
    }

    private function form(array $overrides = []): array
    {
        return array_replace_recursive([
            'name' => 'Studio Flow', 'category_key' => 'professionals.electricians',
            'public_description' => 'A business managed by its owner.', 'setup' => [],
        ], $overrides);
    }
}
