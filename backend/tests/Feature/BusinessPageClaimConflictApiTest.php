<?php

namespace Tests\Feature;

use App\Models\Ad;
use App\Models\ChatMessage;
use App\Models\Page;
use App\Models\PageClaimRequest;
use App\Models\PageProduct;
use App\Models\PageRating;
use App\Models\User;
use App\Services\AccountNotificationService;
use App\Services\BusinessPageClaimConflictService;
use App\Services\PageClaimService;
use App\Support\AccountNotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class BusinessPageClaimConflictApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('public');
    }

    public function test_pending_conflict_preserves_page_and_exposes_comparison_only_to_admin(): void
    {
        $page = $this->page();
        $before = $page->getAttributes();
        $requester = User::factory()->create();
        $claim = $this->submit($requester, $page);
        $this->assertSame($before, $page->fresh()->getAttributes());
        $this->assertSame(0, $requester->pages()->count());
        $this->assertSame(PageClaimRequest::KIND_CONFLICT, $claim->kind);
        $this->assertStringContainsString('[CLAIM CONFLICT #'.$claim->id.']', ChatMessage::query()->sole()->body);
        $this->assertSame(1, $this->admin()->notifications()->where('type', AccountNotificationType::PAGE_CLAIM_SUBMITTED)->count());

        $public = app(PageClaimService::class)->requestPayload($claim);
        $this->assertNull($public['current_owner']);
        $this->assertNull($public['proposed_data']);
        $this->assertArrayNotHasKey('contact_email', $public['page']);
        $this->assertArrayNotHasKey('setup', $public['page']);
        Sanctum::actingAs($this->admin());
        $response = $this->getJson('/api/v1/admin/support-chats')->assertOk()
            ->assertJsonPath('data.conversations.0.claim_requests.0.kind', 'claim_conflict')
            ->assertJsonPath('data.conversations.0.claim_requests.0.page.contact_email', 'original@example.org')
            ->assertJsonPath('data.conversations.0.claim_requests.0.proposed_data.contact_email', 'proposed@example.org')
            ->assertJsonPath('data.conversations.0.claim_requests.0.current_owner.id', $page->user_id);
        $this->assertArrayNotHasKey('logo_url', $response->json('data.conversations.0.claim_requests.0.proposed_data'));
    }

    public function test_pending_retry_updates_form_and_group_but_keeps_omitted_staged_media(): void
    {
        $page = $this->page();
        $requester = User::factory()->create();
        Storage::disk('public')->put('pages/proposed.webp', 'proposal');
        $claim = $this->submit($requester, $page, ['logo_path' => 'pages/proposed.webp', 'logo_original_name' => 'logo.webp']);
        $group = (string) Str::uuid();
        $retry = $this->submit($requester, $page, ['public_description' => 'Updated proposal'], $group);
        $this->assertSame($claim->id, $retry->id);
        $this->assertSame($group, $retry->conflict_group_id);
        $this->assertSame('Updated proposal', $retry->proposed_data['public_description']);
        $this->assertSame('pages/proposed.webp', $retry->proposed_data['logo_path']);
        $this->assertSame(1, PageClaimRequest::count());
        $this->assertSame(1, $this->admin()->notifications()->count());
        $this->assertSame('Original business', $page->fresh()->name);
        Storage::disk('public')->assertExists('pages/proposed.webp');
        $removed = $this->submit($requester, $page, ['logo_path' => null, 'logo_original_name' => null], $group);
        $this->assertNull($removed->proposed_data['logo_path']);
    }

    public function test_approval_keeps_page_id_and_related_content_and_applies_staged_data_atomically(): void
    {
        $page = $this->page(['logo_path' => 'pages/original.webp', 'banner_path' => 'pages/banner.webp']);
        Storage::disk('public')->put('pages/original.webp', 'original');
        Storage::disk('public')->put('pages/banner.webp', 'banner');
        Storage::disk('public')->put('pages/proposed.webp', 'proposal');
        $originalOwner = $page->user_id;
        $product = PageProduct::create(['page_id' => $page->id, 'name' => 'Original product',
            'description' => 'Original product details', 'image_path' => 'products/original.webp',
            'link' => 'https://example.org/product', 'price' => 20]);
        $reviewer = User::factory()->create();
        $rating = PageRating::create(['page_id' => $page->id, 'user_id' => $reviewer->id, 'rating' => 5]);
        $ad = Ad::create(['page_id' => $page->id, 'user_id' => $originalOwner, 'type' => Ad::TYPE_BUSINESS,
            'title' => 'Existing ad', 'text' => 'Existing advertisement', 'city' => 'Haifa', 'status' => 'active']);
        $requester = User::factory()->create();
        $claim = $this->submit($requester, $page, ['logo_path' => 'pages/proposed.webp', 'logo_original_name' => 'new.webp']);
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/admin/page-claims/'.$claim->id.'/approve')->assertOk()
            ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.page.id', $page->id)
            ->assertJsonPath('data.page.name', 'Proposed business')->assertJsonPath('data.current_owner.id', $requester->id);
        $fresh = $page->fresh();
        $this->assertSame($requester->id, $fresh->user_id);
        $this->assertSame($originalOwner, $fresh->created_by_user_id);
        $this->assertSame('proposed@example.org', $fresh->contact_email);
        $this->assertSame('Tel Aviv', $fresh->setup['address']['city']);
        $this->assertSame([['provider' => 'foursquare_places', 'key' => 'legacy', 'label' => 'Legacy category']], $fresh->setup['imported_categories']);
        $this->assertSame([['provider' => 'overture_places']], $fresh->setup['imported_attributions']);
        $this->assertSame($page->id, $product->fresh()->page_id);
        $this->assertSame($reviewer->id, $rating->fresh()->user_id);
        $this->assertSame($requester->id, $ad->fresh()->user_id);
        $this->assertSame('Tel Aviv', $ad->fresh()->city);
        $this->assertSame('Center', $ad->fresh()->neighborhood);
        Storage::disk('public')->assertMissing('pages/original.webp');
        Storage::disk('public')->assertExists(['pages/proposed.webp', 'pages/banner.webp']);
        $this->assertSame(1, $requester->notifications()->where('type', AccountNotificationType::PAGE_CLAIM_APPROVED)->count());
        $this->assertSame(1, User::findOrFail($originalOwner)->notifications()->where('type', AccountNotificationType::PAGE_DETACHED)->count());
        $this->postJson('/api/v1/admin/page-claims/'.$claim->id.'/approve')->assertConflict();
        $this->assertSame(1, $requester->notifications()->where('type', AccountNotificationType::PAGE_CLAIM_APPROVED)->count());
    }

    public function test_approval_cancels_competitors_and_group_siblings_without_altering_other_pages(): void
    {
        $page = $this->page();
        $other = $this->page(['name' => 'Second candidate']);
        $otherBefore = $other->getAttributes();
        $requester = User::factory()->create();
        $competitor = User::factory()->create();
        $group = (string) Str::uuid();
        $claim = $this->submit($requester, $page, [], $group);
        $sibling = $this->submit($requester, $other, [], $group);
        $competing = $this->submit($competitor, $page);
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/admin/page-claims/'.$claim->id.'/approve')->assertOk();
        $this->assertSame('cancelled', $sibling->fresh()->status);
        $this->assertSame('cancelled', $competing->fresh()->status);
        $this->assertSame($otherBefore, $other->fresh()->getAttributes());
        $this->assertSame(1, $requester->pages()->count());
        $this->postJson('/api/v1/admin/page-claims/'.$sibling->id.'/approve')->assertConflict();
    }

    public function test_cancel_preserves_current_page_and_shared_proposed_assets_and_other_candidate(): void
    {
        $page = $this->page(['logo_path' => 'pages/original.webp']);
        Storage::disk('public')->put('pages/original.webp', 'original');
        Storage::disk('public')->put('pages/shared.webp', 'proposal');
        $before = $page->getAttributes();
        $requester = User::factory()->create();
        $group = (string) Str::uuid();
        $claim = $this->submit($requester, $page, ['logo_path' => 'pages/shared.webp'], $group);
        $sibling = $this->submit($requester, $this->page(), ['logo_path' => 'pages/shared.webp'], $group);
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/admin/page-claims/'.$claim->id.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame($before, $page->fresh()->getAttributes());
        $this->assertSame('pending', $sibling->fresh()->status);
        Storage::disk('public')->assertExists(['pages/original.webp', 'pages/shared.webp']);
        $this->assertSame(0, $requester->pages()->count());
    }

    public function test_changed_owner_blocks_approval_and_keeps_request_pending(): void
    {
        $page = $this->page();
        $claim = $this->submit(User::factory()->create(), $page);
        $newOwner = User::factory()->create();
        $page->forceFill(['user_id' => $newOwner->id])->save();
        $before = $page->getAttributes();
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/admin/page-claims/'.$claim->id.'/approve')->assertConflict();
        $this->assertSame($before, $page->fresh()->getAttributes());
        $this->assertSame('pending', $claim->fresh()->status);
    }

    public function test_changed_claim_timestamp_or_unclaimed_state_blocks_approval(): void
    {
        $page = $this->page();
        $claim = $this->submit(User::factory()->create(), $page);
        $page->forceFill(['claimed_at' => now()->addDay()])->save();
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/admin/page-claims/'.$claim->id.'/approve')->assertConflict();
        $page->forceFill(['claimed_at' => $claim->owner_at_request_claimed_at, 'is_unclaimed' => true])->save();
        $this->postJson('/api/v1/admin/page-claims/'.$claim->id.'/approve')->assertConflict();
        $this->assertSame('pending', $claim->fresh()->status);
    }

    public function test_requester_new_business_is_never_deleted_by_conflict_approval(): void
    {
        $page = $this->page();
        $before = $page->getAttributes();
        $requester = User::factory()->create();
        $claim = $this->submit($requester, $page);
        $own = $this->page(['user_id' => $requester->id, 'logo_path' => 'pages/requester.webp']);
        Storage::disk('public')->put('pages/requester.webp', 'owned-logo');
        $ownBefore = $own->getAttributes();
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/admin/page-claims/'.$claim->id.'/approve')->assertConflict();
        $this->assertSame($before, $page->fresh()->getAttributes());
        $this->assertSame($ownBefore, $own->fresh()->getAttributes());
        Storage::disk('public')->assertExists('pages/requester.webp');
    }

    public function test_ambiguous_unclaimed_candidate_can_be_approved_but_has_no_current_owner_payload(): void
    {
        $page = $this->page(['is_unclaimed' => true, 'claimed_at' => null]);
        $requester = User::factory()->create();
        $claim = $this->submit($requester, $page, [], (string) Str::uuid());
        $this->assertNull(app(PageClaimService::class)->requestPayload($claim, forAdmin: true)['current_owner']);
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/admin/page-claims/'.$claim->id.'/approve')->assertOk();
        $this->assertSame($requester->id, $page->fresh()->user_id);
        $this->assertFalse($page->fresh()->is_unclaimed);
    }

    public function test_banned_existing_owner_can_be_reviewed_but_banned_requester_cannot_receive_page(): void
    {
        $owner = User::factory()->create(['banned_at' => now()]);
        $page = $this->page(['user_id' => $owner->id]);
        $requester = User::factory()->create();
        $claim = $this->submit($requester, $page);
        $requester->forceFill(['banned_at' => now()])->save();
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/admin/page-claims/'.$claim->id.'/approve')->assertConflict();
        $this->assertSame($owner->id, $page->fresh()->user_id);
    }

    public function test_superseded_candidates_are_cancelled_but_kept_candidates_and_media_remain(): void
    {
        $requester = User::factory()->create();
        $keptPage = $this->page();
        $kept = $this->submit($requester, $keptPage);
        $obsolete = $this->submit($requester, $this->page(), ['logo_path' => 'pages/staged.webp']);
        Storage::disk('public')->put('pages/staged.webp', 'staged');
        DB::transaction(fn () => app(BusinessPageClaimConflictService::class)->cancelObsoleteSubmissions($requester, [$keptPage->id]));
        $this->assertSame('pending', $kept->fresh()->status);
        $this->assertSame('cancelled', $obsolete->fresh()->status);
        Storage::disk('public')->assertExists('pages/staged.webp');
    }

    public function test_non_admin_cannot_approve_or_cancel_conflict(): void
    {
        $requester = User::factory()->create();
        $claim = $this->submit($requester, $this->page());
        Sanctum::actingAs($requester);
        $this->postJson('/api/v1/admin/page-claims/'.$claim->id.'/approve')->assertForbidden();
        $this->postJson('/api/v1/admin/page-claims/'.$claim->id.'/cancel')->assertForbidden();
        $this->assertSame('pending', $claim->fresh()->status);
    }

    public function test_direct_owner_assignment_does_not_implicitly_approve_or_apply_conflict(): void
    {
        $page = $this->page();
        $requester = User::factory()->create();
        $claim = $this->submit($requester, $page);
        Sanctum::actingAs($this->admin());
        $this->patchJson('/api/v1/admin/pages/'.$page->id.'/owner', ['user_id' => $requester->id])->assertOk();
        $this->assertSame('cancelled', $claim->fresh()->status);
        $this->assertSame('ownership_changed', $requester->notifications()->where('type', AccountNotificationType::PAGE_CLAIM_REJECTED)->sole()->data['reason']);
        $this->assertSame('Original business', $page->fresh()->name);
        $this->postJson('/api/v1/admin/page-claims/'.$claim->id.'/approve')->assertConflict();
    }

    public function test_failure_after_page_save_rolls_back_ownership_proposal_claim_and_media(): void
    {
        $page = $this->page(['logo_path' => 'pages/original.webp']);
        Storage::disk('public')->put('pages/original.webp', 'original');
        Storage::disk('public')->put('pages/proposed.webp', 'proposal');
        $before = $page->getAttributes();
        $requester = User::factory()->create();
        $claim = $this->submit($requester, $page, ['logo_path' => 'pages/proposed.webp']);
        $this->mock(AccountNotificationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('pageSnapshot')->andReturn([]);
            $mock->shouldReceive('create')->andThrow(new RuntimeException('Injected notification persistence failure'));
        });
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/admin/page-claims/'.$claim->id.'/approve')->assertServerError();
        $this->assertSame($before, $page->fresh()->getAttributes());
        $this->assertSame('pending', $claim->fresh()->status);
        Storage::disk('public')->assertExists(['pages/original.webp', 'pages/proposed.webp']);
    }

    public function test_requester_role_change_to_worker_blocks_approval(): void
    {
        $page = $this->page();
        $requester = User::factory()->create();
        $claim = $this->submit($requester, $page);
        $requester->forceFill(['role' => 'ai_worker'])->save();
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/admin/page-claims/'.$claim->id.'/approve')->assertConflict();
        $this->assertSame('pending', $claim->fresh()->status);
        $this->assertNotSame($requester->id, $page->fresh()->user_id);
    }

    public function test_direct_detach_cancels_stale_conflict_without_applying_proposal(): void
    {
        User::factory()->create(['role' => 'ai_worker']);
        $page = $this->page();
        $claim = $this->submit(User::factory()->create(), $page);
        Sanctum::actingAs($this->admin());
        $this->patchJson('/api/v1/admin/pages/'.$page->id.'/owner', ['user_id' => null])->assertOk();
        $this->assertSame('cancelled', $claim->fresh()->status);
        $this->assertSame('Original business', $page->fresh()->name);
        $this->assertTrue($page->fresh()->is_unclaimed);
    }

    public function test_automatic_adoption_approves_winners_pending_claim_and_rejects_only_competitor(): void
    {
        $page = $this->page(['is_unclaimed' => true, 'claimed_at' => null]);
        $requester = User::factory()->create();
        $competitor = User::factory()->create();
        $winner = $this->submit($requester, $page);
        $loser = $this->submit($competitor, $page);
        DB::transaction(function () use ($page, $requester): void {
            $page->forceFill(['user_id' => $requester->id, 'is_unclaimed' => false, 'claimed_at' => now()])->save();
            app(PageClaimService::class)->cancelCompetingRequests($page, $requester);
        });
        $this->assertSame('approved', $winner->fresh()->status);
        $this->assertSame('cancelled', $loser->fresh()->status);
        $this->assertSame(1, $requester->notifications()->where('type', AccountNotificationType::PAGE_CLAIM_APPROVED)->count());
        $this->assertSame(0, $requester->notifications()->where('type', AccountNotificationType::PAGE_CLAIM_REJECTED)->count());
    }

    private function admin(): User
    {
        return User::query()->where('email', config('sveevee.support_admin_email'))->firstOrFail();
    }

    private function page(array $attributes = []): Page
    {
        $ownerId = $attributes['user_id'] ?? User::factory()->create()->id;

        return Page::create(array_replace([
            'user_id' => $ownerId, 'created_by_user_id' => $ownerId, 'type' => Page::TYPE_BUSINESS,
            'is_unclaimed' => false, 'claimed_at' => now()->subDay(), 'name' => 'Original business',
            'public_description' => 'Original description', 'contact_email' => 'original@example.org',
            'phone' => '0501234567', 'address' => 'Haifa', 'category_key' => 'home_services.plumbing',
            'setup' => ['address' => ['city' => 'Haifa'], 'imported_categories' => [
                ['provider' => 'foursquare_places', 'key' => 'legacy', 'label' => 'Legacy category'],
            ], 'imported_attributions' => [['provider' => 'overture_places']]],
        ], $attributes))->fresh();
    }

    private function submit(User $requester, Page $page, array $data = [], ?string $group = null): PageClaimRequest
    {
        return DB::transaction(function () use ($requester, $page, $data, $group): PageClaimRequest {
            User::query()->whereKey($requester->id)->lockForUpdate()->firstOrFail();

            return app(BusinessPageClaimConflictService::class)->submit($requester, $page, array_replace([
                'name' => 'Proposed business', 'public_description' => 'Proposed description',
                'contact_email' => 'proposed@example.org', 'phone' => '0527654321', 'address' => 'Tel Aviv',
                'category_key' => 'home_services.plumbing', 'palette_key' => 'blue',
                'setup' => ['address' => ['city' => 'Tel Aviv', 'neighborhood' => 'Center'],
                    'imported_attributions' => [['provider' => 'forged']], 'imported_categories' => [['key' => 'forged']]],
            ], $data), ['name', 'phone'], $group);
        });
    }
}
