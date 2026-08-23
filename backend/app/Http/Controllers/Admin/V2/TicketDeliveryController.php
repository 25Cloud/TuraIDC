<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\Ticket\ListTicketUpstreamDeliveryLogsRequest;
use App\Http\Requests\Admin\V2\Ticket\SaveTicketUpstreamUploadGuardRequest;
use App\Http\Requests\Admin\V2\Ticket\UpsertTicketDeliveryRuleRequest;
use App\Http\Resources\Admin\V2\TicketDeliveryRuleResource;
use App\Http\Resources\Admin\V2\TicketUpstreamDeliveryLogResource;
use App\Models\ProductUpstreamBinding;
use App\Models\Supplier;
use App\Models\Ticket;
use App\Models\TicketDeliveryRule;
use App\Services\System\OperationLogService;
use App\Services\Ticket\TicketDeliveryService;
use App\Services\Upstream\ProviderKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TicketDeliveryController extends Controller
{
    public function __construct(
        private readonly OperationLogService $operationLogService,
    ) {}

    public function index(): JsonResponse
    {
        return $this->success([
            'list' => TicketDeliveryRuleResource::collection(
                TicketDeliveryRule::query()->with('products:id')->latest('id')->limit(200)->get()
            )->resolve(),
        ]);
    }

    public function upstreamDepartments(Request $request, TicketDeliveryService $deliveryService): JsonResponse
    {
        $supplierId = (int) $request->query('supplier_id', 0);
        if ($supplierId <= 0) {
            throw ValidationException::withMessages(['supplier_id' => '请选择供应商']);
        }

        return $this->success([
            'list' => $deliveryService->upstreamDepartments($supplierId),
        ]);
    }

    public function store(UpsertTicketDeliveryRuleRequest $request): JsonResponse
    {
        $data = $request->payload();
        $this->ensureRuleTarget($data);
        $rule = DB::transaction(function () use ($data): TicketDeliveryRule {
            $products = $data['product_ids'] ?? [];
            unset($data['product_ids']);
            $data['provider_key'] = ProviderKey::ZJMF_FINANCE_API;
            $rule = TicketDeliveryRule::create($data);
            $rule->products()->sync($products);

            return $rule->load('products:id');
        });

        return $this->success(TicketDeliveryRuleResource::make($rule)->resolve(), '工单传递规则已保存');
    }

    public function update(UpsertTicketDeliveryRuleRequest $request, TicketDeliveryRule $rule): JsonResponse
    {
        $data = $request->payload();
        $data['supplier_id'] = (int) $data['supplier_id'];
        $this->ensureRuleTarget($data, $rule);
        $products = $data['product_ids'] ?? [];
        unset($data['product_ids']);
        DB::transaction(function () use ($rule, $data, $products): void {
            $data['provider_key'] = ProviderKey::ZJMF_FINANCE_API;
            $rule->update($data);
            $rule->products()->sync($products);
        });

        return $this->success(TicketDeliveryRuleResource::make($rule->fresh()->load('products:id'))->resolve(), '工单传递规则已更新');
    }

    public function destroy(TicketDeliveryRule $rule): JsonResponse
    {
        $rule->delete();

        return $this->success(null, '工单传递规则已删除');
    }

    public function ticketStatus(Ticket $ticket, TicketDeliveryService $deliveryService): JsonResponse
    {
        return $this->success($deliveryService->ticketStatus($ticket));
    }

    public function registerCallback(Ticket $ticket, TicketDeliveryService $deliveryService): JsonResponse
    {
        $deliveryService->registerTicketCallback($ticket);

        return $this->success(null, '上游工单回调已重新注册');
    }

    public function ticketLogs(
        ListTicketUpstreamDeliveryLogsRequest $request,
        Ticket $ticket,
        TicketDeliveryService $deliveryService
    ): JsonResponse {
        return $this->paginate(
            $deliveryService->deliveryLogs($ticket, (int) ($request->validated('page_size') ?? 20)),
            TicketUpstreamDeliveryLogResource::class
        );
    }

    public function uploadGuardConfig(): JsonResponse
    {
        return $this->success([
            'upload_image_enabled' => $this->uploadImageEnabled(),
            'allowed_ips' => (string) \App\Models\Setting::getValue(
                'ticket_upstream',
                'allowed_ips',
                (string) config('ticket_upstream.upload_allowed_ips', '')
            ),
            'rate_limit' => (int) \App\Models\Setting::getValue(
                'ticket_upstream',
                'rate_limit',
                (string) config('ticket_upstream.upload_rate_limit', 30)
            ),
            // 布尔值用格式无关解析（filter_var + FILTER_VALIDATE_BOOLEAN），
            // 避免 (bool) 强转把字符串 'false' 判为 true 造成语义反转。
            'block_non_whitelisted' => $this->blockNonWhitelisted(),
            'unused_retention_minutes' => (int) config('ticket_upstream.upload_unused_retention_minutes', 5),
        ]);
    }

    public function saveUploadGuardConfig(SaveTicketUpstreamUploadGuardRequest $request): JsonResponse
    {
        $payload = $request->payload();
        $validated = $request->validated();

        // 存在工单传递规则时不允许显式关闭 /upload_image：规则创建前置条件要求接口启用，
        // 反向关闭会造成既有规则指向已停用的上传通道，附件将无法回传。
        // 仅拦截「本次请求显式提交关闭」，部分更新（不带该字段）保留已保存值，不误伤。
        if (
            array_key_exists('upload_image_enabled', $validated)
            && ! $payload['upload_image_enabled']
            && TicketDeliveryRule::query()->exists()
        ) {
            throw ValidationException::withMessages([
                'upload_image_enabled' => '存在工单传递规则时不能关闭 /upload_image 接口',
            ]);
        }

        // 先读取变更前的 ticket_upstream 配置值，供审计做前后对照。
        $before = [
            'allowed_ips' => (string) \App\Models\Setting::getValue(
                'ticket_upstream',
                'allowed_ips',
                (string) config('ticket_upstream.upload_allowed_ips', '')
            ),
            'rate_limit' => (int) \App\Models\Setting::getValue(
                'ticket_upstream',
                'rate_limit',
                (string) config('ticket_upstream.upload_rate_limit', 30)
            ),
            'upload_image_enabled' => $this->uploadImageEnabled(),
            'block_non_whitelisted' => $this->blockNonWhitelisted(),
        ];

        \App\Models\Setting::setValues('ticket_upstream', $payload);

        // 配置写入成功后记录管理员操作审计，便于追踪上传防护白名单/限流的变更。
        $this->operationLogService->write(
            userId: (int) ($request->user()?->id ?? 0),
            userType: 'admin',
            action: 'ticket.upload_guard.update',
            module: 'ticket',
            targetId: 0,
            detail: [
                'title' => '工单传递上传防护配置更新',
                'before' => $before,
                'after' => $payload,
                'operator_name' => (string) ($request->user()?->username ?? $request->user()?->name ?? ''),
                'trace_id' => (string) $request->header('X-Request-Id', ''),
            ],
            ipAddress: (string) $request->ip(),
        );

        return $this->success($payload, '上传防护配置已保存');
    }

    private function uploadImageEnabled(): bool
    {
        $value = \App\Models\Setting::getValue(
            'ticket_upstream',
            'upload_image_enabled',
            config('ticket_upstream.upload_image_enabled', false)
        );

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    private function blockNonWhitelisted(): bool
    {
        $value = \App\Models\Setting::getValue(
            'ticket_upstream',
            'block_non_whitelisted',
            config('ticket_upstream.upload_block_non_whitelisted', true)
        );

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    }

    private function ensureRuleTarget(array $data, ?TicketDeliveryRule $existing = null): void
    {
        if (! $this->uploadImageEnabled()) {
            throw ValidationException::withMessages(['upload_image_enabled' => '请先启用 /upload_image 接口']);
        }

        $supplier = Supplier::query()->find((int) $data['supplier_id']);
        $binding = $supplier === null ? null : DB::table('supplier_plugin_bindings')
            ->where('supplier_id', $supplier->id)
            ->where('provider_key', ProviderKey::ZJMF_FINANCE_API)
            ->where('status', 1)
            ->first();
        if ($supplier === null || (int) $supplier->status !== 1 || $binding === null || (int) $binding->status !== 1) {
            throw ValidationException::withMessages(['supplier_id' => '供应商必须启用并配置启用的 ZJMF 财务接口绑定']);
        }

        $mode = (string) ($data['product_scope_mode'] ?? '');
        $productIds = array_values(array_unique(array_map('intval', (array) ($data['product_ids'] ?? []))));
        if ($mode === 'all' && $productIds !== []) {
            throw ValidationException::withMessages(['product_ids' => '全部产品模式不能填写指定产品']);
        }
        if ($mode === 'selected' && $productIds === []) {
            throw ValidationException::withMessages(['product_ids' => '指定产品模式至少选择一个产品']);
        }
        if ($productIds !== []) {
            $validCount = ProductUpstreamBinding::query()
                ->whereIn('product_id', $productIds)
                ->where('supplier_plugin_binding_id', $binding->id)
                ->where('provider_key', ProviderKey::ZJMF_FINANCE_API)
                ->where('status', 1)
                ->select('product_id')
                ->distinct()
                ->count('product_id');
            if ($validCount !== count($productIds)) {
                throw ValidationException::withMessages(['product_ids' => '指定产品必须存在启用的 ZJMF 上游绑定']);
            }
        }

        $duplicate = TicketDeliveryRule::query()
            ->where('supplier_id', $supplier->id)
            ->where('department', $data['department'])
            ->where('product_scope_mode', $mode)
            ->when($existing !== null, fn ($query) => $query->where('id', '!=', $existing->id))
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['department' => '相同供应商、部门和产品范围模式的规则已存在']);
        }
    }
}
