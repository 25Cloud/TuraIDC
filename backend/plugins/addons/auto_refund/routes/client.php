<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use TuraIDC\Plugins\Addons\AutoRefund\Http\Controllers\Client\AutoRefundController;

/*
|--------------------------------------------------------------------------
| 自助退款：客户端接口
|--------------------------------------------------------------------------
|
| 外层前缀 api/v2/client 与 ['api','auth:sanctum','ensure.client'] 由
| AutoRefundServiceProvider 统一挂载；本文件只声明路径与只属于本插件的限流名。
|
| 客户端只能看到/操作自己的数据：控制器一律按 $request->user()->id 过滤，
| 服务层再按 user_id 复查一遍。
|
*/

Route::get('/auto-refund/bootstrap', [AutoRefundController::class, 'bootstrap']);
Route::get('/auto-refund/candidates', [AutoRefundController::class, 'candidates']);
Route::get('/auto-refund/applications', [AutoRefundController::class, 'index']);
Route::post('/auto-refund/applications', [AutoRefundController::class, 'store'])
    ->middleware('throttle:10,1,client-auto-refund-submit');
Route::post('/auto-refund/applications/{application}/cancel', [AutoRefundController::class, 'cancel'])
    ->whereNumber('application')
    ->middleware('throttle:20,1,client-auto-refund-cancel');
