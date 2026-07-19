<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailBan;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\PayloadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function __construct(private readonly PayloadService $payloads)
    {
    }

    public function register(Request $request)
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:5', 'confirmed'],
            'given_name' => ['required', 'string', 'max:255'],
            'family_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'city' => ['nullable', 'string', 'max:120'],
            'neighborhood' => ['nullable', 'string', 'max:120'],
            'languages' => ['nullable', 'array'],
            'languages.*' => ['string', Rule::in(['he', 'en', 'ru', 'fr'])],
            'locale' => ['nullable', 'string', Rule::in(['he', 'en', 'ru', 'fr'])],
        ]);

        if (EmailBan::query()->where('email', $data['email'])->exists()) {
            return ApiResponseService::error('This email address is banned.', status: 403);
        }

        $user = User::query()->create([
            'name' => trim($data['given_name'].' '.$data['family_name']),
            'given_name' => $data['given_name'],
            'family_name' => $data['family_name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'locale' => $data['locale'] ?? 'he',
            'role' => 'user',
        ]);

        $user->profile()->updateOrCreate([], [
            'phone' => $data['phone'] ?? null,
            'city' => $data['city'] ?? null,
            'neighborhood' => $data['neighborhood'] ?? null,
            'languages' => $data['languages'] ?? [$data['locale'] ?? 'he'],
        ]);

        return $this->authenticated($user, 'Account created.', 201);
    }

    public function login(Request $request)
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (EmailBan::query()->where('email', $data['email'])->exists()) {
            return ApiResponseService::error('This email address is banned.', status: 403);
        }

        $user = User::query()->where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return ApiResponseService::error('The email or password is incorrect.', status: 422);
        }

        if ($user->banned_at) {
            return ApiResponseService::error('This account is banned.', status: 403);
        }

        return $this->authenticated($user, 'Logged in.');
    }

    public function me(Request $request)
    {
        return ApiResponseService::success($this->payloads->user($request->user(), includePrivate: true));
    }

    public function logout(Request $request)
    {
        $request->user()?->currentAccessToken()?->delete();

        return ApiResponseService::success(null, 'Logged out.');
    }

    private function authenticated(User $user, string $message, int $status = 200)
    {
        $token = $user->createToken('sveevee-api')->plainTextToken;

        return ApiResponseService::success([
            'token' => $token,
            'user' => $this->payloads->user($user->fresh(), includePrivate: true),
        ], $message, $status);
    }
}
