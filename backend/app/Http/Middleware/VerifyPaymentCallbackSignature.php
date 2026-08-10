<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Contracts\Integrations\Payments\PaymentGatewayInterface;
use App\Services\Integrations\Payments\PaymentGatewayManager;
use App\Services\Integrations\Plugins\PaymentGatewayBindingResolver;
use App\Support\SensitiveDataSanitizer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyPaymentCallbackSignature
{
    public function __construct(
        private readonly PaymentGatewayManager $paymentGatewayManager,
        private readonly PaymentGatewayBindingResolver $paymentGatewayBindingResolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $gatewayKey = $this->paymentGatewayBindingResolver->normalizeGatewayKey(
            trim((string) $request->route('gateway', ''))
        );
        $params = $request->all();

        try {
            $gateway = $this->paymentGatewayManager->gateway($gatewayKey);
        } catch (\Throwable $exception) {
            Log::warning('[支付回调] 网关不可用，路由层签名验证失败', [
                'gateway' => $gatewayKey,
                'message' => $exception->getMessage(),
                'params' => SensitiveDataSanitizer::sanitize($params),
            ]);

            return response('fail', 200)->header('Content-Type', 'text/plain');
        }

        if (! $gateway->verifyNotify($params)) {
            Log::warning('[支付回调] 路由层签名验证失败', [
                'gateway' => $gatewayKey,
                'payment_no' => (string) $request->input('out_trade_no', ''),
                'trade_no' => (string) $request->input('trade_no', ''),
                'trade_status' => (string) $request->input('trade_status', ''),
                'params' => SensitiveDataSanitizer::sanitize($params),
            ]);

            return $this->failureResponse($gateway);
        }

        return $next($request);
    }

    private function failureResponse(PaymentGatewayInterface $gateway): Response
    {
        return $gateway->buildNotifyResponse(false);
    }
}
