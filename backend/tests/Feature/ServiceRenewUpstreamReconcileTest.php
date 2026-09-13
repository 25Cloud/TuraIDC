<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use App\Services\Provisioning\ServiceRenewService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 续费上游金额对账回归。
 *
 * 代理折扣是本地让利，上游按原价实扣供应商余额：带代理折扣的账单应以
 * original_renew_amount 为对账基准（折扣差为预期，不打告警），与原价仍不一致
 * 才是真异常。无折扣账单沿用历史口径（与本地应收比对）。
 * 此前口径直接拿上游实扣与本地折后应收比对，有代理折扣时每次上游续费都误报告警。
 */
class ServiceRenewUpstreamReconcileTest extends TestCase
{
    use DatabaseTransactions;

    public function test带代理折扣时按原价对账且折扣差不告警(): void
    {
        Log::spy();
        $invoice = $this->makeRenewInvoice([
            'agent_discount_rate' => 85.0,
            'agent_discount_amount' => '14.85',
            'original_renew_amount' => '99.00',
        ]);

        $this->invokeReconcile($invoice, '99.00');

        Log::shouldNotHaveReceived('warning');
    }

    public function test带代理折扣但上游实扣连原价都对不上时告警(): void
    {
        Log::spy();
        $invoice = $this->makeRenewInvoice([
            'agent_discount_rate' => 85.0,
            'agent_discount_amount' => '14.85',
            'original_renew_amount' => '99.00',
        ]);

        $this->invokeReconcile($invoice, '88.00');

        Log::shouldHaveReceived('warning', function (string $message, array $context): bool {
            return str_contains($message, '已剔除代理折扣差')
                && (float) ($context['local_original_amount'] ?? 0) === 99.0
                && (float) ($context['upstream_amount'] ?? 0) === 88.0;
        });
    }

    public function test无折扣账单沿用本地应收口径(): void
    {
        Log::spy();
        $invoice = $this->makeRenewInvoice([]);

        // 一致 → 静默
        $this->invokeReconcile($invoice, '84.15');
        Log::shouldNotHaveReceived('warning');

        // 不一致 → 原口径告警
        $this->invokeReconcile($invoice, '88.00');
        Log::shouldHaveReceived('warning', function (string $message): bool {
            return str_contains($message, '上游实扣金额与本地应收不一致');
        });
    }

    private function invokeReconcile(Invoice $invoice, string $upstreamAmount): void
    {
        $service = app(ServiceRenewService::class);
        $method = new \ReflectionMethod($service, 'reconcileRenewUpstreamAmount');
        $method->invoke($service, $invoice, ['upstream_amount' => $upstreamAmount]);
    }

    private function makeRenewInvoice(array $configSnapshot): Invoice
    {
        $suffix = bin2hex(random_bytes(4));
        $user = User::query()->create([
            'email' => "renew-reconcile-{$suffix}@example.test",
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);

        return Invoice::query()->create([
            'invoice_no' => 'RC'.$suffix,
            'user_id' => (int) $user->id,
            'type' => 'renew',
            'amount' => '84.15',
            'paid_amount' => '84.15',
            'status' => 1,
            'config_snapshot' => $configSnapshot === [] ? null : $configSnapshot,
        ]);
    }
}
