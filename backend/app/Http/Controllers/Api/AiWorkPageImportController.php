<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ExactPageDuplicateException;
use App\Http\Controllers\Controller;
use App\Models\AiPageImport;
use App\Models\AiPageImportRow;
use App\Models\Page;
use App\Services\AiWorkPageService;
use App\Services\ApiResponseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AiWorkPageImportController extends Controller
{
    private const MAX_ROWS = 1000;

    public function __construct(private readonly AiWorkPageService $pages) {}

    public function index(Request $request)
    {
        $imports = AiPageImport::query()
            ->where('created_by_user_id', $request->user()->id)
            ->where('expires_at', '>', now())
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (AiPageImport $import) => $this->payload($import));

        return ApiResponseService::success(['imports' => $imports]);
    }

    public function show(Request $request, AiPageImport $import)
    {
        $this->ensureOwner($request, $import);

        return ApiResponseService::success($this->payload($import));
    }

    public function store(Request $request)
    {
        $request->validate([
            'client_import_id' => ['required', 'uuid'],
            'rows' => ['required', 'array', 'min:1', 'max:'.self::MAX_ROWS],
            'rows.*' => ['required', 'array'],
        ]);

        $existing = AiPageImport::query()
            ->where('created_by_user_id', $request->user()->id)
            ->where('client_import_id', $request->input('client_import_id'))
            ->first();

        if ($existing) {
            return ApiResponseService::success($this->payload($existing), 'Import already processed.');
        }

        $import = AiPageImport::query()->create([
            'created_by_user_id' => $request->user()->id,
            'client_import_id' => $request->input('client_import_id'),
            'status' => 'processing',
            'input_count' => count($request->input('rows')),
            'expires_at' => now()->addDays(30),
        ]);
        $created = [];
        $skipped = [];
        $duplicateCount = 0;
        $invalidCount = 0;

        foreach (array_values($request->input('rows')) as $index => $rawRow) {
            $position = $index + 1;
            $input = $this->normalizedRow($rawRow);

            try {
                $data = $this->pages->validate($input);
                $rowKey = hash('sha256', $position.'|'.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $page = DB::transaction(function () use ($request, $data, $import, $position, $rowKey): Page {
                    $page = $this->pages->create($request->user(), $data);
                    AiPageImportRow::query()->create([
                        'ai_page_import_id' => $import->id,
                        'position' => $position,
                        'row_key' => $rowKey,
                        'payload' => $data,
                        'page_id' => $page->id,
                    ]);

                    return $page;
                });
                $created[] = $this->pages->summary($page);
            } catch (ExactPageDuplicateException) {
                $duplicateCount++;
                $skipped[] = ['row' => $position, 'name' => $input['name'] ?? null, 'reason' => 'duplicate'];
            } catch (ValidationException $exception) {
                $invalidCount++;
                $skipped[] = [
                    'row' => $position,
                    'name' => $input['name'] ?? null,
                    'reason' => 'invalid',
                    'fields' => $exception->errors(),
                ];
            }
        }

        $import->update([
            'status' => 'completed',
            'created_count' => count($created),
            'duplicate_count' => $duplicateCount,
            'invalid_count' => $invalidCount,
            'created_page_ids' => collect($created)->pluck('id')->all(),
        ]);

        return ApiResponseService::success([
            ...$this->payload($import->fresh()),
            'created_pages' => $created,
            'skipped' => $skipped,
        ], 'Import completed.', 201);
    }

    private function normalizedRow(array $row): array
    {
        $address = is_array($row['address'] ?? null) ? $row['address'] : [];
        $socials = is_array($row['socials'] ?? null) ? $row['socials'] : [];

        $normalized = [
            'type' => $row['type'] ?? '',
            'name' => $row['name'] ?? $row['title'] ?? '',
            'public_description' => $row['public_description'] ?? $row['description'] ?? null,
            'category_key' => $row['category_key'] ?? $row['category'] ?? '',
            'contact_email' => $row['contact_email'] ?? $row['email'] ?? null,
            'phone' => $row['phone'] ?? null,
            'whatsapp' => $row['whatsapp'] ?? null,
            'website' => $row['website'] ?? $row['site'] ?? null,
            'address' => [
                'street' => $address['street'] ?? $row['street'] ?? null,
                'number' => $address['number'] ?? $row['number'] ?? null,
                'city' => $address['city'] ?? $row['city'] ?? '',
                'neighborhood' => $address['neighborhood'] ?? $row['neighborhood'] ?? null,
            ],
            'socials' => [
                'facebook' => $socials['facebook'] ?? $row['facebook'] ?? null,
                'instagram' => $socials['instagram'] ?? $row['instagram'] ?? null,
                'tiktok' => $socials['tiktok'] ?? $row['tiktok'] ?? null,
                'x' => $socials['x'] ?? $row['x'] ?? null,
                'telegram' => $socials['telegram'] ?? $row['telegram'] ?? null,
            ],
            'opening_hours' => $this->normalizedOpeningHours($row['opening_hours'] ?? []),
            'service_areas' => $this->normalizedList($row['service_areas'] ?? $row['serviceAreas'] ?? []),
            'specialties' => $this->normalizedList($row['specialties'] ?? []),
        ];

        $normalized['palette_key'] = $this->pages->automaticPalette($normalized);

        return $normalized;
    }

    private function normalizedOpeningHours(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        if (trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : $value;
    }

    private function normalizedList(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);
        if ($value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded)
            ? $decoded
            : (preg_split('/\s*,\s*/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    private function payload(AiPageImport $import): array
    {
        $pageIds = $import->created_page_ids ?? [];
        $pages = Page::query()->whereIn('id', $pageIds)->get()->keyBy('id');

        return [
            'id' => $import->id,
            'client_import_id' => $import->client_import_id,
            'status' => $import->status,
            'input_count' => $import->input_count,
            'created_count' => $import->created_count,
            'duplicate_count' => $import->duplicate_count,
            'invalid_count' => $import->invalid_count,
            'created_pages' => collect($pageIds)
                ->map(fn ($id) => $pages->has($id) ? $this->pages->summary($pages->get($id)) : null)
                ->filter()
                ->values(),
            'created_at' => $import->created_at?->toISOString(),
            'expires_at' => $import->expires_at?->toISOString(),
        ];
    }

    private function ensureOwner(Request $request, AiPageImport $import): void
    {
        abort_unless($import->created_by_user_id === $request->user()->id, 404);
    }
}
