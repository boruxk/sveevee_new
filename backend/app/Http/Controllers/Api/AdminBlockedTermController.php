<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BlockedTerm;
use App\Services\ApiResponseService;
use App\Services\BlockedTermService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminBlockedTermController extends Controller
{
    public function index()
    {
        return ApiResponseService::success([
            'items' => BlockedTerm::query()->orderBy('locale')->orderBy('term')->get(),
        ]);
    }

    public function store(Request $request, BlockedTermService $terms)
    {
        $data = $request->validate($this->rules());
        $normalized = $terms->normalize($data['term']);
        $this->ensureUnique($normalized, $data['locale']);

        $term = BlockedTerm::query()->create([
            ...$data,
            'normalized_term' => $normalized,
            'created_by_user_id' => $request->user()?->id,
            'updated_by_user_id' => $request->user()?->id,
        ]);
        $terms->clearCache();

        return ApiResponseService::success($term, 'Blocked term created.', 201);
    }

    public function update(Request $request, BlockedTerm $blockedTerm, BlockedTermService $terms)
    {
        $data = $request->validate($this->rules());
        $normalized = $terms->normalize($data['term']);
        $this->ensureUnique($normalized, $data['locale'], $blockedTerm->id);

        $blockedTerm->update([
            ...$data,
            'normalized_term' => $normalized,
            'updated_by_user_id' => $request->user()?->id,
        ]);
        $terms->clearCache();

        return ApiResponseService::success($blockedTerm->fresh(), 'Blocked term saved.');
    }

    public function destroy(BlockedTerm $blockedTerm, BlockedTermService $terms)
    {
        $blockedTerm->delete();
        $terms->clearCache();

        return ApiResponseService::success(null, 'Blocked term deleted.');
    }

    private function rules(): array
    {
        return [
            'term' => ['required', 'string', 'max:200'],
            'locale' => ['required', 'string', Rule::in(['all', 'he', 'en', 'ru', 'fr'])],
            'active' => ['required', 'boolean'],
        ];
    }

    private function ensureUnique(string $normalized, string $locale, ?int $ignoreId = null): void
    {
        if ($normalized === '') {
            throw ValidationException::withMessages(['term' => ['Enter a word or phrase.']]);
        }

        $exists = BlockedTerm::query()
            ->where('normalized_term', $normalized)
            ->where('locale', $locale)
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['term' => ['This word or phrase already exists.']]);
        }
    }
}
