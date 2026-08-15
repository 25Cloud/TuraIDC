<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
use App\Constants\PaymentGatewayCode;
use App\Constants\PaymentStatus;
use App\Models\AdminUser;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\User\UserService;
use App\Support\AdminPermissions;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

class V2AdminUserFinanceActionApiTest extends TestCase
{
    public function test_user_status_action_requires_permission_and_uses_explicit_enabled(): void
    {
        $user = $this->createUser(['status' => 1]);

        $this->patchJson('/api/v2/admin/users/'.$user->id.'/status', ['enabled' => false])
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::USER_DETAIL]));

        $this->patchJson('/api/v2/admin/users/'.$user->id.'/status', ['enabled' => false])
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::USER_MANAGE]));

        $this->patchJson('/api/v2/admin/users/'.$user->id.'/status', ['per_page' => 20])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['enabled', 'per_page']]]);

        $response = $this->patchJson('/api/v2/admin/users/'.$user->id.'/status', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.detail.user.status', 0)
            ->assertJsonPath('data.detail.user.enabled', false);

        $this->assertActionResponse($response->json());
        $this->assertSame(0, (int) $user->refresh()->status);
    }

    public function test_service_power_and_password_actions_are_compacted(): void
    {
        $user = $this->createUser();

        $this->mock(UserService::class, function (MockInterface $mock) use ($user): void {
            $mock->shouldReceive('servicePower')
                ->once()
                ->withArgs(function (User $actualUser, int $serviceId, string $action, array $context) use ($user): bool {
                    return (int) $actualUser->id === (int) $user->id
                        && $serviceId === 123
                        && $action === 'reboot'
                        && ($context['actor_type'] ?? null) === 'admin';
                })
                ->andReturn([
                    'action' => 'reboot',
                    'action_label' => '重启',
                    'message' => '重启指令已发送',
                    'status' => [
                        'status' => 'running',
                        'message' => '执行中',
                        'password' => 'must-not-leak',
                        'raw_response' => ['must' => 'not leak'],
                    ],
                    'detail' => ['api_key' => 'must-not-leak'],
                    'secret' => 'must-not-leak',
                ]);

            $mock->shouldReceive('serviceResetPassword')
                ->once()
                ->withArgs(function (User $actualUser, int $serviceId, array $payload, array $context) use ($user): bool {
                    return (int) $actualUser->id === (int) $user->id
                        && $serviceId === 123
                        && ($payload['password'] ?? null) === 'Reset#123456'
                        && ($context['actor_type'] ?? null) === 'admin';
                })
                ->andReturn([
                    'action' => 'password_reset',
                    'message' => '已提交',
                    'status' => [
                        'status' => 'queued',
                        'password' => 'must-not-leak',
                        'raw_response' => ['must' => 'not leak'],
                    ],
                    'password' => 'must-not-leak',
                ]);
        });

        Sanctum::actingAs($this->createAdmin([AdminPermissions::USER_MANAGE]));

        $this->postJson('/api/v2/admin/users/'.$user->id.'/services/123/power-actions', ['per_page' => 20])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['action', 'per_page']]]);

        $powerResponse = $this->postJson('/api/v2/admin/users/'.$user->id.'/services/123/power-actions', [
            'action' => 'reboot',
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.id', 123)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.detail.operation.action', 'reboot')
            ->assertJsonPath('data.detail.operation.status.status', 'running')
            ->assertJsonMissingPath('data.detail.operation.status.password')
            ->assertJsonMissingPath('data.detail.operation.status.raw_response')
            ->assertJsonMissingPath('data.detail.detail')
            ->assertJsonMissingPath('data.detail.secret');

        $this->assertActionResponse($powerResponse->json());

        $this->postJson('/api/v2/admin/users/'.$user->id.'/services/123/password-resets', [
            'password' => 'Reset#123456',
            'per_page' => 20,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['password', 'per_page']]]);

        $passwordResponse = $this->postJson('/api/v2/admin/users/'.$user->id.'/services/123/password-resets', [
            'password' => 'Reset#123456',
            'password_confirmation' => 'Reset#123456',
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.id', 123)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.detail.operation.action', 'password_reset')
            ->assertJsonMissingPath('data.detail.operation.status.password')
            ->assertJsonMissingPath('data.detail.operation.status.raw_response');

        $this->assertActionResponse($passwordResponse->json());
    }

    public function test_invoice_cancellation_uses_invoice_manage_and_preserves_payment_rows(): void
    {
        $fixture = $this->createInvoiceFixture();
        $paymentCount = Payment::query()->count();

        Sanctum::actingAs($this->createAdmin([AdminPermissions::INVOICE_LIST]));

        $this->postJson('/api/v2/admin/invoices/'.$fixture['invoice']->id.'/cancellations')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::INVOICE_MANAGE]));

        $this->postJson('/api/v2/admin/invoices/'.$fixture['invoice']->id.'/cancellations', ['per_page' => 20])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $response = $this->postJson('/api/v2/admin/invoices/'.$fixture['invoice']->id.'/cancellations')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.id', $fixture['invoice']->id)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.detail.invoice.status', InvoiceStatus::CANCELLED);

        $this->assertActionResponse($response->json());
        $this->assertSame($paymentCount, Payment::query()->count());
        $this->assertSame(InvoiceStatus::CANCELLED, (int) $fixture['invoice']->fresh()->status);
    }

    public function test_invoice_and_service_refunds_validate_payload_and_return_small_projection(): void
    {
        $fixture = $this->createInvoiceFixture();

        $this->mock(UserService::class, function (MockInterface $mock) use ($fixture): void {
            $mock->shouldReceive('refundInvoice')
                ->once()
                ->withArgs(function (User $actualUser, int $invoiceId, array $payload, array $context) use ($fixture): bool {
                    return (int) $actualUser->id === (int) $fixture['user']->id
                        && $invoiceId === (int) $fixture['invoice']->id
                        && ($payload['refund_method'] ?? null) === 'balance'
                        && ($context['operator_id'] ?? 0) > 0;
                })
                ->andReturn([
                    'document_links' => [
                        'refund_id' => 101,
                        'refund_invoice_id' => 202,
                        'recharge_record_id' => 303,
                    ],
                    'raw_response' => 'must-not-leak',
                    'api_key' => 'must-not-leak',
                ]);

            $mock->shouldReceive('refundService')
                ->once()
                ->withArgs(function (User $actualUser, int $serviceId, array $payload, array $context) use ($fixture): bool {
                    return (int) $actualUser->id === (int) $fixture['user']->id
                        && $serviceId === 456
                        && ($payload['refund_method'] ?? null) === 'balance'
                        && ($context['operator_id'] ?? 0) > 0;
                })
                ->andReturn([
                    'service_id' => 456,
                    'order_id' => (int) $fixture['order']->id,
                    'order_status' => 5,
                    'already_refunded' => false,
                    'refund' => [
                        'refund_method' => 'balance',
                        'refund_amount' => '10.00',
                        'refund_reason' => '测试退款',
                        'raw_response' => 'must-not-leak',
                        'api_key' => 'must-not-leak',
                    ],
                ]);
        });

        Sanctum::actingAs($this->createAdmin([AdminPermissions::INVOICE_MANAGE, AdminPermissions::USER_MANAGE]));

        $this->postJson('/api/v2/admin/users/'.$fixture['user']->id.'/invoices/'.$fixture['invoice']->id.'/refunds', [
            'refund_method' => 'balance',
            'per_page' => 20,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['remark', 'per_page']]]);

        $invoiceResponse = $this->postJson('/api/v2/admin/users/'.$fixture['user']->id.'/invoices/'.$fixture['invoice']->id.'/refunds', [
            'refund_method' => 'balance',
            'amount' => '10.00',
            'remark' => '测试退款',
            'scope' => ['order', 'payment'],
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.id', $fixture['invoice']->id)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.detail.type', 'invoice_refund')
            ->assertJsonPath('data.detail.documents.refund_id', 101)
            ->assertJsonPath('data.detail.documents.refund_invoice_id', 202)
            ->assertJsonPath('data.detail.documents.recharge_record_id', 303)
            ->assertJsonMissingPath('data.detail.raw_response')
            ->assertJsonMissingPath('data.detail.api_key');

        $this->assertActionResponse($invoiceResponse->json());

        $serviceResponse = $this->postJson('/api/v2/admin/users/'.$fixture['user']->id.'/services/456/refunds', [
            'refund_method' => 'balance',
            'amount' => '10.00',
            'remark' => '测试退款',
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.id', 456)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.detail.refund.refund_amount', '10.00')
            ->assertJsonMissingPath('data.detail.refund.raw_response')
            ->assertJsonMissingPath('data.detail.refund.api_key');

        $this->assertActionResponse($serviceResponse->json());
    }

    /**
     * @return array{user: User, product: Product, order: Order, invoice: Invoice, payment: Payment}
     */
    private function createInvoiceFixture(): array
    {
        $suffix = strtoupper(bin2hex(random_bytes(4)));
        $user = $this->createUser([
            'email' => 'v2-admin-finance-'.$suffix.'@example.com',
            'nickname' => 'V2 Admin Finance '.$suffix,
        ]);

        $product = Product::query()->create([
            'custom_display_name' => 'V2 Admin Finance Product '.$suffix,
            'product_type' => 'vps',
            'service_type_code' => 'vps',
            'pricing' => ['monthly' => '88.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'sort_order' => 1,
            'auto_setup' => 0,
        ]);

        $order = Order::query()->create([
            'order_no' => 'V2ADMACTORD'.$suffix,
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'product_spec_snapshot' => 'V2 Admin Finance Product '.$suffix,
            'product_type_snapshot' => 'vps',
            'type' => 'new',
            'amount' => '88.00',
            'discount' => '0.00',
            'paid_amount' => '0.00',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'status' => 0,
        ]);

        $invoice = Invoice::query()->create([
            'invoice_no' => 'V2ADMACTINV'.$suffix,
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'product_id' => (int) $product->id,
            'product_spec_snapshot' => 'V2 Admin Finance Product '.$suffix,
            'product_type_snapshot' => 'vps',
            'type' => 'new',
            'amount' => '88.00',
            'discount' => '0.00',
            'paid_amount' => '0.00',
            'status' => InvoiceStatus::UNPAID,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'due_date' => now()->addDay(),
            'trace_id' => 'trace-admin-finance-'.$suffix,
        ]);

        $payment = Payment::query()->create([
            'payment_no' => 'V2ADMACTPAY'.$suffix,
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'invoice_id' => (int) $invoice->id,
            'gateway' => PaymentGatewayCode::ALIPAY,
            'gateway_key' => PaymentGatewayCode::ALIPAY,
            'trade_no' => 'V2ADMACTTRADE'.$suffix,
            'amount' => '88.00',
            'status' => PaymentStatus::PENDING,
            'callback_raw' => [
                'raw_response' => 'must-not-leak',
                'api_key' => 'must-not-leak',
            ],
            'trace_id' => 'trace-admin-finance-payment-'.$suffix,
        ]);

        return [
            'user' => $user,
            'product' => $product,
            'order' => $order,
            'invoice' => $invoice,
            'payment' => $payment,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createUser(array $overrides = []): User
    {
        $suffix = bin2hex(random_bytes(4));

        return User::query()->create(array_replace([
            'email' => 'v2-admin-user-action-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'V2 Admin User Action '.$suffix,
            'real_name' => '',
            'id_card' => '',
            'verification_status' => 0,
            'verification_message' => '',
            'verification_certify_id' => null,
            'member_level_id' => null,
            'total_sales_amount' => '0.00',
            'referrer_user_id' => null,
            'verified_at' => null,
        ], $overrides));
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'v2-admin-user-finance-action-'.$suffix,
            'label' => 'V2 Admin User Finance Action',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'v2-admin-user-finance-action-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'V2 Admin User Finance Action',
            'email' => 'v2-admin-user-finance-action-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }

    private function assertActionResponse(array $payload): void
    {
        $this->assertSame(['id', 'status', 'message', 'detail'], array_keys($payload['data']));
        $this->assertNoSensitiveKeys($payload);
        $this->assertLessThan(100 * 1024, strlen((string) json_encode($payload, JSON_UNESCAPED_UNICODE)));
    }

    private function assertNoSensitiveKeys(mixed $payload): void
    {
        if (! is_array($payload)) {
            return;
        }

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                foreach (['password', 'secret', 'api_key', 'raw_response', 'third_party_response', 'token'] as $needle) {
                    $this->assertStringNotContainsString($needle, strtolower($key));
                }
            }

            $this->assertNoSensitiveKeys($value);
        }
    }
}
