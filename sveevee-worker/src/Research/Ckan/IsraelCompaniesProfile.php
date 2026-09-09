<?php

declare(strict_types=1);

namespace Sveevee\Worker\Research\Ckan;

use RuntimeException;
use Sveevee\Worker\Config\ResearchTarget;
use Sveevee\Worker\Support\Json;

/** Israeli Companies Authority register: registered companies, not business licenses. */
final class IsraelCompaniesProfile implements CkanScopedDatasetProfileInterface
{
    private const FIELDS = [
        '_id', 'מספר חברה', 'שם חברה', 'שם באנגלית', 'סטטוס חברה', 'קוד סטטוס חברה',
        'תאור חברה', 'מטרת החברה', 'שם עיר', 'שם רחוב', 'מספר בית',
    ];

    public function name(): string
    {
        return 'israel_companies';
    }

    public function supports(array $dataset, ResearchTarget $target): bool
    {
        return $target->neighborhood === null
            && $this->cityNames($dataset, $target) !== []
            && in_array($target->categoryKey, CompanyCategoryClassifier::CATEGORY_KEYS, true);
    }

    public function searchParameters(array $dataset, ResearchTarget $target): array
    {
        $cityNames = $this->cityNames($dataset, $target);
        if ($cityNames === []) {
            throw new RuntimeException('The companies register requires an explicit registry city mapping.');
        }

        return [
            'filters' => Json::encode(['שם עיר' => $cityNames, 'סטטוס חברה' => 'פעילה']),
            'fields' => implode(',', self::FIELDS),
            'sort' => 'מספר חברה asc, _id asc',
        ];
    }

    public function recordId(array $record): ?string
    {
        $value = $record['מספר חברה'] ?? null;
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }
        $number = trim((string) $value);

        return preg_match('/^[1-9][0-9]{8}$/D', $number) === 1 ? $number : null;
    }

    public function recordFilters(string $recordId): array
    {
        return ['מספר חברה' => (int) $recordId];
    }

    public function keepRecord(array $record, array $dataset, ResearchTarget $target): bool
    {
        // Do not silently turn a renamed/removed source field into an empty city.
        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $record)) {
                throw new RuntimeException('The companies register record is missing field '.$field.'.');
            }
        }

        return $this->isEligible($record, $dataset, $target) && $this->category($record) !== null;
    }

    public function map(
        array $record,
        array $dataset,
        ResearchTarget $target,
        string $sourceUrl,
        string $checkedAt,
    ): ?array {
        if (! $this->supports($dataset, $target) || ! $this->isEligible($record, $dataset, $target)) {
            return null;
        }
        $category = $this->category($record);
        if ($category === null || $category !== $target->categoryKey) {
            return null;
        }

        $name = $this->text($record['שם חברה']);
        $cityLabel = $this->text($record['שם עיר']);
        $number = $this->text($record['מספר בית']);
        if ($number === '0' || $number === '-') {
            $number = null;
        }

        return [
            'type' => 'business',
            'name' => $name,
            'public_description' => "{$name} — חברה רשומה ב{$cityLabel}. הכתובת היא כתובת החברה במרשם החברות.",
            'category_key' => $category,
            'address' => array_filter([
                'street' => $this->text($record['שם רחוב']),
                'number' => $number,
                'city' => $target->city,
            ], static fn ($value): bool => $value !== null),
            'source_name' => trim((string) ($dataset['source_name'] ?? $this->name())),
            'source_url' => $sourceUrl,
            'source_checked_at' => $checkedAt,
        ];
    }

    private function isEligible(array $record, array $dataset, ResearchTarget $target): bool
    {
        return $this->recordId($record) !== null
            && $this->text($record['סטטוס חברה'] ?? null) === 'פעילה'
            && isset($record['קוד סטטוס חברה'])
            && (string) $record['קוד סטטוס חברה'] === '0'
            && $this->text($record['שם חברה'] ?? null) !== null
            && $this->text($record['שם רחוב'] ?? null) !== null
            && in_array($this->text($record['שם עיר'] ?? null), array_map($this->text(...), $this->cityNames($dataset, $target)), true);
    }

    private function category(array $record): ?string
    {
        return CompanyCategoryClassifier::classify(
            $this->text($record['שם חברה'] ?? null) ?? '',
            $this->text($record['שם באנגלית'] ?? null) ?? '',
            $this->text($record['מטרת החברה'] ?? null) ?? '',
            $this->text($record['תאור חברה'] ?? null) ?? '',
        );
    }

    private function cityNames(array $dataset, ResearchTarget $target): array
    {
        $mapping = $dataset['city_names'] ?? [];
        if (! is_array($mapping)) {
            return [];
        }
        foreach ($mapping as $canonical => $names) {
            if (strcasecmp((string) $canonical, $target->city) !== 0) {
                continue;
            }

            return array_values(array_unique(array_filter(array_map(
                static fn ($name): ?string => is_string($name) && trim($name) !== '' ? trim($name) : null,
                (array) $names,
            ), static fn ($name): bool => $name !== null)));
        }

        return [];
    }

    private function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return $value === '' ? null : $value;
    }
}
