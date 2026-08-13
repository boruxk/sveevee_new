<?php

namespace App\Http\Controllers;

use App\Models\Ad;
use App\Models\Page;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

class SitemapController extends Controller
{
    public function index(): Response
    {
        $entries = collect([
            $this->entry('/', now(), 'daily', '1.0'),
            $this->entry('/search', now(), 'daily', '0.8'),
            $this->entry('/privacy', now(), 'monthly', '0.3'),
        ]);

        Page::query()
            ->whereNotNull('name')
            ->where('name', '!=', '')
            ->whereHas('user', fn ($query) => $query->whereNull('banned_at'))
            ->orderBy('id')
            ->get(['id', 'updated_at'])
            ->each(fn (Page $page) => $entries->push(
                $this->entry("/pages/{$page->id}", $page->updated_at, 'weekly', '0.8')
            ));

        Ad::query()
            ->active()
            ->whereHas('user', fn ($query) => $query->whereNull('banned_at'))
            ->orderBy('id')
            ->get(['id', 'updated_at'])
            ->each(fn (Ad $ad) => $entries->push(
                $this->entry("/ads/{$ad->id}", $ad->updated_at, 'daily', '0.7')
            ));

        return response($this->toXml($entries->take(50000)->all()), 200)
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    private function entry(string $path, ?Carbon $lastModified, string $changeFrequency, string $priority): array
    {
        return [
            'loc' => $this->absoluteUrl($path),
            'lastmod' => ($lastModified ?: now())->toDateString(),
            'changefreq' => $changeFrequency,
            'priority' => $priority,
        ];
    }

    private function absoluteUrl(string $path): string
    {
        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
    }

    private function toXml(array $entries): string
    {
        $xml = ['<?xml version="1.0" encoding="UTF-8"?>'];
        $xml[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ($entries as $entry) {
            $xml[] = '  <url>';
            $xml[] = '    <loc>'.$this->escape($entry['loc']).'</loc>';
            $xml[] = '    <lastmod>'.$this->escape($entry['lastmod']).'</lastmod>';
            $xml[] = '    <changefreq>'.$this->escape($entry['changefreq']).'</changefreq>';
            $xml[] = '    <priority>'.$this->escape($entry['priority']).'</priority>';
            $xml[] = '  </url>';
        }

        $xml[] = '</urlset>';

        return implode("\n", $xml)."\n";
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}
