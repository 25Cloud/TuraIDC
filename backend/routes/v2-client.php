<?php

use App\Http\Controllers\Client\V2\ActionController;
use App\Http\Controllers\Client\V2\AuthController;
use App\Http\Controllers\Client\V2\ContentController;
use App\Http\Controllers\Client\V2\CouponController;
use App\Http\Controllers\Client\V2\FinanceController;
use App\Http\Controllers\Client\V2\FinanceLedgerController;
use App\Http\Controllers\Client\V2\InvoiceController;
use App\Http\Controllers\Client\V2\InvoiceWorkflowController;
use App\Http\Controllers\Client\V2\LedgerController;
use App\Http\Controllers\Client\V2\NotificationController;
use App\Http\Controllers\Client\V2\OrderController;
use App\Http\Controllers\Client\V2\PaymentCallbackController;
use App\Http\Controllers\Client\V2\PaymentController;
use App\Http\Controllers\Client\V2\RechargeController;
use App\Http\Controllers\Client\V2\ReferralController;
use App\Http\Controllers\Client\V2\ServiceConsoleController;
use App\Http\Controllers\Client\V2\ServiceController;
use App\Http\Controllers\Client\V2\TicketController;
use App\Http\Controllers\Client\V2\TicketWorkflowController;
use App\Http\Controllers\Client\V2\VerificationController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1,client-auth-login');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1,client-auth-register');
Route::get('/auth/captcha-config', [AuthController::class, 'captchaConfig'])
    ->middleware('throttle:30,1,v2-client-captcha-config');
Route::get('/auth/captcha-script', [AuthController::class, 'captchaScript'])
    ->middleware('throttle:10,1,v2-client-captcha-script');
Route::post('/auth/login-as/exchange', [AuthController::class, 'exchangeLoginAsCode'])->middleware('throttle:10,1,client-auth-login-as');
Route::post('/auth/phone-code', [AuthController::class, 'sendPhoneCode'])->middleware('throttle:3,1,client-auth-phone-code');
Route::post('/auth/email-code', [AuthController::class, 'sendEmailCode'])->middleware('throttle:3,1,client-auth-email-code');
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1,client-auth-reset-password');
Route::post('/auth/login-by-code', [AuthController::class, 'loginByCode'])->middleware('throttle:5,1,client-auth-login-by-code');
Route::match(['GET', 'POST'], '/verification/callback', [VerificationController::class, 'callback'])->middleware('verify.callback');
Route::get('/verification/scan', [VerificationController::class, 'scan']);
// 支付回调为未认证入口：限流放在验签之前，避免高频请求打穿验签与 DB 写入。
// 60/分钟按 IP 计数，足以覆盖网关正常重试（支付宝异步通知最多 8 次递增退避），
// 又能挡住脚本化高频刷回调造成的写放大。
Route::post('/payment/alipay/notify', [PaymentCallbackController::class, 'alipayNotify'])
    ->middleware(['throttle:60,1,client-payment-alipay-notify', 'verify.alipay.callback']);
Route::match(['GET', 'POST'], '/payment/notify/{gateway}', [PaymentCallbackController::class, 'notify'])
    ->middleware(['throttle:60,1,client-payment-notify', 'verify.payment.callback']);
Route::get('/vnc-tokens/{token}', [ServiceConsoleController::class, 'vncToken'])->middleware('throttle:30,1,client-vnc-token');

Route::middleware(['auth:sanctum', 'ensure.client'])->group(function (): void {
    Route::get('/auth/info', [AuthController::class, 'info']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::put('/password', [AuthController::class, 'updatePassword']);
    Route::put('/auth/profile', [AuthController::class, 'updateProfile']);
    Route::get('/auth/alipay-account', [AuthController::class, 'alipayAccount']);
    Route::put('/auth/alipay-account', [AuthController::class, 'updateAlipayAccount']);
    Route::get('/auth/notification-preferences', [AuthController::class, 'notificationPreferences']);
    Route::put('/auth/notification-preferences', [AuthController::class, 'updateNotificationPreferences']);
    Route::put('/auth/phone', [AuthController::class, 'updatePhone']);
    Route::put('/auth/email', [AuthController::class, 'updateEmail']);

    Route::get('/verification/fee-config', [VerificationController::class, 'feeConfig']);
    Route::post('/verification/init', [VerificationController::class, 'init']);
    Route::post('/verification/qrcode', [VerificationController::class, 'qrcode']);
    Route::post('/verification/close', [VerificationController::class, 'close']);
    Route::get('/verification/status', [VerificationController::class, 'status']);
    Route::post('/verification/restart', [VerificationController::class, 'restart']);

    Route::get('/balance-logs', [FinanceController::class, 'balanceLogs']);
    Route::get('/balance-logs/summary', [FinanceController::class, 'balanceLogsSummary']);
    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::get('/invoices/summary', [InvoiceWorkflowController::class, 'summary']);
    Route::post('/invoices', [InvoiceWorkflowController::class, 'store'])->middleware('throttle:8,1,client-invoices-store');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);
    Route::post('/invoices/{invoice}/cancellations', [ActionController::class, 'cancelInvoice'])
        ->middleware('throttle:10,1,client-invoices-cancel');
    Route::post('/invoices/{id}/pay/balance', [InvoiceWorkflowController::class, 'payByBalance'])->middleware('throttle:10,1,client-invoices-pay-balance');
    Route::post('/invoices/{id}/pay/mix', [InvoiceWorkflowController::class, 'payByBalanceAndAlipay'])->middleware('throttle:10,1,client-invoices-pay-mix');
    Route::post('/invoices/{id}/pay/alipay', [InvoiceWorkflowController::class, 'payByAlipay'])->middleware('throttle:12,1,client-invoices-pay-alipay');
    Route::get('/invoices/{id}/pay/alipay/status', [InvoiceWorkflowController::class, 'queryAlipayStatus'])->middleware('throttle:30,1,client-invoices-pay-alipay-status');

    Route::get('/ledger', [LedgerController::class, 'index']);
    Route::get('/finance/ledger', [FinanceLedgerController::class, 'index']);
    Route::get('/finance/ledger/summary', [FinanceLedgerController::class, 'summary']);
    Route::get('/finance/ledger/{id}', [FinanceLedgerController::class, 'show']);

    Route::get('/coupons', [CouponController::class, 'index']);
    Route::get('/coupons/summary', [CouponController::class, 'summary']);
    Route::get('/coupons/public', [CouponController::class, 'publicIndex']);
    Route::get('/coupons/public/summary', [CouponController::class, 'publicSummary']);
    Route::post('/coupons/{couponId}/claim', [CouponController::class, 'claim'])->middleware('throttle:6,1,client-coupons-claim');

    Route::get('/recharge/gateways', [RechargeController::class, 'gateways']);
    Route::post('/recharge', [RechargeController::class, 'store'])->middleware('throttle:6,1,client-recharge-store');
    Route::get('/recharge/{paymentNo}/status', [RechargeController::class, 'status'])->middleware('throttle:30,1,client-recharge-status');

    Route::get('/payments', [PaymentController::class, 'index']);
    Route::get('/payments/summary', [PaymentController::class, 'summary']);
    Route::get('/payments/{id}', [PaymentController::class, 'show']);

    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/summary', [OrderController::class, 'summary']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);
    Route::post('/orders/{order}/cancellations', [ActionController::class, 'cancelOrder'])
        ->middleware('throttle:10,1,client-orders-cancel');

    Route::get('/content/overview', [ContentController::class, 'overview']);
    Route::get('/notices', [ContentController::class, 'notices']);
    Route::get('/notices/unread-count', [ContentController::class, 'noticeUnreadCount']);
    Route::post('/notices/mark-all-read', [ContentController::class, 'markAllNoticesRead']);
    Route::get('/notices/{article}', [ContentController::class, 'noticeDetail']);
    Route::put('/notices/{article}/read-state', [ActionController::class, 'markNoticeRead']);
    Route::get('/help-articles', [ContentController::class, 'helpArticles']);
    Route::get('/help-articles/{article}', [ContentController::class, 'helpDetail']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::get('/notifications/feed', [NotificationController::class, 'feed']);
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllRead']);
    Route::put('/notifications/{notification}/read-state', [ActionController::class, 'markNotificationRead']);

    Route::get('/referral/overview', [ReferralController::class, 'overview']);
    Route::get('/referral/rewards', [ReferralController::class, 'rewards']);
    Route::get('/referral/account-logs', [ReferralController::class, 'accountLogs']);
    Route::get('/referral/withdrawals', [ReferralController::class, 'withdrawals']);
    Route::get('/referral/direct-referrals', [ReferralController::class, 'directReferrals']);
    Route::post('/referral/withdrawals', [ReferralController::class, 'applyWithdrawal'])->middleware('throttle:3,1,client-referral-withdraw');

    Route::get('/services', [ServiceConsoleController::class, 'index']);
    Route::get('/services/grouped-overview', [ServiceConsoleController::class, 'groupedOverview']);
    Route::get('/services/{id}/config', [ServiceConsoleController::class, 'config']);
    Route::put('/services/{id}/name', [ServiceConsoleController::class, 'updateName']);
    Route::put('/services/{id}/remark', [ServiceConsoleController::class, 'updateRemark']);
    Route::get('/services/{id}/traffic-packages', [ServiceConsoleController::class, 'trafficPackages']);
    Route::post('/services/{id}/traffic-packages/quote', [ServiceConsoleController::class, 'quoteTrafficPackage'])->middleware('throttle:12,1,client-service-traffic-package-quote');
    Route::post('/services/{id}/traffic-packages/orders', [ServiceConsoleController::class, 'createTrafficPackageOrder'])->middleware('throttle:6,1,client-service-traffic-package-order');
    Route::get('/services/{id}/upgrades', [ServiceConsoleController::class, 'hostUpgradePreview']);
    Route::post('/services/{id}/upgrades/quotes', [ServiceConsoleController::class, 'quoteHostUpgrade'])->middleware('throttle:12,1,client-service-host-upgrade-quote');
    Route::post('/services/{id}/upgrades/orders', [ServiceConsoleController::class, 'createHostUpgradeOrder'])->middleware('throttle:6,1,client-service-host-upgrade-order');
    Route::get('/services/{id}/renewals', [ServiceConsoleController::class, 'renewPreview']);
    Route::post('/services/{id}/renewals', [ServiceConsoleController::class, 'createRenewOrder'])->middleware('throttle:6,1,client-service-renew');
    Route::put('/services/{id}/renewals/auto', [ServiceConsoleController::class, 'updateAutoRenew'])->middleware('throttle:6,1,client-service-renew-auto');
    Route::get('/services/{id}/module-status', [ServiceConsoleController::class, 'moduleStatus']);
    Route::get('/services/{id}/reinstallations/options', [ServiceConsoleController::class, 'reinstallOptions']);
    Route::get('/services/{id}/operation-logs', [ServiceConsoleController::class, 'operationLogs']);
    Route::get('/services/{id}/monitor', [ServiceConsoleController::class, 'monitor']);
    Route::get('/services/{id}/monitor/batch', [ServiceConsoleController::class, 'monitorBatch']);
    Route::get('/services/{id}/nat-forwardings', [ServiceConsoleController::class, 'natForwardings']);
    Route::post('/services/{id}/nat-forwardings', [ServiceConsoleController::class, 'createNatForwarding'])->middleware('throttle:10,1,client-service-nat-create');
    Route::delete('/services/{id}/nat-forwardings/{forwardingId}', [ServiceConsoleController::class, 'deleteNatForwarding'])->middleware('throttle:10,1,client-service-nat-delete');
    Route::get('/services/{id}/security-groups', [ServiceConsoleController::class, 'securityGroups']);
    Route::post('/services/{id}/security-groups', [ServiceConsoleController::class, 'createSecurityGroup'])->middleware('throttle:10,1,client-service-security-group-create');
    Route::get('/services/{id}/security-groups/{groupId}/rules', [ServiceConsoleController::class, 'securityGroupRules']);
    Route::post('/services/{id}/security-groups/{groupId}/apply', [ServiceConsoleController::class, 'applySecurityGroup'])->middleware('throttle:10,1,client-service-security-group-apply');
    Route::delete('/services/{id}/security-groups/{groupId}', [ServiceConsoleController::class, 'deleteSecurityGroup'])->middleware('throttle:10,1,client-service-security-group-delete');
    Route::post('/services/{id}/security-groups/{groupId}/rules', [ServiceConsoleController::class, 'createSecurityRule'])->middleware('throttle:10,1,client-service-security-rule-create');
    Route::delete('/services/{id}/security-groups/{groupId}/rules/{ruleId}', [ServiceConsoleController::class, 'deleteSecurityRule'])->middleware('throttle:10,1,client-service-security-rule-delete');
    Route::get('/services/{id}/vnc', [ServiceConsoleController::class, 'vnc']);
    Route::get('/services/{service}/connection', [ServiceController::class, 'connection']);
    Route::get('/services/{service}/runtime', [ServiceController::class, 'runtime']);
    Route::get('/services/{service}', [ServiceController::class, 'show']);
    Route::post('/services/{service}/power-actions', [ActionController::class, 'power'])
        ->middleware('throttle:10,1,client-service-power');
    Route::post('/services/{service}/password-resets', [ActionController::class, 'resetPassword'])
        ->middleware('throttle:6,1,client-service-password-reset');
    Route::post('/services/{service}/reinstallations', [ActionController::class, 'reinstall'])
        ->middleware('throttle:6,1,client-service-reinstall');

    Route::get('/tickets', [TicketWorkflowController::class, 'index']);
    Route::get('/tickets/service-options', [TicketWorkflowController::class, 'serviceOptions']);
    Route::post('/tickets', [TicketWorkflowController::class, 'store'])->middleware('throttle:10,1,client-ticket-store');
    Route::post('/tickets/upload-images', [TicketWorkflowController::class, 'uploadImage'])->middleware('throttle:12,1,client-ticket-upload-image');
    Route::get('/tickets/{ticket}/replies', [TicketController::class, 'replies']);
    Route::get('/tickets/{ticket}', [TicketController::class, 'show']);
    Route::post('/tickets/{id}/replies', [TicketWorkflowController::class, 'reply'])->middleware('throttle:10,1,client-ticket-reply');
    Route::post('/tickets/{ticket}/replies/{reply}/recalls', [ActionController::class, 'recallTicketReply'])
        ->middleware('throttle:10,1,client-ticket-recall');
    Route::post('/tickets/{id}/closures', [TicketWorkflowController::class, 'close'])->middleware('throttle:10,1,client-ticket-close');
    Route::post('/tickets/{id}/reopen', [TicketWorkflowController::class, 'reopen'])->middleware('throttle:10,1,client-ticket-reopen');
});
