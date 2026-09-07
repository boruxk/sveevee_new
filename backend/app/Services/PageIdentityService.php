<?php

namespace App\Services;

use App\Models\Page;
use App\Models\PageIdentityKey;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Normalizer;

class PageIdentityService
{
    public function sync(Page $page): PageIdentityKey
    {
        return PageIdentityKey::query()->updateOrCreate(
            ['page_id' => $page->id],
            $this->fromPage($page)
        );
    }

    public function ensureAll(): void
    {
        Page::query()
            ->whereDoesntHave('identityKey')
            ->chunkById(100, fn (Collection $pages) => $pages->each(fn (Page $page) => $this->sync($page)));
    }

    public function fromPage(Page $page): array
    {
        $setup = is_array($page->setup) ? $page->setup : [];
        $address = is_array($setup['address'] ?? null) ? $setup['address'] : [];

        return $this->fromInput([
            'type' => $page->type,
            'name' => $page->name,
            'category_key' => $page->category_key,
            'phone' => $page->phone,
            'contact_email' => $page->contact_email,
            'website' => $setup['website'] ?? null,
            'address' => $address,
        ]);
    }

    public function fromInput(array $data): array
    {
        $address = is_array($data['address'] ?? null) ? $data['address'] : [];
        $name = $this->text($data['name'] ?? null);
        $city = $this->text($address['city'] ?? null);
        $neighborhood = $this->text($address['neighborhood'] ?? null);
        $type = trim((string) ($data['type'] ?? ''));
        $category = trim((string) ($data['category_key'] ?? ''));
        $streetAddress = collect([
            $address['street'] ?? null,
            $address['number'] ?? null,
            $address['neighborhood'] ?? null,
            $address['city'] ?? null,
        ])->filter(fn ($value) => filled($value))->implode(' ');

        return [
            'type' => $type,
            'category_key' => $category !== '' ? $category : null,
            'normalized_name' => $name,
            'normalized_city' => $city !== '' ? $city : null,
            'normalized_neighborhood' => $neighborhood !== '' ? $neighborhood : null,
            'normalized_phone' => $this->phone($data['phone'] ?? null),
            'normalized_email' => $this->email($data['contact_email'] ?? $data['email'] ?? null),
            'normalized_website' => $this->website($data['website'] ?? null),
            'normalized_address' => ($normalized = $this->text($streetAddress)) !== '' ? $normalized : null,
            'identity_hash' => hash('sha256', implode('|', [$type, $name, $city, $category, $neighborhood])),
        ];
    }

    public function exactMatches(array $data, ?int $excludePageId = null, bool $allowSingleContactSignal = false): Collection
    {
        $this->ensureAll();
        $identity = $this->fromInput($data);

        return PageIdentityKey::query()
            ->with('page')
            ->when($excludePageId, fn ($query) => $query->where('page_id', '!=', $excludePageId))
            ->when($identity['type'] !== '', fn ($query) => $query->where('type', $identity['type']))
            ->where(function ($query) use ($identity, $allowSingleContactSignal): void {
                $query->whereRaw('1 = 0');

                if ($identity['normalized_name'] !== '' && $identity['normalized_city']) {
                    $query->orWhere('identity_hash', $identity['identity_hash']);
                    $query->orWhere(function ($name) use ($identity): void {
                        $name->where('normalized_name', $identity['normalized_name'])
                            ->where('normalized_city', $identity['normalized_city']);
                    });
                } elseif ($identity['normalized_name'] !== '') {
                    $query->orWhere('normalized_name', $identity['normalized_name']);
                }

                if ($identity['normalized_phone']) {
                    $query->orWhere(function ($phone) use ($identity, $allowSingleContactSignal): void {
                        $phone->where('normalized_phone', $identity['normalized_phone']);
                        if (! $allowSingleContactSignal) {
                            $phone->where('normalized_name', $identity['normalized_name']);
                        }
                    });
                }

                if ($identity['normalized_email']) {
                    $query->orWhere(function ($email) use ($identity, $allowSingleContactSignal): void {
                        $email->where('normalized_email', $identity['normalized_email']);
                        if (! $allowSingleContactSignal) {
                            $email->where('normalized_name', $identity['normalized_name']);
                        }
                    });
                }

                if ($identity['normalized_name'] !== '' && $identity['normalized_website']) {
                    $query->orWhere(function ($website) use ($identity): void {
                        $website->where('normalized_name', $identity['normalized_name'])
                            ->where('normalized_website', $identity['normalized_website']);
                    });
                }

                if ($identity['normalized_address']) {
                    $query->orWhere(function ($address) use ($identity): void {
                        $address->where('type', $identity['type'])
                            ->where('category_key', $identity['category_key'])
                            ->where('normalized_name', $identity['normalized_name'])
                            ->where('normalized_address', $identity['normalized_address']);
                    });
                }
            })
            ->limit(10)
            ->get()
            ->filter(fn (PageIdentityKey $key) => $key->page !== null)
            ->map(function (PageIdentityKey $key) use ($identity): array {
                $matchedOn = [];

                if ($identity['normalized_name'] !== ''
                    && $key->normalized_name === $identity['normalized_name']
                    && (! $identity['normalized_city'] || $key->normalized_city === $identity['normalized_city'])) {
                    $matchedOn[] = 'name';
                }
                if ($identity['normalized_phone'] && $key->normalized_phone === $identity['normalized_phone']) {
                    $matchedOn[] = 'phone';
                }
                if ($identity['normalized_email'] && $key->normalized_email === $identity['normalized_email']) {
                    $matchedOn[] = 'contact_email';
                }
                if ($identity['normalized_website'] && $key->normalized_website === $identity['normalized_website']) {
                    $matchedOn[] = 'website';
                }
                if ($identity['normalized_address'] && $key->normalized_address === $identity['normalized_address']) {
                    $matchedOn[] = 'address';
                }

                return [
                    'id' => $key->page->id,
                    'name' => $key->page->name,
                    'type' => $key->page->type,
                    'category_key' => $key->page->category_key,
                    'public_path' => $key->page->public_path,
                    'matched_on' => array_values(array_unique($matchedOn)),
                ];
            })
            ->values();
    }

    public function withDuplicateLocks(array $data, Closure $callback): mixed
    {
        $identity = $this->fromInput($data);
        $signals = [
            $identity['normalized_phone'] ? 'phone|'.$identity['normalized_phone'] : null,
            $identity['normalized_email'] ? 'email|'.$identity['normalized_email'] : null,
            $identity['normalized_name'] !== ''
                ? 'name|'.$identity['type'].'|'.$identity['normalized_name'].'|'.$identity['normalized_city']
                : null,
            $identity['normalized_name'] !== '' && $identity['normalized_city']
                ? 'identity|'.$identity['identity_hash']
                : null,
        ];
        $lockKeys = collect($signals)
            ->filter()
            ->map(fn (string $signal): string => 'page-duplicate:'.hash('sha256', $signal))
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $this->runWithLocks($lockKeys, 0, $callback);
    }

    public function text(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        if (class_exists(Normalizer::class)) {
            $value = Normalizer::normalize($value, Normalizer::FORM_KC) ?: $value;
        }

        $value = mb_strtolower($value);
        $value = preg_replace('/[\x{0591}-\x{05C7}]/u', '', $value) ?? $value;
        $value = preg_replace('/[\p{P}\p{S}\p{Z}\s]+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    public function phone(mixed $value): ?string
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

    public function email(mixed $value): ?string
    {
        $value = mb_strtolower(trim((string) $value), 'UTF-8');

        return $value !== '' ? $value : null;
    }

    private function website(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $url = preg_match('~^https?://~i', $value) ? $value : 'https://'.$value;
        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\./i', '', $host) ?? $host;
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        return $host !== '' ? $host.($path !== '' ? '/'.$path : '') : null;
    }

    private function runWithLocks(array $lockKeys, int $position, Closure $callback): mixed
    {
        if (! isset($lockKeys[$position])) {
            return $callback();
        }

        return Cache::lock($lockKeys[$position], 10)->block(
            5,
            fn () => $this->runWithLocks($lockKeys, $position + 1, $callback)
        );
    }
}
