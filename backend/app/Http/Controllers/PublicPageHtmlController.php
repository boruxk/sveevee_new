<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\PageProduct;
use App\Services\SeoPrerenderService;
use App\Support\PublicSlug;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class PublicPageHtmlController extends Controller
{
    private const LOCALES = ['he', 'en', 'ru', 'fr'];

    public function __construct(private readonly SeoPrerenderService $renderer) {}

    public function home(Request $request): Response
    {
        try {
            return $this->htmlResponse($request, $this->renderer->renderHome());
        } catch (RuntimeException $error) {
            report($error);

            return $this->unavailable('he', 503);
        }
    }

    public function show(Request $request, string $locale, string $kind, string $slug): Response
    {
        return $this->render($request, $locale, $kind, $slug);
    }

    public function legacy(Request $request, string $kind, string $slug): Response
    {
        $locale = $request->query('lang');

        return $this->render($request, in_array($locale, self::LOCALES, true) ? $locale : 'he', $kind, $slug);
    }

    private function render(Request $request, string $locale, string $kind, string $slug): Response
    {
        $id = PublicSlug::idFromSlug($slug);
        if (! $id || ! preg_match('/\A[\pL\pN]+(?:-[\pL\pN]+)*\z/u', $slug)) {
            return $this->unavailable($locale, 404);
        }

        if ($kind === 'product') {
            $record = PageProduct::query()
                ->whereKey($id)
                ->whereNotNull('name')->where('name', '!=', '')
                ->whereHas('page', fn ($query) => $query->managed()
                    ->where('type', Page::TYPE_BUSINESS)
                    ->whereHas('user', fn ($owner) => $owner->whereNull('banned_at')))
                ->with('page.user')
                ->first();
            $canonical = $record ? '/'.$locale.'/product/'.$record->public_slug : null;
        } else {
            $record = Page::query()
                ->whereKey($id)
                ->whereIn('type', [Page::TYPE_BUSINESS, Page::TYPE_COMMUNITY])
                ->whereNotNull('name')->where('name', '!=', '')
                ->whereHas('user', fn ($owner) => $owner->whereNull('banned_at'))
                ->first();
            $canonical = $record ? '/'.$locale.$record->public_path : null;
        }

        if (! $record) {
            return $this->unavailable($locale, 404);
        }

        // Old names, numeric IDs and unlocalized aliases consolidate to the same public URL.
        if (rawurldecode($request->getPathInfo()) !== $canonical) {
            $query = $request->query();
            unset($query['lang']);
            $target = rtrim((string) config('app.url'), '/').$canonical;
            if ($query !== []) {
                $target .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            }

            return redirect()->away($target, 301);
        }

        try {
            $html = $record instanceof PageProduct
                ? $this->renderer->renderProduct($record, $locale)
                : $this->renderer->renderPage($record, $locale);
        } catch (RuntimeException $error) {
            report($error);

            return $this->unavailable($locale, 503);
        }

        return $this->htmlResponse($request, $html);
    }

    private function htmlResponse(Request $request, string $html): Response
    {
        // No cookies, account-specific data or persisted page snapshots. Edits are visible immediately.
        $response = response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'public, max-age=0, must-revalidate',
        ]);
        $response->setEtag(hash('sha256', $html));
        $response->isNotModified($request);

        return $response;
    }

    private function unavailable(string $locale, int $status): Response
    {
        $titles = $status === 404
            ? ['he' => 'העמוד לא נמצא', 'en' => 'Page not found', 'ru' => 'Страница не найдена', 'fr' => 'Page introuvable']
            : ['he' => 'העמוד אינו זמין כרגע', 'en' => 'Page temporarily unavailable', 'ru' => 'Страница временно недоступна', 'fr' => 'Page temporairement indisponible'];
        $response = response()->view('seo.error', [
            'locale' => $locale,
            'title' => $titles[$locale],
            'status' => $status,
        ], $status)->header('Cache-Control', 'no-store');

        return $status === 404
            ? $response->header('X-Robots-Tag', 'noindex')
            : $response->header('Retry-After', '300');
    }
}
