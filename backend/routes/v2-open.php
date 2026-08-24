<?php

use App\Http\Controllers\Open\V2\OpenFinanceController;
use App\Http\Controllers\Open\V2\OpenOrderController;
use App\Http\Controllers\Open\V2\OpenProductController;
use App\Http\Controllers\Open\V2\OpenServiceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api.key:products,read'])->group(function (): void {
    Route::get('/products', [OpenProductController::class, 'index']);
    Route::get('/products/{product}', [OpenProductController::class, 'show']);
    Route::get('/products/{product}/quotes', [OpenProductController::class, 'quotes']);
});

Route::middleware(['api.key:orders,read'])->group(function (): void {
    Route::get('/orders', [OpenOrderController::class, 'index']);
    Route::get('/orders/{invoice}', [OpenOrderController::class, 'show']);
});

Route::middleware(['api.key:orders,write'])->group(function (): void {
    Route::post('/orders', [OpenOrderController::class, 'store']);
    Route::post('/orders/{invoice}/pay', [OpenOrderController::class, 'payByBalance']);
});

Route::middleware(['api.key:services,read'])->group(function (): void {
    Route::get('/services', [OpenServiceController::class, 'index']);
    Route::get('/services/{service}', [OpenServiceController::class, 'show']);
    Route::get('/services/{service}/renewals', [OpenServiceController::class, 'renewPreview']);
});

Route::middleware(['api.key:services,write'])->group(function (): void {
    Route::post('/services/{service}/power', [OpenServiceController::class, 'power']);
    Route::post('/services/{service}/renew', [OpenServiceController::class, 'renew']);
    Route::post('/services/{service}/reinstall', [OpenServiceController::class, 'reinstall']);
});

Route::middleware(['api.key:finance,read'])->group(function (): void {
    Route::get('/balance', [OpenFinanceController::class, 'balance']);
});

Route::middleware(['api.key'])->group(function (): void {
    Route::get('/keys/self', [OpenServiceController::class, 'selfKey']);
    Route::post('/keys/self/disable', [OpenServiceController::class, 'disableSelfKey']);
});
