<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\MofangCloud\Logic;

use App\Exceptions\BusinessException;
use App\Models\Supplier;
use App\Services\Upstream\ProviderKey;
use Illuminate\Contracts\Container\Container;
use Throwable;
use TuraIDC\Plugins\Servers\MofangCloud\Lib\CloudApiMode;
use TuraIDC\Plugins\Servers\MofangCloud\Lib\CloudClientFactory;
use TuraIDC\Plugins\Servers\MofangCloud\Lib\CloudCredentialsResolver;
use TuraIDC\Plugins\Servers\MofangCloud\Lib\MofangCloudAdapter;

/**
 * 魔方云上游插件入口。
 *
 * 这里是插件运行时与平台之间的接缝：`config.php` 声明能力清单，
 * 平台通过 `server.resolve_capability` 拿到 {@see MofangCloudAdapter} 实例。
 *
 * 能力清单只放已实现且行为确定的部分——没把握的宁可不声明，也不要让平台
 * 回退到通用的 `/v1/hosts/...` REST 路径，那对魔方云是错的端点。
 */
final class MofangCloud
{
    /**
     * 能力清单从插件清单读取后缓存在这里，避免每次请求都 require 一遍配置。
     *
     * @var array<int, class-string>|null
     */
    private static ?array $capabilities = null;

    private ?MofangCloudAdapter $adapter = null;

    public function __construct(
        private readonly Container $container,
    ) {}

    public function key(): string
    {
        return ProviderKey::MOFANG_CLOUD_API;
    }

    public function label(): string
    {
        return '魔方云接口';
    }

    /**
     * @return array<int, class-string>
     */
    public function capabilities(): array
    {
        if (self::$capabilities === null) {
            // 能力清单唯一真源在插件清单 config.php，这里不维护第二份。
            $config = require dirname(__DIR__).'/config.php';
            $declared = (array) ($config['info']['capabilities'] ?? []);

            self::$capabilities = array_values(array_filter(
                $declared,
                static fn (mixed $capability): bool => is_string($capability) && $capability !== ''
            ));
        }

        return self::$capabilities;
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    public function resolve(string $capability): ?object
    {
        return $this->supports($capability) ? $this->adapter() : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function supplierFormSchema(): array
    {
        return [
            'help' => '魔方云对外有两代完全不同的接口，必须先分清再填：'
                .'3.7.6 及更早的魔方云走老版 /v1（账号 + 密码换 access-token）；'
                .'新版魔方云走 /api/v1（AppID + AppSecret 请求签名）。'
                .'两代端点、鉴权、报文编码互不兼容，选错只会得到 404 或验签失败。'
                .'不确定就先留「自动分辨」。',
            'fields' => [
                [
                    'key' => 'api_url',
                    'label' => '魔方云地址',
                    'type' => 'url',
                    'required' => true,
                    'placeholder' => 'https://cloud.example.com',
                    'description' => '填魔方云站点根地址，不要带 /v1 或 /api/v1 后缀。',
                ],
                [
                    'key' => 'api_username',
                    'label' => '账号 / AppID',
                    'type' => 'text',
                    'required' => true,
                    'description' => '老版本填魔方云登录账号；新版本填开放接口的 AppID。',
                ],
                [
                    'key' => 'api_key',
                    'label' => '密码 / AppSecret',
                    'type' => 'password',
                    'required' => true,
                    'secret' => true,
                    'placeholder' => '编辑时留空则保持原密钥',
                    'description' => '老版本填登录密码；新版本填与 AppID 配对的 AppSecret。',
                ],
                [
                    'key' => 'api_mode',
                    'label' => '接口代际',
                    'type' => 'select',
                    'default' => 'auto',
                    'options' => [
                        ['label' => '自动分辨（推荐）', 'value' => 'auto'],
                        ['label' => '老版本 /v1（3.7.6 及更早）', 'value' => 'legacy'],
                        ['label' => '新版本 /api/v1（3.8.0 及以后）', 'value' => 'dcims'],
                    ],
                    'description' => '自动分辨会先按新版本探活、再按老版本登录试探，结果缓存 1 小时；'
                        .'已知上游版本时直接指定，可以省掉探测这一跳。',
                ],
                [
                    'key' => 'account_type',
                    'label' => '老版本账号类型',
                    'type' => 'select',
                    'default' => 'admin',
                    'options' => [
                        ['label' => '普通账号（面板账号）', 'value' => 'admin'],
                        ['label' => '代理账号（代理商入口）', 'value' => 'agent'],
                    ],
                    'description' => '仅老版本接口有效：代理账号走 index.php?path=… 取 token。新版本接口忽略此项。',
                ],
                [
                    'key' => 'user_prefix',
                    'label' => '上游客户名前缀',
                    'type' => 'text',
                    'default' => 'turaidc_',
                    'description' => '开通时上游客户用户名 = 该前缀 + 本地用户 ID（与魔方财务的 user_prefix 同义）。'
                        .'同一个魔方云面板被多个系统共用时靠它隔离命名空间，留空则直接用本地用户 ID。',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function execute(array $request): array
    {
        $action = trim((string) ($request['action'] ?? ''));
        $payload = is_array($request['payload'] ?? null) ? (array) $request['payload'] : [];

        return match ($action) {
            'server.metadata' => [
                'success' => true,
                'action' => $action,
                'data' => [
                    'key' => $this->key(),
                    'label' => $this->label(),
                    'capabilities' => $this->capabilities(),
                ],
            ],
            'server.supports' => [
                'success' => true,
                'action' => $action,
                'data' => ['supported' => $this->supports((string) ($payload['capability'] ?? ''))],
            ],
            'server.resolve_capability' => [
                'success' => true,
                'action' => $action,
                'data' => ['resolved' => $this->resolve((string) ($payload['capability'] ?? ''))],
            ],
            'server.supplier_form_schema' => [
                'success' => true,
                'action' => $action,
                'data' => $this->supplierFormSchema(),
            ],
            'server.supplier.refresh_card' => $this->refreshCard($action, $request),
            default => [
                'success' => false,
                'action' => $action,
                'message' => 'Unsupported plugin action',
                'data' => [],
            ],
        };
    }

    /**
     * 供应商卡片：把「接口代际分辨」的结果直接摆在管理员眼前。
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function renderCard(Supplier $supplier, array $context = []): array
    {
        $diagnosis = $this->diagnose($supplier);
        $mode = trim((string) ($diagnosis['mode'] ?? ''));

        return [
            'title' => trim((string) ($supplier->name ?? '')) ?: $this->label(),
            'subtitle' => $this->label(),
            'status' => [
                'label' => ($diagnosis['ok'] ?? false) ? '连接正常' : '连接异常',
                'theme' => ($diagnosis['ok'] ?? false) ? 'success' : 'danger',
                'variant' => 'light',
            ],
            'fields' => [
                [
                    'key' => 'base_url',
                    'label' => '接口地址',
                    'value' => $this->baseUrlOf($supplier),
                ],
                [
                    'key' => 'api_mode',
                    'label' => '接口代际',
                    'value' => $mode !== '' ? $this->modeLabel($mode) : '未识别',
                ],
                [
                    'key' => 'checked_at',
                    'label' => '最近检测',
                    'value' => now()->format('Y-m-d H:i:s'),
                ],
            ],
            'message' => (string) ($diagnosis['message'] ?? ''),
            'actions' => [
                [
                    'key' => 'refresh_card',
                    'label' => '测试连接',
                    'action' => 'supplier.remote_metric.refresh',
                    'request_action' => 'server.supplier.refresh_card',
                    'theme' => 'primary',
                    'variant' => 'text',
                    'disabled' => false,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function healthCheck(): array
    {
        return [
            'healthy' => true,
            'message' => '魔方云插件加载正常',
            'details' => [
                'capabilities' => $this->capabilities(),
                'modes' => ['legacy', 'dcims'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function refreshCard(string $action, array $request): array
    {
        $supplier = $request['context']['supplier'] ?? null;
        if (! $supplier instanceof Supplier) {
            throw new BusinessException('供应商上下文缺失，无法检测魔方云连接', 42200);
        }

        $diagnosis = $this->diagnose($supplier);

        return [
            'success' => true,
            'action' => $action,
            'message' => (string) ($diagnosis['message'] ?? ''),
            'data' => [
                'remote' => $diagnosis,
                'card' => $this->renderCard($supplier),
            ],
        ];
    }

    /**
     * 诊断不抛异常：卡片要能把「两种代际都连不上」的原因显示出来。
     *
     * @return array{mode:?string,ok:bool,message:string,probe:array<string, string>}
     */
    private function diagnose(Supplier $supplier): array
    {
        try {
            return $this->container->make(CloudClientFactory::class)->diagnose($supplier);
        } catch (Throwable $exception) {
            return [
                'mode' => null,
                'ok' => false,
                'message' => $exception->getMessage(),
                'probe' => [],
            ];
        }
    }

    private function baseUrlOf(Supplier $supplier): string
    {
        try {
            return $this->container->make(CloudCredentialsResolver::class)->resolve($supplier)->baseUrl;
        } catch (Throwable) {
            return '-';
        }
    }

    private function modeLabel(string $mode): string
    {
        $resolved = CloudApiMode::tryFrom($mode);

        return $resolved instanceof CloudApiMode ? $resolved->label() : $mode;
    }

    private function adapter(): MofangCloudAdapter
    {
        return $this->adapter ??= $this->container->make(MofangCloudAdapter::class);
    }
}
