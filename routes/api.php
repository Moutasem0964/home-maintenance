<?php

use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\AdminTechnicianController;
use App\Http\Controllers\Api\AdminTechnicianFlagController;
use App\Http\Controllers\Api\AppSettingController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CancellationController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ClosureController;
use App\Http\Controllers\Api\DisputeController;
use App\Http\Controllers\Api\OfficeController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\OrderPhotoController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\QuoteController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\TechnicianController;
use App\Http\Controllers\Api\TechnicianOfferController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\WarrantyController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('register/start', [AuthController::class, 'registerStart'])->middleware('throttle:6,1');
    Route::post('register/verify', [AuthController::class, 'registerVerify'])->middleware('throttle:6,1');
    Route::post('register/client', [AuthController::class, 'registerClient'])->middleware('throttle:6,1');
    Route::post('register/technician', [AuthController::class, 'registerTechnician'])->middleware('throttle:6,1');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:6,1');
    Route::post('password/forgot', [AuthController::class, 'passwordForgot'])->middleware('throttle:6,1');
    Route::post('password/verify', [AuthController::class, 'passwordVerify'])->middleware('throttle:6,1');
    Route::post('password/reset', [AuthController::class, 'passwordReset'])->middleware('throttle:6,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

Route::get('categories', [CategoryController::class, 'index']);
Route::get('app-settings', [AppSettingController::class, 'index']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('offices', [OfficeController::class, 'index']);
    Route::put('profile', [ProfileController::class, 'update']);

    Route::get('wallet', [WalletController::class, 'show']);
    Route::post('wallet/top-up', [WalletController::class, 'topUp']);

    Route::apiResource('addresses', AddressController::class);
    Route::apiResource('orders', OrderController::class)->only(['index', 'store', 'show']);
    Route::get('order-photos/{orderPhoto}', [OrderPhotoController::class, 'show']);

    Route::get('orders/{order}/quotes', [QuoteController::class, 'index']);
    Route::post('orders/{order}/quotes', [QuoteController::class, 'store']);
    Route::post('quotes/{quote}/approve', [QuoteController::class, 'approve']);
    Route::post('quotes/{quote}/reject', [QuoteController::class, 'reject']);

    Route::post('orders/{order}/closure/request', [ClosureController::class, 'requestCode']);
    Route::get('orders/{order}/closure/code', [ClosureController::class, 'code']);
    Route::post('orders/{order}/closure/verify', [ClosureController::class, 'verify']);

    Route::get('disputes', [DisputeController::class, 'index']);
    Route::post('orders/{order}/dispute', [DisputeController::class, 'store']);
    Route::post('disputes/{dispute}/resolve', [DisputeController::class, 'resolve']);

    Route::post('orders/{order}/review', [ReviewController::class, 'store']);
    Route::post('orders/{order}/warranty-claim', [WarrantyController::class, 'claim']);

    Route::post('orders/{order}/cancel', [CancellationController::class, 'cancel']);
    Route::post('orders/{order}/withdraw', [CancellationController::class, 'withdraw']);
    Route::post('orders/{order}/no-show/client', [CancellationController::class, 'clientNoShow']);
    Route::post('orders/{order}/no-show/technician', [CancellationController::class, 'technicianNoShow']);

    Route::get('technician/me', [TechnicianController::class, 'me']);
    Route::put('technician/services', [TechnicianController::class, 'setServices']);
    Route::put('technician/availability', [TechnicianController::class, 'setAvailability']);

    Route::get('technician/offers', [TechnicianOfferController::class, 'index']);
    Route::post('technician/offers/{offer}/accept', [TechnicianOfferController::class, 'accept']);
    Route::post('technician/offers/{offer}/decline', [TechnicianOfferController::class, 'decline']);

    Route::post('admin/technicians/{technician}/approve', [AdminTechnicianController::class, 'approve']);
    Route::post('admin/technicians/{technician}/suspend', [AdminTechnicianController::class, 'suspend']);
    Route::post('admin/technicians/{technician}/ban', [AdminTechnicianController::class, 'ban']);

    Route::get('admin/technician-flags', [AdminTechnicianFlagController::class, 'index']);
    Route::post('admin/technician-flags/{flag}/review', [AdminTechnicianFlagController::class, 'review']);
});
