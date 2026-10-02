<?php

declare(strict_types=1);

use App\Http\Controllers\ZjmfUpstream\AuthController;
use App\Http\Controllers\ZjmfUpstream\CartController;
use App\Http\Controllers\ZjmfUpstream\CreditController;
use App\Http\Controllers\ZjmfUpstream\DcimController;
use App\Http\Controllers\ZjmfUpstream\HostController;
use App\Http\Controllers\ZjmfUpstream\ProductController;
use App\Http\Controllers\ZjmfUpstream\ProvisionController;
use App\Http\Controllers\ZjmfUpstream\PushController;
use App\Http\Controllers\ZjmfUpstream\TicketController;
use App\Http\Controllers\ZjmfUpstream\UpgradeController;
use Illuminate\Support\Facades\Route;

/**
 * 上游服务商 API（被魔方财务对接）。
 *
 * 魔方财务在「上游」里把 API 地址配成 {站点}/api/v2/zjmf，
 * 其 zjmfCurl 会把登录拼为 /api/v2/zjmf/zjmf_api_login，业务请求拼为
 * /api/v2/zjmf/{path}。所有接口固定返回 HTTP 200，业务状态放在 body.status：
 * 200 成功 / 400 业务失败 / 405 JWT 失效（触发魔方财务强制重登）。
 */

// 登录：换取 JWT（免鉴权，单独限流防爆破）
Route::post('/zjmf_api_login', [AuthController::class, 'login'])
    ->middleware('throttle:20,1');

// 其余业务接口统一走 zjmf.upstream 鉴权中间件。
// 限流放在鉴权之后：中间件顺序即数组顺序，先解析 JWT 得到对接账号（限流按账号+IP
// 计数），再执行限流；未认证请求在前一步已被拒，不会消耗配额。
//
// 读接口（商品/列表/详情/状态查询）挂全局阈值；写接口（下单、余额支付、电源操作、
// 续费、重装、扣费、工单投递等涉及真实扣费与不可逆操作的端点）单独收紧阈值，
// 与开放接口 open-api / open-api-write 的两级设计对齐。
Route::middleware(['zjmf.upstream', 'throttle:zjmf-upstream-api'])->group(function (): void {
    // P2 商品
    Route::get('/cart/all', [ProductController::class, 'all']);
    Route::get('/api/product/proinfo', [ProductController::class, 'proInfo']);
    Route::get('/api/product/prodetail', [ProductController::class, 'proDetail']);
    Route::get('/cart/get_product_config', [ProductController::class, 'config']);
    Route::get('/cart/ontrialmax', [ProductController::class, 'trialLimit']);
    // 下游管理端面板（上游余额 / 上游信息 / 下游汇总）
    Route::get('/cart/credit', [CartController::class, 'credit']);
    Route::get('/cart/hostinfo', [CartController::class, 'hostInfo']);
    Route::get('/cart/summary', [CartController::class, 'summary']);
    Route::get('/user_info', [CartController::class, 'userInfo']);
    Route::get('/provision/chart/{id}', [PushController::class, 'chart']);
    Route::get('/host/header', [HostController::class, 'header']);
    Route::get('/dcim/traffic_usage', [DcimController::class, 'trafficUsage']);
    Route::get('/host/trafficusage', [DcimController::class, 'trafficUsage']);
    Route::get('/dcim/resintall_status', [DcimController::class, 'reinstallStatus']);
    Route::get('/dcim/detail', [DcimController::class, 'detail']);
});

Route::middleware(['zjmf.upstream', 'throttle:zjmf-upstream-write'])->group(function (): void {
    // P3 购物车/下单/开通
    Route::post('/cart/clear', [CartController::class, 'clear']);
    Route::post('/cart/add_to_shop', [CartController::class, 'addToShop']);
    Route::post('/cart/settle', [CartController::class, 'settle']);
    Route::post('/provision/default', [ProvisionController::class, 'execute']);
    Route::post('/provision/custom/{id}', [PushController::class, 'provisionCustom']);
    Route::post('/provision/button', [ProvisionController::class, 'button']);

    // P4 host
    Route::post('/host/renew', [HostController::class, 'renew']);
    Route::post('/host/cancel', [HostController::class, 'cancel']);
    // 下游管理端手工改绑上游主机后重新登记回推目标
    Route::post('/host/setdownstream', [HostController::class, 'setDownstream']);
    // 自定义 tab 内容（下游按 host/header 下发的 module_client_area.key 取值）
    Route::post('/zjmf_api/provision/custom/content', [HostController::class, 'customContent']);

    // P5 dcim 控制
    Route::post('/dcim/on', [DcimController::class, 'on']);
    Route::post('/dcim/off', [DcimController::class, 'off']);
    Route::post('/dcim/reboot', [DcimController::class, 'reboot']);
    Route::post('/dcim/traffic', [DcimController::class, 'traffic']);
    Route::post('/dcim/kvm', [DcimController::class, 'kvm']);
    Route::post('/dcim/ikvm', [DcimController::class, 'ikvm']);
    Route::post('/dcim/bmc', [DcimController::class, 'bmc']);
    Route::post('/dcim/novnc', [DcimController::class, 'novnc']);
    Route::post('/dcim/rescue', [DcimController::class, 'rescue']);
    Route::post('/dcim/crack_pass', [DcimController::class, 'crackPass']);
    Route::post('/dcim/reinstall', [DcimController::class, 'reinstall']);
    Route::post('/dcim/cancel_task', [DcimController::class, 'cancelTask']);
    Route::post('/dcim/refresh_power_status', [DcimController::class, 'refreshPowerStatus']);
    Route::post('/dcim/refresh_all_power_status', [DcimController::class, 'refreshAllPowerStatus']);
    Route::post('/dcim/hide_result', [DcimController::class, 'hideResult']);
    Route::post('/dcim/check_reinstall', [DcimController::class, 'checkReinstall']);
    Route::post('/dcim/buy_reinstall_times', [DcimController::class, 'buyReinstallTimes']);
    Route::post('/dcim/buy_flow_packet', [DcimController::class, 'buyFlowPacket']);

    // P6 升级/余额/推送
    Route::post('/upgrade/upgrade_config_post', [UpgradeController::class, 'configPost']);
    Route::post('/upgrade/checkout_config_upgrade', [UpgradeController::class, 'checkoutConfig']);
    Route::post('/upgrade/upgrade_product_post', [UpgradeController::class, 'productPost']);
    Route::post('/upgrade/checkout_upgrade_product', [UpgradeController::class, 'checkoutProduct']);
    Route::post('/apply_credit', [CreditController::class, 'applyCredit']);
    Route::post('/apply_credit_limit', [CreditController::class, 'applyCreditLimit']);
    // 下游工单投递（ticketDeliver / ticketReplyDeliver）
    Route::post('/ticket/create', [TicketController::class, 'create']);
    Route::post('/ticket/reply', [TicketController::class, 'reply']);
    Route::post('/api/ticket_reply/sync', [PushController::class, 'ticketReplySync']);
    Route::post('/upload_image', [PushController::class, 'uploadImage']);
});
