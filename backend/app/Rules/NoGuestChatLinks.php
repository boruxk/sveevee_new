<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class NoGuestChatLinks implements ValidationRule
{
    public const ERROR = 'guest_chat_links_not_allowed';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && self::contains($value)) {
            $fail(self::ERROR);
        }
    }

    public static function contains(string $value): bool
    {
        // Keep these checks aligned with guestChatLinks.js using the shared fixtures.
        for ($i = 0; $i < 2; $i++) {
            $value = preg_replace_callback('/(?:%[a-f0-9]{2})+/i', function (array $match): string {
                $decoded = rawurldecode($match[0]);

                return mb_check_encoding($decoded, 'UTF-8') ? $decoded : $match[0];
            }, $value) ?? $value;
        }

        $value = preg_replace_callback('/[\x{FF01}-\x{FF5E}]/u', fn (array $match): string => chr(mb_ord($match[0]) - 0xFEE0), $value) ?? $value;
        $value = str_replace(["\u{3002}", "\u{FF61}"], '.', $value);
        $value = preg_replace('/\p{Cf}/u', '', $value) ?? $value;

        $scheme = '~(?:[a-z][a-z0-9+.-]{1,31}\s*:\s*[/\\\\]{2}|(?:https?|mailto|tel|sms|javascript|data)\s*:|(?<![\p{L}\p{N}_@.])www\s*\.)~iu';
        $domain = '~(?<![\p{L}\p{N}_@.-])(?:[\p{L}\p{N}](?:[\p{L}\p{N}-]{0,61}[\p{L}\p{N}])?\.)+(?:[\p{L}]{2,63}|xn--[a-z0-9-]{2,59})(?![\p{L}\p{N}_-])~iu';
        $address = '~(?<![\p{L}\p{N}_@.])(?:[0-9]{1,3}\.){3}[0-9]{1,3}(?![\p{L}\p{N}_.])|\[[a-f0-9]*:[a-f0-9:]+\]~iu';

        return preg_match($scheme, $value) === 1
            || preg_match($domain, $value) === 1
            || preg_match($address, $value) === 1;
    }
}
