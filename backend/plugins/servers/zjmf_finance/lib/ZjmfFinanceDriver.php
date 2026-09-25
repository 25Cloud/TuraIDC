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
use App\Services\Upstream\Contracts\ProvidesRenewal;
use App\Services\Upstream\Contracts\ProvidesRenewableCycleFiltering;
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
            'help' => 'ZJMF 财务插件使用供应商后台地址、账号和密码/API 密钥登录并刷新 JWT。',
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
            ],
        ];
    }
}
