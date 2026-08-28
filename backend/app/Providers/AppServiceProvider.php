<?php

namespace App\Providers;

use App\Models\User;
use App\Services\SystemSettingsService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
    }
}
