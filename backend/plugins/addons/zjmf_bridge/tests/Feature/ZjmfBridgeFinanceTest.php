<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/../Support/InstallsZjmfBridgeAddon.php';

use App\Constants\InvoiceStatus;
use App\Constants\PaymentGatewayCode;
use App\Constants\PaymentStatus;
use App\Models\AccountTransaction;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\User\AccountService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\Support\InstallsZjmfBridgeAddon;
use Tests\TestCase;
use TuraIDC\Plugins\Addons\ZjmfBridge\Services\ZjmfTokenService;

class ZjmfBridgeFinanceTest extends TestCase
{
    use DatabaseTransactions;
    use InstallsZjmfBridgeAddon;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'zjmf_bridge.enabled' => true,
            'zjmf_bridge.secret' => 'zjmf-test-secret',
            'zjmf_bridge.token_ttl' => 7200,
        ]);
        $this->installZjmfBridgeAddon();
    }

    public function test_client_invoice_and_fund_transaction_routes_use_zjmf_token(): void
    {
        $user = $this->createClientUser();
        $invoice = Invoice::query()->create([
            'invoice_no' => Invoice::generateInvoiceNo(),
            'user_id' => (int) $user->id,
            'type' => 'new',
            'amount' => '120.00',
            'discount' => '20.00',
            'paid_amount' => '100.00',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'config_snapshot' => [
                'hostname' => 'zjmf-test',
                'api_key' => 'should-not-leak',
            ],
            'status' => InvoiceStatus::PAID,
            'due_date' => now()->addDays(7),
            'paid_at' => now(),
        ]);
        $payment = Payment::query()->create([
            'payment_no' => Payment::generatePaymentNo(),
            'user_id' => (int) $user->id,
            'invoice_id' => (int) $invoice->id,
            'gateway_key' => PaymentGatewayCode::ALIPAY,
            'trade_no' => 'trade-zjmf-finance',
            'amount' => '100.00',
            'status' => PaymentStatus::SUCCESS,
            'paid_at' => now(),
        ]);
        $transaction = AccountTransaction::query()->create([
            'user_id' => (int) $user->id,
            'account_type' => 'cash',
            'event_type' => 'invoice_paid',
            'change_amount' => '-100.00',
            'balance_after' => '50.00',
            'source_type' => 'invoice',
            'source_id' => (int) $invoice->id,
            'origin_type' => 'payment',
            'origin_id' => (int) $payment->id,
            'remark' => '支付账单',
            'operator' => 'client',
        ]);
        $headers = ['Authorization' => 'JWT '.$this->jwtFor($user, ['finance.read'])];

        $this
            ->withHeaders($headers)
            ->get('/zjmf/v1/invoices?limit=10', ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('data.list.0.id', (int) $invoice->id)
            ->assertJsonPath('data.list.0.invoiceid', (int) $invoice->id)
            ->assertJsonPath('data.list.0.status', InvoiceStatus::PAID)
            ->assertJsonPath('data.list.0.payment.id', (int) $payment->id);

        $this
            ->withHeaders($headers)
            ->get('/zjmf/v1/invoices/'.$invoice->id, ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('data.invoice.id', (int) $invoice->id)
            ->assertJsonPath('data.invoice.config_snapshot.hostname', 'zjmf-test')
            ->assertJsonMissingPath('data.invoice.config_snapshot.api_key');

        $this
            ->withHeaders($headers)
            ->get('/zjmf/v1/invoices/'.$invoice->id.'/status', ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.paid', true)
            ->assertJsonPath('data.zjmf_status', 1000)
            ->assertJsonPath('data.payment.gateway', PaymentGatewayCode::ALIPAY);

        $this
            ->withHeaders($headers)
            ->get('/zjmf/v1/transactions/funds?limit=10', ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('data.list.0.id', (int) $transaction->id)
            ->assertJsonPath('data.list.0.change_amount', '-100.00')
            ->assertJsonPath('data.list.0.balance_after', '50.00');
    }

    public function test_invoice_fund_pays_by_balance_without_creating_payment_record(): void
    {
        $user = $this->createClientUser();
        app(AccountService::class)->setCashBalance($user, '200.00');
        $invoice = Invoice::query()->create([
            'invoice_no' => Invoice::generateInvoiceNo(),
            'user_id' => (int) $user->id,
            'type' => 'new',
            'amount' => '80.00',
            'discount' => '0.00',
            'paid_amount' => '0.00',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'status' => InvoiceStatus::UNPAID,
            'due_date' => now()->addDays(7),
        ]);

        $this
            ->withHeaders(['Authorization' => 'JWT '.$this->jwtFor($user, ['finance.write'])])
            ->postJson('/zjmf/v1/invoices/'.$invoice->id.'/fund', [])
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('msg', '支付成功')
            ->assertJsonPath('data.gateway', 'balance')
            ->assertJsonPath('data.amount', '80.00')
            ->assertJsonPath('data.cash_balance', '120.00')
            ->assertJsonPath('data.invoice.status', InvoiceStatus::PAID)
            ->assertJsonPath('data.invoice.paid_amount', '80.00');

        $this->assertSame(0, Payment::query()->where('invoice_id', (int) $invoice->id)->count());
        $this->assertSame(InvoiceStatus::PAID, (int) $invoice->fresh()->status);
        $this->assertDatabaseHas('account_transactions', [
            'user_id' => (int) $user->id,
            'source_type' => 'invoice',
            'source_id' => (int) $invoice->id,
            'change_amount' => '-80.00',
            'balance_after' => '120.00',
        ]);
    }

    public function test_payment_records_and_funds_query_use_payment_read_scope(): void
    {
        $user = $this->createClientUser();
        $otherUser = $this->createClientUser();
        $invoice = Invoice::query()->create([
            'invoice_no' => Invoice::generateInvoiceNo(),
            'user_id' => (int) $user->id,
            'type' => 'new',
            'amount' => '66.00',
            'discount' => '0.00',
            'paid_amount' => '66.00',
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
            'trade_no' => 'trade-zjmf-payment',
            'amount' => '66.00',
            'status' => PaymentStatus::SUCCESS,
            'callback_raw' => [
                'public' => 'visible',
                'secret' => 'should-not-leak',
            ],
            'paid_at' => now(),
        ]);
        $otherPayment = Payment::query()->create([
            'payment_no' => Payment::generatePaymentNo(),
            'user_id' => (int) $otherUser->id,
            'gateway_key' => PaymentGatewayCode::ALIPAY,
            'trade_no' => 'trade-zjmf-other',
            'amount' => '12.00',
            'status' => PaymentStatus::SUCCESS,
            'paid_at' => now(),
        ]);
        $headers = ['Authorization' => 'JWT '.$this->jwtFor($user, ['payment.read'])];

        $fundsResponse = $this
            ->withHeaders($headers)
            ->get('/zjmf/v1/funds?limit=10', ['Accept' => 'application/json']);

        $fundsResponse
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('data.payments.list.0.id', (int) $payment->id);

        $paymentsResponse = $this
            ->withHeaders($headers)
            ->get('/zjmf/v1/payments?limit=10', ['Accept' => 'application/json']);

        $paymentsResponse
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('data.list.0.id', (int) $payment->id)
            ->assertJsonPath('data.list.0.paymentid', (int) $payment->id)
            ->assertJsonPath('data.list.0.trans_id', (string) $payment->payment_no)
            ->assertJsonPath('data.list.0.gateway', PaymentGatewayCode::ALIPAY);

        $ids = collect($paymentsResponse->json('data.list'))->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $this->assertNotContains((int) $otherPayment->id, $ids);

        $this
            ->withHeaders($headers)
            ->get('/zjmf/v1/payments/'.$payment->id, ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('data.payment.id', (int) $payment->id)
            ->assertJsonPath('data.payment.callback_raw.public', 'visible')
            ->assertJsonMissingPath('data.payment.callback_raw.secret');
    }

    public function test_recharge_route_requires_available_gateway(): void
    {
        $user = $this->createClientUser();

        $this
            ->withHeaders(['Authorization' => 'JWT '.$this->jwtFor($user, ['payment.write'])])
            ->postJson('/zjmf/v1/funds', [
                'amount' => '50.00',
                'gateway' => PaymentGatewayCode::ALIPAY,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('status', 422)
            ->assertJsonPath('msg', '当前没有可用支付方式，请联系管理员开启支付渠道');
    }

    private function createClientUser(): User
    {
        $suffix = bin2hex(random_bytes(4));

        return User::query()->create([
            'email' => 'zjmf-finance-'.$suffix.'@example.com',
            'phone' => '138'.random_int(10000000, 99999999),
            'password' => 'Secret123!',
            'nickname' => 'ZJMF Finance',
            'status' => 1,
        ]);
    }

    /**
     * @param  list<string>  $scopes
     */
    private function jwtFor(User $user, array $scopes): string
    {
        return app(ZjmfTokenService::class)->issue([
            'sub' => 'client:'.(int) $user->id,
            'uid' => (int) $user->id,
            'scope' => $scopes,
        ], 7200);
    }
}
