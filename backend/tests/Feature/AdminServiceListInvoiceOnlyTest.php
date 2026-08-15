<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
use App\Constants\ServiceStatus;
use App\Models\FirstProductGroup;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\SecondProductGroup;
use App\Models\Service;
use App\Models\ThirdProductGroup;
use App\Models\User;
use App\Services\Provisioning\AdminServiceListService;
use App\Services\Upstream\ProviderKey;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminServiceListInvoiceOnlyTest extends TestCase
{
    use DatabaseTransactions;

    private function mirrorUserToIdc(User $user, string $suffix): void
    {
        $payload = [
            'email' => $user->email,
            'phone' => $user->phone,
            'password' => Hash::make('Temp@123456'),
            'nickname' => (string) $user->nickname,
            'company' => '',
            'qq' => '',
            'alipay_real_name' => '',
            'alipay_account' => '',
            'status' => 1,
            'referral_code' => 'A'.strtoupper(substr(md5($suffix.'-'.$user->id), 0, 8)),
            'referrer_user_id' => null,
            'member_level_id' => null,
            'total_sales_amount' => '0.00',
            'login_email_alert' => 1,
            'login_notify' => 1,
            'login_location_alert' => 1,
            'password_change_alert' => 1,
            'phone_change_alert' => 1,
            'email_change_alert' => 1,
            'marketing_alert' => 0,
            'is_verified' => 0,
            'real_name' => '',
            'id_card' => '',
            'verification_status' => 0,
            'verification_message' => '',
            'verification_certify_id' => null,
            'referred_at' => null,
            'verified_at' => null,
            'last_login_ip' => null,
            'last_login_at' => null,
            'admin_note' => null,
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::connection()->table('users')->updateOrInsert(['id' => (int) $user->id], $payload);
    }

    private function mirrorProductToIdc(Product $product, string $suffix): void
    {
        DB::connection()->table('products')->updateOrInsert(
            ['id' => (int) $product->id],
            Product::buildIdcMirrorPayload($product, 'admin-service-'.$suffix.'-'.(int) $product->id)
        );
    }

    public function test_paginate_supports_invoice_only_service_keyword_and_invoice_payload(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->forceCreate([
            'email' => 'admin-service-list-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Admin Service List',
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

        $group = FirstProductGroup::query()->create([
            'code' => 'server-'.$suffix,
            'name' => 'Admin Service Group '.$suffix,
            'slug' => 'admin-service-group-'.$suffix,
            'description' => '',
            'is_visible' => 1,
            'sort_order' => 0,
        ]);

        $product = Product::query()->create([
            'product_group_id' => (int) $this->createLeafGroup($group, $suffix)->id,
            'name' => '旧产品名 '.$suffix,
            'product_type' => 'server',
            'description' => '',
            'pricing' => ['monthly' => '66.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => 10,
            'status' => 1,
            'sort_order' => 0,
            'provision_module' => null,
            'auto_setup' => 0,
        ]);
        $this->mirrorProductToIdc($product, $suffix);

        $invoice = Invoice::query()->create([
            'invoice_no' => 'ADMSVCINV'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'type' => 'normal',
            'amount' => '66.00',
            'paid_amount' => '66.00',
            'status' => InvoiceStatus::PAID,
            'billing_cycle' => 'monthly',
            'product_spec_snapshot' => '独立云主机 2核4G',
            'config_snapshot' => ['region' => 'ap-east-1'],
            'due_date' => now()->addDay(),
            'paid_at' => now()->subMinute(),
        ]);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'invoice_id' => (int) $invoice->id,
            'name' => 'Invoice Only Service '.$suffix,
            'domain' => 'invoice-only-'.$suffix.'.example.com',
            'billing_cycle' => 'monthly',
            'amount' => '66.00',
            'locked_pricing' => [],
            'status' => ServiceStatus::ACTIVE,
            'provision_data' => [
                'source_invoice_id' => (int) $invoice->id,
                'upstream_host_id' => 'host-'.$suffix,
                'dedicated_ip' => '10.0.0.8',
            ],
            'expires_at' => now()->addMonth(),
            'auto_renew' => 1,
        ]);

        $result = app(AdminServiceListService::class)->paginate([
            'keyword' => (string) $invoice->invoice_no,
            'page_size' => 20,
        ]);

        $this->assertSame(1, (int) ($result['total'] ?? 0));
        $this->assertCount(1, $result['list'] ?? []);
        $this->assertSame((int) $service->id, (int) ($result['list'][0]['id'] ?? 0));
        $this->assertSame('独立云主机 2核4G', $result['list'][0]['product_display_name'] ?? null);
        $this->assertSame((string) $invoice->invoice_no, $result['list'][0]['invoice']['invoice_no'] ?? null);
        $this->assertSame('', $result['list'][0]['order']['order_no'] ?? null);
    }

    public function test_paginate_supports_invoice_snapshot_keyword_without_order_binding(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->forceCreate([
            'email' => 'admin-service-snapshot-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Admin Service Snapshot',
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
        $this->mirrorUserToIdc($user, $suffix.'-snapshot');

        $group = FirstProductGroup::query()->create([
            'code' => 'server-'.$suffix,
            'name' => 'Admin Snapshot Group '.$suffix,
            'slug' => 'admin-snapshot-group-'.$suffix,
            'description' => '',
            'is_visible' => 1,
            'sort_order' => 0,
        ]);

        $product = Product::query()->create([
            'product_group_id' => (int) $this->createLeafGroup($group, $suffix)->id,
            'name' => '旧产品名快照 '.$suffix,
            'product_type' => 'server',
            'description' => '',
            'pricing' => ['monthly' => '88.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => 10,
            'status' => 1,
            'sort_order' => 0,
            'provision_module' => null,
            'auto_setup' => 0,
        ]);
        $this->mirrorProductToIdc($product, $suffix.'-snapshot');

        $invoice = Invoice::query()->create([
            'invoice_no' => 'ADMSVCSNAP'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'type' => 'normal',
            'amount' => '88.00',
            'paid_amount' => '88.00',
            'status' => InvoiceStatus::PAID,
            'billing_cycle' => 'monthly',
            'product_spec_snapshot' => '东京云服务器 '.$suffix.' 4核8G',
            'config_snapshot' => ['region' => 'ap-northeast-1'],
            'due_date' => now()->addDay(),
            'paid_at' => now()->subMinute(),
        ]);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'invoice_id' => (int) $invoice->id,
            'name' => 'Invoice Snapshot Service '.$suffix,
            'domain' => 'invoice-snapshot-'.$suffix.'.example.com',
            'billing_cycle' => 'monthly',
            'amount' => '88.00',
            'locked_pricing' => [],
            'status' => ServiceStatus::ACTIVE,
            'provision_data' => [
                'source_invoice_id' => (int) $invoice->id,
                'upstream_host_id' => 'snapshot-'.$suffix,
                'dedicated_ip' => '10.0.0.18',
            ],
            'expires_at' => now()->addMonth(),
            'auto_renew' => 1,
        ]);

        $result = app(AdminServiceListService::class)->paginate([
            'keyword' => $suffix.' 4核8G',
            'page_size' => 20,
        ]);

        $this->assertSame(1, (int) ($result['total'] ?? 0));
        $this->assertCount(1, $result['list'] ?? []);
        $this->assertSame((int) $service->id, (int) ($result['list'][0]['id'] ?? 0));
        $this->assertSame('东京云服务器 '.$suffix.' 4核8G', $result['list'][0]['product_display_name'] ?? null);
    }

    public function test_paginate_ignores_legacy_order_payload_when_invoice_still_has_order_id(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->forceCreate([
            'email' => 'admin-service-order-fallback-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Admin Service Order Fallback',
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
        $this->mirrorUserToIdc($user, $suffix.'-legacy-order');

        $group = FirstProductGroup::query()->create([
            'code' => 'server-'.$suffix,
            'name' => 'Admin Fallback Group '.$suffix,
            'slug' => 'admin-fallback-group-'.$suffix,
            'description' => '',
            'is_visible' => 1,
            'sort_order' => 0,
        ]);

        $product = Product::query()->create([
            'product_group_id' => (int) $this->createLeafGroup($group, $suffix)->id,
            'name' => '回退产品 '.$suffix,
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
        $this->mirrorProductToIdc($product, $suffix.'-legacy-order');

        $order = Order::query()->create([
            'order_no' => 'ADMSVCORD'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'product_spec_snapshot' => '旧订单回退规格 '.$suffix,
            'product_type_snapshot' => 'server',
            'type' => 'new',
            'amount' => '99.00',
            'discount' => '0.00',
            'paid_amount' => '99.00',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'status' => 1,
            'paid_at' => now()->subMinute(),
            'trace_id' => 'admin-fallback-order-'.$suffix,
        ]);

        $invoice = Invoice::query()->create([
            'invoice_no' => 'ADMFALLINV'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'product_id' => (int) $product->id,
            'type' => 'normal',
            'amount' => '99.00',
            'paid_amount' => '99.00',
            'status' => InvoiceStatus::PAID,
            'billing_cycle' => 'monthly',
            'product_spec_snapshot' => '旧订单回退规格 '.$suffix,
            'config_snapshot' => ['region' => 'ap-southeast-1'],
            'due_date' => now()->addDay(),
            'paid_at' => now()->subMinute(),
        ]);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'invoice_id' => (int) $invoice->id,
            'name' => 'Invoice Order Fallback Service '.$suffix,
            'domain' => 'invoice-order-fallback-'.$suffix.'.example.com',
            'billing_cycle' => 'monthly',
            'amount' => '99.00',
            'locked_pricing' => [],
            'status' => ServiceStatus::ACTIVE,
            'provision_data' => [
                'source_invoice_id' => (int) $invoice->id,
            ],
            'expires_at' => now()->addMonth(),
            'auto_renew' => 1,
        ]);

        $result = app(AdminServiceListService::class)->paginate([
            'keyword' => (string) $invoice->invoice_no,
            'page_size' => 20,
        ]);

        $this->assertGreaterThanOrEqual(1, (int) ($result['total'] ?? 0));
        $matched = collect($result['list'] ?? [])->firstWhere('id', (int) $service->id);

        $this->assertIsArray($matched);
        $this->assertSame(0, (int) ($matched['order']['id'] ?? -1));
        $this->assertSame('', $matched['order']['order_no'] ?? null);
        $this->assertSame((string) $invoice->invoice_no, $matched['invoice']['invoice_no'] ?? null);
    }

    public function test_paginate_supports_service_identity_and_host_runtime_keywords(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->forceCreate([
            'email' => 'admin-service-runtime-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Runtime User '.$suffix,
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
        $this->mirrorUserToIdc($user, $suffix.'-runtime');

        $group = FirstProductGroup::query()->create([
            'code' => 'server-'.$suffix,
            'name' => 'Admin Runtime Group '.$suffix,
            'slug' => 'admin-runtime-group-'.$suffix,
            'description' => '',
            'is_visible' => 1,
            'sort_order' => 0,
        ]);

        $product = Product::query()->create([
            'product_group_id' => (int) $this->createLeafGroup($group, $suffix)->id,
            'name' => '运行态搜索产品 '.$suffix,
            'product_type' => 'server',
            'description' => '',
            'pricing' => ['monthly' => '55.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => 10,
            'status' => 1,
            'sort_order' => 0,
            'provision_module' => null,
            'auto_setup' => 0,
        ]);
        $this->mirrorProductToIdc($product, $suffix.'-runtime');

        $invoice = Invoice::query()->create([
            'invoice_no' => 'ADMRUNTIME'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'type' => 'normal',
            'amount' => '55.00',
            'paid_amount' => '55.00',
            'status' => InvoiceStatus::PAID,
            'billing_cycle' => 'monthly',
            'product_spec_snapshot' => '运行态云主机 '.$suffix,
            'config_snapshot' => ['region' => 'ap-southeast-1'],
            'due_date' => now()->addDay(),
            'paid_at' => now()->subMinute(),
        ]);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'invoice_id' => (int) $invoice->id,
            'name' => 'Runtime Search Service '.$suffix,
            'domain' => 'runtime-search-'.$suffix.'.example.com',
            'billing_cycle' => 'monthly',
            'amount' => '55.00',
            'locked_pricing' => [],
            'status' => ServiceStatus::ACTIVE,
            'provision_data' => [
                'source_invoice_id' => (int) $invoice->id,
            ],
            'expires_at' => now()->addMonth(),
            'auto_renew' => 1,
        ]);
        $this->mirrorServiceCompatToIdc([
            'id' => (int) $service->id,
            'user_id' => (int) $service->user_id,
            'product_id' => (int) $service->product_id,
            'invoice_id' => (int) $service->invoice_id,
            'name' => (string) $service->name,
            'domain' => (string) $service->domain,
            'billing_cycle' => (string) $service->billing_cycle,
            'amount' => (string) $service->amount,
            'locked_pricing' => $service->locked_pricing ?? [],
            'status' => (int) $service->status,
            'provision_data' => $service->provision_data ?? [],
            'expires_at' => $service->expires_at,
            'auto_renew' => (int) $service->auto_renew,
            'created_at' => $service->created_at,
            'updated_at' => $service->updated_at,
        ]);

        $invoice->forceFill(['service_id' => (int) $service->id])->save();
        $this->attachRuntimeSnapshotsToService($service, $suffix);

        $keywords = [
            'service id' => (string) $service->id,
            'user id' => (string) $user->id,
            'invoice id' => (string) $invoice->id,
            'custom hostname' => 'custom-host-'.$suffix.'.example.net',
            'assigned ip' => '203.0.113.77',
            'internal ip' => '10.55.77.4',
            'host username' => 'console-user-'.$suffix,
            'connection hostname' => 'console-host-'.$suffix.'.example.net',
            'connection username' => 'console-user-'.$suffix,
            'upstream host id list' => 'runtime-array-'.$suffix,
        ];

        foreach ($keywords as $label => $keyword) {
            $result = app(AdminServiceListService::class)->paginate([
                'keyword' => $keyword,
                'page_size' => 20,
            ]);

            $matched = collect($result['list'] ?? [])->firstWhere('id', (int) $service->id);
            $this->assertIsArray($matched, "Service list keyword [{$label}] did not match the target service.");
        }

        $result = app(AdminServiceListService::class)->paginate([
            'keyword' => (string) $service->id,
            'page_size' => 20,
        ]);
        $matched = collect($result['list'] ?? [])->firstWhere('id', (int) $service->id);

        $this->assertSame((int) $service->id, (int) ($matched['service_id'] ?? 0));
        $this->assertSame((int) $service->id, (int) ($matched['instance_id'] ?? 0));
        $this->assertSame('requested-host-'.$suffix.'.example.net', $matched['requested_hostname'] ?? null);
        $this->assertSame('custom-host-'.$suffix.'.example.net', $matched['custom_hostname'] ?? null);
        $this->assertSame('runtime-host-'.$suffix, $matched['upstream_host_id_text'] ?? null);
        $this->assertSame(['runtime-array-'.$suffix], $matched['upstream_host_ids'] ?? null);
        $this->assertSame('console-user-'.$suffix, $matched['host_username'] ?? null);
        $this->assertSame('console-host-'.$suffix.'.example.net', $matched['connection']['hostname'] ?? null);
        $this->assertSame('console-user-'.$suffix, $matched['connection']['username'] ?? null);
        $this->assertSame('10.55.88.9', $matched['connection']['internal_ip'] ?? null);
        $this->assertContains('198.51.100.61', $matched['host_ips'] ?? []);
        $this->assertContains('203.0.113.77', $matched['host_ips'] ?? []);
        $this->assertContains('10.55.88.9', $matched['host_ips'] ?? []);
        $this->assertSame((int) $invoice->id, (int) ($matched['invoice']['id'] ?? 0));
        $this->assertSame((string) $invoice->invoice_no, $matched['invoice']['invoice_no'] ?? null);
        $this->assertSame((int) $user->id, (int) ($matched['user']['id'] ?? 0));
    }

    private function createLeafGroup(FirstProductGroup $firstGroup, string $suffix): ThirdProductGroup
    {
        $secondGroup = SecondProductGroup::query()->create([
            'first_product_group_id' => (int) $firstGroup->id,
            'name' => 'Admin Service Second '.$suffix,
            'slug' => 'admin-service-second-'.$suffix,
            'description' => '',
            'sort_order' => 0,
            'is_visible' => 1,
        ]);

        return ThirdProductGroup::query()->create([
            'second_product_group_id' => (int) $secondGroup->id,
            'name' => 'Admin Service Third '.$suffix,
            'slug' => 'admin-service-third-'.$suffix,
            'description' => '',
            'sort_order' => 0,
            'is_visible' => 1,
        ]);
    }

    private function attachRuntimeSnapshotsToService(Service $service, string $suffix): void
    {
        $this->activateIntegrationPluginForTest('upstream', 'zjmf_finance');

        $pluginId = (int) DB::table('integration_plugins')
            ->where('domain', 'upstream')
            ->where('slug', 'zjmf_finance')
            ->value('id');

        $this->assertGreaterThan(0, $pluginId);

        $bindingId = DB::table('service_upstream_bindings')->insertGetId([
            'service_id' => (int) $service->id,
            'plugin_id' => $pluginId,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'upstream_service_id' => 'runtime-host-'.$suffix,
            'status_snapshot' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('service_runtime_snapshots')->insert([
            'service_id' => (int) $service->id,
            'service_upstream_binding_id' => $bindingId,
            'plugin_id' => $pluginId,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'status_key' => 'running',
            'status_text' => 'Running',
            'resource_json' => json_encode([
                'upstream_host_ids' => ['runtime-array-'.$suffix],
                'dedicated_ip' => '198.51.100.61',
                'assigned_ips' => ['203.0.113.77', '2001:db8::77'],
                'internal_ip' => '10.55.77.4',
                'os' => 'Debian-12-x64',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('service_connection_snapshots')->insert([
            'service_id' => (int) $service->id,
            'service_upstream_binding_id' => $bindingId,
            'plugin_id' => $pluginId,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'connection_type' => 'default',
            'hostname' => 'console-host-'.$suffix.'.example.net',
            'ip_address' => '198.51.100.61',
            'port' => 22,
            'connection_json' => json_encode([
                'hostname' => 'console-host-'.$suffix.'.example.net',
                'username' => 'console-user-'.$suffix,
                'internal_ip' => '10.55.88.9',
                'requested_host' => 'requested-host-'.$suffix.'.example.net',
                'custom_hostname' => 'custom-host-'.$suffix.'.example.net',
                'assigned_ips' => ['203.0.113.77', '2001:db8::77'],
                'dedicated_ip' => '198.51.100.61',
                'os' => 'Debian-12-x64',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'checked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
