<?php

namespace App\Services;

use App\Models\BusinessPageLead;
use App\Models\Page;
use App\Models\PageClaimRequest;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use JsonException;

class BusinessPageLeadRegistrationService
{
    private const TOKEN_VERSION = 1;

    private const TOKEN_LIFETIME_HOURS = 24;

    public function issue(BusinessPageLead $lead): ?string
    {
        if (
            ! $lead->created_page
            || ! $lead->page_id
            || $lead->source !== BusinessPageLead::SOURCE_LEADS_PAGE_001
        ) {
            return null;
        }

        return Crypt::encryptString(json_encode([
            'version' => self::TOKEN_VERSION,
            'lead_id' => $lead->id,
            'page_id' => $lead->page_id,
            'email' => $this->normalizeEmail($lead->email),
            'expires_at' => now()->addHours(self::TOKEN_LIFETIME_HOURS)->timestamp,
        ], JSON_THROW_ON_ERROR));
    }

    public function attach(User $user, string $token): ?Page
    {
        $payload = $this->decode($token);

        if (! $payload || ! $user->hasRole('user')) {
            return null;
        }

        return DB::transaction(function () use ($payload, $user): ?Page {
            $lead = BusinessPageLead::query()
                ->lockForUpdate()
                ->find($payload['lead_id']);

            if (
                ! $lead
                || (int) $lead->page_id !== $payload['page_id']
                || ! $lead->created_page
                || $lead->source !== BusinessPageLead::SOURCE_LEADS_PAGE_001
                || ! hash_equals($payload['email'], $this->normalizeEmail($lead->email))
            ) {
                return null;
            }

            $page = Page::query()->lockForUpdate()->find($lead->page_id);

            if ($lead->status === BusinessPageLead::STATUS_CONVERTED) {
                return $page
                    && ! $page->is_unclaimed
                    && (int) $page->user_id === (int) $user->id
                        ? $page
                        : null;
            }

            if (
                $lead->status !== BusinessPageLead::STATUS_NEW
                || ! $page
                || $page->type !== Page::TYPE_BUSINESS
                || ! $page->is_unclaimed
                || PageClaimRequest::query()
                    ->where('page_id', $page->id)
                    ->where('status', PageClaimRequest::STATUS_PENDING)
                    ->exists()
            ) {
                return null;
            }

            $existingBusinessPage = Page::query()
                ->where('user_id', $user->id)
                ->where('type', Page::TYPE_BUSINESS)
                ->where('is_unclaimed', false)
                ->whereKeyNot($page->id)
                ->lockForUpdate()
                ->first();

            if ($existingBusinessPage) {
                return null;
            }

            $page->forceFill([
                'user_id' => $user->id,
                'is_unclaimed' => false,
                'claimed_at' => now(),
            ])->save();
            $page->ads()->update(['user_id' => $user->id]);

            $profile = $user->profile()->lockForUpdate()->first()
                ?: $user->profile()->create([]);
            $profile->forceFill([
                'city' => $profile->city ?: $lead->city,
                'phone' => $profile->phone ?: $lead->phone,
            ])->save();

            $lead->forceFill(['status' => BusinessPageLead::STATUS_CONVERTED])->save();

            return $page->fresh();
        });
    }

    private function decode(string $token): ?array
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return null;
        }

        $leadId = filter_var($payload['lead_id'] ?? null, FILTER_VALIDATE_INT);
        $pageId = filter_var($payload['page_id'] ?? null, FILTER_VALIDATE_INT);
        $expiresAt = filter_var($payload['expires_at'] ?? null, FILTER_VALIDATE_INT);
        $email = $this->normalizeEmail($payload['email'] ?? null);

        if (
            ($payload['version'] ?? null) !== self::TOKEN_VERSION
            || ! $leadId
            || ! $pageId
            || ! $expiresAt
            || $expiresAt <= now()->timestamp
            || $email === ''
        ) {
            return null;
        }

        return [
            'lead_id' => $leadId,
            'page_id' => $pageId,
            'email' => $email,
        ];
    }

    private function normalizeEmail(mixed $email): string
    {
        return mb_strtolower(trim((string) $email));
    }
}
