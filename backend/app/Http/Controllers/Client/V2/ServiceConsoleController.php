<?php

namespace App\Http\Controllers\Client\V2;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\V2\Service\CreateHostUpgradeOrderRequest;
use App\Http\Requests\Client\V2\Service\CreateNatForwardingRequest;
use App\Http\Requests\Client\V2\Service\CreateRenewOrderRequest;
use App\Http\Requests\Client\V2\Service\CreateSecurityGroupRequest;
use App\Http\Requests\Client\V2\Service\CreateSecurityRuleRequest;
use App\Http\Requests\Client\V2\Service\CreateTrafficPackageOrderRequest;
use App\Http\Requests\Client\V2\Service\IndexRequest;
use App\Http\Requests\Client\V2\Service\ListServiceOperationLogsRequest;
use App\Http\Requests\Client\V2\Service\ModuleStatusRequest;
use App\Http\Requests\Client\V2\Service\MonitorBatchRequest;
use App\Http\Requests\Client\V2\Service\MonitorRequest;
use App\Http\Requests\Client\V2\Service\QuoteHostUpgradeRequest;
use App\Http\Requests\Client\V2\Service\QuoteTrafficPackageRequest;
use App\Http\Requests\Client\V2\Service\RenewPreviewRequest;
use App\Http\Requests\Client\V2\Service\UpdateAutoRenewRequest;
use App\Http\Requests\Client\V2\Service\UpdateNameRequest;
use App\Http\Requests\Client\V2\Service\UpdateRemarkRequest;
use App\Services\ClientServiceConsole\ClientServiceConsoleService;
use App\Services\Provisioning\ServiceRenewService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ServiceConsoleController extends Controller
{
    public function __construct(
        private ClientServiceConsoleService $clientServiceConsoleService,
        private ServiceRenewService $serviceRenewService,
    ) {}

    public function index(IndexRequest $request)
    {
        $filters = $request->validated();

        return $this->success(
            $this->clientServiceConsoleService->paginateForUser($request->user(), $filters)
        );
    }

    public function groupedOverview(Request $request)
    {
        return $this->success(
            $this->clientServiceConsoleService->groupedOverviewForUser($request->user())
        );
    }

    public function config(Request $request, int $id)
    {
        return $this->success(
            $this->clientServiceConsoleService->getServiceConfigForUser(
                $request->user(),
                $id
            )
        );
    }

    public function renewPreview(RenewPreviewRequest $request, int $id)
    {
        $data = $request->validated();

        return $this->success(
            $this->serviceRenewService->previewForUser(
                $request->user(),
                $id,
                $data['billing_cycle'] ?? null,
                (int) ($data['user_coupon_id'] ?? 0)
            )
        );
    }

    public function updateRemark(UpdateRemarkRequest $request, int $id)
    {
        $data = $request->validated();

        return $this->success(
            $this->clientServiceConsoleService->updateRemarkForUser(
                $request->user(),
                $id,
                $data['remark'] ?? null,
                $this->buildOperationContext($request)
            ),
            '备注已更新'
        );
    }

    public function updateName(UpdateNameRequest $request, int $id)
    {
        $data = $request->validated();

        return $this->success(
            $this->clientServiceConsoleService->updateServiceNameForUser(
                $request->user(),
                $id,
                $data['name'] ?? null,
                $this->buildOperationContext($request)
            ),
            '实例名称已更新'
        );
    }

    public function trafficPackages(Request $request, int $id)
    {
        return $this->success(
            $this->clientServiceConsoleService->getTrafficPackagePreviewForUser(
                $request->user(),
                $id
            )
        );
    }

    public function quoteTrafficPackage(QuoteTrafficPackageRequest $request, int $id)
    {
        $data = $request->validated();

        return $this->success(
            $this->clientServiceConsoleService->quoteTrafficPackageForUser(
                $request->user(),
                $id,
                $data
            )
        );
    }

    public function hostUpgradePreview(Request $request, int $id)
    {
        return $this->success(
            $this->clientServiceConsoleService->getHostUpgradePreviewForUser(
                $request->user(),
                $id
            )
        );
    }

    public function quoteHostUpgrade(QuoteHostUpgradeRequest $request, int $id)
    {
        $data = $request->validated();

        return $this->success(
            $this->clientServiceConsoleService->quoteHostUpgradeForUser(
                $request->user(),
                $id,
                $data
            )
        );
    }

    public function createRenewOrder(CreateRenewOrderRequest $request, int $id)
    {
        $data = $request->validated();

        $invoice = $this->executeLockedServiceAction(
            $request,
            $id,
            'renew_order',
            fn () => $this->serviceRenewService->createRenewInvoiceForUser(
                $request->user(),
                $id,
                (string) $data['billing_cycle'],
                (int) ($data['user_coupon_id'] ?? 0),
                $this->buildOperationContext($request)
            )
        );
        $invoice->loadMissing(['product:id,product_type,product_group_id,service_type_code,config_options,purchase_requires', 'service']);

        return $this->success([
            'id' => (int) $invoice->id,
            'invoice_no' => (string) $invoice->invoice_no,
            'service_id' => (int) ($invoice->service_id ?? 0),
        ], '续费账单创建成功');
    }

    public function createTrafficPackageOrder(CreateTrafficPackageOrderRequest $request, int $id)
    {
        $data = $request->validated();

        $invoice = $this->executeLockedServiceAction(
            $request,
            $id,
            'traffic_package_order',
            fn () => $this->clientServiceConsoleService->createTrafficPackageInvoiceForUser(
                $request->user(),
                $id,
                $data,
                $this->buildOperationContext($request)
            )
        );
        $invoice->loadMissing(['product:id,product_type,product_group_id,service_type_code,config_options,purchase_requires', 'service']);

        return $this->success([
            'id' => (int) $invoice->id,
            'invoice_no' => (string) $invoice->invoice_no,
            'service_id' => (int) ($invoice->service_id ?? 0),
        ], '流量包账单创建成功');
    }

    public function createHostUpgradeOrder(CreateHostUpgradeOrderRequest $request, int $id)
    {
        $data = $request->validated();

        $invoice = $this->executeLockedServiceAction(
            $request,
            $id,
            'host_upgrade_order',
            fn () => $this->clientServiceConsoleService->createHostUpgradeInvoiceForUser(
                $request->user(),
                $id,
                $data,
                $this->buildOperationContext($request)
            )
        );
        $invoice->loadMissing(['product:id,product_type,product_group_id,service_type_code,config_options,purchase_requires', 'service']);

        return $this->success([
            'id' => (int) $invoice->id,
            'invoice_no' => (string) $invoice->invoice_no,
            'service_id' => (int) ($invoice->service_id ?? 0),
        ], '产品升降级账单创建成功');
    }

    public function updateAutoRenew(UpdateAutoRenewRequest $request, int $id)
    {
        $data = $request->validated();

        return $this->success(
            $this->executeLockedServiceAction(
                $request,
                $id,
                'renew_auto',
                fn () => $this->serviceRenewService->updateAutoRenewForUser(
                    $request->user(),
                    $id,
                    (int) $data['auto_renew'],
                    $this->buildOperationContext($request)
                )
            ),
            '自动续费状态已更新'
        );
    }

    public function moduleStatus(ModuleStatusRequest $request, int $id)
    {
        $filters = $request->validated();

        return $this->success(
            $this->clientServiceConsoleService->getModuleStatusForUser($request->user(), $id, $filters['type'])
        );
    }

    public function reinstallOptions(Request $request, int $id)
    {
        return $this->success(
            $this->clientServiceConsoleService->getReinstallOptionsForUser(
                $request->user(),
                $id,
                $this->booleanQuery($request, 'refresh')
            )
        );
    }

    public function natForwardings(Request $request, int $id)
    {
        return $this->success(
            $this->clientServiceConsoleService->getNatForwardingsForUser($request->user(), $id)
        );
    }

    public function createNatForwarding(CreateNatForwardingRequest $request, int $id)
    {
        $data = $request->validated();

        return $this->success(
            $this->executeLockedServiceAction(
                $request,
                $id,
                'nat_create',
                fn () => $this->clientServiceConsoleService->createNatForwardingForUser(
                    $request->user(),
                    $id,
                    $data,
                    $this->buildOperationContext($request)
                )
            ),
            '端口转发创建成功'
        );
    }

    public function deleteNatForwarding(Request $request, int $id, int $forwardingId)
    {
        return $this->success(
            $this->executeLockedServiceAction(
                $request,
                $id,
                'nat_delete_'.$forwardingId,
                fn () => $this->clientServiceConsoleService->deleteNatForwardingForUser(
                    $request->user(),
                    $id,
                    $forwardingId,
                    $this->buildOperationContext($request)
                )
            ),
            '端口转发删除成功'
        );
    }

    public function operationLogs(ListServiceOperationLogsRequest $request, int $id)
    {
        return $this->success(
            $this->clientServiceConsoleService->getOperationLogsForUser(
                $request->user(),
                $id,
                $request->filters(),
                $request->perPage(10, 50)
            )
        );
    }

    public function monitor(MonitorRequest $request, int $id)
    {
        $filters = $request->validated();

        if ($request->has('fresh')) {
            $filters['fresh'] = $this->booleanQuery($request, 'fresh');
        }

        return $this->success(
            $this->clientServiceConsoleService->getMonitorForUser($request->user(), $id, $filters)
        );
    }

    public function monitorBatch(MonitorBatchRequest $request, int $id)
    {
        $filters = $request->validated();

        if ($request->has('fresh')) {
            $filters['fresh'] = $this->booleanQuery($request, 'fresh');
        }

        return $this->success(
            $this->clientServiceConsoleService->getMonitorBatchForUser($request->user(), $id, $filters)
        );
    }

    public function securityGroups(Request $request, int $id)
    {
        return $this->success(
            $this->clientServiceConsoleService->getSecurityGroupsForUser(
                $request->user(),
                $id,
                $this->booleanQuery($request, 'fresh')
            )
        );
    }

    public function securityGroupRules(Request $request, int $id, int $groupId)
    {
        return $this->success(
            $this->clientServiceConsoleService->getSecurityGroupRulesForUser($request->user(), $id, $groupId)
        );
    }

    public function createSecurityGroup(CreateSecurityGroupRequest $request, int $id)
    {
        $data = $request->validated();

        return $this->success(
            $this->executeLockedServiceAction(
                $request,
                $id,
                'security_group_create',
                fn () => $this->clientServiceConsoleService->createSecurityGroupForUser(
                    $request->user(),
                    $id,
                    $data,
                    $this->buildOperationContext($request)
                )
            ),
            '创建成功'
        );
    }

    public function applySecurityGroup(Request $request, int $id, int $groupId)
    {
        return $this->success(
            $this->executeLockedServiceAction(
                $request,
                $id,
                'security_group_apply_'.$groupId,
                fn () => $this->clientServiceConsoleService->applySecurityGroupForUser(
                    $request->user(),
                    $id,
                    $groupId,
                    $this->buildOperationContext($request)
                )
            ),
            '应用成功'
        );
    }

    public function deleteSecurityGroup(Request $request, int $id, int $groupId)
    {
        return $this->success(
            $this->executeLockedServiceAction(
                $request,
                $id,
                'security_group_delete_'.$groupId,
                fn () => $this->clientServiceConsoleService->deleteSecurityGroupForUser(
                    $request->user(),
                    $id,
                    $groupId,
                    $this->buildOperationContext($request)
                )
            ),
            '删除成功'
        );
    }

    public function createSecurityRule(CreateSecurityRuleRequest $request, int $id, int $groupId)
    {
        $data = $request->validated();

        return $this->success(
            $this->executeLockedServiceAction(
                $request,
                $id,
                'security_rule_create_'.$groupId,
                fn () => $this->clientServiceConsoleService->createSecurityRuleForUser(
                    $request->user(),
                    $id,
                    $groupId,
                    $data,
                    $this->buildOperationContext($request)
                )
            ),
            '创建成功'
        );
    }

    public function deleteSecurityRule(Request $request, int $id, int $groupId, int $ruleId)
    {
        return $this->success(
            $this->executeLockedServiceAction(
                $request,
                $id,
                'security_rule_delete_'.$groupId.'_'.$ruleId,
                fn () => $this->clientServiceConsoleService->deleteSecurityRuleForUser(
                    $request->user(),
                    $id,
                    $groupId,
                    $ruleId,
                    $this->buildOperationContext($request)
                )
            ),
            '删除成功'
        );
    }

    public function vnc(Request $request, int $id)
    {
        return $this->success(
            $this->clientServiceConsoleService->getVncUrlForUser(
                $request->user(),
                $id,
                $this->buildOperationContext($request)
            ),
            '获取VNC链接成功'
        );
    }

    public function vncToken(Request $request, string $token)
    {
        return $this->success(
            $this->clientServiceConsoleService->resolvePublicVncTokenPayload($token)
        );
    }

    private function buildOperationContext(Request $request, string $actorType = 'client'): array
    {
        $user = $request->user();

        return [
            'actor_type' => $actorType,
            'actor_user_id' => (int) ($user?->id ?? 0),
            'actor_name' => (string) ($user?->display_name ?? $user?->nickname ?? $user?->email ?? ''),
            'ip_address' => (string) $request->ip(),
            'trace_id' => (string) $request->header('X-Request-Id', ''),
            'request_origin' => $this->resolveRequestOrigin($request),
        ];
    }

    private function resolveRequestOrigin(Request $request): string
    {
        $origin = trim((string) $request->headers->get('Origin', ''));
        if ($origin !== '') {
            return rtrim($origin, '/');
        }

        $referer = trim((string) $request->headers->get('Referer', ''));
        if ($referer !== '') {
            $parts = parse_url($referer);
            if (is_array($parts)) {
                $scheme = strtolower((string) ($parts['scheme'] ?? ''));
                $host = (string) ($parts['host'] ?? '');
                $port = (int) ($parts['port'] ?? 0);

                if ($scheme !== '' && $host !== '') {
                    $defaultPort = $scheme === 'https' ? 443 : 80;

                    if ($port > 0 && $port !== $defaultPort) {
                        return sprintf('%s://%s:%d', $scheme, $host, $port);
                    }

                    return sprintf('%s://%s', $scheme, $host);
                }
            }
        }

        return rtrim($request->getSchemeAndHttpHost(), '/');
    }

    private function executeLockedServiceAction(Request $request, int $serviceId, string $action, callable $callback): mixed
    {
        $userId = (int) ($request->user()?->id ?? 0);
        $lockKey = sprintf('lock:client:service:%d:%d:%s', $userId, $serviceId, sha1($action));

        try {
            return Cache::lock($lockKey, 20)->block(3, $callback);
        } catch (LockTimeoutException) {
            throw new BusinessException('操作处理中，请勿重复提交', 40900, 409);
        }
    }
}
