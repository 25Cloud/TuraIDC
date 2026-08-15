<?php

declare(strict_types=1);

use App\Constants\FinanceLedgerEventType;
use App\Constants\InvoiceStatus;
use App\Constants\InvoiceType;
use App\Constants\OrderStatus;
use App\Constants\PaymentStatus;
use App\Constants\ServiceStatus;
use App\Models\BalanceLog;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use Database\Seeders\SettingsSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

require_once __DIR__.'/../database/seeders/SettingsSeeder.php';

$mockUserPassword = trim((string) env('MOCK_USER_PASSWORD', ''));
if ($mockUserPassword === '') {
    throw new InvalidArgumentException('运行模拟数据脚本前必须通过受控环境变量设置 MOCK_USER_PASSWORD。');
}

$now = now();

$json = static fn (array $value): string => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$money = static fn (float $value): string => number_format($value, 2, '.', '');

$summary = DB::transaction(function () use ($now, $json, $money, $mockUserPassword): array {
    SettingsSeeder::seed();

    DB::table('member_levels')->insert([
        [
            'name' => '标准会员',
            'code' => 'standard',
            'sales_amount_min' => '0.00',
            'sales_amount_max' => '9999.99',
            'reward_rate' => '1.00',
            'status' => 1,
            'sort_order' => 10,
            'remark' => '模拟数据：默认会员等级',
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'name' => '金牌会员',
            'code' => 'gold',
            'sales_amount_min' => '10000.00',
            'sales_amount_max' => null,
            'reward_rate' => '3.00',
            'status' => 1,
            'sort_order' => 20,
            'remark' => '模拟数据：高等级会员',
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    $userId = DB::table('users')->insertGetId([
        'email' => 'demo-user@example.test',
        'password' => Hash::make($mockUserPassword),
        'nickname' => '演示用户',
        'phone' => '13900000001',
        'company' => '图拉云演示公司',
        'qq' => '100000',
        'alipay_real_name' => '演示用户',
        'alipay_account' => 'demo@example.com',
        'referral_code' => 'DEMO'.strtoupper(substr(md5('demo-user'), 0, 8)),
        'member_level_id' => 1,
        'total_sales_amount' => '99.00',
        'balance' => '450.00',
        'credit_limit' => '0.00',
        'status' => 1,
        'is_verified' => 1,
        'real_name' => '演示用户',
        'id_card' => '110101199001010011',
        'verification_status' => 1,
        'verification_message' => '模拟实名认证通过',
        'verified_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('user_accounts')->insert([
        'user_id' => $userId,
        'cash_balance' => '450.00',
        'credit_limit' => '0.00',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $supplierId = DB::table('suppliers')->insertGetId([
        'name' => '本地模拟供应商',
        'code' => 'local-demo',
        'interface_type' => 'local_manual',
        'api_url' => 'http://127.0.0.1/mock-upstream',
        'api_username' => 'demo',
        'api_key' => 'example',
        'contact_name' => '演示运维',
        'contact_phone' => '13900000002',
        'contact_email' => 'ops@example.com',
        'website' => 'https://example.com',
        'status' => 1,
        'sort_order' => 10,
        'notes' => '模拟数据：不会调用真实上游',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('product_groups')->insert([
        'product_type' => 'vps',
        'name' => '云服务器',
        'slogan' => '适合网站、开发测试与轻量业务',
        'slug' => 'cloud-server',
        'sort_order' => 10,
        'is_visible' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('product_groups')->insert([
        'product_type' => 'dedicated',
        'name' => '独立服务器',
        'slogan' => '高性能独享资源',
        'slug' => 'dedicated-server',
        'sort_order' => 20,
        'is_visible' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $vpsProductId = DB::table('products')->insertGetId([
        'product_type' => 'vps',
        'remark' => '2C4G 云服务器',
        'meta_title' => '2C4G 云服务器',
        'meta_description' => '模拟数据：适合中小型站点的云服务器。',
        'meta_keywords' => '云服务器,VPS,演示产品',
        'pricing' => $json(['monthly' => '99.00', 'quarterly' => '270.00', 'yearly' => '999.00']),
        'setup_fee' => '0.00',
        'config_options' => $json([
            ['key' => 'cpu', 'label' => 'CPU', 'value' => '2 核'],
            ['key' => 'memory', 'label' => '内存', 'value' => '4 GB'],
            ['key' => 'disk', 'label' => '系统盘', 'value' => '80 GB SSD'],
        ]),
        'purchase_requires' => $json(['realname' => true]),
        'stock' => 28,
        'status' => 1,
        'sort_order' => 10,
        'provision_module' => 'manual',
        'auto_setup' => 0,
        'supplier_id' => $supplierId,
        'supplier_product_id' => 10001,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $dedicatedProductId = DB::table('products')->insertGetId([
        'product_type' => 'dedicated',
        'remark' => 'E5 独立服务器',
        'meta_title' => 'E5 独立服务器',
        'meta_description' => '模拟数据：适合高负载业务的独享服务器。',
        'meta_keywords' => '独立服务器,高防,演示产品',
        'pricing' => $json(['monthly' => '299.00', 'quarterly' => '850.00', 'yearly' => '3200.00']),
        'setup_fee' => '0.00',
        'config_options' => $json([
            ['key' => 'cpu', 'label' => 'CPU', 'value' => 'E5-2680v4'],
            ['key' => 'memory', 'label' => '内存', 'value' => '32 GB'],
            ['key' => 'bandwidth', 'label' => '带宽', 'value' => '30 Mbps'],
        ]),
        'purchase_requires' => $json(['realname' => true]),
        'stock' => 6,
        'status' => 1,
        'sort_order' => 20,
        'provision_module' => 'manual',
        'auto_setup' => 0,
        'supplier_id' => $supplierId,
        'supplier_product_id' => 10002,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $paidOrder = Order::query()->create([
        'order_no' => Order::generateOrderNo(),
        'user_id' => $userId,
        'product_id' => $vpsProductId,
        'product_spec_snapshot' => '2C4G 云服务器',
        'product_type_snapshot' => 'vps',
        'type' => 'new',
        'amount' => '99.00',
        'discount' => '0.00',
        'paid_amount' => '99.00',
        'billing_cycle' => 'monthly',
        'quantity' => 1,
        'config_snapshot' => ['hostname' => 'demo-web-01', 'region' => '宁波'],
        'config_pricing_snapshot' => ['monthly' => '99.00'],
        'status' => OrderStatus::PAID,
        'paid_at' => $now->copy()->subDays(2),
        'remark' => '模拟数据：已支付购买单',
        'trace_id' => 'mock-paid-order',
    ]);

    $paidInvoice = Invoice::query()->create([
        'invoice_no' => Invoice::generateInvoiceNoFromOrderNo((string) $paidOrder->order_no),
        'user_id' => $userId,
        'order_id' => (int) $paidOrder->id,
        'product_id' => $vpsProductId,
        'product_spec_snapshot' => '2C4G 云服务器',
        'product_type_snapshot' => 'vps',
        'type' => InvoiceType::NEW_PURCHASE,
        'amount' => '99.00',
        'discount' => '0.00',
        'paid_amount' => '99.00',
        'billing_cycle' => 'monthly',
        'quantity' => 1,
        'config_snapshot' => ['hostname' => 'demo-web-01', 'region' => '宁波'],
        'config_pricing_snapshot' => ['monthly' => '99.00'],
        'status' => InvoiceStatus::PAID,
        'due_date' => $now->copy()->addDays(5)->toDateString(),
        'paid_at' => $now->copy()->subDays(2),
        'remark' => '模拟数据：已支付账单',
        'trace_id' => 'mock-paid-invoice',
    ]);

    $serviceId = DB::table('services')->insertGetId([
        'user_id' => $userId,
        'product_id' => $vpsProductId,
        'order_id' => (int) $paidOrder->id,
        'invoice_id' => (int) $paidInvoice->id,
        'name' => 'demo-web-01',
        'domain' => 'demo-web-01.example.local',
        'billing_cycle' => 'monthly',
        'amount' => '99.00',
        'locked_pricing' => $json(['monthly' => '99.00']),
        'status' => ServiceStatus::ACTIVE,
        'provision_data' => $json([
            'source_type' => 'mock',
            'upstream_host_id' => 'MOCK-10001',
            'ip' => '10.10.0.21',
            'os' => 'Ubuntu 22.04',
        ]),
        'expires_at' => $now->copy()->addMonth(),
        'auto_renew' => 1,
        'remark' => '模拟数据：已开通实例',
        'trace_id' => 'mock-service-active',
        'created_at' => $now->copy()->subDays(2),
        'updated_at' => $now,
    ]);

    $paidOrder->forceFill(['service_id' => $serviceId])->save();
    $paidInvoice->forceFill(['service_id' => $serviceId])->save();

    $paidPayment = Payment::query()->create([
        'payment_no' => Payment::generatePaymentNo(),
        'user_id' => $userId,
        'order_id' => (int) $paidOrder->id,
        'invoice_id' => (int) $paidInvoice->id,
        'gateway' => 'alipay',
        'trade_no' => 'TRADE-MOCK-'.strtoupper(substr(md5('paid'), 0, 10)),
        'amount' => '99.00',
        'status' => PaymentStatus::SUCCESS,
        'callback_raw' => ['source' => 'mock_seed', 'trade_status' => 'TRADE_SUCCESS'],
        'paid_at' => $now->copy()->subDays(2),
        'remark' => '模拟数据：支付宝支付成功',
        'trace_id' => 'mock-paid-payment',
    ]);
    $paidPayment->syncPaymentCallbackProjection();
    $paidInvoice->syncInvoiceItemProjection();

    $unpaidOrder = Order::query()->create([
        'order_no' => Order::generateOrderNo(),
        'user_id' => $userId,
        'product_id' => $dedicatedProductId,
        'product_spec_snapshot' => 'E5 独立服务器',
        'product_type_snapshot' => 'dedicated',
        'type' => 'new',
        'amount' => '299.00',
        'discount' => '0.00',
        'paid_amount' => '0.00',
        'billing_cycle' => 'monthly',
        'quantity' => 1,
        'config_snapshot' => ['hostname' => 'demo-ds-01', 'region' => '上海'],
        'config_pricing_snapshot' => ['monthly' => '299.00'],
        'status' => OrderStatus::PENDING,
        'remark' => '模拟数据：待支付购买单',
        'trace_id' => 'mock-unpaid-order',
    ]);

    $unpaidInvoice = Invoice::query()->create([
        'invoice_no' => Invoice::generateInvoiceNoFromOrderNo((string) $unpaidOrder->order_no),
        'user_id' => $userId,
        'order_id' => (int) $unpaidOrder->id,
        'product_id' => $dedicatedProductId,
        'product_spec_snapshot' => 'E5 独立服务器',
        'product_type_snapshot' => 'dedicated',
        'type' => InvoiceType::NEW_PURCHASE,
        'amount' => '299.00',
        'paid_amount' => '0.00',
        'billing_cycle' => 'monthly',
        'quantity' => 1,
        'config_snapshot' => ['hostname' => 'demo-ds-01', 'region' => '上海'],
        'config_pricing_snapshot' => ['monthly' => '299.00'],
        'status' => InvoiceStatus::UNPAID,
        'due_date' => $now->copy()->addDays(7)->toDateString(),
        'remark' => '模拟数据：待支付账单',
        'trace_id' => 'mock-unpaid-invoice',
    ]);
    $unpaidInvoice->syncInvoiceItemProjection();

    $rechargeInvoice = Invoice::query()->create([
        'invoice_no' => Invoice::generateInvoiceNo(),
        'user_id' => $userId,
        'type' => InvoiceType::RECHARGE,
        'amount' => '500.00',
        'paid_amount' => '500.00',
        'status' => InvoiceStatus::PAID,
        'due_date' => $now->toDateString(),
        'paid_at' => $now->copy()->subDay(),
        'config_snapshot' => ['remark' => '模拟支付宝充值'],
    ]);
    $rechargePayment = Payment::query()->create([
        'payment_no' => Payment::generatePaymentNo(),
        'user_id' => $userId,
        'invoice_id' => (int) $rechargeInvoice->id,
        'gateway' => 'alipay',
        'trade_no' => 'TRADE-MOCK-'.strtoupper(substr(md5('recharge'), 0, 10)),
        'amount' => '500.00',
        'status' => PaymentStatus::SUCCESS,
        'callback_raw' => ['source' => 'mock_seed', 'trade_status' => 'TRADE_SUCCESS'],
        'paid_at' => $now->copy()->subDay(),
        'remark' => '模拟数据：余额充值',
        'trace_id' => 'mock-recharge-payment',
    ]);
    $rechargePayment->syncPaymentCallbackProjection();
    $rechargeInvoice->syncInvoiceItemProjection();

    $rechargeLog = new BalanceLog([
        'user_id' => $userId,
        'event_type' => FinanceLedgerEventType::RECHARGE,
        'change_amount' => '500.00',
        'balance_after' => '500.00',
        'reference_id' => (int) $rechargePayment->id,
        'remark' => '模拟支付宝充值',
    ]);
    $rechargeLog->created_at = $now->copy()->subDay();
    $rechargeLog->save();

    $deductionInvoice = Invoice::query()->create([
        'invoice_no' => Invoice::generateInvoiceNo(),
        'user_id' => $userId,
        'type' => InvoiceType::DEDUCTION,
        'amount' => '50.00',
        'paid_amount' => '50.00',
        'status' => InvoiceStatus::PAID,
        'due_date' => $now->toDateString(),
        'paid_at' => $now,
        'config_snapshot' => ['remark' => '模拟人工扣款'],
    ]);
    $deductionInvoice->syncInvoiceItemProjection();

    $deductionLog = new BalanceLog([
        'user_id' => $userId,
        'event_type' => FinanceLedgerEventType::MANUAL_DEDUCTION,
        'change_amount' => '-50.00',
        'balance_after' => '450.00',
        'reference_id' => (int) $deductionInvoice->id,
        'remark' => '模拟人工扣款',
    ]);
    $deductionLog->created_at = $now;
    $deductionLog->save();

    $couponId = DB::table('coupons')->insertGetId([
        'name' => '新用户满减券',
        'code' => 'WELCOME50',
        'description' => '模拟数据：满 199 减 50',
        'distribution_type' => 'public',
        'discount_scope' => 'first_month',
        'discount_type' => 'fixed',
        'discount_value' => '50.00',
        'min_amount' => '199.00',
        'billing_cycles' => $json(['monthly', 'quarterly', 'yearly']),
        'product_ids' => $json([$vpsProductId, $dedicatedProductId]),
        'first_order_only' => 0,
        'total_usage_limit' => 100,
        'per_user_limit' => 1,
        'status' => 1,
        'starts_at' => $now->copy()->subDay(),
        'expires_at' => $now->copy()->addMonth(),
        'remark' => '模拟优惠券',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('user_coupons')->insert([
        'coupon_id' => $couponId,
        'user_id' => $userId,
        'receive_type' => 'claim',
        'status' => 1,
        'claimed_at' => $now,
        'remark' => '模拟领取',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $ticketId = DB::table('tickets')->insertGetId([
        'user_id' => $userId,
        'department' => 'support',
        'subject' => '模拟工单：服务器无法登录',
        'priority' => 2,
        'status' => 1,
        'service_id' => $serviceId,
        'created_at' => $now->copy()->subHours(6),
        'updated_at' => $now->copy()->subHours(4),
    ]);
    DB::table('ticket_replies')->insert([
        [
            'ticket_id' => $ticketId,
            'user_id' => $userId,
            'content' => '我无法通过 SSH 登录 demo-web-01，请协助检查。',
            'is_staff' => 0,
            'attachments' => null,
            'created_at' => $now->copy()->subHours(6),
        ],
        [
            'ticket_id' => $ticketId,
            'user_id' => 1,
            'content' => '已检查实例状态正常，请重置密码后再尝试登录。',
            'is_staff' => 1,
            'attachments' => null,
            'created_at' => $now->copy()->subHours(4),
        ],
    ]);

    $categoryId = DB::table('content_categories')->insertGetId([
        'content_type' => 'article',
        'name' => '使用指南',
        'slug' => 'guides',
        'description' => '模拟数据：帮助中心分类',
        'status' => 1,
        'sort_order' => 10,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('content_articles')->insert([
        'content_type' => 'article',
        'category_id' => $categoryId,
        'title' => '如何完成账户充值并支付账单',
        'slug' => 'how-to-recharge-and-pay-invoice',
        'summary' => '模拟数据：介绍充值、账单支付与服务开通流程。',
        'content' => '<p>这是模拟帮助文档，可用于验证内容中心、SEO 与帮助页展示。</p>',
        'category_name' => '使用指南',
        'keywords' => '充值,账单,服务开通',
        'meta_title' => '如何完成账户充值并支付账单',
        'meta_description' => '模拟帮助文档。',
        'status' => 1,
        'is_pinned' => 1,
        'is_recommended' => 1,
        'sort_order' => 10,
        'view_count' => 128,
        'publish_at' => $now,
        'last_published_at' => $now,
        'operator' => 'mock-seed',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'users' => DB::table('users')->count(),
        'products' => DB::table('products')->count(),
        'invoices' => DB::table('invoices')->count(),
        'payments' => DB::table('payments')->count(),
        'services' => DB::table('services')->count(),
        'tickets' => DB::table('tickets')->count(),
        'settings' => DB::table('settings')->count(),
        'demo_user_balance' => $money((float) DB::table('users')->where('id', $userId)->value('balance')),
    ];
});

echo "[mock-seed] 模拟数据写入完成\n";
foreach ($summary as $key => $value) {
    echo "[mock-seed] {$key}: {$value}\n";
}
echo "[mock-seed] 管理员与用户测试凭据必须通过受控渠道取得，禁止写入脚本或日志。\n";
