<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Constants\ServiceStatus;
use App\Models\IntegrationPlugin;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Finance\CheckoutSecurityService;
use App\Services\Finance\CheckoutService;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\PaymentService;
use App\Services\Integrations\Plugins\PluginFileLoader;
use App\Services\Integrations\Plugins\PluginInstaller;
use App\Services\Integrations\Plugins\PluginScanner;
use App\Services\Integrations\Plugins\UpstreamBindingWriter;
use App\Services\Provisioning\ServiceRenewService;
use App\Services\Upstream\ProviderRegistry;
use App\Services\Upstream\ProviderResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class LocalProductSimulateCommand extends Command
{
    protected $signature = 'simulate:product {--skip-setup : 跳过产品创建}';

    protected $description = '本地商品模拟：全8类账单覆盖，不调第三方，使用测试账号 #1';

    private User $user;

    private Product $product;

    private Supplier $supplier;

    /** @var array<int, array{type:string, label:string, id:int, no:string, status:string, amount:string}> */
    private array $results = [];

    public function handle(): int
    {
        Config::set('queue.default', 'sync');

        $this->user = User::query()->findOrFail(1);
        $this->info("测试账号: #{$this->user->id} {$this->user->nickname} ({$this->user->email}) 余额={$this->user->balance}");

        // 激活 demo_servers 插件
        app(PluginFileLoader::class)->ensureLoaded(
            app(PluginScanner::class)->requireManifest('upstream', 'demo_servers')
        );
        $this->ensurePluginActive('upstream', 'demo_servers');
        $this->laravel->forgetInstance(ProviderRegistry::class);
        $this->laravel->forgetInstance(ProviderResolver::class);

        // 余额不足则补充
        if ((float) $this->user->balance < 500) {
            $this->user->forceFill(['balance' => '500.00'])->save();
            $this->info('余额补充至 500.00');
        }

        // 创建产品和供应商
        if (! $this->option('skip-setup')) {
            $this->setupProduct();
        } else {
            $this->product = Product::query()->where('status', 1)->firstOrFail();
            $this->info("复用已有商品 #{$this->product->id}");
        }

        $this->newLine();
        $this->info('=== 开始全8类账单模拟 ===');
        $this->newLine();

        // ── 1. new 新购 ──
        $this->head('1. new — 新购（新购买产品）');
        $this->doNew();
        $this->tail();

        // ── 2. renew 续费 ──
        $this->head('2. renew — 续费（产品续费）');
        $this->doRenew();
        $this->tail();

        // ── 3. recharge 充值 ──
        $this->head('3. recharge — 充值（账户充值）');
        $this->doRecharge();
        $this->tail();

        // ── 4. upgrade 附加配置 ──
        $this->head('4. upgrade — 附加配置（升级/附加配置）');
        $this->doUpgrade();
        $this->tail();

        // ── 5. deduction 扣款 ──
        $this->head('5. deduction — 扣款（系统扣款）');
        $this->doDeduction();
        $this->tail();

        // ── 6. refund 退款 ──
        $this->head('6. refund — 退款（红字退款账单）');
        $this->doRefund();
        $this->tail();

        // ── 7. referral_credit 推荐奖励 ──
        $this->head('7. referral_credit — 推荐奖励（推荐返利）');
        $this->doReferralCredit();
        $this->tail();

        // ── 8. manual 手工账单 ──
        $this->head('8. manual — 手工账单（手动创建）');
        $this->doManual();
        $this->tail();

        // ── 汇总表 ──
        $this->newLine();
        $this->info('=== 全8类账单模拟完成 ===');
        $this->table(
            ['#', '类型代码', '说明', '账单ID', '编号', '状态', '金额'],
            array_map(fn ($r, $i) => [
                (string) ($i + 1), $r['type'], $r['label'],
                (string) $r['id'], $r['no'], $r['status'], $r['amount'],
            ], $this->results, array_keys($this->results))
        );
        $this->info('可在管理端查看: http://127.0.0.1:5174/admin/finance/invoices');

        return 0;
    }

    // ══════════════════════════════════════
    //  8 类账单创建
    // ══════════════════════════════════════

    private function doNew(): void
    {
        $suffix = bin2hex(random_bytes(2));
        $checkout = app(CheckoutService::class);
        $security = app(CheckoutSecurityService::class);
        $quote = $checkout->quote($this->product, 'monthly', [], 1);
        $token = $security->issueQuoteToken($this->product->id, 'monthly', [], array_merge($quote, [
            'subtotal_amount' => $quote['total_amount'],
        ]));

        $invoice = $checkout->create((int) $this->user->id, [
            'product_id' => (int) $this->product->id,
            'billing_cycle' => 'monthly', 'quantity' => 1, 'config' => [],
            'quote_token' => (string) $token['quote_token'],
        ], ['idempotency_key' => 'sim-new-'.$suffix, 'trace_id' => 'sim-new-'.$suffix]);

        app(PaymentService::class)->payByBalance($invoice, $this->user, ['trace_id' => 'sim-pay-new-'.$suffix]);

        $invoice->refresh();
        $order = Order::query()->find((int) $invoice->order_id);
        $svc = $order ? Service::query()->where('order_id', (int) $order->id)->first() : null;

        $this->push('new', '新购', $invoice, $order, $svc);
    }

    private function doRenew(): void
    {
        // 找已有的活跃服务来续费
        $service = Service::query()
            ->where('user_id', (int) $this->user->id)
            ->whereIn('status', [ServiceStatus::ACTIVE, ServiceStatus::SUSPENDED])
            ->whereHas('product')
            ->first();

        if (! $service) {
            $this->warn('  无可用服务续费，跳过');
            $this->results[] = ['type' => 'renew', 'label' => '续费', 'id' => 0, 'no' => '跳过', 'status' => '无可用服务', 'amount' => '-'];

            return;
        }

        $suffix = bin2hex(random_bytes(2));
        $renewInvoice = app(ServiceRenewService::class)->createRenewInvoiceForUser(
            $this->user, (int) $service->id, 'monthly', 0,
            ['trace_id' => 'sim-renew-'.$suffix]
        );

        $order = $renewInvoice->order_id ? Order::query()->find((int) $renewInvoice->order_id) : null;
        $this->push('renew', '续费', $renewInvoice, $order, $service);
    }

    private function doRecharge(): void
    {
        $invoice = app(InvoiceService::class)->createForRecharge(
            $this->user, 100.00, null, '本地模拟：账户充值', 'sim-recharge'
        );
        $this->push('recharge', '充值', $invoice);
    }

    private function doUpgrade(): void
    {
        $invoice = app(InvoiceService::class)->createDirect([
            'user_id' => (int) $this->user->id,
            'product_id' => (int) $this->product->id,
            'product_spec_snapshot' => $this->product->name.' 升级 2C4G',
            'product_type_snapshot' => (string) $this->product->product_type,
            'type' => 'upgrade', 'amount' => 20.00, 'discount' => 0,
            'billing_cycle' => 'monthly', 'quantity' => 1,
            'config_snapshot' => ['upgrade_from' => '1C2G', 'upgrade_to' => '2C4G', 'source' => 'simulation'],
            'status' => InvoiceStatus::UNPAID, 'due_date' => now()->addDays(7),
            'trace_id' => 'sim-upgrade',
        ]);

        app(PaymentService::class)->payByBalance($invoice, $this->user, ['trace_id' => 'sim-pay-upgrade']);
        $invoice->refresh();
        $this->push('upgrade', '附加配置', $invoice);
    }

    private function doDeduction(): void
    {
        $invoice = app(InvoiceService::class)->createForDeduction(
            $this->user, 30.00, '本地模拟：系统扣款', 'sim-deduction'
        );
        $this->push('deduction', '扣款', $invoice);
    }

    private function doRefund(): void
    {
        // 找一笔有商品的已付账单来退款
        $paidInv = Invoice::query()
            ->where('user_id', (int) $this->user->id)
            ->where('status', InvoiceStatus::PAID)
            ->whereNotNull('product_id')
            ->whereNot('type', 'refund')
            ->latest('id')
            ->first();

        if (! $paidInv) {
            // 创建一个简单的新购账单然后退款
            $invoiceService = app(InvoiceService::class);
            $paidInv = $invoiceService->createDirect([
                'user_id' => (int) $this->user->id,
                'product_id' => (int) ($this->product->id ?? 0),
                'product_spec_snapshot' => $this->product->name ?? '测试',
                'product_type_snapshot' => (string) ($this->product->product_type ?? ''),
                'type' => 'new', 'amount' => 39.00, 'discount' => 0,
                'billing_cycle' => 'monthly', 'quantity' => 1,
                'status' => InvoiceStatus::PAID, 'paid_at' => now(), 'paid_amount' => 39.00,
                'due_date' => now()->addDays(7),
                'trace_id' => 'sim-refund-base',
            ]);
        }

        $productId = (int) ($paidInv->product_id ?? $this->product->id ?? 0);

        $refundInvoice = app(InvoiceService::class)->createDirect([
            'user_id' => (int) $this->user->id,
            'origin_invoice_id' => (int) $paidInv->id,
            'product_id' => $productId > 0 ? $productId : null,
            'type' => 'refund',
            'amount' => -(float) $paidInv->amount,
            'discount' => 0,
            'billing_cycle' => (string) ($paidInv->billing_cycle ?: ''),
            'quantity' => 1,
            'status' => InvoiceStatus::PAID,
            'paid_at' => now(),
            'paid_amount' => -(float) $paidInv->amount,
            'refund_amount' => (float) $paidInv->amount,
            'due_date' => now()->addDays(7),
            'remark' => "本地模拟：退 #{$paidInv->id} ({$paidInv->invoice_no})",
            'trace_id' => 'sim-refund',
        ]);

        $this->push('refund', '退款红字', $refundInvoice);
    }

    private function doReferralCredit(): void
    {
        $invoice = app(InvoiceService::class)->createForReferralCredit(
            $this->user, 15.00, '本地模拟：推荐返利', 'sim-referral'
        );
        $this->push('referral_credit', '推荐奖励', $invoice);
    }

    private function doManual(): void
    {
        $invoice = app(InvoiceService::class)->createDirect([
            'user_id' => (int) $this->user->id,
            'type' => 'manual', 'amount' => 50.00, 'discount' => 0,
            'status' => InvoiceStatus::UNPAID, 'due_date' => now()->addDays(7),
            'remark' => '本地模拟：管理员手工创建',
            'trace_id' => 'sim-manual',
        ]);
        $this->push('manual', '手工账单', $invoice);
    }

    // ══════════════════════════════════════
    //  辅助方法
    // ══════════════════════════════════════

    private function setupProduct(): void
    {
        $s = bin2hex(random_bytes(2));

        $this->supplier = Supplier::query()->create([
            'name' => "Demo供应商{$s}",
            'code' => "demo-sim-{$s}",
            'interface_type' => 'demo_servers',
            'api_url' => "http://demo-{$s}.example.test",
            'api_username' => 'demo', 'api_key' => 'secret',
            'status' => 1, 'sort_order' => 1,
        ]);

        $this->product = Product::query()->create([
            'name' => "Demo云服务器1C2G{$s}",
            'product_type' => 'server',
            'pricing' => ['monthly' => '39.00'],
            'setup_fee' => '0.00', 'config_options' => [], 'purchase_requires' => [],
            'stock' => 99, 'status' => 1, 'auto_setup' => 1,
            'supplier_id' => (int) $this->supplier->id,
            'provision_module' => 'demo_servers',
            'supplier_product_id' => 1001,
        ]);

        app(UpstreamBindingWriter::class)->syncSupplierBinding($this->supplier, [
            'provider_key' => 'demo_servers',
            'base_url' => (string) $this->supplier->api_url,
            'account_name' => (string) $this->supplier->api_username,
            'api_key' => (string) $this->supplier->api_key,
            'status' => 1,
        ]);
        app(UpstreamBindingWriter::class)->syncProductBinding($this->product, $this->supplier, '1001');

        $this->info("创建商品 #{$this->product->id} + 供应商 #{$this->supplier->id} + demo_servers 绑定");
    }

    private function ensurePluginActive(string $domain, string $slug): void
    {
        $installer = app(PluginInstaller::class);
        $existing = DB::table('integration_plugins')
            ->where('domain', $domain)->where('plugin_key', $slug)->first();

        if (! $existing) {
            $plugin = $installer->install($domain, $slug);
            $installer->enable($plugin);
        } elseif ((int) $existing->status !== 1) {
            $plugin = IntegrationPlugin::query()->find((int) $existing->id);
            if ($plugin) {
                $installer->enable($plugin);
            }
        }
    }

    private function head(string $t): void
    {
        $this->line("  <fg=cyan>{$t}</>");
    }

    private function tail(): void
    {
        // 空行分隔
    }

    /**
     * @param  Invoice  $invoice
     * @param  Order|null  $order
     * @param  Service|null  $service
     */
    private function push(string $type, string $label, $invoice, $order = null, $service = null): void
    {
        $invLabel = InvoiceStatus::$labels[(int) $invoice->status] ?? '?';
        $parts = ["账单 #{$invoice->id} [{$invoice->invoice_no}] {$invLabel} ¥{$invoice->amount}"];

        if ($order) {
            $ordLabel = OrderStatus::$labels[(int) $order->status] ?? '?';
            $parts[] = "订单 #{$order->id} {$ordLabel}";
        }
        if ($service) {
            $svcLabel = ServiceStatus::$labels[(int) $service->status] ?? '?';
            $parts[] = "服务 #{$service->id} {$svcLabel}";
        }

        $this->line('  → '.implode(' | ', $parts));

        $status = $order
            ? "账单{$invLabel} / 订单{$ordLabel}"
            : "账单{$invLabel}";

        $this->results[] = [
            'type' => $type, 'label' => $label,
            'id' => (int) $invoice->id, 'no' => (string) $invoice->invoice_no,
            'status' => $status, 'amount' => (string) $invoice->amount,
        ];
    }
}
