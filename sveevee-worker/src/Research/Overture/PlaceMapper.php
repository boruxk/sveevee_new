<?php

declare(strict_types=1);

namespace Sveevee\Worker\Research\Overture;

use RuntimeException;
use Sveevee\Worker\Domain\BusinessNormalizer;

/** Explicit source taxonomy/city mapping: an unknown locality never inherits a target city. */
final class PlaceMapper
{
    private array $cities = [];

    public function __construct(
        array $cities,
        array $aliases,
        private readonly float $minConfidence = 0.75,
        private readonly string $mode = 'catalog',
    ) {
        if (! in_array($mode, ['catalog', 'all_places'], true)) {
            throw new RuntimeException('Overture import_mode must be catalog or all_places.');
        }
        if ($minConfidence < 0 || $minConfidence > 1 || ! is_finite($minConfidence)) {
            throw new RuntimeException('Overture min_confidence must be between 0 and 1.');
        }
        foreach ($cities as $city) {
            foreach ([$city, ...($aliases[$city] ?? [])] as $alias) {
                $key = $this->cityKey($alias);
                if (isset($this->cities[$key]) && $this->cities[$key] !== $city) {
                    throw new RuntimeException('Ambiguous Overture city alias: '.$alias);
                }
                $this->cities[$key] = $city;
            }
        }
    }

    public static function fromConfig(array $config): self
    {
        // Explicit source spellings observed in the Israel release; no fuzzy/transliteration matching.
        $aliases = [
            'Sakhnin' => ['סחנין'], 'Tel Aviv' => ['Tel Aviv-Yafo'],
            'Rishon LeZion' => ['Rishon Leziyyon'],
            'Kiryat Ono' => ['קרית אונו'], 'Kiryat Bialik' => ['קרית ביאליק'],
            'Kiryat Motzkin' => ['קרית מוצקין'], 'Kiryat Ata' => ['קרית אתא'],
            'Kiryat Yam' => ['קרית ים'], 'Kiryat Tivon' => ['קרית טבעון'],
            'Kiryat Malakhi' => ['קרית מלאכי'],
        ];
        foreach (($config['sources']['data_gov_ckan']['datasets'] ?? []) as $dataset) {
            if (($dataset['profile'] ?? '') !== 'israel_companies') {
                continue;
            }
            foreach (($dataset['city_names'] ?? []) as $city => $names) {
                $aliases[$city] = array_merge($aliases[$city] ?? [], (array) $names);
            }
        }
        foreach (($config['sources']['overture_places']['city_names'] ?? []) as $city => $names) {
            $aliases[$city] = array_merge($aliases[$city] ?? [], (array) $names);
        }

        return new self(
            $config['cities'] ?? [], $aliases,
            (float) ($config['sources']['overture_places']['min_confidence'] ?? 0.75),
            (string) ($config['sources']['overture_places']['import_mode'] ?? 'catalog'),
        );
    }

    public function importMode(): string
    {
        return $this->mode;
    }

    public function map(array $row, string $release, string $checkedAt, ?string &$skipReason = null): ?array
    {
        $skipReason = null;
        foreach (['id', 'names', 'addresses', 'taxonomy', 'confidence', 'operating_status', 'sources'] as $key) {
            if (! array_key_exists($key, $row)) {
                throw new RuntimeException('Overture record is missing field '.$key.'.');
            }
        }
        $id = $this->text($row['id'], 100);
        if ($id === null || preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $id) !== 1) {
            return $this->skip('invalid_id', $skipReason);
        }
        $allPlaces = $this->mode === 'all_places';
        $confidence = is_numeric($row['confidence']) && is_finite((float) $row['confidence'])
            && (float) $row['confidence'] >= 0 && (float) $row['confidence'] <= 1 ? (float) $row['confidence'] : null;
        if (! $allPlaces && ($confidence === null || $confidence < $this->minConfidence)) {
            return $this->skip('confidence', $skipReason);
        }
        if (! $allPlaces && in_array($row['operating_status'], ['permanently_closed', 'temporarily_closed', 'closed'], true)) {
            return $this->skip('closed', $skipReason);
        }
        $name = $this->text($row['names']['primary'] ?? null, 255);
        if ($name === null || BusinessNormalizer::cleanBusinessName($name) === '' || ! preg_match('/[\p{L}\p{N}]/u', $name)) {
            return $this->skip('name', $skipReason);
        }
        $category = TaxonomyMapper::map(is_array($row['taxonomy']) ? $row['taxonomy'] : [], $allPlaces);
        if ($category === null && ! $allPlaces) {
            return $this->skip('category', $skipReason);
        }
        $address = null;
        $hadIsrael = false;
        foreach (is_array($row['addresses']) ? $row['addresses'] : [] as $candidate) {
            if (! is_array($candidate) || ($candidate['country'] ?? null) !== 'IL') {
                continue;
            }
            $hadIsrael = true;
            $city = $this->cities[$this->cityKey($candidate['locality'] ?? '')] ?? null;
            $street = $this->text($candidate['freeform'] ?? null, 255);
            if ($city !== null && $street !== null && preg_match('/[\p{L}\p{N}]/u', $street)) {
                $address = ['city' => $city, 'street' => $street, 'original' => $candidate];
                break;
            }
        }
        if ($address === null && $allPlaces && $hadIsrael) {
            // Keep the legacy choice above first. Otherwise preserve the best available IL address,
            // without inventing a city from its region, postcode, coordinates or another country.
            $score = -1;
            foreach ($row['addresses'] as $candidate) {
                if (! is_array($candidate) || ($candidate['country'] ?? null) !== 'IL') {
                    continue;
                }
                $city = $this->cities[$this->cityKey($candidate['locality'] ?? '')] ?? $this->meaningfulText($candidate['locality'] ?? null, 120);
                $street = $this->meaningfulText($candidate['freeform'] ?? null, 255);
                $candidateScore = ($city !== null ? 2 : 0) + ($street !== null ? 1 : 0);
                if ($candidateScore > $score) {
                    $address = ['city' => $city, 'street' => $street, 'original' => $candidate];
                    $score = $candidateScore;
                }
            }
        }
        if ($address === null) {
            return $this->skip($hadIsrael ? 'address_or_city' : 'country', $skipReason);
        }
        $socials = [];
        foreach ((array) ($row['socials'] ?? []) as $value) {
            $url = $this->url($value);
            $host = $url === null ? '' : strtolower((string) parse_url($url, PHP_URL_HOST));
            $network = match (preg_replace('/^(www\.|m\.)/', '', $host)) {
                'facebook.com' => 'facebook', 'instagram.com' => 'instagram',
                'tiktok.com' => 'tiktok', 't.me', 'telegram.me' => 'telegram',
                'twitter.com', 'x.com' => 'x', default => null,
            };
            if ($network !== null) {
                $socials[$network] ??= $url;
            }
        }

        return [
            'id' => $id, 'name' => $name, 'category_key' => $category,
            'city' => $address['city'], 'street' => $address['street'],
            'phone' => $this->first($row['phones'] ?? [], $this->phone(...)),
            'email' => $this->first($row['emails'] ?? [], $this->email(...)),
            'website' => $this->first($row['websites'] ?? [], $this->url(...)),
            'social_links' => (object) $socials,
            'confidence' => $confidence, 'release' => $release,
            'source_url' => 'https://explore.overturemaps.org/?feature=places.place.'.rawurlencode($id),
            'source_name' => 'Overture Maps Places', 'source_checked_at' => $checkedAt,
            'source_metadata' => [
                'gers_id' => $id, 'release' => $release, 'confidence' => $confidence,
                'operating_status' => $row['operating_status'], 'taxonomy' => $row['taxonomy'],
                'address' => $address['original'], 'sources' => $row['sources'],
                'license_url' => 'https://docs.overturemaps.org/attribution/',
                'attribution' => '© Overture Maps Foundation and its contributors',
                ...array_intersect_key($row, array_flip(['basic_category', 'bbox', 'geometry', 'coordinates'])),
            ],
        ];
    }

    private function skip(string $reason, ?string &$skipReason): ?array
    {
        $skipReason = $reason;

        return null;
    }

    private function cityKey(mixed $value): string
    {
        return mb_strtolower($this->text($value, 255) ?? '', 'UTF-8');
    }

    private function meaningfulText(mixed $value, int $maximum): ?string
    {
        $text = $this->text($value, $maximum);

        return $text !== null && preg_match('/[\p{L}\p{N}]/u', $text) ? $text : null;
    }

    private function text(mixed $value, int $maximum): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim(preg_replace('/[\p{Z}\s]+/u', ' ', $value) ?? '');

        return $value === '' || mb_strlen($value, 'UTF-8') > $maximum ? null : $value;
    }

    private function first(mixed $values, callable $normalize): ?string
    {
        foreach (is_array($values) ? $values : [] as $value) {
            if (($result = $normalize($value)) !== null) {
                return $result;
            }
        }

        return null;
    }

    private function phone(mixed $value): ?string
    {
        $value = $this->text($value, 80);
        if ($value === null || ! preg_match('/^[+0-9() .-]+$/D', $value)) {
            return null;
        }
        $digits = preg_replace('/\D/', '', $value);
        if (str_starts_with($digits, '00972')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $digits = '972'.substr($digits, 1);
        }

        return preg_match('/^972[1-9][0-9]{7,8}$/D', $digits) ? '+'.$digits : null;
    }

    private function email(mixed $value): ?string
    {
        $value = $this->text($value, 255);

        return $value !== null && filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? mb_strtolower($value, 'UTF-8') : null;
    }

    private function url(mixed $value): ?string
    {
        $value = $this->text($value, 2048);
        if ($value === null || preg_match('/\s/u', $value)) {
            return null;
        }
        if (! preg_match('~^https?://~i', $value)) {
            if (str_contains($value, ':')) {
                return null;
            }
            $value = 'https://'.$value;
        }
        $host = (string) parse_url($value, PHP_URL_HOST);
        if (filter_var($value, FILTER_VALIDATE_URL) === false || ! str_contains($host, '.') || parse_url($value, PHP_URL_USER) !== null) {
            return null;
        }

        return $value;
    }
}
