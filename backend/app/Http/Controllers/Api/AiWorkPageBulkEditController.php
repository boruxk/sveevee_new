<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ExactPageDuplicateException;
use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Services\AiWorkPageService;
use App\Services\ApiResponseService;
use App\Services\PageIdentityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AiWorkPageBulkEditController extends Controller
{
    private const MAX_PAGES = 1000;

    public function __construct(
        private readonly AiWorkPageService $pages,
        private readonly PageIdentityService $identities,
    ) {}

    public function export(Request $request)
    {
        $filters = $this->pages->validateBulkEditFilters($request->query());
        $query = Page::query()
            ->where('user_id', $request->user()->id)
            ->where('is_unclaimed', true)
            ->when($filters['city'], fn ($query, string $city) => $query->where('setup->address->city', $city))
            ->when($filters['neighborhood'], fn ($query, string $neighborhood) => $query->where('setup->address->neighborhood', $neighborhood))
            ->when($filters['category_key'], fn ($query, string $category) => $query->where('category_key', $category))
            ->when($filters['id_from'], fn ($query, int $id) => $query->where('id', '>=', $id))
            ->when($filters['id_to'], fn ($query, int $id) => $query->where('id', '<=', $id));

        $matchedCount = (clone $query)->count();
        $pages = $query
            ->orderBy('id')
            ->limit(self::MAX_PAGES)
            ->get();
        $truncated = $matchedCount > $pages->count();

        return ApiResponseService::success([
            'filters' => $filters,
            'pages' => $pages->map(fn (Page $page): array => $this->pages->bulkEditableData($page))->values(),
            'matched_count' => $matchedCount,
            'returned_count' => $pages->count(),
            'limit' => self::MAX_PAGES,
            'truncated' => $truncated,
            'next_id_from' => $truncated && $pages->isNotEmpty() ? $pages->last()->id + 1 : null,
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'pages' => ['required', 'array', 'min:1', 'max:'.self::MAX_PAGES],
            'pages.*' => ['required', 'array:id,type,name,public_description,contact_email,phone,whatsapp,website,category_key,address,socials,opening_hours,service_areas,specialties'],
            'pages.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'pages.*.type' => ['sometimes', 'string'],
            'pages.*.name' => ['sometimes', 'string'],
            'pages.*.public_description' => ['sometimes', 'nullable', 'string'],
            'pages.*.contact_email' => ['sometimes', 'nullable', 'string'],
            'pages.*.phone' => ['sometimes', 'nullable', 'string'],
            'pages.*.whatsapp' => ['sometimes', 'nullable', 'string'],
            'pages.*.website' => ['sometimes', 'nullable', 'string'],
            'pages.*.category_key' => ['sometimes', 'string'],
            'pages.*.address' => ['sometimes', 'array:street,number,city,neighborhood'],
            'pages.*.socials' => ['sometimes', 'array:facebook,instagram,tiktok,x,telegram'],
            'pages.*.opening_hours' => ['sometimes', 'array'],
            'pages.*.opening_hours.*' => ['array:weekday,is_open,opens_at,closes_at'],
            'pages.*.service_areas' => ['sometimes', 'array', 'max:10'],
            'pages.*.specialties' => ['sometimes', 'array', 'max:50'],
        ]);

        try {
            $updated = DB::transaction(function () use ($request, $data): array {
                $rows = array_values($data['pages']);
                $ids = collect($rows)->pluck('id')->map(fn ($id): int => (int) $id)->all();
                $pages = Page::query()
                    ->whereIn('id', $ids)
                    ->where('user_id', $request->user()->id)
                    ->where('is_unclaimed', true)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                if ($pages->count() !== count($ids)) {
                    throw ValidationException::withMessages([
                        'pages' => ['One or more pages are claimed, missing, or no longer editable. Reload the JSON before saving.'],
                    ]);
                }

                $validatedRows = [];
                $errors = [];

                foreach ($rows as $index => $row) {
                    $page = $pages->get((int) $row['id']);
                    $input = $this->mergeRow($page, $row);

                    try {
                        $validated = $this->pages->validate($input);
                    } catch (ValidationException $exception) {
                        foreach ($exception->errors() as $field => $messages) {
                            $errors["pages.{$index}.{$field}"] = $messages;
                        }

                        continue;
                    }

                    $matches = $this->identities->exactMatches($validated, $page->id);
                    if ($matches->isNotEmpty()) {
                        $errors["pages.{$index}.id"] = ['This edit would create an exact duplicate of another page.'];

                        continue;
                    }

                    $validatedRows[] = ['page' => $page, 'data' => $validated];
                }

                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }

                return collect($validatedRows)
                    ->map(fn (array $item): array => $this->pages->bulkEditableData(
                        $this->pages->update($item['page'], $item['data'])
                    ))
                    ->values()
                    ->all();
            });
        } catch (ExactPageDuplicateException $exception) {
            return ApiResponseService::error(
                'An exact page duplicate already exists.',
                ['pages' => ['An exact page duplicate already exists. Reload the JSON and try again.']],
                409,
                ['matches' => $exception->matches]
            );
        }

        return ApiResponseService::success([
            'updated_count' => count($updated),
            'pages' => $updated,
        ], 'Pages updated.');
    }

    private function mergeRow(Page $page, array $row): array
    {
        $input = $this->pages->editableData($page);

        foreach (['type', 'name', 'public_description', 'contact_email', 'phone', 'whatsapp', 'website', 'category_key'] as $field) {
            if (array_key_exists($field, $row)) {
                $input[$field] = $row[$field];
            }
        }

        if (array_key_exists('address', $row)) {
            $input['address'] = [...$input['address'], ...$row['address']];
        }

        if (array_key_exists('socials', $row)) {
            $input['socials'] = [...$input['socials'], ...$row['socials']];
        }

        if (array_key_exists('opening_hours', $row)) {
            $input['opening_hours'] = $row['opening_hours'];
        }

        if (array_key_exists('service_areas', $row)) {
            $input['service_areas'] = $row['service_areas'];
        }

        if (array_key_exists('specialties', $row)) {
            $input['specialties'] = $row['specialties'];
        }

        return $input;
    }
}
