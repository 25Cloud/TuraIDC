<?php

declare(strict_types=1);

namespace App\Http\Controllers\Open\V2;

use App\Constants\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Service;
use App\Models\User;
use App\Services\ClientServiceConsole\ServiceDetailService;
use App\Services\ClientServiceConsole\ServicePowerService;
use App\Services\Finance\PaymentService;
use App\Services\Provisioning\ServiceRenewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OpenServiceController extends Controller
{
    /** 列表默认页大小（与旧 limit 行为一致），page_size 可放大但不超过上限 */
    private const LIST_DEFAULT_PAGE_SIZE = 100;

    private const LIST_MAX_PAGE_SIZE = 200;

    public function __construct(
        private readonly ServiceDetailService $detail,
        private readonly ServicePowerService $power,
        private readonly ServiceRenewService $renew,
        private readonly PaymentService $payments,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->attributes->get('api_key_user');
        [$page, $pageSize] = $this->resolveListPagination($request);

        $query = Service::query()
            ->where('user_id', (int) $user->id)
            ->orderByDesc('id');
        $total = (int) $query->count();
        $items = $query->forPage($page, $pageSize)
            ->get()
            ->map(fn (Service $service) => $this->present($service));

        return $this->success($this->listPayload($items, $total, $page, $pageSize));
    }

    public function show(Request $request, int $service): JsonResponse
    {
        $user = $request->attributes->get('api_key_user');
        $detail = $this->detail->getDetailForUser($user, $service);

        return $this->success($detail);
    }

    public function renewPreview(Request $request, int $service): JsonResponse
    {
        $user = $request->attributes->get('api_key_user');
        $cycle = $request->query('billing_cycle') !== null ? (string) $request->query('billing_cycle') : null;

        return $this->success($this->renew->previewForUser($user, $service, $cycle, 0));
    }

    public function reinstallOptions(Request $request, int $service): JsonResponse
    {
        $user = $request->attributes->get('api_key_user');

        // 重装选项列表：下游取 os_id 后再调 POST /services/{id}/reinstall
        return $this->success($this->power->getReinstallOptionsForUser($user, $service));
    }

    public function power(Request $request, int $service): JsonResponse
    {
        $user = $request->attributes->get('api_key_user');
        $action = (string) $request->validate(['action' => ['required', 'in:on,off,reboot,hard_off,hard_reboot']])['action'];
        $result = $this->power->powerActionForUser($user, $service, $action, $this->context($request));

        return $this->success($result, '操作已提交');
    }

    public function renew(Request $request, int $service): JsonResponse
    {
        $user = $request->attributes->get('api_key_user');
        $cycle = (string) $request->validate(['billing_cycle' => ['required', 'string']])['billing_cycle'];
        $invoice = $this->renew->createRenewInvoiceForUser($user, $service, $cycle, 0, $this->context($request));

        $this->payments->payByBalance($invoice, $user, ['trace_id' => 'open:renew:'.$invoice->invoice_no]);
        $invoice->refresh();

        return $this->success([
            'invoice_id' => (int) $invoice->id,
            'invoice_no' => (string) $invoice->invoice_no,
            'amount' => (string) $invoice->amount,
            // payByBalance 返回 Invoice 实例本身，用它判空恒为 true；真实支付结果以账单状态为准
            'paid' => (int) $invoice->status === InvoiceStatus::PAID,
        ], '续费完成');
    }

    public function reinstall(Request $request, int $service): JsonResponse
    {
        $user = $request->attributes->get('api_key_user');
        // 与用户控制台 ReinstallationRequest 同字段同约束（os_id 为上游重装接口的系统标识）。
        // 旧参数名 os_template_id 从未被服务层读取，等于静默丢失选择，这里对齐修正。
        $data = $request->validate(['os_id' => ['required', 'string', 'max:50']]);
        $result = $this->power->reinstallForUser($user, $service, $data, $this->context($request));

        return $this->success($result, '重装已提交');
    }

    public function selfKey(Request $request): JsonResponse
    {
        $key = $request->attributes->get('api_key');

        return $this->success([
            'key_prefix' => (string) $key->key_prefix,
            'scopes' => is_array($key->scopes) ? $key->scopes : [],
            'expires_at' => $key->expires_at?->format('Y-m-d H:i:s'),
            'last_used_at' => $key->last_used_at?->format('Y-m-d H:i:s'),
        ]);
    }

    public function disableSelfKey(Request $request): JsonResponse
    {
        $key = $request->attributes->get('api_key');
        $key->forceFill(['status' => ApiKey::STATUS_DISABLED])->save();

        return $this->success([], '密钥已停用');
    }

    private function context(Request $request): array
    {
        return [
            'trace_id' => 'open:'.$request->attributes->get('api_key')->key_prefix,
            'ip_address' => (string) $request->ip(),
        ];
    }

    /**
     * @return array{0: int, 1: int} [page, pageSize]
     */
    private function resolveListPagination(Request $request): array
    {
        $page = max((int) $request->query('page', '1'), 1);
        $pageSize = (int) $request->query('page_size', (string) self::LIST_DEFAULT_PAGE_SIZE);

        return [$page, min(max($pageSize, 1), self::LIST_MAX_PAGE_SIZE)];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function listPayload($items, int $total, int $page, int $pageSize): array
    {
        return [
            'list' => $items,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    private function present(Service $service): array
    {
        return [
            'id' => (int) $service->id,
            'name' => (string) $service->name,
            'product_name' => (string) ($service->product?->name ?? ''),
            'status' => (int) $service->status,
            'expires_at' => $service->expires_at?->format('Y-m-d H:i:s'),
            'created_at' => $service->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
