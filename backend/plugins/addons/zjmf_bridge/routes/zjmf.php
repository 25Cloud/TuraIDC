<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use TuraIDC\Plugins\Addons\ZjmfBridge\Http\Controllers\ZjmfBridge\AddonController;

$zjmfAddon = [AddonController::class, 'handle'];

Route::match(['GET', 'POST'], '/', $zjmfAddon);
Route::get('/health', $zjmfAddon)->name('health');
Route::get('/ping', $zjmfAddon)->name('ping');

Route::post('/zjmf_api_login', $zjmfAddon)->name('auth.api_login');

Route::middleware(['zjmf.signature:system.health'])->group(function (): void {
    Route::get('/system/health', [AddonController::class, 'handle'])->name('system.health');
});

Route::middleware(['zjmf.signature:system.reconcile'])->group(function (): void {
    Route::get('/reconcile/payments', [AddonController::class, 'handle'])->name('reconcile.payments');
    Route::get('/reconcile/invoices', [AddonController::class, 'handle'])->name('reconcile.invoices');
});

Route::post('/login_api', $zjmfAddon)
    ->middleware(['zjmf.signature:auth.login'])
    ->name('auth.login');

Route::get('/user', $zjmfAddon)
    ->middleware(['zjmf.client:client.read'])
    ->name('user.show');

Route::middleware(['zjmf.client:finance.read'])->group(function (): void {
    Route::get('/invoices', [AddonController::class, 'handle'])->name('invoices.index');
    Route::get('/invoices/{id}', [AddonController::class, 'handle'])->whereNumber('id')->name('invoices.show');
    Route::get('/invoices/{id}/status', [AddonController::class, 'handle'])->whereNumber('id')->name('invoices.status');
    Route::get('/transactions/funds', [AddonController::class, 'handle'])->name('transactions.funds');
});

Route::post('/invoices/{id}/fund', $zjmfAddon)
    ->middleware(['zjmf.client:finance.write'])
    ->whereNumber('id')
    ->name('invoices.fund');

Route::middleware(['zjmf.client:payment.read'])->group(function (): void {
    Route::get('/funds', [AddonController::class, 'handle'])->name('funds.index');
    Route::get('/payments', [AddonController::class, 'handle'])->name('payments.index');
    Route::get('/payments/{id}', [AddonController::class, 'handle'])->whereNumber('id')->name('payments.show');
});

Route::post('/funds', $zjmfAddon)
    ->middleware(['zjmf.client:payment.write'])
    ->name('funds.store');

Route::middleware(['zjmf.client:service.read'])->group(function (): void {
    Route::get('/hosts', [AddonController::class, 'handle'])->name('hosts.index');
    Route::get('/hosts/{id}', [AddonController::class, 'handle'])->whereNumber('id')->name('hosts.show');
});

Route::middleware(['zjmf.client:ticket.read'])->group(function (): void {
    Route::get('/tickets', [AddonController::class, 'handle'])->name('tickets.index');
    Route::get('/tickets/page', [AddonController::class, 'handle'])->name('tickets.page');
    Route::get('/tickets/{id}', [AddonController::class, 'handle'])->whereNumber('id')->name('tickets.show');
});

Route::middleware(['zjmf.client:ticket.write'])->group(function (): void {
    Route::post('/tickets', [AddonController::class, 'handle'])->name('tickets.store');
    Route::post('/tickets/{id}/reply', [AddonController::class, 'handle'])->whereNumber('id')->name('tickets.reply');
    Route::post('/tickets/{id}/close', [AddonController::class, 'handle'])->whereNumber('id')->name('tickets.close');
});

Route::middleware(['zjmf.signature:product.read'])->group(function (): void {
    Route::get('/products', [AddonController::class, 'handle'])->name('products.index');
    Route::get('/productsconfig', [AddonController::class, 'handle'])->name('products.config');
    Route::post('/products/total', [AddonController::class, 'handle'])->name('products.total');
    Route::get('/hosts/cates', [AddonController::class, 'handle'])->name('hosts.cates');
});

Route::middleware(['zjmf.client:client.read'])->group(function () use ($zjmfAddon): void {
    Route::get('/cart/all', $zjmfAddon)->name('cart.all');
    Route::get('/cart/get_product_config', $zjmfAddon)->name('cart.get_product_config');
    Route::get('/api/product/proinfo', $zjmfAddon)->name('api.product.proinfo');
    Route::get('/api/product/prodetail', $zjmfAddon)->name('api.product.prodetail');
});

Route::get('/cart/credit', $zjmfAddon)
    ->middleware(['zjmf.client:payment.read'])
    ->name('cart.credit');
