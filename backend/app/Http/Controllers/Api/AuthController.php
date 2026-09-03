<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailBan;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\PayloadService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Throwable;

class AuthController extends Controller
{
    public function __construct(private readonly PayloadService $payloads) {}

    public function register(Request $request)
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', PasswordRule::min(8)->letters()->numbers(), 'confirmed'],
            'given_name' => ['required', 'string', 'max:255'],
            'family_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'city' => ['nullable', 'string', 'max:120'],
            'neighborhood' => ['nullable', 'string', 'max:120'],
            'locale' => ['nullable', 'string', Rule::in(['he', 'en', 'ru', 'fr'])],
            'consented' => ['required', 'accepted'],
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
            'consented' => true,
            'role' => 'user',
        ]);

        $user->profile()->updateOrCreate([], [
            'phone' => $data['phone'] ?? null,
            'city' => $data['city'] ?? null,
            'neighborhood' => $data['neighborhood'] ?? null,
        ]);
        $user->sendEmailVerificationNotification();

        return $this->authenticated($user, 'Account created.', 201);
    }

    public function login(Request $request)
    {
        $user = $this->userForLogin($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        return $this->authenticated($user, 'Logged in.');
    }

    public function aiLogin(Request $request)
    {
        $user = $this->userForLogin($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        if (! $user->hasRole('ai_worker')) {
            return ApiResponseService::error('The login or password is incorrect.', status: 422);
        }

        return $this->authenticated($user, 'AI Works login successful.');
    }

    private function userForLogin(Request $request): User|JsonResponse
    {
        $identifier = trim((string) $request->input('email'));
        $normalizedEmail = strtolower($identifier);
        $request->merge(['email' => $identifier]);

        $data = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        if (filter_var($identifier, FILTER_VALIDATE_EMAIL) && EmailBan::query()->where('email', $normalizedEmail)->exists()) {
            return ApiResponseService::error('This email address is banned.', status: 403);
        }

        $user = User::query()
            ->where('email', $normalizedEmail)
            ->when(Schema::hasColumn('users', 'login'), fn ($query) => $query->orWhere('login', $data['email']))
            ->first();

        if (! $user || ! filled($user->password) || ! Hash::check($data['password'], $user->password)) {
            return ApiResponseService::error('The email or password is incorrect.', status: 422);
        }

        if (EmailBan::query()->where('email', $user->email)->exists()) {
            return ApiResponseService::error('This email address is banned.', status: 403);
        }

        if ($user->banned_at) {
            return ApiResponseService::error('This account is banned.', status: 403);
        }

        return $user;
    }

    public function redirectToGoogle()
    {
        return $this->googleProvider()
            ->scopes(['openid', 'profile', 'email'])
            ->stateless()
            ->redirect();
    }

    public function handleGoogleCallback()
    {
        try {
            $googleUser = $this->googleProvider()->stateless()->user();
        } catch (Throwable) {
            return redirect()->away($this->frontendGoogleCallbackUrl(['error' => 'google_login_failed']));
        }

        $googleId = trim((string) $googleUser->getId());
        $email = strtolower(trim((string) $googleUser->getEmail()));

        if ($googleId === '' || $email === '') {
            return redirect()->away($this->frontendGoogleCallbackUrl(['error' => 'google_missing_email']));
        }

        if (EmailBan::query()->where('email', $email)->exists()) {
            return redirect()->away($this->frontendGoogleCallbackUrl(['error' => 'email_banned']));
        }

        $user = User::query()->where('google_id', $googleId)->first()
            ?: User::query()->where('email', $email)->first();

        if ($user?->banned_at) {
            return redirect()->away($this->frontendGoogleCallbackUrl(['error' => 'account_banned']));
        }

        $names = $this->googleNames($googleUser, $email);

        if ($user) {
            $updates = [
                'google_id' => $googleId,
                'email_verified_at' => $user->email_verified_at ?: now(),
            ];

            if (! filled($user->given_name) && filled($names['given_name'])) {
                $updates['given_name'] = $names['given_name'];
            }

            if (! filled($user->family_name) && filled($names['family_name'])) {
                $updates['family_name'] = $names['family_name'];
            }

            if (! filled($user->name) && filled($names['name'])) {
                $updates['name'] = $names['name'];
            }

            $user->forceFill($updates)->save();
        } else {
            $user = User::query()->create([
                'name' => $names['name'],
                'given_name' => $names['given_name'],
                'family_name' => $names['family_name'],
                'email' => $email,
                'email_verified_at' => now(),
                'google_id' => $googleId,
                'password' => null,
                'locale' => 'he',
                'role' => 'user',
            ]);
        }

        $user->profile()->firstOrCreate([]);

        return redirect()->away($this->frontendGoogleCallbackUrl(fragment: [
            'token' => $this->createAuthToken($user),
        ]));
    }

    public function forgotPassword(Request $request)
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $status = Password::sendResetLink(['email' => $data['email']]);

        if ($status === Password::RESET_THROTTLED) {
            return ApiResponseService::error('Please wait before requesting another password reset email.', status: 429);
        }

        return ApiResponseService::success(null, 'If an account exists for this email, a reset link has been sent.');
    }

    public function resetPassword(Request $request)
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', PasswordRule::min(8)->letters()->numbers(), 'confirmed'],
        ]);

        $status = Password::reset($data, function (User $user, string $password): void {
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();

            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            return ApiResponseService::error('The reset link is invalid or has expired.', status: 422);
        }

        return ApiResponseService::success(null, 'Password has been reset.');
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
        $user->forceFill(['last_seen_at' => now()])->saveQuietly();

        return ApiResponseService::success([
            'token' => $this->createAuthToken($user),
            'user' => $this->payloads->user($user->fresh(), includePrivate: true),
        ], $message, $status);
    }

    private function createAuthToken(User $user): string
    {
        return $user->createToken('sveevee-api')->plainTextToken;
    }

    private function googleProvider(): AbstractProvider
    {
        /** @var AbstractProvider $provider */
        $provider = Socialite::driver('google');

        return $provider;
    }

    private function googleNames(SocialiteUser $googleUser, string $email): array
    {
        $raw = is_array($googleUser->user ?? null) ? $googleUser->user : [];
        $givenName = trim((string) ($raw['given_name'] ?? ''));
        $familyName = trim((string) ($raw['family_name'] ?? ''));
        $fullName = trim((string) ($googleUser->getName() ?: ''));

        if ((! filled($givenName) || ! filled($familyName)) && filled($fullName)) {
            $parts = preg_split('/\s+/', $fullName, 2);

            if (! filled($givenName)) {
                $givenName = trim((string) ($parts[0] ?? ''));
            }

            if (! filled($familyName)) {
                $familyName = trim((string) ($parts[1] ?? ''));
            }
        }

        $displayName = trim($givenName.' '.$familyName);

        if (! filled($displayName)) {
            $displayName = $fullName ?: Str::before($email, '@');
        }

        return [
            'given_name' => filled($givenName) ? $givenName : null,
            'family_name' => filled($familyName) ? $familyName : null,
            'name' => $displayName,
        ];
    }

    private function frontendGoogleCallbackUrl(array $query = [], array $fragment = []): string
    {
        $url = rtrim((string) config('app.frontend_url'), '/').'/auth/google/callback';

        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        if ($fragment !== []) {
            $url .= '#'.http_build_query($fragment);
        }

        return $url;
    }
}
