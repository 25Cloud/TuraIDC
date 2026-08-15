<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
use App\Constants\ServiceStatus;
use App\Exceptions\BusinessException;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\User\UserService;
use Tests\TestCase;

/**
 * 用户删除资产保护：无在用服务、无未付账单、余额为 0 才允许删除。
 */
class AdminUserDeleteAssetProtectionTest extends TestCase
{
    public function test_delete_user_succeeds_without_assets(): void
    {
        $user = $this->createUser('delete-ok');

        app(UserService::class)->deleteUser($user, [
            'operator_id' => 9001,
            'operator_name' => 'Delete Tester',
            'trace_id' => 'delete-ok',
        ]);

        $this->assertSoftDeleted('users', ['id' => (int) $user->id]);
        $this->assertDatabaseHas('operation_logs', [
            'module' => 'user',
            'action' => 'user.deleted',
            'subject_id' => (int) $user->id,
        ]);
    }

    public function test_delete_user_rejects_active_service(): void
    {
        $user = $this->createUser('delete-service');
        $product = Product::query()->create([
            'name' => 'Delete Guard Product',
            'product_type' => 'server',
            'pricing' => ['monthly' => '19.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 0,
        ]);
        Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => 'Active Service',
            'billing_cycle' => 'monthly',
            'amount' => '19.00',
            'status' => ServiceStatus::ACTIVE,
        ]);

        $this->assertDeleteRejected($user, '该用户存在在用服务，请先处理服务后再删除');
    }

    public function test_delete_user_rejects_unpaid_invoice(): void
    {
        $user = $this->createUser('delete-invoice');
        Invoice::query()->create([
            'invoice_no' => 'INVDEL'.strtoupper(bin2hex(random_bytes(4))),
            'user_id' => (int) $user->id,
            'type' => 'normal',
            'amount' => '19.00',
            'paid_amount' => '0.00',
            'status' => InvoiceStatus::UNPAID,
            'due_date' => now()->addDay(),
        ]);

        $this->assertDeleteRejected($user, '该用户存在未付账单，请先处理账单后再删除');
    }

    public function test_delete_user_rejects_positive_balance(): void
    {
        $user = $this->createUser('delete-balance');
        $user->forceFill(['balance' => '10.00'])->save();

        $this->assertDeleteRejected($user, '该用户账户仍有余额，请先清零后再删除');
    }

    private function assertDeleteRejected(User $user, string $message): void
    {
        try {
            app(UserService::class)->deleteUser($user, [
                'operator_id' => 9001,
                'operator_name' => 'Delete Tester',
                'trace_id' => 'delete-rejected',
            ]);
            $this->fail('预期删除被拒绝，但未抛出异常');
        } catch (BusinessException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }

        $this->assertNotSoftDeleted('users', ['id' => (int) $user->id]);
    }

    private function createUser(string $prefix): User
    {
        $suffix = bin2hex(random_bytes(4));

        return User::query()->create([
            'email' => $prefix.'-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);
    }
}
