<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AutoRefund\Services;

use App\Constants\ServiceStatus;
use App\Exceptions\BusinessException;
use App\Models\AdminUser;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpstreamBinding;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\TicketUpstreamBinding;
use App\Models\User;
use App\Services\Integrations\Plugins\PluginBindingResolver;
use App\Services\ProductCatalog\ProductDisplayNameResolver;
use App\Services\Ticket\TicketDeliveryService;
use App\Services\User\UserService;
use App\Support\DatabaseSchema;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;
use TuraIDC\Plugins\Addons\AutoRefund\Data\AutoRefundApiResult;
use TuraIDC\Plugins\Addons\AutoRefund\Logging\AutoRefundLogger;
use TuraIDC\Plugins\Addons\AutoRefund\Models\AutoRefundApplication;
use TuraIDC\Plugins\Addons\AutoRefund\Models\AutoRefundProductRule;

/**
 * 自助退款的编排层：四种退款类型的分流、申请、取消、结算、驳回、作废、台账、上游连通性。
 *
 * 四条不变量：
 * 1. **闸门先占后用**：申请行先落库（`active_service_id` 唯一索引挡住并发），再去调上游；
 *    上游超时/未知时保留闸门，只有「上游明确拒绝」或人工「作废」才释放。
 * 2. **钱只走既有链路**：结算调用 `UserService::refundService()` → `PaymentService::refundOrder()`，
 *    本插件不加减余额、不建退款单，可退上限由既有账务服务判定。
 * 3. **上游不可用只降级不报错**：`/candidates` 在没有上游时返回本地候选 + `upstream_error`，
 *    绝不把「上游挂了」变成前端 500。
 * 4. **通道由商品策略决定**：`manual` / `auto` 不外发（`channel = local`），
 *    `bridge` 走上游 `api.php`，`api_ticket` 建本站工单再按 `ticket_protocol` 投递上游。
 */
final class AutoRefundService
{
    /** 对账页扫描本地服务的上限（只扫最近的开通记录，更老的请以绑定表为准） */
    private const RECONCILE_SCAN_LIMIT = 2000;

    /** 客户端候选列表上限：控制台一次展示的机器数量 */
    private const CANDIDATE_LIMIT = 200;

    /** `api_ticket` 通道承载工单的部门（财务） */
    public const TICKET_DEPARTMENT = 'billing';

    public function __construct(
        private readonly AutoRefundSettings $settings,
        private readonly AutoRefundApiClient $api,
        private readonly ZjfmTicketApiClient $ticketApi,
        private readonly AutoRefundPolicy $policy,
        private readonly AutoRefundLogger $logger,
        private readonly UserService $userService,
        private readonly ProductDisplayNameResolver $displayNames,
        private readonly TicketDeliveryService $ticketDelivery,
        private readonly PluginBindingResolver $bindings,
    ) {}

    /**
     * 数据表是否已就位。插件迁移没跑时业务入口一律降级，绝不 500。
     */
    public function tableReady(): bool
    {
        return DatabaseSchema::hasTable('auto_refund_applications');
    }

    public function productRuleTableReady(): bool
    {
        return DatabaseSchema::hasTable('auto_refund_product_rules');
    }

    /**
     * 退款中心是否「有意义地可用」。
     *
     * 不能只看「有没有出站通道」：商品策略可以是 `manual` / `auto`，这两类根本不出站，
     * 只配了它们、没配上游凭据的站点照样该开放。反过来，一条策略都没配时页面开了也没用
     *（每台机器都会因为 `rule_missing` 不可退），所以那种情况仍按未开放处理。
     */
    public function isOpen(): bool
    {
        if (! $this->settings->pluginEnabled() || ! $this->settings->enabled()) {
            return false;
        }

        if (! $this->tableReady() || ! $this->productRuleTableReady()) {
            return false;
        }

        if ($this->settings->anyChannelReady()) {
            return true;
        }

        try {
            return AutoRefundProductRule::query()->where('enabled', 1)->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 控制台「退款中心」首屏：开关、策略说明、通道就绪情况。**不请求上游**。
     *
     * @return array<string, mixed>
     */
    public function bootstrap(): array
    {
        $snapshot = $this->settings->snapshot();
        $tableReady = $this->tableReady();

        return [
            'enabled' => (bool) $snapshot['plugin_enabled'],
            // `configured` 保留旧语义（至少一条出站通道就绪），前端据此显示「未开放」
            'configured' => (bool) $snapshot['configured'],
            'open' => $this->isOpen(),
            'table_ready' => $tableReady,
            'rule_table_ready' => $this->productRuleTableReady(),
            'console_notice' => (string) $snapshot['console_notice'],
            'policy' => $snapshot['policy'],
            'ticket' => $snapshot['ticket'],
            'request_timeout' => (int) $snapshot['request_timeout'],
            'status_options' => AutoRefundApplication::statusOptions(),
            'refund_type_options' => AutoRefundProductRule::typeOptions(),
            'blocked_reason_labels' => self::blockedReasonLabels(),
        ];
    }

    /**
     * 可申请列表：本地服务 ∩ 上游 `query` 的 hostid 集合。
     *
     * 上游不可用时不报错：返回本地候选并带 `upstream_error`，前端把整页置为「上游暂不可用」。
     * 只有 `bridge` 类型依赖上游 `query`；商品策略是 `manual` / `auto` 的机器在
     * 上游不可用时仍然可退（它们根本不出站）。
     *
     * @return array{items: array<int, array<string, mixed>>, upstream_available: bool, upstream_error: string|null}
     */
    public function candidates(User $user): array
    {
        if (! $this->tableReady()) {
            return [
                'items' => [],
                'upstream_available' => false,
                'upstream_error' => '自助退款数据表尚未初始化，请联系管理员',
            ];
        }

        $services = Service::query()
            ->with(['product', 'order.invoice', 'invoice', 'upstreamBinding'])
            ->where('user_id', (int) $user->id)
            ->whereIn('status', [ServiceStatus::ACTIVE, ServiceStatus::SUSPENDED, ServiceStatus::EXPIRED, ServiceStatus::PENDING])
            ->orderByDesc('id')
            ->limit(self::CANDIDATE_LIMIT)
            ->get();

        if ($services->isEmpty()) {
            return ['items' => [], 'upstream_available' => true, 'upstream_error' => null];
        }

        [$upstreamAvailable, $upstreamError, $upstreamHostIds] = $this->loadUpstreamHostIds();

        $activeApplications = AutoRefundApplication::query()
            ->where('user_id', (int) $user->id)
            ->whereIn('service_id', $services->pluck('id')->all())
            ->active()
            ->orderByDesc('id')
            ->get()
            ->keyBy('service_id');

        $items = [];
        $anyNeedsListing = false;
        foreach ($services as $service) {
            $hostId = $this->resolveUpstreamHostId($service);
            $application = $activeApplications->get((int) $service->id);

            $evaluation = $this->policy->evaluate($service, [
                'upstream_host_id' => $hostId,
                'upstream_available' => $upstreamAvailable,
                'upstream_contains' => $hostId !== '' && in_array($hostId, $upstreamHostIds, true),
                'active_application_id' => $application instanceof AutoRefundApplication ? (int) $application->id : null,
            ]);

            if ((string) $evaluation['refund_type'] === AutoRefundProductRule::TYPE_BRIDGE) {
                $anyNeedsListing = true;
            }

            $items[] = $this->presentCandidate($service, $hostId, $evaluation, $application);
        }

        return [
            'items' => $items,
            // 只有确实有机器要走上游 `query`（`bridge`）时，才把「上游挂了」当成整页的状态：
            // 全是 manual/auto 的站点、或只用工单通道的站点不该看到这个降级提示。
            'upstream_available' => $anyNeedsListing ? $upstreamAvailable : true,
            'upstream_error' => $anyNeedsListing ? $upstreamError : null,
        ];
    }

    /**
     * 提交退款申请：评估策略 → 占闸门 → 按商品退款类型分流。
     *
     * @return array<string, mixed>
     */
    public function submit(User $user, int $serviceId, string $reason): array
    {
        $this->assertOpen();

        $service = Service::query()
            ->with(['product', 'order.invoice', 'invoice', 'upstreamBinding'])
            ->where('id', $serviceId)
            ->where('user_id', (int) $user->id)
            ->first();

        if (! $service instanceof Service) {
            throw new BusinessException('服务不存在', 40400, 404);
        }

        $hostId = $this->resolveUpstreamHostId($service);

        $evaluation = $this->policy->evaluate($service, [
            'upstream_host_id' => $hostId,
            'upstream_available' => true,
            'upstream_contains' => true,
        ]);

        $refundType = (string) $evaluation['refund_type'];
        $needsUpstream = in_array($refundType, [
            AutoRefundProductRule::TYPE_BRIDGE,
            AutoRefundProductRule::TYPE_API_TICKET,
        ], true);

        // 这两条通道都要带上游 hostid（bridge 提交、工单 host_id 字段）
        if ($needsUpstream && $hostId === '') {
            throw new BusinessException('该服务未绑定上游实例，无法自助退款', 42200);
        }

        if (! $evaluation['refundable']) {
            throw new BusinessException($this->blockedMessage($evaluation['blocked_reasons']), 42200);
        }

        // 通道就绪检查放在落库之前：配置没填齐时不该留一条注定失败的台账
        if ($refundType === AutoRefundProductRule::TYPE_BRIDGE) {
            $this->assertBridgeReady();
        }
        if ($refundType === AutoRefundProductRule::TYPE_API_TICKET) {
            $this->assertTicketReady($service, $hostId);
        }

        if ($refundType === AutoRefundProductRule::TYPE_BRIDGE && $this->settings->upstreamPrecheck()) {
            $precheck = $this->api->query();
            if ($precheck->isUnknown()) {
                throw new BusinessException('上游暂不可用，暂时无法提交退款申请，请稍后再试', 42200);
            }
            if (! $precheck->isOk()) {
                throw new BusinessException(
                    '上游拒绝了预检请求：'.($precheck->upstreamMessage !== '' ? $precheck->upstreamMessage : 'code '.$precheck->code),
                    42200,
                );
            }
            if (! in_array($hostId, $this->hostIdsOf($precheck), true)) {
                throw new BusinessException('上游列表中找不到这台机器，请联系客服处理', 42200);
            }
        }

        $invoice = $evaluation['invoice'] instanceof Invoice ? $evaluation['invoice'] : null;
        $rule = $this->policy->ruleFor($service);

        $application = $this->createApplication(
            $user,
            $service,
            $hostId,
            $reason,
            $evaluation,
            $rule,
            $invoice,
        );

        $application = match ($refundType) {
            AutoRefundProductRule::TYPE_MANUAL => $this->queueForManualReview($application),
            AutoRefundProductRule::TYPE_AUTO => $this->settleImmediately($application, '自动退款'),
            AutoRefundProductRule::TYPE_BRIDGE => $this->submitToBridge($application, $hostId),
            AutoRefundProductRule::TYPE_API_TICKET => $this->submitToTicketChannel($application, $service),
            default => $this->queueForManualReview($application),
        };

        return $this->presentApplication($application->fresh() ?? $application);
    }

    /**
     * 用户取消：只有 `pending_review` 可取消。
     *
     * `manual` / `auto` 与 `api_ticket` 三类在本地没有可取消的上游申请：
     * 前者没有外发，后者取消得去工单里说。只有 `bridge` 通道真的调上游 `cancel`。
     *
     * @return array<string, mixed>
     */
    public function cancel(User $user, int $applicationId): array
    {
        $application = AutoRefundApplication::query()
            ->where('id', $applicationId)
            ->where('user_id', (int) $user->id)
            ->first();

        if (! $application instanceof AutoRefundApplication) {
            throw new BusinessException('退款申请不存在', 40400, 404);
        }

        if (! $application->isCancellable()) {
            throw new BusinessException('当前状态不允许取消（只有待上游审核的申请可以取消）', 42200);
        }

        if ((string) $application->channel !== AutoRefundApplication::CHANNEL_BRIDGE) {
            throw new BusinessException('该申请没有可取消的上游记录，请在工单或联系客服处理', 42200);
        }

        $hostId = $this->applicationHostId($application);
        if ($hostId === '') {
            throw new BusinessException('该申请缺少上游产品标识，请联系客服处理', 42200);
        }

        $result = $this->api->cancel($hostId);

        if ($result->isOk()) {
            $application->forceFill([
                'status' => AutoRefundApplication::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'active_service_id' => null,
                'upstream_code' => $result->code,
                'upstream_msg' => $this->truncate($result->upstreamMessage),
                'trace_id' => $result->traceId ?? $application->trace_id,
                'response_meta' => $result->toMeta(),
            ])->save();

            $this->logger->record('cancel', 'cancelled', [
                'application_id' => (int) $application->id,
                'application_no' => (string) $application->application_no,
                'trace_id' => $result->traceId,
            ]);

            return $this->presentApplication($application->fresh() ?? $application);
        }

        // 上游明确拒绝或结果未知：状态不动，仍占闸门；把上游原文写回台账供人核对。
        $application->forceFill([
            'upstream_code' => $result->code,
            'upstream_msg' => $this->truncate($result->upstreamMessage !== '' ? $result->upstreamMessage : $result->message),
            'trace_id' => $result->traceId ?? $application->trace_id,
            'response_meta' => $result->toMeta(),
        ])->save();

        $this->logger->record('cancel', $result->outcome, [
            'application_id' => (int) $application->id,
            'application_no' => (string) $application->application_no,
            'code' => $result->code,
        ]);

        if ($result->isUnknown()) {
            throw new BusinessException('取消结果未知，请到「退款中心」刷新查看或联系客服确认', 42200);
        }

        throw new BusinessException(
            '上游未受理该取消请求：'.($result->upstreamMessage !== '' ? $result->upstreamMessage : 'code '.$result->code),
            42200,
        );
    }

    /**
     * 管理端结算：复用既有退款链路，金额与可退上限由账务服务判定。
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function settle(AdminUser $admin, int $applicationId, string $amount, string $remark = '', array $context = []): array
    {
        $settleAmount = round((float) $amount, 2);
        if ($settleAmount <= 0) {
            throw new BusinessException('结算金额必须大于 0', 42200);
        }

        return DB::transaction(function () use ($admin, $applicationId, $settleAmount, $remark, $context): array {
            $application = $this->lockedApplication($applicationId);

            $this->assertSettleable($application);

            $user = $application->user ?? User::query()->find((int) $application->user_id);
            if (! $user instanceof User) {
                throw new BusinessException('申请关联的用户不存在，无法结算', 42200);
            }

            $settleRemark = $this->buildSettleRemark($application, $remark);

            // 既有链路：UserService::refundService → PaymentService::refundOrder
            // 它会锁账单、校验可退上限、写退款单/退款账单/余额流水与通知。
            // 这里绝不自己加减余额，也不吞它的 BusinessException——账务失败必须原样回报。
            $result = $this->userService->refundService($user, (int) $application->service_id, [
                'refund_method' => 'balance',
                'amount' => $settleAmount,
                'remark' => $settleRemark,
            ], $context + [
                'operator_id' => (int) $admin->id,
                'operator_name' => $this->adminName($admin),
                'trace_id' => (string) $application->application_no,
            ]);

            $application->forceFill([
                'status' => AutoRefundApplication::STATUS_SETTLED,
                'settled_amount' => number_format($settleAmount, 2, '.', ''),
                'settled_at' => now(),
                'active_service_id' => null,
                'operator_id' => (int) $admin->id,
                'settle_remark' => $this->truncate($settleRemark),
                'response_meta' => $this->mergeMeta($application, [
                    'settle' => [
                        'operator_id' => (int) $admin->id,
                        'amount' => number_format($settleAmount, 2, '.', ''),
                        'already_refunded' => (bool) ($result['already_refunded'] ?? false),
                        'settled_at' => now()->format('Y-m-d H:i:s'),
                    ],
                ]),
            ])->save();

            $this->logger->record('settle', 'settled', [
                'application_id' => (int) $application->id,
                'application_no' => (string) $application->application_no,
                'service_id' => (int) $application->service_id,
                'amount' => number_format($settleAmount, 2, '.', ''),
                'operator_id' => (int) $admin->id,
            ]);

            return array_merge($this->presentApplication($application->fresh() ?? $application, true), [
                'refund' => $result['refund'] ?? [],
            ]);
        });
    }

    /**
     * 管理端驳回：上游驳回或人工判定不可退。
     *
     * @return array<string, mixed>
     */
    public function reject(AdminUser $admin, int $applicationId, string $remark): array
    {
        $application = DB::transaction(function () use ($admin, $applicationId, $remark): AutoRefundApplication {
            $application = $this->lockedApplication($applicationId);

            if (! $application->isPendingReview()) {
                throw new BusinessException('只有「待上游审核」的申请可以驳回', 42200);
            }

            $application->forceFill([
                'status' => AutoRefundApplication::STATUS_REJECTED,
                'active_service_id' => null,
                'operator_id' => (int) $admin->id,
                'settle_remark' => $this->truncate($remark),
            ])->save();

            return $application;
        });

        $this->logger->record('reject', 'rejected', [
            'application_id' => (int) $application->id,
            'application_no' => (string) $application->application_no,
            'operator_id' => (int) $admin->id,
        ]);

        return $this->presentApplication($application->fresh() ?? $application, true);
    }

    /**
     * 管理端作废：卡死（典型是「结果未知」）时的唯一人工收口出口。
     *
     * @return array<string, mixed>
     */
    public function close(AdminUser $admin, int $applicationId, string $remark): array
    {
        $application = DB::transaction(function () use ($admin, $applicationId, $remark): AutoRefundApplication {
            $application = $this->lockedApplication($applicationId);

            if (! in_array((string) $application->status, [
                AutoRefundApplication::STATUS_PENDING_REVIEW,
                AutoRefundApplication::STATUS_UNCERTAIN,
                AutoRefundApplication::STATUS_CREATED,
                AutoRefundApplication::STATUS_FAILED,
            ], true)) {
                throw new BusinessException('只有「待上游审核 / 结果未知 / 已创建 / 提交失败」的申请可以作废', 42200);
            }

            $application->forceFill([
                'status' => AutoRefundApplication::STATUS_CLOSED,
                'active_service_id' => null,
                'operator_id' => (int) $admin->id,
                'settle_remark' => $this->truncate($remark),
            ])->save();

            return $application;
        });

        $this->logger->record('close', 'closed', [
            'application_id' => (int) $application->id,
            'application_no' => (string) $application->application_no,
            'operator_id' => (int) $admin->id,
        ]);

        return $this->presentApplication($application->fresh() ?? $application, true);
    }

    /**
     * 客户端台账：只返回本人数据，状态/分页可筛。
     *
     * @param  array<string, mixed>  $filters
     */
    public function listForUser(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = AutoRefundApplication::query()
            ->where('user_id', (int) $user->id)
            ->with(['service:id,name,domain', 'ticket.upstreamBinding'])
            ->orderByDesc('id');

        $this->applyStatusFilter($query, $filters);

        return $query->paginate(perPage: $perPage, page: max(1, (int) ($filters['page'] ?? 1)));
    }

    /**
     * 管理端台账。
     *
     * @param  array<string, mixed>  $filters
     */
    public function listForAdmin(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = AutoRefundApplication::query()
            ->with([
                'user:id,email,nickname',
                'service:id,name,domain,user_id',
                'operator:id,username,nickname',
                'ticket.upstreamBinding',
            ])
            ->orderByDesc('id');

        $this->applyStatusFilter($query, $filters);

        if (! empty($filters['refund_type'])) {
            $query->where('refund_type', (string) $filters['refund_type']);
        }
        if (! empty($filters['channel'])) {
            $query->where('channel', (string) $filters['channel']);
        }
        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }
        if (! empty($filters['service_id'])) {
            $query->where('service_id', (int) $filters['service_id']);
        }
        if (! empty($filters['application_no'])) {
            $query->where('application_no', 'like', '%'.trim((string) $filters['application_no']).'%');
        }
        if (! empty($filters['keyword'])) {
            $keyword = trim((string) $filters['keyword']);
            $query->where(function ($nested) use ($keyword): void {
                $nested->where('application_no', 'like', "%{$keyword}%")
                    ->orWhere('upstream_product_id', 'like', "%{$keyword}%")
                    ->orWhere('upstream_ticket_id', 'like', "%{$keyword}%")
                    ->orWhere('trace_id', 'like', "%{$keyword}%")
                    ->orWhere('reason', 'like', "%{$keyword}%");
            });
        }
        if (! empty($filters['start_date'])) {
            // 用范围比较而不是 whereDate()：函数包裹会让 created_at 索引失效
            $query->where('created_at', '>=', (string) $filters['start_date'].' 00:00:00');
        }
        if (! empty($filters['end_date'])) {
            $query->where('created_at', '<=', (string) $filters['end_date'].' 23:59:59');
        }

        return $query->paginate(perPage: $perPage, page: max(1, (int) ($filters['page'] ?? 1)));
    }

    public function findForAdmin(int $applicationId): AutoRefundApplication
    {
        $application = AutoRefundApplication::query()
            ->with([
                // users 表没有 balance 列（余额在 user_accounts.cash_balance），别在这里点它
                'user:id,email,nickname',
                'service',
                'order',
                'invoice',
                'operator:id,username,nickname',
                'ticket.upstreamBinding',
            ])
            ->find($applicationId);

        if (! $application instanceof AutoRefundApplication) {
            throw new BusinessException('退款申请不存在', 40400, 404);
        }

        return $application;
    }

    /**
     * 上游连通性自检：调 `query`，回报机器数与服务端时间差。
     *
     * @return array<string, mixed>
     */
    public function testUpstream(): array
    {
        $result = $this->api->query();
        $skew = $this->upstreamSkewSeconds($result);

        return [
            'ok' => $result->isOk(),
            'outcome' => $result->outcome,
            'code' => $result->code,
            'message' => $result->message,
            'upstream_msg' => $result->upstreamMessage,
            'host_count' => count($result->hosts()),
            'http_status' => $result->httpStatus,
            'trace_id' => $result->traceId,
            'upstream_server_time' => $result->upstreamServerTime,
            'local_time' => now()->toIso8601String(),
            'skew_seconds' => $skew,
            'endpoint' => $this->settings->maskedHost(),
            'configured' => $this->settings->bridgeReady(),
        ];
    }

    /**
     * 上游全量机器 + 本地绑定情况（对账）。
     *
     * @return array{items: array<int, array<string, mixed>>, host_count: int, bound_count: int, message: string}
     */
    public function upstreamProducts(): array
    {
        $result = $this->api->query();

        if (! $result->isOk()) {
            throw new BusinessException(
                '上游查询失败：'.($result->upstreamMessage !== '' ? $result->upstreamMessage : $result->message),
                42200,
            );
        }

        $hosts = $result->hosts();
        $boundMap = $this->localServiceMapForHostIds($this->hostIdsOf($result));

        $items = [];
        $boundCount = 0;

        foreach ($hosts as $host) {
            $hostId = $this->hostIdOf($host);
            $service = $hostId === '' ? null : ($boundMap[$hostId] ?? null);

            if ($service instanceof Service) {
                $boundCount++;
            }

            $items[] = [
                'hostid' => $hostId,
                'domain' => (string) ($host['domain'] ?? $host['name'] ?? ''),
                'product_name' => (string) ($host['product'] ?? $host['productname'] ?? $host['product_name'] ?? ''),
                'status' => (string) ($host['domainstatus'] ?? $host['status'] ?? ''),
                'nextduedate' => (string) ($host['nextduedate'] ?? ''),
                'bound' => $service instanceof Service,
                'service_id' => $service instanceof Service ? (int) $service->id : null,
                'service_name' => $service instanceof Service ? (string) $service->name : '',
                'user_id' => $service instanceof Service ? (int) $service->user_id : null,
            ];
        }

        return [
            'items' => $items,
            'host_count' => count($items),
            'bound_count' => $boundCount,
            'message' => $result->upstreamMessage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentApplication(AutoRefundApplication $application, bool $admin = false): array
    {
        $row = [
            'id' => (int) $application->id,
            'application_no' => (string) $application->application_no,
            'status' => (string) $application->status,
            'status_label' => $application->statusLabel(),
            'refund_type' => (string) ($application->refund_type ?: AutoRefundProductRule::TYPE_MANUAL),
            'refund_type_label' => $application->refundTypeLabel(),
            'channel' => (string) ($application->channel ?: AutoRefundApplication::CHANNEL_LOCAL),
            'channel_label' => $application->channelLabel(),
            'ticket_id' => $application->ticket_id === null ? null : (int) $application->ticket_id,
            'upstream_ticket_id' => $this->upstreamTicketIdOf($application),
            'service_id' => (int) $application->service_id,
            'service_name' => (string) ($application->service?->name ?? ''),
            'domain' => (string) ($application->service?->domain ?? ''),
            'upstream_product_id' => (string) $application->upstream_product_id,
            'reason' => (string) $application->reason,
            'estimated_amount' => number_format((float) $application->estimated_amount, 2, '.', ''),
            'settled_amount' => $application->settled_amount === null
                ? null
                : number_format((float) $application->settled_amount, 2, '.', ''),
            'upstream_code' => $application->upstream_code === null ? null : (int) $application->upstream_code,
            'upstream_msg' => (string) ($application->upstream_msg ?? ''),
            'trace_id' => $application->trace_id,
            'policy_snapshot' => is_array($application->policy_snapshot) ? $application->policy_snapshot : [],
            'cancellable' => $application->isCancellable()
                && (string) $application->channel === AutoRefundApplication::CHANNEL_BRIDGE,
            'settle_remark' => $admin ? (string) ($application->settle_remark ?? '') : null,
            'submitted_at' => $application->submitted_at?->format('Y-m-d H:i:s'),
            'cancelled_at' => $application->cancelled_at?->format('Y-m-d H:i:s'),
            'settled_at' => $application->settled_at?->format('Y-m-d H:i:s'),
            'created_at' => $application->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $application->updated_at?->format('Y-m-d H:i:s'),
        ];

        if (! $admin) {
            return $row;
        }

        return array_merge($row, [
            'user_id' => (int) $application->user_id,
            'user_email' => (string) ($application->user?->email ?? ''),
            'user_nickname' => (string) ($application->user?->nickname ?? ''),
            'order_id' => $application->order_id === null ? null : (int) $application->order_id,
            'invoice_id' => $application->invoice_id === null ? null : (int) $application->invoice_id,
            'invoice_no' => (string) ($application->invoice?->invoice_no ?? ''),
            'operator_id' => $application->operator_id === null ? null : (int) $application->operator_id,
            'operator_name' => $this->adminName($application->operator),
            // 管理端不做脱敏：上游原始摘要整段给出，排错要看到真实内容
            'response_meta' => $application->response_meta ?? [],
        ]);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function blockedReasonLabels(): array
    {
        $labels = [];
        foreach (AutoRefundPolicy::REASON_LABELS as $value => $label) {
            $labels[] = ['value' => (string) $value, 'label' => $label];
        }

        return $labels;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function channelOptions(): array
    {
        $options = [];
        foreach (AutoRefundApplication::CHANNEL_LABELS as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => $label];
        }

        return $options;
    }

    private function assertOpen(): void
    {
        if (! $this->settings->pluginEnabled()) {
            throw new BusinessException('自助退款插件未启用', 42200);
        }
        if (! $this->tableReady()) {
            throw new BusinessException('自助退款数据表尚未初始化，请联系管理员', 42200);
        }
        if (! $this->productRuleTableReady()) {
            throw new BusinessException('商品退款策略表尚未初始化，请联系管理员', 42200);
        }
        if (! $this->settings->enabled()) {
            throw new BusinessException('自助退款未开放', 42200);
        }
    }

    private function assertBridgeReady(): void
    {
        if (! $this->settings->bridgeReady()) {
            throw new BusinessException('插件间对接通道尚未配置上游接口地址或 API KEY，请联系管理员', 42200);
        }
    }

    /**
     * 工单通道就绪检查。缺哪一项直接说清楚，绝不带着空密钥去发单。
     */
    private function assertTicketReady(Service $service, string $hostId): void
    {
        if (! $this->settings->ticketChannelReady()) {
            throw new BusinessException(
                '工单退款通道未就绪：缺少 '.implode('、', $this->settings->ticketChannelMissing()).'，请联系管理员',
                42200,
            );
        }

        if ($this->settings->ticketProtocol() === AutoRefundSettings::TICKET_PROTOCOL_TURA) {
            $supplierId = $this->bindings->supplierIdForService($service);
            $upstreamServiceId = $this->bindings->upstreamServiceIdForService($service) ?? $hostId;

            if ($supplierId === null) {
                throw new BusinessException('该服务未配置上游供应商，无法向上游开工单退款', 42200);
            }
            if ($upstreamServiceId === '') {
                throw new BusinessException('该服务未配置上游实例，无法向上游开工单退款', 42200);
            }
        }
    }

    /**
     * 落库里表行并占住幂等闸门。唯一索引冲突 = 并发/重复提交，转成可读的业务错误。
     *
     * @param  array<string, mixed>  $evaluation
     */
    private function createApplication(
        User $user,
        Service $service,
        string $hostId,
        string $reason,
        array $evaluation,
        ?AutoRefundProductRule $rule,
        ?Invoice $invoice,
    ): AutoRefundApplication {
        $order = $service->order instanceof Order ? $service->order : null;

        try {
            return AutoRefundApplication::query()->create([
                'application_no' => $this->generateApplicationNo(),
                'user_id' => (int) $user->id,
                'service_id' => (int) $service->id,
                'order_id' => $order instanceof Order ? (int) $order->id : null,
                'invoice_id' => $invoice instanceof Invoice ? (int) $invoice->id : null,
                'upstream_product_id' => $hostId,
                'status' => AutoRefundApplication::STATUS_CREATED,
                'refund_type' => (string) $evaluation['refund_type'],
                'channel' => $rule?->channel() ?? AutoRefundApplication::CHANNEL_LOCAL,
                // 提交时的策略快照：事后要能解释「当时为什么能退、金额怎么算的」
                'policy_snapshot' => (array) ($evaluation['policy_snapshot'] ?? []),
                // 幂等闸门：先占住再调上游，超时也不释放
                'active_service_id' => (int) $service->id,
                'reason' => $this->truncate($reason, 500),
                'estimated_amount' => (string) $evaluation['estimated_amount'],
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new BusinessException('该服务已有进行中的退款申请，请勿重复提交', 42200);
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                throw new BusinessException('该服务已有进行中的退款申请，请勿重复提交', 42200);
            }

            throw $exception;
        }
    }

    /**
     * `manual`：只落台账等人工审核，不外发。
     */
    private function queueForManualReview(AutoRefundApplication $application): AutoRefundApplication
    {
        $application->forceFill([
            'status' => AutoRefundApplication::STATUS_PENDING_REVIEW,
            'submitted_at' => now(),
        ])->save();

        $this->logger->record('submit', 'manual_queued', [
            'application_id' => (int) $application->id,
            'application_no' => (string) $application->application_no,
            'refund_type' => AutoRefundProductRule::TYPE_MANUAL,
        ]);

        return $application;
    }

    /**
     * `bridge`：调上游 `api.php` 的 submit（**不传 amount**——商业版契约不允许调用方指定金额）。
     */
    private function submitToBridge(AutoRefundApplication $application, string $hostId): AutoRefundApplication
    {
        $result = $this->api->submit($hostId);

        return $this->applySubmitResult($application, $result);
    }

    /**
     * `api_ticket`：建本站工单承载沟通记录，再按 `ticket_protocol` 投递上游。
     *
     * 顺序很重要：`zjfm` 走「先上游、后本地」——上游没受理就不该留一张本地产单；
     * `tura` 只能「先本地、后投递」，因为投递链路本身就是围绕本站工单跑的，
     * 于是整段包在事务里，投递前置条件不满足时把工单一起回滚掉。
     */
    private function submitToTicketChannel(AutoRefundApplication $application, Service $service): AutoRefundApplication
    {
        $title = $this->renderTemplate($this->settings->ticketTitleTemplate(), $application, $service);
        $content = $this->renderTemplate($this->settings->ticketContentTemplate(), $application, $service);

        if ($this->settings->ticketProtocol() === AutoRefundSettings::TICKET_PROTOCOL_TURA) {
            return $this->submitToTuraTicket($application, $service, $title, $content);
        }

        return $this->submitToZjfmTicket($application, $service, $title, $content);
    }

    /**
     * `zjfm` 协议：`login_api` 换 JWT → `submitticket` 提交，成功判据是 302 且 Location 含
     * `supporttickets`（或变体 200 + `code = 200`）。上游不回工单号，`upstream_ticket_id` 留空。
     */
    private function submitToZjfmTicket(
        AutoRefundApplication $application,
        Service $service,
        string $title,
        string $content,
    ): AutoRefundApplication {
        $result = $this->ticketApi->submit($title, $content, (string) $application->upstream_product_id);

        if (! $result['ok']) {
            $application->forceFill([
                'status' => AutoRefundApplication::STATUS_FAILED,
                'active_service_id' => null,
                'upstream_msg' => $this->truncate($result['message']),
                'response_meta' => $this->mergeMeta($application, [
                    'ticket_channel' => [
                        'protocol' => AutoRefundSettings::TICKET_PROTOCOL_ZJFM,
                        'http_status' => $result['http_status'],
                        'location' => $result['location'],
                        'message' => $result['message'],
                    ],
                ]),
            ])->save();

            $this->logger->record('submit', 'ticket_failed', [
                'application_id' => (int) $application->id,
                'application_no' => (string) $application->application_no,
                'http_status' => $result['http_status'],
            ]);

            return $application;
        }

        $ticket = $this->createLocalTicket((int) $application->user_id, (int) $application->service_id, $title, $content);

        $application->forceFill([
            'status' => AutoRefundApplication::STATUS_PENDING_REVIEW,
            'submitted_at' => now(),
            'ticket_id' => (int) $ticket->id,
            'response_meta' => $this->mergeMeta($application, [
                'ticket_channel' => [
                    'protocol' => AutoRefundSettings::TICKET_PROTOCOL_ZJFM,
                    'http_status' => $result['http_status'],
                    'location' => $result['location'],
                    // 302 只给列表页，拿不到上游工单号：这件事要在详情里说清楚，不是缺陷
                    'upstream_ticket_id' => null,
                    'note' => '上游未返回工单号',
                ],
            ]),
        ])->save();

        $this->logger->record('submit', 'ticket_queued', [
            'application_id' => (int) $application->id,
            'application_no' => (string) $application->application_no,
            'ticket_id' => (int) $ticket->id,
        ]);

        return $application;
    }

    /**
     * `tura` 协议：建本站工单后投递到上游 TuraIDC 的 `/ticket/create`。
     *
     * 部门优先取配置；没配时回退既有 `ticket_delivery_rules` 匹配（`queueTicket`），
     * 两条都没有就整体回滚并报「未配置上游工单部门」——绝不猜部门发单。
     */
    private function submitToTuraTicket(
        AutoRefundApplication $application,
        Service $service,
        string $title,
        string $content,
    ): AutoRefundApplication {
        $supplierId = (int) $this->bindings->supplierIdForService($service);
        $upstreamServiceId = (string) ($this->bindings->upstreamServiceIdForService($service)
            ?? $application->upstream_product_id);
        $departmentId = $this->settings->ticketDepartmentId();

        $ticketId = 0;

        try {
            $ticket = DB::transaction(function () use ($application, $title, $content, $supplierId, $upstreamServiceId, $departmentId, &$ticketId): Ticket {
                $ticket = $this->createLocalTicket((int) $application->user_id, (int) $application->service_id, $title, $content);
                $ticketId = (int) $ticket->id;

                if ($departmentId !== '') {
                    $this->ticketDelivery->queueRefundTicket($ticket, $supplierId, $departmentId, $upstreamServiceId);

                    return $ticket;
                }

                if (! $this->ticketDelivery->matchesDeliveryRule($ticket)) {
                    throw new BusinessException('未配置上游工单部门，无法向上游提交退款工单，请联系管理员', 42200);
                }

                $this->ticketDelivery->queueTicket($ticket);

                return $ticket;
            });
        } catch (Throwable $exception) {
            // 同步队列（QUEUE_CONNECTION=sync）下投递失败会在提交后抛回来：
            // 这时本地工单已经落库，台账不能记成「提交失败」把闸门放掉——留在待审核，
            // 由管理员按投递日志决定重试还是作废。
            $ticketPersisted = $ticketId > 0 && Ticket::query()->whereKey($ticketId)->exists();

            $application->forceFill([
                'status' => $ticketPersisted
                    ? AutoRefundApplication::STATUS_PENDING_REVIEW
                    : AutoRefundApplication::STATUS_FAILED,
                'submitted_at' => $ticketPersisted ? now() : null,
                'active_service_id' => $ticketPersisted ? (int) $application->service_id : null,
                'ticket_id' => $ticketPersisted ? $ticketId : null,
                'upstream_msg' => $this->truncate($exception->getMessage()),
                'response_meta' => $this->mergeMeta($application, [
                    'ticket_channel' => [
                        'protocol' => AutoRefundSettings::TICKET_PROTOCOL_TURA,
                        'message' => $exception->getMessage(),
                        'local_ticket_persisted' => $ticketPersisted,
                    ],
                ]),
            ])->save();

            $this->logger->record('submit', 'ticket_failed', [
                'application_id' => (int) $application->id,
                'application_no' => (string) $application->application_no,
                'local_ticket_persisted' => $ticketPersisted,
                'error' => $exception->getMessage(),
            ]);

            return $application;
        }

        $upstreamTicketId = $this->upstreamTicketIdOfTicket($ticket);

        $application->forceFill([
            'status' => AutoRefundApplication::STATUS_PENDING_REVIEW,
            'submitted_at' => now(),
            'ticket_id' => (int) $ticket->id,
            'upstream_ticket_id' => $upstreamTicketId,
            'response_meta' => $this->mergeMeta($application, [
                'ticket_channel' => [
                    'protocol' => AutoRefundSettings::TICKET_PROTOCOL_TURA,
                    'supplier_id' => $supplierId,
                    'department_id' => $departmentId !== '' ? $departmentId : null,
                    'upstream_ticket_id' => $upstreamTicketId,
                ],
            ]),
        ])->save();

        $this->logger->record('submit', 'ticket_queued', [
            'application_id' => (int) $application->id,
            'application_no' => (string) $application->application_no,
            'ticket_id' => (int) $ticket->id,
            'upstream_ticket_id' => $upstreamTicketId,
        ]);

        return $application;
    }

    /**
     * 建一张本站工单（财务部门）+ 首条客户回复。
     *
     * 为什么不用 `TicketService::create()`：那条链路会给全体有工单权限的管理员发信，
     * 并立刻走 `ticket_delivery_rules` 投递上游——对 `zjfm` 协议是重复发单，
     * 对 `tura` 协议则会让本插件的部门选择失效。这里只写两张表的必要行。
     */
    private function createLocalTicket(int $userId, int $serviceId, string $title, string $content): Ticket
    {
        return DB::transaction(function () use ($userId, $serviceId, $title, $content): Ticket {
            $ticket = Ticket::query()->create([
                'user_id' => $userId,
                'department' => self::TICKET_DEPARTMENT,
                'subject' => $this->truncate($title, 200),
                'priority' => 2,
                'status' => 0,
                'service_id' => $serviceId,
            ]);

            TicketReply::query()->create([
                'ticket_id' => (int) $ticket->id,
                'user_id' => $userId,
                'content' => $content,
                'is_staff' => 0,
                'sender_type' => 'client',
                'sender_name' => null,
                'attachments' => [],
                'created_at' => now(),
            ]);

            return $ticket;
        });
    }

    /**
     * `auto`：落台账后立刻走既有退款链路入账，直接 `settled`。
     *
     * 账务失败不回滚台账：状态留在 `pending_review` 等人工处理，失败原因写进
     * `response_meta.auto_settle_error`——用户至少还能在台账里看到这一笔。
     */
    private function settleImmediately(AutoRefundApplication $application, string $remarkPrefix): AutoRefundApplication
    {
        $amount = round((float) $application->estimated_amount, 2);

        try {
            $user = $application->user ?? User::query()->find((int) $application->user_id);
            if (! $user instanceof User) {
                throw new BusinessException('申请关联的用户不存在');
            }

            $this->userService->refundService($user, (int) $application->service_id, [
                'refund_method' => 'balance',
                'amount' => $amount,
                'remark' => $this->buildSettleRemark($application, $remarkPrefix),
            ], [
                'operator_id' => 0,
                'operator_name' => 'auto_refund',
                'trace_id' => (string) $application->application_no,
            ]);

            $application->forceFill([
                'status' => AutoRefundApplication::STATUS_SETTLED,
                'submitted_at' => now(),
                'settled_amount' => number_format($amount, 2, '.', ''),
                'settled_at' => now(),
                'active_service_id' => null,
                'settle_remark' => $this->truncate($remarkPrefix.' '.number_format($amount, 2, '.', '')),
                'response_meta' => $this->mergeMeta($application, [
                    'auto_settle' => 'settled',
                    'settled_amount' => number_format($amount, 2, '.', ''),
                ]),
            ])->save();

            $this->logger->record('settle', 'auto_settled', [
                'application_id' => (int) $application->id,
                'application_no' => (string) $application->application_no,
                'amount' => number_format($amount, 2, '.', ''),
            ]);
        } catch (Throwable $exception) {
            $application->forceFill([
                'status' => AutoRefundApplication::STATUS_PENDING_REVIEW,
                'submitted_at' => now(),
                'response_meta' => $this->mergeMeta($application, [
                    'auto_settle' => 'failed',
                    'auto_settle_error' => mb_substr($exception->getMessage(), 0, 255),
                ]),
            ])->save();

            $this->logger->record('settle', 'auto_settle_failed', [
                'application_id' => (int) $application->id,
                'application_no' => (string) $application->application_no,
                'error' => $exception->getMessage(),
            ]);
        }

        return $application;
    }

    /**
     * 按 submit 结果推进状态机：200→待审核，非 200→失败并释放闸门，未知→结果未知并保留闸门。
     */
    private function applySubmitResult(AutoRefundApplication $application, AutoRefundApiResult $result): AutoRefundApplication
    {
        $payload = [
            'upstream_code' => $result->code,
            'upstream_msg' => $this->truncate($result->upstreamMessage !== '' ? $result->upstreamMessage : $result->message),
            'trace_id' => $result->traceId,
            'response_meta' => $result->toMeta(),
        ];

        if ($result->isOk()) {
            $payload['status'] = AutoRefundApplication::STATUS_PENDING_REVIEW;
            $payload['submitted_at'] = now();
        } elseif ($result->isUnknown()) {
            // 结果未知：保留 active_service_id，否则用户重提会造出第二笔上游申请
            $payload['status'] = AutoRefundApplication::STATUS_UNCERTAIN;
        } else {
            $payload['status'] = AutoRefundApplication::STATUS_FAILED;
            $payload['active_service_id'] = null;
        }

        $application->forceFill($payload)->save();

        $this->logger->record('submit', $result->outcome, [
            'application_id' => (int) $application->id,
            'application_no' => (string) $application->application_no,
            'service_id' => (int) $application->service_id,
            'upstream_product_id' => (string) $application->upstream_product_id,
            'code' => $result->code,
            'trace_id' => $result->traceId,
        ]);

        return $application;
    }

    /**
     * 结算前置校验：只有待上游审核可结算，且不应重复结算。
     */
    private function assertSettleable(AutoRefundApplication $application): void
    {
        if ((string) $application->status === AutoRefundApplication::STATUS_SETTLED) {
            throw new BusinessException('该申请已经结算完成，不要重复结算', 42200);
        }

        if (! $application->isPendingReview()) {
            throw new BusinessException('只有「待上游审核」的申请可以结算', 42200);
        }
    }

    /**
     * 在事务内锁行读取申请（并发结算/驳回/作废必须串行）。
     */
    private function lockedApplication(int $applicationId): AutoRefundApplication
    {
        $application = AutoRefundApplication::query()->lockForUpdate()->find($applicationId);

        if (! $application instanceof AutoRefundApplication) {
            throw new BusinessException('退款申请不存在', 40400, 404);
        }

        return $application;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyStatusFilter(Builder $query, array $filters): void
    {
        $status = trim((string) ($filters['status'] ?? ''));
        if ($status === '') {
            return;
        }

        $statuses = array_values(array_filter(array_map('trim', explode(',', $status))));
        $allowed = array_values(array_filter(
            $statuses,
            static fn (string $item): bool => array_key_exists($item, AutoRefundApplication::STATUS_LABELS)
        ));

        if ($allowed !== []) {
            $query->whereIn('status', $allowed);
        }
    }

    /**
     * @param  array<int, string>  $blockedReasons
     */
    private function blockedMessage(array $blockedReasons): string
    {
        foreach ($blockedReasons as $reason) {
            if (isset(AutoRefundPolicy::REASON_LABELS[$reason])) {
                return AutoRefundPolicy::REASON_LABELS[$reason];
            }
        }

        return '当前不满足自助退款条件';
    }

    /**
     * 上游机器集合 + 是否可用。降级信息直接回给控制器，不抛异常。
     *
     * 只有 `bridge` 通道真的依赖上游 `query`：没配上游地址时不该让整页报错，
     * 返回「不可用」让 `manual` / `auto` 的机器照常可退。
     *
     * @return array{0: bool, 1: string|null, 2: array<int, string>}
     */
    private function loadUpstreamHostIds(): array
    {
        if (! $this->settings->bridgeReady()) {
            return [false, '尚未配置上游接口地址或 API KEY', []];
        }

        $result = $this->api->query();

        if ($result->isOk()) {
            return [true, null, $this->hostIdsOf($result)];
        }

        $reason = $result->isUnknown()
            ? '上游暂不可用，请稍后再试'
            : '上游拒绝查询：'.($result->upstreamMessage !== '' ? $result->upstreamMessage : 'code '.$result->code);

        return [false, $reason, []];
    }

    /**
     * @return array<int, string>
     */
    private function hostIdsOf(AutoRefundApiResult $result): array
    {
        $ids = [];
        foreach ($result->hosts() as $host) {
            $hostId = $this->hostIdOf($host);
            if ($hostId !== '') {
                $ids[] = $hostId;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, mixed>  $host
     */
    private function hostIdOf(array $host): string
    {
        foreach (['hostid', 'host_id', 'id'] as $key) {
            if (! array_key_exists($key, $host)) {
                continue;
            }

            $value = $host[$key];
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }

    /**
     * 本地服务对应的上游实例标识：优先绑定表，其次 provision_data（开通时写入）。
     */
    private function resolveUpstreamHostId(Service $service): string
    {
        $bindingId = trim((string) ($service->upstreamBinding?->upstream_service_id ?? ''));
        if ($bindingId !== '') {
            return $bindingId;
        }

        $provisionData = $service->provision_data;
        if (! is_array($provisionData)) {
            return '';
        }

        foreach (['upstream_host_id', 'host_id', 'server_id'] as $key) {
            $value = $provisionData[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        $list = $provisionData['upstream_host_ids'] ?? null;
        if (is_array($list)) {
            foreach ($list as $item) {
                if (is_scalar($item) && trim((string) $item) !== '') {
                    return trim((string) $item);
                }
            }
        }

        return '';
    }

    /**
     * 上游产品 ID → 本地服务。先查绑定表，再兜底扫最近的开通记录。
     *
     * @param  array<int, string>  $hostIds
     * @return array<string, Service>
     */
    private function localServiceMapForHostIds(array $hostIds): array
    {
        if ($hostIds === []) {
            return [];
        }

        $map = [];

        ServiceUpstreamBinding::query()
            ->whereIn('upstream_service_id', $hostIds)
            ->with('service')
            ->get()
            ->each(function (ServiceUpstreamBinding $binding) use (&$map): void {
                if ($binding->service instanceof Service) {
                    $map[(string) $binding->upstream_service_id] = $binding->service;
                }
            });

        if (count($map) >= count($hostIds)) {
            return $map;
        }

        Service::query()
            ->select(['id', 'user_id', 'name', 'status', 'provision_data'])
            ->whereNotNull('provision_data')
            ->orderByDesc('id')
            ->limit(self::RECONCILE_SCAN_LIMIT)
            ->get()
            ->each(function (Service $service) use (&$map): void {
                $hostId = $this->resolveUpstreamHostId($service);
                if ($hostId !== '' && ! isset($map[$hostId])) {
                    $map[$hostId] = $service;
                }
            });

        return $map;
    }

    private function applicationHostId(AutoRefundApplication $application): string
    {
        $stored = trim((string) $application->upstream_product_id);
        if ($stored !== '') {
            return $stored;
        }

        $service = $application->service;
        if ($service instanceof Service) {
            return $this->resolveUpstreamHostId($service);
        }

        return '';
    }

    /**
     * 台账上的上游工单号：列上有就用列，没有就回查本地上游绑定。
     *
     * `tura` 协议是异步投递的，提交那一刻上游工单还没建出来；管理员过一会儿点开详情，
     * 这时绑定上已经有号了——回查比「当时没记就永远没有」有用得多。
     */
    private function upstreamTicketIdOf(AutoRefundApplication $application): ?string
    {
        $stored = trim((string) ($application->upstream_ticket_id ?? ''));
        if ($stored !== '') {
            return $stored;
        }

        $ticket = $application->ticket;
        if (! $ticket instanceof Ticket) {
            return null;
        }

        return $this->upstreamTicketIdOfTicket($ticket);
    }

    private function upstreamTicketIdOfTicket(Ticket $ticket): ?string
    {
        $binding = $ticket->upstreamBinding ?? TicketUpstreamBinding::query()
            ->where('ticket_id', (int) $ticket->id)
            ->first();

        if (! $binding instanceof TicketUpstreamBinding) {
            return null;
        }

        $upstreamTicketId = trim((string) ($binding->upstream_ticket_id ?? ''));

        return $upstreamTicketId === '' ? null : $upstreamTicketId;
    }

    /**
     * @param  array<string, mixed>  $evaluation
     */
    private function presentCandidate(
        Service $service,
        string $hostId,
        array $evaluation,
        ?AutoRefundApplication $application,
    ): array {
        $provisionData = is_array($service->provision_data) ? $service->provision_data : [];
        $assignedIps = $provisionData['assigned_ips'] ?? [];

        return [
            'service_id' => (int) $service->id,
            'service_name' => (string) $service->name,
            'product_name' => $this->productName($service),
            'domain' => (string) ($service->domain ?? ''),
            'dedicatedip' => (string) ($provisionData['dedicated_ip'] ?? ''),
            'assignedips' => is_array($assignedIps) ? implode(',', array_map(strval(...), $assignedIps)) : (string) $assignedIps,
            'regdate' => $service->created_at?->format('Y-m-d H:i:s'),
            'nextduedate' => $service->expires_at?->format('Y-m-d H:i:s'),
            'upstream_product_id' => $hostId,
            'refund_type' => (string) $evaluation['refund_type'],
            'refund_type_label' => (string) $evaluation['refund_type_label'],
            'refund_rule' => (string) $evaluation['refund_rule'],
            'refund_rule_label' => (string) $evaluation['refund_rule_label'],
            'requirement' => (string) $evaluation['requirement'],
            'requirement_label' => (string) $evaluation['requirement_label'],
            'window_hours' => (int) $evaluation['window_hours'],
            'policy_configured' => (bool) $evaluation['policy_configured'],
            'estimated_amount' => (string) $evaluation['estimated_amount'],
            'estimate_hint' => (string) $evaluation['estimate_hint'],
            'refundable' => (bool) $evaluation['refundable'],
            'blocked_reasons' => $evaluation['blocked_reasons'],
            'used_days' => (int) round(((int) $evaluation['used_seconds']) / 86400),
            'application' => $application instanceof AutoRefundApplication
                ? $this->presentApplication($application)
                : null,
        ];
    }

    /**
     * 模板占位替换：`{product_name}` `{host_id}` `{amount}` `{reason}`。
     *
     * 只做字符串替换（模板由站长在插件配置里维护），不做表达式求值。
     */
    private function renderTemplate(string $template, AutoRefundApplication $application, Service $service): string
    {
        $replacements = [
            '{product_name}' => $this->productName($service),
            '{host_id}' => (string) $application->upstream_product_id,
            '{amount}' => number_format((float) $application->estimated_amount, 2, '.', ''),
            '{reason}' => (string) $application->reason,
        ];

        return strtr($template, $replacements);
    }

    private function generateApplicationNo(): string
    {
        // 形如 AR20261101A1B2：日期 + 4 位随机，便于工单引用；唯一索引兜住极小概率碰撞
        return 'AR'.now()->format('Ymd').strtoupper(Str::random(4));
    }

    /**
     * 商品显示名：与控制台其它页面同源（`ProductDisplayNameResolver`），
     * 不自己拼「无规格 #ID」那种第二套口径。
     */
    private function productName(Service $service): string
    {
        $product = $service->product;

        if (! $product instanceof Product) {
            return '';
        }

        try {
            $resolved = $this->displayNames->resolveForProduct($product);

            return (string) ($resolved['product_display_name'] ?? '');
        } catch (Throwable) {
            // 商品规格残缺时不能让候选列表整体 500，退回商品自定义名
            return trim((string) ($product->custom_display_name ?? ''));
        }
    }

    private function buildSettleRemark(AutoRefundApplication $application, string $remark): string
    {
        $base = trim($remark);
        if ($base === '') {
            $base = $this->settings->settleRemark();
        }

        return $this->truncate($base.' '.$application->application_no, 255);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function mergeMeta(AutoRefundApplication $application, array $extra): array
    {
        $meta = is_array($application->response_meta) ? $application->response_meta : [];

        return array_merge($meta, $extra);
    }

    private function adminName(?AdminUser $admin): string
    {
        if (! $admin instanceof AdminUser) {
            return '';
        }

        $nickname = trim((string) ($admin->nickname ?? ''));

        return $nickname !== '' ? $nickname : (string) ($admin->username ?? '');
    }

    /**
     * 上游 `Date` 头与本地时间的差（秒）。拿不到就返回 null，不猜。
     */
    private function upstreamSkewSeconds(AutoRefundApiResult $result): ?int
    {
        $serverTime = $result->upstreamServerTime;
        if ($serverTime === null || $serverTime === '') {
            return null;
        }

        try {
            $parsed = CarbonImmutable::parse($serverTime);
        } catch (Throwable) {
            return null;
        }

        return (int) round(now()->getTimestamp() - $parsed->getTimestamp());
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'Duplicate entry') || (string) $exception->getCode() === '23000';
    }

    private function truncate(string $value, int $limit = 255): string
    {
        return mb_substr($value, 0, $limit);
    }
}
