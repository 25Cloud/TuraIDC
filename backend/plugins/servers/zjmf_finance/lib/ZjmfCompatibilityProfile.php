<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\ZjmfFinance\Lib;

use App\Exceptions\BusinessException;
use App\Models\Supplier;

/**
 * 魔方财务上游的版本/接口档位。
 *
 * 背景：魔方财务 3.8.0 把 openapi 模块（/v1/*）整体删掉，并把
 * cart/get_product_config 挂上了「仅下游 API 账号可用」的 UpstreamApiCheck；
 * 有的商家升级后又把这条路由彻底移除。3.7.6 及更早则是 /v1/* 全在、
 * cart/get_product_config 裸奔。同一套对接要同时吃这两种。
 *
 * 因此这里不按「版本号」做分支，而是把差异收敛成两条可配置的能力：
 * - 商品配置接口：api/product/prodetail（新旧都有）还是 cart/get_product_config（老站）
 * - /v1 是否可用（3.8.0 起整套消失）
 *
 * 档位只是给人看的预设；真正跑起来用下面的候选顺序 + 失败回退，
 * 所以站点被魔改、或者版本号填错，也只会多一次探测请求，不会直接挂。
 */
final class ZjmfCompatibilityProfile
{
    public const VERSION_AUTO = 'auto';

    /** 3.7.6 及更早：商品配置吃 cart/get_product_config，/v1 可用 */
    public const VERSION_LEGACY = 'legacy_376';

    /** 3.8.0 及以后：商品配置吃 api/product/prodetail，/v1 视为已移除 */
    public const VERSION_MODERN = 'modern_380';

    public const V1_AUTO = 'auto';

    public const V1_ALWAYS = 'always';

    public const V1_NEVER = 'never';

    public const PRODUCT_CONFIG_PRODETAIL = 'prodetail';

    public const PRODUCT_CONFIG_CART = 'cart';

    public const INVOICE_PAY_APPLY_CREDIT = 'apply_credit';

    public const INVOICE_PAY_V1_FUND = 'v1_fund';

    private function __construct(
        private readonly string $version,
        private readonly string $v1,
    ) {}

    /**
     * 从供应商绑定的 provider_config 读档位；缺省即 auto，保证老记录零迁移。
     */
    public static function fromSupplier(?Supplier $supplier): self
    {
        $config = [];
        if ($supplier instanceof Supplier) {
            $raw = $supplier->provider_config;
            $config = is_array($raw) ? $raw : [];
        }

        return new self(
            self::normalizeVersion($config['version_profile'] ?? null),
            self::normalizeV1($config['use_v1_api'] ?? null),
        );
    }

    public static function fromValues(mixed $version, mixed $v1): self
    {
        return new self(self::normalizeVersion($version), self::normalizeV1($v1));
    }

    public static function normalizeVersion(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));

        return match ($value) {
            self::VERSION_LEGACY => self::VERSION_LEGACY,
            self::VERSION_MODERN => self::VERSION_MODERN,
            default => self::VERSION_AUTO,
        };
    }

    public static function normalizeV1(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));

        return match ($value) {
            self::V1_ALWAYS => self::V1_ALWAYS,
            self::V1_NEVER => self::V1_NEVER,
            default => self::V1_AUTO,
        };
    }

    public function version(): string
    {
        return $this->version;
    }

    public function v1Mode(): string
    {
        return $this->v1;
    }

    /**
     * 商品配置接口的候选顺序。
     *
     * auto 默认先打 api/product/prodetail：它在 3.7.6 → 3.8.0 全版本都注册着，
     * 而 cart/get_product_config 在新版可能 404。明确选了老站的才反过来先试 cart，
     * 因为个别老站的 prodetail 没有 forSync 过滤、返回体更大也更慢。
     *
     * @return list<string>
     */
    public function productConfigOrder(): array
    {
        return $this->version === self::VERSION_LEGACY
            ? [self::PRODUCT_CONFIG_CART, self::PRODUCT_CONFIG_PRODETAIL]
            : [self::PRODUCT_CONFIG_PRODETAIL, self::PRODUCT_CONFIG_CART];
    }

    /**
     * 账单用余额支付的候选顺序。
     *
     * /apply_credit 是购物车结算页那条路由，三个版本都在；/v1/invoices/{id}/fund
     * 只在老版本存在，所以 auto 与 modern 都先走 apply_credit，只有明确选老站
     * 且允许 /v1 时才优先 fund（老版本上 fund 的返回体更完整）。
     *
     * @return list<string>
     */
    public function invoicePayOrder(): array
    {
        if (! $this->allowsV1()) {
            return [self::INVOICE_PAY_APPLY_CREDIT];
        }

        return $this->version === self::VERSION_LEGACY
            ? [self::INVOICE_PAY_V1_FUND, self::INVOICE_PAY_APPLY_CREDIT]
            : [self::INVOICE_PAY_APPLY_CREDIT, self::INVOICE_PAY_V1_FUND];
    }

    /**
     * 是否允许调用 /v1/*。
     *
     * never 明确关掉；always 明确打开（老站必须）；auto 时不预先封死，
     * 让调用点自己 404 后回退——否则「档位填错」会变成功能静默不可用。
     */
    public function allowsV1(): bool
    {
        return match ($this->v1) {
            self::V1_NEVER => false,
            self::V1_ALWAYS => true,
            default => true,
        };
    }

    /**
     * 明确关掉 /v1（或选新版本且不强制）时，给升级类操作一个能落地的提示。
     */
    public function v1UnavailableMessage(string $action): BusinessException
    {
        $hint = $this->v1 === self::V1_NEVER
            ? '当前供应商已设置「不走 /v1」'
            : '魔方财务 3.8.0 起已移除 /v1 接口';

        return new BusinessException(sprintf(
            '%s，无法通过接口%s；请在供应商后台手工操作，或把该供应商的「接口版本」设为 3.7.6 及更早后重试',
            $hint,
            $action
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version_profile' => $this->version,
            'use_v1_api' => $this->v1,
            'product_config_order' => $this->productConfigOrder(),
            'invoice_pay_order' => $this->invoicePayOrder(),
            'allows_v1' => $this->allowsV1(),
        ];
    }
}
