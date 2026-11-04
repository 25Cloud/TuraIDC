<?php

declare(strict_types=1);

use App\Support\AdminPermissions;
use Illuminate\Support\Facades\Route;
use TuraIDC\Plugins\Addons\AutoRefund\Http\Controllers\Admin\AutoRefundAdminController;

/*
|--------------------------------------------------------------------------
| 自助退款：管理端接口
|--------------------------------------------------------------------------
|
| 外层前缀 api/v2/admin 与 ['api','auth:sanctum','ensure.admin'] 由
| AutoRefundServiceProvider 统一挂载；这里按管理端既有权限码分组：
|
| - 台账查询（invoice.list）：能看发票列表的人就能看退款台账；
| - 结算/驳回/作废（invoice.manage）：会动钱的动作与「账单管理」同权；
| - 上游连通性/对账（integration_plugin.test）：属于插件治理能力。
|
| 商品退款策略（第二阶段新增）：查询走 invoice.list、写入走 invoice.manage，
| 与台账保持同一批人——能签退款的人本来就该能决定哪些商品可退、怎么退。
|
| 不新造权限码：权限码目录在核心 AdminPermissions 里，插件不能自行注册权限
| （插件规范第 9 节），新增权限码需要核心改动与回填迁移，由维护者统一安排。
|
*/

Route::middleware(['permission:'.AdminPermissions::INVOICE_LIST])->group(function (): void {
    Route::get('/auto-refund/applications', [AutoRefundAdminController::class, 'index']);
    Route::get('/auto-refund/applications/{application}', [AutoRefundAdminController::class, 'show'])
        ->whereNumber('application');
    Route::get('/auto-refund/product-rules', [AutoRefundAdminController::class, 'productRules']);
});

Route::middleware(['permission:'.AdminPermissions::INVOICE_MANAGE])->group(function (): void {
    Route::post('/auto-refund/applications/{application}/settle', [AutoRefundAdminController::class, 'settle'])
        ->whereNumber('application');
    Route::post('/auto-refund/applications/{application}/reject', [AutoRefundAdminController::class, 'reject'])
        ->whereNumber('application');
    Route::post('/auto-refund/applications/{application}/close', [AutoRefundAdminController::class, 'close'])
        ->whereNumber('application');
    Route::put('/auto-refund/product-rules/{product}', [AutoRefundAdminController::class, 'saveProductRule'])
        ->whereNumber('product');
});

Route::middleware(['permission:'.AdminPermissions::INTEGRATION_PLUGIN_TEST])->group(function (): void {
    Route::post('/auto-refund/upstream/test', [AutoRefundAdminController::class, 'testUpstream']);
    Route::get('/auto-refund/upstream/products', [AutoRefundAdminController::class, 'upstreamProducts']);
});
