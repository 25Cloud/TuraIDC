<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\IntegrationPlugin\DeleteIntegrationPluginRequest;
use App\Http\Requests\Admin\V2\IntegrationPlugin\InstallIntegrationPluginRequest;
use App\Http\Requests\Admin\V2\IntegrationPlugin\ListIntegrationPluginsRequest;
use App\Http\Requests\Admin\V2\IntegrationPlugin\RevealIntegrationPluginSecretRequest;
use App\Http\Requests\Admin\V2\IntegrationPlugin\RunIntegrationPluginTaskRequest;
use App\Http\Requests\Admin\V2\IntegrationPlugin\ScanIntegrationPluginsRequest;
use App\Http\Requests\Admin\V2\IntegrationPlugin\ShowIntegrationPluginRequest;
use App\Http\Requests\Admin\V2\IntegrationPlugin\UpdateIntegrationPluginConfigRequest;
use App\Http\Requests\Admin\V2\IntegrationPlugin\UpdateIntegrationPluginStatusRequest;
use App\Http\Resources\Admin\V2\AdminActionResultResource;
use App\Models\AdminUser;
use App\Models\IntegrationPlugin;
use App\Services\Admin\V2\AdminConfigurationV2QueryService;
use App\Services\System\OperationLogService;

class IntegrationPluginController extends Controller
{
    public function __construct(
        private readonly AdminConfigurationV2QueryService $queryService,
        private readonly OperationLogService $operationLogService,
    ) {}

    public function index(ListIntegrationPluginsRequest $request)
    {
        return $this->success($this->queryService->plugins(
            $request->domain(),
            $request->pageNumber(),
            $request->pageSize()
        ));
    }

    public function show(ShowIntegrationPluginRequest $request, IntegrationPlugin $plugin)
    {
        return $this->success($this->queryService->pluginDetail($plugin));
    }

    public function schema(ShowIntegrationPluginRequest $request, IntegrationPlugin $plugin)
    {
        return $this->success($this->queryService->pluginSchema($plugin));
    }

    public function revealSecret(RevealIntegrationPluginSecretRequest $request, IntegrationPlugin $plugin)
    {
        $result = $this->queryService->pluginSecret($plugin, $request->secretKey());

        // 密钥 reveal 统一审计：记录谁在何时查看了哪个敏感字段。
        $this->operationLogService->write(
            userId: (int) ($request->user()?->id ?? 0),
            userType: 'admin',
            action: 'secret.reveal',
            module: 'secret',
            targetId: (int) $plugin->id,
            detail: [
                'secret_type' => 'plugin',
                'secret_key' => (string) $request->secretKey(),
                'plugin_id' => (int) $plugin->id,
                'plugin_name' => (string) ($plugin->name ?? ''),
                'operator_name' => (string) ($request->user()?->username ?? $request->user()?->name ?? ''),
                'trace_id' => (string) $request->header('X-Request-Id', ''),
            ],
            ipAddress: (string) $request->ip(),
        );

        return $this->success($result);
    }

    public function scan(ScanIntegrationPluginsRequest $request)
    {
        $result = $this->queryService->scanPlugins($request->domain());

        return $this->success(AdminActionResultResource::make($result)->resolve(), (string) $result['message']);
    }

    public function install(InstallIntegrationPluginRequest $request)
    {
        return $this->success(
            $this->queryService->installPlugin($request->domain(), $request->slug()),
            '插件安装成功'
        );
    }

    public function updateConfig(UpdateIntegrationPluginConfigRequest $request, IntegrationPlugin $plugin)
    {
        $admin = $request->user();

        return $this->success(
            $this->queryService->updatePluginConfig(
                $plugin,
                $request->config(),
                $admin instanceof AdminUser ? $admin : null
            ),
            '插件配置已更新'
        );
    }

    public function destroy(DeleteIntegrationPluginRequest $request, IntegrationPlugin $plugin)
    {
        $result = $this->queryService->uninstallPlugin($plugin, $request->force());

        return $this->success(AdminActionResultResource::make($result)->resolve(), (string) $result['message']);
    }

    public function updateStatus(UpdateIntegrationPluginStatusRequest $request, IntegrationPlugin $plugin)
    {
        $result = $this->queryService->updatePluginStatus($plugin, $request->enabled());

        return $this->success(AdminActionResultResource::make($result)->resolve(), (string) $result['message']);
    }

    public function runTask(RunIntegrationPluginTaskRequest $request, IntegrationPlugin $plugin)
    {
        $result = $this->queryService->runPluginTask($plugin, $request->taskType(), $request->payload());

        return $this->success(AdminActionResultResource::make($result)->resolve(), (string) $result['message']);
    }
}
