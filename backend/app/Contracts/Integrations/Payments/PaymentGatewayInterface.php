<?php

declare(strict_types=1);

namespace App\Contracts\Integrations\Payments;

use App\Services\Integrations\Payments\Data\PaymentPrecreateRequest;
use App\Services\Integrations\Payments\Data\PaymentPrecreateResult;
use App\Services\Integrations\Payments\Data\PaymentQueryResult;
use App\Services\Integrations\Payments\Data\PaymentRefundRequest;
use App\Services\Integrations\Payments\Data\PaymentRefundResult;
use Illuminate\Http\Response;

interface PaymentGatewayInterface
{
    public function key(): string;

    public function name(): string;

    public function isEnabled(): bool;

    public function matchesMerchantId(?string $merchantId): bool;

    public function precreate(PaymentPrecreateRequest $request): PaymentPrecreateResult;

    public function query(string $outTradeNo): PaymentQueryResult;

    public function refund(PaymentRefundRequest $request): PaymentRefundResult;

    public function verifyNotify(array $payload): bool;

    /**
     * 构建异步通知响应（各网关格式不同）。
     * 支付宝返回 text/plain "success"/"fail"，微信返回 application/xml。
     */
    public function buildNotifyResponse(bool $success): Response;
}
