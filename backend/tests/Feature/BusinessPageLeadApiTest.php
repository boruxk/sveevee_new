<?php

namespace Tests\Feature;

use App\Models\BusinessPageLead;
use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BusinessPageLeadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_create_a_userless_business_page_from_the_campaign_form(): void
    {
        $response = $this->withHeaders([
            'Referer' => 'https://sveevee.co.il/he/free-business-page?utm_source=facebook',
            'User-Agent' => 'Meta-Test-Browser',
        ])->postJson('/api/v1/business-page-leads', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('data.created', true)
            ->assertJsonPath('data.page.name', 'Albert Locksmith')
            ->assertJsonPath('data.page.type', Page::TYPE_BUSINESS)
            ->assertJsonMissingPath('data.page.full_name')
            ->assertJsonMissingPath('data.page.email')
            ->assertJsonMissingPath('data.page.phone');
        $this->assertIsString($response->json('data.registration_token'));

        $page = Page::query()->sole();
        $worker = User::query()->where('role', 'ai_worker')->firstOrFail();
        $this->assertSame($worker->id, $page->user_id);
        $this->assertSame($worker->id, $page->created_by_user_id);
        $this->assertTrue($page->is_unclaimed);
        $this->assertSame('Albert Locksmith', $page->name);
        $this->assertSame('services.home_repairs.locksmith', $page->category_key);
        $this->assertSame('albert@example.com', $page->contact_email);
        $this->assertSame('+972546555580', $page->phone);
        $this->assertSame('Netanya', data_get($page->setup, 'address.city'));
        $this->assertSame([
            'store' => false,
            'services' => false,
            'events' => false,
            'price_list' => false,
        ], data_get($page->setup, 'features'));
        $this->assertSame($page->public_path, $response->json('data.page.public_path'));

        $this->getJson('/api/v1/pages/'.$page->id)
            ->assertOk()
            ->assertJsonPath('data.owner', null)
            ->assertJsonPath('data.is_unclaimed', true);

        $lead = BusinessPageLead::query()->sole();
        $this->assertSame($page->id, $lead->page_id);
        $this->assertSame(BusinessPageLead::SOURCE_LEADS_PAGE_001, $lead->source);
        $this->assertSame('Albert Eliasi', $lead->full_name);
        $this->assertSame('albert@example.com', $lead->email);
        $this->assertSame('facebook', $lead->utm_source);
        $this->assertSame('meta-locksmiths', $lead->utm_campaign);
        $this->assertSame('test-click-id', $lead->fbclid);
        $this->assertSame('Meta-Test-Browser', $lead->user_agent);
        $this->assertNotNull($lead->ip_hash);
        $this->assertTrue($lead->created_page);
        $this->assertNotNull($lead->consented_at);
    }

    public function test_admin_can_list_only_pages_created_by_leads_page_001(): void
    {
        $createdPageId = $this->postJson('/api/v1/business-page-leads', $this->payload())
            ->assertCreated()
            ->json('data.page.id');
        $worker = User::query()->where('role', 'ai_worker')->firstOrFail();
        Page::query()->create([
            'user_id' => $worker->id,
            'created_by_user_id' => $worker->id,
            'type' => Page::TYPE_BUSINESS,
            'is_unclaimed' => true,
            'name' => 'Unrelated Unclaimed Page',
            'category_key' => 'services.home_repairs.handyman',
            'setup' => ['address' => ['city' => 'Jerusalem']],
        ]);

        $admin = User::query()->where('email', config('sveevee.support_admin_email'))->firstOrFail();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/pages?source='.BusinessPageLead::SOURCE_LEADS_PAGE_001)
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $createdPageId)
            ->assertJsonPath('data.items.0.lead.source', BusinessPageLead::SOURCE_LEADS_PAGE_001)
            ->assertJsonPath('data.items.0.lead.full_name', 'Albert Eliasi')
            ->assertJsonPath('data.items.0.lead.email', 'albert@example.com');

        $this->getJson('/api/v1/admin/pages?source=unknown')->assertUnprocessable();
    }

    public function test_duplicate_submission_reuses_the_existing_page_and_records_the_lead(): void
    {
        $first = $this->postJson('/api/v1/business-page-leads', $this->payload())
            ->assertCreated();
        $second = $this->postJson('/api/v1/business-page-leads', $this->payload())
            ->assertOk()
            ->assertJsonPath('data.created', false)
            ->assertJsonPath('data.registration_token', null);

        $this->assertSame($first->json('data.page.id'), $second->json('data.page.id'));
        $this->assertDatabaseCount('pages', 1);
        $this->assertDatabaseCount('business_page_leads', 2);
        $this->assertSame(1, BusinessPageLead::query()->where('created_page', false)->count());
    }

    public function test_matching_registration_automatically_attaches_the_new_campaign_page(): void
    {
        $leadResponse = $this->postJson('/api/v1/business-page-leads', $this->payload())
            ->assertCreated();
        $pageId = $leadResponse->json('data.page.id');

        $this->postJson('/api/v1/auth/register', [
            'email' => 'albert@example.com',
            'password' => 'password1',
            'password_confirmation' => 'password1',
            'given_name' => 'Albert',
            'family_name' => 'Eliasi',
            'locale' => 'he',
            'consented' => true,
            'lead_page_registration_token' => $leadResponse->json('data.registration_token'),
        ])->assertCreated()
            ->assertJsonPath('data.lead_page_attached', true)
            ->assertJsonPath('data.lead_page.id', $pageId)
            ->assertJsonPath('data.user.business_page.id', $pageId)
            ->assertJsonPath('data.user.profile.city', 'Netanya')
            ->assertJsonPath('data.user.profile.phone', '+972546555580')
            ->assertJsonPath('data.user.profile_complete', true);

        $user = User::query()->where('email', 'albert@example.com')->firstOrFail();
        $page = Page::query()->findOrFail($pageId);
        $this->assertSame($user->id, $page->user_id);
        $this->assertFalse($page->is_unclaimed);
        $this->assertNotNull($page->claimed_at);
        $this->assertSame(BusinessPageLead::STATUS_CONVERTED, BusinessPageLead::query()->sole()->status);
    }

    public function test_private_account_email_may_differ_from_the_business_contact_email(): void
    {
        $leadResponse = $this->postJson('/api/v1/business-page-leads', $this->payload())
            ->assertCreated();
        $pageId = $leadResponse->json('data.page.id');

        $this->postJson('/api/v1/auth/register', [
            'email' => 'someone-else@example.com',
            'password' => 'password1',
            'password_confirmation' => 'password1',
            'given_name' => 'Someone',
            'family_name' => 'Else',
            'locale' => 'he',
            'consented' => true,
            'lead_page_registration_token' => $leadResponse->json('data.registration_token'),
        ])->assertCreated()
            ->assertJsonPath('data.lead_page_attached', true)
            ->assertJsonPath('data.lead_page.id', $pageId)
            ->assertJsonPath('data.user.business_page.id', $pageId);

        $page = Page::query()->findOrFail($pageId);
        $this->assertFalse($page->is_unclaimed);
        $this->assertSame('albert@example.com', $page->contact_email);
        $this->assertSame('someone-else@example.com', $page->user->email);
        $this->assertSame(BusinessPageLead::STATUS_CONVERTED, BusinessPageLead::query()->sole()->status);
    }

    public function test_tampered_campaign_registration_token_cannot_attach_the_page(): void
    {
        $leadResponse = $this->postJson('/api/v1/business-page-leads', $this->payload())
            ->assertCreated();
        $token = $leadResponse->json('data.registration_token');
        $tamperedToken = substr($token, 0, -1).($token[-1] === 'a' ? 'b' : 'a');
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/business-page-leads/attach', [
            'registration_token' => $tamperedToken,
        ])->assertUnprocessable();

        $this->assertTrue(Page::query()->sole()->is_unclaimed);
        $this->assertSame(BusinessPageLead::STATUS_NEW, BusinessPageLead::query()->sole()->status);
    }

    public function test_authenticated_account_can_attach_its_pending_campaign_page(): void
    {
        $leadResponse = $this->postJson('/api/v1/business-page-leads', $this->payload())
            ->assertCreated();
        $user = User::factory()->create(['email' => 'albert@example.com']);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/business-page-leads/attach', [
            'registration_token' => $leadResponse->json('data.registration_token'),
        ])->assertOk()
            ->assertJsonPath('data.page.id', $leadResponse->json('data.page.id'));

        $this->assertSame($user->id, Page::query()->sole()->user_id);
        $this->assertSame('Netanya', $user->profile()->firstOrFail()->city);
    }

    public function test_expired_campaign_registration_token_cannot_attach_the_page(): void
    {
        $leadResponse = $this->postJson('/api/v1/business-page-leads', $this->payload())
            ->assertCreated();
        $user = User::factory()->create(['email' => 'albert@example.com']);
        Sanctum::actingAs($user);
        $this->travel(25)->hours();

        $this->postJson('/api/v1/business-page-leads/attach', [
            'registration_token' => $leadResponse->json('data.registration_token'),
        ])->assertUnprocessable();

        $this->assertTrue(Page::query()->sole()->is_unclaimed);
        $this->assertSame(BusinessPageLead::STATUS_NEW, BusinessPageLead::query()->sole()->status);
    }

    public function test_campaign_page_does_not_replace_an_existing_business_page(): void
    {
        $leadResponse = $this->postJson('/api/v1/business-page-leads', $this->payload())
            ->assertCreated();
        $user = User::factory()->create(['email' => 'albert@example.com']);
        Page::query()->create([
            'user_id' => $user->id,
            'created_by_user_id' => $user->id,
            'type' => Page::TYPE_BUSINESS,
            'is_unclaimed' => false,
            'name' => 'Existing Managed Business',
            'category_key' => 'services.home_repairs.handyman',
            'setup' => ['address' => ['city' => 'Jerusalem']],
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/business-page-leads/attach', [
            'registration_token' => $leadResponse->json('data.registration_token'),
        ])->assertUnprocessable();

        $leadPage = Page::query()->findOrFail($leadResponse->json('data.page.id'));
        $this->assertTrue($leadPage->is_unclaimed);
        $this->assertNotSame($user->id, $leadPage->user_id);
    }

    public function test_campaign_form_accepts_alfei_menashe_as_a_city(): void
    {
        $this->postJson('/api/v1/business-page-leads', $this->payload([
            'business_name' => 'Alfei Menashe Locksmith',
            'city' => 'Alfei Menashe',
            'email' => 'alfei@example.com',
        ]))->assertCreated();

        $this->assertSame('Alfei Menashe', data_get(Page::query()->sole()->setup, 'address.city'));
    }

    public function test_campaign_form_rejects_unknown_page_values_and_invalid_contact_data(): void
    {
        $invalidContact = $this->payload([
            'email' => 'not-an-email',
            'phone' => '123',
            'consent' => false,
        ]);

        $this->postJson('/api/v1/business-page-leads', $invalidContact)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'phone', 'consent']);

        $invalidPage = $this->payload([
            'city' => 'Not A Real City',
            'category_key' => 'not.a.real.category',
        ]);

        $this->postJson('/api/v1/business-page-leads', $invalidPage)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['address.city', 'category_key']);

        $this->assertDatabaseCount('pages', 0);
        $this->assertDatabaseCount('business_page_leads', 0);
    }

    public function test_campaign_form_honeypot_rejects_bot_submissions(): void
    {
        $this->postJson('/api/v1/business-page-leads', $this->payload([
            'website' => 'https://spam.example',
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors('website');

        $this->assertDatabaseCount('pages', 0);
        $this->assertDatabaseCount('business_page_leads', 0);
    }

    public function test_campaign_form_is_rate_limited_per_ip(): void
    {
        foreach (range(1, 3) as $attempt) {
            $this->postJson('/api/v1/business-page-leads', $this->payload())
                ->assertSuccessful();
        }

        $this->postJson('/api/v1/business-page-leads', $this->payload())
            ->assertTooManyRequests();
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'business_name' => 'Albert Locksmith',
            'city' => 'Netanya',
            'category_key' => 'services.home_repairs.locksmith',
            'full_name' => 'Albert Eliasi',
            'email' => ' Albert@Example.com ',
            'phone' => '+972546555580',
            'locale' => 'he',
            'consent' => true,
            'website' => '',
            'utm_source' => 'facebook',
            'utm_medium' => 'paid_social',
            'utm_campaign' => 'meta-locksmiths',
            'utm_content' => 'netanya-video',
            'utm_term' => 'locksmith',
            'fbclid' => 'test-click-id',
        ], $overrides);
    }
}
