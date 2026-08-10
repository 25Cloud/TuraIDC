<?php

namespace App\Http\Middleware;

use App\Services\Integrations\Payments\PaymentGatewayManager;
use App\Support\SensitiveDataSanitizer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyAlipayCallbackSignature
{
    public function __construct(
        private readonly PaymentGatewayManager $paymentGatewayManager,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $params = $request->all();

        if (! $this->paymentGatewayManager->alipay()->verifyNotify($params)) {
            Log::warning('[支付宝回调] 路由层签名验证失败', [
                'payment_no' => (string) $request->input('out_trade_no', ''),
                'trade_no' => (string) $request->input('trade_no', ''),
                'trade_status' => (string) $request->input('trade_status', ''),
                'params' => SensitiveDataSanitizer::sanitize($params),
            ]);

            return response('签名验证失败', 401)
                ->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        return $next($request);
    }
}
