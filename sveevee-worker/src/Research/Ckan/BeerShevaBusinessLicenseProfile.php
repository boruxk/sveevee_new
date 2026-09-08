<?php

declare(strict_types=1);

namespace Sveevee\Worker\Research\Ckan;

use DateTimeImmutable;
use Sveevee\Worker\Config\ResearchTarget;

final class BeerShevaBusinessLicenseProfile implements CkanDatasetProfileInterface
{
    private const CATEGORY_LABELS = [
        'food_catering.restaurants' => 'מסעדה',
        'food_catering.cafes' => 'בית קפה',
        'food_catering.bakery' => 'מאפייה',
        'professionals.catering' => 'שירותי קייטרינג',
        'professionals.fast_food' => 'מזון מהיר',
        'professionals.grocery_food' => 'חנות מזון',
        'food_catering.meat_deli' => 'מעדנייה',
        'food_catering.bars' => 'בר',
        'professionals.venues' => 'מקום לאירועים',
        'travel_leisure.hotels_guesthouses' => 'מלון או מקום אירוח',
    ];

    public function name(): string
    {
        return 'beer_sheva_business_licenses';
    }

    public function supports(array $dataset, ResearchTarget $target): bool
    {
        $city = trim((string) ($dataset['city'] ?? 'Beersheba'));
        $neighborhood = $this->text($dataset['neighborhood'] ?? null);

        return strcasecmp($target->city, $city) === 0
            && ($target->neighborhood === null || $target->neighborhood === $neighborhood)
            && isset(self::CATEGORY_LABELS[$target->categoryKey]);
    }

    public function recordId(array $record): ?string
    {
        $value = $record['_id'] ?? null;
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (string) $value;
        }

        return null;
    }

    public function map(
        array $record,
        array $dataset,
        ResearchTarget $target,
        string $sourceUrl,
        string $checkedAt,
    ): ?array {
        if (! $this->isActive($record, $dataset)) {
            return null;
        }

        $name = $this->text($record['שם עסק'] ?? null);
        $street = $this->text($record['שם רחוב'] ?? null);
        $number = $this->addressNumber($record['בית'] ?? null);
        if ($name === null) {
            return null;
        }

        $license = $this->text($record['תאור רישיון'] ?? $record['תיאור רישיון'] ?? null) ?? '';
        $category = $this->category($name, $license);
        if ($category !== $target->categoryKey) {
            return null;
        }

        $city = trim((string) ($dataset['city'] ?? 'Beersheba'));
        $cityLabel = trim((string) ($dataset['city_label'] ?? 'באר שבע'));
        $label = self::CATEGORY_LABELS[$category];
        $business = [
            'type' => 'business',
            'name' => $name,
            'public_description' => "{$name} - {$label} ב{$cityLabel}.",
            'category_key' => $category,
            'address' => array_filter([
                'street' => $street,
                'number' => $number,
                'city' => $city,
                'neighborhood' => $this->text($dataset['neighborhood'] ?? null),
            ]),
            'service_areas' => [$city],
            'source_name' => trim((string) ($dataset['source_name'] ?? $this->name())),
            'source_url' => $sourceUrl,
            'source_checked_at' => $checkedAt,
        ];

        $phone = $this->phone($record['טלפון בעסק'] ?? null);
        if ($phone !== null) {
            $business['phone'] = $phone;
        }
        $email = $this->email($record['מייל בעסק'] ?? null);
        if ($email !== null) {
            $business['contact_email'] = $email;
        }

        return $business;
    }

    private function isActive(array $record, array $dataset): bool
    {
        $statuses = array_map('intval', (array) ($dataset['active_statuses'] ?? [6, 7, 8]));
        if (! in_array((int) ($record['סטטוס'] ?? 0), $statuses, true)) {
            return false;
        }

        $expiresAt = $this->date($record['תאריך תוקף'] ?? null);

        return $expiresAt !== null && $expiresAt >= new DateTimeImmutable('today');
    }

    private function category(string $name, string $license): ?string
    {
        $value = $this->normalized("{$name} {$license}");
        $nameValue = $this->normalized($name);

        if ($this->containsAny($nameValue, ['מלון', 'בית מלון'])) {
            return 'travel_leisure.hotels_guesthouses';
        }
        if ($this->containsAny($value, ['מכולת', 'מרכול', 'סופרמרקט', 'חנות נוחות'])) {
            return 'professionals.grocery_food';
        }
        if ($this->containsAny($value, ['אולם אירועים', 'אולם שמחות', 'מרכז אירועים'])) {
            return 'professionals.venues';
        }
        if ($this->containsAny($value, ['קייטרינג', 'הסעדה'])) {
            return 'professionals.catering';
        }
        if ($this->containsAny($value, [
            'מאפ', 'קונדיט', 'בורקס', 'רוגעלך', 'פאטיס', 'שיבולת', 'בונז ור',
            'עוגות', 'cookie', 'קוקי', 'peretz',
        ])) {
            return 'food_catering.bakery';
        }
        if ($this->containsAny($value, [
            'פיצה', 'פלאפל', 'שווארמה', 'בורגר', 'המבורגר', 'בגט', 'טורטיל',
            'שניצל', 'קבב', 'פרצל', 'צ יקן', 'בורגראנץ', 'מקדונלד', 'ג ירוס',
        ])) {
            return 'professionals.fast_food';
        }
        if ($this->containsAny($value, [
            'בית קפה', 'קפה', 'קופי', 'ארומה', 'גליד', 'ג ילטו', 'לג נדה',
            'teabar', 'רולדין', 'קייק',
        ])) {
            return 'food_catering.cafes';
        }
        if ($this->containsAny($value, ['אטליז', 'מעדנ'])) {
            return 'food_catering.meat_deli';
        }
        if ($this->containsAny($value, ['פאב']) || preg_match('/(?:^| )בר(?: |$)/u', $nameValue) === 1) {
            return 'food_catering.bars';
        }
        if ($this->containsAny($value, ['מסעדה', 'בית אוכל'])) {
            return 'food_catering.restaurants';
        }

        return null;
    }

    private function containsAny(string $value, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($value, $this->normalized((string) $needle))) {
                return true;
            }
        }

        return false;
    }

    private function normalized(string $value): string
    {
        if (class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_KC) ?: $value;
        }
        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/[\x{0591}-\x{05C7}]/u', '', $value) ?? $value;
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return $value === '' ? null : $value;
    }

    private function addressNumber(mixed $value): ?string
    {
        $value = $this->text($value);

        return $value === null || $value === '0' ? null : $value;
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        try {
            return $this->text($value) === null ? null : new DateTimeImmutable((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function phone(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
        if (preg_match('/^\d{7}$/', $digits) === 1) {
            return '08-'.$digits;
        }
        if (preg_match('/^08\d{7}$/', $digits) === 1) {
            return '08-'.substr($digits, 2);
        }
        if (preg_match('/^05\d{8}$/', $digits) === 1) {
            return substr($digits, 0, 3).'-'.substr($digits, 3);
        }
        if (preg_match('/^9725\d{8}$/', $digits) === 1) {
            return '0'.substr($digits, 3, 2).'-'.substr($digits, 5);
        }

        return null;
    }

    private function email(mixed $value): ?string
    {
        $email = mb_strtolower(trim((string) $value), 'UTF-8');
        if (preg_match('/@(?:gmail|hotmail|outlook|walla)\.co$/i', $email) === 1) {
            return null;
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) === false ? null : $email;
    }
}
