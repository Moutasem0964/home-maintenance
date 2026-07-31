<?php

use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\WalletController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('register/start', [AuthController::class, 'registerStart'])->middleware('throttle:6,1');
    Route::post('register/verify', [AuthController::class, 'registerVerify'])->middleware('throttle:6,1');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:6,1');
    Route::post('password/forgot', [AuthController::class, 'passwordForgot'])->middleware('throttle:6,1');
    Route::post('password/reset', [AuthController::class, 'passwordReset'])->middleware('throttle:6,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

Route::get('categories', [CategoryController::class, 'index']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('wallet', [WalletController::class, 'show']);
    Route::post('wallet/top-up', [WalletController::class, 'topUp']);

    Route::apiResource('addresses', AddressController::class);
});
