<?php

declare(strict_types=1);

namespace Sveevee\Worker\Domain;

final class BusinessMerger
{
    private const OPTIONAL_SCALARS = [
        'public_description', 'contact_email', 'phone', 'whatsapp', 'website',
    ];

    public function mergeResearchData(array $current, array $incoming): array
    {
        $merged = $current;
        foreach (['name', 'public_description', 'contact_email', 'phone', 'whatsapp', 'website', 'category_key'] as $field) {
            if ($this->filled($incoming[$field] ?? null)) {
                $merged[$field] = $incoming[$field];
            }
        }
        if (isset($incoming['id'])) {
            $merged['id'] = $incoming['id'];
        }
        if (isset($incoming['source'])) {
            $merged['source'] = $incoming['source'];
        }
        $merged['type'] = 'business';
        $merged['address'] = $this->mergeMap($current['address'] ?? [], $incoming['address'] ?? []);
        $merged['socials'] = $this->mergeMap($current['socials'] ?? [], $incoming['socials'] ?? []);

        if (! empty($incoming['opening_hours'])) {
            $merged['opening_hours'] = $incoming['opening_hours'];
        }
        foreach (['service_areas' => 10, 'specialties' => 50] as $field => $maximum) {
            $list = $this->mergeList($current[$field] ?? [], $incoming[$field] ?? [], $maximum);
            if ($list !== []) {
                $merged[$field] = $list;
            }
        }

        return $this->removeEmptyOptionals($merged);
    }

    public function patchForRemote(array $candidate, array $remote): array
    {
        $patch = ['id' => (int) $remote['id']];
        if (isset($candidate['source'])) {
            $patch['source'] = $candidate['source'];
        }
        if (! $this->filled($remote['category_key'] ?? null) && $this->filled($candidate['category_key'] ?? null)) {
            $patch['category_key'] = $candidate['category_key'];
        }
        foreach (self::OPTIONAL_SCALARS as $field) {
            if (! $this->filled($remote[$field] ?? null) && $this->filled($candidate[$field] ?? null)) {
                $patch[$field] = $candidate[$field];
            }
        }

        foreach (['address', 'socials'] as $field) {
            $nested = [];
            $current = is_array($remote[$field] ?? null) ? $remote[$field] : [];
            foreach ((array) ($candidate[$field] ?? []) as $key => $value) {
                if (! $this->filled($current[$key] ?? null) && $this->filled($value)) {
                    $nested[$key] = $value;
                }
            }
            if ($nested !== []) {
                $patch[$field] = $nested;
            }
        }

        if (empty($remote['opening_hours']) && ! empty($candidate['opening_hours'])) {
            $patch['opening_hours'] = $candidate['opening_hours'];
        }
        foreach (['service_areas' => 10, 'specialties' => 50] as $field => $maximum) {
            $merged = $this->mergeList($remote[$field] ?? [], $candidate[$field] ?? [], $maximum);
            if ($merged !== array_values((array) ($remote[$field] ?? []))) {
                $patch[$field] = $merged;
            }
        }

        return $patch;
    }

    public function hasRemoteChanges(array $patch): bool
    {
        return count($patch) > 1;
    }

    private function mergeMap(mixed $current, mixed $incoming): array
    {
        $result = is_array($current) ? $current : [];
        foreach (is_array($incoming) ? $incoming : [] as $key => $value) {
            if ($this->filled($value)) {
                $result[$key] = $value;
            }
        }

        return array_filter($result, $this->filled(...));
    }

    private function mergeList(mixed $current, mixed $incoming, int $maximum): array
    {
        $result = [];
        foreach ([...(array) $current, ...(array) $incoming] as $value) {
            if (! $this->filled($value)) {
                continue;
            }
            $key = mb_strtolower(trim((string) $value), 'UTF-8');
            $result[$key] = trim((string) $value);
            if (count($result) >= $maximum) {
                break;
            }
        }

        return array_values($result);
    }

    private function removeEmptyOptionals(array $data): array
    {
        foreach (['socials', 'opening_hours', 'service_areas', 'specialties'] as $field) {
            if (isset($data[$field]) && $data[$field] === []) {
                unset($data[$field]);
            }
        }

        return $data;
    }

    private function filled(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [];
    }
}
