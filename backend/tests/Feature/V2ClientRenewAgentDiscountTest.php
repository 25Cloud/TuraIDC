<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Constants\ServiceStatus;
use App\Models\AgentGroup;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Automation\AutoRenewService;
use App\Services\Provisioning\ServiceRenewService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 代理折扣在续费链路的端到端回归。
 *
 * 代理折扣是「计价时折算」而非改商品/服务定价：续费预览、手动建单、自动续费
 * 共用 buildRenewConfig 一处折扣计算（矩阵未覆盖时回退代理组全局默认折扣率）。
 * 此前该测试只有占位断言，折扣链路无端到端回归保护。
 */
class V2ClientRenewAgentDiscountTest extends TestCase
{
    use DatabaseTransactions;

    public function test自动续费建单金额与续费预览一致且账单快照固定代理折扣(): void
    {
        $stack = $this->makeAgentServiceStack(defaultDiscountRate: 85.0);
        $user = $stack['user'];
        $user->forceFill(['balance' => '200.00'])->save();
        $service = $stack['service'];

        // 到期时间落在自动续费窗口（默认提前 3 天的当天）
        $service->forceFill([
            'auto_renew' => 1,
            'expires_at' => now()->addDays((int) config('idc.auto_renew_days_before', 3))->setTime(12, 0),
        ])->save();

        // 预览：矩阵未覆盖 → 回退代理组默认折扣率 85%
        $preview = app(ServiceRenewService::class)->previewForUser($user, (int) $service->id, 'monthly', 0);
        $previewCycle = collect($preview['cycles'])->firstWhere('billing_cycle', 'monthly');
        $this->assertNotNull($previewCycle);
        $this->assertSame('99.00', (string) $previewCycle['original_amount']);
        $this->assertSame('84.15', (string) $previewCycle['amount']);
        $this->assertSame(85.0, (float) $previewCycle['agent_discount_rate']);
        $this->assertSame('14.85', (string) $previewCycle['agent_discount_amount']);
        $this->assertSame('84.15', (string) $preview['renew_price']);

        // 自动续费端到端：调度入口真实建单扣款，金额必须与预览一致
        // （共享测试库中可能有其它残留服务同样命中窗口，故只断言本用例的服务被处理）
        app(AutoRenewService::class)->handle();

        $order = Order::query()
            ->where('user_id', (int) $user->id)
            ->where('type', 'renew')
            ->where('service_id', (int) $service->id)
            ->latest('id')
            ->firstOrFail();
        // 同步队列下支付后可能立即履约完成（COMPLETED），未履约时停在被扣款状态（PAID）
        $this->assertContains(
            (int) $order->status,
            [OrderStatus::PAID, OrderStatus::PROCESSING, OrderStatus::COMPLETED],
            '自动续费应为本用例服务成功扣款'
        );

        $invoice = Invoice::query()->where('order_id', (int) $order->id)->latest('id')->firstOrFail();
        $this->assertSame(InvoiceStatus::PAID, (int) $invoice->status);
        $this->assertSame((float) $previewCycle['amount'], (float) $invoice->amount, '建单金额必须与续费预览一致');

        // 账单快照固定折扣依据（审计可追溯）
        $snapshot = (array) $invoice->config_snapshot;
        $this->assertSame(85.0, (float) ($snapshot['agent_discount_rate'] ?? 0));
        $this->assertSame('14.85', (string) ($snapshot['agent_discount_amount'] ?? ''));
        $this->assertSame('99.00', (string) ($snapshot['original_renew_amount'] ?? ''));
        $this->assertSame((int) $stack['agentGroup']->id, (int) ($snapshot['agent_group_id'] ?? 0));

        // 余额按折后价扣减
        $this->assertSame('115.85', (string) $user->fresh()->balance);
    }

    public function test手动续费账单同样应用代理折扣且不改商品定价(): void
    {
        $stack = $this->makeAgentServiceStack(defaultDiscountRate: 85.0);
        $user = $stack['user'];
        $user->forceFill(['balance' => '200.00'])->save();
        $service = $stack['service'];

        $invoice = app(ServiceRenewService::class)->createRenewInvoiceForUser(
            $user, (int) $service->id, 'monthly', 0, ['trace_id' => 'trace-renew-agent-discount']
        );

        $this->assertSame('84.15', (string) $invoice->amount);
        $snapshot = (array) $invoice->config_snapshot;
        $this->assertSame(85.0, (float) ($snapshot['agent_discount_rate'] ?? 0));
        $this->assertSame('99.00', (string) ($snapshot['original_renew_amount'] ?? ''));

        // 计价时折算：商品定价与初始金额不被修改
        $this->assertSame('99.00', (string) $stack['product']->fresh()->pricing['monthly']);
        $this->assertSame('99.00', (string) $service->fresh()->amount);
    }

    public function test无代理身份的续费不产生折扣(): void
    {
        $stack = $this->makeAgentServiceStack(null);
        $user = $stack['user'];
        $user->forceFill(['balance' => '200.00'])->save();
        $service = $stack['service'];

        $preview = app(ServiceRenewService::class)->previewForUser($user, (int) $service->id, 'monthly', 0);
        $previewCycle = collect($preview['cycles'])->firstWhere('billing_cycle', 'monthly');

        $this->assertSame('99.00', (string) $previewCycle['amount']);
        $this->assertSame(100.0, (float) $previewCycle['agent_discount_rate']);
    }

    /**
     * 构建挂代理组的续费场景：商品不挂折扣组（矩阵未覆盖），走代理组全局默认折扣率。
     * defaultDiscountRate 传 null 表示用户无代理身份。
     *
     * @return array{user: User, product: Product, service: Service, agentGroup: ?AgentGroup}
     */
    private function makeAgentServiceStack(?float $defaultDiscountRate): array
    {
        $suffix = bin2hex(random_bytes(4));

        $agentGroup = null;
        $userAttributes = [
            'email' => "renew-discount-{$suffix}@example.test",
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
        ];

        if ($defaultDiscountRate !== null) {
            $agentGroup = AgentGroup::query()->create([
                'name' => 'Renew Discount Agent '.$suffix,
                'code' => 'ra-'.$suffix,
                'status' => 1,
                'default_discount_rate' => $defaultDiscountRate,
            ]);
            $userAttributes['agent_group_id'] = (int) $agentGroup->id;
        }

        $user = User::query()->create($userAttributes);

        $product = Product::query()->create([
            'name' => 'Renew Discount Product '.$suffix,
            'product_type' => 'server',
            'pricing' => ['monthly' => '99.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 0,
        ]);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => 'Renew Discount Service '.$suffix,
            'domain' => $suffix.'.example.test',
            'billing_cycle' => 'monthly',
            'amount' => '99.00',
            'status' => ServiceStatus::ACTIVE,
            'locked_pricing' => [],
            'provision_data' => [],
            'expires_at' => now()->addMonth(),
            'auto_renew' => 0,
        ]);

        return ['user' => $user, 'product' => $product, 'service' => $service, 'agentGroup' => $agentGroup];
    }
}
