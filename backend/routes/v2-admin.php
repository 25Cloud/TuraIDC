<?php

use App\Http\Controllers\Admin\V2\AgentGroupController;
use App\Http\Controllers\Admin\V2\AgentGroupDiscountController;
use App\Http\Controllers\Admin\V2\AuthController;
use App\Http\Controllers\Admin\V2\ContentArticleController;
use App\Http\Controllers\Admin\V2\ContentCategoryController;
use App\Http\Controllers\Admin\V2\CouponCampaignController;
use App\Http\Controllers\Admin\V2\CouponController;
use App\Http\Controllers\Admin\V2\CouponProductGroupController;
use App\Http\Controllers\Admin\V2\CpuModelCatalogController;
use App\Http\Controllers\Admin\V2\DashboardController;
use App\Http\Controllers\Admin\V2\DatabaseStatusController;
use App\Http\Controllers\Admin\V2\FinanceLedgerController;
use App\Http\Controllers\Admin\V2\FinanceMenuController;
use App\Http\Controllers\Admin\V2\HomeHeroController;
use App\Http\Controllers\Admin\V2\InstanceSpecCatalogController;
use App\Http\Controllers\Admin\V2\IntegrationPluginController;
use App\Http\Controllers\Admin\V2\InvoiceController;
use App\Http\Controllers\Admin\V2\LogController;
use App\Http\Controllers\Admin\V2\MediaFileController;
use App\Http\Controllers\Admin\V2\MemberLevelController;
use App\Http\Controllers\Admin\V2\OrderController;
use App\Http\Controllers\Admin\V2\ProductController;
use App\Http\Controllers\Admin\V2\ProductDiscountGroupController;
use App\Http\Controllers\Admin\V2\ProductGroupController;
use App\Http\Controllers\Admin\V2\ProductSpecHighlightController;
use App\Http\Controllers\Admin\V2\ProductTypeController;
use App\Http\Controllers\Admin\V2\ReferralController;
use App\Http\Controllers\Admin\V2\ReferralWithdrawalController;
use App\Http\Controllers\Admin\V2\RoleController;
use App\Http\Controllers\Admin\V2\ScheduleTaskController;
use App\Http\Controllers\Admin\V2\ServiceController;
use App\Http\Controllers\Admin\V2\SettingController;
use App\Http\Controllers\Admin\V2\StaffController;
use App\Http\Controllers\Admin\V2\SupplierController;
use App\Http\Controllers\Admin\V2\TicketController;
use App\Http\Controllers\Admin\V2\TicketDeliveryController;
use App\Http\Controllers\Admin\V2\TicketPreReplyController;
use App\Http\Controllers\Admin\V2\UserController;
use App\Http\Controllers\Admin\V2\UserServiceController;
use App\Http\Controllers\Admin\V2\VerificationController;
use App\Support\AdminPermissions;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1,v2-admin-login');

Route::middleware(['auth:sanctum', 'ensure.admin'])->group(function (): void {
    Route::get('/auth/info', [AuthController::class, 'info']);
    Route::put('/auth/profile', [AuthController::class, 'updateProfile']);
    Route::put('/auth/password', [AuthController::class, 'updatePassword']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::middleware(['permission:'.AdminPermissions::DASHBOARD_VIEW])->group(function (): void {
        Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
        Route::get('/dashboard/recent-invoices', [DashboardController::class, 'recentInvoices']);
        Route::get('/dashboard/monthly-revenue', [DashboardController::class, 'monthlyRevenue']);
    });

    Route::middleware(['permission:'.AdminPermissions::ORDER_LIST])->group(function (): void {
        Route::get('/orders', [OrderController::class, 'index']);
    });

    Route::middleware(['permission:'.AdminPermissions::ORDER_DETAIL])->group(function (): void {
        Route::get('/orders/{order}', [OrderController::class, 'show']);
    });

    Route::middleware(['permission:'.AdminPermissions::INVOICE_LIST])->group(function (): void {
        Route::get('/invoices', [InvoiceController::class, 'index']);
        Route::get('/finance/ledger', [FinanceLedgerController::class, 'index']);
        Route::get('/finance/ledger/summary', [FinanceLedgerController::class, 'summary']);
        Route::get('/finance/recharges', [FinanceMenuController::class, 'recharges']);
        Route::get('/finance/renewal-orders', [FinanceMenuController::class, 'renewalOrders']);
        Route::get('/finance/upgrade-orders', [FinanceMenuController::class, 'upgradeOrders']);
    });

    Route::middleware(['permission:'.AdminPermissions::INVOICE_DETAIL])->group(function (): void {
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);
        Route::get('/finance/ledger/{id}', [FinanceLedgerController::class, 'show']);
    });

    Route::middleware(['permission:'.AdminPermissions::INVOICE_MANAGE])->group(function (): void {
        Route::post('/invoices/{invoice}/cancellations', [InvoiceController::class, 'cancel']);
        Route::post('/users/{user}/invoices/{invoice}/refunds', [UserController::class, 'refundInvoice']);
    });

    Route::middleware(['permission:'.AdminPermissions::FINANCE_REPORT])->group(function (): void {
        Route::get('/finance/new-customer-daily-summary', [FinanceMenuController::class, 'newCustomerDailySummary']);
        Route::get('/finance/product-income-summary', [FinanceMenuController::class, 'productIncomeSummary']);
    });

    Route::middleware(['permission:'.AdminPermissions::USER_LIST])->group(function (): void {
        Route::get('/users', [UserController::class, 'index']);
    });

    Route::middleware(['permission:'.AdminPermissions::USER_DETAIL])->group(function (): void {
        Route::get('/users/{user}', [UserController::class, 'show']);
        Route::get('/users/{user}/services', [UserServiceController::class, 'index']);
        Route::get('/users/{user}/invoices', [UserController::class, 'invoices']);
        Route::get('/users/{user}/invoices/{invoice}', [UserController::class, 'invoiceDetail']);
        Route::get('/users/{user}/balance-logs', [UserController::class, 'balanceLogs']);
        Route::get('/users/{user}/tickets', [UserController::class, 'tickets']);
        Route::get('/users/{user}/operation-logs', [UserController::class, 'operationLogs']);
        Route::get('/users/{user}/sms-logs', [UserController::class, 'smsLogs']);
        Route::get('/users/{user}/email-logs', [UserController::class, 'emailLogs']);
        Route::get('/users/{user}/services/{service}/connection', [UserServiceController::class, 'connection']);
        Route::get('/users/{user}/services/{service}/remote-status', [UserServiceController::class, 'remoteStatus']);
        Route::get('/users/{user}/services/{service}', [UserServiceController::class, 'show']);
    });

    Route::middleware(['permission:'.AdminPermissions::USER_MANAGE])->group(function (): void {
        Route::get('/os-options', [UserController::class, 'osOptions']);
        Route::post('/users', [UserController::class, 'store']);
        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::delete('/users/{user}', [UserController::class, 'destroy']);
        Route::patch('/users/{user}/status', [UserController::class, 'updateStatus']);
        Route::post('/services/custom-hostnames/batch', [ServiceController::class, 'batchUpdateCustomHostnames']);
        Route::post('/users/{user}/services', [UserServiceController::class, 'store']);
        Route::post('/users/{user}/services/refresh-statuses', [UserServiceController::class, 'refreshStatuses']);
        Route::put('/users/{user}/services/{service}/meta', [UserServiceController::class, 'updateMeta']);
        Route::put('/users/{user}/services/{service}/manual-provision', [UserServiceController::class, 'manualProvision']);
        Route::delete('/users/{user}/services/{service}', [UserServiceController::class, 'destroy']);
        Route::post('/users/{user}/services/{service}/power-actions', [UserController::class, 'servicePower']);
        Route::post('/users/{user}/services/{service}/password-resets', [UserController::class, 'resetServicePassword']);
        Route::post('/users/{user}/services/{service}/refunds', [UserController::class, 'refundService']);
    });

    Route::middleware(['permission:'.AdminPermissions::USER_LOGIN_AS])->group(function (): void {
        Route::post('/users/{user}/login-as', [UserController::class, 'loginAs']);
    });

    Route::middleware(['permission:'.AdminPermissions::USER_RECHARGE])->group(function (): void {
        Route::post('/users/{user}/recharges', [UserController::class, 'recharge']);
    });

    Route::middleware(['permission:'.AdminPermissions::VERIFICATION_LIST])->group(function (): void {
        Route::get('/verifications', [VerificationController::class, 'index']);
        Route::get('/verifications/summary', [VerificationController::class, 'summary']);
        Route::get('/verifications/{user}/history', [VerificationController::class, 'history']);
        Route::get('/verifications/{user}', [VerificationController::class, 'show']);
    });

    Route::middleware(['permission:'.AdminPermissions::VERIFICATION_UNBIND])->group(function (): void {
        Route::post('/verifications/{user}/unbindings', [VerificationController::class, 'unbind']);
    });

    Route::middleware(['permission:'.AdminPermissions::TICKET_LIST])->group(function (): void {
        Route::get('/tickets/summary', [TicketController::class, 'summary']);
        Route::get('/tickets/admin-users', [TicketController::class, 'adminUsers']);
        Route::get('/tickets', [TicketController::class, 'index']);
        Route::get('/tickets/{ticket}/replies', [TicketController::class, 'replies']);
        Route::get('/tickets/{ticket}/upstream-delivery/logs', [TicketDeliveryController::class, 'ticketLogs']);
        Route::get('/tickets/{ticket}/upstream-delivery', [TicketDeliveryController::class, 'ticketStatus']);
        Route::get('/tickets/{ticket}', [TicketController::class, 'show']);
    });

    Route::middleware(['permission:'.AdminPermissions::TICKET_MANAGE])->group(function (): void {
        Route::post('/tickets/{ticket}/closures', [TicketController::class, 'close']);
        Route::post('/tickets/{ticket}/reopen', [TicketController::class, 'reopen'])->middleware('throttle:10,1,admin-ticket-reopen');
        Route::put('/tickets/{ticket}/assignment', [TicketController::class, 'assign']);
        Route::post('/tickets/{ticket}/upstream-delivery/callback-registration', [TicketDeliveryController::class, 'registerCallback']);
    });

    // 工单传递设置：规则、上游部门与上传防护配置归入独立权限，
    // ticket.delivery_manage 必须显式授予，不再由 ticket.manage 隐含。
    // 存量角色由迁移 2026_08_23_120000_backfill_ticket_delivery_manage_permission 补勾选。
    Route::middleware(['permission:'.AdminPermissions::TICKET_DELIVERY_MANAGE])->group(function (): void {
        Route::get('/ticket-delivery-departments', [TicketDeliveryController::class, 'upstreamDepartments']);
        Route::get('/ticket-delivery-rules', [TicketDeliveryController::class, 'index']);
        Route::post('/ticket-delivery-rules', [TicketDeliveryController::class, 'store']);
        Route::put('/ticket-delivery-rules/{rule}', [TicketDeliveryController::class, 'update']);
        Route::delete('/ticket-delivery-rules/{rule}', [TicketDeliveryController::class, 'destroy']);
        Route::get('/ticket-delivery-upload-guard', [TicketDeliveryController::class, 'uploadGuardConfig']);
        Route::post('/ticket-delivery-upload-guard', [TicketDeliveryController::class, 'saveUploadGuardConfig']);
    });

    // 工单预回复设置：独立权限 ticket.pre_reply_manage，必须显式授予，
    // 不与 ticket.manage 隐含。存量角色由迁移 2026_08_23_130000_backfill_ticket_pre_reply_manage_permission 补勾选。
    Route::middleware(['permission:'.AdminPermissions::TICKET_PRE_REPLY_MANAGE])->group(function (): void {
        Route::get('/ticket-pre-reply-settings', [TicketPreReplyController::class, 'show']);
        Route::post('/ticket-pre-reply-settings', [TicketPreReplyController::class, 'save']);
    });

    Route::middleware(['permission:'.AdminPermissions::TICKET_REPLY])->group(function (): void {
        Route::post('/tickets/upload-images', [TicketController::class, 'uploadImage']);
        Route::post('/tickets/{ticket}/replies', [TicketController::class, 'reply']);
        Route::post('/tickets/{ticket}/replies/{replyId}/recalls', [TicketController::class, 'recall']);
    });

    Route::middleware(['permission:'.AdminPermissions::PRODUCT_LIST])->group(function (): void {
        Route::get('/instance-spec-catalog', [InstanceSpecCatalogController::class, 'index']);
        Route::get('/cpu-model-catalog', [CpuModelCatalogController::class, 'index']);

        Route::get('/coupon-product-groups', [CouponProductGroupController::class, 'index']);
        Route::get('/coupon-product-groups/{group}/children', [CouponProductGroupController::class, 'children']);
        Route::get('/coupon-product-groups/{group}/products', [CouponProductGroupController::class, 'products']);
        Route::post('/coupon-product-groups/batch-products', [CouponProductGroupController::class, 'batchProducts']);
        Route::get('/coupons/summary', [CouponController::class, 'summary']);
        Route::get('/coupons', [CouponController::class, 'index']);
        Route::get('/coupon-campaigns/summary', [CouponCampaignController::class, 'summary']);
        Route::get('/coupon-campaigns', [CouponCampaignController::class, 'index']);

        Route::get('/product-groups', [ProductGroupController::class, 'index']);
        Route::get('/product-groups/tree', [ProductGroupController::class, 'tree']);
        Route::get('/product-groups/{group}/children', [ProductGroupController::class, 'children']);
        Route::get('/product-groups/{group}', [ProductGroupController::class, 'show']);
        Route::get('/product-types', [ProductTypeController::class, 'index']);

        Route::get('/products/summary', [ProductController::class, 'summary']);
        Route::get('/products', [ProductController::class, 'index']);
        Route::get('/products/{product}/owners', [ProductController::class, 'owners']);
        Route::get('/products/{product}/spec-highlight', [ProductSpecHighlightController::class, 'show']);
        Route::get('/products/{product}', [ProductController::class, 'show']);
        Route::get('/services', [ServiceController::class, 'index']);
    });

    Route::middleware(['permission:'.AdminPermissions::PRODUCT_MANAGE])->group(function (): void {
        Route::post('/instance-spec-catalog', [InstanceSpecCatalogController::class, 'update']);
        Route::post('/cpu-model-catalog', [CpuModelCatalogController::class, 'update']);
        Route::post('/product-types/reorders', [ProductTypeController::class, 'reorder']);
        Route::post('/product-types', [ProductTypeController::class, 'store']);
        Route::put('/product-types/{productType}', [ProductTypeController::class, 'update']);
        Route::delete('/product-types/{productType}', [ProductTypeController::class, 'destroy']);
        Route::post('/product-groups/reorders', [ProductGroupController::class, 'reorder']);
        Route::post('/product-groups', [ProductGroupController::class, 'store']);
        Route::put('/product-groups/{group}', [ProductGroupController::class, 'update']);
        Route::delete('/product-groups/{group}', [ProductGroupController::class, 'destroy']);
        Route::delete('/product-groups/{group}/force', [ProductGroupController::class, 'forceDelete']);
        Route::post('/product-groups/{group}/batch-update-products', [ProductGroupController::class, 'batchUpdateProducts']);
        Route::post('/coupons', [CouponController::class, 'store']);
        Route::put('/coupons/{coupon}', [CouponController::class, 'update']);
        Route::delete('/coupons/{coupon}', [CouponController::class, 'destroy']);
        Route::post('/coupon-campaigns', [CouponCampaignController::class, 'store']);
        Route::put('/coupon-campaigns/{couponCampaign}', [CouponCampaignController::class, 'update']);
        Route::delete('/coupon-campaigns/{couponCampaign}', [CouponCampaignController::class, 'destroy']);
        Route::post('/products', [ProductController::class, 'store']);
        Route::put('/products/{product}', [ProductController::class, 'update']);
        Route::delete('/products/{product}', [ProductController::class, 'destroy']);
        Route::post('/products/{product}/restorations', [ProductController::class, 'restore']);
        Route::delete('/products/{product}/force', [ProductController::class, 'forceDelete']);
        Route::post('/products/reorders', [ProductController::class, 'reorder']);
        Route::post('/products/split-previews', [ProductController::class, 'splitPreview']);
        Route::post('/products/splits', [ProductController::class, 'split']);
        Route::post('/products/category-batches', [ProductController::class, 'batchUpdateCategory']);
        Route::post('/products/provision-hostname-batches', [ProductController::class, 'batchUpdateProvisionHostname']);
        Route::post('/products/batch-update', [ProductController::class, 'batchUpdate']);
        Route::post('/products/batch-delete', [ProductController::class, 'batchDelete']);
        Route::post('/products/batch-force-delete', [ProductController::class, 'batchForceDelete']);
        Route::patch('/products/{product}/status', [ProductController::class, 'updateStatus']);
        Route::post('/products/{product}/spec-highlight', [ProductSpecHighlightController::class, 'update']);
        Route::patch('/coupons/{coupon}/status', [CouponController::class, 'updateStatus']);
        Route::patch('/coupon-campaigns/{couponCampaign}/status', [CouponCampaignController::class, 'updateStatus']);
        Route::post('/coupon-campaigns/{couponCampaign}/tasks', [CouponCampaignController::class, 'runTask']);
    });

    Route::middleware(['permission:'.AdminPermissions::PRODUCT_SYNC])->group(function (): void {
        Route::post('/products/traffic-package-pulls', [ProductController::class, 'pullTrafficPackageCatalog']);
    });

    Route::middleware(['permission:'.AdminPermissions::LOG_LIST])->group(function (): void {
        Route::get('/logs/{channel}', [LogController::class, 'index']);
        Route::get('/logs/{channel}/{log}', [LogController::class, 'show']);
        Route::get('/log-summaries/{channel}', [LogController::class, 'summary']);
        Route::get('/log-cleanups/overview', [LogController::class, 'cleanupOverview']);
    });

    Route::middleware(['permission:'.AdminPermissions::LOG_MANAGE])->group(function (): void {
        Route::post('/log-cleanups', [LogController::class, 'cleanup']);
    });

    Route::middleware(['permission:'.AdminPermissions::SCHEDULE_VIEW])->group(function (): void {
        Route::get('/schedules/overview', [ScheduleTaskController::class, 'overview']);
        Route::get('/schedule-runs', [ScheduleTaskController::class, 'index']);
        Route::get('/schedule-runs/{run}', [ScheduleTaskController::class, 'show']);
    });

    Route::middleware(['permission:'.AdminPermissions::SCHEDULE_TRIGGER])->group(function (): void {
        Route::post('/schedule-triggers', [ScheduleTaskController::class, 'trigger']);
    });

    Route::middleware(['permission:'.AdminPermissions::SCHEDULE_RETRY])->group(function (): void {
        Route::post('/schedule-runs/{run}/retry', [ScheduleTaskController::class, 'retry']);
    });

    Route::middleware(['permission:'.AdminPermissions::CONTENT_MANAGE])->group(function (): void {
        Route::post('/site/home-hero', [HomeHeroController::class, 'update']);
        Route::post('/content/categories', [ContentCategoryController::class, 'store']);
        Route::put('/content/categories/{category}', [ContentCategoryController::class, 'update']);
        Route::delete('/content/categories/{category}', [ContentCategoryController::class, 'destroy']);
        Route::post('/content/articles', [ContentArticleController::class, 'store']);
        Route::put('/content/articles/{article}', [ContentArticleController::class, 'update']);
        Route::delete('/content/articles/{article}', [ContentArticleController::class, 'destroy']);
        Route::post('/media-files', [MediaFileController::class, 'store']);
        Route::delete('/media-files/{mediaFile}', [MediaFileController::class, 'destroy']);
        Route::post('/media-file-reindexes', [MediaFileController::class, 'reindex']);
    });

    Route::middleware(['permission:'.AdminPermissions::CONTENT_LIST])->group(function (): void {
        Route::get('/site/home-hero', [HomeHeroController::class, 'show']);
        Route::get('/content/summary', [ContentArticleController::class, 'summary']);
        Route::get('/content/categories', [ContentCategoryController::class, 'index']);
        Route::get('/content/articles', [ContentArticleController::class, 'index']);
        Route::get('/content/articles/{article}', [ContentArticleController::class, 'show']);
        Route::get('/media-files', [MediaFileController::class, 'index']);
        Route::get('/media-files/{mediaFile}/references', [MediaFileController::class, 'references']);
    });

    Route::middleware(['permission:'.AdminPermissions::MEMBER_LEVEL_LIST])->group(function (): void {
        Route::get('/member-levels', [MemberLevelController::class, 'index']);
    });

    Route::middleware(['permission:'.AdminPermissions::MEMBER_LEVEL_MANAGE])->group(function (): void {
        Route::post('/member-levels', [MemberLevelController::class, 'store']);
        Route::put('/member-levels/{memberLevel}', [MemberLevelController::class, 'update']);
        Route::delete('/member-levels/{memberLevel}', [MemberLevelController::class, 'destroy']);
    });

    Route::middleware(['permission:'.AdminPermissions::AGENT_DISCOUNT_LIST])->group(function (): void {
        Route::get('/agent-groups', [AgentGroupController::class, 'index']);
        Route::get('/product-discount-groups', [ProductDiscountGroupController::class, 'index']);
        Route::get('/agent-group-discounts', [AgentGroupDiscountController::class, 'index']);
    });

    Route::middleware(['permission:'.AdminPermissions::AGENT_DISCOUNT_MANAGE])->group(function (): void {
        Route::post('/agent-groups', [AgentGroupController::class, 'store']);
        Route::put('/agent-groups/{agentGroup}', [AgentGroupController::class, 'update']);
        Route::delete('/agent-groups/{agentGroup}', [AgentGroupController::class, 'destroy']);
        Route::post('/product-discount-groups', [ProductDiscountGroupController::class, 'store']);
        Route::put('/product-discount-groups/{productDiscountGroup}', [ProductDiscountGroupController::class, 'update']);
        Route::delete('/product-discount-groups/{productDiscountGroup}', [ProductDiscountGroupController::class, 'destroy']);
        Route::put('/agent-group-discounts', [AgentGroupDiscountController::class, 'update']);
    });

    Route::middleware(['permission:'.AdminPermissions::INTEGRATION_PLUGIN_VIEW])->group(function (): void {
        Route::get('/integration-plugins', [IntegrationPluginController::class, 'index']);
        Route::get('/integration-plugins/{plugin}', [IntegrationPluginController::class, 'show']);
        Route::get('/integration-plugins/{plugin}/schema', [IntegrationPluginController::class, 'schema']);
    });

    Route::middleware(['permission:'.AdminPermissions::INTEGRATION_PLUGIN_MANAGE])->group(function (): void {
        Route::post('/integration-plugins', [IntegrationPluginController::class, 'install']);
        Route::post('/integration-plugin-scans', [IntegrationPluginController::class, 'scan']);
        Route::put('/integration-plugins/{plugin}/config', [IntegrationPluginController::class, 'updateConfig']);
        Route::patch('/integration-plugins/{plugin}/status', [IntegrationPluginController::class, 'updateStatus']);
        Route::delete('/integration-plugins/{plugin}', [IntegrationPluginController::class, 'destroy']);
    });

    Route::middleware(['permission:'.AdminPermissions::INTEGRATION_PLUGIN_TEST])->group(function (): void {
        // 测试发送会真实触发短信/邮件，需节流防止对任意收件人轰炸（与密钥 reveal 同级限流）。
        Route::post('/integration-plugins/{plugin}/tasks', [IntegrationPluginController::class, 'runTask'])->middleware('throttle:10,1');
    });

    Route::middleware(['permission:'.AdminPermissions::INTEGRATION_PLUGIN_SECRET_REVEAL])->group(function (): void {
        Route::get('/integration-plugins/{plugin}/secrets/{key}', [IntegrationPluginController::class, 'revealSecret'])->middleware('throttle:10,1');
    });

    Route::middleware(['permission:'.AdminPermissions::SUPPLIER_LIST])->group(function (): void {
        Route::get('/suppliers/summary', [SupplierController::class, 'summary']);
        Route::get('/suppliers/provider-types', [SupplierController::class, 'providerTypes']);
        Route::get('/suppliers', [SupplierController::class, 'index']);
    });

    Route::middleware(['permission:'.AdminPermissions::SUPPLIER_DETAIL])->group(function (): void {
        Route::get('/suppliers/{supplier}', [SupplierController::class, 'show']);
    });

    Route::middleware(['permission:'.AdminPermissions::SUPPLIER_MANAGE])->group(function (): void {
        Route::post('/suppliers', [SupplierController::class, 'store']);
        Route::put('/suppliers/{supplier}', [SupplierController::class, 'update']);
        Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy']);
        Route::patch('/suppliers/{supplier}/status', [SupplierController::class, 'updateStatus']);
    });

    Route::middleware(['permission:'.AdminPermissions::SUPPLIER_SYNC])->group(function (): void {
        Route::get('/suppliers/{supplier}/balance', [SupplierController::class, 'balance']);
        Route::get('/suppliers/{supplier}/products', [SupplierController::class, 'products']);
        Route::get('/suppliers/{supplier}/products/{productId}/config-template', [SupplierController::class, 'productConfigTemplate']);
        Route::post('/suppliers/{supplier}/tasks', [SupplierController::class, 'runTask']);
    });

    Route::middleware(['permission:'.AdminPermissions::SUPPLIER_SECRET_REVEAL])->group(function (): void {
        Route::get('/suppliers/{supplier}/secrets/{key}', [SupplierController::class, 'revealSecret'])->middleware('throttle:10,1');
    });

    Route::middleware(['permission:'.AdminPermissions::SETTINGS_VIEW])->group(function (): void {
        Route::get('/notification-templates', [SettingController::class, 'notificationTemplates']);
        Route::get('/settings', [SettingController::class, 'index']);
    });

    Route::middleware(['permission:'.AdminPermissions::SETTINGS_MANAGE])->group(function (): void {
        Route::post('/notification-templates/test-send', [SettingController::class, 'testNotificationTemplate']);
        Route::post('/settings', [SettingController::class, 'update']);
    });

    Route::middleware(['permission:'.AdminPermissions::SETTINGS_SECRET_REVEAL])->group(function (): void {
        Route::get('/settings/{group}/secrets/{key}', [SettingController::class, 'revealSecret'])->middleware('throttle:10,1');
    });

    Route::middleware(['permission:'.AdminPermissions::DATABASE_VIEW])->group(function (): void {
        Route::get('/database/status', [DatabaseStatusController::class, 'status']);
    });

    Route::middleware(['permission:'.AdminPermissions::DATABASE_MANAGE])->group(function (): void {
        Route::post('/database/optimizations', [DatabaseStatusController::class, 'optimize']);
        Route::post('/database/backups', [DatabaseStatusController::class, 'exportBackup']);
    });

    Route::middleware(['permission:'.AdminPermissions::STAFF_LIST])->group(function (): void {
        Route::get('/staff', [StaffController::class, 'index']);
        Route::get('/staff/roles', [StaffController::class, 'roles']);
        Route::get('/staff/{staff}', [StaffController::class, 'show']);
    });

    Route::middleware(['permission:'.AdminPermissions::STAFF_MANAGE])->group(function (): void {
        Route::post('/staff', [StaffController::class, 'store']);
        Route::put('/staff/{staff}', [StaffController::class, 'update']);
        Route::delete('/staff/{staff}', [StaffController::class, 'destroy']);
        Route::patch('/staff/{staff}/status', [StaffController::class, 'updateStatus']);
        Route::post('/staff/{staff}/password-resets', [StaffController::class, 'resetPassword']);
    });

    Route::middleware(['permission:'.AdminPermissions::PERMISSION_LIST])->group(function (): void {
        Route::get('/permissions', [RoleController::class, 'permissions']);
    });

    Route::middleware(['permission:'.AdminPermissions::ROLE_LIST])->group(function (): void {
        Route::get('/roles', [RoleController::class, 'index']);
        Route::get('/roles/{role}', [RoleController::class, 'show']);
    });

    Route::middleware(['permission:'.AdminPermissions::ROLE_MANAGE])->group(function (): void {
        Route::post('/roles', [RoleController::class, 'store']);
        Route::put('/roles/{role}', [RoleController::class, 'update']);
        Route::delete('/roles/{role}', [RoleController::class, 'destroy']);
        Route::post('/roles/{role}/copies', [RoleController::class, 'copy']);
    });

    Route::middleware(['permission:'.AdminPermissions::REFERRAL_LIST])->group(function (): void {
        Route::get('/referral/overview', [ReferralController::class, 'overview']);
        Route::get('/referral/rewards', [ReferralController::class, 'rewards']);
    });

    Route::middleware(['permission:'.AdminPermissions::REFERRAL_WITHDRAWAL_LIST])->group(function (): void {
        Route::get('/referral-withdrawals', [ReferralWithdrawalController::class, 'index']);
    });

    Route::middleware(['permission:'.AdminPermissions::FINANCE_WITHDRAW])->group(function (): void {
        Route::post('/referral-withdrawals/{withdrawal}/approvals', [ReferralWithdrawalController::class, 'approve']);
        Route::post('/referral-withdrawals/{withdrawal}/rejections', [ReferralWithdrawalController::class, 'reject']);
        Route::post('/referral-withdrawals/{withdrawal}/payment-confirmations', [ReferralWithdrawalController::class, 'confirmPayment']);
    });
});
