<?php

declare(strict_types=1);

namespace App\Services\Upstream;

final class ProviderKey
{
    public const HOSTING_PANEL_API = 'hosting_panel_api';

    public const ZJMF_FINANCE_API = 'zjmf_finance_api';

    /**
     * 魔方云（DCIMS）直连上游：老版 /v1 与新版 /api/v1 两代接口共用该 key，
     * 代际由供应商配置里的接口代际决定，不按 key 区分为两个 provider。
     */
    public const MOFANG_CLOUD_API = 'mofang_cloud_api';

    /**
     * 兜底展示名：仅翻译没有独立驱动类的历史 key。
     *
     * 注意：有驱动类（如 zjmf_finance_api、demo_servers）的 provider 一律展示
     * 驱动自身的 label()，这里不得重复维护翻译——否则 ProviderRegistry 导出的
     * 元数据会出现两套不一致的展示名（由 ZjmfServiceProviderTest 锁定）。
     */
    public static function label(string $key): string
    {
        return match ($key) {
            self::HOSTING_PANEL_API => '主机面板接口',
            default => $key,
        };
    }
}
