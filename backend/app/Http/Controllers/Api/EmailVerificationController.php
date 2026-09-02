<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\EmailVerificationService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function __construct(private readonly EmailVerificationService $verification) {}

    public function send(Request $request)
    {
        $user = $request->user();
        $status = $this->verification->status($user);

        if ($status === EmailVerificationService::STATUS_BOUNCED) {
            return ApiResponseService::error(
                'This email address cannot receive messages.',
                status: 409,
                data: ['email_verification' => $this->verification->payload($user)]
            );
        }

        if ($status === EmailVerificationService::STATUS_VERIFIED) {
            return ApiResponseService::success([
                'email_verification' => $this->verification->payload($user),
            ], 'Email address is already verified.');
        }

        $user->sendEmailVerificationNotification();

        return ApiResponseService::success([
            'email_verification' => $this->verification->payload($user),
        ], 'Verification email sent.');
    }

    public function verify(Request $request, int $id, string $hash): RedirectResponse
    {
        if (! $request->hasValidSignature(absolute: false)) {
            return $this->profileRedirect('invalid');
        }

        $user = User::query()->find($id);

        if (! $user || ! hash_equals(sha1($this->verification->normalize($user->email)), $hash)) {
            return $this->profileRedirect('invalid');
        }

        if ($this->verification->status($user) === EmailVerificationService::STATUS_BOUNCED) {
            return $this->profileRedirect('bounced');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        return $this->profileRedirect('verified');
    }

    private function profileRedirect(string $status): RedirectResponse
    {
        $url = rtrim((string) config('app.frontend_url'), '/')
            .'/profile?'.http_build_query(['emailVerification' => $status]);

        return redirect()->away($url);
    }
}
