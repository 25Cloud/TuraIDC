<?php

namespace App\Http\Controllers\Client\V2;

use App\Http\Controllers\Controller;
use App\Services\Finance\PaymentService;
use App\Services\Integrations\Payments\PaymentGatewayManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentCallbackController extends Controller
{
    public function __construct(
        private PaymentService $paymentService,
        private PaymentGatewayManager $paymentGatewayManager,
    ) {}

    /**
     * 第三方支付网关异步通知（通用入口）
     */
    public function notify(string $gateway, Request $request)
    {
        $gateway = trim($gateway);

        Log::info("[{$gateway}回调] 收到通知", [
            'gateway' => $gateway,
            'payload_keys' => array_keys($request->all()),
        ]);

        try {
            $success = $this->paymentService->handleGatewayNotify($gateway, $request->all());
        } catch (\Throwable $exception) {
            Log::warning("[{$gateway}回调] 处理失败，已按 fail 响应", [
                'gateway' => $gateway,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);
            $success = false;
        }

        return $this->paymentGatewayManager->gateway($gateway)
            ->buildNotifyResponse($success);
    }

    /**
     * 支付宝异步通知（向后兼容，委托给通用入口）
     */
    public function alipayNotify(Request $request)
    {
        Log::info('[支付宝回调] 收到通知', [
            'payment_no' => (string) $request->input('out_trade_no', ''),
            'trade_no' => (string) $request->input('trade_no', ''),
            'trade_status' => (string) $request->input('trade_status', ''),
            'app_id' => (string) $request->input('app_id', ''),
        ]);

        try {
            $success = $this->paymentService->handleAlipayNotify($request->all());
        } catch (\Throwable $exception) {
            Log::warning('[支付宝回调] 处理失败，已按 fail 响应', [
                'payment_no' => (string) $request->input('out_trade_no', ''),
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);
            $success = false;
        }

        // 支付宝要求返回纯文本 success / fail
        return response($success ? 'success' : 'fail', 200)
            ->header('Content-Type', 'text/plain');
    }
}
