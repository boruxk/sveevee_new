<?php

declare(strict_types=1);

namespace Sveevee\Worker\Support;

final class SourceFingerprint
{
    public static function hash(array $raw): string
    {
        // Audit metadata can change between releases without changing the business itself.
        unset($raw['source_checked_at'], $raw['source_metadata']);

        return Json::hash($raw);
    }
}
