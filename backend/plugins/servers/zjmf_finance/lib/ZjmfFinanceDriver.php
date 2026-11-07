<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\ZjmfFinance\Lib;

use App\Services\Upstream\Contracts\ProvidesBatchStatusSync;
use App\Services\Upstream\Contracts\ProvidesConsoleAccess;
use App\Services\Upstream\Contracts\ProvidesConsoleCatalog;
use App\Services\Upstream\Contracts\ProvidesConsoleNetwork;
use App\Services\Upstream\Contracts\ProvidesConsoleRuntime;
use App\Services\Upstream\Contracts\ProvidesConsoleSecurity;
use App\Services\Upstream\Contracts\ProvidesContextualRenewalRecovery;
use App\Services\Upstream\Contracts\ProvidesHostSuspension;
use App\Services\Upstream\Contracts\ProvidesHostTermination;
use App\Services\Upstream\Contracts\ProvidesInvoiceRenewal;
use App\Services\Upstream\Contracts\ProvidesOrderProvisioning;
use App\Services\Upstream\Contracts\ProvidesProvisioning;
use App\Services\Upstream\Contracts\ProvidesRenewableCycleFiltering;
use App\Services\Upstream\Contracts\ProvidesRenewal;
use App\Services\Upstream\Contracts\ProvidesScheduledAuthRefresh;
use App\Services\Upstream\Contracts\ProvidesStatusSync;
use App\Services\Upstream\Contracts\ProvidesSupplierBalance;
use App\Services\Upstream\Contracts\ProvidesSupplierFormSchema;
use App\Services\Upstream\Contracts\UpstreamDriver;
use App\Services\Upstream\ProviderKey;

final class ZjmfFinanceDriver implements ProvidesSupplierFormSchema, UpstreamDriver
{
    private const CAPABILITIES = [
        ProvidesBatchStatusSync::class,
        ProvidesConsoleAccess::class,
        ProvidesConsoleCatalog::class,
        ProvidesConsoleNetwork::class,
        ProvidesConsoleRuntime::class,
        ProvidesConsoleSecurity::class,
        ProvidesContextualRenewalRecovery::class,
        ProvidesHostSuspension::class,
        ProvidesHostTermination::class,
        ProvidesInvoiceRenewal::class,
        ProvidesOrderProvisioning::class,
        // 基契约必须保留：编排层以 ProvidesProvisioning/ProvidesRenewal 作为能力门槛，
        // 子接口虽然通过继承满足 instanceof，但 supports() 需要显式列出才会命中
        ProvidesProvisioning::class,
        ProvidesRenewal::class,
        ProvidesRenewableCycleFiltering::class,
        ProvidesScheduledAuthRefresh::class,
        ProvidesStatusSync::class,
        ProvidesSupplierBalance::class,
    ];

    public function __construct(
        private readonly ZjmfFinanceAdapter $adapter,
    ) {}

    public function key(): string
    {
        return ProviderKey::ZJMF_FINANCE_API;
    }

    public function label(): string
    {
        return 'ZJMF 财务接口';
    }

    public function capabilities(): array
    {
        return self::CAPABILITIES;
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, self::CAPABILITIES, true)
            && $this->adapter instanceof $capability;
    }

    public function resolve(string $capability): ?object
    {
        return $this->supports($capability) ? $this->adapter : null;
    }

    public function supplierFormSchema(): array
    {
        return [
            'help' => 'ZJMF 财务插件使用供应商后台地址、账号和密码/API 密钥登录并刷新 JWT。'
                .'魔方财务 3.8.0 删除了整套 /v1 接口并把商品配置换了新路由，'
                .'如果供应商升级过，请把「接口版本」选成 3.8.0 及以后；不确定就留「自动探测」。',
            'fields' => [
                [
                    'key' => 'api_url',
                    'label' => 'ZJMF 财务地址',
                    'type' => 'url',
                    'required' => true,
                    'placeholder' => 'https://finance.example.com',
                ],
                [
                    'key' => 'api_username',
                    'label' => '登录账号',
                    'type' => 'text',
                    'required' => true,
                ],
                [
                    'key' => 'api_key',
                    'label' => '登录密码/API 密钥',
                    'type' => 'password',
                    'required' => true,
                    'secret' => true,
                    'placeholder' => '编辑时留空则保持原密钥',
                ],
                [
                    'key' => 'version_profile',
                    'label' => '接口版本',
                    'type' => 'select',
                    'default' => 'auto',
                    'options' => [
                        ['label' => '自动探测（推荐）', 'value' => 'auto'],
                        ['label' => '3.7.6 及更早（老版本）', 'value' => 'legacy_376'],
                        ['label' => '3.8.0 及以后（新版本）', 'value' => 'modern_380'],
                    ],
                    'description' => '只影响优先尝试哪个接口：老版本商品配置走 cart/get_product_config，'
                        .'3.8.0 起走 api/product/prodetail。选错只会多一次探测请求，不会直接失败。',
                ],
                [
                    'key' => 'use_v1_api',
                    'label' => '走 /v1 接口',
                    'type' => 'select',
                    'default' => 'auto',
                    'options' => [
                        ['label' => '自动（按版本推导）', 'value' => 'auto'],
                        ['label' => '强制开启', 'value' => 'always'],
                        ['label' => '关闭（3.8.0 及以上建议）', 'value' => 'never'],
                    ],
                    'description' => '3.8.0 起魔方财务删除了 /v1/invoices、/v1/hosts 等接口。'
                        .'关闭后升级配置/升级套餐会在本地直接提示，不再打出必然 404 的请求。',
                ],
            ],
        ];
    }
}
