<?php

use App\Http\Controllers\Api\AdController;
use App\Http\Controllers\Api\AdminBlockedTermController;
use App\Http\Controllers\Api\AdminPageClaimController;
use App\Http\Controllers\Api\AdminPageController;
use App\Http\Controllers\Api\AdminSettingsController;
use App\Http\Controllers\Api\AdminSupportController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AiWorkPageBulkEditController;
use App\Http\Controllers\Api\AiWorkPageController;
use App\Http\Controllers\Api\AiWorkPageImportController;
use App\Http\Controllers\Api\AiWorkPreferenceController;
use App\Http\Controllers\Api\AiWorkTaskController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BusinessPageLeadController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\GuestSupportController;
use App\Http\Controllers\Api\HomeFeedController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\MarketController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PageChatController;
use App\Http\Controllers\Api\PageClaimController;
use App\Http\Controllers\Api\PageController;
use App\Http\Controllers\Api\PageEventController;
use App\Http\Controllers\Api\PagePriceController;
use App\Http\Controllers\Api\PageProductController;
use App\Http\Controllers\Api\PageRatingController;
use App\Http\Controllers\Api\PageServiceController;
use App\Http\Controllers\Api\PlatformStatusController;
use App\Http\Controllers\Api\PresenceController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PublicUserController;
use App\Http\Controllers\Api\SearchController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['platform.available', 'recaptcha'])->group(function () {
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->whereNumber('id')
        ->name('email-verification.verify')
        ->withoutMiddleware(['platform.available', 'recaptcha']);
    Route::get('/platform-status', PlatformStatusController::class)->withoutMiddleware('platform.available');
    Route::get('/catalog', [CatalogController::class, 'index']);
    Route::get('/catalog/{topicSlug}', [CatalogController::class, 'index']);
    Route::get('/catalog/{citySlug}/{topicSlug}', [CatalogController::class, 'indexForCity']);
    Route::get('/catalog/{citySlug}/{neighborhoodSlug}/{topicSlug}', [CatalogController::class, 'indexForNeighborhood']);
    Route::get('/market/{citySlug}', [MarketController::class, 'index']);
    Route::get('/market/{citySlug}/{topicSlug}', [MarketController::class, 'index']);
    Route::get('/search', [SearchController::class, 'index']);
    Route::get('/locations', [LocationController::class, 'index']);
    Route::get('/users/{user}', [PublicUserController::class, 'show']);
    Route::get('/ads/{ad}', [AdController::class, 'show']);
    Route::get('/products/{product}', [PageProductController::class, 'show']);
    Route::post('/products/{product}/contact', [PageProductController::class, 'recordContact'])
        ->withoutMiddleware('recaptcha')
        ->middleware('throttle:120,1');
    Route::get('/pages/{page}/ratings', [PageRatingController::class, 'index']);
    Route::get('/pages/{page}', [PageController::class, 'show']);

    Route::post('/business-page-leads', [BusinessPageLeadController::class, 'store'])
        ->middleware('throttle:business-page-leads');

    Route::get('/guest-support', [GuestSupportController::class, 'show'])->middleware('throttle:120,1');
    Route::post('/guest-support', [GuestSupportController::class, 'store'])->middleware('throttle:guest-support-start');
    Route::post('/guest-support/messages', [GuestSupportController::class, 'send'])->middleware('throttle:chat-send');

    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:auth-register');
        Route::post('/login', [AuthController::class, 'login'])->withoutMiddleware('platform.available')->middleware('throttle:auth-login');
        Route::post('/srvfrvrvv53Ljjug5h2h9zbdw', [AuthController::class, 'aiLogin'])
            ->withoutMiddleware(['platform.available', 'recaptcha'])
            ->middleware('throttle:auth-ai-worker');
        Route::get('/google/redirect', [AuthController::class, 'redirectToGoogle'])->withoutMiddleware('platform.available')->middleware('throttle:auth-login');
        Route::get('/google/callback', [AuthController::class, 'handleGoogleCallback'])->withoutMiddleware('platform.available')->middleware('throttle:auth-login');
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:auth-login');
        Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:auth-login');
        Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout'])->withoutMiddleware('platform.available');
    });

    Route::middleware('auth:sanctum')->get('/me', [AuthController::class, 'me'])->withoutMiddleware('platform.available');
    Route::middleware(['auth:sanctum', 'throttle:30,1'])
        ->post('/presence/heartbeat', PresenceController::class)
        ->withoutMiddleware(['platform.available', 'recaptcha']);
    Route::middleware('auth:sanctum')->put('/profile/locale', [ProfileController::class, 'updateLocale'])->withoutMiddleware('platform.available');
    Route::middleware('auth:sanctum')->withoutMiddleware(['platform.available', 'recaptcha'])->group(function () {
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::patch('/notifications/read-all', [NotificationController::class, 'readAll']);
        Route::patch('/notifications/{id}/read', [NotificationController::class, 'read'])
            ->whereUuid('id');
    });

    Route::middleware(['auth:sanctum', 'role:user,admin'])->group(function () {
        Route::post('/guest-support/claim', [GuestSupportController::class, 'claim'])->middleware('throttle:10,1');

        Route::get('/home-feed', [HomeFeedController::class, 'index']);

        Route::get('/profile', [ProfileController::class, 'show']);
        Route::put('/profile', [ProfileController::class, 'update']);
        Route::put('/profile/password', [ProfileController::class, 'updatePassword']);
        Route::post('/profile/photo', [ProfileController::class, 'uploadPhoto']);
        Route::delete('/profile/photo', [ProfileController::class, 'destroyPhoto']);
        Route::post('/profile/email-verification', [EmailVerificationController::class, 'send'])
            ->middleware('throttle:3,60');
        Route::put('/profile/email-preferences', [ProfileController::class, 'updateEmailPreferences']);

        Route::get('/pages/{type}/mine', [PageController::class, 'mine']);
        Route::post('/pages/{type}', [PageController::class, 'upsert']);
        Route::patch('/pages/{type}/features', [PageController::class, 'updateFeatures']);
        Route::delete('/pages/{page}', [PageController::class, 'destroy']);
        Route::post('/pages/{page}/claim-requests', [PageClaimController::class, 'store']);
        Route::post('/pages/{page}/products', [PageProductController::class, 'store']);
        Route::put('/products/{product}', [PageProductController::class, 'update']);
        Route::delete('/products/{product}', [PageProductController::class, 'destroy']);
        Route::post('/pages/{page}/prices', [PagePriceController::class, 'store']);
        Route::put('/page-prices/{price}', [PagePriceController::class, 'update']);
        Route::delete('/page-prices/{price}', [PagePriceController::class, 'destroy']);
        Route::post('/pages/{page}/services', [PageServiceController::class, 'store']);
        Route::put('/services/{service}', [PageServiceController::class, 'update']);
        Route::delete('/services/{service}', [PageServiceController::class, 'destroy']);
        Route::post('/pages/{page}/events', [PageEventController::class, 'store']);
        Route::get('/events', [PageEventController::class, 'index']);
        Route::post('/events', [PageEventController::class, 'storePersonal']);
        Route::put('/events/{event}', [PageEventController::class, 'update']);
        Route::delete('/events/{event}', [PageEventController::class, 'destroy']);
        Route::put('/pages/{page}/ratings/me', [PageRatingController::class, 'store']);

        Route::get('/pages/{page}/chat', [PageChatController::class, 'visitorShow']);
        Route::post('/pages/{page}/chat/messages', [PageChatController::class, 'sendToPage'])->middleware('throttle:chat-send');
        Route::get('/pages/{page}/chats', [PageChatController::class, 'ownerIndex']);
        Route::get('/page-chats', [PageChatController::class, 'visitorIndex']);
        Route::get('/page-chats/{pageConversation}', [PageChatController::class, 'show']);
        Route::post('/page-chats/{pageConversation}/messages', [PageChatController::class, 'send'])->middleware('throttle:chat-send');
        Route::patch('/page-chats/{pageConversation}/read', [PageChatController::class, 'markAsRead']);

        Route::get('/ads', [AdController::class, 'index']);
        Route::post('/ads', [AdController::class, 'store']);
        Route::put('/ads/{ad}', [AdController::class, 'update']);
        Route::delete('/ads/{ad}', [AdController::class, 'destroy']);

        Route::get('/chats', [ChatController::class, 'index']);
        Route::get('/chats/support', [ChatController::class, 'support']);
        Route::post('/chats/support/messages', [ChatController::class, 'sendSupport'])->middleware('throttle:chat-send');
        Route::get('/chats/users/{user}', [ChatController::class, 'start']);
        Route::post('/chats/users/{user}/messages', [ChatController::class, 'sendToUser'])->middleware('throttle:chat-send');
        Route::get('/chats/{conversation}', [ChatController::class, 'show']);
        Route::post('/chats/{conversation}/messages', [ChatController::class, 'send'])->middleware('throttle:chat-send');
        Route::patch('/chats/{conversation}/read', [ChatController::class, 'markAsRead']);
        Route::delete('/chats/{conversation}', [ChatController::class, 'destroy']);
    });

    Route::middleware(['auth:sanctum', 'role:ai_worker'])
        ->withoutMiddleware('platform.available')
        ->prefix('ai-works')
        ->group(function () {
            Route::get('/tasks', [AiWorkTaskController::class, 'index']);
            Route::post('/tasks', [AiWorkTaskController::class, 'store']);
            Route::put('/tasks/{task}', [AiWorkTaskController::class, 'update']);
            Route::delete('/tasks/{task}', [AiWorkTaskController::class, 'destroy']);
            Route::get('/preferences', [AiWorkPreferenceController::class, 'show']);
            Route::patch('/preferences', [AiWorkPreferenceController::class, 'update'])->withoutMiddleware('recaptcha');
            Route::get('/pages', [AiWorkPageController::class, 'index']);
            Route::post('/pages/duplicate-check', [AiWorkPageController::class, 'duplicateCheck'])->withoutMiddleware('recaptcha');
            Route::get('/pages/bulk-edit', [AiWorkPageBulkEditController::class, 'export']);
            Route::patch('/pages/bulk-edit', [AiWorkPageBulkEditController::class, 'update'])->withoutMiddleware('recaptcha');
            Route::post('/pages', [AiWorkPageController::class, 'store'])->withoutMiddleware('recaptcha');
            Route::get('/pages/{page}', [AiWorkPageController::class, 'show']);
            Route::put('/pages/{page}', [AiWorkPageController::class, 'update'])->withoutMiddleware('recaptcha');
            Route::delete('/pages/{page}', [AiWorkPageController::class, 'destroy']);
            Route::get('/page-imports', [AiWorkPageImportController::class, 'index']);
            Route::post('/page-imports', [AiWorkPageImportController::class, 'store'])->withoutMiddleware('recaptcha');
            Route::get('/page-imports/{import}', [AiWorkPageImportController::class, 'show']);
        });

    Route::middleware(['auth:sanctum', 'admin'])->withoutMiddleware('platform.available')->prefix('admin')->group(function () {
        Route::get('/support-chats', [AdminSupportController::class, 'index']);
        Route::get('/support-chats/{source}/{id}', [AdminSupportController::class, 'show'])
            ->whereIn('source', ['account', 'guest']);
        Route::post('/support-chats/{source}/{id}/messages', [AdminSupportController::class, 'send'])
            ->whereIn('source', ['account', 'guest'])
            ->middleware('throttle:chat-send');
        Route::post('/page-claims/{claimRequest}/approve', [AdminPageClaimController::class, 'approve']);
        Route::post('/page-claims/{claimRequest}/cancel', [AdminPageClaimController::class, 'cancel']);
        Route::get('/pages', [AdminPageController::class, 'index']);
        Route::get('/page-owner-options', [AdminPageController::class, 'ownerOptions']);
        Route::patch('/pages/{page}/owner', [AdminPageController::class, 'updateOwner']);
        Route::delete('/pages/{page}', [AdminPageController::class, 'destroy']);
        Route::get('/settings', [AdminSettingsController::class, 'index']);
        Route::patch('/settings/{section}', [AdminSettingsController::class, 'update']);
        Route::get('/blocked-terms', [AdminBlockedTermController::class, 'index']);
        Route::post('/blocked-terms', [AdminBlockedTermController::class, 'store']);
        Route::put('/blocked-terms/{blockedTerm}', [AdminBlockedTermController::class, 'update']);
        Route::delete('/blocked-terms/{blockedTerm}', [AdminBlockedTermController::class, 'destroy']);
        Route::get('/users', [AdminUserController::class, 'index']);
        Route::get('/users/{user}', [AdminUserController::class, 'show']);
        Route::delete('/users/{user}', [AdminUserController::class, 'destroy']);
        Route::patch('/users/{user}/ban', [AdminUserController::class, 'ban']);
        Route::patch('/users/{user}/restore', [AdminUserController::class, 'restore']);
        Route::post('/users/{user}/message', [ChatController::class, 'adminSend'])->middleware('throttle:chat-send');
    });
});
