<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Invoice;
use App\Models\Order;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;

final class OrderInvoiceNoGenerator
{
    private const ORDER_PREFIX = 'dd';

    private const INVOICE_PREFIX = 'zd';

    private const MAX_ATTEMPTS = 50;

    private const RESERVE_TTL_SECONDS = 10;

    /**
     * 生成一组订单号与账单号，二者共享同一时间戳与 4 位尾号。
     *
     * @return array{order_no:string,invoice_no:string,timestamp:string,suffix:string}
     */
    public static function generatePair(?CarbonInterface $time = null): array
    {
        $resolvedTime = $time?->copy() ?? now();

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $pair = self::buildPair($resolvedTime, self::randomSuffix());
            $reservationKey = sprintf('serial:order_invoice:%s:%s', $pair['timestamp'], $pair['suffix']);

            if (! Cache::add($reservationKey, 1, now()->addSeconds(self::RESERVE_TTL_SECONDS))) {
                continue;
            }

            if (! self::pairExists($pair['order_no'], $pair['invoice_no'])) {
                return $pair;
            }
        }

        throw new RuntimeException('订单号生成失败，请稍后重试');
    }

    /**
     * 按指定时间与尾号构造编号，便于测试与派生账单号。
     *
     * @return array{order_no:string,invoice_no:string,timestamp:string,suffix:string}
     */
    public static function buildPair(?CarbonInterface $time = null, ?string $suffix = null): array
    {
        $resolvedTime = $time?->copy() ?? now();
        $timestamp = $resolvedTime->format('YmdHis');
        $normalizedSuffix = self::normalizeSuffix($suffix ?? self::randomSuffix());

        return [
            'order_no' => self::ORDER_PREFIX.$timestamp.$normalizedSuffix,
            'invoice_no' => self::INVOICE_PREFIX.$timestamp.$normalizedSuffix,
            'timestamp' => $timestamp,
            'suffix' => $normalizedSuffix,
        ];
    }

    public static function buildOrderNo(?CarbonInterface $time = null, ?string $suffix = null): string
    {
        return self::buildPair($time, $suffix)['order_no'];
    }

    public static function buildInvoiceNo(?CarbonInterface $time = null, ?string $suffix = null): string
    {
        return self::buildPair($time, $suffix)['invoice_no'];
    }

    public static function deriveInvoiceNoFromOrderNo(string $orderNo): ?string
    {
        if (! preg_match('/^'.self::ORDER_PREFIX.'(\d{14})(\d{4})$/', trim($orderNo), $matches)) {
            return null;
        }

        return self::INVOICE_PREFIX.$matches[1].$matches[2];
    }

    public static function deriveOrderNoFromInvoiceNo(string $invoiceNo): ?string
    {
        if (! preg_match('/^'.self::INVOICE_PREFIX.'(\d{14})(\d{4})$/', trim($invoiceNo), $matches)) {
            return null;
        }

        return self::ORDER_PREFIX.$matches[1].$matches[2];
    }

    private static function pairExists(string $orderNo, string $invoiceNo): bool
    {
        return Order::query()->where('order_no', $orderNo)->exists()
            || Invoice::query()->where('invoice_no', $invoiceNo)->exists();
    }

    private static function randomSuffix(): string
    {
        return str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    private static function normalizeSuffix(string $suffix): string
    {
        $normalized = trim($suffix);

        if (! preg_match('/^\d{4}$/', $normalized)) {
            throw new InvalidArgumentException('编号尾号必须是 4 位数字');
        }

        return $normalized;
    }
}
