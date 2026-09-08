<?php

declare(strict_types=1);

namespace Sveevee\Worker\Research;

final class RobotsRules
{
    public function __construct(private readonly string $body) {}

    public function allows(string $userAgent, string $path): bool
    {
        $groups = $this->groups();
        $specific = [];
        $fallback = [];
        $agent = strtolower($userAgent);

        foreach ($groups as $group) {
            foreach ($group['agents'] as $candidate) {
                $candidate = strtolower($candidate);
                if ($candidate === '*') {
                    $fallback = [...$fallback, ...$group['rules']];
                } elseif ($candidate !== '' && str_contains($agent, $candidate)) {
                    $specific = [...$specific, ...$group['rules']];
                }
            }
        }
        $rules = $specific !== [] ? $specific : $fallback;
        $bestLength = -1;
        $allowed = true;
        foreach ($rules as $rule) {
            if ($rule['path'] === '') {
                continue;
            }
            if (! $this->matches($rule['path'], $path)) {
                continue;
            }
            $length = strlen(str_replace(['*', '$'], '', $rule['path']));
            if ($length > $bestLength || ($length === $bestLength && $rule['allow'])) {
                $bestLength = $length;
                $allowed = $rule['allow'];
            }
        }

        return $allowed;
    }

    public function crawlDelay(string $userAgent): ?float
    {
        $agent = strtolower($userAgent);
        $fallback = null;
        foreach ($this->groups() as $group) {
            foreach ($group['agents'] as $candidate) {
                $candidate = strtolower($candidate);
                if ($candidate !== '*' && $candidate !== '' && str_contains($agent, $candidate)) {
                    return $group['crawl_delay'];
                }
                if ($candidate === '*' && $group['crawl_delay'] !== null) {
                    $fallback = $group['crawl_delay'];
                }
            }
        }

        return $fallback;
    }

    private function groups(): array
    {
        $groups = [];
        $agents = [];
        $rules = [];
        $crawlDelay = null;
        $hasRules = false;

        $flush = static function () use (&$groups, &$agents, &$rules, &$crawlDelay, &$hasRules): void {
            if ($agents !== []) {
                $groups[] = ['agents' => $agents, 'rules' => $rules, 'crawl_delay' => $crawlDelay];
            }
            $agents = [];
            $rules = [];
            $crawlDelay = null;
            $hasRules = false;
        };

        foreach (preg_split('/\R/u', $this->body) ?: [] as $line) {
            $line = trim((string) preg_replace('/\s*#.*$/', '', $line));
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);
            if ($field === 'user-agent') {
                if ($hasRules) {
                    $flush();
                }
                $agents[] = $value;
                continue;
            }
            if ($agents === []) {
                continue;
            }
            if ($field === 'allow' || $field === 'disallow') {
                $rules[] = ['allow' => $field === 'allow', 'path' => $value];
                $hasRules = true;
            } elseif ($field === 'crawl-delay' && is_numeric($value)) {
                $crawlDelay = max(0.0, (float) $value);
                $hasRules = true;
            }
        }
        $flush();

        return $groups;
    }

    private function matches(string $rule, string $path): bool
    {
        $anchored = str_ends_with($rule, '$');
        if ($anchored) {
            $rule = substr($rule, 0, -1);
        }
        $pattern = str_replace('\\*', '.*', preg_quote($rule, '~'));

        return preg_match('~^'.$pattern.($anchored ? '$' : '').'~u', $path) === 1;
    }
}

