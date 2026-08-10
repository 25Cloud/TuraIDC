<?php

declare(strict_types=1);

namespace App\Services\Upstream\Drivers\HostingPanelApi;

use App\Services\Upstream\Contracts\ProvidesConsoleAccess;
use App\Services\Upstream\Contracts\ProvidesConsoleCatalog;
use App\Services\Upstream\Contracts\ProvidesConsoleNetwork;
use App\Services\Upstream\Contracts\ProvidesConsoleRuntime;
use App\Services\Upstream\Contracts\ProvidesConsoleSecurity;
use App\Services\Upstream\Contracts\ProvidesProvisioning;
use App\Services\Upstream\Contracts\ProvidesRenewal;
use App\Services\Upstream\Contracts\ProvidesScheduledAuthRefresh;
use App\Services\Upstream\Contracts\ProvidesStatusSync;
use App\Services\Upstream\Contracts\ProvidesSupplierFormSchema;
use App\Services\Upstream\Contracts\UpstreamDriver;
use App\Services\Upstream\ProviderKey;

final class HostingPanelApiDriver implements ProvidesSupplierFormSchema, UpstreamDriver
{
    private const CAPABILITIES = [
        ProvidesConsoleAccess::class,
        ProvidesConsoleCatalog::class,
        ProvidesConsoleNetwork::class,
        ProvidesConsoleRuntime::class,
        ProvidesConsoleSecurity::class,
        ProvidesProvisioning::class,
        ProvidesRenewal::class,
        ProvidesScheduledAuthRefresh::class,
        ProvidesStatusSync::class,
    ];

    public function __construct(
        private readonly HostingPanelApiTransport $transport,
    ) {}

    public function key(): string
    {
        return ProviderKey::HOSTING_PANEL_API;
    }

    public function label(): string
    {
        return '主机面板接口';
    }

    public function capabilities(): array
    {
        return self::CAPABILITIES;
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, self::CAPABILITIES, true)
            && $this->transport instanceof $capability;
    }

    public function resolve(string $capability): ?object
    {
        return $this->supports($capability) ? $this->transport : null;
    }

    public function supplierFormSchema(): array
    {
        return [
            'help' => '通用主机面板协议需要填写接口根地址、接口账号和密钥。',
            'fields' => [
                [
                    'key' => 'api_url',
                    'label' => '接口地址',
                    'type' => 'url',
                    'required' => true,
                    'placeholder' => 'https://panel.example.com',
                ],
                [
                    'key' => 'api_username',
                    'label' => '接口账号',
                    'type' => 'text',
                    'required' => true,
                ],
                [
                    'key' => 'api_key',
                    'label' => '接口密钥',
                    'type' => 'password',
                    'required' => true,
                    'secret' => true,
                    'placeholder' => '编辑时留空则保持原密钥',
                ],
            ],
        ];
    }
}
