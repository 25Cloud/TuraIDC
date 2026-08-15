<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/../Support/InstallsZjmfBridgeAddon.php';

use App\Constants\InvoiceStatus;
use App\Constants\PaymentGatewayCode;
use App\Constants\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\Support\InstallsZjmfBridgeAddon;
use Tests\TestCase;

class ZjmfBridgeReconcileTest extends TestCase
{
    use DatabaseTransactions;
    use InstallsZjmfBridgeAddon;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'zjmf_bridge.enabled' => true,
            'zjmf_bridge.app_id' => 'zjmf-test',
            'zjmf_bridge.secret' => 'zjmf-test-secret',
            'zjmf_bridge.allowed_ips' => [],
            'zjmf_bridge.signature_tolerance' => 300,
            'zjmf_bridge.system_scopes' => ['system.reconcile'],
        ]);
        $this->installZjmfBridgeAddon();
    }

    public function test_reconcile_payment_and_invoice_queries_use_system_hmac_scope(): void
    {
        $user = $this->createClientUser();
        $invoice = Invoice::query()->create([
            'invoice_no' => Invoice::generateInvoiceNo(),
            'user_id' => (int) $user->id,
            'type' => 'new',
            'amount' => '99.00',
            'discount' => '0.00',
            'paid_amount' => '99.00',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'status' => InvoiceStatus::PAID,
            'due_date' => now()->addDays(7),
            'paid_at' => now(),
        ]);
        $payment = Payment::query()->create([
            'payment_no' => Payment::generatePaymentNo(),
            'user_id' => (int) $user->id,
            'invoice_id' => (int) $invoice->id,
            'gateway_key' => PaymentGatewayCode::ALIPAY,
            'trade_no' => 'trade-zjmf-reconcile',
            'amount' => '99.00',
            'status' => PaymentStatus::SUCCESS,
            'paid_at' => now(),
        ]);
        $query = [
            'from' => now()->subDay()->format('Y-m-d'),
            'to' => now()->format('Y-m-d'),
            'limit' => 10,
        ];

        $this
            ->withHeaders($this->signedHeaders('GET', '/zjmf/v1/reconcile/payments', $query))
            ->get($this->url('/zjmf/v1/reconcile/payments', $query), ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('data.list.0.id', (int) $payment->id)
            ->assertJsonPath('data.list.0.invoice_no', (string) $invoice->invoice_no)
            ->assertJsonPath('data.page_size', 10)
            ->assertJsonPath('data.window.max_days', 31);

        $this
            ->withHeaders($this->signedHeaders('GET', '/zjmf/v1/reconcile/invoices', $query))
            ->get($this->url('/zjmf/v1/reconcile/invoices', $query), ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('data.list.0.id', (int) $invoice->id)
            ->assertJsonPath('data.list.0.payment_no', (string) $payment->payment_no)
            ->assertJsonPath('data.window.max_days', 31);
    }

    public function test_reconcile_rejects_large_query_window(): void
    {
        $query = [
            'from' => now()->subDays(40)->format('Y-m-d'),
            'to' => now()->format('Y-m-d'),
        ];

        $this
            ->withHeaders($this->signedHeaders('GET', '/zjmf/v1/reconcile/payments', $query))
            ->get($this->url('/zjmf/v1/reconcile/payments', $query), ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('status', 422)
            ->assertJsonPath('msg', '对账查询窗口不能超过 31 天');
    }

    private function createClientUser(): User
    {
        $suffix = bin2hex(random_bytes(4));

        return User::query()->create([
            'email' => 'zjmf-reconcile-'.$suffix.'@example.com',
            'phone' => '135'.random_int(10000000, 99999999),
            'password' => 'Secret123!',
            'nickname' => 'ZJMF Reconcile',
            'status' => 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, string>
     */
    private function signedHeaders(
        string $method,
        string $path,
        array $query = [],
        string $body = '',
        ?int $timestamp = null,
        ?string $nonce = null,
    ): array {
        $timestamp ??= time();
        $nonce ??= 'nonce-'.bin2hex(random_bytes(8));
        ksort($query);
        $canonical = implode("\n", [
            strtoupper($method),
            $path,
            http_build_query($query, '', '&', PHP_QUERY_RFC3986),
            hash('sha256', $body),
            (string) $timestamp,
            $nonce,
        ]);

        return [
            'X-ZJMF-App-Id' => 'zjmf-test',
            'X-ZJMF-Timestamp' => (string) $timestamp,
            'X-ZJMF-Nonce' => $nonce,
            'X-ZJMF-Signature' => hash_hmac('sha256', $canonical, 'zjmf-test-secret'),
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function url(string $path, array $query = []): string
    {
        return $query === [] ? $path : $path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
