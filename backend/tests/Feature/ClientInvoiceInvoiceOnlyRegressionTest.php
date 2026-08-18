<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
use App\Constants\PaymentStatus;
use App\Models\FirstProductGroup;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SecondProductGroup;
use App\Models\Service;
use App\Models\ThirdProductGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientInvoiceInvoiceOnlyRegressionTest extends TestCase
{
    use DatabaseTransactions;

    private function mirrorUserToIdc(User $user, string $suffix): void
    {
        DB::connection()->table('users')->updateOrInsert(
            ['id' => (int) $user->id],
            [
                'email' => $user->email,
                'phone' => $user->phone,
                'password' => Hash::make('Temp@123456'),
                'status' => 1,
                'referral_code' => 'C'.strtoupper(substr(md5($suffix.'-'.$user->id), 0, 8)),
                'referrer_user_id' => null,
                'member_level_id' => null,
                'login_email_alert' => 1,
                'login_notify' => 1,
                'login_location_alert' => 1,
                'password_change_alert' => 1,
                'phone_change_alert' => 1,
                'email_change_alert' => 1,
                'marketing_alert' => 0,
                'is_verified' => 0,
                'verification_status' => 0,
                'verification_message' => '',
                'verification_certify_id' => null,
                'referred_at' => null,
                'verified_at' => null,
                'last_login_ip' => null,
                'last_login_at' => null,
                'deleted_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    private function mirrorProductToIdc(Product $product, string $suffix): void
    {
        $payload = [
            'product_group_id' => (int) ($product->product_group_id ?: 0) ?: null,
            'product_type' => (string) ($product->product_type ?: 'other'),
            'remark' => null,
            'pricing' => json_encode($product->pricing ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'setup_fee' => number_format((float) ($product->setup_fee ?? 0), 2, '.', ''),
            'config_options' => json_encode($product->config_options ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'purchase_requires' => json_encode($product->purchase_requires ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'stock' => (int) ($product->stock ?? 0),
            'status' => 1,
            'sort_order' => 0,
            'provision_module' => null,
            'auto_setup' => 0,
            'supplier_id' => null,
            'supplier_product_id' => null,
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $columns = DB::connection()->getSchemaBuilder()->getColumnListing('products');

        DB::connection()->table('products')->updateOrInsert(
            ['id' => (int) $product->id],
            array_intersect_key($payload, array_fill_keys($columns, true))
        );
    }

    private function mirrorServiceInstanceToIdc(Service $service, string $suffix): void
    {
        $this->mirrorServiceCompatToIdc([
            'id' => (int) $service->id,
            'user_id' => (int) $service->user_id,
            'product_id' => (int) $service->product_id,
            'source_invoice_id' => (int) (($service->provision_snapshot_json['source_invoice_id'] ?? 0) ?: 0) ?: null,
            'name' => (string) $service->name,
            'domain' => 'client-invoice-'.$suffix.'.example.com',
            'billing_cycle' => (string) $service->billing_cycle,
            'amount' => number_format((float) $service->amount, 2, '.', ''),
            'pricing_snapshot_json' => [],
            'status' => (int) $service->status,
            'provision_snapshot_json' => $service->provision_snapshot_json ?? [],
            'expires_at' => $service->expires_at,
            'auto_renew' => (int) $service->auto_renew,
            'remark' => null,
            'trace_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_client_invoice_summary_uses_total_amount_for_invoice_only_records(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->forceCreate([
            'email' => 'client-invoice-summary-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Client Invoice Summary',
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
        $this->mirrorUserToIdc($user, $suffix);

        Invoice::query()->create([
            'invoice_no' => 'CLISUMA'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'type' => 'normal',
            'amount' => '18.00',
            'paid_amount' => '0.00',
            'status' => InvoiceStatus::UNPAID,
            'billing_cycle' => 'monthly',
            'product_spec_snapshot' => '未支付账单 A',
            'due_date' => now()->addDay(),
        ]);

        Invoice::query()->create([
            'invoice_no' => 'CLISUMB'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'type' => 'normal',
            'amount' => '22.50',
            'paid_amount' => '0.00',
            'status' => InvoiceStatus::OVERDUE,
            'billing_cycle' => 'monthly',
            'product_spec_snapshot' => '逾期账单 B',
            'due_date' => now()->subDay(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v2/client/invoices/summary')
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.unpaid', 1)
            ->assertJsonPath('data.overdue', 1)
            ->assertJsonPath('data.unpaid_amount', '40.50');
    }

    public function test_client_invoice_show_returns_product_config_without_order_binding(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->forceCreate([
            'email' => 'client-invoice-show-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Client Invoice Show',
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
        $this->mirrorUserToIdc($user, $suffix);

        $groupIds = $this->createProductGroupIds('client-invoice-group-'.$suffix, 'Client Invoice Group '.$suffix);

        $product = Product::query()->create([
            'product_group_id' => $groupIds['third'],
            'name' => 'Client Invoice Product '.$suffix,
            'product_type' => 'server',
            'description' => '',
            'pricing' => ['monthly' => '66.00'],
            'setup_fee' => '0.00',
            'config_options' => [
                ['field' => 'cpu', 'name' => 'CPU', 'option_type' => 6],
            ],
            'purchase_requires' => [],
            'stock' => 8,
            'status' => 1,
            'sort_order' => 0,
            'provision_module' => null,
            'auto_setup' => 0,
        ]);
        $this->mirrorProductToIdc($product, $suffix);

        $invoice = Invoice::query()->create([
            'invoice_no' => 'CLISHOW'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'type' => 'normal',
            'amount' => '66.00',
            'paid_amount' => '0.00',
            'status' => InvoiceStatus::UNPAID,
            'billing_cycle' => 'monthly',
            'product_spec_snapshot' => '客户端云主机 2核4G',
            'config_snapshot' => ['cpu' => '2'],
            'due_date' => now()->addDay(),
        ]);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => 'Client Invoice Service '.$suffix,
            'domain' => 'client-invoice-'.$suffix.'.example.com',
            'billing_cycle' => 'monthly',
            'amount' => '66.00',
            'status' => 0,
            'locked_pricing' => [],
            'provision_data' => [],
            'expires_at' => now()->addMonth(),
            'auto_renew' => 0,
        ]);

        // 更新 Invoice 的 service_id
        $invoice->service_id = (int) $service->id;
        $invoice->save();

        Payment::query()->create([
            'payment_no' => 'CLISHOWPAY'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'invoice_id' => (int) $invoice->id,
            'gateway' => 'alipay',
            'trade_no' => 'TRADE-CLIENT-VISIBLE-'.$suffix,
            'amount' => '66.00',
            'status' => PaymentStatus::PENDING,
        ]);

        $this->mirrorServiceInstanceToIdc($service, $suffix);

        Sanctum::actingAs($user);

        $this->getJson('/api/v2/client/invoices/'.$invoice->id)
            ->assertOk()
            ->assertJsonPath('data.invoice.product.id', (int) $product->id)
            ->assertJsonPath('data.invoice.product.config_options.0.field', 'cpu')
            ->assertJsonPath('data.invoice.service.id', (int) $service->id)
            ->assertJsonPath('data.invoice.display.product_display_name', '客户端云主机 2核4G')
            ->assertJsonMissingPath('data.raw_status')
            ->assertJsonMissingPath('data.invoice.payment_chain.payment_summary.trade_no')
            ->assertJsonMissingPath('data.invoice.payment_chain.payments.0.trade_no');

        $this->getJson('/api/v2/client/invoices?keyword=TRADE-CLIENT-VISIBLE-'.$suffix.'&page_size=20')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.invoice_no', (string) $invoice->invoice_no);
    }

    public function test_client_invoice_list_filters_refunded_invoice_without_order_binding(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->forceCreate([
            'email' => 'client-invoice-refund-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Client Invoice Refund',
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
        $this->mirrorUserToIdc($user, $suffix);

        $refundedInvoice = Invoice::query()->create([
            'invoice_no' => 'CLIREFUND'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'type' => 'normal',
            'amount' => '15.00',
            'paid_amount' => '15.00',
            'status' => InvoiceStatus::REFUNDED,
            'billing_cycle' => 'monthly',
            'product_spec_snapshot' => '已退款账单',
            'due_date' => now()->addDay(),
            'paid_at' => now()->subMinute(),
        ]);

        DB::table('payments')->insert([
            'payment_no' => 'CLIREFPAY'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'invoice_id' => (int) $refundedInvoice->id,
            'gateway_key' => 'balance',
            'amount' => '15.00',
            'status' => PaymentStatus::REFUNDED,
            'paid_at' => now()->subMinute(),
            'callback_raw' => json_encode([
                'refund' => [
                    'refund_method' => 'balance',
                    'refund_reason' => 'invoice only list filter',
                    'refunded_at' => now()->format('Y-m-d H:i:s'),
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Invoice::query()->create([
            'invoice_no' => 'CLINORMAL'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'type' => 'normal',
            'amount' => '18.00',
            'paid_amount' => '0.00',
            'status' => InvoiceStatus::UNPAID,
            'billing_cycle' => 'monthly',
            'product_spec_snapshot' => '普通账单',
            'due_date' => now()->addDay(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v2/client/invoices?status=5&page_size=20')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.invoice_no', (string) $refundedInvoice->invoice_no)
            ->assertJsonPath('data.list.0.status', InvoiceStatus::REFUNDED);
    }

    public function test_client_invoice_list_uses_bill_display_text_without_json_or_missing_spec_placeholder(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->forceCreate([
            'email' => 'client-invoice-display-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Client Invoice Display',
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
        $this->mirrorUserToIdc($user, $suffix);

        $groupIds = $this->createProductGroupIds('client-invoice-display-group-'.$suffix, 'Client Invoice Display Group '.$suffix);

        $product = Product::query()->create([
            'product_group_id' => $groupIds['third'],
            'name' => 'Name Attribute Is Not Persisted '.$suffix,
            'product_type' => 'server',
            'description' => '',
            'pricing' => ['monthly' => '88.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => 8,
            'status' => 1,
            'sort_order' => 0,
            'provision_module' => null,
            'auto_setup' => 0,
        ]);
        $this->mirrorProductToIdc($product, $suffix);
        DB::connection()->table('products')
            ->where('id', (int) $product->id)
            ->update(['remark' => '演示云服务器 '.$suffix]);

        Invoice::query()->create([
            'invoice_no' => 'CLIRECH'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'type' => 'recharge',
            'amount' => '20.00',
            'paid_amount' => '20.00',
            'status' => InvoiceStatus::PAID,
            'billing_cycle' => '',
            'due_date' => now()->addDay(),
            'paid_at' => now()->subMinute(),
        ]);

        Invoice::query()->create([
            'invoice_no' => 'CLIPROD'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'type' => 'new',
            'amount' => '88.00',
            'paid_amount' => '0.00',
            'status' => InvoiceStatus::UNPAID,
            'billing_cycle' => 'monthly',
            'due_date' => now()->addDay(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v2/client/invoices?page_size=20')
            ->assertOk()
            ->assertJsonPath('data.total', 2);

        $rows = collect($response->json('data.list'));
        $rechargeRow = $rows->firstWhere('invoice_no', 'CLIRECH'.strtoupper($suffix));
        $productRow = $rows->firstWhere('invoice_no', 'CLIPROD'.strtoupper($suffix));

        $this->assertIsArray($rechargeRow);
        $this->assertSame('充值账单', $rechargeRow['product_display_name']);
        $this->assertSame('充值账单', $rechargeRow['combined_display_name']);
        $this->assertSame('余额充值', $rechargeRow['product_spec_display']);
        $this->assertSame('充值账单', $rechargeRow['summary']['headline']);

        $this->assertIsArray($productRow);
        $this->assertSame('演示云服务器 '.$suffix, $productRow['product_display_name']);
        $this->assertSame('演示云服务器 '.$suffix, $productRow['combined_display_name']);
        $this->assertSame('演示云服务器 '.$suffix, $productRow['product_spec_display']);
        $this->assertStringNotContainsString('未配置规格', $productRow['product_display_name']);
    }

    public function test_client_invoice_list_accepts_comma_separated_type_filters_for_shared_panels(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->forceCreate([
            'email' => 'client-invoice-filter-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Client Invoice Type Filter',
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
        $this->mirrorUserToIdc($user, $suffix);

        $normalInvoice = Invoice::query()->create([
            'invoice_no' => 'CLITFNOR'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'type' => 'normal',
            'amount' => '30.00',
            'paid_amount' => '0.00',
            'status' => InvoiceStatus::UNPAID,
            'billing_cycle' => 'monthly',
            'product_spec_snapshot' => '普通新购账单',
            'due_date' => now()->addDay(),
        ]);

        $renewInvoice = Invoice::query()->create([
            'invoice_no' => 'CLITFREN'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'type' => 'renew',
            'amount' => '40.00',
            'paid_amount' => '0.00',
            'status' => InvoiceStatus::UNPAID,
            'billing_cycle' => 'monthly',
            'product_spec_snapshot' => '续费账单',
            'due_date' => now()->addDay(),
        ]);

        $rechargeInvoice = Invoice::query()->create([
            'invoice_no' => 'CLITFREC'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'type' => 'recharge',
            'amount' => '50.00',
            'paid_amount' => '50.00',
            'status' => InvoiceStatus::PAID,
            'billing_cycle' => '',
            'product_spec_snapshot' => '余额充值',
            'due_date' => now()->addDay(),
            'paid_at' => now()->subMinute(),
        ]);

        Sanctum::actingAs($user);

        $orderPanelResponse = $this->getJson('/api/v2/client/invoices?type=new,normal,renew,upgrade&page_size=20')
            ->assertOk()
            ->assertJsonPath('data.total', 2);

        $orderPanelInvoiceNos = collect($orderPanelResponse->json('data.list'))->pluck('invoice_no')->all();
        $this->assertContains((string) $normalInvoice->invoice_no, $orderPanelInvoiceNos);
        $this->assertContains((string) $renewInvoice->invoice_no, $orderPanelInvoiceNos);
        $this->assertNotContains((string) $rechargeInvoice->invoice_no, $orderPanelInvoiceNos);

        $rechargePanelResponse = $this->getJson('/api/v2/client/invoices?type=recharge&page_size=20')
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        $this->assertSame((string) $rechargeInvoice->invoice_no, $rechargePanelResponse->json('data.list.0.invoice_no'));
    }

    /**
     * @return array{first:int,second:int,third:int}
     */
    private function createProductGroupIds(string $slug, string $name): array
    {
        $first = FirstProductGroup::query()->firstOrCreate(
            ['code' => 'server'],
            [
                'name' => 'Server',
                'slug' => 'client-invoice-first-server',
                'sort_order' => 0,
                'is_visible' => 1,
                'is_system' => 0,
                'legacy_product_type' => 'server',
            ]
        );

        if ((int) $first->is_visible !== 1) {
            $first->update(['is_visible' => 1]);
        }

        $second = SecondProductGroup::query()->create([
            'first_product_group_id' => (int) $first->id,
            'name' => $name,
            'slug' => $slug,
            'description' => '',
            'sort_order' => 0,
            'is_visible' => 1,
        ]);
        $third = ThirdProductGroup::query()->create([
            'second_product_group_id' => (int) $second->id,
            'name' => $name.' 三级分组',
            'slug' => $slug.'-third',
            'description' => '',
            'sort_order' => 0,
            'is_visible' => 1,
        ]);

        return [
            'first' => (int) $first->id,
            'second' => (int) $second->id,
            'third' => (int) $third->id,
        ];
    }
}
