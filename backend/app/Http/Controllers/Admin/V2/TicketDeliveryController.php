<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\Ticket\UpsertTicketDeliveryRuleRequest;
use App\Http\Resources\Admin\V2\TicketDeliveryRuleResource;
use App\Models\ProductUpstreamBinding;
use App\Models\Supplier;
use App\Models\TicketDeliveryRule;
use App\Models\TicketUpstreamBinding;
use App\Services\Ticket\TicketDeliveryService;
use App\Services\Upstream\ProviderKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TicketDeliveryController extends Controller
{
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

    public function ticketStatus(int $ticketId): JsonResponse
    {
        return response()->json(['data' => TicketUpstreamBinding::query()->where('ticket_id', $ticketId)->first()]);
    }

    private function ensureRuleTarget(array $data, ?TicketDeliveryRule $existing = null): void
    {
        $supplier = Supplier::query()->find((int) $data['supplier_id']);
        $binding = $supplier === null ? null : DB::table('supplier_plugin_bindings')
            ->where('supplier_id', $supplier->id)
            ->where('provider_key', ProviderKey::ZJMF_FINANCE_API)
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
