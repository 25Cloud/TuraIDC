<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Exceptions\BusinessException;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class CheckoutSecurityService
{
    private const QUOTE_TTL_SECONDS = 600;

    private const ORDER_IDEMPOTENCY_TTL_SECONDS = 900;

    private const ORDER_FINGERPRINT_TTL_SECONDS = 180;

    private const PAYMENT_SESSION_TTL_SECONDS = 300;

    /**
     * 所有 checkout 临时数据使用 volatile store（Redis DB 2），与业务缓存隔离。
     */
    private function volatileStore()
    {
        return Cache::store('redis_volatile');
    }

    public function issueQuoteToken(
        int $productId,
        string $billingCycle,
        array $config,
        array $quotePayload,
        array $context = [],
    ): array {
        $now = CarbonImmutable::now();
        $expiresAt = $now->addSeconds(self::QUOTE_TTL_SECONDS);
        $token = $this->generateToken('quote');

        $this->volatileStore()->put($this->quoteCacheKey($token), [
            'product_id' => $productId,
            'billing_cycle' => $billingCycle,
            'quantity' => max((int) ($quotePayload['quantity'] ?? 1), 1),
            'config_hash' => $this->hashPayload($config),
            'original_amount' => $this->normalizeAmount($quotePayload['subtotal_amount'] ?? $quotePayload['total_amount'] ?? 0),
            'amount' => $this->normalizeAmount($quotePayload['total_amount'] ?? 0),
            'base_amount' => $this->normalizeAmount($quotePayload['base_amount'] ?? 0),
            'config_amount' => $this->normalizeAmount($quotePayload['config_amount'] ?? 0),
            'setup_fee' => $this->normalizeAmount($quotePayload['setup_fee'] ?? 0),
            'discount_amount' => $this->normalizeAmount($quotePayload['discount_amount'] ?? 0),
            'user_coupon_id' => (int) ($quotePayload['coupon']['user_coupon_id'] ?? $quotePayload['user_coupon_id'] ?? 0),
            'request_id' => trim((string) ($context['request_id'] ?? '')),
            'ip_address' => trim((string) ($context['ip_address'] ?? '')),
            'issued_at' => $now->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
        ], $expiresAt);

        return [
            'quote_token' => $token,
            'quote_expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    public function assertQuoteToken(
        string $token,
        int $productId,
        string $billingCycle,
        int $quantity,
        array $config,
        string $originalAmount,
        string $amount,
        ?int $couponId = null,
    ): array {
        $payload = $this->volatileStore()->get($this->quoteCacheKey($token));

        throw_if(! is_array($payload), new BusinessException('报价凭证已失效，请刷新配置后重试'));

        $tokenProductId = (int) ($payload['product_id'] ?? 0);
        $tokenBillingCycle = (string) ($payload['billing_cycle'] ?? '');
        $tokenQuantity = max((int) ($payload['quantity'] ?? 1), 1);
        $tokenConfigHash = (string) ($payload['config_hash'] ?? '');
        $tokenOriginalAmount = $this->normalizeAmount($payload['original_amount'] ?? 0);
        $tokenAmount = $this->normalizeAmount($payload['amount'] ?? 0);
        $tokenCouponId = (int) ($payload['user_coupon_id'] ?? 0);

        throw_if(
            $tokenProductId !== $productId
            || $tokenBillingCycle !== $billingCycle
            || $tokenQuantity !== max($quantity, 1)
            || $tokenConfigHash !== $this->hashPayload($config),
            new BusinessException('订单配置与报价不一致，请重新获取报价')
        );

        throw_if(
            $tokenOriginalAmount !== $this->normalizeAmount($originalAmount)
            || $tokenCouponId !== (int) ($couponId ?? 0)
            || $tokenAmount !== $this->normalizeAmount($amount),
            new BusinessException('报价已变更，请刷新页面后重试')
        );

        return $payload;
    }

    public function buildCheckoutFingerprint(int $productId, string $billingCycle, int $quantity, array $config, int $couponId = 0): string
    {
        return hash('sha256', implode('|', [
            $productId,
            $billingCycle,
            max($quantity, 1),
            $this->hashPayload($config),
            (int) $couponId,
        ]));
    }

    public function rememberCreatedInvoice(
        int $userId,
        string $idempotencyKey,
        string $fingerprint,
        int $invoiceId,
    ): void {
        $idempotencyExpiresAt = CarbonImmutable::now()->addSeconds(self::ORDER_IDEMPOTENCY_TTL_SECONDS);
        $fingerprintExpiresAt = CarbonImmutable::now()->addSeconds(self::ORDER_FINGERPRINT_TTL_SECONDS);

        $this->volatileStore()->put($this->invoiceIdempotencyCacheKey($userId, $idempotencyKey), $invoiceId, $idempotencyExpiresAt);
        $this->volatileStore()->put($this->invoiceFingerprintCacheKey($userId, $fingerprint), $invoiceId, $fingerprintExpiresAt);
    }

    public function rememberCreatedOrder(
        int $userId,
        string $idempotencyKey,
        string $fingerprint,
        int $orderId,
    ): void {
        $idempotencyExpiresAt = CarbonImmutable::now()->addSeconds(self::ORDER_IDEMPOTENCY_TTL_SECONDS);
        $fingerprintExpiresAt = CarbonImmutable::now()->addSeconds(self::ORDER_FINGERPRINT_TTL_SECONDS);

        $this->volatileStore()->put($this->orderIdempotencyCacheKey($userId, $idempotencyKey), $orderId, $idempotencyExpiresAt);
        $this->volatileStore()->put($this->orderFingerprintCacheKey($userId, $fingerprint), $orderId, $fingerprintExpiresAt);
    }

    public function resolveIdempotentInvoiceId(int $userId, string $idempotencyKey): ?int
    {
        $invoiceId = (int) $this->volatileStore()->get($this->invoiceIdempotencyCacheKey($userId, $idempotencyKey), 0);

        return $invoiceId > 0 ? $invoiceId : null;
    }

    public function resolveIdempotentOrderId(int $userId, string $idempotencyKey): ?int
    {
        $orderId = (int) $this->volatileStore()->get($this->orderIdempotencyCacheKey($userId, $idempotencyKey), 0);

        return $orderId > 0 ? $orderId : null;
    }

    public function resolveFingerprintInvoiceId(int $userId, string $fingerprint): ?int
    {
        $invoiceId = (int) $this->volatileStore()->get($this->invoiceFingerprintCacheKey($userId, $fingerprint), 0);

        return $invoiceId > 0 ? $invoiceId : null;
    }

    public function resolveFingerprintOrderId(int $userId, string $fingerprint): ?int
    {
        $orderId = (int) $this->volatileStore()->get($this->orderFingerprintCacheKey($userId, $fingerprint), 0);

        return $orderId > 0 ? $orderId : null;
    }

    public static function paymentSessionTtlSeconds(): int
    {
        return self::PAYMENT_SESSION_TTL_SECONDS;
    }

    public function paymentSessionExpiresAt(Invoice|Order $record): CarbonImmutable
    {
        $createdAt = $record->created_at;
        $base = $createdAt instanceof \DateTimeInterface
            ? CarbonImmutable::instance($createdAt)
            : ($createdAt ? CarbonImmutable::parse((string) $createdAt) : CarbonImmutable::now());

        return $base->addSeconds(self::PAYMENT_SESSION_TTL_SECONDS);
    }

    public function paymentRecordExpiresAt(Payment $payment): CarbonImmutable
    {
        $createdAt = $payment->created_at;
        $base = $createdAt instanceof \DateTimeInterface
            ? CarbonImmutable::instance($createdAt)
            : ($createdAt ? CarbonImmutable::parse((string) $createdAt) : CarbonImmutable::now());

        return $base->addSeconds(self::PAYMENT_SESSION_TTL_SECONDS);
    }

    public function isPaymentSessionExpired(Invoice|Order $record): bool
    {
        return $this->paymentSessionExpiresAt($record)->lessThanOrEqualTo(CarbonImmutable::now());
    }

    public function issuePaymentSession(Order $order, int $userId): array
    {
        if (! in_array((int) $order->status, [OrderStatus::PENDING], true)) {
            return [
                'session_token' => '',
                'expires_at' => null,
            ];
        }

        $order->loadMissing('invoice');
        if ($order->invoice instanceof Invoice) {
            return $this->issueInvoicePaymentSession($order->invoice, $userId);
        }

        $now = CarbonImmutable::now();
        $expiresAt = $this->paymentSessionExpiresAt($order);
        if ($expiresAt->lessThanOrEqualTo($now)) {
            return [
                'session_token' => '',
                'expires_at' => $expiresAt->toIso8601String(),
            ];
        }
        $token = $this->generateToken('ord_session');

        $this->volatileStore()->put($this->orderPaymentSessionCacheKey($token), [
            'order_id' => (int) $order->id,
            'user_id' => $userId,
            'order_status' => (int) $order->status,
            'payable_amount' => $this->normalizeAmount($order->paid_amount ?? 0),
            'issued_at' => $now->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
        ], $expiresAt);

        return [
            'session_token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    public function assertPaymentSessionToken(string $token, Order $order, int $userId): array
    {
        $order->loadMissing('invoice');
        if ($order->invoice instanceof Invoice) {
            return $this->assertInvoicePaymentSessionToken($token, $order->invoice, $userId);
        }

        $this->assertPaymentSessionWindowOpen($order);

        $payload = $this->volatileStore()->get($this->orderPaymentSessionCacheKey($token));

        throw_if(! is_array($payload), new BusinessException('支付会话已失效，请刷新页面后重试'));

        $currentPayableAmount = $this->normalizeAmount($order->paid_amount ?? 0);

        throw_if(
            (int) ($payload['order_id'] ?? 0) !== (int) $order->id
            || (int) ($payload['user_id'] ?? 0) !== $userId
            || (string) ($payload['payable_amount'] ?? '') !== $currentPayableAmount,
            new BusinessException('支付会话校验失败，请刷新页面后重试')
        );

        return $payload;
    }

    public function issueInvoicePaymentSession(Invoice $invoice, int $userId): array
    {
        $payableAmount = $this->resolveInvoicePayableAmount($invoice);
        if (! in_array((int) $invoice->status, [InvoiceStatus::UNPAID, InvoiceStatus::OVERDUE], true)) {
            return [
                'session_token' => '',
                'expires_at' => null,
            ];
        }

        $now = CarbonImmutable::now();
        $expiresAt = $this->paymentSessionExpiresAt($invoice);
        if ($expiresAt->lessThanOrEqualTo($now)) {
            return [
                'session_token' => '',
                'expires_at' => $expiresAt->toIso8601String(),
            ];
        }
        $token = $this->generateToken('inv_session');

        $this->volatileStore()->put($this->invoicePaymentSessionCacheKey($token), [
            'invoice_id' => (int) $invoice->id,
            'user_id' => $userId,
            'invoice_status' => (int) $invoice->status,
            'payable_amount' => $this->normalizeAmount($payableAmount),
            'issued_at' => $now->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
        ], $expiresAt);

        return [
            'session_token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    public function assertInvoicePaymentSessionToken(string $token, Invoice $invoice, int $userId): array
    {
        $this->assertPaymentSessionWindowOpen($invoice);

        $payload = $this->volatileStore()->get($this->invoicePaymentSessionCacheKey($token));

        throw_if(! is_array($payload), new BusinessException('支付会话已失效，请刷新页面后重试'));

        $currentPayableAmount = $this->normalizeAmount($this->resolveInvoicePayableAmount($invoice));

        throw_if(
            (int) ($payload['invoice_id'] ?? 0) !== (int) $invoice->id
            || (int) ($payload['user_id'] ?? 0) !== $userId
            || (string) ($payload['payable_amount'] ?? '') !== $currentPayableAmount,
            new BusinessException('支付会话校验失败，请刷新页面后重试')
        );

        return $payload;
    }

    public function issueInvoicePaymentPollToken(Payment $payment, Invoice $invoice, int $userId, string $clientIp = ''): array
    {
        $now = CarbonImmutable::now();
        $expiresAt = $this->paymentSessionExpiresAt($invoice);
        $token = $this->generateToken('inv_poll');

        $this->volatileStore()->put($this->invoicePaymentPollCacheKey($token), [
            'payment_id' => (int) $payment->id,
            'payment_no' => (string) $payment->payment_no,
            'invoice_id' => (int) $invoice->id,
            'user_id' => $userId,
            'gateway' => $payment->gatewayKey(),
            'client_ip_hash' => $this->hashClientIp($clientIp),
            'issued_at' => $now->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
        ], $expiresAt);

        return [
            'poll_token' => $token,
            'poll_expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    public function issuePaymentPollToken(Payment $payment, Order $order, int $userId, string $clientIp = ''): array
    {
        $order->loadMissing('invoice');
        if ($order->invoice instanceof Invoice) {
            return $this->issueInvoicePaymentPollToken($payment, $order->invoice, $userId, $clientIp);
        }

        $now = CarbonImmutable::now();
        $expiresAt = $this->paymentSessionExpiresAt($order);
        $token = $this->generateToken('ord_poll');

        $this->volatileStore()->put($this->orderPaymentPollCacheKey($token), [
            'payment_id' => (int) $payment->id,
            'payment_no' => (string) $payment->payment_no,
            'order_id' => (int) $order->id,
            'user_id' => $userId,
            'gateway' => $payment->gatewayKey(),
            'client_ip_hash' => $this->hashClientIp($clientIp),
            'issued_at' => $now->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
        ], $expiresAt);

        return [
            'poll_token' => $token,
            'poll_expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    public function assertInvoicePaymentPollToken(string $token, Payment $payment, Invoice $invoice, int $userId, string $clientIp = ''): array
    {
        $payload = $this->volatileStore()->get($this->invoicePaymentPollCacheKey($token));

        throw_if(! is_array($payload), new BusinessException('支付轮询凭证已失效，请重新获取二维码'));

        throw_if(
            (int) ($payload['payment_id'] ?? 0) !== (int) $payment->id
            || (string) ($payload['payment_no'] ?? '') !== (string) $payment->payment_no
            || (int) ($payload['invoice_id'] ?? 0) !== (int) $invoice->id
            || (int) ($payload['user_id'] ?? 0) !== $userId
            || ! $this->verifyClientIp($payload, $clientIp),
            new BusinessException('支付轮询凭证校验失败，请重新获取二维码')
        );

        return $payload;
    }

    public function assertPaymentPollToken(string $token, Payment $payment, Order $order, int $userId, string $clientIp = ''): array
    {
        $order->loadMissing('invoice');
        if ($order->invoice instanceof Invoice) {
            return $this->assertInvoicePaymentPollToken($token, $payment, $order->invoice, $userId, $clientIp);
        }

        $payload = $this->volatileStore()->get($this->orderPaymentPollCacheKey($token));

        throw_if(! is_array($payload), new BusinessException('支付轮询凭证已失效，请重新获取二维码'));

        throw_if(
            (int) ($payload['payment_id'] ?? 0) !== (int) $payment->id
            || (string) ($payload['payment_no'] ?? '') !== (string) $payment->payment_no
            || (int) ($payload['order_id'] ?? 0) !== (int) $order->id
            || (int) ($payload['user_id'] ?? 0) !== $userId
            || ! $this->verifyClientIp($payload, $clientIp),
            new BusinessException('支付轮询凭证校验失败，请重新获取二维码')
        );

        return $payload;
    }

    public function issueRechargePollToken(Payment $payment, int $userId, string $clientIp = ''): array
    {
        $now = CarbonImmutable::now();
        $expiresAt = $this->paymentRecordExpiresAt($payment);
        $token = $this->generateToken('recharge_poll');

        $this->volatileStore()->put($this->rechargePollCacheKey($token), [
            'payment_id' => (int) $payment->id,
            'payment_no' => (string) $payment->payment_no,
            'user_id' => $userId,
            'gateway' => $payment->gatewayKey(),
            'client_ip_hash' => $this->hashClientIp($clientIp),
            'issued_at' => $now->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
        ], $expiresAt);

        return [
            'poll_token' => $token,
            'poll_expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    public function assertRechargePollToken(string $token, Payment $payment, int $userId, string $clientIp = ''): array
    {
        $payload = $this->volatileStore()->get($this->rechargePollCacheKey($token));

        throw_if(! is_array($payload), new BusinessException('充值轮询凭证已失效，请重新获取二维码'));

        throw_if(
            (int) ($payload['payment_id'] ?? 0) !== (int) $payment->id
            || (string) ($payload['payment_no'] ?? '') !== (string) $payment->payment_no
            || (int) ($payload['user_id'] ?? 0) !== $userId
            || ! $this->verifyClientIp($payload, $clientIp),
            new BusinessException('充值轮询凭证校验失败，请重新获取二维码')
        );

        return $payload;
    }

    private function quoteCacheKey(string $token): string
    {
        return 'checkout:quote:'.$token;
    }

    private function invoiceIdempotencyCacheKey(int $userId, string $idempotencyKey): string
    {
        return 'checkout:invoice:idempotency:'.$userId.':'.sha1($idempotencyKey);
    }

    private function invoiceFingerprintCacheKey(int $userId, string $fingerprint): string
    {
        return 'checkout:invoice:fingerprint:'.$userId.':'.$fingerprint;
    }

    private function orderIdempotencyCacheKey(int $userId, string $idempotencyKey): string
    {
        return 'checkout:order:idempotency:'.$userId.':'.sha1($idempotencyKey);
    }

    private function orderFingerprintCacheKey(int $userId, string $fingerprint): string
    {
        return 'checkout:order:fingerprint:'.$userId.':'.$fingerprint;
    }

    private function rechargePollCacheKey(string $token): string
    {
        return 'checkout:recharge:poll:'.$token;
    }

    private function invoicePaymentSessionCacheKey(string $token): string
    {
        return 'checkout:invoice:session:'.$token;
    }

    private function orderPaymentSessionCacheKey(string $token): string
    {
        return 'checkout:order:session:'.$token;
    }

    private function invoicePaymentPollCacheKey(string $token): string
    {
        return 'checkout:invoice:poll:'.$token;
    }

    private function orderPaymentPollCacheKey(string $token): string
    {
        return 'checkout:order:poll:'.$token;
    }

    private function generateToken(string $prefix): string
    {
        return $prefix.'_'.Str::lower(Str::random(48));
    }

    private function assertPaymentSessionWindowOpen(Invoice|Order $record): void
    {
        throw_if(
            $this->isPaymentSessionExpired($record),
            new BusinessException('支付会话已失效，请重新创建账单后支付')
        );
    }

    private function hashPayload(array $payload): string
    {
        return hash('sha256', json_encode($this->sortPayload($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function sortPayload(array $payload): array
    {
        ksort($payload);

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->sortPayload($value);

                continue;
            }

            if (is_bool($value)) {
                $payload[$key] = $value ? 1 : 0;

                continue;
            }

            if ($value === null) {
                $payload[$key] = '';

                continue;
            }

            if (is_numeric($value)) {
                $payload[$key] = (string) $value;

                continue;
            }

            $payload[$key] = trim((string) $value);
        }

        return $payload;
    }

    private function resolveInvoicePayableAmount(Invoice $invoice): float
    {
        return round(max((float) ($invoice->amount ?? 0) - (float) ($invoice->paid_amount ?? 0), 0), 2);
    }

    private function normalizeAmount(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    private function hashClientIp(string $clientIp): string
    {
        $ip = trim($clientIp);
        if ($ip === '') {
            return '';
        }

        return hash('sha256', $ip);
    }

    private function verifyClientIp(array $payload, string $clientIp): bool
    {
        $storedHash = (string) ($payload['client_ip_hash'] ?? '');

        // 如果存储时未绑定 IP（历史令牌），则跳过校验
        if ($storedHash === '') {
            return true;
        }

        // 如果当前请求无 IP，则校验失败
        if (trim($clientIp) === '') {
            return false;
        }

        return hash_equals($storedHash, $this->hashClientIp($clientIp));
    }
}
