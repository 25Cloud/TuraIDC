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
use App\Services\System\SettingService;
use Illuminate\Http\JsonResponse;

class SettingController extends Controller
{
    public function __construct(
        private readonly AdminConfigurationV2QueryService $queryService,
        private readonly SettingService $settings,
        private readonly NotificationTemplateService $notificationTemplates,
        private readonly NotificationTemplateTestSendService $templateTestSender,
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
        return $this->success($this->queryService->settingSecret(
            $request->groupKey(),
            $request->secretKey()
        ));
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
