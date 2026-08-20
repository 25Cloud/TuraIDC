<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
use App\Constants\InvoiceType;
use App\Models\AdminUser;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use App\Services\System\DashboardService;
use App\Support\AdminPermissions;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

class V2AdminDashboardApiTest extends TestCase
{
    public function test_dashboard_stats_requires_permission_rejects_per_page_and_returns_whitelist(): void
    {
        $this->mock(DashboardService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('stats')
                ->once()
                ->andReturn([
                    'counts' => [
                        'total_users' => 10,
                        'total_invoices' => 20,
                        'active_services' => 3,
                        'open_tickets' => 1,
                        'secret' => 'must-not-leak',
                    ],
                    'today' => [
                        'new_users' => 2,
                        'new_invoices' => 4,
                        'income' => 19.8,
                    ],
                    'month' => [
                        'income' => 120.5,
                        'new_users' => 8,
                        'new_invoices' => 13,
                    ],
                    'raw_response' => 'must-not-leak',
                ]);
        });

        $this->getJson('/api/v2/admin/dashboard/stats')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SETTINGS_VIEW]));

        $this->getJson('/api/v2/admin/dashboard/stats')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::DASHBOARD_VIEW]));

        $this->getJson('/api/v2/admin/dashboard/stats?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $response = $this->getJson('/api/v2/admin/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.counts.total_users', 10)
            ->assertJsonMissingPath('data.raw_response')
            ->assertJsonMissingPath('data.counts.secret');

        $this->assertSame(['counts', 'today', 'month'], array_keys($response->json('data')));
        $this->assertSame(['total_users', 'total_invoices', 'active_services', 'open_tickets'], array_keys($response->json('data.counts')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
    }

    public function test_recent_invoices_returns_compact_whitelisted_payload(): void
    {
        $this->mock(DashboardService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('recentInvoices')
                ->once()
                ->andReturn([
                    [
                        'id' => 1,
                        'invoice_no' => 'INV202607050001',
                        'amount' => '99.9',
                        'status' => 1,
                        'status_label' => '已支付',
                        'type' => 'new',
                        'created_at' => '2026-07-05 10:00:00',
                        'user' => [
                            'nickname' => '测试用户',
                            'email' => 't***@example.com',
                            'api_key' => 'must-not-leak',
                        ],
                        'third_party_response' => 'must-not-leak',
                    ],
                ]);
        });

        Sanctum::actingAs($this->createAdmin([AdminPermissions::DASHBOARD_VIEW]));

        $this->getJson('/api/v2/admin/dashboard/recent-invoices?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $response = $this->getJson('/api/v2/admin/dashboard/recent-invoices')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.recent_invoices.0.invoice_no', 'INV202607050001')
            ->assertJsonPath('data.recent_invoices.0.amount', '99.90')
            ->assertJsonMissingPath('data.recent_invoices.0.third_party_response')
            ->assertJsonMissingPath('data.recent_invoices.0.user.api_key');

        $this->assertSame(['recent_invoices'], array_keys($response->json('data')));
        $this->assertSame([
            'id',
            'invoice_no',
            'amount',
            'status',
            'status_label',
            'type',
            'created_at',
            'user',
        ], array_keys($response->json('data.recent_invoices.0')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
    }

    public function test_recent_invoices_real_service_does_not_error_when_masking_user_email(): void
    {
        Cache::flush();

        $suffix = strtoupper(bin2hex(random_bytes(4)));
        $user = User::query()->create([
            'email' => 'dashboard-real-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Dashboard Real '.$suffix,
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

        Invoice::query()->create([
            'invoice_no' => 'V2DASH'.$suffix,
            'user_id' => (int) $user->id,
            'type' => 'new',
            'amount' => '66.00',
            'discount' => '0.00',
            'paid_amount' => '66.00',
            'status' => InvoiceStatus::PAID,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'due_date' => now()->addDay(),
        ]);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::DASHBOARD_VIEW]));

        $response = $this->getJson('/api/v2/admin/dashboard/recent-invoices')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.recent_invoices.0.invoice_no', 'V2DASH'.$suffix);

        $this->assertSame(['nickname', 'email'], array_keys($response->json('data.recent_invoices.0.user')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
    }

    public function test_monthly_revenue_returns_capped_whitelisted_payload(): void
    {
        $this->mock(DashboardService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('monthlyRevenue')
                ->once()
                ->andReturn([
                    'revenue_by_product' => collect(range(1, 12))->map(fn (int $index): array => [
                        'label' => '产品 '.$index,
                        'amount' => $index * 10,
                        'count' => $index,
                        'secret' => 'must-not-leak',
                    ])->all(),
                    'daily_revenue' => collect(range(1, 35))->map(fn (int $index): array => [
                        'date' => '2026-07-'.str_pad((string) min($index, 31), 2, '0', STR_PAD_LEFT),
                        'day' => $index,
                        'amount' => $index,
                        'count' => 1,
                        'raw_response' => 'must-not-leak',
                    ])->all(),
                    'month_label' => '2026年7月',
                    'api_key' => 'must-not-leak',
                ]);
        });

        Sanctum::actingAs($this->createAdmin([AdminPermissions::DASHBOARD_VIEW]));

        $this->getJson('/api/v2/admin/dashboard/monthly-revenue?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $response = $this->getJson('/api/v2/admin/dashboard/monthly-revenue')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.month_label', '2026年7月')
            ->assertJsonCount(9, 'data.revenue_by_product')
            ->assertJsonCount(31, 'data.daily_revenue')
            ->assertJsonMissingPath('data.api_key')
            ->assertJsonMissingPath('data.revenue_by_product.0.secret')
            ->assertJsonMissingPath('data.daily_revenue.0.raw_response');

        $this->assertSame(['revenue_by_product', 'daily_revenue', 'month_label'], array_keys($response->json('data')));
        $this->assertSame(['label', 'amount', 'count'], array_keys($response->json('data.revenue_by_product.0')));
        $this->assertSame(['date', 'day', 'amount', 'count'], array_keys($response->json('data.daily_revenue.0')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
    }

    public function test_real_dashboard_revenue_excludes_non_sales_funds_and_keeps_refund_offset(): void
    {
        Cache::flush();

        $suffix = strtoupper(bin2hex(random_bytes(4)));
        $service = app(DashboardService::class);
        $baselineStats = $service->stats();
        $baselineRevenue = $service->monthlyRevenue();
        $baselineProductTotal = (float) collect($baselineRevenue['revenue_by_product'])->sum('amount');
        $today = now()->format('Y-m-d');
        $baselineDailyRevenue = collect($baselineRevenue['daily_revenue'])->firstWhere('date', $today) ?? [];
        $user = User::query()->create([
            'email' => 'dashboard-revenue-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Dashboard Revenue '.$suffix,
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
        $salesProductName = '销售收入-'.$suffix;

        foreach ([
            [InvoiceType::NEW_PURCHASE, '100.00', $salesProductName],
            [InvoiceType::REFUND, '-20.00', $salesProductName],
            [InvoiceType::RECHARGE, '300.00', '充值-'.$suffix],
            [InvoiceType::DEDUCTION, '40.00', '扣款-'.$suffix],
            [InvoiceType::REFERRAL_CREDIT, '60.00', '推荐奖励-'.$suffix],
        ] as [$type, $paidAmount, $productName]) {
            Invoice::query()->create([
                'invoice_no' => 'V2DASHREV'.$suffix.'-'.strtoupper(substr(sha1($type.$paidAmount), 0, 6)),
                'user_id' => (int) $user->id,
                'type' => $type,
                'amount' => $paidAmount,
                'discount' => '0.00',
                'paid_amount' => $paidAmount,
                'status' => InvoiceStatus::PAID,
                'product_spec_snapshot' => $productName,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'paid_at' => now(),
                'due_date' => now()->addDay(),
            ]);
        }

        Cache::flush();

        $stats = $service->stats();
        $revenue = $service->monthlyRevenue();
        $dailyRevenue = collect($revenue['daily_revenue'])->firstWhere('date', $today) ?? [];

        $this->assertSame(
            (float) ($baselineStats['today']['income'] ?? 0) + 80.0,
            (float) ($stats['today']['income'] ?? 0)
        );
        $this->assertSame(
            (float) ($baselineStats['month']['income'] ?? 0) + 80.0,
            (float) ($stats['month']['income'] ?? 0)
        );
        $this->assertSame(
            $baselineProductTotal + 80.0,
            (float) collect($revenue['revenue_by_product'])->sum('amount')
        );
        $this->assertSame(
            (float) ($baselineDailyRevenue['amount'] ?? 0) + 80.0,
            (float) ($dailyRevenue['amount'] ?? 0)
        );
        $this->assertSame(
            (int) ($baselineDailyRevenue['count'] ?? 0) + 2,
            (int) ($dailyRevenue['count'] ?? 0)
        );
        $this->assertNotContains('充值-'.$suffix, array_column($revenue['revenue_by_product'], 'label'));
        $this->assertNotContains('扣款-'.$suffix, array_column($revenue['revenue_by_product'], 'label'));
        $this->assertNotContains('推荐奖励-'.$suffix, array_column($revenue['revenue_by_product'], 'label'));
    }

    public function test_monthly_revenue_merges_null_and_empty_spec_snapshot_into_single_unknown_product(): void
    {
        Cache::flush();

        $suffix = strtoupper(bin2hex(random_bytes(4)));
        $service = app(DashboardService::class);
        $baselineUnknown = collect($service->monthlyRevenue()['revenue_by_product'])
            ->where('label', '未知产品');
        $baselineUnknownCount = (int) $baselineUnknown->sum('count');
        $baselineUnknownAmount = (float) $baselineUnknown->sum('amount');

        $user = User::query()->create([
            'email' => 'dashboard-unknown-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Dashboard Unknown '.$suffix,
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

        // 规格快照分别为 NULL 与空字符串：SQL 按原始列分组后，PHP 层按 label 归一合并为同一个“未知产品”行
        // （MariaDB ONLY_FULL_GROUP_BY 只能按原始列分组，不能按表达式分组）。
        // 使用大金额（50,000 × 2）确保“未知产品”进入 Top 8，避免被 revenue_by_product 截断。
        $paidAmount = '50000.00';

        foreach ([null, ''] as $index => $spec) {
            Invoice::query()->create([
                'invoice_no' => 'V2DASHUK'.$suffix.'-'.$index,
                'user_id' => (int) $user->id,
                'type' => InvoiceType::NEW_PURCHASE,
                'amount' => $paidAmount,
                'discount' => '0.00',
                'paid_amount' => $paidAmount,
                'status' => InvoiceStatus::PAID,
                'product_spec_snapshot' => $spec,
                'billing_cycle' => 'monthly',
                'quantity' => 1,
                'paid_at' => now(),
                'due_date' => now()->addDay(),
            ]);
        }

        Cache::flush();

        $unknown = collect($service->monthlyRevenue()['revenue_by_product'])->where('label', '未知产品');

        // 归一分组后“未知产品”只可能有一行（无论基线是否已存在该标签，均合并到同一行）。
        $this->assertCount(1, $unknown->values());
        $this->assertSame($baselineUnknownCount + 2, (int) $unknown->sum('count'));
        $this->assertEqualsWithDelta(
            $baselineUnknownAmount + 100000.0,
            (float) $unknown->sum('amount'),
            0.001
        );
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'v2-dashboard-'.$suffix,
            'label' => 'V2 Dashboard',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'v2-dashboard-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'V2 Dashboard',
            'email' => 'v2-dashboard-'.$suffix.'@example.com',
            'status' => 1,
        ]);
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
