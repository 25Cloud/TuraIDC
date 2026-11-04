<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AutoRefund\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use Illuminate\Http\JsonResponse;
use TuraIDC\Plugins\Addons\AutoRefund\Http\Requests\Admin\CloseApplicationRequest;
use TuraIDC\Plugins\Addons\AutoRefund\Http\Requests\Admin\ListApplicationsRequest;
use TuraIDC\Plugins\Addons\AutoRefund\Http\Requests\Admin\ListProductRulesRequest;
use TuraIDC\Plugins\Addons\AutoRefund\Http\Requests\Admin\RejectApplicationRequest;
use TuraIDC\Plugins\Addons\AutoRefund\Http\Requests\Admin\SaveProductRuleRequest;
use TuraIDC\Plugins\Addons\AutoRefund\Http\Requests\Admin\SettleApplicationRequest;
use TuraIDC\Plugins\Addons\AutoRefund\Models\AutoRefundApplication;
use TuraIDC\Plugins\Addons\AutoRefund\Services\AutoRefundProductRuleService;
use TuraIDC\Plugins\Addons\AutoRefund\Services\AutoRefundService;

/**
 * 管理端自助退款台账：列表、详情、结算、驳回、作废、上游连通性、商品策略。
 *
 * 权限由 routes/admin.php 里的 permission 中间件守（沿用管理端既有权限码），
 * 控制器不再自己判权限位；管理端按要求不做脱敏，上游原始摘要整段返回。
 */
final class AutoRefundAdminController extends Controller
{
    public function __construct(
        private readonly AutoRefundService $service,
        private readonly AutoRefundProductRuleService $rules,
    ) {}

    /**
     * GET /api/v2/admin/auto-refund/applications
     */
    public function index(ListApplicationsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $paginator = $this->service->listForAdmin($data, $request->perPage(20, 100));

        return $this->paginate($paginator, null, [
            'list' => array_map(
                fn (AutoRefundApplication $application): array => $this->service->presentApplication($application, true),
                $paginator->items(),
            ),
        ]);
    }

    /**
     * GET /api/v2/admin/auto-refund/applications/{application}
     */
    public function show(int $application): JsonResponse
    {
        return $this->success(
            $this->service->presentApplication($this->service->findForAdmin($application), true),
        );
    }

    /**
     * POST /api/v2/admin/auto-refund/applications/{application}/settle
     */
    public function settle(SettleApplicationRequest $request, int $application): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();
        $data = $request->validated();

        $result = $this->service->settle(
            $admin,
            $application,
            (string) $data['amount'],
            (string) ($data['remark'] ?? ''),
            [
                'operator_id' => (int) $admin->id,
                'operator_name' => (string) ($admin->nickname ?: $admin->username),
                'ip_address' => (string) $request->ip(),
            ],
        );

        return $this->success($result, '已结算到用户余额');
    }

    /**
     * POST /api/v2/admin/auto-refund/applications/{application}/reject
     */
    public function reject(RejectApplicationRequest $request, int $application): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();
        $data = $request->validated();

        return $this->success(
            $this->service->reject($admin, $application, (string) $data['remark']),
            '已驳回该申请',
        );
    }

    /**
     * POST /api/v2/admin/auto-refund/applications/{application}/close
     */
    public function close(CloseApplicationRequest $request, int $application): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();
        $data = $request->validated();

        return $this->success(
            $this->service->close($admin, $application, (string) $data['remark']),
            '已作废该申请',
        );
    }

    /**
     * POST /api/v2/admin/auto-refund/upstream/test
     */
    public function testUpstream(): JsonResponse
    {
        $result = $this->service->testUpstream();

        return $result['ok']
            ? $this->success($result, '上游连通正常，共 '.$result['host_count'].' 台机器可见')
            : $this->error(42200, '上游连通异常：'.($result['message'] ?? '未知原因'), $result);
    }

    /**
     * GET /api/v2/admin/auto-refund/upstream/products
     */
    public function upstreamProducts(): JsonResponse
    {
        return $this->success($this->service->upstreamProducts());
    }

    /**
     * GET /api/v2/admin/auto-refund/product-rules
     *
     * 商品策略列表：商品 id/名称/一级分组、四项策略值、是否已配置。
     * `status` 支持 `all` / `configured` / `unconfigured`。
     */
    public function productRules(ListProductRulesRequest $request): JsonResponse
    {
        $data = $request->validated();
        $paginator = $this->rules->paginate($data, $request->perPage(20, 100));

        return $this->paginate($paginator, null, ['list' => $paginator->items()]);
    }

    /**
     * PUT /api/v2/admin/auto-refund/product-rules/{product}
     *
     * 保存单个商品的退款策略（`enabled=false` 或不建行＝该商品不参与自助退款）。
     */
    public function saveProductRule(SaveProductRuleRequest $request, int $product): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();

        return $this->success(
            $this->rules->save($admin, $product, $request->validated()),
            '商品退款策略已保存',
        );
    }
}
