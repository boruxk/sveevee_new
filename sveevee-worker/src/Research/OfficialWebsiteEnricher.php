<?php

declare(strict_types=1);

namespace Sveevee\Worker\Research;

use DOMDocument;
use DOMXPath;
use RuntimeException;
use Sveevee\Worker\Domain\BusinessCandidate;
use Sveevee\Worker\Domain\BusinessMerger;
use Sveevee\Worker\Domain\OpeningHoursParser;
use Sveevee\Worker\Domain\SourceRecord;
use Sveevee\Worker\Http\SafeWebClient;
use Sveevee\Worker\Support\Clock;
use Sveevee\Worker\Support\Pacer;

final class OfficialWebsiteEnricher implements BusinessEnricherInterface
{
    private readonly Pacer $pacer;

    public function __construct(
        private readonly array $config,
        private readonly SafeWebClient $web,
        private readonly RobotsPolicy $robots,
        private readonly BusinessMerger $merger,
        private readonly OpeningHoursParser $openingHours,
    ) {
        $this->pacer = new Pacer((int) round(max(0.0, (float) ($config['min_interval_seconds'] ?? 2)) * 1000));
    }

    public function name(): string
    {
        return 'official_website';
    }

    public function refreshAfterDays(): int
    {
        return max(1, (int) ($this->config['refresh_after_days'] ?? 30));
    }

    public function supports(BusinessCandidate $candidate): bool
    {
        return isset($candidate->data['website'])
            && filter_var($candidate->data['website'], FILTER_VALIDATE_URL) !== false;
    }

    public function enrich(BusinessCandidate $candidate): BusinessCandidate
    {
        $url = (string) $candidate->data['website'];
        $decision = $this->robots->decision($url);
        if (! $decision->allowed) {
            throw new RuntimeException("Website access denied by robots policy ({$decision->reason}).");
        }
        $this->pacer->wait();
        if ($decision->crawlDelay !== null && $decision->crawlDelay > 0) {
            usleep((int) min(30_000_000, round($decision->crawlDelay * 1_000_000)));
        }

        $response = $this->web->get(
            $url,
            max(3, (int) ($this->config['timeout_seconds'] ?? 12)),
            max(65536, (int) ($this->config['max_response_bytes'] ?? 2_097_152)),
        );
        if ($response->status < 200 || $response->status >= 300) {
            throw new RuntimeException("Official website returned HTTP {$response->status}.");
        }
        $contentType = strtolower((string) ($response->header('content-type') ?? ''));
        if ($contentType !== '' && ! str_contains($contentType, 'text/html') && ! str_contains($contentType, 'application/xhtml')) {
            throw new RuntimeException('Official website did not return HTML.');
        }

        $extracted = $this->extract($response->body, $candidate->data);
        $source = new SourceRecord(
            $this->name(),
            'Official business website',
            $url,
            Clock::now(),
            [
                'url' => $url,
                'status' => $response->status,
                'content_sha256' => hash('sha256', $response->body),
                'extracted' => $extracted,
            ],
        );

        return $candidate->withDataAndSource(
            $this->merger->mergeResearchData($candidate->data, $extracted),
            $source,
        );
    }

    private function extract(string $html, array $existing): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML($html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            throw new RuntimeException('Official website HTML could not be parsed.');
        }
        $xpath = new DOMXPath($document);
        $data = [];
        $schema = $this->businessSchema($xpath);

        $description = $this->schemaString($schema, 'description')
            ?? $this->xpathAttribute($xpath, '//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="description"]', 'content')
            ?? $this->xpathAttribute($xpath, '//meta[@property="og:description"]', 'content');
        if ($description !== null) {
            $data['public_description'] = trim(strip_tags($description));
        }

        $email = $this->schemaString($schema, 'email') ?? $this->linkValue($xpath, 'mailto:');
        if ($email !== null) {
            $data['contact_email'] = preg_replace('/\?.*$/', '', urldecode($email));
        }
        $phone = $this->schemaString($schema, 'telephone') ?? $this->linkValue($xpath, 'tel:');
        if ($phone !== null) {
            $data['phone'] = urldecode($phone);
        }

        $links = $this->links($xpath);
        foreach ((array) ($schema['sameAs'] ?? []) as $sameAs) {
            if (is_string($sameAs) && preg_match('~^https?://~i', $sameAs)) {
                $links[] = $sameAs;
            }
        }
        $links = array_values(array_unique($links));
        $socials = [];
        foreach ($links as $link) {
            $host = strtolower((string) parse_url($link, PHP_URL_HOST));
            $network = match (true) {
                str_ends_with($host, 'facebook.com') => 'facebook',
                str_ends_with($host, 'instagram.com') => 'instagram',
                str_ends_with($host, 'tiktok.com') => 'tiktok',
                str_ends_with($host, 't.me'), str_ends_with($host, 'telegram.me') => 'telegram',
                $host === 'x.com', str_ends_with($host, '.x.com'), str_ends_with($host, 'twitter.com') => 'x',
                default => null,
            };
            if ($network !== null) {
                $socials[$network] ??= $link;
            }
            if (! isset($data['whatsapp']) && ($host === 'wa.me' || str_ends_with($host, 'whatsapp.com'))) {
                $digits = preg_replace('/\D+/', '', (string) parse_url($link, PHP_URL_PATH));
                if ($digits !== '') {
                    $data['whatsapp'] = $digits;
                }
            }
        }
        if ($socials !== []) {
            $data['socials'] = $socials;
        }

        $hours = $this->schemaOpeningHours($schema);
        if ($hours !== []) {
            $data['opening_hours'] = $hours;
        }
        $keywords = $schema['keywords'] ?? null;
        if (is_string($keywords) || is_array($keywords)) {
            $data['specialties'] = is_array($keywords) ? $keywords : preg_split('/\s*[,;]\s*/u', $keywords);
        }

        $address = is_array($schema['address'] ?? null) ? $schema['address'] : [];
        $streetAddress = trim((string) ($address['streetAddress'] ?? ''));
        if ($streetAddress !== '' && empty($existing['address']['street'])) {
            $data['address'] = ['street' => $streetAddress];
        }

        return array_filter($data, static fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    private function businessSchema(DOMXPath $xpath): array
    {
        foreach ($xpath->query('//script[contains(translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"ld+json")]') ?: [] as $node) {
            try {
                $decoded = json_decode($node->textContent, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            foreach ($this->schemaNodes($decoded) as $schema) {
                $types = (array) ($schema['@type'] ?? []);
                foreach ($types as $type) {
                    if (is_string($type) && (str_contains(strtolower($type), 'business') || in_array($type, ['Organization', 'ProfessionalService'], true))) {
                        return $schema;
                    }
                }
            }
        }

        return [];
    }

    private function schemaNodes(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $nodes = array_is_list($value) ? $value : [$value];
        $result = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $result[] = $node;
            if (isset($node['@graph'])) {
                $result = [...$result, ...$this->schemaNodes($node['@graph'])];
            }
        }

        return $result;
    }

    private function schemaOpeningHours(array $schema): array
    {
        $specification = $schema['openingHoursSpecification'] ?? null;
        if (is_array($specification)) {
            $specification = array_is_list($specification) ? $specification : [$specification];
            $rows = [];
            foreach ($specification as $item) {
                if (! is_array($item)) {
                    continue;
                }
                foreach ((array) ($item['dayOfWeek'] ?? []) as $day) {
                    $weekday = strtolower((string) preg_replace('~^.*/~', '', (string) $day));
                    $rows[] = [
                        'weekday' => $weekday,
                        'is_open' => true,
                        'opens_at' => substr((string) ($item['opens'] ?? ''), 0, 5),
                        'closes_at' => substr((string) ($item['closes'] ?? ''), 0, 5),
                    ];
                }
            }

            return $this->openingHours->normalize($rows);
        }
        $value = $schema['openingHours'] ?? null;
        if (is_string($value)) {
            return $this->openingHours->parse($value);
        }
        if (is_array($value)) {
            return $this->openingHours->parse(implode('; ', $value));
        }

        return [];
    }

    private function schemaString(array $schema, string $key): ?string
    {
        $value = $schema[$key] ?? null;

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function xpathAttribute(DOMXPath $xpath, string $query, string $attribute): ?string
    {
        $node = $xpath->query($query)?->item(0);
        $value = $node?->attributes?->getNamedItem($attribute)?->nodeValue;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function linkValue(DOMXPath $xpath, string $prefix): ?string
    {
        $node = $xpath->query('//a[starts-with(translate(@href,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"'.$prefix.'")]')?->item(0);
        $href = $node?->attributes?->getNamedItem('href')?->nodeValue;

        return is_string($href) ? substr($href, strlen($prefix)) : null;
    }

    private function links(DOMXPath $xpath): array
    {
        $links = [];
        foreach ($xpath->query('//a[@href]') ?: [] as $node) {
            $href = trim((string) $node->attributes?->getNamedItem('href')?->nodeValue);
            if (preg_match('~^https?://~i', $href)) {
                $links[] = $href;
            }
        }

        return array_values(array_unique($links));
    }
}
