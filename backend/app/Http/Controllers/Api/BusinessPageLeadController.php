<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ExactPageDuplicateException;
use App\Http\Controllers\Controller;
use App\Models\BusinessPageLead;
use App\Models\Page;
use App\Models\User;
use App\Rules\CleanContent;
use App\Services\AiWorkPageService;
use App\Services\ApiResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BusinessPageLeadController extends Controller
{
    public function __construct(private readonly AiWorkPageService $pages) {}

    public function store(Request $request)
    {
        $request->merge([
            'business_name' => trim((string) $request->input('business_name')),
            'city' => trim((string) $request->input('city')),
            'category_key' => trim((string) $request->input('category_key')),
            'full_name' => trim((string) $request->input('full_name')),
            'email' => mb_strtolower(trim((string) $request->input('email'))),
            'phone' => trim((string) $request->input('phone')),
            'locale' => trim((string) $request->input('locale', 'he')),
        ]);

        $data = $request->validate([
            'business_name' => ['required', 'string', 'min:2', 'max:255', new CleanContent],
            'city' => ['required', 'string', 'max:120'],
            'category_key' => ['required', 'string', 'max:255'],
            'full_name' => ['required', 'string', 'min:2', 'max:255', new CleanContent],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:40', 'regex:/^\+?[0-9][0-9\s().-]{7,24}$/'],
            'locale' => ['required', Rule::in(['he', 'en', 'ru', 'fr'])],
            'consent' => ['required', 'accepted'],
            'website' => ['nullable', 'string', 'max:0'],
            'utm_source' => ['nullable', 'string', 'max:255'],
            'utm_medium' => ['nullable', 'string', 'max:255'],
            'utm_campaign' => ['nullable', 'string', 'max:255'],
            'utm_content' => ['nullable', 'string', 'max:255'],
            'utm_term' => ['nullable', 'string', 'max:255'],
            'fbclid' => ['nullable', 'string', 'max:500'],
        ]);

        $pageInput = [
            'type' => Page::TYPE_BUSINESS,
            'name' => $data['business_name'],
            'public_description' => null,
            'contact_email' => $data['email'],
            'phone' => $data['phone'],
            'whatsapp' => null,
            'website' => null,
            'category_key' => $data['category_key'],
            'address' => [
                'street' => null,
                'number' => null,
                'city' => $data['city'],
                'neighborhood' => null,
            ],
            'socials' => [],
            'opening_hours' => [],
            'service_areas' => [],
            'specialties' => [],
        ];
        $pageInput['palette_key'] = $this->pages->automaticPalette($pageInput);
        $pageData = $this->pages->validate($pageInput);
        $worker = User::query()->where('role', 'ai_worker')->oldest('id')->first();

        if (! $worker) {
            return ApiResponseService::error('Business page creation is temporarily unavailable.', status: 503);
        }

        [$page, $created] = DB::transaction(function () use ($request, $data, $pageData, $worker): array {
            $created = true;

            try {
                $page = $this->pages->create($worker, $pageData);
            } catch (ExactPageDuplicateException $exception) {
                $match = collect($exception->matches)->firstWhere('type', Page::TYPE_BUSINESS);
                $page = $match ? Page::query()->find($match['id']) : null;

                if (! $page) {
                    throw $exception;
                }

                $created = false;
            }

            BusinessPageLead::query()->create([
                'page_id' => $page->id,
                'source' => BusinessPageLead::SOURCE_LEADS_PAGE_001,
                'business_name' => $data['business_name'],
                'city' => $data['city'],
                'category_key' => $data['category_key'],
                'full_name' => $data['full_name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'locale' => $data['locale'],
                'created_page' => $created,
                'utm_source' => $data['utm_source'] ?? null,
                'utm_medium' => $data['utm_medium'] ?? null,
                'utm_campaign' => $data['utm_campaign'] ?? null,
                'utm_content' => $data['utm_content'] ?? null,
                'utm_term' => $data['utm_term'] ?? null,
                'fbclid' => $data['fbclid'] ?? null,
                'landing_url' => $request->headers->get('referer'),
                'ip_hash' => $request->ip()
                    ? hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'))
                    : null,
                'user_agent' => $request->userAgent(),
                'consented_at' => now(),
            ]);

            return [$page, $created];
        });

        return ApiResponseService::success([
            'created' => $created,
            'page' => [
                'id' => $page->id,
                'name' => $page->name,
                'type' => $page->type,
                'public_path' => $page->public_path,
            ],
        ], $created ? 'Business page created.' : 'Business page already exists.', $created ? 201 : 200);
    }
}
