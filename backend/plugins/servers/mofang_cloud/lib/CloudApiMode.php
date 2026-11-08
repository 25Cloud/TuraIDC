<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\MofangCloud\Lib;

/**
 * 魔方云接口代际。
 *
 * 魔方云（DCIMS）对外提供过两代完全不同的接口，魔方财务也是按代际写了两套客户端：
 * 3.7.6 及更早走老版 `{url}/v1{action}` + `access-token` 头；3.8.0 及以后走新版
 * `{url}/api/v1{action}` + `Dcims-X-Appid/Nonce/Timestamp/Sign` 签名。
 *
 * 两代的端点路径、鉴权方式、报文编码互不兼容，也不存在「一次请求两边都能过」的写法，
 * 因此必须先分辨代际再发请求：混用的结果一定是 404 或签名失败，而不会自动降级。
 */
enum CloudApiMode: string
{
    case Legacy = 'legacy';

    case Dcims = 'dcims';

    public function label(): string
    {
        return match ($this) {
            self::Legacy => '老版本接口（/v1 + access-token）',
            self::Dcims => '新版本接口（/api/v1 + AppID 签名）',
        };
    }

    public static function tryFromConfig(mixed $value): ?self
    {
        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            '', 'auto' => null,
            'legacy', 'v1', 'old', '376', '3.7.6' => self::Legacy,
            'dcims', 'v2', 'new', '380', '3.8.0', 'v10' => self::Dcims,
            default => null,
        };
    }
}
