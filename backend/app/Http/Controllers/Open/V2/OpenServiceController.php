<?php

declare(strict_types=1);

namespace App\Http\Controllers\Open\V2;

use App\Constants\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Invoice;
use App\Models\Service;
use App\Services\ClientServiceConsole\ServiceDetailService;
use App\Services\ClientServiceConsole\ServicePowerService;
use App\Services\ClientServiceConsole\ServiceTrafficPackageService;
use App\Services\ClientServiceConsole\ServiceUpgradeService;
use App\Services\Finance\PaymentService;
use App\Services\Provisioning\ServiceRenewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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
        private readonly ServiceUpgradeService $upgrades,
        private readonly ServiceTrafficPackageService $trafficPackages,
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

    /**
     * GET /services/{service}/upgrades
     *
     * 配置项升级预览：直接回放 ServiceUpgradeService 的预览结果（选项结构由上游驱动决定），
     * 下游 tura_open_api 驱动把它映射成自己的控制台选项。
     */
    public function upgrades(Request $request, int $service): JsonResponse
    {
        $user = $request->attributes->get('api_key_user');

        return $this->success($this->upgrades->previewForUser($user, $service));
    }

    /**
     * POST /services/{service}/upgrades/quotes
     *
     * 升级报价：字段与用户控制台 QuoteHostUpgradeRequest 完全一致（product_id + billing_cycle
     * + 可选 promo_code），下游不用为开放接口再记一套参数名。
     */
    public function quoteUpgrade(Request $request, int $service): JsonResponse
    {
        $user = $request->attributes->get('api_key_user');
        $data = $request->validate($this->upgradeSelectionRules());

        return $this->success($this->upgrades->quoteForUser($user, $service, $data));
    }

    /**
     * POST /services/{service}/upgrades/orders
     *
     * 生成升级账单：只落账单，不扣余额也不代付——付款走既有的
     * `POST /orders/{invoice}/pay`，与续费、下单两条链路同口径。
     */
    public function createUpgradeOrder(Request $request, int $service): JsonResponse
    {
        $user = $request->attributes->get('api_key_user');
        $data = $request->validate($this->upgradeSelectionRules());
        $invoice = $this->upgrades->createInvoiceForUser($user, $service, $data, $this->context($request));

        return $this->success($this->presentInvoice($invoice), '产品升降级账单创建成功');
    }

    /**
     * GET /services/{service}/flow-packages
     *
     * 可售流量包档位：`supported=false` 时 message 说明原因（服务不支持/后台未开流量包），
     * 与用户控制台同一份数据，下游据此决定是否展示购买入口。
     */
    public function flowPackages(Request $request, int $service): JsonResponse
    {
        $user = $request->attributes->get('api_key_user');

        return $this->success($this->trafficPackages->previewForUser($user, $service));
    }

    /**
     * POST /services/{service}/flow-packages/orders
     *
     * 生成流量包账单：selection 只需 `target_value`（档位数值），与用户控制台
     * CreateTrafficPackageOrderRequest 一致；付款同样走既有 pay 端点。
     */
    public function createFlowPackageOrder(Request $request, int $service): JsonResponse
    {
        $user = $request->attributes->get('api_key_user');
        $data = $request->validate([
            'target_value' => ['required', 'integer', 'min:1'],
        ]);
        $invoice = $this->trafficPackages->createInvoiceForUser($user, $service, $data, $this->context($request));

        return $this->success($this->presentInvoice($invoice), '流量包账单创建成功');
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
     * 升级选择参数：与用户控制台 QuoteHostUpgradeRequest / CreateHostUpgradeOrderRequest 同约束。
     *
     * @return array<string, array<int, string>>
     */
    private function upgradeSelectionRules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'min:1'],
            'billing_cycle' => ['required', 'string', 'max:30'],
            'promo_code' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * 账单投影：开放接口只需要「哪张单、多少钱」，其余明细走 GET /orders/{invoice}。
     *
     * @return array{id: int, invoice_no: string, amount: string}
     */
    private function presentInvoice(Invoice $invoice): array
    {
        return [
            'id' => (int) $invoice->id,
            'invoice_no' => (string) $invoice->invoice_no,
            'amount' => number_format((float) $invoice->amount, 2, '.', ''),
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
     * @param  Collection<int, array<string, mixed>>  $items
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
