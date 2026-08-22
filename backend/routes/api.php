<?php

use App\Http\Controllers\Client\V2\TicketUpstreamCallbackController;
use App\Http\Controllers\Site\V2\ContentController as V2SiteContentController;
use App\Http\Controllers\Site\V2\HomeController as V2SiteHomeController;
use App\Http\Controllers\Site\V2\ProductController as V2SiteProductController;
use App\Http\Controllers\Site\V2\ProductGroupController as V2SiteProductGroupController;
use App\Http\Controllers\System\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 主路由 - 注册子路由
|--------------------------------------------------------------------------
*/

Route::get('/v2/site/product-groups', [V2SiteProductGroupController::class, 'index']);
Route::get('/v2/site/product-groups/{group}/children', [V2SiteProductGroupController::class, 'children']);
Route::get('/v2/site/product-groups/{group}/products', [V2SiteProductGroupController::class, 'products']);
Route::get('/v2/site/product-types', [V2SiteProductController::class, 'types']);
Route::get('/v2/site/products', [V2SiteProductController::class, 'index']);
Route::get('/v2/site/products/{product}/stock', [V2SiteProductController::class, 'stock']);
Route::post('/v2/site/products/{product}/quote', [V2SiteProductController::class, 'quote'])->middleware('throttle:60,1');
Route::get('/v2/site/products/{product}', [V2SiteProductController::class, 'show']);
Route::get('/v2/site/product-purchase-context', [V2SiteProductController::class, 'purchaseContext']);
Route::get('/v2/site/config', [V2SiteHomeController::class, 'config']);
Route::get('/v2/site/home', [V2SiteHomeController::class, 'home']);
Route::get('/v2/site/home-hero', [V2SiteHomeController::class, 'hero']);
Route::get('/v2/site/content/overview', [V2SiteContentController::class, 'overview']);
Route::get('/v2/site/notices', [V2SiteContentController::class, 'notices']);
Route::get('/v2/site/notices/{article}', [V2SiteContentController::class, 'noticeDetail']);
Route::get('/v2/site/help-articles', [V2SiteContentController::class, 'helpArticles']);
Route::get('/v2/site/help-articles/{article}', [V2SiteContentController::class, 'helpDetail']);
Route::get('/health', [HealthController::class, 'live']);
Route::get('/ready', [HealthController::class, 'ready']);
Route::post('/ticket_reply/sync', [TicketUpstreamCallbackController::class, 'reply'])
    ->middleware(['throttle:60,1,ticket-upstream-callback', 'verify.ticket.upstream.callback']);
