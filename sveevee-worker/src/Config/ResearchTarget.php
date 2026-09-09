<?php

declare(strict_types=1);

namespace Sveevee\Worker\Config;

final readonly class ResearchTarget
{
    public function __construct(
        public string $city,
        public string $categoryKey,
        public ?string $neighborhood = null,
    ) {}

    /** Internal scan/report label only; never used as a place's address or category. */
    public static function overtureAll(): self
    {
        return new self('Israel', 'all_places');
    }

    public function isOvertureAll(): bool
    {
        return $this->city === 'Israel' && $this->categoryKey === 'all_places' && $this->neighborhood === null;
    }

    /** A source-wide cursor label, never a business address or category. */
    public static function sourceAll(string $provider): self
    {
        if (! in_array($provider, ['data_gov_ckan', 'tel_aviv_business_licenses'], true)) {
            throw new \InvalidArgumentException('Unsupported complete source scan.');
        }

        return new self($provider === 'data_gov_ckan' ? 'Israel' : 'Tel Aviv', 'all_records:'.$provider);
    }

    public function fullSourceProvider(): ?string
    {
        if ($this->isOvertureAll()) {
            return 'overture_places';
        }
        foreach (['data_gov_ckan', 'tel_aviv_business_licenses'] as $provider) {
            if ($this->key() === self::sourceAll($provider)->key()) {
                return $provider;
            }
        }

        return null;
    }

    public function isFullSource(): bool
    {
        return $this->fullSourceProvider() !== null;
    }

    public function key(): string
    {
        $parts = [$this->city];
        if ($this->neighborhood !== null) {
            $parts[] = $this->neighborhood;
        }
        $parts[] = $this->categoryKey;

        return implode('|', $parts);
    }
}
