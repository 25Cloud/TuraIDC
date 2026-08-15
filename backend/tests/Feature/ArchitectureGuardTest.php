<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class ArchitectureGuardTest extends TestCase
{
    public function test_services_root_no_longer_contains_business_service_files(): void
    {
        $files = glob(app_path('Services/*.php')) ?: [];

        $this->assertSame([], $files, 'Services 根目录不应再保留平铺业务服务类');
    }

    public function test_resources_root_no_longer_contains_flat_files(): void
    {
        $files = glob(app_path('Http/Resources/*.php')) ?: [];

        $this->assertSame([], $files, 'Http/Resources 根目录不应再保留平铺资源类');
    }

    public function test_client_requests_root_no_longer_contains_flat_files(): void
    {
        $files = glob(app_path('Http/Requests/Client/*.php')) ?: [];

        $this->assertSame([], $files, 'Http/Requests/Client 根目录不应再保留平铺请求类');
    }

    public function test_backend_root_no_longer_contains_debug_php_files(): void
    {
        $forbidden = [
            base_path('_check_schema.php'),
            base_path('_check_schema2.php'),
            base_path('_halo_check_invoice_service.php'),
            base_path('_halo_debug_service_traffic.php'),
            base_path('_halo_fix_balance.php'),
            base_path('_halo_sync_balance.php'),
            base_path('_run_migrate.php'),
        ];

        foreach ($forbidden as $file) {
            $this->assertFileDoesNotExist($file, 'backend 根目录不应保留调试 PHP 文件：'.basename($file));
        }
    }
}
