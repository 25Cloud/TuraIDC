<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\ZjmfBridge\Tests\Unit;

use PHPUnit\Framework\TestCase;

class PluginBoundaryTest extends TestCase
{
    public function test_zjmf_bridge_runtime_is_fully_owned_by_the_addon(): void
    {
        $backendPath = dirname(__DIR__, 5);

        $this->assertDirectoryExists($backendPath.'/plugins/addons/zjmf_bridge/src');
        $this->assertFileDoesNotExist($backendPath.'/config/zjmf_bridge.php');
        $this->assertFileDoesNotExist($backendPath.'/routes/zjmf.php');
        $this->assertDirectoryDoesNotExist($backendPath.'/app/Services/ZjmfBridge');
        $this->assertDirectoryDoesNotExist($backendPath.'/app/Http/Controllers/Zjmf');
        $this->assertFileDoesNotExist($backendPath.'/app/Logging/ZjmfBridgeLogger.php');
        $this->assertFileDoesNotExist($backendPath.'/app/Http/Middleware/AuthenticateZjmfClient.php');
        $this->assertFileDoesNotExist($backendPath.'/app/Http/Middleware/LogZjmfBridgeRequest.php');
        $this->assertFileDoesNotExist($backendPath.'/app/Http/Middleware/ResolveZjmfActor.php');
        $this->assertFileDoesNotExist($backendPath.'/app/Http/Middleware/VerifyZjmfSignature.php');
        $this->assertFileDoesNotExist($backendPath.'/app/Http/Middleware/ZjmfBridgeEnabled.php');
        $this->assertFileDoesNotExist($backendPath.'/app/Models/AgentApplication.php');
        $this->assertFileDoesNotExist($backendPath.'/app/Http/Controllers/Client/V2/AgentController.php');
        $this->assertFileDoesNotExist($backendPath.'/database/migrations/2026_07_11_000001_create_agent_applications_table.php');
        $this->assertFileDoesNotExist($backendPath.'/database/migrations/2026_07_11_000002_add_api_key_to_agent_applications.php');
        $this->assertFileDoesNotExist($backendPath.'/tests/Support/InstallsZjmfBridgeAddon.php');

        $bootstrap = file_get_contents($backendPath.'/bootstrap/app.php');

        $this->assertIsString($bootstrap);
        // 魔方「上游协议」是平台核心功能（routes/v2-zjmf-upstream.php 与
        // EnsureZjmfUpstreamAuthenticated 中间件），允许存在于主应用；
        // 本边界测试针对的是 zjmf_bridge 附加插件的运行时不得漏进主应用。
        $this->assertStringNotContainsString('zjmf_bridge', $bootstrap);
    }
}
