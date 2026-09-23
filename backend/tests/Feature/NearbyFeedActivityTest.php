<?php

namespace Tests\Feature;

use App\Models\Ad;
use App\Models\LocalQuestion;
use App\Models\PageEvent;
use App\Models\PublicComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NearbyFeedActivityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-23 12:00:00'));
        Queue::fake();
    }

    public function test_visible_answers_raise_questions_among_ads_and_events_without_changing_creation_dates(): void
    {
        $owner = User::factory()->create();
        $answered = $this->question($owner, now()->subDays(3));
        $quiet = $this->question($owner, now()->subDays(2));
        $newer = $this->question($owner, now()->subHours(1));
        $ad = $this->ad($owner, now()->subHours(2));
        $event = $this->event($owner, now()->subHours(3));
        $this->comment($owner, $answered, now()->subMinutes(30));
        // Discussion on other card types still does not change their feed order.
        foreach (['ad' => $ad, 'event' => $event] as $type => $target) {
            PublicComment::create(['target_type' => $type, 'target_id' => $target->id, 'user_id' => $owner->id, 'body' => 'New comment']);
        }
        $quiet->update(['title' => 'Edited title', 'status' => 'resolved']);

        $response = $this->getJson('/api/v1/nearby')->assertOk();
        $this->assertSame(['question:'.$answered->id, 'question:'.$newer->id, 'ad:'.$ad->id, 'event:'.$event->id, 'question:'.$quiet->id], $this->ids($response));
        $response->assertJsonPath('data.items.0.created_at', $answered->created_at->toISOString())
            ->assertJsonPath('data.items.0.value.created_at', $answered->created_at->toISOString())
            ->assertJsonPath('data.items.0.activity_at', now()->subMinutes(30)->toISOString())
            ->assertJsonPath('data.items.2.activity_at', $ad->created_at->toISOString())
            ->assertJsonPath('data.items.3.activity_at', $event->created_at->toISOString())
            ->assertJsonPath('data.items.4.activity_at', $quiet->created_at->toISOString());
    }

    public function test_reply_creation_and_removal_use_the_same_public_visibility_as_the_discussion(): void
    {
        $owner = User::factory()->create();
        $helper = User::factory()->create();
        $old = $this->question($owner, now()->subDays(2));
        $newer = $this->question($owner, now()->subDay());
        Sanctum::actingAs($helper);
        $answerId = $this->postJson('/api/v1/discussions/question/'.$old->id.'/comments', ['body' => 'A helpful local answer.'])
            ->assertCreated()->json('data.id');
        $this->getJson('/api/v1/nearby?kind=question')->assertOk()->assertJsonPath('data.items.0.id', $old->id);
        $this->deleteJson('/api/v1/comments/'.$answerId)->assertOk();
        $this->getJson('/api/v1/nearby?kind=question')->assertOk()->assertJsonPath('data.items.0.id', $newer->id)
            ->assertJsonPath('data.items.1.activity_at', $old->created_at->toISOString());

        $reply = $this->comment($helper, $old, now()->subHour());
        $helper->forceFill(['banned_at' => now()])->save();
        $this->getJson('/api/v1/nearby?kind=question')->assertOk()->assertJsonPath('data.items.0.id', $newer->id);
        $helper->forceFill(['banned_at' => null])->save();
        $this->getJson('/api/v1/nearby?kind=question')->assertOk()->assertJsonPath('data.items.0.id', $old->id);
        $reply->delete();
        $this->getJson('/api/v1/nearby?kind=question')->assertOk()->assertJsonPath('data.items.0.id', $newer->id);
    }

    public function test_visible_nested_replies_count_but_hidden_or_banned_parent_answers_do_not(): void
    {
        $owner = User::factory()->create();
        $helper = User::factory()->create();
        $old = $this->question($owner, now()->subDays(3));
        $newer = $this->question($owner, now()->subDay());
        $parent = $this->comment($helper, $old, now()->subDays(2));
        $this->comment($owner, $old, now()->subHour(), ['parent_id' => $parent->id]);
        $this->getJson('/api/v1/nearby?kind=question')->assertOk()->assertJsonPath('data.items.0.id', $old->id);

        $parent->forceFill(['hidden_at' => now()])->save();
        $this->getJson('/api/v1/nearby?kind=question')->assertOk()->assertJsonPath('data.items.0.id', $newer->id)
            ->assertJsonPath('data.items.1.activity_at', $old->created_at->toISOString());
        $parent->forceFill(['hidden_at' => null])->save();
        $helper->forceFill(['banned_at' => now()])->save();
        $this->getJson('/api/v1/nearby?kind=question')->assertOk()->assertJsonPath('data.items.0.id', $newer->id);
        $helper->forceFill(['banned_at' => null])->save();
        $this->getJson('/api/v1/nearby?kind=question')->assertOk()->assertJsonPath('data.items.0.id', $old->id);
    }

    public function test_keyset_pagination_uses_activity_ties_and_an_insertion_snapshot_for_new_answers(): void
    {
        $owner = User::factory()->create();
        $quiet = $active = $ads = $events = [];
        foreach (range(1, 26) as $n) {
            $question = $this->question($owner, now()->subDays(3));
            if ($n <= 23) {
                $this->comment($owner, $question, now());
                $active[] = $question;
            } else {
                $quiet[] = $question;
            }
        }
        foreach (range(1, 12) as $n) {
            $ads[] = $this->ad($owner, now());
            $events[] = $this->event($owner, now());
        }
        $expected = [
            ...array_map(fn ($q) => 'question:'.$q->id, array_reverse($active)),
            ...array_map(fn ($ad) => 'ad:'.$ad->id, array_reverse($ads)),
            ...array_map(fn ($event) => 'event:'.$event->id, array_reverse($events)),
            ...array_map(fn ($q) => 'question:'.$q->id, array_reverse($quiet)),
        ];
        $first = $this->getJson('/api/v1/nearby')->assertOk()->assertJsonCount(20, 'data.items');
        // Same-second inserts are excluded by the id ceiling, not just time.
        $this->comment($owner, $quiet[0], now());
        $newAd = $this->ad($owner, now());
        $newEvent = $this->event($owner, now());
        $this->travel(1)->seconds();
        $this->comment($owner, $quiet[1], now());
        $newQuestion = $this->question($owner, now());
        $seen = $this->ids($first);
        $current = $first;
        for ($safe = 0; $current->json('data.has_more') && $safe < 5; $safe++) {
            $current = $this->getJson('/api/v1/nearby?'.http_build_query(['cursor' => $current->json('data.next_cursor')]))->assertOk();
            $seen = [...$seen, ...$this->ids($current)];
        }
        $this->assertSame($expected, $seen);
        $this->assertFalse($current->json('data.has_more'));
        $this->assertCount(count($seen), array_unique($seen));

        $fresh = $this->getJson('/api/v1/nearby')->assertOk();
        $fresh->assertJsonPath('data.items.0.id', $newQuestion->id)->assertJsonPath('data.items.1.id', $quiet[1]->id)
            ->assertJsonPath('data.items.2.id', $quiet[0]->id);
        $adsResponse = $this->getJson('/api/v1/nearby?kind=ad')->assertOk();
        $eventsResponse = $this->getJson('/api/v1/nearby?kind=event')->assertOk();
        $adsResponse->assertJsonPath('data.items.0.id', $newAd->id);
        $eventsResponse->assertJsonPath('data.items.0.id', $newEvent->id);
    }

    public function test_legacy_creation_order_cursors_are_rejected_and_reply_rows_are_not_hydrated(): void
    {
        $owner = User::factory()->create();
        foreach (range(1, 32) as $n) {
            $question = $this->question($owner, now()->subDays(3));
            $this->comment($owner, $question, now());
            $this->ad($owner, now());
            $this->event($owner, now());
        }
        $hydrated = ['question' => 0, 'ad' => 0, 'event' => 0, 'comment' => 0];
        foreach (['question' => LocalQuestion::class, 'ad' => Ad::class, 'event' => PageEvent::class, 'comment' => PublicComment::class] as $type => $model) {
            Event::listen('eloquent.retrieved: '.$model, function ($row) use (&$hydrated, $type) {
                // Existing social counts return grouped aggregates, not reply rows.
                $hydrated[$type] += (int) ($row->getKey() !== null);
            });
        }
        $this->getJson('/api/v1/nearby')->assertOk()->assertJsonCount(20, 'data.items');
        $this->assertSame(['question' => 21, 'ad' => 21, 'event' => 21, 'comment' => 0], $hydrated);
        $legacy = Crypt::encryptString(json_encode([
            'at' => now()->format('Y-m-d H:i:s'), 'rank' => 3, 'id' => 10,
            'filter' => hash('sha256', json_encode(['all', '', '', '', null])),
        ]));
        $this->getJson('/api/v1/nearby?'.http_build_query(['cursor' => $legacy]))->assertUnprocessable()->assertJsonValidationErrors('cursor');
    }

    private function question(User $owner, Carbon $at): LocalQuestion
    {
        return tap((new LocalQuestion)->forceFill([
            'user_id' => $owner->id, 'title' => 'A local question', 'body' => 'Advice for this area',
            'city' => 'Jerusalem', 'neighborhood' => 'Ramot', 'category_key' => 'professionals.electricians',
            'created_at' => $at, 'updated_at' => $at,
        ]), fn ($question) => $question->save());
    }

    private function comment(User $owner, LocalQuestion $question, Carbon $at, array $extra = []): PublicComment
    {
        return tap((new PublicComment)->forceFill([
            'target_type' => 'question', 'target_id' => $question->id, 'user_id' => $owner->id, 'body' => 'A local answer',
            'created_at' => $at, 'updated_at' => $at, ...$extra,
        ]), fn ($comment) => $comment->save());
    }

    private function ad(User $owner, Carbon $at): Ad
    {
        return tap((new Ad)->forceFill([
            'user_id' => $owner->id, 'type' => Ad::TYPE_PRIVATE, 'title' => 'Local offer', 'text' => 'Local details',
            'status' => 'active', 'created_at' => $at, 'updated_at' => $at,
        ]), fn ($ad) => $ad->save());
    }

    private function event(User $owner, Carbon $at): PageEvent
    {
        return tap((new PageEvent)->forceFill([
            'user_id' => $owner->id, 'name' => 'Local event', 'description' => 'Local details',
            'image_path' => 'events/fixture.webp', 'event_date' => today()->addDay(), 'event_time' => '18:00',
            'address' => 'Local venue', 'created_at' => $at, 'updated_at' => $at,
        ]), fn ($event) => $event->save());
    }

    private function ids($response): array
    {
        return array_map(fn ($item) => $item['type'].':'.$item['id'], $response->json('data.items'));
    }
}
