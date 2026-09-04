<?php

namespace Tests\Feature;

use App\Jobs\SendAccountNotificationEmail;
use App\Mail\AccountStatusMail;
use App\Models\EmailDelivery;
use App\Models\EmailSuppression;
use App\Models\Page;
use App\Models\PageClaimRequest;
use App\Models\User;
use App\Services\AccountNotificationService;
use App\Support\AccountNotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_api_is_private_and_supports_read_states_and_heartbeat_summary(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $page = $this->managedPage($user, 'Notification Page');
        $service = app(AccountNotificationService::class);
        $first = $service->create($user, AccountNotificationType::PAGE_RATING_RECEIVED, [
            'page' => $service->pageSnapshot($page),
            'reviewer_name' => 'Reviewer',
            'rating' => 5,
            'action_path' => $page->public_path,
        ]);
        $foreign = $service->create($other, AccountNotificationType::PAGE_RATING_RECEIVED, [
            'page' => $service->pageSnapshot($page),
            'reviewer_name' => 'Someone else',
            'rating' => 4,
            'action_path' => $page->public_path,
        ]);

        $this->getJson('/api/v1/notifications')->assertUnauthorized();

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $first->id)
            ->assertJsonPath('data.items.0.type', AccountNotificationType::PAGE_RATING_RECEIVED)
            ->assertJsonPath('data.items.0.data.page.name', 'Notification Page')
            ->assertJsonPath('data.unread_count', 1)
            ->assertJsonPath('data.latest_id', $first->id);

        $this->patchJson("/api/v1/notifications/{$foreign->id}/read")->assertNotFound();
        $this->patchJson("/api/v1/notifications/{$first->id}/read")
            ->assertOk()
            ->assertJsonPath('data.notification.read_at', fn ($value) => is_string($value))
            ->assertJsonPath('data.unread_count', 0);

        $second = $service->create($user, AccountNotificationType::PAGE_RATING_RECEIVED, [
            'page' => $service->pageSnapshot($page),
            'reviewer_name' => 'Second reviewer',
            'rating' => 3,
            'action_path' => $page->public_path,
        ]);
        $this->postJson('/api/v1/presence/heartbeat')
            ->assertOk()
            ->assertJsonPath('data.notifications.unread_count', 1)
            ->assertJsonPath('data.notifications.latest_id', $second->id);
        $this->patchJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);
    }

    public function test_private_broadcast_channel_only_authorizes_its_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        Sanctum::actingAs($user);
        $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-users.'.$user->id,
        ])->assertOk();
        $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-users.'.$other->id,
        ])->assertForbidden();
    }

    public function test_claim_submission_approval_and_competing_rejection_notify_exact_recipients(): void
    {
        Queue::fake();
        $admin = $this->supportAdmin();
        $worker = $this->worker();
        $page = $this->unclaimedPage($worker, 'Claim Target');
        $winner = User::factory()->create();
        $competitor = User::factory()->create();

        Sanctum::actingAs($winner);
        $winnerClaim = $this->postJson("/api/v1/pages/{$page->id}/claim-requests", [
            'message' => 'I can verify ownership of this business.',
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs($competitor);
        $this->postJson("/api/v1/pages/{$page->id}/claim-requests", [
            'message' => 'I also request ownership of this business.',
        ])->assertCreated();

        $this->assertSame(2, $admin->notifications()->where('type', AccountNotificationType::PAGE_CLAIM_SUBMITTED)->count());

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/page-claims/{$winnerClaim}/approve")->assertOk();

        $this->assertSame(1, $winner->notifications()->where('type', AccountNotificationType::PAGE_CLAIM_APPROVED)->count());
        $this->assertSame(0, $winner->notifications()->where('type', AccountNotificationType::PAGE_ASSIGNED)->count());
        $rejection = $competitor->notifications()->where('type', AccountNotificationType::PAGE_CLAIM_REJECTED)->sole();
        $this->assertSame('claimed_by_another', $rejection->data['reason']);
        $this->assertDatabaseHas('page_claim_requests', [
            'page_id' => $page->id,
            'user_id' => $competitor->id,
            'status' => PageClaimRequest::STATUS_CANCELLED,
        ]);
        Queue::assertPushed(SendAccountNotificationEmail::class, 2);
    }

    public function test_replacement_claim_creates_only_approval_with_replacement_context(): void
    {
        Queue::fake();
        $admin = $this->supportAdmin();
        $worker = $this->worker();
        $target = $this->unclaimedPage($worker, 'Better Business');
        $claimant = User::factory()->create();

        Sanctum::actingAs($claimant);
        $claimId = $this->postJson("/api/v1/pages/{$target->id}/claim-requests", [
            'message' => 'This is the current official location.',
        ])->assertCreated()->json('data.id');
        $oldPage = $this->managedPage($claimant, 'Old Business');

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/page-claims/{$claimId}/approve")->assertOk();

        $notification = $claimant->notifications()->sole();
        $this->assertSame(AccountNotificationType::PAGE_CLAIM_APPROVED, $notification->type);
        $this->assertSame('Old Business', $notification->data['replaced_page_name']);
        $this->assertDatabaseMissing('pages', ['id' => $oldPage->id]);
    }

    public function test_direct_assignment_resolves_claims_without_duplicate_and_tracks_reassignment_detach_and_delete(): void
    {
        Queue::fake();
        $admin = $this->supportAdmin();
        $worker = $this->worker();
        $page = $this->unclaimedPage($worker, 'Assignment Page');
        $claimant = User::factory()->create();
        $competitor = User::factory()->create();

        Sanctum::actingAs($claimant);
        $this->postJson("/api/v1/pages/{$page->id}/claim-requests", [
            'message' => 'Please assign this page to my account.',
        ])->assertCreated();
        Sanctum::actingAs($competitor);
        $this->postJson("/api/v1/pages/{$page->id}/claim-requests", [
            'message' => 'I would also like this page reviewed.',
        ])->assertCreated();

        Sanctum::actingAs($admin);
        $this->patchJson("/api/v1/admin/pages/{$page->id}/owner", ['user_id' => $claimant->id])->assertOk();
        $this->assertSame(1, $claimant->notifications()->where('type', AccountNotificationType::PAGE_CLAIM_APPROVED)->count());
        $this->assertSame(0, $claimant->notifications()->where('type', AccountNotificationType::PAGE_ASSIGNED)->count());
        $this->assertSame(1, $competitor->notifications()->where('type', AccountNotificationType::PAGE_CLAIM_REJECTED)->count());

        $newOwner = User::factory()->create();
        $this->patchJson("/api/v1/admin/pages/{$page->id}/owner", ['user_id' => $newOwner->id])->assertOk();
        $this->assertSame(1, $claimant->notifications()->where('type', AccountNotificationType::PAGE_DETACHED)->count());
        $this->assertSame(1, $newOwner->notifications()->where('type', AccountNotificationType::PAGE_ASSIGNED)->count());

        $this->patchJson("/api/v1/admin/pages/{$page->id}/owner", ['user_id' => null])->assertOk();
        $this->assertSame(1, $newOwner->notifications()->where('type', AccountNotificationType::PAGE_DETACHED)->count());

        $finalOwner = User::factory()->create();
        $this->patchJson("/api/v1/admin/pages/{$page->id}/owner", ['user_id' => $finalOwner->id])->assertOk();
        $this->deleteJson("/api/v1/admin/pages/{$page->id}")->assertOk();
        $this->assertSame(1, $finalOwner->notifications()->where('type', AccountNotificationType::PAGE_ASSIGNED)->count());
        $this->assertSame(1, $finalOwner->notifications()->where('type', AccountNotificationType::PAGE_DELETED)->count());
    }

    public function test_only_the_first_rating_creates_an_owner_notification(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $reviewer = User::factory()->create([
            'given_name' => 'Rating',
            'family_name' => 'Person',
        ]);
        $page = $this->managedPage($owner, 'Rated Page');
        Sanctum::actingAs($reviewer);

        $this->putJson("/api/v1/pages/{$page->id}/ratings/me", ['rating' => 4, 'comment' => 'Good'])->assertOk();
        $this->putJson("/api/v1/pages/{$page->id}/ratings/me", ['rating' => 5, 'comment' => 'Great'])->assertOk();

        $notification = $owner->notifications()->where('type', AccountNotificationType::PAGE_RATING_RECEIVED)->sole();
        $this->assertSame(4, $notification->data['rating']);
        $this->assertSame('Rating Person', $notification->data['reviewer_name']);
    }

    public function test_leads_page_notifies_admins_only_when_a_new_page_is_created(): void
    {
        Queue::fake();
        $admin = $this->supportAdmin();
        $payload = [
            'business_name' => 'Realtime Locksmith',
            'city' => 'Netanya',
            'category_key' => 'services.home_repairs.locksmith',
            'full_name' => 'Lead Person',
            'email' => 'lead@example.test',
            'phone' => '+972546555580',
            'locale' => 'he',
            'consent' => true,
            'website' => '',
        ];

        $first = $this->postJson('/api/v1/business-page-leads', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created', true);
        $this->postJson('/api/v1/business-page-leads', $payload)
            ->assertOk()
            ->assertJsonPath('data.created', false);

        $notification = $admin->notifications()->where('type', AccountNotificationType::LEAD_PAGE_CREATED)->sole();
        $this->assertSame($first->json('data.page.id'), $notification->data['page']['id']);
        $this->assertSame('/admin?tab=statistics', $notification->data['action_path']);
    }

    public function test_public_page_exposes_only_the_current_viewers_claim(): void
    {
        Queue::fake();
        $page = $this->unclaimedPage($this->worker(), 'Private Claim State');
        $claimant = User::factory()->create();
        $other = User::factory()->create();

        Sanctum::actingAs($claimant);
        $claimId = $this->postJson("/api/v1/pages/{$page->id}/claim-requests", [
            'message' => 'Persist my pending state after reload.',
        ])->assertCreated()->json('data.id');
        $this->getJson("/api/v1/pages/{$page->id}")
            ->assertOk()
            ->assertJsonPath('data.viewer_claim.id', $claimId)
            ->assertJsonPath('data.viewer_claim.status', PageClaimRequest::STATUS_PENDING);

        Sanctum::actingAs($other);
        $this->getJson("/api/v1/pages/{$page->id}")
            ->assertOk()
            ->assertJsonPath('data.viewer_claim', null);
    }

    public function test_account_status_mail_is_localized_logged_and_skips_unusable_addresses(): void
    {
        Queue::fake();
        Mail::fake();
        $service = app(AccountNotificationService::class);

        foreach (['he', 'en', 'ru', 'fr'] as $locale) {
            $user = User::factory()->create([
                'locale' => $locale,
                'email_verified_at' => now(),
            ]);
            $page = $this->managedPage($user, strtoupper($locale).' Page');
            $notification = $service->create($user, AccountNotificationType::PAGE_ASSIGNED, [
                'page' => $service->pageSnapshot($page),
                'action_path' => '/business',
            ]);

            app()->call([new SendAccountNotificationEmail($notification->id), 'handle']);
        }

        Mail::assertSent(AccountStatusMail::class, 4);
        foreach (['he', 'en', 'ru', 'fr'] as $locale) {
            Mail::assertSent(AccountStatusMail::class, fn (AccountStatusMail $mail): bool => $mail->messageLocale === $locale);
        }
        $this->assertSame(4, EmailDelivery::query()->whereNotNull('notification_id')->where('status', EmailDelivery::STATUS_SENT)->count());

        $unverified = User::factory()->create(['email_verified_at' => null]);
        $unverifiedPage = $this->managedPage($unverified, 'Unverified Page');
        $unverifiedNotification = $service->create($unverified, AccountNotificationType::PAGE_ASSIGNED, [
            'page' => $service->pageSnapshot($unverifiedPage),
            'action_path' => '/business',
        ]);
        app()->call([new SendAccountNotificationEmail($unverifiedNotification->id), 'handle']);

        $suppressed = User::factory()->create(['email_verified_at' => now()]);
        EmailSuppression::query()->create([
            'email' => $suppressed->email,
            'reason' => 'hard_bounce',
            'suppressed_at' => now(),
        ]);
        $suppressedPage = $this->managedPage($suppressed, 'Suppressed Page');
        $suppressedNotification = $service->create($suppressed, AccountNotificationType::PAGE_ASSIGNED, [
            'page' => $service->pageSnapshot($suppressedPage),
            'action_path' => '/business',
        ]);
        app()->call([new SendAccountNotificationEmail($suppressedNotification->id), 'handle']);

        Mail::assertSent(AccountStatusMail::class, 4);
        $this->assertDatabaseMissing('email_deliveries', ['notification_id' => $unverifiedNotification->id]);
        $this->assertDatabaseMissing('email_deliveries', ['notification_id' => $suppressedNotification->id]);
    }

    private function supportAdmin(): User
    {
        return User::query()->where('email', config('sveevee.support_admin_email'))->firstOrFail();
    }

    private function worker(): User
    {
        return User::query()->where('role', 'ai_worker')->firstOrFail();
    }

    private function unclaimedPage(User $worker, string $name): Page
    {
        return Page::query()->create([
            'user_id' => $worker->id,
            'created_by_user_id' => $worker->id,
            'type' => Page::TYPE_BUSINESS,
            'is_unclaimed' => true,
            'name' => $name,
            'category_key' => 'services.home_repairs.locksmith',
            'setup' => [],
        ]);
    }

    private function managedPage(User $owner, string $name): Page
    {
        return Page::query()->create([
            'user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'type' => Page::TYPE_BUSINESS,
            'is_unclaimed' => false,
            'name' => $name,
            'category_key' => 'services.home_repairs.locksmith',
            'setup' => [],
            'claimed_at' => now(),
        ]);
    }
}
