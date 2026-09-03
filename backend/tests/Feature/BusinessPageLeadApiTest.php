<?php

namespace Tests\Feature;

use App\Models\BusinessPageLead;
use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_duplicate_submission_reuses_the_existing_page_and_records_the_lead(): void
    {
        $first = $this->postJson('/api/v1/business-page-leads', $this->payload())
            ->assertCreated();
        $second = $this->postJson('/api/v1/business-page-leads', $this->payload())
            ->assertOk()
            ->assertJsonPath('data.created', false);

        $this->assertSame($first->json('data.page.id'), $second->json('data.page.id'));
        $this->assertDatabaseCount('pages', 1);
        $this->assertDatabaseCount('business_page_leads', 2);
        $this->assertSame(1, BusinessPageLead::query()->where('created_page', false)->count());
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
