<?php

namespace Tests\Feature;

use App\Jobs\SendEmailVerificationEmail;
use App\Jobs\SendUnreadChatEmail;
use App\Mail\UnreadChatMessageMail;
use App\Models\ChatEmailNotificationState;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\EmailDelivery;
use App\Models\EmailSuppression;
use App\Models\User;
use App\Services\BounceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmailNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_queues_a_general_verification_email(): void
    {
        Queue::fake();

        $this->postJson('/api/v1/auth/register', [
            'email' => 'new-user@example.test',
            'password' => 'password1',
            'password_confirmation' => 'password1',
            'given_name' => 'New',
            'family_name' => 'User',
            'locale' => 'en',
            'consented' => true,
        ])->assertCreated();

        Queue::assertPushed(SendEmailVerificationEmail::class, function (SendEmailVerificationEmail $job): bool {
            return $job->email === 'new-user@example.test';
        });
    }

    public function test_profile_exposes_general_verification_status_and_rejects_unverified_opt_in(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.email_verification.status', 'unverified')
            ->assertJsonPath('data.email_chat_notifications', false);

        $this->putJson('/api/v1/profile/email-preferences', [
            'chat_notifications' => true,
        ])->assertStatus(409)
            ->assertJsonPath('data.email_verification.status', 'unverified');

        $user->forceFill(['email_verified_at' => now()])->save();

        $this->putJson('/api/v1/profile/email-preferences', [
            'chat_notifications' => true,
        ])->assertOk()
            ->assertJsonPath('data.email_chat_notifications', true);
    }

    public function test_signed_link_verifies_the_current_email_address(): void
    {
        $user = User::factory()->create([
            'email' => 'verify-me@example.test',
            'email_verified_at' => null,
        ]);
        $path = URL::temporarySignedRoute(
            'email-verification.verify',
            now()->addHour(),
            ['id' => $user->id, 'hash' => sha1($user->email)],
            absolute: false,
        );

        $this->get($path)->assertRedirect();

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_expired_and_tampered_links_do_not_verify_the_address(): void
    {
        Carbon::setTestNow('2026-09-02 12:00:00');
        $user = User::factory()->create([
            'email' => 'invalid-link@example.test',
            'email_verified_at' => null,
        ]);
        $expiredPath = URL::temporarySignedRoute(
            'email-verification.verify',
            now()->subMinute(),
            ['id' => $user->id, 'hash' => sha1($user->email)],
            absolute: false,
        );
        $tamperedPath = URL::temporarySignedRoute(
            'email-verification.verify',
            now()->addHour(),
            ['id' => $user->id, 'hash' => sha1('another-address@example.test')],
            absolute: false,
        );

        $this->get($expiredPath)->assertRedirectContains('emailVerification=invalid');
        $this->get($tamperedPath)->assertRedirectContains('emailVerification=invalid');

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_verification_resend_is_limited_to_three_requests_per_hour(): void
    {
        Queue::fake();
        $user = User::factory()->create(['email_verified_at' => null]);
        Sanctum::actingAs($user);

        foreach (range(1, 3) as $attempt) {
            $this->postJson('/api/v1/profile/email-verification')->assertOk();
        }

        $this->postJson('/api/v1/profile/email-verification')->assertTooManyRequests();
        Queue::assertPushed(SendEmailVerificationEmail::class, 3);
    }

    public function test_email_change_resets_verification_and_dependent_preferences(): void
    {
        Queue::fake();
        $user = User::factory()->create([
            'email' => 'old@example.test',
            'email_verified_at' => now(),
        ]);
        $user->profile()->update(['email_chat_notifications' => true]);
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/profile', [
            'email' => 'new@example.test',
            'given_name' => $user->given_name,
            'family_name' => $user->family_name,
            'locale' => 'en',
        ])->assertOk()
            ->assertJsonPath('data.email_verification.status', 'unverified')
            ->assertJsonPath('data.email_chat_notifications', false);

        $this->assertNull($user->fresh()->email_verified_at);
        Queue::assertPushed(SendEmailVerificationEmail::class, fn (SendEmailVerificationEmail $job): bool => $job->email === 'new@example.test');
    }

    public function test_private_chat_queues_one_delayed_email_per_unread_block(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-09-02 12:00:00');
        $sender = User::factory()->create();
        $recipient = User::factory()->create(['email_verified_at' => now()]);
        $recipient->profile()->update(['email_chat_notifications' => true]);
        [$one, $two] = Conversation::pairFor($sender, $recipient);
        $conversation = Conversation::query()->create([
            'user_one_id' => $one,
            'user_two_id' => $two,
            'started_by_user_id' => $sender->id,
            'is_support' => false,
        ]);

        ChatMessage::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'body' => 'First',
        ]);
        ChatMessage::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'body' => 'Second',
        ]);

        Queue::assertPushed(SendUnreadChatEmail::class, 1);
        Queue::assertPushed(SendUnreadChatEmail::class, function (SendUnreadChatEmail $job): bool {
            return $job->delay instanceof Carbon
                && $job->delay->equalTo(now()->addMinutes(5));
        });

        $supportAdmin = User::factory()->create();
        [$supportOne, $supportTwo] = Conversation::pairFor($sender, $supportAdmin);
        $supportConversation = Conversation::query()->create([
            'user_one_id' => $supportOne,
            'user_two_id' => $supportTwo,
            'started_by_user_id' => $sender->id,
            'is_support' => true,
        ]);
        ChatMessage::query()->create([
            'conversation_id' => $supportConversation->id,
            'sender_id' => $sender->id,
            'body' => 'Support message',
        ]);

        Queue::assertPushed(SendUnreadChatEmail::class, 1);
    }

    public function test_delayed_job_sends_without_message_body_and_marks_block_notified(): void
    {
        Mail::fake();
        $sender = User::factory()->create([
            'name' => 'Sender Name',
            'given_name' => 'Sender',
            'family_name' => 'Name',
        ]);
        $recipient = User::factory()->create(['email_verified_at' => now(), 'locale' => 'en']);
        $recipient->profile()->update(['email_chat_notifications' => true]);
        [$one, $two] = Conversation::pairFor($sender, $recipient);
        $conversation = Conversation::query()->create([
            'user_one_id' => $one,
            'user_two_id' => $two,
            'started_by_user_id' => $sender->id,
            'is_support' => false,
        ]);
        $message = ChatMessage::withoutEvents(fn () => ChatMessage::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'body' => 'Secret message body',
        ]));
        $state = ChatEmailNotificationState::query()->create([
            'conversation_id' => $conversation->id,
            'recipient_id' => $recipient->id,
            'pending_message_id' => $message->id,
            'pending_token' => 'chat-token',
        ]);

        app()->call([new SendUnreadChatEmail($conversation->id, $recipient->id, 'chat-token'), 'handle']);

        Mail::assertSent(UnreadChatMessageMail::class, function (UnreadChatMessageMail $mail) use ($recipient): bool {
            return $mail->hasTo($recipient->email)
                && $mail->senderName === 'Sender Name'
                && ! str_contains($mail->render(), 'Secret message body');
        });
        $this->assertNull($state->fresh()->pending_token);
        $this->assertNotNull($state->fresh()->notified_at);
        $this->assertDatabaseHas('email_deliveries', [
            'bounce_token' => 'chat-token',
            'status' => EmailDelivery::STATUS_SENT,
        ]);
    }

    public function test_chat_email_renders_in_every_supported_locale_without_message_content(): void
    {
        $headings = [
            'en' => 'You have a new chat message',
            'he' => 'יש לך הודעת צ׳אט חדשה',
            'ru' => 'У вас новое сообщение в чате',
            'fr' => 'Vous avez un nouveau message',
        ];

        foreach ($headings as $locale => $heading) {
            $mail = (new UnreadChatMessageMail(
                senderName: 'Sender Name',
                chatUrl: 'https://sveevee.co.il/me?chatWith=42',
                returnPath: 'bounce+test@mail.sveevee.co.il',
                messageLocale: $locale,
            ))->locale($locale);
            $html = $mail->render();

            $this->assertStringContainsString($heading, $html);
            $this->assertStringContainsString('Sender Name', $html);
            $this->assertStringNotContainsString('Secret message body', $html);
        }
    }

    public function test_reading_chat_cancels_its_pending_email(): void
    {
        Mail::fake();
        Queue::fake();
        $sender = User::factory()->create();
        $recipient = User::factory()->create(['email_verified_at' => now()]);
        $recipient->profile()->update(['email_chat_notifications' => true]);
        [$one, $two] = Conversation::pairFor($sender, $recipient);
        $conversation = Conversation::query()->create([
            'user_one_id' => $one,
            'user_two_id' => $two,
            'started_by_user_id' => $sender->id,
            'is_support' => false,
        ]);
        ChatMessage::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'body' => 'Unread',
        ]);
        $state = ChatEmailNotificationState::query()->firstOrFail();

        Sanctum::actingAs($recipient);
        $this->getJson('/api/v1/chats/'.$conversation->id)->assertOk();

        app()->call([new SendUnreadChatEmail($conversation->id, $recipient->id, $state->pending_token), 'handle']);
        Mail::assertNothingSent();
        $this->assertDatabaseMissing('chat_email_notification_states', ['id' => $state->id]);
    }

    public function test_hard_bounce_suppresses_current_address_and_disables_email_features(): void
    {
        $user = User::factory()->create([
            'email' => 'hard-bounce@example.test',
            'email_verified_at' => now(),
        ]);
        $user->profile()->update(['email_chat_notifications' => true]);
        $delivery = EmailDelivery::query()->create([
            'user_id' => $user->id,
            'kind' => 'chat_unread',
            'recipient_email' => $user->email,
            'bounce_token' => 'hard-bounce-token',
            'status' => EmailDelivery::STATUS_SENT,
        ]);

        app(BounceService::class)->ingest($delivery->bounce_token, implode("\r\n", [
            'Action: failed',
            'Status: 5.1.1',
            'Diagnostic-Code: smtp; 550 5.1.1 User unknown',
        ]));

        $this->assertDatabaseHas('email_suppressions', [
            'email' => $user->email,
            'reason' => 'hard_bounce',
        ]);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertFalse((bool) $user->fresh()->profile->email_chat_notifications);
        $this->assertSame(EmailDelivery::STATUS_HARD_BOUNCED, $delivery->fresh()->status);
    }

    public function test_bounce_for_an_old_address_does_not_invalidate_the_current_address(): void
    {
        $user = User::factory()->create([
            'email' => 'old-address@example.test',
            'email_verified_at' => now(),
        ]);
        $delivery = EmailDelivery::query()->create([
            'user_id' => $user->id,
            'kind' => 'email_verification',
            'recipient_email' => $user->email,
            'bounce_token' => 'old-address-token',
            'status' => EmailDelivery::STATUS_SENT,
        ]);
        $user->forceFill(['email' => 'current-address@example.test'])->save();
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->profile()->update(['email_chat_notifications' => true]);

        app(BounceService::class)->ingest($delivery->bounce_token, "Status: 5.1.1\r\nDiagnostic-Code: smtp; 550 User unknown");

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertTrue((bool) $user->fresh()->profile->email_chat_notifications);
        $this->assertTrue(EmailSuppression::query()->where('email', 'old-address@example.test')->exists());
    }

    public function test_soft_bounce_is_recorded_without_suppressing_the_address(): void
    {
        $user = User::factory()->create([
            'email' => 'temporary-bounce@example.test',
            'email_verified_at' => now(),
        ]);
        $user->profile()->update(['email_chat_notifications' => true]);
        $delivery = EmailDelivery::query()->create([
            'user_id' => $user->id,
            'kind' => 'chat_unread',
            'recipient_email' => $user->email,
            'bounce_token' => 'soft-bounce-token',
            'status' => EmailDelivery::STATUS_SENT,
        ]);

        app(BounceService::class)->ingest($delivery->bounce_token, implode("\r\n", [
            'Action: delayed',
            'Status: 4.2.0',
            'Diagnostic-Code: smtp; 451 4.2.0 Mailbox temporarily unavailable',
        ]));

        $this->assertSame(EmailDelivery::STATUS_SOFT_BOUNCED, $delivery->fresh()->status);
        $this->assertFalse(EmailSuppression::query()->where('email', $user->email)->exists());
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertTrue((bool) $user->fresh()->profile->email_chat_notifications);
    }
}
