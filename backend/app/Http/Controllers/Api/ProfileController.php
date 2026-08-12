<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\HandlesUploadedImages;
use App\Models\Ad;
use App\Models\EmailBan;
use App\Notifications\PasswordChangedNotification;
use App\Services\ApiResponseService;
use App\Services\PayloadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    use HandlesUploadedImages;

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
            'locale' => ['nullable', 'string', Rule::in(['he', 'en', 'ru', 'fr'])],
        ]);

        if (EmailBan::query()->where('email', $data['email'])->exists()) {
            return ApiResponseService::error('This email address is banned.', status: 403);
        }

        $user->forceFill([
            'email' => $data['email'],
            'given_name' => $data['given_name'],
            'family_name' => $data['family_name'],
            'name' => trim($data['given_name'].' '.$data['family_name']),
            'locale' => $data['locale'] ?? $user->locale,
        ])->save();

        $profile = $user->profile()->updateOrCreate([], [
            'phone' => $data['phone'] ?? null,
            'city' => $data['city'] ?? null,
            'neighborhood' => $data['neighborhood'] ?? null,
        ]);
        $user->ads()
            ->whereNull('page_id')
            ->where('type', Ad::TYPE_PRIVATE)
            ->update([
                'city' => $data['city'] ?? null,
                'neighborhood' => $data['neighborhood'] ?? null,
            ]);

        return ApiResponseService::success($this->payloads->profile($profile, $user->fresh()), 'Profile saved.');
    }

    public function updateLocale(Request $request)
    {
        $data = $request->validate([
            'locale' => ['required', 'string', Rule::in(['he', 'en', 'ru', 'fr'])],
        ]);

        $user = $request->user();
        $user->forceFill(['locale' => $data['locale']])->save();
        $user = $user->fresh();

        return ApiResponseService::success([
            'profile' => $this->payloads->profile($user->profile, $user),
            'user' => $this->payloads->user($user, includePrivate: true),
        ], 'Profile saved.');
    }

    public function updatePassword(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:5', 'confirmed'],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            return ApiResponseService::error('The current password is incorrect.', status: 422);
        }

        $user->forceFill(['password' => $data['password']])->save();
        $user->notify(new PasswordChangedNotification());

        return ApiResponseService::success(null, 'Password changed.');
    }

    public function uploadPhoto(Request $request)
    {
        $data = $request->validate([
            'photo' => ['required', 'image', 'mimetypes:image/jpeg,image/png,image/x-png,image/webp', 'max:20480'],
        ]);

        $oldProfile = $request->user()->profile()->first();
        $path = $this->storePublicWebp($data['photo'], 'profiles', 'photo');
        $this->deletePublicUpload($oldProfile?->photo_path);

        $profile = $request->user()->profile()->updateOrCreate([], [
            'photo_path' => $path,
            'photo_original_name' => $this->originalUploadName($request, 'photo', $data['photo']),
        ]);

        return ApiResponseService::success([
            'profile' => $this->payloads->profile($profile, $request->user()),
            'user' => $this->payloads->user($request->user()->fresh(), includePrivate: true),
        ], 'Photo uploaded.');
    }

    public function destroyPhoto(Request $request)
    {
        $profile = $request->user()->profile()->first();

        if ($profile?->photo_path) {
            $this->deletePublicUpload($profile->photo_path);
            $profile->forceFill([
                'photo_path' => null,
                'photo_original_name' => null,
            ])->save();
        }

        $user = $request->user()->fresh();

        return ApiResponseService::success([
            'profile' => $this->payloads->profile($user->profile, $user),
            'user' => $this->payloads->user($user, includePrivate: true),
        ], 'Photo deleted.');
    }
}
