<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\OrderStatus;
use App\Constants\OrderType;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderTypeContractTest extends TestCase
{
    public function test_client_order_list_accepts_upgrade_and_returns_unified_label(): void
    {
        $suffix = strtoupper(bin2hex(random_bytes(4)));
        $user = $this->createClientUser('order-type-'.strtolower($suffix));
        $product = $this->createProduct('Order Type Product '.$suffix);

        $order = Order::query()->create([
            'order_no' => 'ORDTYPE'.$suffix,
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'product_spec_snapshot' => '附加配置测试',
            'product_type_snapshot' => (string) $product->product_type,
            'type' => OrderType::UPGRADE,
            'amount' => '19.90',
            'discount' => '0.00',
            'paid_amount' => '19.90',
            'billing_cycle' => 'one_time',
            'quantity' => 1,
            'config_snapshot' => [],
            'config_pricing_snapshot' => [],
            'coupon_snapshot' => [],
            'status' => OrderStatus::COMPLETED,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v2/client/orders?type=upgrade&page_size=20')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.id', (int) $order->id)
            ->assertJsonPath('data.list.0.type', OrderType::UPGRADE)
            ->assertJsonPath('data.list.0.type_label', '附加配置');
    }

    public function test_client_order_list_rejects_legacy_addon_type(): void
    {
        $user = $this->createClientUser('order-type-reject-'.bin2hex(random_bytes(4)));

        Sanctum::actingAs($user);

        $this->getJson('/api/v2/client/orders?type=addon')
            ->assertStatus(422)
            ->assertJsonPath('code', 42200);
    }

    private function createClientUser(string $prefix): User
    {
        return User::query()->create([
            'email' => "{$prefix}@example.com",
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Order Type User',
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

    private function createProduct(string $name): Product
    {
        return Product::query()->create([
            'name' => $name,
            'product_type' => 'cloud',
            'remark' => $name,
            'pricing' => ['monthly' => '100.00'],
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => 100,
            'status' => 1,
            'sort_order' => 1,
        ]);
    }
}
