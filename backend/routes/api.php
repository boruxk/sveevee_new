<?php

use App\Http\Controllers\Api\AdController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\HomeFeedController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\PageController;
use App\Http\Controllers\Api\PageEventController;
use App\Http\Controllers\Api\PageProductController;
use App\Http\Controllers\Api\PageRatingController;
use App\Http\Controllers\Api\PageServiceController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PublicUserController;
use App\Http\Controllers\Api\SearchController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('recaptcha')->group(function () {
    Route::get('/search', [SearchController::class, 'index']);
    Route::get('/locations', [LocationController::class, 'index']);
    Route::get('/users/{user}', [PublicUserController::class, 'show']);
    Route::get('/pages/{page}/ratings', [PageRatingController::class, 'index']);
    Route::get('/pages/{page}', [PageController::class, 'show']);

    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:auth-register');
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:auth-login');
        Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:auth-login');
        Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::get('/home-feed', [HomeFeedController::class, 'index']);

        Route::get('/profile', [ProfileController::class, 'show']);
        Route::put('/profile', [ProfileController::class, 'update']);
        Route::put('/profile/locale', [ProfileController::class, 'updateLocale']);
        Route::put('/profile/password', [ProfileController::class, 'updatePassword']);
        Route::post('/profile/photo', [ProfileController::class, 'uploadPhoto']);
        Route::delete('/profile/photo', [ProfileController::class, 'destroyPhoto']);

        Route::get('/pages/{type}/mine', [PageController::class, 'mine']);
        Route::post('/pages/{type}', [PageController::class, 'upsert']);
        Route::delete('/pages/{page}', [PageController::class, 'destroy']);
        Route::post('/pages/{page}/products', [PageProductController::class, 'store']);
        Route::put('/products/{product}', [PageProductController::class, 'update']);
        Route::delete('/products/{product}', [PageProductController::class, 'destroy']);
        Route::post('/pages/{page}/services', [PageServiceController::class, 'store']);
        Route::put('/services/{service}', [PageServiceController::class, 'update']);
        Route::delete('/services/{service}', [PageServiceController::class, 'destroy']);
        Route::post('/pages/{page}/events', [PageEventController::class, 'store']);
        Route::put('/events/{event}', [PageEventController::class, 'update']);
        Route::delete('/events/{event}', [PageEventController::class, 'destroy']);
        Route::put('/pages/{page}/ratings/me', [PageRatingController::class, 'store']);

        Route::get('/ads', [AdController::class, 'index']);
        Route::post('/ads', [AdController::class, 'store']);
        Route::put('/ads/{ad}', [AdController::class, 'update']);
        Route::delete('/ads/{ad}', [AdController::class, 'destroy']);

        Route::get('/chats', [ChatController::class, 'index']);
        Route::get('/chats/users/{user}', [ChatController::class, 'start']);
        Route::post('/chats/users/{user}/messages', [ChatController::class, 'sendToUser'])->middleware('throttle:chat-send');
        Route::get('/chats/{conversation}', [ChatController::class, 'show']);
        Route::post('/chats/{conversation}/messages', [ChatController::class, 'send'])->middleware('throttle:chat-send');
        Route::patch('/chats/{conversation}/read', [ChatController::class, 'markAsRead']);
    });

    Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
        Route::get('/users', [AdminUserController::class, 'index']);
        Route::get('/users/{user}', [AdminUserController::class, 'show']);
        Route::patch('/users/{user}/ban', [AdminUserController::class, 'ban']);
        Route::patch('/users/{user}/restore', [AdminUserController::class, 'restore']);
        Route::post('/users/{user}/message', [ChatController::class, 'adminSend'])->middleware('throttle:chat-send');
    });
});
