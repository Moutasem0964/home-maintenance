<?php

use App\Http\Controllers\AdminDepositReceiptController;
use App\Http\Controllers\AdminTechnicianDocController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Session-authed receipt stream for the admin panel (Filament uses the web guard).
Route::get('admin/deposits/{topUp}/receipt', AdminDepositReceiptController::class)
    ->middleware('auth')
    ->name('admin.deposits.receipt');

Route::get('admin/technicians/{technician}/doc/{kind}', AdminTechnicianDocController::class)
    ->middleware('auth')
    ->name('admin.technicians.doc');
