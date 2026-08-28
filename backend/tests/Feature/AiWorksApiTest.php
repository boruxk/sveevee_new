<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\Page;
use App\Models\PageClaimRequest;
use App\Models\User;
use App\Support\CatalogTopics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AiWorksApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_worker_account_and_task_crud_are_isolated_from_regular_user_apis(): void
    {
        $worker = $this->aiWorker();

        $this->assertMatchesRegularExpression('/^\$2y\$\d{2}\$/', $worker->password);
        $this->assertSame('en', $worker->locale);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/ai-works/tasks')->assertForbidden();

        Sanctum::actingAs($worker);
        $created = $this->postJson('/api/v1/ai-works/tasks', [
            'title' => 'Research a local business',
            'text' => 'Use an official public source and record the source URL and check date.',
        ])->assertCreated()
            ->assertJsonPath('data.title', 'Research a local business');

        $taskId = $created->json('data.id');
        $this->putJson("/api/v1/ai-works/tasks/{$taskId}", [
            'title' => 'Research and verify a local business',
            'text' => 'Use an official public source and record the source URL and check date.',
        ])->assertOk()
            ->assertJsonPath('data.title', 'Research and verify a local business');

        $this->getJson('/api/v1/ai-works/tasks')
            ->assertOk()
            ->assertJsonCount(1, 'data.tasks');

        $this->getJson('/api/v1/chats')->assertForbidden();

        $this->deleteJson("/api/v1/ai-works/tasks/{$taskId}")->assertOk();
        $this->assertDatabaseMissing('ai_work_tasks', ['id' => $taskId]);
    }

    public function test_ai_worker_creates_information_only_unclaimed_pages(): void
    {
        $worker = $this->aiWorker();
        Sanctum::actingAs($worker);

        $created = $this->postJson('/api/v1/ai-works/pages', $this->pagePayload())
            ->assertCreated()
            ->assertJsonPath('data.is_unclaimed', true)
            ->assertJsonPath('data.user_id', null)
            ->assertJsonPath('data.owner', null)
            ->assertJsonPath('data.features.store', false)
            ->assertJsonPath('data.features.services', false)
            ->assertJsonPath('data.logo_url', null)
            ->assertJsonPath('data.banner_url', null)
            ->assertJsonPath('data.source_url', 'https://example.com/business-directory/sample-business');

        $pageId = $created->json('data.id');
        $this->assertDatabaseHas('pages', [
            'id' => $pageId,
            'user_id' => $worker->id,
            'created_by_user_id' => $worker->id,
            'is_unclaimed' => true,
        ]);

        $this->getJson("/api/v1/pages/{$pageId}")
            ->assertOk()
            ->assertJsonPath('data.user_id', null)
            ->assertJsonPath('data.rating_summary.count', 0)
            ->assertJsonCount(0, 'data.products')
            ->assertJsonCount(0, 'data.services');

        $page = Page::query()->findOrFail($pageId);
        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('/he/business/'.$page->public_slug, false);

        Sanctum::actingAs(User::factory()->create());
        $this->putJson("/api/v1/pages/{$pageId}/ratings/me", [
            'rating' => 5,
            'comment' => 'A rating must not be accepted yet.',
        ])->assertStatus(409);

        $this->getJson("/api/v1/pages/{$pageId}/chat")->assertStatus(409);
    }

    public function test_claim_request_appears_in_support_and_admin_can_approve_it(): void
    {
        $worker = $this->aiWorker();
        Sanctum::actingAs($worker);
        $pageId = $this->postJson('/api/v1/ai-works/pages', $this->pagePayload())
            ->assertCreated()
            ->json('data.id');

        $claimant = User::factory()->create();
        Sanctum::actingAs($claimant);
        $claim = $this->postJson("/api/v1/pages/{$pageId}/claim-requests", [
            'message' => 'I own this business and can verify it through the official business email.',
        ])->assertCreated()
            ->assertJsonPath('data.status', PageClaimRequest::STATUS_PENDING)
            ->assertJsonPath('data.page.id', $pageId);

        $claimId = $claim->json('data.id');
        $request = PageClaimRequest::query()->findOrFail($claimId);
        $this->assertDatabaseHas('chat_messages', [
            'conversation_id' => $request->conversation_id,
            'sender_id' => $claimant->id,
        ]);
        $this->assertStringContainsString(
            "[PAGE CLAIM REQUEST #{$claimId}]",
            ChatMessage::query()->where('conversation_id', $request->conversation_id)->latest('id')->value('body')
        );

        $this->getJson('/api/v1/chats')
            ->assertOk()
            ->assertJsonPath('data.conversations.0.is_support', true);

        $admin = User::query()->where('email', config('sveevee.support_admin_email'))->firstOrFail();
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/support-chats')
            ->assertOk()
            ->assertJsonPath('data.conversations.0.pending_claim_count', 1)
            ->assertJsonPath('data.conversations.0.claim_requests.0.id', $claimId);

        $this->postJson("/api/v1/admin/page-claims/{$claimId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', PageClaimRequest::STATUS_APPROVED)
            ->assertJsonPath('data.page.is_unclaimed', false);

        $this->assertDatabaseHas('pages', [
            'id' => $pageId,
            'user_id' => $claimant->id,
            'created_by_user_id' => $worker->id,
            'is_unclaimed' => false,
        ]);
        $this->assertDatabaseHas('page_claim_requests', [
            'id' => $claimId,
            'status' => PageClaimRequest::STATUS_APPROVED,
            'reviewed_by_user_id' => $admin->id,
        ]);

        Sanctum::actingAs($claimant);
        $this->getJson('/api/v1/pages/business/mine')
            ->assertOk()
            ->assertJsonPath('data.id', $pageId)
            ->assertJsonPath('data.is_unclaimed', false);
    }

    public function test_admin_can_reject_a_claim_without_transferring_the_page(): void
    {
        $worker = $this->aiWorker();
        Sanctum::actingAs($worker);
        $pageId = $this->postJson('/api/v1/ai-works/pages', $this->pagePayload())
            ->assertCreated()
            ->json('data.id');

        $claimant = User::factory()->create();
        Sanctum::actingAs($claimant);
        $claimId = $this->postJson("/api/v1/pages/{$pageId}/claim-requests", [
            'message' => 'Please review my ownership request.',
        ])->assertCreated()->json('data.id');

        $admin = User::query()->where('email', config('sveevee.support_admin_email'))->firstOrFail();
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/page-claims/{$claimId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', PageClaimRequest::STATUS_CANCELLED);

        $page = Page::query()->findOrFail($pageId);
        $this->assertTrue($page->is_unclaimed);
        $this->assertSame($worker->id, $page->user_id);
    }

    private function aiWorker(): User
    {
        return User::query()->where('login', 'spfksfmbvpt')->firstOrFail();
    }

    private function pagePayload(): array
    {
        return [
            'name' => 'Sample Public Business',
            'public_description' => 'A basic public description from the official business directory.',
            'contact_email' => 'contact@example.com',
            'phone' => '02-555-0100',
            'website' => 'example.com',
            'category_key' => CatalogTopics::keysForScope(CatalogTopics::SCOPE_BUSINESS_PAGES)[0],
            'palette_key' => 'amber-dawn',
            'source_url' => 'example.com/business-directory/sample-business',
            'source_checked_at' => now()->format('Y-m-d'),
            'address' => [
                'street' => 'Jaffa Street',
                'number' => '1',
                'city' => 'Jerusalem',
                'neighborhood' => 'City Center',
            ],
            'socials' => [],
            'opening_hours' => [],
        ];
    }
}
