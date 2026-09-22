<?php

namespace Tests\Unit;

use App\Rules\NoGuestChatLinks;
use PHPUnit\Framework\TestCase;

class NoGuestChatLinksTest extends TestCase
{
    public function test_shared_guest_link_policy_cases(): void
    {
        $cases = json_decode(file_get_contents(__DIR__.'/../Fixtures/guest-chat-links.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($cases as $case) {
            $this->assertSame($case['blocked'], NoGuestChatLinks::contains($case['body']), $case['body']);
            $errors = [];
            (new NoGuestChatLinks)->validate('body', $case['body'], function (string $error) use (&$errors): void {
                $errors[] = $error;
            });
            $this->assertSame($case['blocked'] ? [NoGuestChatLinks::ERROR] : [], $errors);
        }
    }
}
