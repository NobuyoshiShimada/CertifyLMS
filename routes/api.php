<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\NotificationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| JSON API のルート定義。
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// 通知ポップオーバー(JS)向け 通知 JSON API。Sanctum SPA Cookie 認証(同一オリジンのセッション Cookie + CSRF)で保護する。
// read-all は {notification} より先に定義する(パスの衝突回避)。
Route::middleware('auth:sanctum')
    ->prefix('v1/notifications')
    ->name('api.v1.notifications.')
    ->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::post('read-all', [NotificationController::class, 'readAll'])->name('readAll');
        Route::post('{notification}/read', [NotificationController::class, 'read'])->name('read');
    });
