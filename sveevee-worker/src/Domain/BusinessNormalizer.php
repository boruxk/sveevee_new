<?php

declare(strict_types=1);

namespace Sveevee\Worker\Domain;

use DateTimeImmutable;
use Sveevee\Worker\Config\ResearchTarget;
use Sveevee\Worker\Support\Clock;

final class BusinessNormalizer
{
    private const SCALARS = [
        'name' => 255,
        'public_description' => 3000,
        'contact_email' => 255,
        'phone' => 40,
        'whatsapp' => 80,
        'website' => 2048,
        'category_key' => 120,
    ];

    private const SOCIALS = ['facebook', 'instagram', 'tiktok', 'telegram', 'x'];

    public function __construct(
        private readonly OpeningHoursParser $openingHours,
        private readonly array $knownCities,
    ) {}

    public function normalize(array $raw, ResearchTarget $target, string $adapter): BusinessCandidate
    {
        $allPlaces = $adapter === $target->fullSourceProvider();
        $source = new SourceRecord(
            $adapter,
            $this->string($raw['source_name'] ?? $adapter, 120) ?? $adapter,
            $this->url($raw['source_url'] ?? null),
            $this->timestamp($raw['source_checked_at'] ?? null),
            $raw,
        );

        $data = ['type' => 'business'];
        if (isset($raw['id']) && filter_var($raw['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false) {
            $data['id'] = (int) $raw['id'];
        }
        foreach (self::SCALARS as $field => $maximum) {
            $value = $this->string($raw[$field] ?? null, $maximum);
            if ($value !== null) {
                $data[$field] = $value;
            }
        }
        if (! $allPlaces) {
            $data['category_key'] ??= $target->categoryKey;
        }

        if (isset($data['contact_email'])) {
            $email = mb_strtolower($data['contact_email'], 'UTF-8');
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                unset($data['contact_email']);
            } else {
                $data['contact_email'] = $email;
            }
        }
        if (isset($data['website'])) {
            $website = $this->url($data['website']);
            if ($website === null) {
                unset($data['website']);
            } else {
                $data['website'] = $website;
            }
        }
        if (isset($data['whatsapp']) && preg_match('~(?:wa\.me|whatsapp\.com)~i', $data['whatsapp'])) {
            $digits = preg_replace('/\D+/', '', $data['whatsapp']) ?? '';
            if ($digits !== '') {
                $data['whatsapp'] = $digits;
            }
        }

        $rawAddress = is_array($raw['address'] ?? null) ? $raw['address'] : [];
        $address = [
            'city' => $this->canonicalCity($rawAddress['city'] ?? null)
                ?? ($allPlaces ? $this->string($rawAddress['city'] ?? null, 120) : $target->city),
        ];
        foreach (['street' => 255, 'number' => 40, 'neighborhood' => 120] as $field => $maximum) {
            $value = $this->string($rawAddress[$field] ?? null, $maximum);
            if ($value !== null) {
                $address[$field] = $value;
            }
        }
        $address['neighborhood'] ??= $target->neighborhood;
        $data['address'] = array_filter($address, static fn ($value) => $value !== null && $value !== '');

        $socials = [];
        foreach (self::SOCIALS as $network) {
            $value = $this->socialUrl(
                $network,
                is_array($raw['socials'] ?? null) ? ($raw['socials'][$network] ?? null) : null
            );
            if ($value !== null) {
                $socials[$network] = $value;
            }
        }
        if ($socials !== []) {
            $data['socials'] = $socials;
        }

        $openingHours = [];
        if (is_string($raw['opening_hours'] ?? null)) {
            $openingHours = $this->openingHours->parse($raw['opening_hours']);
        } elseif (is_array($raw['opening_hours'] ?? null)) {
            $openingHours = $this->openingHours->normalize($raw['opening_hours']);
        }
        if ($openingHours !== []) {
            $data['opening_hours'] = $openingHours;
        }

        // A source place's location does not establish a service area.
        $serviceAreas = $allPlaces ? [] : $this->stringList($raw['service_areas'] ?? [], 10, 120);
        $serviceAreas = array_values(array_filter(array_map($this->canonicalCity(...), $serviceAreas)));
        if ($serviceAreas !== []) {
            $data['service_areas'] = $serviceAreas;
        }
        $specialties = $this->stringList($raw['specialties'] ?? [], 50, 120);
        if ($specialties !== []) {
            $data['specialties'] = $specialties;
        }

        $missing = [];
        foreach ($allPlaces ? ['name'] : ['name', 'category_key'] as $required) {
            if (! isset($data[$required]) || trim((string) $data[$required]) === '') {
                $missing[] = $required;
            }
        }
        if (! $allPlaces && ($data['address']['city'] ?? '') === '') {
            $missing[] = 'address.city';
        }
        if ($missing !== []) {
            throw new IncompleteCandidateException($missing);
        }

        if ($allPlaces) {
            $metadata = is_array($raw['source_metadata'] ?? null) ? $raw['source_metadata'] : [];
            $metadata['original_name'] = $raw['name'];
            if (isset($raw['website'])) {
                $metadata['original_website'] = $raw['website'];
            }
            $id = $this->string($adapter === 'overture_places' ? ($metadata['overture_id'] ?? $metadata['gers_id'] ?? null) : ($metadata['source_id'] ?? null), 100);
            if ($id === null || $source->url === null) {
                throw new IncompleteCandidateException(['source.id', 'source.url']);
            }
            $data['source'] = ['provider' => $adapter, 'id' => $id, 'url' => $source->url, 'metadata' => $metadata];
        }

        return new BusinessCandidate($data, [$source]);
    }

    public static function cleanBusinessName(string $name): string
    {
        $marker = 'בע(?:\s*["״“”„~\x{0027}׳]{1,2}\s*)?מ';
        $cleaned = preg_replace(
            '/(?<![\p{L}\p{M}\p{N}])(?:\(\s*'.$marker.'\s*\)|'.$marker.')(?![\p{L}\p{M}\p{N}])/u',
            '',
            $name,
        ) ?? $name;
        if ($cleaned === $name) {
            return $name;
        }

        return trim(preg_replace('/[\p{Z}\s]+/u', ' ', $cleaned) ?? $cleaned);
    }

    public function identityKeys(array $data): array
    {
        $address = is_array($data['address'] ?? null) ? $data['address'] : [];
        $name = $this->identityText($data['name'] ?? '');
        $city = $this->identityText($address['city'] ?? '');
        $keys = [];
        if ($name !== '') {
            $keys['name_city'] = $name.'|'.$city;
        }
        $placeName = BusinessLocationIdentity::name($data);
        $placeCity = BusinessLocationIdentity::city($data);
        if ($placeName !== '' && $placeCity !== '') {
            $keys['place_name_city'] = $placeName.'|'.$placeCity;
            if (($street = BusinessLocationIdentity::street($data)) !== '') {
                $keys['name_location'] = $placeName.'|'.$placeCity.'|'.$street;
            }
        }
        if (isset($data['contact_email'])) {
            $keys['email'] = mb_strtolower(trim((string) $data['contact_email']), 'UTF-8');
        }
        if (($phone = $this->phoneKey($data['phone'] ?? null)) !== null) {
            $keys['phone'] = $phone;
        }
        if (($host = parse_url((string) ($data['website'] ?? ''), PHP_URL_HOST)) !== null && $host !== false && $host !== '') {
            $keys['website'] = preg_replace('/^www\./i', '', mb_strtolower($host, 'UTF-8'));
        }

        return $keys;
    }

    private function canonicalCity(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        foreach ($this->knownCities as $city) {
            if (mb_strtolower((string) $city, 'UTF-8') === mb_strtolower($value, 'UTF-8')) {
                return (string) $city;
            }
        }

        return null;
    }

    private function string(mixed $value, int $maximum): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $maximum, 'UTF-8');
    }

    private function stringList(mixed $value, int $maximumItems, int $maximumLength): array
    {
        if (is_string($value)) {
            $value = preg_split('/\s*[;,]\s*/u', $value) ?: [];
        }
        if (! is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $item) {
            $item = $this->string($item, $maximumLength);
            if ($item !== null) {
                $result[mb_strtolower($item, 'UTF-8')] = $item;
            }
            if (count($result) >= $maximumItems) {
                break;
            }
        }

        return array_values($result);
    }

    private function url(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (! preg_match('~^https?://~i', $value)) {
            $value = 'https://'.$value;
        }
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? mb_substr($value, 0, 2048, 'UTF-8') : null;
    }

    private function socialUrl(string $network, mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || preg_match('/\s/u', $value)) {
            return null;
        }
        if (preg_match('~^https?://~i', $value) || str_contains($value, '.')) {
            return $this->url($value);
        }
        $handle = trim($value, '@/');
        if ($handle === '') {
            return null;
        }
        $base = match ($network) {
            'facebook' => 'https://facebook.com/',
            'instagram' => 'https://instagram.com/',
            'tiktok' => 'https://tiktok.com/@',
            'telegram' => 'https://t.me/',
            'x' => 'https://x.com/',
            default => null,
        };

        return $base === null ? null : $base.rawurlencode($handle);
    }

    private function timestamp(mixed $value): string
    {
        try {
            return $value ? (new DateTimeImmutable((string) $value))->format(DATE_ATOM) : Clock::now();
        } catch (\Throwable) {
            return Clock::now();
        }
    }

    private function identityText(mixed $value): string
    {
        $value = mb_strtolower(trim((string) $value), 'UTF-8');
        $value = preg_replace('/[\x{0591}-\x{05C7}]/u', '', $value) ?? $value;
        $value = preg_replace('/[\p{P}\p{S}\p{Z}\s]+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function phoneKey(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '00972')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0') && strlen($digits) >= 9) {
            $digits = '972'.substr($digits, 1);
        }

        return $digits;
    }
}
