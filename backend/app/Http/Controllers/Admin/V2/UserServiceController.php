<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\UserService\CreateServiceHostUpgradeOrderRequest;
use App\Http\Requests\Admin\V2\UserService\CreateServiceNatForwardingRequest;
use App\Http\Requests\Admin\V2\UserService\CreateServiceTrafficPackageOrderRequest;
use App\Http\Requests\Admin\V2\UserService\CreateSecurityGroupRequest;
use App\Http\Requests\Admin\V2\UserService\CreateSecurityRuleRequest;
use App\Http\Requests\Admin\V2\UserService\DeleteServiceNatForwardingRequest;
use App\Http\Requests\Admin\V2\UserService\DeleteServiceSecurityGroupRequest;
use App\Http\Requests\Admin\V2\UserService\DeleteServiceSecurityRuleRequest;
use App\Http\Requests\Admin\V2\UserService\DeleteUserServiceRequest;
use App\Http\Requests\Admin\V2\UserService\ListServiceOperationLogsRequest;
use App\Http\Requests\Admin\V2\UserService\ListUserServicesRequest;
use App\Http\Requests\Admin\V2\UserService\ManualProvisionUserServiceRequest;
use App\Http\Requests\Admin\V2\UserService\QuoteServiceHostUpgradeRequest;
use App\Http\Requests\Admin\V2\UserService\QuoteServiceTrafficPackageRequest;
use App\Http\Requests\Admin\V2\UserService\RefreshUserServiceStatusesRequest;
use App\Http\Requests\Admin\V2\UserService\ServiceRescueActionRequest;
use App\Http\Requests\Admin\V2\UserService\ShowServiceModuleStatusRequest;
use App\Http\Requests\Admin\V2\UserService\ShowServiceMonitorBatchRequest;
use App\Http\Requests\Admin\V2\UserService\ShowServiceMonitorRequest;
use App\Http\Requests\Admin\V2\UserService\ShowServiceSecurityGroupsRequest;
use App\Http\Requests\Admin\V2\UserService\ShowUserServiceConnectionRequest;
use App\Http\Requests\Admin\V2\UserService\ShowUserServiceRemoteStatusRequest;
use App\Http\Requests\Admin\V2\UserService\ShowUserServiceRequest;
use App\Http\Requests\Admin\V2\UserService\StoreUserServiceRequest;
use App\Http\Requests\Admin\V2\UserService\UpdateServiceAutoRenewRequest;
use App\Http\Requests\Admin\V2\UserService\UpdateServiceNameRequest;
use App\Http\Requests\Admin\V2\UserService\UpdateServiceRemarkRequest;
use App\Http\Requests\Admin\V2\UserService\UpdateUserServiceMetaRequest;
use App\Http\Resources\Admin\V2\AdminActionResultResource;
use App\Http\Resources\Admin\V2\AdminUserServiceListItemResource;
use App\Http\Resources\Admin\V2\AdminUserServiceRefreshResultResource;
use App\Http\Resources\Service\V2\ServiceConnectionResource;
use App\Http\Resources\Service\V2\ServiceDetailResource;
use App\Http\Resources\Service\V2\ServiceRuntimeResource;
use App\Models\User;
use App\Services\ClientServiceConsole\ClientServiceConsoleService;
use App\Services\User\UserService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class UserServiceController extends Controller
{
    public function __construct(
        private readonly UserService $users,
    ) {}

    public function index(ListUserServicesRequest $request, User $user): JsonResponse
    {
        $result = $this->users->services($user, $request->filters(), $request->perPage());

        return $this->success([
            'list' => collect((array) ($result['list'] ?? []))
                ->map(fn (mixed $item): array => AdminUserServiceListItemResource::make($item)->resolve())
                ->values()
                ->all(),
            'total' => (int) ($result['total'] ?? 0),
            'page' => (int) ($result['page'] ?? $request->integer('page', 1)),
            'page_size' => (int) ($result['page_size'] ?? $request->perPage()),
        ]);
    }

    public function store(StoreUserServiceRequest $request, User $user): JsonResponse
    {
        $detail = $this->users->createManualService($user, $request->payload(), $this->adminContext($request));

        return $this->success([
            'service' => ServiceDetailResource::make($detail)->resolve(),
        ], '服务创建成功');
    }

    public function show(ShowUserServiceRequest $request, User $user, int $service): JsonResponse
    {
        $detail = $this->users->serviceDetail(
            $user,
            $service,
            $request->boolean('refresh'),
            true
        );

        return $this->success([
            'service' => (new ServiceDetailResource($detail))->resolve(),
        ]);
    }

    public function connection(ShowUserServiceConnectionRequest $request, User $user, int $service): JsonResponse
    {
        $detail = $this->users->serviceBaseDetail($user, $service, true);

        return $this->success([
            'connection' => (new ServiceConnectionResource((array) ($detail['connection'] ?? [])))->resolve(),
        ]);
    }

    public function remoteStatus(ShowUserServiceRemoteStatusRequest $request, User $user, int $service): JsonResponse
    {
        $detail = $this->users->serviceRemoteStatusPatch($user, $service, true, true);

        return $this->success([
            'service' => ServiceRuntimeResource::make($detail)->resolve(),
        ]);
    }

    public function refreshStatuses(RefreshUserServiceStatusesRequest $request, User $user): JsonResponse
    {
        $payload = $request->validated();
        $result = $this->users->refreshServiceStatuses($user, (array) ($payload['service_ids'] ?? []));

        return $this->success([
            'result' => AdminUserServiceRefreshResultResource::make($result)->resolve(),
        ], '当前页实例状态已刷新');
    }

    public function updateMeta(UpdateUserServiceMetaRequest $request, User $user, int $service): JsonResponse
    {
        $detail = $this->users->updateServiceMeta($user, $service, $request->payload(), $this->adminContext($request));

        return $this->success([
            'service' => ServiceDetailResource::make($detail)->resolve(),
        ], '服务信息已更新');
    }

    public function manualProvision(ManualProvisionUserServiceRequest $request, User $user, int $service): JsonResponse
    {
        $detail = $this->users->manualProvisionService($user, $service, $request->payload(), $this->adminContext($request));

        return $this->success([
            'service' => ServiceDetailResource::make($detail)->resolve(),
        ], '已重新提交上游开通');
    }

    public function destroy(DeleteUserServiceRequest $request, User $user, int $service): JsonResponse
    {
        $this->users->deleteService($user, $service, $this->adminContext($request));

        return $this->success(null, '服务记录已删除');
    }

    public function config(ShowUserServiceRequest $request, User $user, int $service): JsonResponse
    {
        return $this->success($this->users->serviceConsoleConfig($user, $service));
    }

    public function capabilities(ShowUserServiceRequest $request, User $user, int $service): JsonResponse
    {
        return $this->success($this->users->serviceConsoleCapabilities($user, $service));
    }

    public function createAreaTicket(ShowUserServiceRequest $request, User $user, int $service): JsonResponse
    {
        return $this->success(
            $this->users->createServiceConsoleAreaTicket($user, $service),
            '已生成访问凭证'
        );
    }

    public function moduleStatus(ShowServiceModuleStatusRequest $request, User $user, int $service): JsonResponse
    {
        $data = $request->validated();

        return $this->success($this->users->serviceModuleStatus($user, $service, (string) $data['type']));
    }

    public function operationLogs(ListServiceOperationLogsRequest $request, User $user, int $service): JsonResponse
    {
        return $this->success(
            $this->users->serviceOperationLogs($user, $service, $request->filters(), $request->perPage(10, 50))
        );
    }

    public function monitor(ShowServiceMonitorRequest $request, User $user, int $service): JsonResponse
    {
        $filters = $request->validated();

        if ($request->has('fresh')) {
            $filters['fresh'] = $request->boolean('fresh');
        }

        return $this->success($this->users->serviceMonitor($user, $service, $filters));
    }

    public function monitorBatch(ShowServiceMonitorBatchRequest $request, User $user, int $service): JsonResponse
    {
        $filters = $request->validated();

        if ($request->has('fresh')) {
            $filters['fresh'] = $request->boolean('fresh');
        }

        return $this->success($this->users->serviceMonitorBatch($user, $service, $filters));
    }

    public function trafficPackages(ShowUserServiceRequest $request, User $user, int $service): JsonResponse
    {
        return $this->success($this->users->serviceTrafficPackages($user, $service));
    }

    public function upgradePreview(ShowUserServiceRequest $request, User $user, int $service): JsonResponse
    {
        return $this->success($this->users->serviceUpgradePreview($user, $service));
    }

    public function natForwardings(ShowUserServiceRequest $request, User $user, int $service): JsonResponse
    {
        return $this->success($this->users->serviceNatForwardings($user, $service));
    }

    public function securityGroups(ShowServiceSecurityGroupsRequest $request, User $user, int $service): JsonResponse
    {
        return $this->success(
            $this->users->serviceSecurityGroups($user, $service, $request->boolean('fresh'))
        );
    }

    public function securityGroupRules(Request $request, User $user, int $service, int $group): JsonResponse
    {
        return $this->success($this->users->serviceSecurityGroupRules($user, $service, $group));
    }

    public function vnc(ShowUserServiceRequest $request, User $user, int $service): JsonResponse
    {
        $result = $this->users->serviceVnc($user, $service, $this->adminContext($request));

        $result['detail'] = app(ClientServiceConsoleService::class)->sanitizeClientDetail(
            (array) ($result['detail'] ?? [])
        );

        return $this->success($result, '获取VNC链接成功');
    }

    public function rescue(ServiceRescueActionRequest $request, User $user, int $service): JsonResponse
    {
        $data = $request->validated();
        $result = $this->executeLockedServiceAction(
            $user,
            $service,
            'rescue',
            fn () => $this->users->serviceRescue($user, $service, $data, $this->adminContext($request))
        );

        return $this->success(AdminActionResultResource::make($result)->resolve(), (string) ($result['message'] ?? '救援模式指令已提交'));
    }

    public function updateName(UpdateServiceNameRequest $request, User $user, int $service): JsonResponse
    {
        $data = $request->validated();

        return $this->success([
            'service' => $this->executeLockedServiceAction(
                $user,
                $service,
                'name_update',
                fn () => $this->users->updateServiceName($user, $service, $data['name'] ?? null, $this->adminContext($request))
            ),
        ], '实例名称已更新');
    }

    public function updateRemark(UpdateServiceRemarkRequest $request, User $user, int $service): JsonResponse
    {
        $data = $request->validated();

        return $this->success([
            'service' => $this->executeLockedServiceAction(
                $user,
                $service,
                'remark_update',
                fn () => $this->users->updateServiceRemark($user, $service, $data['remark'] ?? null, $this->adminContext($request))
            ),
        ], '备注已更新');
    }

    public function updateAutoRenew(UpdateServiceAutoRenewRequest $request, User $user, int $service): JsonResponse
    {
        $data = $request->validated();

        return $this->success(
            $this->executeLockedServiceAction(
                $user,
                $service,
                'renew_auto',
                fn () => $this->users->updateServiceAutoRenew($user, $service, (int) $data['auto_renew'], $this->adminContext($request))
            ),
            '自动续费状态已更新'
        );
    }

    public function quoteTrafficPackage(QuoteServiceTrafficPackageRequest $request, User $user, int $service): JsonResponse
    {
        return $this->success(
            $this->users->quoteServiceTrafficPackage($user, $service, $request->validated())
        );
    }

    public function createTrafficPackageOrder(CreateServiceTrafficPackageOrderRequest $request, User $user, int $service): JsonResponse
    {
        $data = $request->validated();
        $invoice = $this->executeLockedServiceAction(
            $user,
            $service,
            'traffic_package_order',
            fn () => $this->users->createServiceTrafficPackageOrder($user, $service, $data, $this->adminContext($request))
        );
        $invoice->loadMissing(['product:id,product_type,product_group_id,service_type_code,config_options,purchase_requires', 'service']);

        return $this->success([
            'id' => (int) $invoice->id,
            'invoice_no' => (string) $invoice->invoice_no,
            'service_id' => (int) ($invoice->service_id ?? 0),
        ], '流量包账单创建成功');
    }

    public function quoteHostUpgrade(QuoteServiceHostUpgradeRequest $request, User $user, int $service): JsonResponse
    {
        return $this->success(
            $this->users->quoteServiceHostUpgrade($user, $service, $request->validated())
        );
    }

    public function createHostUpgradeOrder(CreateServiceHostUpgradeOrderRequest $request, User $user, int $service): JsonResponse
    {
        $data = $request->validated();
        $invoice = $this->executeLockedServiceAction(
            $user,
            $service,
            'host_upgrade_order',
            fn () => $this->users->createServiceHostUpgradeOrder($user, $service, $data, $this->adminContext($request))
        );
        $invoice->loadMissing(['product:id,product_type,product_group_id,service_type_code,config_options,purchase_requires', 'service']);

        return $this->success([
            'id' => (int) $invoice->id,
            'invoice_no' => (string) $invoice->invoice_no,
            'service_id' => (int) ($invoice->service_id ?? 0),
        ], '产品升降级账单创建成功');
    }

    public function createNatForwarding(CreateServiceNatForwardingRequest $request, User $user, int $service): JsonResponse
    {
        $data = $request->validated();

        return $this->success(
            $this->executeLockedServiceAction(
                $user,
                $service,
                'nat_create',
                fn () => $this->users->createServiceNatForwarding($user, $service, $data, $this->adminContext($request))
            ),
            '端口转发创建成功'
        );
    }

    public function deleteNatForwarding(DeleteServiceNatForwardingRequest $request, User $user, int $service, int $forwarding): JsonResponse
    {
        return $this->success(
            $this->executeLockedServiceAction(
                $user,
                $service,
                'nat_delete_'.$forwarding,
                fn () => $this->users->deleteServiceNatForwarding($user, $service, $forwarding, $this->adminContext($request))
            ),
            '端口转发删除成功'
        );
    }

    public function createSecurityGroup(CreateSecurityGroupRequest $request, User $user, int $service): JsonResponse
    {
        $data = $request->validated();

        return $this->success(
            $this->executeLockedServiceAction(
                $user,
                $service,
                'security_group_create',
                fn () => $this->users->createServiceSecurityGroup($user, $service, $data, $this->adminContext($request))
            ),
            '创建成功'
        );
    }

    public function applySecurityGroup(Request $request, User $user, int $service, int $group): JsonResponse
    {
        return $this->success(
            $this->executeLockedServiceAction(
                $user,
                $service,
                'security_group_apply_'.$group,
                fn () => $this->users->applyServiceSecurityGroup($user, $service, $group, $this->adminContext($request))
            ),
            '应用成功'
        );
    }

    public function deleteSecurityGroup(DeleteServiceSecurityGroupRequest $request, User $user, int $service, int $group): JsonResponse
    {
        return $this->success(
            $this->executeLockedServiceAction(
                $user,
                $service,
                'security_group_delete_'.$group,
                fn () => $this->users->deleteServiceSecurityGroup($user, $service, $group, $this->adminContext($request))
            ),
            '删除成功'
        );
    }

    public function createSecurityRule(CreateSecurityRuleRequest $request, User $user, int $service, int $group): JsonResponse
    {
        $data = $request->validated();

        return $this->success(
            $this->executeLockedServiceAction(
                $user,
                $service,
                'security_rule_create_'.$group,
                fn () => $this->users->createServiceSecurityRule($user, $service, $group, $data, $this->adminContext($request))
            ),
            '创建成功'
        );
    }

    public function deleteSecurityRule(DeleteServiceSecurityRuleRequest $request, User $user, int $service, int $group, int $rule): JsonResponse
    {
        return $this->success(
            $this->executeLockedServiceAction(
                $user,
                $service,
                'security_rule_delete_'.$group.'_'.$rule,
                fn () => $this->users->deleteServiceSecurityRule($user, $service, $group, $rule, $this->adminContext($request))
            ),
            '删除成功'
        );
    }

    /**
     * 防重复提交：同一用户对同一服务的同一写操作进行缓存锁保护，
     * 与用户端控制台使用不同锁命名空间，避免跨端相互阻塞。
     */
    private function executeLockedServiceAction(User $user, int $serviceId, string $action, callable $callback): mixed
    {
        $lockKey = sprintf('lock:admin:service:%d:%d:%s', (int) $user->id, $serviceId, sha1($action));

        try {
            return Cache::lock($lockKey, 20)->block(3, $callback);
        } catch (LockTimeoutException) {
            throw new BusinessException('操作处理中，请勿重复提交', 40900, 409);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function adminContext(Request $request): array
    {
        return [
            'operator_id' => (int) ($request->user()?->id ?? 0),
            'operator_name' => (string) ($request->user()?->username ?? $request->user()?->name ?? $request->user()?->email ?? 'admin'),
            'actor_type' => 'admin',
            'actor_user_id' => (int) ($request->user()?->id ?? 0),
            'actor_name' => (string) ($request->user()?->username ?? $request->user()?->name ?? $request->user()?->email ?? 'admin'),
            'trace_id' => (string) ($request->header('X-Request-Id', '')),
            'ip_address' => (string) $request->ip(),
        ];
    }
}
