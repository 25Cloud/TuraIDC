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

Route::middleware(['api.key:orders,write', 'throttle:open-api-write'])->group(function (): void {
    Route::post('/orders', [OpenOrderController::class, 'store']);
    Route::post('/orders/{invoice}/pay', [OpenOrderController::class, 'payByBalance']);
});

Route::middleware(['api.key:services,read'])->group(function (): void {
    Route::get('/services', [OpenServiceController::class, 'index']);
    Route::get('/services/{service}', [OpenServiceController::class, 'show']);
    Route::get('/services/{service}/renewals', [OpenServiceController::class, 'renewPreview']);
    Route::get('/services/{service}/reinstall-options', [OpenServiceController::class, 'reinstallOptions']);
    // 配置项升级预览 / 流量包档位：纯读，复用 services,read
    Route::get('/services/{service}/upgrades', [OpenServiceController::class, 'upgrades']);
    Route::get('/services/{service}/flow-packages', [OpenServiceController::class, 'flowPackages']);
});

Route::middleware(['api.key:services,write', 'throttle:open-api-write'])->group(function (): void {
    Route::post('/services/{service}/power', [OpenServiceController::class, 'power']);
    Route::post('/services/{service}/renew', [OpenServiceController::class, 'renew']);
    Route::post('/services/{service}/reinstall', [OpenServiceController::class, 'reinstall']);
    // 升级报价 / 升级账单 / 流量包账单：都会产生可支付的账单，按写端点限流
    Route::post('/services/{service}/upgrades/quotes', [OpenServiceController::class, 'quoteUpgrade']);
    Route::post('/services/{service}/upgrades/orders', [OpenServiceController::class, 'createUpgradeOrder']);
    Route::post('/services/{service}/flow-packages/orders', [OpenServiceController::class, 'createFlowPackageOrder']);
});

Route::middleware(['api.key:finance,read'])->group(function (): void {
    Route::get('/balance', [OpenFinanceController::class, 'balance']);
});

Route::middleware(['api.key'])->group(function (): void {
    Route::get('/keys/self', [OpenServiceController::class, 'selfKey']);
    Route::post('/keys/self/disable', [OpenServiceController::class, 'disableSelfKey']);
});
