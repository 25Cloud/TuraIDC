<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\MofangCloud\Lib;

use App\Models\Supplier;
use App\Services\Integrations\Plugins\PluginBindingResolver;

/**
 * 从供应商绑定里取出魔方云连接凭据。
 *
 * 平台把上游连接信息统一存在 `supplier_plugin_bindings`：`base_url` / `account_name`
 * 是明文字段，密钥在加密的 `secret_json` 里，额外的非敏感配置（接口代际、是否忽略
 * 证书）在 `config_json` 里。这里只做解析与兜底校验，不发明新的存储位置。
 */
final class CloudCredentialsResolver
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private array $projectionCache = [];

    public function __construct(
        private readonly PluginBindingResolver $bindings,
    ) {}

    public function resolve(Supplier $supplier): CloudCredentials
    {
        $supplierId = (int) $supplier->id;
        if ($supplierId <= 0) {
            throw new CloudApiException('供应商未保存，无法解析魔方云连接信息');
        }

        $projection = $this->projection($supplier);
        $config = is_array($projection['provider_config'] ?? null) ? (array) $projection['provider_config'] : [];

        $baseUrl = rtrim(trim((string) ($projection['base_url'] ?? '')), '/');
        $account = trim((string) ($projection['account_name'] ?? ''));
        $secret = trim((string) ($projection['api_key'] ?? ''));

        if ($baseUrl === '') {
            throw new CloudApiException('魔方云地址未配置，请先在供应商编辑页填写接口地址');
        }

        if ($account === '' || $secret === '') {
            throw new CloudApiException('魔方云凭据不完整：老版本需要账号+密码，新版本需要 AppID+AppSecret');
        }

        return new CloudCredentials(
            supplierId: $supplierId,
            baseUrl: $this->normalizeBaseUrl($baseUrl),
            account: $account,
            secret: $secret,
            mode: CloudApiMode::tryFromConfig($config['api_mode'] ?? null) ?? CloudApiMode::Legacy,
            agentAccount: strtolower(trim((string) ($config['account_type'] ?? ''))) === 'agent',
        );
    }

    /**
     * 显式声明的代际；返回 null 表示「自动探测」。
     */
    public function declaredMode(Supplier $supplier): ?CloudApiMode
    {
        $projection = $this->projection($supplier);
        $config = is_array($projection['provider_config'] ?? null) ? (array) $projection['provider_config'] : [];

        return CloudApiMode::tryFromConfig($config['api_mode'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function projection(Supplier $supplier): array
    {
        $supplierId = (int) $supplier->id;
        if (isset($this->projectionCache[$supplierId])) {
            return $this->projectionCache[$supplierId];
        }

        $projection = $this->bindings->supplierBindingProjection($supplier, includeSecrets: true);
        $this->projectionCache[$supplierId] = $projection;

        return $projection;
    }

    private function normalizeBaseUrl(string $baseUrl): string
    {
        $parts = parse_url($baseUrl);
        if (! is_array($parts) || empty($parts['host'])) {
            throw new CloudApiException('魔方云地址格式不正确，需要形如 https://cloud.example.com');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new CloudApiException('魔方云地址必须以 http:// 或 https:// 开头');
        }

        return rtrim($baseUrl, '/');
    }
}
