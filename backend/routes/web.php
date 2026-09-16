<?php

use App\Http\Controllers\PublicPageHtmlController;
use App\Http\Controllers\SitemapController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/sitemap.xml', [SitemapController::class, 'index']);

// These responses contain public HTML only; session/CSRF cookies would defeat shared caching.
Route::middleware('platform.available')->withoutMiddleware([
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    ShareErrorsFromSession::class,
    ValidateCsrfToken::class,
])->group(function (): void {
    Route::get('/', [PublicPageHtmlController::class, 'home']);
    Route::get('/{locale}/{kind}/{slug}', [PublicPageHtmlController::class, 'show'])
        ->where('locale', 'he|en|ru|fr')->where('kind', 'business|community|product|pages');
    Route::get('/{kind}/{slug}', [PublicPageHtmlController::class, 'legacy'])
        ->where('kind', 'business|community|product|pages');
});
