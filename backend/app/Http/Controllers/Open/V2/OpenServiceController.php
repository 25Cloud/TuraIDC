<?php

declare(strict_types=1);

namespace App\Http\Controllers\Open\V2;

use App\Http\Controllers\Controller;
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
    public function __construct(
        private readonly ServiceDetailService $detail,
        private readonly ServicePowerService $power,
        private readonly ServiceRenewService $renew,
        private readonly PaymentService $payments,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->attributes->get('api_key_user');
        $items = Service::query()
            ->where('user_id', (int) $user->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (Service $service) => $this->present($service));

        return $this->success(['list' => $items]);
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

        $paid = $this->payments->payByBalance($invoice, $user, ['trace_id' => 'open:renew:'.$invoice->invoice_no]);
        $invoice->refresh();

        return $this->success([
            'invoice_id' => (int) $invoice->id,
            'invoice_no' => (string) $invoice->invoice_no,
            'amount' => (string) $invoice->amount,
            'paid' => $paid !== null,
        ], '续费完成');
    }

    public function reinstall(Request $request, int $service): JsonResponse
    {
        $user = $request->attributes->get('api_key_user');
        $data = $request->validate(['os_template_id' => ['nullable', 'integer']]);
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
        $key->forceFill(['status' => 'disabled'])->save();

        return $this->success([], '密钥已停用');
    }

    private function context(Request $request): array
    {
        return [
            'trace_id' => 'open:'.$request->attributes->get('api_key')->key_prefix,
            'ip_address' => (string) $request->ip(),
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
