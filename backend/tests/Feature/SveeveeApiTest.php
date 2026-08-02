<?php

namespace Tests\Feature;

use App\Models\Ad;
use App\Models\Page;
use App\Models\PageProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SveeveeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_creates_admin_user_and_private_ad_without_prefilled_pages(): void
    {
        $this->seed();

        $this->assertDatabaseHas('users', ['email' => 'admin@sveevee.local', 'role' => 'admin']);
        $this->assertDatabaseHas('users', ['email' => 'user@sveevee.local', 'role' => 'user']);
        $this->assertDatabaseMissing('pages', ['type' => 'business']);
        $this->assertDatabaseMissing('pages', ['type' => 'community']);
        $this->assertDatabaseHas('ads', ['type' => 'private_ad', 'title' => 'Kids chair to give away']);
    }

    public function test_chat_requires_reply_before_second_message_to_same_user(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();

        Sanctum::actingAs($sender);

        $this->postJson("/api/v1/chats/users/{$recipient->id}/messages", [
            'body' => 'Hallo',
        ])->assertCreated();

        $this->postJson("/api/v1/chats/users/{$recipient->id}/messages", [
            'body' => 'Noch eine Nachricht',
        ])->assertStatus(409)
            ->assertJsonPath('errors.reason', 'pending_reply');

        Sanctum::actingAs($recipient);

        $this->postJson("/api/v1/chats/users/{$sender->id}/messages", [
            'body' => 'Antwort',
        ])->assertCreated();

        Sanctum::actingAs($sender);

        $this->postJson("/api/v1/chats/users/{$recipient->id}/messages", [
            'body' => 'Danke',
        ])->assertCreated();
    }

    public function test_user_can_contact_only_ten_new_users_per_day(): void
    {
        $sender = User::factory()->create();
        Sanctum::actingAs($sender);

        $recipients = User::factory()->count(11)->create();

        foreach ($recipients->take(10) as $recipient) {
            $this->postJson("/api/v1/chats/users/{$recipient->id}/messages", [
                'body' => 'Hallo',
            ])->assertCreated();
        }

        $this->postJson("/api/v1/chats/users/{$recipients->last()->id}/messages", [
            'body' => 'Hallo Nummer 11',
        ])->assertStatus(429)
            ->assertJsonPath('errors.reason', 'daily_limit');
    }

    public function test_user_can_create_page_with_presence_details(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['email' => 'owner@example.test']);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/pages/business', [
            'name' => 'Miri Studio',
            'public_description' => 'Local design help.',
            'contact_email' => 'hello@example.test',
            'phone' => '+972 50 111 2222',
            'address' => 'Herzl 10, Haifa',
            'palette_key' => 'sea-glass',
            'setup' => [
                'contact' => [
                    'tel' => '+972 50 111 2222',
                    'email' => 'hello@example.test',
                    'whatsapp' => '+972 50 111 2222',
                ],
                'address' => [
                    'street' => 'Herzl',
                    'number' => '10',
                    'city' => 'Haifa',
                    'neighborhood' => 'Hadar',
                ],
                'opening_hours' => [
                    ['weekday' => 'monday', 'is_open' => true, 'opens_at' => '08:30', 'closes_at' => '16:00'],
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('data.palette_key', 'sea-glass')
            ->assertJsonPath('data.contact.whatsapp', '+972 50 111 2222')
            ->assertJsonPath('data.address_details.street', 'Herzl')
            ->assertJsonPath('data.address_details.neighborhood', 'Hadar')
            ->assertJsonPath('data.opening_hours.1.weekday', 'monday')
            ->assertJsonPath('data.opening_hours.1.opens_at', '08:30');

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.business_page.name', 'Miri Studio');

        $page = Page::query()->where('user_id', $user->id)->where('type', Page::TYPE_BUSINESS)->firstOrFail();
        $page->forceFill([
            'logo_path' => 'pages/logos/logo.png',
            'logo_original_name' => 'logo.png',
            'banner_path' => 'pages/banners/banner.png',
            'banner_original_name' => 'banner.png',
        ])->save();
        Storage::disk('public')->put('pages/logos/logo.png', 'logo');
        Storage::disk('public')->put('pages/banners/banner.png', 'banner');

        $this->post('/api/v1/pages/business', [
            'name' => 'Miri Studio',
            'public_description' => 'Local design help.',
            'contact_email' => 'hello@example.test',
            'phone' => '+972 50 111 2222',
            'address' => 'Herzl 10, Haifa',
            'palette_key' => 'sea-glass',
            'logo_remove' => '1',
            'banner_remove' => '1',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.logo_url', null)
            ->assertJsonPath('data.logo_name', null)
            ->assertJsonPath('data.banner_url', null)
            ->assertJsonPath('data.banner_name', null);

        Storage::disk('public')->assertMissing('pages/logos/logo.png');
        Storage::disk('public')->assertMissing('pages/banners/banner.png');
    }

    public function test_ads_use_owner_location_and_search_by_location(): void
    {
        $owner = User::factory()->create();
        $owner->profile()->update([
            'city' => 'Jerusalem',
            'neighborhood' => 'Ramot',
        ]);

        $other = User::factory()->create();
        $other->profile()->update([
            'city' => 'Jerusalem',
            'neighborhood' => 'Ramot',
        ]);

        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/ads', [
            'title' => 'Desk lamp',
            'text' => 'Pickup today.',
        ])->assertCreated()
            ->assertJsonPath('data.city', 'Jerusalem')
            ->assertJsonPath('data.neighborhood', 'Ramot');

        $page = Page::query()->create([
            'user_id' => $owner->id,
            'type' => Page::TYPE_BUSINESS,
            'name' => 'Florentin Studio',
            'setup' => [
                'address' => [
                    'street' => 'Herzl',
                    'number' => '10',
                    'city' => 'Tel Aviv',
                    'neighborhood' => 'Florentin',
                ],
            ],
        ]);

        $pageAd = $this->postJson('/api/v1/ads', [
            'title' => 'Desk lamp',
            'text' => 'Pickup from the studio.',
            'page_id' => $page->id,
        ])->assertCreated()
            ->assertJsonPath('data.city', 'Tel Aviv')
            ->assertJsonPath('data.neighborhood', 'Florentin');

        $this->getJson('/api/v1/pages/business/mine')
            ->assertOk()
            ->assertJsonCount(1, 'data.ads')
            ->assertJsonPath('data.ads.0.id', $pageAd->json('data.id'));

        Ad::query()->create([
            'user_id' => $other->id,
            'type' => Ad::TYPE_PRIVATE,
            'title' => 'Desk lamp',
            'text' => 'Pickup from Ramot.',
            'status' => 'active',
            'city' => 'Jerusalem',
            'neighborhood' => 'Ramot',
        ]);

        $query = http_build_query([
            'q' => 'Desk',
            'city' => 'Tel Aviv',
            'neighborhood' => 'Florentin',
        ]);

        $this->getJson("/api/v1/search?{$query}")
            ->assertOk()
            ->assertJsonCount(1, 'data.ads')
            ->assertJsonPath('data.ads.0.city', 'Tel Aviv')
            ->assertJsonPath('data.ads.0.neighborhood', 'Florentin');

        $locations = $this->getJson('/api/v1/locations')
            ->assertOk()
            ->assertJsonFragment(['city' => 'Tel Aviv', 'name' => 'Florentin']);

        $this->assertContains('Tel Aviv', $locations->json('data.cities'));
        $this->assertContains(['city' => 'Tel Aviv', 'name' => 'Ramat Aviv'], $locations->json('data.neighborhoods'));
    }

    public function test_ads_expire_after_one_week_and_can_be_pruned(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 10:00:00'));

        try {
            $owner = User::factory()->create();
            Sanctum::actingAs($owner);

            $this->postJson('/api/v1/ads', [
                'title' => 'One week ad',
                'text' => 'This should expire automatically.',
            ])->assertCreated()
                ->assertJsonPath('data.expires_at', now()->addWeek()->toISOString());

            $ad = Ad::query()->firstOrFail();
            $this->assertTrue($ad->expires_at->equalTo(now()->addWeek()));

            Carbon::setTestNow(now()->addDays(8));

            $this->getJson('/api/v1/ads?scope=mine')
                ->assertOk()
                ->assertJsonCount(0, 'data');

            $this->artisan('ads:prune-expired')
                ->expectsOutput('Deleted 1 expired ads.')
                ->assertExitCode(0);

            $this->assertDatabaseMissing('ads', ['id' => $ad->id]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_owner_can_update_ad(): void
    {
        Storage::fake('public');

        $owner = User::factory()->create();
        Sanctum::actingAs($owner);

        $created = $this->post('/api/v1/ads', [
            'title' => 'Original headline',
            'text' => 'Original text.',
            'image' => UploadedFile::fake()->image('ad.png'),
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.image_name', 'ad.png');

        $adId = $created->json('data.id');
        $oldImagePath = Ad::query()->findOrFail($adId)->image_path;
        Storage::disk('public')->assertExists($oldImagePath);

        $this->post("/api/v1/ads/{$adId}", [
            '_method' => 'PUT',
            'title' => 'Updated headline',
            'text' => 'Updated text.',
            'image_remove' => '1',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.title', 'Updated headline')
            ->assertJsonPath('data.text', 'Updated text.')
            ->assertJsonPath('data.image_url', null)
            ->assertJsonPath('data.image_name', null);

        $this->assertDatabaseHas('ads', [
            'id' => $adId,
            'title' => 'Updated headline',
            'text' => 'Updated text.',
            'image_path' => null,
            'image_original_name' => null,
        ]);
        Storage::disk('public')->assertMissing($oldImagePath);
    }

    public function test_user_can_upload_profile_photo_up_to_twenty_megabytes(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->post('/api/v1/profile/photo', [
            'photo_original_name' => 'konezki.png',
            'photo' => UploadedFile::fake()->image('avatar.png')->size(15000),
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.profile.photo_url', fn ($value) => is_string($value) && str_contains($value, '/storage/profiles/'))
            ->assertJsonPath('data.profile.photo_name', 'konezki.png');

        Storage::disk('public')->assertExists($user->fresh()->profile->photo_path);
        $this->assertDatabaseHas('user_profiles', [
            'user_id' => $user->id,
            'photo_original_name' => 'konezki.png',
        ]);
    }

    public function test_user_can_delete_profile_photo(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $profile = $user->profile()->updateOrCreate([], [
            'photo_path' => 'profiles/avatar.png',
            'photo_original_name' => 'avatar.png',
        ]);
        Storage::disk('public')->put($profile->photo_path, 'image');

        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/profile/photo')
            ->assertOk()
            ->assertJsonPath('data.profile.photo_url', null)
            ->assertJsonPath('data.user.profile.photo_url', null);

        Storage::disk('public')->assertMissing('profiles/avatar.png');
        $this->assertDatabaseHas('user_profiles', [
            'user_id' => $user->id,
            'photo_path' => null,
            'photo_original_name' => null,
        ]);
    }

    public function test_business_page_owner_can_add_store_product(): void
    {
        Storage::fake('public');

        $owner = User::factory()->create();
        $businessPage = Page::query()->create([
            'user_id' => $owner->id,
            'type' => Page::TYPE_BUSINESS,
            'name' => 'Miri Studio',
        ]);
        $communityPage = Page::query()->create([
            'user_id' => $owner->id,
            'type' => Page::TYPE_COMMUNITY,
            'name' => 'Miri Community',
        ]);

        Sanctum::actingAs($owner);

        $createdProduct = $this->post("/api/v1/pages/{$businessPage->id}/products", [
            'name' => 'Ceramic cup',
            'description' => 'Handmade cup from the studio.',
            'image' => UploadedFile::fake()->image('cup.jpg'),
            'price' => '29.90',
            'link' => 'https://seller.example/products/cup',
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.name', 'Ceramic cup')
            ->assertJsonPath('data.price', 29.9)
            ->assertJsonPath('data.link', 'https://seller.example/products/cup');

        $oldProductImagePath = PageProduct::query()->findOrFail($createdProduct->json('data.id'))->image_path;
        Storage::disk('public')->assertExists($oldProductImagePath);

        $this->post("/api/v1/products/{$createdProduct->json('data.id')}", [
            '_method' => 'PUT',
            'name' => 'Ceramic bowl',
            'description' => 'Updated product description.',
            'price' => '39.90',
            'link' => 'https://seller.example/products/bowl',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.name', 'Ceramic bowl')
            ->assertJsonPath('data.description', 'Updated product description.')
            ->assertJsonPath('data.price', 39.9)
            ->assertJsonPath('data.link', 'https://seller.example/products/bowl');

        $this->assertDatabaseHas('page_products', [
            'id' => $createdProduct->json('data.id'),
            'name' => 'Ceramic bowl',
            'description' => 'Updated product description.',
        ]);

        $this->post("/api/v1/products/{$createdProduct->json('data.id')}", [
            '_method' => 'PUT',
            'name' => 'Ceramic bowl',
            'description' => 'Updated product description.',
            'price' => '39.90',
            'link' => 'https://seller.example/products/bowl',
            'image_remove' => '1',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.image_url', null)
            ->assertJsonPath('data.image_name', null);

        Storage::disk('public')->assertMissing($oldProductImagePath);

        $this->getJson("/api/v1/pages/{$businessPage->id}")
            ->assertOk()
            ->assertJsonPath('data.products.0.name', 'Ceramic bowl')
            ->assertJsonPath('data.products.0.price_label', '₪39.90');

        $this->post("/api/v1/pages/{$communityPage->id}/products", [
            'name' => 'Community cup',
            'description' => 'Not allowed here.',
            'image' => UploadedFile::fake()->image('community-cup.jpg'),
            'price' => '19.90',
            'link' => 'https://seller.example/products/community-cup',
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_community_page_owner_can_add_event(): void
    {
        Storage::fake('public');

        $owner = User::factory()->create();
        $communityPage = Page::query()->create([
            'user_id' => $owner->id,
            'type' => Page::TYPE_COMMUNITY,
            'name' => 'Miri Community',
        ]);
        $businessPage = Page::query()->create([
            'user_id' => $owner->id,
            'type' => Page::TYPE_BUSINESS,
            'name' => 'Miri Studio',
        ]);

        Sanctum::actingAs($owner);

        $this->post("/api/v1/pages/{$communityPage->id}/events", [
            'name' => 'Friday Picnic',
            'description' => 'Bring snacks and meet the neighbors.',
            'image' => UploadedFile::fake()->image('picnic.jpg'),
            'date' => '2026-08-14',
            'time' => '17:30',
            'address' => 'Gan HaEm, Haifa',
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.name', 'Friday Picnic')
            ->assertJsonPath('data.date', '2026-08-14')
            ->assertJsonPath('data.time', '17:30')
            ->assertJsonPath('data.address', 'Gan HaEm, Haifa');

        $this->getJson("/api/v1/pages/{$communityPage->id}")
            ->assertOk()
            ->assertJsonPath('data.events.0.name', 'Friday Picnic')
            ->assertJsonPath('data.events.0.time', '17:30');

        $this->post("/api/v1/pages/{$businessPage->id}/events", [
            'name' => 'Business Event',
            'description' => 'Not allowed here.',
            'image' => UploadedFile::fake()->image('business-event.jpg'),
            'date' => '2026-08-14',
            'time' => '17:30',
            'address' => 'Herzl 10, Haifa',
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_page_owner_can_delete_page_and_its_page_ads(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $page = Page::query()->create([
            'user_id' => $owner->id,
            'type' => Page::TYPE_BUSINESS,
            'name' => 'Miri Studio',
        ]);
        $pageAd = Ad::query()->create([
            'user_id' => $owner->id,
            'page_id' => $page->id,
            'type' => Ad::TYPE_BUSINESS,
            'title' => 'Studio sale',
            'text' => 'Today only.',
            'status' => 'active',
        ]);

        Sanctum::actingAs($other);

        $this->deleteJson("/api/v1/pages/{$page->id}")
            ->assertStatus(403);

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/v1/pages/{$page->id}")
            ->assertOk();

        $this->assertDatabaseMissing('pages', ['id' => $page->id]);
        $this->assertDatabaseMissing('ads', ['id' => $pageAd->id]);

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.business_page', null);
    }

    public function test_user_can_rate_page_and_update_rating(): void
    {
        $owner = User::factory()->create();
        $reviewer = User::factory()->create();
        $page = Page::query()->create([
            'user_id' => $owner->id,
            'type' => Page::TYPE_BUSINESS,
            'name' => 'Miri Studio',
        ]);

        Sanctum::actingAs($reviewer);

        $this->putJson("/api/v1/pages/{$page->id}/ratings/me", [
            'rating' => 4,
            'comment' => 'Helpful and quick.',
        ])->assertOk()
            ->assertJsonPath('data.summary.count', 1)
            ->assertJsonPath('data.summary.average', 4)
            ->assertJsonPath('data.rating.comment', 'Helpful and quick.');

        $this->putJson("/api/v1/pages/{$page->id}/ratings/me", [
            'rating' => 5,
            'comment' => 'Updated after another visit.',
        ])->assertOk()
            ->assertJsonPath('data.summary.count', 1)
            ->assertJsonPath('data.summary.average', 5)
            ->assertJsonPath('data.rating.rating', 5);

        $this->getJson("/api/v1/pages/{$page->id}/ratings")
            ->assertOk()
            ->assertJsonPath('data.summary.count', 1)
            ->assertJsonPath('data.items.0.rating', 5)
            ->assertJsonPath('data.my_rating.rating', 5);
    }

    public function test_admin_can_ban_email_and_block_login(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create([
            'email' => 'blocked@example.test',
            'password' => 'password',
        ]);

        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/admin/users/{$user->id}/ban", [
            'reason' => 'Spam',
        ])->assertOk()
            ->assertJsonPath('data.banned_at', fn ($value) => filled($value));

        $this->postJson('/api/v1/auth/login', [
            'email' => 'blocked@example.test',
            'password' => 'password',
        ])->assertStatus(403);
    }
}
