<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;

/**
 * 库存预扣与恢复对称工具。
 *
 * 下单时仅在有限正库存（stock > 0）下预扣，并在订单/账单快照记录实际预扣量 stock_reserved；
 * 取消/退款恢复时按该记录精确回补，避免对未预扣订单（库存 0 或 -1 无上限）误加库存。
 * 旧数据未记录 stock_reserved 时回退历史行为（仅有限库存才回补），不改变既有数据语义。
 */
final class StockReservation
{
    /**
     * 计算实际预扣量并执行预扣，返回预扣量（供写入快照）。
     */
    public static function reserve(Product $product, int $quantity): int
    {
        $quantity = max($quantity, 1);
        $reserved = (int) $product->stock > 0 ? $quantity : 0;

        if ($reserved > 0) {
            $product->decrement('stock', $reserved);
        }

        return $reserved;
    }

    /**
     * 按快照记录的预扣量恢复库存，返回是否发生了回补。
     *
     * @param  array<string, mixed>|null  $snapshot
     */
    public static function restore(Product $product, ?array $snapshot, int $quantity): bool
    {
        if (is_array($snapshot) && array_key_exists('stock_reserved', $snapshot)) {
            $reserved = max((int) $snapshot['stock_reserved'], 0);

            if ($reserved > 0) {
                $product->increment('stock', $reserved);

                return true;
            }

            return false;
        }

        if ((int) $product->stock >= 0) {
            $product->increment('stock', max($quantity, 1));

            return true;
        }

        return false;
    }
}
