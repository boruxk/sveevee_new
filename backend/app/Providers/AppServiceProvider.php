<?php

namespace App\Providers;

use App\Models\BusinessImportClient;
use App\Models\ChatMessage;
use App\Models\Page;
use App\Models\User;
use App\Observers\ChatMessageObserver;
use App\Observers\PageObserver;
use App\Services\SystemSettingsService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Page::observe(PageObserver::class);
        ChatMessage::observe(ChatMessageObserver::class);

        Passport::tokensCan([
            BusinessImportClient::SCOPE_READ => 'Search businesses and check duplicates.',
            BusinessImportClient::SCOPE_WRITE => 'Create and update unclaimed business pages.',
        ]);
        Passport::clientCredentialsTokensExpireIn(
            now()->addMinutes(max(5, (int) config('business_import.token_ttl_minutes', 60)))
        );

        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            $frontendUrl = rtrim((string) config('app.frontend_url'), '/');

            return $frontendUrl.'/reset-password/'.$token.'?'.http_build_query([
                'email' => $user->getEmailForPasswordReset(),
            ]);
        });

        RateLimiter::for('auth-login', function (Request $request) {
            return Limit::perMinute(5)->by(
                strtolower((string) $request->input('email')).'|'.$request->ip()
            );
        });

        RateLimiter::for('auth-ai-worker', function (Request $request) {
            return [
                Limit::perMinute(5)->by('ai-worker-login-minute|'.$request->ip()),
                Limit::perHour(30)->by('ai-worker-login-hour|'.$request->ip()),
            ];
        });

        RateLimiter::for('auth-register', function (Request $request) {
            return Limit::perMinute(3)->by(
                strtolower((string) $request->input('email')).'|'.$request->ip()
            );
        });

        RateLimiter::for('chat-send', function (Request $request) {
            $limit = app(SystemSettingsService::class)->integer('chat.messages_per_minute', 30);
            $guestToken = (string) $request->header('X-Guest-Support-Token', '');
            $actor = $request->user()?->id
                ? 'user:'.$request->user()->id
                : 'guest:'.($guestToken !== '' ? hash('sha256', $guestToken) : 'anonymous');

            return Limit::perMinute($limit)->by(
                $actor.'|'.$request->ip()
            );
        });

        RateLimiter::for('guest-support-start', function (Request $request) {
            $browser = substr(hash('sha256', (string) $request->userAgent()), 0, 20);

            return Limit::perHour(10)->by($request->ip().'|'.$browser);
        });

        RateLimiter::for('business-page-leads', function (Request $request) {
            $email = mb_strtolower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(3)->by('business-lead-minute|'.$request->ip()),
                Limit::perDay(20)->by('business-lead-day|'.$request->ip()),
                Limit::perDay(5)->by('business-lead-email|'.$email),
            ];
        });

        RateLimiter::for('business-import-api', function (Request $request) {
            $clientId = (string) $request->attributes->get('oauth_client_id', 'unknown');
            $key = $clientId.'|'.$request->ip();

            return [
                Limit::perMinute(max(1, (int) config('business_import.requests_per_minute', 480)))
                    ->by('business-import-minute|'.$key),
                Limit::perHour(max(1, (int) config('business_import.requests_per_hour', 28800)))
                    ->by('business-import-hour|'.$key),
            ];
        });
    }
}
