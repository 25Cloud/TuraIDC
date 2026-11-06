<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * `audit:admin-routes` 的分组与退出码。
 *
 * 在测试进程里临时注册三条 api/v2/admin 路由（有权限 / 无权限 / 重复权限），
 * 断言命令把它们各归各位，并锁住 CI 语义：只要存在缺权限或未知权限码，退出码就是 1。
 */
class AuditAdminRoutesCommandTest extends TestCase
{
    public function test_json_groups_fixture_routes_and_fails_on_missing_permission(): void
    {
        Route::middleware(['permission:product.list'])
            ->get('/api/v2/admin/audit-fixtures/covered', static fn (): string => 'ok');
        Route::get('/api/v2/admin/audit-fixtures/uncovered', static fn (): string => 'ok');
        Route::middleware(['permission:product.list', 'permission:product.manage'])
            ->get('/api/v2/admin/audit-fixtures/duplicated', static fn (): string => 'ok');

        $result = $this->runAudit();
        $report = $result['report'];

        $this->assertSame(1, $result['exit_code'], '存在缺权限路由时退出码必须是 1');

        $missingUris = array_column($report['missing'], 'uri');
        $this->assertContains('api/v2/admin/audit-fixtures/uncovered', $missingUris);
        // 挂了权限的夹具不能被算成缺权限
        $this->assertNotContains('api/v2/admin/audit-fixtures/covered', $missingUris);
        $this->assertNotContains('api/v2/admin/audit-fixtures/duplicated', $missingUris);

        $duplicated = $this->firstWhere($report['duplicates'], 'uri', 'api/v2/admin/audit-fixtures/duplicated');
        $this->assertIsArray($duplicated, '重复权限码必须单独成组');
        $this->assertSame(['product.list', 'product.manage'], $duplicated['codes']);

        $this->assertSame([], $report['unknown'], '夹具都用权限表里的码，不该出现未知码');
        // 现网 288 条 + 3 条夹具；白名单是 5 个管理员自服务端点
        $this->assertGreaterThanOrEqual(291, $report['total']);
        $this->assertGreaterThanOrEqual(5, $report['whitelisted']);
    }

    public function test_reports_unknown_permission_code_and_permission_without_code(): void
    {
        Route::middleware(['permission:not_a_real_permission'])
            ->get('/api/v2/admin/audit-fixtures/unknown', static fn (): string => 'ok');
        Route::middleware(['permission'])
            ->get('/api/v2/admin/audit-fixtures/no-code', static fn (): string => 'ok');

        $result = $this->runAudit();
        $report = $result['report'];

        $this->assertSame(1, $result['exit_code'], '未知权限码同样必须让退出码变成 1');

        $unknown = $this->firstWhere($report['unknown'], 'uri', 'api/v2/admin/audit-fixtures/unknown');
        $this->assertIsArray($unknown);
        $this->assertSame('not_a_real_permission', $unknown['code']);

        // 挂了 permission 却没带码 = 永远放行，必须归到「缺权限」
        $noCode = $this->firstWhere($report['missing'], 'uri', 'api/v2/admin/audit-fixtures/no-code');
        $this->assertIsArray($noCode);
        $this->assertSame('permission_without_code', $noCode['reason']);
    }

    public function test_passes_when_every_admin_route_is_covered_or_whitelisted(): void
    {
        Route::middleware(['permission:product.manage'])
            ->put('/api/v2/admin/audit-fixtures/covered-write', static fn (): string => 'ok');

        $result = $this->runAudit();

        // 这条断言同时是「当前仓库基线干净」的锁：以后谁加了一条没挂权限的
        // api/v2/admin 路由，这里会直接红，而不是等上线才发现「登录即可访问」。
        $this->assertSame(0, $result['exit_code']);
        $this->assertSame([], $result['report']['missing']);
        $this->assertSame([], $result['report']['unknown']);
        $this->assertSame([], $result['report']['duplicates']);
    }

    /**
     * @return array{exit_code: int, report: array<string, mixed>}
     */
    private function runAudit(): array
    {
        $exitCode = Artisan::call('audit:admin-routes', ['--json' => true]);
        $report = json_decode(trim(Artisan::output()), true);

        $this->assertIsArray($report, '--json 的输出必须是合法 JSON');

        return ['exit_code' => $exitCode, 'report' => $report];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function firstWhere(array $rows, string $key, string $value): ?array
    {
        foreach ($rows as $row) {
            if (($row[$key] ?? null) === $value) {
                return $row;
            }
        }

        return null;
    }
}
