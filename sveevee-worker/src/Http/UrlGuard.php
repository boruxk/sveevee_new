<?php

declare(strict_types=1);

namespace Sveevee\Worker\Http;

use RuntimeException;

final class UrlGuard
{
    public function publicIp(string $url): string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new RuntimeException('Only absolute HTTP(S) website URLs are allowed.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Website URLs with embedded credentials are not allowed.');
        }
        if (isset($parts['port']) && ! in_array((int) $parts['port'], [80, 443], true)) {
            throw new RuntimeException('Only standard HTTP(S) website ports are allowed.');
        }
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            throw new RuntimeException('Local website addresses are not allowed.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $addresses = [$host];
        } else {
            $addresses = [];
            foreach (dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
                $ip = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($ip)) {
                    $addresses[] = $ip;
                }
            }
        }
        if ($addresses === []) {
            throw new RuntimeException('Website hostname could not be resolved.');
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new RuntimeException('Website resolves to a private or reserved address.');
            }
        }

        return $addresses[0];
    }

    public function resolve(string $base, string $location): string
    {
        if (preg_match('~^https?://~i', $location)) {
            return $location;
        }
        $parts = parse_url($base);
        if (str_starts_with($location, '//')) {
            return ($parts['scheme'] ?? 'https').':'.$location;
        }
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');
        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }
        $directory = rtrim(str_replace('\\', '/', dirname((string) ($parts['path'] ?? '/'))), '/');

        return $origin.($directory === '' ? '' : $directory).'/'.$location;
    }
}
