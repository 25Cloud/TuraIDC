<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AutoRefund\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use TuraIDC\Plugins\Addons\AutoRefund\Http\Requests\Client\ListApplicationsRequest;
use TuraIDC\Plugins\Addons\AutoRefund\Http\Requests\Client\SubmitApplicationRequest;
use TuraIDC\Plugins\Addons\AutoRefund\Models\AutoRefundApplication;
use TuraIDC\Plugins\Addons\AutoRefund\Services\AutoRefundService;

/**
 * 用户端退款中心：薄层，业务全在 AutoRefundService。
 *
 * 所有读写都以 `$request->user()->id` 为过滤条件，任何按 id 取单条的地方
 * 都在服务层再带一次 user_id —— 不允许出现跨用户读写（越权在认证/授权层解决，
 * 不交给 WAF，也不靠前端不传别人的 id）。
 */
final class AutoRefundController extends Controller
{
    public function __construct(
        private readonly AutoRefundService $service,
    ) {}

    /**
     * GET /api/v2/client/auto-refund/bootstrap
     *
     * 只读自检：开关、规则文案、可退窗口、上游是否已配置。**不请求上游**。
     */
    public function bootstrap(): JsonResponse
    {
        return $this->success($this->service->bootstrap());
    }

    /**
     * GET /api/v2/client/auto-refund/candidates
     *
     * 上游不可用时降级返回（带 upstream_error），不抛 500。
     */
    public function candidates(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->success($this->service->candidates($user));
    }

    /**
     * POST /api/v2/client/auto-refund/applications
     */
    public function store(SubmitApplicationRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        $application = $this->service->submit(
            $user,
            (int) $data['service_id'],
            (string) $data['reason'],
        );

        $status = (string) ($application['status'] ?? '');

        if (in_array($status, [AutoRefundApplication::STATUS_PENDING_REVIEW, AutoRefundApplication::STATUS_SETTLED], true)) {
            return $this->success($application, $this->successMessage($application, $status));
        }

        // 上游没受理：台账已经落库（失败/结果未知都要留痕），但客户端必须看到失败，
        // 不能拿 code:0 假装成功。上游 msg 原样回报，方便用户和站长对齐。
        $upstreamMessage = trim((string) ($application['upstream_msg'] ?? ''));
        $fallback = $status === AutoRefundApplication::STATUS_UNCERTAIN
            ? '提交结果未知，请稍后刷新查看或联系客服确认'
            : '上游未受理退款申请';

        return $this->error(42200, $upstreamMessage !== '' ? $upstreamMessage : $fallback, [
            'application' => $application,
        ]);
    }

    /**
     * 通道决定用户看到的话术：四种退款类型对应的「刚才发生了什么」完全不同。
     *
     * @param  array<string, mixed>  $application
     */
    private function successMessage(array $application, string $status): string
    {
        $refundType = (string) ($application['refund_type'] ?? '');

        return match (true) {
            $status === AutoRefundApplication::STATUS_SETTLED => '已退款到余额',
            $refundType === 'api_ticket' => '已向上游提交工单，等待处理',
            $refundType === 'bridge' => '已提交上游审核',
            default => '等待管理员审核',
        };
    }

    /**
     * POST /api/v2/client/auto-refund/applications/{application}/cancel
     */
    public function cancel(Request $request, int $application): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $result = $this->service->cancel($user, $application);

        return $this->success($result, '已取消退款申请');
    }

    /**
     * GET /api/v2/client/auto-refund/applications
     */
    public function index(ListApplicationsRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        $paginator = $this->service->listForUser($user, $data, $request->perPage(15, 50));

        return $this->paginate($paginator, null, [
            'list' => array_map(
                fn (AutoRefundApplication $application): array => $this->service->presentApplication($application),
                $paginator->items(),
            ),
        ]);
    }
}
