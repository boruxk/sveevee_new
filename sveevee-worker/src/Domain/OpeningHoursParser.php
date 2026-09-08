<?php

declare(strict_types=1);

namespace Sveevee\Worker\Domain;

final class OpeningHoursParser
{
    private const API_DAYS = [
        'Su' => 'sunday',
        'Mo' => 'monday',
        'Tu' => 'tuesday',
        'We' => 'wednesday',
        'Th' => 'thursday',
        'Fr' => 'friday',
        'Sa' => 'saturday',
    ];

    public function parse(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }
        if ($value === '24/7') {
            return array_map(
                fn (string $day): array => $this->row($day, true, '00:00', '23:59'),
                self::API_DAYS
            );
        }

        $rows = [];
        foreach (self::API_DAYS as $day) {
            $rows[$day] = $this->row($day, false, null, null);
        }

        $parsedAny = false;
        foreach (explode(';', $value) as $clause) {
            $clause = trim($clause);
            if ($clause === '') {
                continue;
            }
            if (! preg_match('/^([A-Z][a-z](?:-[A-Z][a-z])?(?:,[A-Z][a-z](?:-[A-Z][a-z])?)*)\s+(off|closed|\d{2}:\d{2}-\d{2}:\d{2})$/', $clause, $match)) {
                continue;
            }

            $days = $this->expandDays($match[1]);
            if ($days === []) {
                continue;
            }
            $closed = in_array($match[2], ['off', 'closed'], true);
            [$opens, $closes] = $closed ? [null, null] : explode('-', $match[2], 2);
            foreach ($days as $day) {
                $rows[$day] = $this->row($day, ! $closed, $opens, $closes);
            }
            $parsedAny = true;
        }

        return $parsedAny ? array_values($rows) : [];
    }

    public function normalize(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $weekday = strtolower(trim((string) ($row['weekday'] ?? '')));
            if (! in_array($weekday, self::API_DAYS, true) || isset($normalized[$weekday])) {
                continue;
            }
            $isOpen = filter_var($row['is_open'] ?? false, FILTER_VALIDATE_BOOL);
            $opens = $this->time($row['opens_at'] ?? null);
            $closes = $this->time($row['closes_at'] ?? null);
            if ($isOpen && ($opens === null || $closes === null)) {
                continue;
            }
            $normalized[$weekday] = $this->row($weekday, $isOpen, $isOpen ? $opens : null, $isOpen ? $closes : null);
        }

        return array_values($normalized);
    }

    private function expandDays(string $input): array
    {
        $keys = array_keys(self::API_DAYS);
        $days = [];
        foreach (explode(',', $input) as $part) {
            if (! str_contains($part, '-')) {
                if (isset(self::API_DAYS[$part])) {
                    $days[] = self::API_DAYS[$part];
                }
                continue;
            }

            [$from, $to] = explode('-', $part, 2);
            $fromIndex = array_search($from, $keys, true);
            $toIndex = array_search($to, $keys, true);
            if ($fromIndex === false || $toIndex === false) {
                continue;
            }
            $index = $fromIndex;
            do {
                $days[] = self::API_DAYS[$keys[$index]];
                $index = ($index + 1) % count($keys);
            } while ($index !== (($toIndex + 1) % count($keys)));
        }

        return array_values(array_unique($days));
    }

    private function time(mixed $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) === 1 ? $value : null;
    }

    private function row(string $weekday, bool $isOpen, ?string $opens, ?string $closes): array
    {
        return [
            'weekday' => $weekday,
            'is_open' => $isOpen,
            'opens_at' => $opens,
            'closes_at' => $closes,
        ];
    }
}

