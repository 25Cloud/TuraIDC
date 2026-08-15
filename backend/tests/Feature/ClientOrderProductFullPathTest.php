<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Models\FirstProductGroup;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\SecondProductGroup;
use App\Models\ThirdProductGroup;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientOrderProductFullPathTest extends TestCase
{
    public function test_client_order_list_and_detail_include_product_full_path(): void
    {
        $suffix = strtoupper(bin2hex(random_bytes(4)));
        $user = User::query()->create([
            'email' => 'order-path-'.strtolower($suffix).'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Order Path User',
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

        $firstGroup = FirstProductGroup::query()->create([
            'code' => 'vps-'.strtolower($suffix),
            'name' => '云服务器',
            'slug' => 'vps-'.strtolower($suffix),
            'sort_order' => 0,
            'is_visible' => 1,
            'is_system' => 0,
        ]);
        $secondGroup = SecondProductGroup::query()->create([
            'first_product_group_id' => (int) $firstGroup->id,
            'name' => '轻量云',
            'slug' => 'light-'.strtolower($suffix),
            'sort_order' => 0,
            'is_visible' => 1,
        ]);
        $thirdGroup = ThirdProductGroup::query()->create([
            'second_product_group_id' => (int) $secondGroup->id,
            'name' => '香港',
            'slug' => 'hk-'.strtolower($suffix),
            'sort_order' => 0,
            'is_visible' => 1,
        ]);

        $product = Product::query()->create([
            'product_group_id' => (int) $thirdGroup->id,
            'service_type_code' => 'vps',
            'product_type' => 'vps',
            'pricing' => ['monthly' => '88.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'sort_order' => 0,
            'provision_module' => null,
            'auto_setup' => 0,
        ]);

        $order = Order::query()->create([
            'order_no' => 'ORDPATH'.$suffix,
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'product_spec_snapshot' => 'gscs-2vcpu-2gib',
            'product_type_snapshot' => 'vps',
            'type' => 'new',
            'amount' => '88.00',
            'discount' => '0.00',
            'paid_amount' => '0.00',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'config_snapshot' => [],
            'config_pricing_snapshot' => [],
            'status' => OrderStatus::PENDING,
        ]);

        $invoice = Invoice::query()->create([
            'invoice_no' => 'INVPATH'.$suffix,
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'product_id' => (int) $product->id,
            'product_spec_snapshot' => 'gscs-2vcpu-2gib',
            'product_type_snapshot' => 'vps',
            'type' => 'new',
            'amount' => '88.00',
            'paid_amount' => '0.00',
            'billing_cycle' => 'monthly',
            'status' => InvoiceStatus::UNPAID,
            'due_date' => now()->addDay(),
        ]);

        Sanctum::actingAs($user);

        $expectedPath = '云服务器/轻量云/香港/gscs-2vcpu-2gib';

        $this->getJson('/api/v2/client/orders?page_size=20')
            ->assertOk()
            ->assertJsonPath('data.list.0.id', (int) $order->id)
            ->assertJsonPath('data.list.0.product_name', 'gscs-2vcpu-2gib')
            ->assertJsonPath('data.list.0.product_full_path', $expectedPath)
            ->assertJsonPath('data.list.0.invoice.invoice_no', (string) $invoice->invoice_no);

        $this->getJson('/api/v2/client/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.id', (int) $order->id)
            ->assertJsonPath('data.product_name', 'gscs-2vcpu-2gib')
            ->assertJsonPath('data.product_full_path', $expectedPath);
    }
}
