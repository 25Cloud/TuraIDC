<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Services\Auth\LegacyPasswordVerifier;
use App\Services\Auth\LoginRiskControlService;
use App\Services\Referral\ReferralService;
use App\Services\System\NotificationService;
use App\Services\System\OperationLogService;
use App\Services\User\AdminRoleBridgeService;
use Tests\TestCase;

class AdminLoginAsRedirectUrlTest extends TestCase
{
    /**
     * 多域名部署：官网、控制台、管理端各占一个子域，控制台地址不带路径。
     */
    public function test_issue_admin_login_as_code_supports_multi_domain_deployment(): void
    {
        config([
            'app.frontend_url' => 'https://www.sw7111.top',
            'app.client_console_url' => 'https://console.sw7111.top',
            'app.admin_url' => 'https://admin.sw7111.top',
        ]);

        $operationLogService = $this->createMock(OperationLogService::class);
        $operationLogService->expects($this->once())->method('write');

        $result = $this->makeAuthService($operationLogService)
            ->issueAdminLoginAsCode($this->makeClientUser());

        $this->assertNotEmpty($result['login_code']);
        $this->assertSame(
            'https://console.sw7111.top/client/login-as',
            $result['target_url']
        );
    }

    public function test_issue_admin_login_as_code_rejects_missing_client_console_url(): void
    {
        config([
            'app.frontend_url' => '',
            'app.client_console_url' => '',
            'app.admin_url' => 'https://admin.sw7111.top',
        ]);

        $operationLogService = $this->createMock(OperationLogService::class);
        $operationLogService->expects($this->never())->method('write');

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('CLIENT_CONSOLE_URL');

        $this->makeAuthService($operationLogService)
            ->issueAdminLoginAsCode($this->makeClientUser());
    }

    public function test_issue_admin_login_as_code_rejects_non_http_client_console_url(): void
    {
        config([
            'app.client_console_url' => 'ftp://console.example.test',
            'app.admin_url' => 'https://admin.example.test',
        ]);

        $operationLogService = $this->createMock(OperationLogService::class);
        $operationLogService->expects($this->never())->method('write');

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('CLIENT_CONSOLE_URL');

        $this->makeAuthService($operationLogService)
            ->issueAdminLoginAsCode($this->makeClientUser());
    }

    public function test_issue_admin_login_as_code_rejects_admin_url_as_client_console_url(): void
    {
        config([
            'app.frontend_url' => 'https://admin.sw7111.top',
            'app.client_console_url' => 'https://admin.sw7111.top',
            'app.admin_url' => 'https://admin.sw7111.top',
        ]);

        $operationLogService = $this->createMock(OperationLogService::class);
        $operationLogService->expects($this->never())->method('write');

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('CLIENT_CONSOLE_URL');

        $this->makeAuthService($operationLogService)
            ->issueAdminLoginAsCode($this->makeClientUser());
    }

    public function test_issue_admin_login_as_code_supports_single_domain_subpath_deployment(): void
    {
        // 单域名子路径部署：官网、控制台、管理端、API 同源，靠 base path 区分。
        // 控制台地址带 /console 子路径，必须原样保留，否则代登录会落到官网 SPA 上。
        config([
            'app.frontend_url' => 'https://cloud.example.test',
            'app.client_console_url' => 'https://cloud.example.test/console',
            'app.admin_url' => 'https://cloud.example.test/admin',
        ]);

        $operationLogService = $this->createMock(OperationLogService::class);
        $operationLogService->expects($this->once())->method('write');

        $result = $this->makeAuthService($operationLogService)
            ->issueAdminLoginAsCode($this->makeClientUser());

        $this->assertSame(
            'https://cloud.example.test/console/client/login-as',
            $result['target_url']
        );
    }

    public function test_issue_admin_login_as_code_keeps_non_default_port(): void
    {
        // 本地开发与内网非标准端口部署：端口必须保留，否则链接会指向默认端口。
        config([
            'app.frontend_url' => 'https://cloud.example.test:8443',
            'app.client_console_url' => 'https://cloud.example.test:8443/console',
            'app.admin_url' => 'https://cloud.example.test:8443/admin',
        ]);

        $operationLogService = $this->createMock(OperationLogService::class);
        $operationLogService->expects($this->once())->method('write');

        $result = $this->makeAuthService($operationLogService)
            ->issueAdminLoginAsCode($this->makeClientUser());

        $this->assertSame(
            'https://cloud.example.test:8443/console/client/login-as',
            $result['target_url']
        );
    }

    public function test_issue_admin_login_as_code_rejects_client_console_url_sharing_admin_base_path(): void
    {
        // 控制台与管理端指向完全相同的地址（origin 与 base path 都一致）时，
        // postMessage 的 origin 校验形同虚设，必须拒绝。
        // 只同 origin 但 base path 不同（单域名部署的常规形态）不算冲突。
        config([
            'app.frontend_url' => 'https://cloud.example.test',
            'app.client_console_url' => 'https://cloud.example.test/admin',
            'app.admin_url' => 'https://cloud.example.test/admin',
        ]);

        $operationLogService = $this->createMock(OperationLogService::class);
        $operationLogService->expects($this->never())->method('write');

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('ADMIN_URL');

        $this->makeAuthService($operationLogService)
            ->issueAdminLoginAsCode($this->makeClientUser());
    }

    public function test_issue_admin_login_as_code_rejects_invalid_admin_url(): void
    {
        config([
            'app.frontend_url' => 'https://cloud.example.test',
            'app.client_console_url' => 'https://cloud.example.test/console',
            'app.admin_url' => 'ftp://admin.example.test',
        ]);

        $operationLogService = $this->createMock(OperationLogService::class);
        $operationLogService->expects($this->never())->method('write');

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('ADMIN_URL');

        $this->makeAuthService($operationLogService)
            ->issueAdminLoginAsCode($this->makeClientUser());
    }

    private function makeClientUser(): User
    {
        $user = new User([
            'email' => 'client@example.com',
            'nickname' => 'Client',
            'status' => 1,
        ]);
        $user->id = 123;
        $user->exists = true;

        return $user;
    }

    private function makeAuthService(OperationLogService $operationLogService): AuthService
    {
        return new AuthService(
            $this->createMock(NotificationService::class),
            $this->createMock(ReferralService::class),
            $operationLogService,
            $this->createMock(AdminRoleBridgeService::class),
            $this->createMock(LoginRiskControlService::class),
            new LegacyPasswordVerifier,
        );
    }
}
