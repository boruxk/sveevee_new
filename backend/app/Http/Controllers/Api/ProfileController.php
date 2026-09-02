<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesUploadedImages;
use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Models\ChatEmailNotificationState;
use App\Models\EmailBan;
use App\Notifications\PasswordChangedNotification;
use App\Services\ApiResponseService;
use App\Services\EmailVerificationService;
use App\Services\PayloadService;
use App\Support\UserTypes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;

class ProfileController extends Controller
{
    use HandlesUploadedImages;

    public function __construct(
        private readonly PayloadService $payloads,
        private readonly EmailVerificationService $emailVerification,
    ) {}

    public function show(Request $request)
    {
        return ApiResponseService::success($this->payloads->profile(
            $request->user()->profile,
            $request->user(),
            includePrivate: true,
        ));
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $request->merge([
            'email' => strtolower(trim((string) $request->input('email'))),
            'user_type' => $request->filled('user_type') ? $request->input('user_type') : null,
        ]);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'given_name' => ['required', 'string', 'max:255'],
            'family_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'city' => ['nullable', 'string', 'max:120'],
            'neighborhood' => ['nullable', 'string', 'max:120'],
            'user_type' => ['nullable', 'string', Rule::in(UserTypes::KEYS)],
            'locale' => ['nullable', 'string', Rule::in(['he', 'en', 'ru', 'fr'])],
        ]);

        if (EmailBan::query()->where('email', $data['email'])->exists()) {
            return ApiResponseService::error('This email address is banned.', status: 403);
        }

        $emailChanged = $user->email !== $data['email'];

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
            'user_type' => $data['user_type'] ?? null,
        ]);
        $user->ads()
            ->whereNull('page_id')
            ->where('type', Ad::TYPE_PRIVATE)
            ->update([
                'city' => $data['city'] ?? null,
                'neighborhood' => $data['neighborhood'] ?? null,
            ]);

        if ($emailChanged) {
            $user->fresh()->sendEmailVerificationNotification();
        }

        return ApiResponseService::success(
            $this->payloads->profile($profile->fresh(), $user->fresh(), includePrivate: true),
            'Profile saved.'
        );
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
            'profile' => $this->payloads->profile($user->profile, $user, includePrivate: true),
            'user' => $this->payloads->user($user, includePrivate: true),
        ], 'Profile saved.');
    }

    public function updatePassword(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', PasswordRule::min(8)->letters()->numbers(), 'confirmed'],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            return ApiResponseService::error('The current password is incorrect.', status: 422);
        }

        $user->forceFill(['password' => $data['password']])->save();
        $user->notify(new PasswordChangedNotification);

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
            'profile' => $this->payloads->profile($profile, $request->user(), includePrivate: true),
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
            'profile' => $this->payloads->profile($user->profile, $user, includePrivate: true),
            'user' => $this->payloads->user($user, includePrivate: true),
        ], 'Photo deleted.');
    }

    public function updateEmailPreferences(Request $request)
    {
        $data = $request->validate([
            'chat_notifications' => ['required', 'boolean'],
        ]);
        $user = $request->user()->loadMissing('profile');

        if ($data['chat_notifications'] && ! $this->emailVerification->canUseEmailFeatures($user)) {
            return ApiResponseService::error(
                'A verified email address is required.',
                status: 409,
                data: ['email_verification' => $this->emailVerification->payload($user)]
            );
        }

        $profile = $user->profile()->updateOrCreate([], [
            'email_chat_notifications' => $data['chat_notifications'],
        ]);

        if (! $data['chat_notifications']) {
            ChatEmailNotificationState::query()->where('recipient_id', $user->id)->delete();
        }

        return ApiResponseService::success(
            $this->payloads->profile($profile, $user->fresh(), includePrivate: true),
            'Email preferences saved.'
        );
    }
}
