<?php

declare(strict_types=1);

namespace Sveevee\Worker\Support;

final class SourceFingerprint
{
    public static function hash(array $raw): string
    {
        unset($raw['source_checked_at']);

        return Json::hash($raw);
    }
}
