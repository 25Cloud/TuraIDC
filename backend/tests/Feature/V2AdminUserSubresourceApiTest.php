<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Resources\Finance\FinanceLedgerResource;
use App\Models\AccountTransaction;
use App\Models\AdminUser;
use App\Models\OperationLog;
use App\Models\Product;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Services\User\UserService;
use App\Support\AdminPermissions;
use Illuminate\Pagination\LengthAwarePaginator;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

class V2AdminUserSubresourceApiTest extends TestCase
{
    public function test_user_service_subresources_require_permissions_and_strip_sensitive_fields(): void
    {
        $user = $this->createUser('services');
        $product = $this->createProduct();
        $serviceDetail = $this->serviceDetailPayload(321);

        $this->mock(UserService::class, function (MockInterface $mock) use ($user, $serviceDetail): void {
            $mock->shouldReceive('services')
                ->once()
                ->withArgs(fn (User $actualUser, array $filters, int $perPage): bool => (int) $actualUser->id === (int) $user->id
                    && ($filters['keyword'] ?? '') === 'vm'
                    && $perPage === 10)
                ->andReturn([
                    'list' => [[
                        'id' => 321,
                        'name' => 'VM 321',
                        'product_display_name' => 'VPS Pro',
                        'domain' => 'vm321.example.test',
                        'status' => 1,
                        'status_label' => '运行中',
                        'status_tone' => 'success',
                        'billing_cycle' => 'monthly',
                        'billing_cycle_label' => '月付',
                        'amount' => '88.00',
                        'product' => ['name' => 'VPS', 'display_name' => 'VPS Pro', 'password' => 'must-not-leak'],
                        'invoice' => ['id' => 99, 'invoice_no' => 'INV321'],
                        'upstream' => ['host_id' => 456, 'status' => 'Active', 'dedicated_ip' => '203.0.113.10'],
                        'connection' => ['password' => 'must-not-leak'],
                        'raw_response' => ['must' => 'not leak'],
                    ]],
                    'total' => 1,
                    'page' => 1,
                    'page_size' => 10,
                ]);

            $mock->shouldReceive('serviceRemoteStatusPatch')
                ->once()
                ->withArgs(fn (User $actualUser, int $serviceId, bool $includeSensitive): bool => (int) $actualUser->id === (int) $user->id
                    && $serviceId === 321
                    && $includeSensitive)
                ->andReturn($serviceDetail);

            $mock->shouldReceive('refreshServiceStatuses')
                ->once()
                ->withArgs(fn (User $actualUser, array $ids): bool => (int) $actualUser->id === (int) $user->id
                    && $ids === [321])
                ->andReturn([
                    ['id' => 321, 'status' => 'Active', 'password' => 'must-not-leak'],
                ]);

            $mock->shouldReceive('createManualService')
                ->once()
                ->andReturn($serviceDetail);
            $mock->shouldReceive('updateServiceMeta')
                ->once()
                ->andReturn($serviceDetail);
            $mock->shouldReceive('manualProvisionService')
                ->once()
                ->andReturn($serviceDetail);
            $mock->shouldReceive('deleteService')
                ->once();
        });

        $this->getJson('/api/v2/admin/users/'.$user->id.'/services')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::USER_LIST]));
        $this->getJson('/api/v2/admin/users/'.$user->id.'/services')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::USER_DETAIL]));
        $this->getJson('/api/v2/admin/users/'.$user->id.'/services?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $listResponse = $this->getJson('/api/v2/admin/users/'.$user->id.'/services?keyword=vm&page_size=10')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.id', 321)
            ->assertJsonMissingPath('data.list.0.connection')
            ->assertJsonMissingPath('data.list.0.product.password')
            ->assertJsonMissingPath('data.list.0.raw_response');

        $this->assertSame($this->serviceListFields(), array_keys($listResponse->json('data.list.0')));
        $this->assertNoSensitiveKeys($listResponse->json());

        $remoteResponse = $this->getJson('/api/v2/admin/users/'.$user->id.'/services/321/remote-status')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.service.id', 321)
            ->assertJsonMissingPath('data.service.billing_cycle')
            ->assertJsonMissingPath('data.service.amount')
            ->assertJsonMissingPath('data.service.invoice')
            ->assertJsonMissingPath('data.service.connection')
            ->assertJsonMissingPath('data.service.raw_response');

        $this->assertSame($this->serviceRuntimeFields(), array_keys($remoteResponse->json('data.service')));
        $this->assertNoSensitiveKeys($remoteResponse->json());

        Sanctum::actingAs($this->createAdmin([AdminPermissions::USER_MANAGE]));
        $this->postJson('/api/v2/admin/users/'.$user->id.'/services/refresh-statuses', [
            'service_ids' => [321],
            'per_page' => 20,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $refreshResponse = $this->postJson('/api/v2/admin/users/'.$user->id.'/services/refresh-statuses', [
            'service_ids' => [321],
        ])
            ->assertOk()
            ->assertJsonPath('data.result.refreshed_count', 1)
            ->assertJsonMissingPath('data.result.services.0.password');
        $this->assertNoSensitiveKeys($refreshResponse->json());

        $storeResponse = $this->postJson('/api/v2/admin/users/'.$user->id.'/services', [
            'product_id' => (int) $product->id,
            'billing_cycle' => 'monthly',
            'source_type' => 'manual',
            'status' => 1,
            'auto_renew' => 0,
            'create_order' => 1,
            'create_invoice' => 1,
            'deduct_balance' => 1,
        ])
            ->assertOk()
            ->assertJsonPath('data.service.id', 321);
        $this->assertNoSensitiveKeys($storeResponse->json());

        $metaResponse = $this->putJson('/api/v2/admin/users/'.$user->id.'/services/321/meta', [
            'amount' => '99.00',
        ])
            ->assertOk()
            ->assertJsonPath('data.service.id', 321);
        $this->assertNoSensitiveKeys($metaResponse->json());

        $manualProvisionResponse = $this->putJson('/api/v2/admin/users/'.$user->id.'/services/321/manual-provision', [
            'remark' => 'retry',
        ])
            ->assertOk()
            ->assertJsonPath('data.service.id', 321);
        $this->assertNoSensitiveKeys($manualProvisionResponse->json());

        $this->deleteJson('/api/v2/admin/users/'.$user->id.'/services/321')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data', null);
    }

    public function test_user_invoice_log_subresources_return_v2_pagination_and_strip_sensitive_fields(): void
    {
        $user = $this->createUser('logs');

        $this->mock(UserService::class, function (MockInterface $mock) use ($user): void {
            $mock->shouldReceive('invoices')
                ->once()
                ->andReturn($this->paginator([[
                    'id' => 101,
                    'invoice_no' => 'INV101',
                    'status' => 1,
                    'amount' => '88.00',
                    'payment_summary' => ['gateway' => 'alipay', 'api_key' => 'must-not-leak'],
                    'raw_response' => 'must-not-leak',
                ]]));

            $mock->shouldReceive('invoiceDetail')
                ->once()
                ->withArgs(fn (User $actualUser, int $invoiceId): bool => (int) $actualUser->id === (int) $user->id
                    && $invoiceId === 101)
                ->andReturn([
                    'id' => 101,
                    'invoice_no' => 'INV101',
                    'status' => 1,
                    'amount' => '88.00',
                    'payment_summary' => ['gateway' => 'alipay', 'api_key' => 'must-not-leak'],
                    'payments' => [['id' => 1, 'payment_no' => 'PAY101', 'raw_response' => 'must-not-leak']],
                    'items' => [['id' => 1, 'description' => 'VPS']],
                    'logs' => [['id' => 1, 'detail' => ['secret' => 'must-not-leak']]],
                ]);

            $mock->shouldReceive('balanceLogs')
                ->once()
                ->andReturn([
                    'paginator' => $this->paginator([$this->accountTransaction($user)]),
                    'resource_class' => FinanceLedgerResource::class,
                    'summary' => ['total_income' => 88.0, 'total_expense' => 0.0],
                ]);

            $mock->shouldReceive('tickets')
                ->once()
                ->andReturn([
                    'paginator' => $this->paginator([$this->ticket()]),
                    'summary' => ['this_month' => 1],
                ]);

            $mock->shouldReceive('operationLogs')
                ->once()
                ->withArgs(fn (int $userId, array $filters, int $perPage): bool => $userId === (int) $user->id
                    && ($filters['keyword'] ?? '') === 'login'
                    && $perPage === 10)
                ->andReturn($this->paginator([$this->operationLog($user)]));

            $mock->shouldReceive('smsLogs')
                ->once()
                ->andReturn($this->paginator([[
                    'id' => 201,
                    'phone' => '139****0000',
                    'template_code' => 'login_code',
                    'content' => '验证码 123456',
                    'params_json' => ['code' => '123456', 'api_key' => 'must-not-leak'],
                    'status' => 'success',
                    'created_at' => now(),
                ]]));

            $mock->shouldReceive('emailLogs')
                ->once()
                ->andReturn($this->paginator([[
                    'id' => 202,
                    'to_email' => 'u***@example.com',
                    'subject' => '登录提醒',
                    'content' => 'full content must not leak',
                    'status' => 'success',
                    'created_at' => now(),
                ]]));
        });

        Sanctum::actingAs($this->createAdmin([AdminPermissions::USER_DETAIL]));

        $this->getJson('/api/v2/admin/users/'.$user->id.'/invoices?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $invoiceListResponse = $this->getJson('/api/v2/admin/users/'.$user->id.'/invoices?page_size=10')
            ->assertOk()
            ->assertJsonPath('data.list.0.id', 101)
            ->assertJsonMissingPath('data.list.0.raw_response')
            ->assertJsonMissingPath('data.list.0.payment_summary.api_key');
        $this->assertNoSensitiveKeys($invoiceListResponse->json());

        $invoiceDetailResponse = $this->getJson('/api/v2/admin/users/'.$user->id.'/invoices/101')
            ->assertOk()
            ->assertJsonPath('data.invoice.id', 101)
            ->assertJsonMissingPath('data.invoice.payment_summary.api_key')
            ->assertJsonMissingPath('data.payments.0.raw_response')
            ->assertJsonMissingPath('data.logs.0.detail.secret');
        $this->assertSame(['invoice', 'payments', 'items', 'logs'], array_keys($invoiceDetailResponse->json('data')));
        $this->assertNoSensitiveKeys($invoiceDetailResponse->json());

        $balanceResponse = $this->getJson('/api/v2/admin/users/'.$user->id.'/balance-logs?page_size=10')
            ->assertOk()
            ->assertJsonPath('data.list.0.ledger_id', 301)
            ->assertJsonPath('data.summary.total_income', 88);
        $this->assertNoSensitiveKeys($balanceResponse->json());

        $ticketResponse = $this->getJson('/api/v2/admin/users/'.$user->id.'/tickets?page_size=10')
            ->assertOk()
            ->assertJsonPath('data.list.0.id', 401)
            ->assertJsonPath('data.summary.this_month', 1);
        $this->assertNoSensitiveKeys($ticketResponse->json());

        $operationLogResponse = $this->getJson('/api/v2/admin/users/'.$user->id.'/operation-logs?keyword=login&page_size=10')
            ->assertOk()
            ->assertJsonPath('data.list.0.id', 501)
            ->assertJsonMissingPath('data.list.0.context.secret');
        $this->assertNoSensitiveKeys($operationLogResponse->json());

        $smsResponse = $this->getJson('/api/v2/admin/users/'.$user->id.'/sms-logs?page_size=10')
            ->assertOk()
            ->assertJsonPath('data.list.0.id', 201)
            ->assertJsonMissingPath('data.list.0.content')
            ->assertJsonMissingPath('data.list.0.params_json');
        $this->assertNoSensitiveKeys($smsResponse->json());

        $emailResponse = $this->getJson('/api/v2/admin/users/'.$user->id.'/email-logs?page_size=10')
            ->assertOk()
            ->assertJsonPath('data.list.0.id', 202)
            ->assertJsonMissingPath('data.list.0.content');
        $this->assertNoSensitiveKeys($emailResponse->json());

        foreach ([$invoiceListResponse, $invoiceDetailResponse, $balanceResponse, $ticketResponse, $operationLogResponse, $smsResponse, $emailResponse] as $response) {
            $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
        }
    }

    private function createUser(string $prefix): User
    {
        $suffix = bin2hex(random_bytes(4));

        return User::query()->create([
            'email' => 'v2-user-subresource-'.$prefix.'-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'V2 User Subresource '.$prefix,
            'real_name' => '',
            'id_card' => '',
            'verification_status' => 0,
            'verification_message' => '',
            'verification_certify_id' => null,
            'member_level_id' => null,
            'total_sales_amount' => '0.00',
            'referrer_user_id' => null,
            'verified_at' => null,
        ]);
    }

    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'v2-user-subresource-'.$suffix,
            'label' => 'V2 User Subresource',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'v2-user-subresource-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'V2 User Subresource',
            'email' => 'v2-user-subresource-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }

    private function serviceDetailPayload(int $id): array
    {
        return [
            'id' => $id,
            'name' => 'VM '.$id,
            'product_display_name' => 'VPS Pro',
            'combined_display_name' => 'VPS Pro / VM '.$id,
            'domain' => 'vm'.$id.'.example.test',
            'status' => 1,
            'status_label' => '运行中',
            'status_tone' => 'success',
            'billing_cycle' => 'monthly',
            'billing_cycle_label' => '月付',
            'amount' => '88.00',
            'expires_at' => now()->addMonth()->format('Y-m-d H:i:s'),
            'created_at' => now()->format('Y-m-d H:i:s'),
            'auto_renew' => 1,
            'product' => ['id' => 1, 'name' => 'VPS', 'display_name' => 'VPS Pro'],
            'invoice' => ['id' => 99, 'invoice_no' => 'INV321'],
            'upstream' => ['provider_key' => 'zjmf_finance_api', 'host_id' => 456, 'status' => 'Active'],
            'runtime' => ['power_state' => 'running', 'power_label' => '运行中'],
            'connection' => ['password' => 'must-not-leak'],
            'actions' => ['refresh' => true, 'available' => ['power:reboot']],
            'raw_response' => ['must' => 'not leak'],
        ];
    }

    private function paginator(array $items, int $perPage = 10): LengthAwarePaginator
    {
        return new LengthAwarePaginator($items, count($items), $perPage, 1);
    }

    private function accountTransaction(User $user): AccountTransaction
    {
        $transaction = (new AccountTransaction)->forceFill([
            'id' => 301,
            'user_id' => (int) $user->id,
            'account_type' => 'cash',
            'event_type' => 'manual_recharge',
            'change_amount' => '88.00',
            'balance_after' => '188.00',
            'remark' => '测试入账',
            'created_at' => now(),
        ]);

        $transaction->setRelation('invoice', null);
        $transaction->setRelation('payment', null);
        $transaction->setRelation('user', $user);

        return $transaction;
    }

    private function ticket(): Ticket
    {
        return (new Ticket)->forceFill([
            'id' => 401,
            'subject' => '测试工单',
            'department' => 'support',
            'priority' => 2,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function operationLog(User $user): OperationLog
    {
        return (new OperationLog)->forceFill([
            'id' => 501,
            'user_id' => (int) $user->id,
            'user_type' => 'client',
            'action' => 'login.success',
            'module' => 'auth',
            'subject_id' => (int) $user->id,
            'context' => ['ip' => '127.0.0.1', 'secret' => 'must-not-leak'],
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
        ]);
    }

    private function createProduct(): Product
    {
        $suffix = bin2hex(random_bytes(4));

        return Product::query()->create([
            'name' => 'V2 User Subresource Product '.$suffix,
            'custom_display_name' => 'V2 User Subresource Product '.$suffix,
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
    }

    /**
     * @return list<string>
     */
    private function serviceListFields(): array
    {
        return [
            'id',
            'name',
            'product_display_name',
            'product_full_path',
            'domain',
            'custom_hostname',
            'has_custom_hostname',
            'status',
            'status_label',
            'status_tone',
            'billing_cycle',
            'billing_cycle_label',
            'amount',
            'expires_at',
            'created_at',
            'product',
            'invoice',
            'custom_service_name',
            'has_custom_service_name',
            'upstream',
            'remark',
            'can_manage',
            'console_mode',
            'is_nat_console',
            'machine_category',
            'specs',
        ];
    }

    /**
     * @return list<string>
     */
    private function serviceRuntimeFields(): array
    {
        return [
            'id',
            'status',
            'status_label',
            'status_tone',
            'expires_at',
            'upstream',
            'runtime',
            'traffic',
            'actions',
            'specs',
        ];
    }

    private function assertNoSensitiveKeys(mixed $payload): void
    {
        if (! is_array($payload)) {
            return;
        }

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $normalized = strtolower($key);

                if (! in_array($normalized, ['has_password', 'password_reset'], true)) {
                    $this->assertStringNotContainsString('password', $normalized);
                }

                foreach (['secret', 'api_key', 'raw_response', 'third_party_response', 'token'] as $needle) {
                    $this->assertStringNotContainsString($needle, $normalized);
                }
            }

            $this->assertNoSensitiveKeys($value);
        }
    }
}
