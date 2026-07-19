<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailBan;
use App\Services\ApiResponseService;
use App\Services\PayloadService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function __construct(private readonly PayloadService $payloads)
    {
    }

    public function show(Request $request)
    {
        return ApiResponseService::success($this->payloads->profile(
            $request->user()->profile,
            $request->user()
        ));
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'given_name' => ['required', 'string', 'max:255'],
            'family_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'city' => ['nullable', 'string', 'max:120'],
            'neighborhood' => ['nullable', 'string', 'max:120'],
            'languages' => ['nullable', 'array'],
            'languages.*' => ['string', Rule::in(['he', 'en', 'ru', 'fr'])],
        ]);

        if (EmailBan::query()->where('email', $data['email'])->exists()) {
            return ApiResponseService::error('This email address is banned.', status: 403);
        }

        $user->forceFill([
            'email' => $data['email'],
            'given_name' => $data['given_name'],
            'family_name' => $data['family_name'],
            'name' => trim($data['given_name'].' '.$data['family_name']),
            'locale' => $data['languages'][0] ?? $user->locale,
        ])->save();

        $profile = $user->profile()->updateOrCreate([], [
            'phone' => $data['phone'] ?? null,
            'city' => $data['city'] ?? null,
            'neighborhood' => $data['neighborhood'] ?? null,
            'languages' => $data['languages'] ?? [$user->locale],
        ]);

        return ApiResponseService::success($this->payloads->profile($profile, $user->fresh()), 'Profile saved.');
    }

    public function uploadPhoto(Request $request)
    {
        $data = $request->validate([
            'photo' => ['required', 'image', 'max:4096'],
        ]);

        $path = $data['photo']->store('profiles', 'public');
        $profile = $request->user()->profile()->updateOrCreate([], ['photo_path' => $path]);

        return ApiResponseService::success([
            'profile' => $this->payloads->profile($profile, $request->user()),
            'user' => $this->payloads->user($request->user()->fresh(), includePrivate: true),
        ], 'Photo uploaded.');
    }
}
