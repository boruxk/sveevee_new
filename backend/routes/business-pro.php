<?php

use App\Http\Controllers\Api\AdminBusinessProController;
use App\Http\Controllers\Api\BusinessProBillingController;
use App\Http\Controllers\Api\BusinessProController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['platform.available', 'recaptcha', 'auth:sanctum', 'role:user,admin'])->group(function (): void {
    Route::get('/business-pro', [BusinessProController::class, 'index']);
    Route::get('/business-pro/pages/{page}', [BusinessProController::class, 'show']);
    Route::post('/business-pro/checkout', [BusinessProBillingController::class, 'checkout'])->middleware('throttle:6,1');
    Route::post('/business-pro/payments/{payment:public_id}/verify', [BusinessProBillingController::class, 'verify'])->middleware('throttle:20,1');
    Route::post('/business-pro/cancel', [BusinessProBillingController::class, 'cancel'])->middleware('throttle:10,1');
});

Route::prefix('v1/admin/business-pro')->middleware(['recaptcha', 'auth:sanctum', 'admin'])->group(function (): void {
    Route::get('/subscriptions', [AdminBusinessProController::class, 'subscriptions']);
    Route::get('/payments', [AdminBusinessProController::class, 'payments']);
    Route::get('/features', [AdminBusinessProController::class, 'features']);
    Route::patch('/features/{feature}', [AdminBusinessProController::class, 'updateFeature']);
    Route::get('/offer', [AdminBusinessProController::class, 'offer']);
    Route::patch('/offer', [AdminBusinessProController::class, 'updateOffer']);
});

// Provider notifications must remain reachable during maintenance, without a
// logged-in session or CAPTCHA. The handler verifies the receipt with Cardcom.
Route::match(['GET', 'POST'], 'v1/billing/cardcom/webhook', [BusinessProBillingController::class, 'webhook'])
    ->middleware('throttle:60,1');
