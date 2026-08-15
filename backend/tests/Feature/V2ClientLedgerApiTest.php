<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
use App\Constants\PaymentGatewayCode;
use App\Constants\PaymentStatus;
use App\Models\AccountTransaction;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class V2ClientLedgerApiTest extends TestCase
{
    public function test_client_ledger_requires_auth_rejects_legacy_params_and_returns_summary_list(): void
    {
        $fixture = $this->createLedgerFixture();

        $this->getJson('/api/v2/client/ledger')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($fixture['user']);

        $this->getJson('/api/v2/client/ledger?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->getJson('/api/v2/client/ledger?type=recharge')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['type']]]);

        $response = $this->getJson('/api/v2/client/ledger?'.http_build_query([
            'service_id' => $fixture['service']->id,
            'page' => 1,
            'page_size' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.list.0.id', $fixture['transaction']->id)
            ->assertJsonPath('data.list.0.invoice.invoice_no', (string) $fixture['invoice']->invoice_no)
            ->assertJsonPath('data.list.0.payment.payment_no', (string) $fixture['payment']->payment_no)
            ->assertJsonPath('data.summary.total_count', 1)
            ->assertJsonMissingPath('data.list.0.user')
            ->assertJsonMissingPath('data.list.0.trace_id')
            ->assertJsonMissingPath('data.list.0.source_type')
            ->assertJsonMissingPath('data.list.0.payment.trade_no')
            ->assertJsonMissingPath('data.list.0.payment.callback_raw');

        $this->assertSame($this->ledgerListWhitelist(), array_keys($response->json('data.list.0')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
    }

    public function test_client_ledger_is_owner_scoped_and_does_not_mutate_payments(): void
    {
        $owner = $this->createLedgerFixture('owner');
        $other = $this->createLedgerFixture('other');

        Sanctum::actingAs($owner['user']);
        $paymentCount = Payment::query()->count();

        $this->getJson('/api/v2/client/ledger?'.http_build_query([
            'invoice_no' => $other['invoice']->invoice_no,
            'page' => 1,
            'page_size' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonCount(0, 'data.list')
            ->assertJsonPath('data.total', 0);

        $response = $this->getJson('/api/v2/client/ledger?'.http_build_query([
            'invoice_no' => $owner['invoice']->invoice_no,
            'page' => 1,
            'page_size' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.list.0.id', $owner['transaction']->id)
            ->assertJsonPath('data.total', 1);

        $this->assertSame($paymentCount, Payment::query()->count());
        $this->assertSame($this->ledgerPageWhitelist(), array_keys($response->json('data')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
    }

    /**
     * @return array{user: User, product: Product, invoice: Invoice, service: Service, payment: Payment, transaction: AccountTransaction}
     */
    private function createLedgerFixture(string $prefix = 'ledger'): array
    {
        $suffix = strtoupper(bin2hex(random_bytes(4)));
        $user = User::query()->create([
            'email' => 'v2-ledger-'.$prefix.'-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'V2 Ledger '.$suffix,
            'real_name' => '',
            'id_card' => '',
            'verification_status' => 0,
            'verification_message' => '',
            'verification_certify_id' => null,
            'member_level_id' => null,
            'total_sales_amount' => '0.00',
            'referrer_user_id' => null,
            'verified_at' => null,
            'balance' => '125.00',
        ]);

        $product = Product::query()->create([
            'custom_display_name' => 'V2 Ledger Product '.$suffix,
            'product_type' => 'finance',
            'service_type_code' => 'finance',
            'pricing' => ['monthly' => '25.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'sort_order' => 1,
            'auto_setup' => 0,
        ]);

        $invoice = Invoice::query()->create([
            'invoice_no' => 'V2LEDGER'.$suffix,
            'user_id' => (int) $user->id,
            'type' => 'recharge',
            'product_id' => (int) $product->id,
            'product_spec_snapshot' => '余额充值',
            'product_type_snapshot' => 'finance',
            'amount' => '25.00',
            'discount' => '0.00',
            'paid_amount' => '25.00',
            'status' => InvoiceStatus::PAID,
            'billing_cycle' => '',
            'quantity' => 1,
            'due_date' => now()->addDay(),
            'paid_at' => now(),
            'config_snapshot' => [
                'display_name' => '余额充值',
                'password' => 'must-not-leak',
            ],
            'config_pricing_snapshot' => [
                'raw_response' => 'must-not-leak',
            ],
            'coupon_snapshot' => [
                'secret' => 'must-not-leak',
            ],
            'trace_id' => 'trace-ledger-invoice-'.$suffix,
        ]);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'invoice_id' => (int) $invoice->id,
            'name' => 'v2-ledger-service-'.$suffix,
            'domain' => 'v2-ledger-'.$suffix.'.example.test',
            'billing_cycle' => 'monthly',
            'amount' => '25.00',
            'status' => 1,
            'expires_at' => now()->addMonth(),
            'provision_data' => [
                'secret' => 'must-not-leak',
            ],
        ]);
        $invoice->forceFill(['service_id' => (int) $service->id])->save();

        $payment = Payment::query()->create([
            'payment_no' => 'V2LEDPAY'.$suffix,
            'user_id' => (int) $user->id,
            'invoice_id' => (int) $invoice->id,
            'gateway' => PaymentGatewayCode::ALIPAY,
            'gateway_key' => PaymentGatewayCode::ALIPAY,
            'trade_no' => 'V2LEDTRADE'.$suffix,
            'amount' => '25.00',
            'status' => PaymentStatus::SUCCESS,
            'callback_raw' => [
                'api_key' => 'must-not-leak',
                'raw_response' => 'must-not-leak',
            ],
            'paid_at' => now(),
            'trace_id' => 'trace-ledger-payment-'.$suffix,
        ]);

        $transaction = AccountTransaction::query()->create([
            'user_id' => (int) $user->id,
            'account_type' => 'cash',
            'event_type' => 'recharge',
            'change_amount' => '25.00',
            'balance_after' => '125.00',
            'source_type' => 'payment',
            'source_id' => (int) $payment->id,
            'origin_type' => 'payment',
            'origin_id' => (int) $payment->id,
            'remark' => '余额充值到账 '.$payment->payment_no,
            'operator' => 'system',
            'trace_id' => 'trace-ledger-'.$suffix,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'user' => $user,
            'product' => $product,
            'invoice' => $invoice->refresh(),
            'service' => $service,
            'payment' => $payment,
            'transaction' => $transaction,
        ];
    }

    /**
     * @return list<string>
     */
    private function ledgerPageWhitelist(): array
    {
        return [
            'list',
            'total',
            'page',
            'page_size',
            'summary',
        ];
    }

    /**
     * @return list<string>
     */
    private function ledgerListWhitelist(): array
    {
        return [
            'id',
            'ledger_id',
            'account_type',
            'event_type',
            'event_type_label',
            'event_category',
            'direction',
            'amount',
            'change_amount',
            'balance_after',
            'occurred_at',
            'created_at',
            'remark',
            'business_scene',
            'business_scene_label',
            'invoice',
            'payment',
            'display',
        ];
    }

    private function assertNoSensitiveKeys(mixed $payload): void
    {
        if (! is_array($payload)) {
            return;
        }

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                foreach (['password', 'secret', 'api_key', 'raw_response', 'third_party_response'] as $needle) {
                    $this->assertStringNotContainsString($needle, strtolower($key));
                }
            }

            $this->assertNoSensitiveKeys($value);
        }
    }
}
