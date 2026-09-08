<?php

declare(strict_types=1);

namespace Sveevee\Worker\Research;

use RuntimeException;
use Sveevee\Worker\Config\ResearchTarget;
use Sveevee\Worker\Http\HttpClientInterface;
use Sveevee\Worker\Support\Clock;
use Sveevee\Worker\Support\Environment;
use Sveevee\Worker\Support\Json;
use Sveevee\Worker\Support\Pacer;

final class OverpassSource implements SourceAdapterInterface
{
    private int $queryCount = 0;
    private readonly string $endpoint;
    private readonly Pacer $pacer;

    public function __construct(
        private readonly array $config,
        private readonly HttpClientInterface $http,
        private readonly string $userAgent,
    ) {
        $this->endpoint = Environment::get('OVERPASS_API_URL', (string) ($config['endpoint'] ?? '')) ?? '';
        $this->pacer = new Pacer((int) round(max(0.0, (float) ($config['min_interval_seconds'] ?? 10)) * 1000));
        if ($this->endpoint === '') {
            throw new RuntimeException('Overpass is enabled but no endpoint is configured.');
        }
        $host = strtolower((string) parse_url($this->endpoint, PHP_URL_HOST));
        if (($host === 'overpass-api.de' || str_ends_with($host, '.overpass-api.de'))
            && ! (bool) ($config['allow_public_instance'] ?? false)) {
            throw new RuntimeException(
                'The public overpass-api.de instance is disabled. Configure an authorized/self-hosted endpoint or explicitly allow it for a small one-off test.'
            );
        }
    }

    public function name(): string
    {
        return 'overpass';
    }

    public function refreshAfterDays(): int
    {
        return max(1, (int) ($this->config['refresh_after_days'] ?? 30));
    }

    public function research(ResearchTarget $target, int $limit): iterable
    {
        $maximumQueries = max(1, (int) ($this->config['max_queries_per_run'] ?? 20));
        if ($this->queryCount >= $maximumQueries) {
            return;
        }
        $tags = $this->config['category_tags'][$target->categoryKey] ?? [];
        if (! is_array($tags) || $tags === []) {
            throw new RuntimeException("No Overpass tag mapping for category {$target->categoryKey}.");
        }

        $this->queryCount++;
        $query = $this->query($target, $tags, min(1000, max(25, $limit * 3)));
        $response = $this->send($query);
        $decoded = Json::decode($response);
        $elements = is_array($decoded) ? ($decoded['elements'] ?? []) : [];
        $emitted = 0;

        foreach (is_array($elements) ? $elements : [] as $element) {
            $business = $this->mapElement($element, $target);
            if ($business === null) {
                continue;
            }
            yield $business;
            $emitted++;
            if ($emitted >= $limit) {
                return;
            }
        }
    }

    private function send(string $query): string
    {
        $maxRetries = 3;
        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            $this->pacer->wait();
            $response = $this->http->request('POST', $this->endpoint, [
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ], http_build_query(['data' => $query]), [
                'timeout' => max(5, (int) ($this->config['timeout_seconds'] ?? 30)) + 5,
                'max_bytes' => 15_728_640,
                'user_agent' => $this->userAgent,
            ]);
            if ($response->status >= 200 && $response->status < 300) {
                return $response->body;
            }
            if (! in_array($response->status, [429, 502, 503, 504], true) || $attempt === $maxRetries) {
                $detail = trim((string) preg_replace('/\s+/u', ' ', strip_tags($response->body)));
                $detail = $detail === '' ? '' : ' '.mb_substr($detail, 0, 500, 'UTF-8');
                throw new RuntimeException("Overpass request failed with HTTP {$response->status}.{$detail}");
            }
            sleep($response->status === 429 ? 30 : min(30, 2 ** ($attempt + 1)));
        }

        throw new RuntimeException('Overpass request failed.');
    }

    private function query(ResearchTarget $target, array $tags, int $limit): string
    {
        $timeout = max(5, min(120, (int) ($this->config['timeout_seconds'] ?? 30)));
        $country = $this->quote((string) ($this->config['country_code'] ?? 'IL'));
        $cityNames = $this->config['city_names'][$target->city] ?? [$target->city];
        $cityRegex = $this->nameRegex((array) $cityNames);
        $lines = [
            "[out:json][timeout:{$timeout}];",
            "area[\"ISO3166-1\"={$country}][\"boundary\"=\"administrative\"]->.country;",
            '(',
            "area[\"boundary\"=\"administrative\"][\"name\"~{$cityRegex},i](area.country);",
            "area[\"boundary\"=\"administrative\"][\"name:en\"~{$cityRegex},i](area.country);",
            "area[\"boundary\"=\"administrative\"][\"name:he\"~{$cityRegex},i](area.country);",
            ')->.city;',
        ];
        $area = '.city';
        if ($target->neighborhood !== null) {
            $names = $this->config['neighborhood_names'][$target->city][$target->neighborhood] ?? [$target->neighborhood];
            $regex = $this->nameRegex((array) $names);
            $lines[] = '(';
            $lines[] = "area[\"boundary\"=\"administrative\"][\"name\"~{$regex},i](area.city);";
            $lines[] = "area[\"boundary\"=\"administrative\"][\"name:en\"~{$regex},i](area.city);";
            $lines[] = "area[\"boundary\"=\"administrative\"][\"name:he\"~{$regex},i](area.city);";
            $lines[] = ')->.neighborhood;';
            $area = '.neighborhood';
        }
        $lines[] = '(';
        foreach ($tags as $tag) {
            $key = (string) ($tag['key'] ?? '');
            $value = (string) ($tag['value'] ?? '');
            if (preg_match('/^[A-Za-z0-9:_-]+$/', $key) !== 1 || $value === '') {
                throw new RuntimeException("Invalid Overpass tag mapping for {$target->categoryKey}.");
            }
            $lines[] = sprintf('nwr[%s=%s](area%s);', $this->quote($key), $this->quote($value), $area);
        }
        $lines[] = ');';
        $lines[] = "out tags center qt {$limit};";

        return implode("\n", $lines);
    }

    private function mapElement(mixed $element, ResearchTarget $target): ?array
    {
        if (! is_array($element) || ! is_array($element['tags'] ?? null)) {
            return null;
        }
        $tags = $element['tags'];
        $name = $this->first($tags, ['name', 'name:he', 'name:en', 'brand', 'operator']);
        if ($name === null) {
            return null;
        }
        $type = in_array($element['type'] ?? null, ['node', 'way', 'relation'], true) ? $element['type'] : null;
        $id = filter_var($element['id'] ?? null, FILTER_VALIDATE_INT);
        $sourceUrl = $type && $id ? "https://www.openstreetmap.org/{$type}/{$id}" : null;

        $business = [
            'type' => 'business',
            'name' => $name,
            'category_key' => $target->categoryKey,
            'address' => array_filter([
                'street' => $this->first($tags, ['addr:street']),
                'number' => $this->first($tags, ['addr:housenumber']),
                'city' => $target->city,
                'neighborhood' => $target->neighborhood,
            ]),
            'service_areas' => [$target->city],
            'source_name' => 'OpenStreetMap via Overpass',
            'source_url' => $sourceUrl,
            'source_checked_at' => Clock::now(),
        ];
        $map = [
            'public_description' => ['description'],
            'contact_email' => ['contact:email', 'email'],
            'phone' => ['contact:phone', 'phone'],
            'whatsapp' => ['contact:whatsapp', 'whatsapp'],
            'website' => ['contact:website', 'website'],
            'opening_hours' => ['opening_hours'],
        ];
        foreach ($map as $field => $keys) {
            if (($value = $this->first($tags, $keys)) !== null) {
                $business[$field] = $value;
            }
        }
        $socials = [];
        foreach ([
            'facebook' => ['contact:facebook', 'facebook'],
            'instagram' => ['contact:instagram', 'instagram'],
            'tiktok' => ['contact:tiktok', 'tiktok'],
            'telegram' => ['contact:telegram', 'telegram'],
            'x' => ['contact:twitter', 'twitter', 'contact:x'],
        ] as $network => $keys) {
            if (($value = $this->first($tags, $keys)) !== null) {
                $socials[$network] = $value;
            }
        }
        if ($socials !== []) {
            $business['socials'] = $socials;
        }
        $specialties = [];
        foreach (['service', 'services', 'healthcare:speciality', 'cuisine', 'product'] as $key) {
            if (isset($tags[$key])) {
                $specialties = [...$specialties, ...(preg_split('/\s*[;,]\s*/u', (string) $tags[$key]) ?: [])];
            }
        }
        if ($specialties !== []) {
            $business['specialties'] = array_values(array_unique(array_map(
                static fn (string $value): string => str_replace('_', ' ', trim($value)),
                $specialties
            )));
        }

        return $business;
    }

    private function first(array $tags, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($tags[$key] ?? ''));
            if ($value !== '') {
                return preg_split('/\s*;\s*/u', $value, 2)[0];
            }
        }

        return null;
    }

    private function nameRegex(array $names): string
    {
        $escaped = array_map($this->regexLiteral(...), array_map(
            static fn ($name): string => trim((string) $name),
            $names
        ));

        return $this->quote('^('.implode('|', array_filter($escaped)).')$');
    }

    private function regexLiteral(string $value): string
    {
        $result = '';
        foreach (mb_str_split($value) as $character) {
            $result .= in_array($character, ['\\', '.', '^', '$', '|', '(', ')', '[', ']', '{', '}', '*', '+', '?'], true)
                ? '\\'.$character
                : $character;
        }

        return $result;
    }

    private function quote(string $value): string
    {
        return Json::encode($value);
    }
}
