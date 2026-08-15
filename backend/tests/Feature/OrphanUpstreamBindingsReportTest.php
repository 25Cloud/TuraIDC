<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\ServiceStatus;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrphanUpstreamBindingsReportTest extends TestCase
{
    public function test_orphan_report_lists_stale_upstream_bindings_and_keeps_data_intact(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->create([
            'email' => 'orphan-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);

        $product = Product::query()->create([
            'name' => 'Orphan Product '.$suffix,
            'product_type' => 'vps',
            'pricing' => ['monthly' => '10.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
        ]);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => 'Orphan Service '.$suffix,
            'domain' => 'orphan-'.$suffix.'.example.com',
            'billing_cycle' => 'monthly',
            'amount' => '10.00',
            'status' => ServiceStatus::ACTIVE,
            'provision_data' => [],
        ]);

        $pluginId = (int) DB::table('integration_plugins')->value('id');

        // 同一服务两条绑定：88002 是最新（id 更大），88001 是孤儿。
        DB::table('service_upstream_bindings')->insert([
            [
                'service_id' => $service->id,
                'plugin_id' => $pluginId,
                'provider_key' => 'zjmf_finance_api',
                'upstream_service_id' => '88001',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'service_id' => $service->id,
                'plugin_id' => $pluginId,
                'provider_key' => 'zjmf_finance_api',
                'upstream_service_id' => '88002',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->artisan('services:orphan-upstream-bindings', ['--service-id' => (int) $service->id])
            ->expectsOutputToContain('1 个服务存在 1 条孤儿绑定');

        // 只读诊断：不删除任何绑定数据。
        $this->assertSame(2, DB::table('service_upstream_bindings')
            ->where('service_id', $service->id)
            ->count());
    }
}
