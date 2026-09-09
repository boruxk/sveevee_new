<?php

declare(strict_types=1);

namespace Sveevee\Worker\Domain;

/** Shared contacts and website hosts are insufficient to identify a particular place. */
final class BusinessLocationIdentity
{
    public static function conflict(array $current, array $incoming): ?string
    {
        return match (self::matchStatus($current, $incoming)) {
            'same' => null,
            'different' => 'Business name, city or street identifies another business location.',
            default => 'The business address is insufficient to resolve a location safely.',
        };
    }

    /** Contacts never decide this comparison; a location needs its name, city and street address. */
    public static function matchStatus(array $current, array $incoming): string
    {
        $currentName = self::name($current);
        $incomingName = self::name($incoming);
        $currentCity = self::city($current);
        $incomingCity = self::city($incoming);
        if ($currentName === '' || $incomingName === '' || $currentCity === '' || $incomingCity === '') {
            return 'ambiguous';
        }
        if ($currentName !== $incomingName || $currentCity !== $incomingCity) {
            return 'different';
        }
        $currentStreet = self::street($current);
        $incomingStreet = self::street($incoming);
        if ($currentStreet === '' || $incomingStreet === '') {
            return 'ambiguous';
        }
        if ($currentStreet === $incomingStreet) {
            return 'same';
        }
        // A street-only record and the same street with a number might describe the same branch.
        // Retain that uncertainty without inventing, deleting or splitting a house number.
        foreach ([[$currentStreet, $incomingStreet], [$incomingStreet, $currentStreet]] as [$short, $long]) {
            if (str_starts_with($long, $short.' ') && preg_match('/^[0-9]+(?: [\p{L}0-9]+)*$/u', substr($long, strlen($short) + 1)) === 1) {
                return 'ambiguous';
            }
        }

        return 'different';
    }

    public static function name(array $business): string
    {
        return self::text(BusinessNormalizer::cleanBusinessName((string) ($business['name'] ?? '')));
    }

    public static function city(array $business): string
    {
        return self::text($business['address']['city'] ?? '');
    }

    public static function street(array $business): string
    {
        $street = self::text($business['address']['street'] ?? '');

        return $street === '' ? '' : self::text($street.' '.($business['address']['number'] ?? ''));
    }

    /** Use only after conflict() confirmed that both descriptions identify the same place. */
    public static function preserveAddressRepresentation(array $current, array $incoming): array
    {
        if (! empty($current['address']['street']) && ! empty($incoming['address']['street'])) {
            // Keep freeform and split addresses from becoming "Herzl 10", number="10".
            $incoming['address']['street'] = $current['address']['street'];
            if (array_key_exists('number', $current['address'])) {
                $incoming['address']['number'] = $current['address']['number'];
            } else {
                unset($incoming['address']['number']);
            }
        }

        return $incoming;
    }

    private static function text(mixed $value): string
    {
        $value = mb_strtolower(trim((string) $value), 'UTF-8');
        $value = preg_replace('/[\x{0591}-\x{05C7}]/u', '', $value) ?? $value;

        return trim(preg_replace('/[\p{P}\p{S}\p{Z}\s]+/u', ' ', $value) ?? $value);
    }
}
