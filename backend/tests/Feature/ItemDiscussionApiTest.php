<?php

namespace Tests\Feature;

use App\Jobs\NotifyCommunitySubscribers;
use App\Models\CommunitySubscription;
use App\Models\Page;
use App\Models\PageEvent;
use App\Models\PageProduct;
use App\Models\PageService;
use App\Models\PublicComment;
use App\Models\User;
use App\Services\CommunityContentService;
use App\Services\PageDeletionService;
use App\Services\UserDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ItemDiscussionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->freezeTime();
    }

    public function test_service_detail_is_public_and_does_not_hydrate_the_rest_of_the_business_catalog(): void
    {
        $owner = User::factory()->create();
        $page = $this->page($owner);
        $service = $this->item('service', $page);
        foreach (range(1, 35) as $n) {
            $this->item('service', $page, ['name' => 'Other service '.$n]);
        }
        $hydrated = [];
        $recording = true;
        PageService::retrieved(function (PageService $row) use (&$hydrated, &$recording) {
            if ($recording) {
                $hydrated[] = $row->id;
            }
        });
        try {
            $this->getJson('/api/v1/services/'.$service->id)->assertOk()
                ->assertJsonPath('data.public_path', '/services/'.$service->id)
                ->assertJsonPath('data.page.user_id', $owner->id)
                ->assertJsonPath('data.page.address_details.city', 'Jerusalem')
                ->assertJsonPath('data.link', 'https://example.test/service')
                ->assertJsonPath('data.social', ['likes_count' => 0, 'liked' => false, 'comments_count' => 0])
                ->assertJsonMissingPath('data.page.products')->assertJsonMissingPath('data.page.owner.email');
        } finally {
            $recording = false;
        }
        $this->assertSame([$service->id], $hydrated);
        $this->postJson('/api/v1/discussions/service/'.$service->id.'/comments', ['body' => 'What does this include?'])->assertUnauthorized();
    }

    public function test_products_and_services_support_public_questions_owner_replies_likes_and_correct_notification_paths(): void
    {
        $oldOwner = User::factory()->create();
        $owner = User::factory()->create();
        $visitor = User::factory()->create();
        $page = $this->page($oldOwner);
        $page->update(['user_id' => $owner->id]);
        foreach (['product', 'service'] as $type) {
            $item = $this->item($type, $page);
            $path = $type === 'product' ? '/product/'.$item->public_slug : '/services/'.$item->id;
            $url = '/api/v1/discussions/'.$type.'/'.$item->id.'/comments';
            Sanctum::actingAs($visitor);
            $comment = $this->postJson($url, ['body' => 'Is this currently available?'])->assertCreated()
                ->assertJsonPath('data.is_owner', false)->json('data.id');
            $notification = $owner->notifications()->where('type', 'community_reply')->latest('id')->firstOrFail();
            $this->assertSame($path, $notification->data['action_path']);
            $this->assertSame(0, $oldOwner->notifications()->count());
            Sanctum::actingAs($owner);
            $reply = $this->postJson($url, ['body' => 'Yes, please contact us.', 'parent_id' => $comment])->assertCreated()
                ->assertJsonPath('data.is_owner', true)->json('data.id');
            $this->getJson($url)->assertOk()->assertJsonCount(2, 'data.items')->assertJsonPath('data.items.1.is_owner', true);
            $this->postJson($url, ['body' => 'Nested too far', 'parent_id' => $reply])->assertNotFound();
            $this->putJson('/api/v1/social/'.$type.'/'.$item->id.'/like')->assertOk()->assertJsonPath('data.likes_count', 1);
            $this->putJson('/api/v1/social/'.$type.'/'.$item->id.'/like')->assertOk()->assertJsonPath('data.likes_count', 1);
            $this->getJson('/api/v1/social-state?'.http_build_query(['targets' => [$type.':'.$item->id, 'comment:'.$reply]]))->assertOk()->assertJsonCount(2, 'data.items');
            if ($type === 'product') {
                $this->getJson('/api/v1/products/'.$item->public_slug)->assertOk()
                    ->assertJsonPath('data.social.comments_count', 2)->assertJsonPath('data.social.likes_count', 1);
            }
        }
    }

    public function test_unclaimed_banned_and_nonbusiness_parent_pages_hide_item_details_and_all_discussion_access(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        Sanctum::actingAs($viewer);
        foreach (['product', 'service'] as $type) {
            $page = $this->page($owner);
            $item = $this->item($type, $page);
            $comment = PublicComment::create(['target_type' => $type, 'target_id' => $item->id, 'user_id' => $viewer->id, 'body' => 'Question']);
            foreach (['unclaimed', 'banned', 'community'] as $reason) {
                $page->update(['is_unclaimed' => $reason === 'unclaimed', 'type' => $reason === 'community' ? Page::TYPE_COMMUNITY : Page::TYPE_BUSINESS]);
                $owner->forceFill(['banned_at' => $reason === 'banned' ? now() : null])->save();
                $this->getJson('/api/v1/'.($type === 'product' ? 'products' : 'services').'/'.$item->id)->assertNotFound();
                $this->getJson('/api/v1/discussions/'.$type.'/'.$item->id.'/comments')->assertNotFound();
                $this->postJson('/api/v1/discussions/'.$type.'/'.$item->id.'/comments', ['body' => 'Another question'])->assertNotFound();
                $this->putJson('/api/v1/social/comment/'.$comment->id.'/like')->assertNotFound();
                $this->getJson('/api/v1/social-state?'.http_build_query(['targets' => [$type.':'.$item->id, 'comment:'.$comment->id]]))
                    ->assertOk()->assertJsonCount(0, 'data.items');
            }
            $owner->forceFill(['banned_at' => null])->save();
        }
    }

    public function test_reports_hide_products_and_services_from_details_and_discussions_but_preserve_admin_evidence(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $page = $this->page($owner);
        foreach (['product', 'service'] as $type) {
            $item = $this->item($type, $page);
            Sanctum::actingAs($viewer);
            $comment = $this->postJson('/api/v1/discussions/'.$type.'/'.$item->id.'/comments', ['body' => 'A public question'])->assertCreated()->json('data.id');
            $report = $this->postJson('/api/v1/social/'.$type.'/'.$item->id.'/reports', ['reason' => 'Incorrect details'])->assertCreated()->json('data.id');
            Sanctum::actingAs($admin);
            $this->patchJson('/api/v1/admin/community-reports/'.$report, ['action' => 'hide'])->assertOk()
                ->assertJsonPath('data.target.body', 'Public item description')->assertJsonPath('data.target.hidden', true);
            $this->getJson('/api/v1/'.($type === 'product' ? 'products' : 'services').'/'.$item->id)->assertNotFound();
            $this->getJson('/api/v1/discussions/'.$type.'/'.$item->id.'/comments')->assertNotFound();
            $this->getJson('/api/v1/social/comment/'.$comment)->assertNotFound();
            $this->assertDatabaseHas($item->getTable(), ['id' => $item->id]);
            $this->assertSame(0, $page->{$type === 'product' ? 'products' : 'services'}()->count());
        }
    }

    public function test_past_events_keep_public_detail_and_discussion_but_are_not_in_nearby_or_new_activity_notifications(): void
    {
        $owner = User::factory()->create();
        $visitor = User::factory()->create();
        $page = $this->page($owner);
        $past = PageEvent::create(['page_id' => $page->id, 'name' => 'Past event', 'description' => 'Event details', 'image_path' => 'events/past.webp', 'event_date' => today()->subDay(), 'event_time' => '19:00', 'address' => 'Local venue']);
        $this->getJson('/api/v1/nearby/events/'.$past->id)->assertOk()->assertJsonPath('data.name', 'Past event');
        $this->getJson('/api/v1/nearby?kind=event')->assertOk()->assertJsonCount(0, 'data.items');
        Sanctum::actingAs($visitor);
        $this->postJson('/api/v1/discussions/event/'.$past->id.'/comments', ['body' => 'Will there be another one?'])->assertCreated();
        $this->getJson('/api/v1/discussions/event/'.$past->id.'/comments')->assertOk()->assertJsonCount(1, 'data.items');
        CommunitySubscription::create(['user_id' => $visitor->id, 'page_id' => $page->id, 'scope_key' => hash('sha256', 'past-event'), 'notifications_enabled' => true]);
        (new NotifyCommunitySubscribers('event', $past->id))->handle(app(CommunityContentService::class));
        $this->assertSame(0, $visitor->notifications()->where('type', 'community_activity')->count());
        $past->forceFill(['community_hidden_at' => now()])->save();
        $this->getJson('/api/v1/nearby/events/'.$past->id)->assertNotFound();
    }

    public function test_deleting_pages_or_users_also_removes_media_for_moderated_products_and_services(): void
    {
        Storage::fake('public');
        foreach (['page', 'user'] as $deletion) {
            $owner = User::factory()->create();
            $page = $this->page($owner);
            $product = $this->item('product', $page);
            $service = $this->item('service', $page);
            foreach ([$product, $service] as $item) {
                $item->forceFill(['community_hidden_at' => now()])->save();
                Storage::disk('public')->put($item->image_path, 'fixture');
            }
            $page->load(['products', 'services']);
            if ($deletion === 'page') {
                app(PageDeletionService::class)->delete($page);
            } else {
                app(UserDeletionService::class)->delete($owner);
            }
            foreach ([$product, $service] as $item) {
                $this->assertDatabaseMissing($item->getTable(), ['id' => $item->id]);
                Storage::disk('public')->assertMissing($item->image_path);
            }
        }
    }

    private function page(User $owner): Page
    {
        return Page::create(['user_id' => $owner->id, 'type' => Page::TYPE_BUSINESS, 'name' => 'Local business', 'is_unclaimed' => false,
            'setup' => ['address' => ['city' => 'Jerusalem', 'neighborhood' => 'Ramot']]]);
    }

    private function item(string $type, Page $page, array $overrides = []): PageProduct|PageService
    {
        $data = ['page_id' => $page->id, 'name' => 'A local '.$type, 'description' => 'Public item description', 'image_path' => $type.'/fixture-'.$page->id.'.webp', 'link' => 'https://example.test/'.$type, ...$overrides];

        return $type === 'product' ? PageProduct::create([...$data, 'price' => 20]) : PageService::create($data);
    }
}
