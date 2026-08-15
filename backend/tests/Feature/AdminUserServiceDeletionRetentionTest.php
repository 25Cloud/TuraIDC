<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Constants\ServiceStatus;
use App\Models\AdminUser;
use App\Models\FirstProductGroup;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\SecondProductGroup;
use App\Models\Service;
use App\Models\ThirdProductGroup;
use App\Models\User;
use App\Support\AdminPermissions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminUserServiceDeletionRetentionTest extends TestCase
{
    public function test_deleting_service_record_keeps_order_and_invoice_records(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $admin = $this->createAdminUser($suffix);
        $user = $this->createClientUser($suffix);
        $product = $this->createProduct($suffix);

        $order = Order::query()->create([
            'order_no' => 'DEL-SVC-ORD-'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'type' => 'new',
            'amount' => '99.00',
            'discount' => '0.00',
            'paid_amount' => '99.00',
            'billing_cycle' => 'monthly',
            'config_snapshot' => [],
            'config_pricing_snapshot' => [],
            'coupon_snapshot' => [],
            'status' => OrderStatus::COMPLETED,
            'paid_at' => now(),
        ]);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'order_id' => (int) $order->id,
            'name' => 'Retention Service '.$suffix,
            'domain' => '',
            'billing_cycle' => 'monthly',
            'amount' => '99.00',
            'status' => ServiceStatus::ACTIVE,
            'locked_pricing' => ['monthly' => '99.00'],
            'provision_data' => [],
            'expires_at' => now()->addMonth(),
            'auto_renew' => 1,
        ]);

        $order->forceFill([
            'service_id' => (int) $service->id,
        ])->save();

        $invoice = Invoice::query()->create([
            'invoice_no' => 'DEL-SVC-INV-'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'type' => 'normal',
            'amount' => '99.00',
            'paid_amount' => '99.00',
            'status' => InvoiceStatus::PAID,
            'due_date' => now()->addDay(),
            'paid_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $this->deleteJson('/api/v2/admin/users/'.$user->id.'/services/'.$service->id)
            ->assertOk()
            ->assertJsonPath('code', 0);

        // services 已启用软删除，记录保留但标记 deleted_at
        $this->assertSoftDeleted('services', [
            'id' => (int) $service->id,
        ]);

        $this->assertDatabaseHas('orders', [
            'id' => (int) $order->id,
            'service_id' => null,
        ]);

        $this->assertDatabaseHas('invoices', [
            'id' => (int) $invoice->id,
            'order_id' => (int) $order->id,
            'status' => InvoiceStatus::PAID,
        ]);
    }

    private function createAdminUser(string $suffix): AdminUser
    {
        $role = Role::query()->create([
            'name' => 'delete-service-retention-'.$suffix,
            'label' => 'Delete Service Retention',
            'permissions' => [AdminPermissions::ALL],
        ]);

        return AdminUser::query()->create([
            'username' => 'delete-service-admin-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Delete Service Admin',
            'email' => 'delete-service-admin-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }

    private function createClientUser(string $suffix): User
    {
        return User::query()->create([
            'email' => 'delete-service-user-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Delete Service User',
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

    private function createProduct(string $suffix): Product
    {
        $group = FirstProductGroup::query()->create([
            'code' => 'server-'.$suffix,
            'name' => 'Delete Service Group '.$suffix,
            'slug' => 'delete-service-group-'.$suffix,
            'description' => '',
            'is_visible' => 1,
            'sort_order' => 0,
        ]);
        $secondGroup = SecondProductGroup::query()->create([
            'first_product_group_id' => (int) $group->id,
            'name' => 'Delete Service Second '.$suffix,
            'slug' => 'delete-service-second-'.$suffix,
            'description' => '',
            'is_visible' => 1,
            'sort_order' => 0,
        ]);
        $thirdGroup = ThirdProductGroup::query()->create([
            'second_product_group_id' => (int) $secondGroup->id,
            'name' => 'Delete Service Third '.$suffix,
            'slug' => 'delete-service-third-'.$suffix,
            'description' => '',
            'is_visible' => 1,
            'sort_order' => 0,
        ]);

        return Product::query()->create([
            'product_group_id' => (int) $thirdGroup->id,
            'name' => 'Delete Service Product '.$suffix,
            'product_type' => 'server',
            'description' => '',
            'pricing' => ['monthly' => '99.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => 10,
            'status' => 1,
            'sort_order' => 0,
            'provision_module' => null,
            'auto_setup' => 0,
        ]);
    }
}
