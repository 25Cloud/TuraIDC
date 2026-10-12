<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * 上游以「库存不足」类文案拒绝开通/升级（加购/结算/余额支付）时的分类异常。
 *
 * 业务语义与 BusinessException 完全一致（既有的重试、退款等处理链路不变），
 * 仅供编排层识别：命中后需要校准本地库存——上游返回的 qty 是展示口径，
 * 与加购的真实校验脱节，不校准就会出现「页面显示有货、付款后开不出来」。
 *
 * 词表与判定收敛在本类的 matches()，供插件多个上游失败出口共用，
 * 不要在调用方散落文案匹配。
 */
class UpstreamStockShortageException extends BusinessException
{
    /**
     * 上游各失败出口的拒绝文案（home 路由中文与语言包键）：
     * - 「产品%s库存不足」：加购/结算的库存校验
     * - 「商品缺货」：上游代发商品缺货（加购写死）
     * - 「商品不存在」：上游代发商品下架后的加购拒绝（加购写死）
     * - 「产品不存在」：上游商品被删除时读取商品配置的拒绝
     * - 「超出库存」：升级换品时目标商品库存为 0 的拒绝
     * - 「out of stock」：openapi cartCheckout 结算复查（英文版上游）
     *
     * @param  string  $message  上游原始 msg（映射为用户文案之前）
     */
    public static function matches(string $message): bool
    {
        if ($message === '') {
            return false;
        }

        foreach (['库存不足', '售罄', '售完', '缺货', '商品不存在', '产品不存在', '超出库存', 'out of stock'] as $needle) {
            if (mb_stripos($message, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
