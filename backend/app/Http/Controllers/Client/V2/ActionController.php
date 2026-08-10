<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\V2\Action\ClientActionRequest;
use App\Http\Requests\Client\V2\Service\PasswordResetActionRequest;
use App\Http\Requests\Client\V2\Service\PowerActionRequest;
use App\Http\Requests\Client\V2\Service\ReinstallationRequest;
use App\Http\Resources\Client\V2\ClientActionResultResource;
use App\Services\Client\V2\ClientActionV2Service;

class ActionController extends Controller
{
    public function __construct(
        private readonly ClientActionV2Service $actionService,
    ) {}

    public function cancelInvoice(ClientActionRequest $request, int $invoice)
    {
        return $this->success(ClientActionResultResource::make(
            $this->actionService->cancelInvoice($request->user(), $invoice, $request)
        )->resolve(), '账单已取消');
    }

    public function cancelOrder(ClientActionRequest $request, int $order)
    {
        return $this->success(ClientActionResultResource::make(
            $this->actionService->cancelOrder($request->user(), $order, $request)
        )->resolve(), '订单已取消');
    }

    public function markNoticeRead(ClientActionRequest $request, int $article)
    {
        return $this->success(ClientActionResultResource::make(
            $this->actionService->markNoticeRead($request->user(), $article)
        )->resolve(), '公告已标记为已读');
    }

    public function markNotificationRead(ClientActionRequest $request, int $notification)
    {
        return $this->success(ClientActionResultResource::make(
            $this->actionService->markNotificationRead($request->user(), $notification)
        )->resolve(), '消息已标记为已读');
    }

    public function power(PowerActionRequest $request, int $service)
    {
        $data = $request->validated();

        return $this->success(ClientActionResultResource::make(
            $this->actionService->powerAction($request->user(), $service, (string) $data['action'], $request)
        )->resolve(), '操作已提交');
    }

    public function resetPassword(PasswordResetActionRequest $request, int $service)
    {
        return $this->success(ClientActionResultResource::make(
            $this->actionService->resetPassword($request->user(), $service, $request->validated(), $request)
        )->resolve(), '重置密码指令已提交');
    }

    public function reinstall(ReinstallationRequest $request, int $service)
    {
        return $this->success(ClientActionResultResource::make(
            $this->actionService->reinstall($request->user(), $service, $request->validated(), $request)
        )->resolve(), '重装系统任务已提交');
    }

    public function recallTicketReply(ClientActionRequest $request, int $ticket, int $reply)
    {
        return $this->success(ClientActionResultResource::make(
            $this->actionService->recallTicketReply($request->user(), $ticket, $reply)
        )->resolve(), '消息已撤回');
    }
}
