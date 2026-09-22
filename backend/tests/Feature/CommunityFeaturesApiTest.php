<?php

namespace Tests\Feature;

use App\Jobs\NotifyCommunitySubscribers;
use App\Models\Ad;
use App\Models\CommunitySubscription;
use App\Models\LocalQuestion;
use App\Models\Page;
use App\Models\PageEvent;
use App\Models\PublicComment;
use App\Models\User;
use App\Services\CommunityContentService;
use App\Services\PageDeletionService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CommunityFeaturesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Queue::fake();
    }

    public function test_questions_are_public_but_writes_require_active_owner_and_private_fields_are_omitted(): void
    {
        $this->postJson('/api/v1/questions', $this->questionData())->assertUnauthorized();
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);
        $id = $this->postJson('/api/v1/questions', $this->questionData())->assertCreated()
            ->assertJsonPath('data.status', 'open')->assertJsonPath('data.resolved', false)
            ->assertJsonPath('data.can_edit', true)->assertJsonMissingPath('data.author.email')->json('data.id');
        Queue::assertPushed(NotifyCommunitySubscribers::class, fn ($job) => $job->type === 'question' && $job->targetId === $id);
        $this->getJson('/api/v1/questions?mine=1')->assertOk()->assertJsonCount(1, 'data.items');
        $this->patchJson('/api/v1/questions/'.$id, ['resolved' => true])->assertOk()->assertJsonPath('data.resolved', true);
        $this->postJson('/api/v1/questions', [...$this->questionData(), 'category_key' => 'made-up'])->assertUnprocessable();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/questions/'.$id)->assertOk()->assertJsonPath('data.can_edit', false);
        $this->patchJson('/api/v1/questions/'.$id, ['title' => 'Changed'])->assertForbidden();
        $this->deleteJson('/api/v1/questions/'.$id)->assertForbidden();
        Sanctum::actingAs($owner);
        $this->deleteJson('/api/v1/questions/'.$id)->assertOk();
        $this->getJson('/api/v1/questions/'.$id)->assertNotFound();
        $this->getJson('/api/v1/nearby?kind=question')->assertOk()->assertJsonCount(0, 'data.items');
        $this->assertDatabaseHas('local_questions', ['id' => $id]);
    }

    public function test_feed_keyset_cursor_is_bounded_and_stable_across_ties_and_new_insertions(): void
    {
        $owner = User::factory()->create();
        $page = $this->page($owner);
        $expected = [];
        foreach (range(1, 23) as $n) {
            $expected[] = 'question:'.$this->question($owner, ['title' => 'Question '.$n])->id;
        }
        $expected = array_reverse($expected);
        $ads = $events = [];
        foreach (range(1, 12) as $n) {
            $ads[] = 'ad:'.$this->ad($owner, ['title' => 'Ad '.$n])->id;
            $events[] = 'event:'.$this->event($owner, $page, ['name' => 'Event '.$n])->id;
        }
        $expected = [...$expected, ...array_reverse($ads), ...array_reverse($events)];
        $queries = [];
        $recording = true;
        DB::listen(function (QueryExecuted $q) use (&$queries, &$recording) {
            if ($recording) {
                $queries[] = $q->sql;
            }
        });
        $first = $this->getJson('/api/v1/nearby')->assertOk()->assertJsonCount(20, 'data.items')->assertJsonPath('data.has_more', true);
        $recording = false;
        foreach ($queries as $sql) {
            if (preg_match('/^select.*from ["`](local_questions|ads|page_events)["`]/i', $sql) && str_contains($sql, 'order by')) {
                $this->assertStringContainsString('limit 21', $sql);
                $this->assertStringNotContainsString(' offset ', $sql);
            }
        }
        $this->question($owner, ['title' => 'Inserted after first request']);
        $seen = $this->feedIds($first);
        $current = $first;
        for ($safe = 0; $current->json('data.has_more') && $safe < 5; $safe++) {
            $current = $this->getJson('/api/v1/nearby?'.http_build_query(['cursor' => $current->json('data.next_cursor')]))->assertOk();
            $seen = [...$seen, ...$this->feedIds($current)];
        }
        $this->assertSame($expected, $seen);
        $this->assertFalse($current->json('data.has_more'));
        $this->getJson('/api/v1/nearby?cursor=invalid')->assertUnprocessable();
        $this->getJson('/api/v1/nearby?'.http_build_query(['city' => 'Haifa', 'cursor' => $first->json('data.next_cursor')]))->assertUnprocessable();
        $this->getJson('/api/v1/nearby?category_key=invalid')->assertUnprocessable();
    }

    public function test_feed_excludes_banned_expired_inactive_hidden_and_unclaimed_content_and_maps_legacy_categories(): void
    {
        $owner = User::factory()->create();
        $owner->profile()->update(['city' => 'Jerusalem', 'neighborhood' => 'Ramot']);
        $page = $this->page($owner);
        $imported = $this->page($owner, ['is_unclaimed' => true]);
        $visible = $this->ad($owner, ['category' => 'home_professionals.electrician']);
        $this->ad($owner, ['status' => 'inactive']);
        $this->ad($owner, ['expires_at' => now()->subDay()]);
        $this->ad($owner, ['page_id' => $imported->id]);
        $hidden = $this->ad($owner);
        $hidden->forceFill(['community_hidden_at' => now()])->save();
        $banned = User::factory()->create(['banned_at' => now()]);
        $this->ad($banned);
        $this->question($banned);
        $this->event($owner, $page, ['event_date' => today()->subDay()]);
        $this->event($owner, $imported);
        $event = $this->event($owner, $page);
        $this->getJson('/api/v1/nearby')->assertOk()->assertJsonCount(2, 'data.items');
        $this->getJson('/api/v1/nearby?category_key=professionals.electricians&city=Jerusalem&neighborhood=Ramot')
            ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.id', $visible->id)
            ->assertJsonMissingPath('data.items.0.value.user.profile.email')->assertJsonMissingPath('data.items.0.value.user.email');
        $this->getJson('/api/v1/nearby/events/'.$event->id)->assertOk()->assertJsonPath('data.public_path', '/events/'.$event->id);
        $this->getJson('/api/v1/nearby?kind=following')->assertUnauthorized();
    }

    public function test_discussions_allow_one_reply_level_and_only_question_author_can_mark_other_answers_helpful(): void
    {
        $author = User::factory()->create();
        $helper = User::factory()->create();
        $question = $this->question($author);
        $other = $this->question($author);
        $page = $this->page($author);
        Sanctum::actingAs($helper);
        $id = $this->postJson('/api/v1/discussions/question/'.$question->id.'/comments', ['body' => 'Try this local business', 'recommended_page_id' => $page->id])
            ->assertCreated()->assertJsonPath('data.is_owner', false)->assertJsonPath('data.can_delete', true)
            ->assertJsonPath('data.recommended_page.can_rate', true)->json('data.id');
        $this->putJson('/api/v1/questions/'.$question->id.'/helpful/'.$id)->assertForbidden();
        $this->postJson('/api/v1/discussions/question/'.$other->id.'/comments', ['body' => 'Wrong thread', 'parent_id' => $id])->assertNotFound();
        Sanctum::actingAs($author);
        $reply = $this->postJson('/api/v1/discussions/question/'.$question->id.'/comments', ['body' => 'Thank you', 'parent_id' => $id])
            ->assertCreated()->assertJsonPath('data.is_owner', true)->json('data.id');
        $this->postJson('/api/v1/discussions/question/'.$question->id.'/comments', ['body' => 'Too deeply nested', 'parent_id' => $reply])->assertNotFound();
        $this->putJson('/api/v1/questions/'.$question->id.'/helpful/'.$reply)->assertForbidden();
        $this->putJson('/api/v1/questions/'.$question->id.'/helpful/'.$id)->assertOk()->assertJsonPath('data.helpful', true);
        $this->assertSame(1, $helper->notifications()->where('type', 'community_helpful')->count());
        $this->deleteJson('/api/v1/questions/'.$question->id.'/helpful/'.$id)->assertOk()->assertJsonPath('data.helpful', false);
        $this->getJson('/api/v1/discussions/question/'.$question->id.'/comments')->assertOk()->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.is_owner', false)->assertJsonPath('data.items.1.is_owner', true);
        $this->deleteJson('/api/v1/comments/'.$id)->assertForbidden();
        Sanctum::actingAs($helper);
        $this->deleteJson('/api/v1/comments/'.$id)->assertOk();
        $this->getJson('/api/v1/discussions/question/'.$question->id.'/comments')->assertOk()->assertJsonCount(0, 'data.items');
        $this->putJson('/api/v1/social/comment/'.$reply.'/like')->assertNotFound();
        $this->getJson('/api/v1/social-state?'.http_build_query(['targets' => ['comment:'.$reply]]))->assertOk()->assertJsonCount(0, 'data.items');
    }

    public function test_likes_are_idempotent_and_group_notifications_while_hidden_parent_blocks_all_comment_actions(): void
    {
        $author = User::factory()->create();
        $liker = User::factory()->create();
        $question = $this->question($author);
        Sanctum::actingAs($liker);
        $url = '/api/v1/social/question/'.$question->id.'/like';
        $this->putJson($url)->assertOk()->assertJsonPath('data.likes_count', 1)->assertJsonPath('data.liked', true);
        $this->putJson($url)->assertOk()->assertJsonPath('data.likes_count', 1);
        $this->deleteJson($url)->assertOk()->assertJsonPath('data.likes_count', 0)->assertJsonPath('data.liked', false);
        $this->putJson($url)->assertOk()->assertJsonPath('data.likes_count', 1);
        Sanctum::actingAs(User::factory()->create());
        $this->putJson($url)->assertOk()->assertJsonPath('data.likes_count', 2);
        $this->assertSame(1, $author->notifications()->where('type', 'community_like')->count());
        $comment = $this->postJson('/api/v1/discussions/question/'.$question->id.'/comments', ['body' => 'An answer'])->assertCreated()->json('data.id');
        $question->forceFill(['hidden_at' => now()])->save();
        $this->getJson('/api/v1/social/comment/'.$comment)->assertNotFound();
        $this->putJson('/api/v1/social/comment/'.$comment.'/like')->assertNotFound();
        $this->postJson('/api/v1/discussions/question/'.$question->id.'/comments', ['body' => 'Another answer'])->assertNotFound();
        $this->getJson('/api/v1/social-state?'.http_build_query(['targets' => ['question:'.$question->id, 'comment:'.$comment]]))->assertOk()->assertJsonCount(0, 'data.items');
        $this->getJson('/api/v1/social-state?'.http_build_query(['targets' => array_fill(0, 101, 'question:1')]))->assertUnprocessable();
        $this->getJson('/api/v1/social-state?'.http_build_query(['targets' => ['user:1']]))->assertUnprocessable();
    }

    public function test_subscription_ownership_following_filter_and_optout_are_enforced(): void
    {
        $subscriber = User::factory()->create();
        $owner = User::factory()->create();
        $page = $this->page($owner);
        $this->getJson('/api/v1/subscriptions/status?page_id='.$page->id)->assertOk()->assertJsonPath('data.subscribed', false);
        Sanctum::actingAs($subscriber);
        $sid = $this->postJson('/api/v1/subscriptions', ['page_id' => $page->id])->assertCreated()->assertJsonPath('data.notifications_enabled', true)->json('data.id');
        $this->postJson('/api/v1/subscriptions', ['page_id' => $page->id])->assertOk()->assertJsonPath('data.id', $sid);
        $this->getJson('/api/v1/subscriptions/status?page_id='.$page->id)->assertOk()->assertJsonPath('data.subscribed', true);
        $this->postJson('/api/v1/subscriptions', ['category_key' => 'made-up', 'city' => 'Jerusalem'])->assertUnprocessable();
        $this->postJson('/api/v1/subscriptions', ['page_id' => $page->id, 'city' => 'Jerusalem'])->assertUnprocessable();
        $ad = $this->ad($owner, ['page_id' => $page->id]);
        $this->ad($owner);
        $this->question($owner);
        $this->getJson('/api/v1/nearby?kind=following')->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.id', $ad->id);
        $this->postJson('/api/v1/subscriptions', ['category_key' => 'professionals.electricians', 'city' => 'Jerusalem'])->assertCreated();
        $this->getJson('/api/v1/nearby?kind=following')->assertOk()->assertJsonCount(2, 'data.items');
        $this->patchJson('/api/v1/subscriptions/'.$sid, ['notifications_enabled' => false])->assertOk()->assertJsonPath('data.notifications_enabled', false);
        Sanctum::actingAs($owner);
        $this->deleteJson('/api/v1/subscriptions/'.$sid)->assertNotFound();
        $this->patchJson('/api/v1/subscriptions/'.$sid, ['notifications_enabled' => true])->assertNotFound();
        Sanctum::actingAs($subscriber);
        $this->deleteJson('/api/v1/subscriptions/'.$sid)->assertOk();
        $this->getJson('/api/v1/subscriptions')->assertOk()->assertJsonCount(1, 'data.items');
    }

    public function test_subscriber_jobs_deduplicate_overlapping_follows_honor_optout_and_skip_hidden_content(): void
    {
        $owner = User::factory()->create();
        $follower = User::factory()->create();
        $page = $this->page($owner);
        Sanctum::actingAs($follower);
        $this->postJson('/api/v1/subscriptions', ['page_id' => $page->id])->assertCreated();
        $this->postJson('/api/v1/subscriptions', ['category_key' => 'professionals.electricians', 'city' => 'Jerusalem'])->assertCreated();
        $ad = $this->ad($owner, ['page_id' => $page->id, 'category' => 'home_professionals.electrician']);
        $job = new NotifyCommunitySubscribers('ad', $ad->id);
        $job->handle(app(CommunityContentService::class));
        $job->handle(app(CommunityContentService::class));
        $this->assertSame(1, $follower->notifications()->where('type', 'community_activity')->count());
        CommunitySubscription::query()->update(['notifications_enabled' => false]);
        $question = $this->question($owner);
        (new NotifyCommunitySubscribers('question', $question->id))->handle(app(CommunityContentService::class));
        $this->assertSame(1, $follower->notifications()->count());
        CommunitySubscription::query()->update(['notifications_enabled' => true]);
        $question->forceFill(['hidden_at' => now()])->save();
        (new NotifyCommunitySubscribers('question', $question->id))->handle(app(CommunityContentService::class));
        $this->assertSame(1, $follower->notifications()->count());
    }

    public function test_reports_are_private_and_admin_hiding_removes_content_from_existing_apis_without_erasing_evidence(): void
    {
        $owner = User::factory()->create();
        $reporter = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $ad = $this->ad($owner);
        $event = $this->event($owner, $this->page($owner));
        Sanctum::actingAs($reporter);
        $rid = $this->postJson('/api/v1/social/ad/'.$ad->id.'/reports', ['reason' => 'Misleading business details'])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->postJson('/api/v1/social/ad/'.$ad->id.'/reports', ['reason' => 'Same report'])->assertOk()->assertJsonPath('data.id', $rid);
        $this->getJson('/api/v1/admin/community-reports')->assertForbidden();
        $this->patchJson('/api/v1/admin/community-reports/'.$rid, ['action' => 'hide'])->assertForbidden();
        $eventReport = $this->postJson('/api/v1/social/event/'.$event->id.'/reports', ['reason' => 'Incorrect event'])->assertCreated()->json('data.id');
        Sanctum::actingAs($admin);
        $this->patchJson('/api/v1/admin/community-reports/'.$rid, ['action' => 'hide'])->assertOk()->assertJsonPath('data.status', 'hidden')->assertJsonPath('data.target.body', 'Advertisement details');
        $this->assertDatabaseHas('ads', ['id' => $ad->id]);
        $this->assertNull(Ad::find($ad->id));
        $this->getJson('/api/v1/social/ad/'.$ad->id)->assertNotFound();
        $this->getJson('/api/v1/ads/'.$ad->id)->assertNotFound();
        $this->patchJson('/api/v1/admin/community-reports/'.$eventReport, ['action' => 'dismiss'])->assertOk()->assertJsonPath('data.status', 'dismissed');
        $this->getJson('/api/v1/nearby/events/'.$event->id)->assertOk();
        $this->getJson('/api/v1/admin/community-reports')->assertOk()->assertJsonCount(2, 'data.items');
    }

    public function test_page_recommendation_lookup_is_bounded_and_never_offers_rating_of_unclaimed_or_own_business(): void
    {
        $owner = User::factory()->create();
        $visitor = User::factory()->create();
        $this->page($owner);
        $this->page($owner, ['is_unclaimed' => true]);
        $this->page(User::factory()->create(['banned_at' => now()]));
        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/nearby/page-options?q=Bu')->assertOk()->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.can_rate', false)->assertJsonPath('data.items.1.can_rate', false);
        Sanctum::actingAs($visitor);
        $result = $this->getJson('/api/v1/nearby/page-options?q=Bu')->assertOk()->json('data.items');
        $this->assertSame([true, false], array_column($result, 'can_rate'));
        $this->getJson('/api/v1/nearby/page-options?q=B')->assertUnprocessable();
    }

    public function test_subscription_cap_and_write_rate_limits_are_explicit_without_charging_read_requests(): void
    {
        $owner = User::factory()->create();
        $page = $this->page($owner);
        Sanctum::actingAs($owner);
        foreach (range(1, 100) as $n) {
            CommunitySubscription::create(['user_id' => $owner->id, 'category_key' => 'professionals.electricians', 'city' => 'City '.$n,
                'scope_key' => hash('sha256', 'fixture-'.$n), 'notifications_enabled' => true]);
        }
        $this->postJson('/api/v1/subscriptions', ['page_id' => $page->id])->assertUnprocessable();
        foreach (range(1, 22) as $n) {
            $this->getJson('/api/v1/subscriptions')->assertOk();
        }
        // The failed mutation consumed one write; reads consumed none.
        foreach (range(1, 19) as $n) {
            $this->postJson('/api/v1/questions', $this->questionData())->assertCreated();
        }
        $this->postJson('/api/v1/questions', $this->questionData())->assertTooManyRequests();
        $this->getJson('/api/v1/questions?mine=1')->assertOk();
    }

    public function test_banned_accounts_cannot_write_or_moderate_and_offensive_comments_are_rejected(): void
    {
        $owner = User::factory()->create();
        $question = $this->question($owner);
        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/discussions/question/'.$question->id.'/comments', ['body' => 'fuck you'])->assertUnprocessable();
        Sanctum::actingAs(User::factory()->create(['banned_at' => now()]));
        $this->postJson('/api/v1/questions', $this->questionData())->assertForbidden();
        $this->putJson('/api/v1/social/question/'.$question->id.'/like')->assertForbidden();
        $this->postJson('/api/v1/subscriptions', ['category_key' => 'professionals.electricians', 'city' => 'Jerusalem'])->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'banned_at' => now()]));
        $this->getJson('/api/v1/admin/community-reports')->assertForbidden();
    }

    public function test_comment_cursor_keeps_all_replies_and_hiding_an_event_keeps_its_media_cleanup_working(): void
    {
        $owner = User::factory()->create();
        $helper = User::factory()->create();
        $question = $this->question($owner);
        $root = PublicComment::create(['target_type' => 'question', 'target_id' => $question->id, 'user_id' => $helper->id, 'body' => 'First answer']);
        foreach (range(1, 32) as $n) {
            PublicComment::create(['target_type' => 'question', 'target_id' => $question->id, 'user_id' => $owner->id, 'parent_id' => $root->id, 'body' => 'Detail '.$n]);
        }
        $url = '/api/v1/discussions/question/'.$question->id.'/comments';
        $first = $this->getJson($url)->assertOk()->assertJsonCount(30, 'data.items')->assertJsonPath('data.has_more', true);
        $this->getJson($url.'?cursor='.$first->json('data.next_cursor'))->assertOk()->assertJsonCount(3, 'data.items')->assertJsonPath('data.has_more', false);
        $this->assertSame(33, $this->getJson('/api/v1/social/question/'.$question->id)->assertOk()->json('data.comments_count'));

        Storage::fake('public');
        $page = $this->page($owner);
        $event = $this->event($owner, $page);
        $event->forceFill(['community_hidden_at' => now()])->save();
        $ad = $this->ad($owner, ['page_id' => $page->id, 'image_path' => 'ads/hidden.webp']);
        $ad->forceFill(['community_hidden_at' => now()])->save();
        Storage::disk('public')->put($event->image_path, 'fixture');
        Storage::disk('public')->put($ad->image_path, 'fixture');
        $page->load(['ads', 'events']); // Cleanup must not trust preloaded public-only relations.
        app(PageDeletionService::class)->delete($page);
        $this->assertDatabaseMissing('ads', ['id' => $ad->id]);
        $this->assertDatabaseMissing('page_events', ['id' => $event->id]);
        Storage::disk('public')->assertMissing($event->image_path);
        Storage::disk('public')->assertMissing($ad->image_path);
    }

    private function questionData(): array
    {
        return ['title' => 'Can anyone recommend an electrician?', 'body' => 'Looking for help nearby.', 'city' => 'Jerusalem', 'neighborhood' => 'Ramot', 'category_key' => 'professionals.electricians'];
    }

    private function question(User $owner, array $overrides = []): LocalQuestion
    {
        return LocalQuestion::create(['user_id' => $owner->id, ...$this->questionData(), ...$overrides]);
    }

    private function ad(User $owner, array $overrides = []): Ad
    {
        return Ad::create(['user_id' => $owner->id, 'type' => Ad::TYPE_PRIVATE, 'title' => 'Local offer', 'text' => 'Advertisement details', 'status' => 'active', ...$overrides]);
    }

    private function page(User $owner, array $overrides = []): Page
    {
        return Page::create(['user_id' => $owner->id, 'created_by_user_id' => $owner->id, 'type' => Page::TYPE_BUSINESS, 'name' => 'Business '.Page::count(), 'is_unclaimed' => false,
            'setup' => ['address' => ['city' => 'Jerusalem', 'neighborhood' => 'Ramot']], ...$overrides]);
    }

    private function event(User $owner, Page $page, array $overrides = []): PageEvent
    {
        return PageEvent::create(['user_id' => $owner->id, 'page_id' => $page->id, 'name' => 'Local event', 'description' => 'Event details', 'image_path' => 'events/fixture.webp', 'event_date' => today()->addDay(), 'event_time' => '18:00', 'address' => 'Local venue', ...$overrides]);
    }

    private function feedIds($response): array
    {
        return array_map(fn ($item) => $item['type'].':'.$item['id'], $response->json('data.items'));
    }
}
