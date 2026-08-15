<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\Setting\ListNotificationTemplatesRequest;
use App\Http\Requests\Admin\V2\Setting\ListSettingsRequest;
use App\Http\Requests\Admin\V2\Setting\RevealSettingSecretRequest;
use App\Http\Requests\Admin\V2\Setting\TestNotificationTemplateSendRequest;
use App\Http\Requests\Admin\V2\Setting\UpdateSettingsRequest;
use App\Http\Resources\Admin\V2\AdminActionResultResource;
use App\Http\Resources\Admin\V2\AdminNotificationTemplateResource;
use App\Services\Admin\V2\AdminConfigurationV2QueryService;
use App\Services\System\NotificationTemplateService;
use App\Services\System\NotificationTemplateTestSendService;
use App\Services\System\OperationLogService;
use App\Services\System\SettingService;
use Illuminate\Http\JsonResponse;

class SettingController extends Controller
{
    public function __construct(
        private readonly AdminConfigurationV2QueryService $queryService,
        private readonly SettingService $settings,
        private readonly NotificationTemplateService $notificationTemplates,
        private readonly NotificationTemplateTestSendService $templateTestSender,
        private readonly OperationLogService $operationLogService,
    ) {}

    public function index(ListSettingsRequest $request)
    {
        return $this->success($this->queryService->settings(
            $request->group(),
            $request->pageNumber(),
            $request->pageSize()
        ));
    }

    public function notificationTemplates(ListNotificationTemplatesRequest $request)
    {
        $templates = collect($this->notificationTemplates->list($request->channel()));

        return $this->success([
            'list' => AdminNotificationTemplateResource::collection($templates)->resolve(),
            'total' => $templates->count(),
        ]);
    }

    public function revealSecret(RevealSettingSecretRequest $request)
    {
        $group = $request->groupKey();
        $key = $request->secretKey();
        $result = $this->queryService->settingSecret($group, $key);

        // 密钥 reveal 统一审计：记录谁在何时查看了哪个敏感字段。
        $this->operationLogService->write(
            userId: (int) ($request->user()?->id ?? 0),
            userType: 'admin',
            action: 'secret.reveal',
            module: 'secret',
            targetId: 0,
            detail: [
                'secret_type' => 'setting',
                'secret_key' => $key,
                'group' => $group,
                'operator_name' => (string) ($request->user()?->username ?? $request->user()?->name ?? ''),
                'trace_id' => (string) $request->header('X-Request-Id', ''),
            ],
            ipAddress: (string) $request->ip(),
        );

        return $this->success($result);
    }

    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        $group = $request->group();
        $this->settings->saveGroupSettings($group, $request->settings());

        return $this->success(AdminActionResultResource::make([
            'id' => 'settings',
            'status' => 'completed',
            'message' => '配置已更新',
            'detail' => [
                'group' => $group,
            ],
        ])->resolve(), '配置已更新');
    }

    public function testNotificationTemplate(TestNotificationTemplateSendRequest $request): JsonResponse
    {
        $result = $this->templateTestSender->send(
            $request->channel(),
            $request->code(),
            $request->recipients()
        );

        return $this->success($result, '测试发送已完成');
    }
}
