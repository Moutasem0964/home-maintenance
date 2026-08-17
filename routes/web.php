<?php

use App\Http\Controllers\AdminDepositReceiptController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Session-authed receipt stream for the admin panel (Filament uses the web guard).
Route::get('admin/deposits/{topUp}/receipt', AdminDepositReceiptController::class)
    ->middleware('auth')
    ->name('admin.deposits.receipt');
