<?php

declare(strict_types=1);

namespace Sveevee\Worker\Research;

use DateTimeImmutable;
use DateTimeZone;
use Sveevee\Worker\Http\SafeWebClient;
use Sveevee\Worker\Storage\WorkerRepository;

final class RobotsPolicy
{
    public function __construct(
        private readonly SafeWebClient $web,
        private readonly WorkerRepository $repository,
        private readonly string $userAgent,
    ) {}

    public function decision(string $url): RobotsDecision
    {
        $parts = parse_url($url);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');
        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }
        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
        $cached = $this->repository->robotsCache($origin);

        if ($cached === null) {
            try {
                $response = $this->web->get($origin.'/robots.txt', 10, 524288);
                $status = $response->status;
                $body = $response->body;
            } catch (\Throwable) {
                return new RobotsDecision(false, null, 'robots_unreachable');
            }
            $expires = (new DateTimeImmutable('+24 hours', new DateTimeZone('UTC')))->format(DATE_ATOM);
            $this->repository->cacheRobots($origin, $status, $body, $expires);
        } else {
            $status = (int) $cached['status_code'];
            $body = (string) ($cached['body'] ?? '');
        }

        if ($status >= 200 && $status < 300) {
            $rules = new RobotsRules($body);

            return new RobotsDecision(
                $rules->allows($this->userAgent, $path),
                $rules->crawlDelay($this->userAgent),
                'robots_rules'
            );
        }
        if ($status === 404 || $status === 410) {
            return new RobotsDecision(true, null, 'robots_not_found');
        }
        if ($status >= 400 && $status < 500 && ! in_array($status, [401, 403, 429], true)) {
            return new RobotsDecision(true, null, 'robots_unavailable');
        }

        return new RobotsDecision(false, null, 'robots_denied_or_unavailable');
    }
}

