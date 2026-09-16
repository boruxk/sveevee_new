<?php

declare(strict_types=1);

namespace Sveevee\Worker\Research\OpenStreetMap;

/** Exact subset of OSM syntax representable by our single-interval weekly editor. */
final class WeeklyOpeningHours
{
    public static function parse(?string $expression): array
    {
        $expression = trim($expression ?? '');
        if ($expression === '') {
            return [];
        }
        $names = ['Mo' => 1, 'Tu' => 2, 'We' => 3, 'Th' => 4, 'Fr' => 5, 'Sa' => 6, 'Su' => 0];
        $apiDays = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
        $days = [];
        for ($day = 0; $day < 7; $day++) {
            $days[$day] = ['weekday' => $apiDays[$day], 'is_open' => false, 'opens_at' => null, 'closes_at' => null];
        }
        $assigned = [];
        foreach (explode(';', $expression) as $rule) {
            if (! preg_match('/^\s*(?:(Mo|Tu|We|Th|Fr|Sa|Su)(?:-(Mo|Tu|We|Th|Fr|Sa|Su))?\s+)?(off|closed|([0-2][0-9]:[0-5][0-9])-([0-2][0-9]:[0-5][0-9]))\s*$/D', $rule, $match)) {
                return [];
            }
            $start = ($match[1] ?? '') === '' ? 0 : $names[$match[1]];
            $end = ($match[1] ?? '') === '' ? 6 : $names[($match[2] ?? '') !== '' ? $match[2] : $match[1]];
            $open = ! in_array($match[3], ['off', 'closed'], true);
            $from = $open ? $match[4] : null;
            $to = $open ? $match[5] : null;
            // Overnight, 24:00, split hours, holidays and exceptions remain raw-only.
            if ($open && ($from >= '24:00' || $to >= '24:00' || $from >= $to)) {
                return [];
            }
            for ($day = $start; ; $day = ($day + 1) % 7) {
                if (isset($assigned[$day])) {
                    return [];
                }
                $assigned[$day] = true;
                $days[$day] = ['weekday' => $apiDays[$day], 'is_open' => $open, 'opens_at' => $from, 'closes_at' => $to];
                if ($day === $end) {
                    break;
                }
            }
        }

        return array_values($days);
    }
}
