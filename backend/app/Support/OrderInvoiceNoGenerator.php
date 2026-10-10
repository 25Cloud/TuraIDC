<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Invoice;
use App\Models\Order;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;

/**
 * 订单号 / 账单号生成器。
 *
 * 编号格式为「前缀 + 14 位时间戳（YmdHis）+ 4 位随机尾号」，例如 ORD202604051314150427。
 * 前缀统一使用可读的大写英文缩写，不再使用 dd / zd 这类易混淆的拼音首字母。
 *
 * 订单号与账单号共享同一时间戳与尾号，因此二者可互相派生；影子订单绑定、
 * 续费/升级链路与对账修复都依赖这一配对关系，请勿拆成两套独立序列。
 */
final class OrderInvoiceNoGenerator
{
    public const ORDER_PREFIX = 'ORD';

    public const INVOICE_PREFIX = 'INV';

    /**
     * 存量数据的历史前缀，仅用于识别与派生，不再生成。
     */
    private const LEGACY_ORDER_PREFIX = 'dd';

    private const LEGACY_INVOICE_PREFIX = 'zd';

    private const SUFFIX_LENGTH = 4;

    private const TIMESTAMP_LENGTH = 14;

    private const MAX_ATTEMPTS = 50;

    private const RESERVE_TTL_SECONDS = 10;

    /**
     * 生成一组订单号与账单号，二者共享同一时间戳与尾号。
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

    /**
     * 从订单号派生账单号：ORD 归一到 INV，存量 dd 归一到存量 zd。
     */
    public static function deriveInvoiceNoFromOrderNo(string $orderNo): ?string
    {
        $tail = self::matchTail($orderNo, self::ORDER_PREFIX);
        if ($tail !== null) {
            return self::INVOICE_PREFIX.$tail;
        }

        $legacyTail = self::matchTail($orderNo, self::LEGACY_ORDER_PREFIX);

        return $legacyTail === null ? null : self::LEGACY_INVOICE_PREFIX.$legacyTail;
    }

    /**
     * 从账单号派生订单号：INV 归一到 ORD，存量 zd 归一到存量 dd。
     */
    public static function deriveOrderNoFromInvoiceNo(string $invoiceNo): ?string
    {
        $tail = self::matchTail($invoiceNo, self::INVOICE_PREFIX);
        if ($tail !== null) {
            return self::ORDER_PREFIX.$tail;
        }

        $legacyTail = self::matchTail($invoiceNo, self::LEGACY_INVOICE_PREFIX);

        return $legacyTail === null ? null : self::LEGACY_ORDER_PREFIX.$legacyTail;
    }

    /**
     * 校验编号前缀并返回「时间戳 + 随机尾号」，前缀不匹配时返回 null。
     */
    private static function matchTail(string $number, string $prefix): ?string
    {
        $pattern = sprintf(
            '/^%s(\d{%d}\d{%d})$/',
            preg_quote($prefix, '/'),
            self::TIMESTAMP_LENGTH,
            self::SUFFIX_LENGTH,
        );

        return preg_match($pattern, trim($number), $matches) === 1 ? $matches[1] : null;
    }

    private static function pairExists(string $orderNo, string $invoiceNo): bool
    {
        return Order::query()->where('order_no', $orderNo)->exists()
            || Invoice::query()->where('invoice_no', $invoiceNo)->exists();
    }

    private static function randomSuffix(): string
    {
        return str_pad(
            (string) random_int(0, (10 ** self::SUFFIX_LENGTH) - 1),
            self::SUFFIX_LENGTH,
            '0',
            STR_PAD_LEFT
        );
    }

    private static function normalizeSuffix(string $suffix): string
    {
        $normalized = trim($suffix);
        $pattern = sprintf('/^\d{%d}$/', self::SUFFIX_LENGTH);

        if (! preg_match($pattern, $normalized)) {
            throw new InvalidArgumentException('编号尾号必须是 4 位数字');
        }

        return $normalized;
    }
}
