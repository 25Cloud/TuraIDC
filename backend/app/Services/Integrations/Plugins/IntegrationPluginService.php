<?php

declare(strict_types=1);

namespace App\Services\Integrations\Plugins;

use App\Exceptions\BusinessException;
use App\Models\AdminUser;
use App\Models\IntegrationPlugin;
use App\Services\Auth\GeeTestService;
use App\Services\Integrations\Payments\PaymentGatewayOperationService;
use App\Services\Mail\MailDriverManager;
use App\Services\Sms\Data\SmsSendRequest;
use App\Services\Sms\SmsDriverManager;
use App\Services\System\NotificationService;
use App\Services\System\SmsService;
use App\Support\PublicUrl;
use App\Support\SmsTemplateCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class IntegrationPluginService
{
    /** 卸载受阻提示里用的引用表中文名 */
    private const REFERENCE_TABLE_LABELS = [
        'integration_plugin_bindings' => '插件绑定',
        'supplier_plugin_bindings' => '供应商绑定',
        'product_upstream_bindings' => '商品上游绑定',
        'service_upstream_bindings' => '服务上游绑定',
        'service_runtime_snapshots' => '服务运行快照',
        'service_connection_snapshots' => '服务连接快照',
        'service_provision_attempts' => '开通记录',
        'integration_plugin_runtime_logs' => '插件运行日志',
        'payments' => '支付记录',
        'payment_callbacks' => '支付回调',
        'gateway_logs' => '网关日志',
        'message_logs' => '消息记录',
    ];

    public function __construct(
        private readonly PluginScanner $scanner,
        private readonly PluginInstaller $installer,
        private readonly PluginConfigRepository $configRepository,
        private readonly PluginRuntimeRegistry $runtimeRegistry,
        private readonly GeeTestService $geeTestService,
        private readonly PaymentGatewayOperationService $paymentGatewayOperationService,
        private readonly MailDriverManager $mailDriverManager,
        private readonly SmsDriverManager $smsDriverManager,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(?string $domain = null): array
    {
        $scannedManifests = $this->scanner->scan($domain);
        $manifests = array_values(array_filter(
            $scannedManifests,
            fn (PluginManifest $manifest): bool => ! $this->isDemoManifest($manifest),
        ));
        $installedMap = $this->installedPluginMap($domain);
        $enabledPluginMap = $this->enabledPluginMap($domain);
        $installedPlugins = array_values($installedMap);
        $referenceCountsByPluginId = $this->pluginReferenceCountsByPlugin($installedPlugins);
        $latestRuntimeLogsByPluginId = $this->latestRuntimeLogsByPlugin($installedPlugins);

        // 已在文件系统中找到的 domain:slug 键集合。
        // 必须基于未过滤 demo 的扫描结果，否则已安装的 demo 插件会被误判成“文件已丢失”。
        $scannedKeys = array_flip(array_map(
            fn (PluginManifest $m): string => $m->domain.':'.$m->slug,
            $scannedManifests,
        ));

        $items = array_map(
            function (PluginManifest $manifest) use (
                $installedMap,
                $enabledPluginMap,
                $referenceCountsByPluginId,
                $latestRuntimeLogsByPluginId,
            ): array {
                return $this->manifestPayload(
                    $manifest,
                    $installedMap[$manifest->domain.':'.$manifest->slug] ?? null,
                    $enabledPluginMap[$manifest->domain] ?? null,
                    $referenceCountsByPluginId,
                    $latestRuntimeLogsByPluginId,
                );
            },
            $manifests,
        );

        // 将已安装但文件目录已丢失的插件追加到列表末尾，带 manifest_missing 标志
        foreach ($installedMap as $key => $plugin) {
            if (! isset($scannedKeys[$key])) {
                $items[] = $this->missingManifestPayload(
                    $plugin,
                    $enabledPluginMap[(string) $plugin->domain] ?? null,
                    $referenceCountsByPluginId,
                    $latestRuntimeLogsByPluginId,
                );
            }
        }

        return $items;
    }

    public function install(string $domain, string $slug): array
    {
        // demo 插件不在管理端列表展示，也不允许通过管理端安装，
        // 否则会产生“已安装但列表不可见”的状态，无法再停用或卸载。
        if ($this->isDemoManifest($this->scanner->requireManifest($domain, $slug))) {
            throw new BusinessException('示例插件仅供开发调试，不支持在管理端安装', 42200);
        }

        $plugin = $this->installer->install($domain, $slug);

        return $this->detail($plugin);
    }

    public function detail(IntegrationPlugin $plugin): array
    {
        $currentPlugin = $plugin->fresh('config') ?? $plugin;
        $manifest = $this->scanner->find((string) $currentPlugin->domain, (string) $currentPlugin->slug);
        if (! $manifest instanceof PluginManifest) {
            $payload = $this->missingManifestPayload($currentPlugin);
            $displayConfig = $this->configRepository->displayConfig($currentPlugin);
            $payload['config'] = $displayConfig['config'];
            $payload['has_secret_values'] = $displayConfig['has_secret_values'];
            $payload['secret_previews'] = [];

            return $payload;
        }

        $payload = $this->manifestPayload($manifest, $currentPlugin);
        $displayConfig = $this->configRepository->displayConfig($currentPlugin);

        $payload['config'] = $displayConfig['config'];
        $payload['has_secret_values'] = $displayConfig['has_secret_values'];
        $payload['secret_previews'] = $this->configRepository->secretPreviews($currentPlugin, $manifest);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function updateConfig(IntegrationPlugin $plugin, array $config, ?AdminUser $admin = null): array
    {
        $manifest = $this->scanner->requireManifest((string) $plugin->domain, (string) $plugin->slug);
        $saved = $this->configRepository->save($plugin, $manifest, $config, $admin);

        $payload = $this->detail($plugin->fresh('config') ?? $plugin);
        $payload['config'] = $saved['config'];
        $payload['has_secret_values'] = $saved['has_secret_values'];

        return $payload;
    }

    public function revealConfigSecret(IntegrationPlugin $plugin, string $key): array
    {
        $manifest = $this->scanner->requireManifest((string) $plugin->domain, (string) $plugin->slug);

        return $this->configRepository->revealSecret($plugin, $manifest, $key);
    }

    public function enable(IntegrationPlugin $plugin): array
    {
        return $this->detail($this->installer->enable($plugin));
    }

    public function disable(IntegrationPlugin $plugin): array
    {
        return $this->detail($this->installer->disable($plugin));
    }

    /**
     * @return array<string, mixed>
     */
    public function uninstall(IntegrationPlugin $plugin, bool $force = false): array
    {
        if (! $force) {
            $this->assertUninstallable($plugin);
        }

        $result = [
            'deleted' => false,
            'plugin_id' => (int) $plugin->id,
            'plugin' => null,
        ];

        DB::transaction(function () use ($plugin, &$result): void {
            if ($plugin->isEnabled()) {
                $this->installer->disable($plugin);
            }
            $this->deleteUpstreamRuntimeBindings($plugin);
            $plugin->bindings()->delete();
            $plugin->supplierBindings()->delete();
            $plugin->config()->delete();
            $plugin->delete();
            $result['deleted'] = true;
        });

        return $result;
    }

    /**
     * 检测已安装插件中哪些文件目录已丢失。
     * 返回 'domain/slug' => true 的映射，供列表接口附加 manifest_missing 标志。
     *
     * @return array<string, bool>
     */
    public function detectMissingManifests(): array
    {
        if (! Schema::hasTable('integration_plugins')) {
            return [];
        }

        $missing = [];

        IntegrationPlugin::query()->get()->each(function (IntegrationPlugin $plugin) use (&$missing): void {
            $manifest = $this->scanner->find((string) $plugin->domain, (string) $plugin->slug);
            if (! $manifest instanceof PluginManifest) {
                $missing["{$plugin->domain}/{$plugin->slug}"] = true;
            }
        });

        return $missing;
    }

    public function healthCheck(IntegrationPlugin $plugin): array
    {
        if (! Schema::hasTable('integration_plugins')) {
            throw new BusinessException('插件系统尚未初始化', 42200);
        }

        $manifest = $this->scanner->requireManifest((string) $plugin->domain, (string) $plugin->slug);
        $this->configRepository->assertConfigReady($plugin, $manifest);

        return $this->runtimeRegistry->healthCheck($plugin);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function testEmail(IntegrationPlugin $plugin, array $payload): array
    {
        $this->assertTestDomain($plugin, PluginDomain::MAIL, '邮件发送');

        $testPayload = $this->verificationEmailPayload($payload);
        $driver = $this->mailDriverManager->resolve((string) $plugin->plugin_key);
        $driver->sendHtml(
            to: (string) $testPayload['to'],
            subject: (string) $testPayload['subject'],
            html: (string) $testPayload['html'],
            context: array_merge((array) ($testPayload['context'] ?? []), [
                'test' => true,
                'account_index' => (int) ($testPayload['account_index'] ?? 0),
            ]),
        );

        // 测试发信走驱动直发，绕过了「站点设置 → 通知设置 → 启用邮件通知」总开关，
        // 因此必须回报总开关状态：否则总开关为关（默认值）时，管理员看到
        // 「测试邮件发送成功」会误判为对外已可用，而注册/找回密码等对外发码
        // 仍会返回「邮件服务暂不可用」。判定复用 NotificationService 的唯一真源。
        $notificationEnabled = app(NotificationService::class)->isEmailEnabled();

        return [
            'success' => true,
            'action' => 'mail.test_smtp',
            'message' => $notificationEnabled
                ? '测试邮件发送成功'
                : '测试邮件发送成功，但站点设置中的「启用邮件通知」当前为关闭状态，对外仍不会发送邮件（注册、找回密码等会提示「邮件服务暂不可用」）。请在 站点设置 → 通知设置 中开启后再验证。',
            'data' => [
                'sent' => true,
                'notification_enabled' => $notificationEnabled,
                'to' => $testPayload['to'],
                'subject' => $testPayload['subject'],
                'template_code' => $testPayload['template_code'],
                'account_index' => (int) ($testPayload['account_index'] ?? 0),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function testSms(IntegrationPlugin $plugin, array $payload): array
    {
        $this->assertTestDomain($plugin, PluginDomain::SMS, '短信发送');

        $testPayload = $this->verificationSmsPayload($payload);
        $driver = $this->smsDriverManager->resolve((string) $plugin->plugin_key);
        $result = $driver->sendVerifyCode(new SmsSendRequest(
            phone: (string) $testPayload['phone'],
            code: (string) $testPayload['code'],
            options: (array) ($testPayload['options'] ?? []),
        ));

        // 同邮件：测试发信绕过「启用短信通知」总开关，需回报其状态避免误判。
        $notificationEnabled = app(SmsService::class)->isEnabled();

        return [
            'success' => true,
            'action' => 'sms.test',
            'message' => $notificationEnabled
                ? '测试短信发送成功'
                : '测试短信发送成功，但站点设置中的「启用短信通知」当前为关闭状态，对外仍不会发送短信。请在 站点设置 → 通知设置 中开启后再验证。',
            'data' => [
                'sent' => true,
                'notification_enabled' => $notificationEnabled,
                'phone' => $testPayload['phone'],
                'status' => $result->status,
                'template_code' => $testPayload['template_code'],
                'request_id' => $result->requestId,
                'template_params' => $result->templateParams,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function verificationEmailPayload(array $payload): array
    {
        $code = $this->verificationCode();
        $expireMinutes = '10';
        $templateCode = NotificationService::TEMPLATE_EMAIL_CODE;
        $body = "您的邮箱验证码为：{$code}，{$expireMinutes}分钟内有效。如非本人操作，请忽略此邮件。";
        $context = is_array($payload['context'] ?? null) ? $payload['context'] : [];

        return array_merge($payload, [
            'subject' => '邮箱验证码',
            'body' => $body,
            'html' => $body,
            'template_code' => $templateCode,
            'code' => $code,
            'context' => array_merge($context, [
                'template_code' => $templateCode,
                'code' => $code,
                'expire_minutes' => $expireMinutes,
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function verificationSmsPayload(array $payload): array
    {
        $code = $this->verificationCode();
        $options = is_array($payload['options'] ?? null) ? $payload['options'] : [];
        $options['template_code'] = SmsTemplateCatalog::TEMPLATE_VERIFY_CODE;

        return array_merge($payload, [
            'template_code' => SmsTemplateCatalog::TEMPLATE_VERIFY_CODE,
            'code' => $code,
            'options' => $options,
        ]);
    }

    /**
     * 实名域测试：按插件流程真实创建一次认证任务。
     * 测试结果只返回给管理员，不落库、不关联任何用户。
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function testVerification(IntegrationPlugin $plugin, array $payload): array
    {
        $this->assertTestDomain($plugin, PluginDomain::VERIFICATION, '实名认证');

        $result = $this->runtimeRegistry->execute(
            domain: (string) $plugin->domain,
            slugOrKey: (string) $plugin->slug,
            action: 'certification.initialize',
            payload: [
                'real_name' => (string) ($payload['real_name'] ?? ''),
                'id_card' => (string) ($payload['card_no'] ?? ''),
                'cert_type' => 'IDENTITY_CARD',
                'return_url' => PublicUrl::api('/api/v2/client/verification/callback'),
            ],
        );

        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $status = (int) ($data['status'] ?? 0);
        $success = $status === 200;

        return [
            'success' => $success,
            'action' => 'certification.initialize',
            'message' => $success
                ? (string) ($result['message'] ?? '测试任务创建成功')
                : (string) ($data['message'] ?? $result['message'] ?? '测试任务创建失败'),
            'data' => $data,
        ];
    }

    /**
     * 支付域测试：按插件流程预创建一笔 0.01 元测试支付单。
     * 只返回支付链接，不写入系统订单表。
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function testPayment(IntegrationPlugin $plugin, array $payload): array
    {
        $this->assertTestDomain($plugin, PluginDomain::PAYMENT, '支付渠道');

        $outTradeNo = 'TEST'.date('YmdHis').substr(bin2hex(random_bytes(4)), 0, 6);
        $result = $this->paymentGatewayOperationService->precreate(
            gateway: (string) ($plugin->plugin_key ?? $plugin->key),
            outTradeNo: $outTradeNo,
            amount: 0.01,
            subject: '插件测试订单',
            timeoutExpress: '10m',
        );

        return [
            'success' => true,
            'action' => 'payment.test',
            'message' => '测试支付单创建成功，请扫描二维码完成测试',
            'data' => $result,
        ];
    }

    /**
     * 人机验证域测试：管理员在管理端完成验证码交互后提交结果，走完整验证链路。
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function testCaptcha(IntegrationPlugin $plugin, array $payload): array
    {
        $this->assertTestDomain($plugin, PluginDomain::CAPTCHA, '人机验证');

        if (! $this->geeTestService->isEnabled()) {
            return [
                'success' => false,
                'action' => 'captcha.test',
                'message' => '人机验证插件未完成配置（captcha_id 或 captcha_key 缺失），无法执行测试',
                'data' => ['verified' => false],
            ];
        }

        $result = $this->geeTestService->verify($payload);
        $ok = (bool) ($result['ok'] ?? false);
        if (! $ok) {
            return [
                'success' => false,
                'action' => 'captcha.test',
                'message' => (string) ($result['message'] ?? '行为验证未通过'),
                'data' => ['verified' => false],
            ];
        }

        return [
            'success' => true,
            'action' => 'captcha.test',
            'message' => '行为验证通过',
            'data' => ['verified' => true],
        ];
    }

    /**
     * 上游域测试：解析插件声明的第一个能力，验证插件可加载且能力可解析。
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function testConnection(IntegrationPlugin $plugin, array $payload): array
    {
        $this->assertTestDomain($plugin, PluginDomain::UPSTREAM, '上游开通');

        $manifest = $this->scanner->find((string) $plugin->domain, (string) $plugin->slug);
        $capabilities = array_values(array_filter(
            (array) ($manifest?->capabilities ?? []),
            static fn (mixed $value): bool => is_string($value) && trim($value) !== ''
        ));

        if ($capabilities === []) {
            return [
                'success' => false,
                'action' => 'upstream.test',
                'message' => '插件未声明任何能力，无法执行连接测试',
                'data' => ['healthy' => false],
            ];
        }

        $capability = $capabilities[0];
        $result = $this->runtimeRegistry->execute(
            domain: (string) $plugin->domain,
            slugOrKey: (string) $plugin->slug,
            action: 'server.resolve_capability',
            payload: ['capability' => $capability],
        );

        $resolved = $result['data']['resolved'] ?? null;
        if (! $result['success'] || ! is_object($resolved)) {
            return [
                'success' => false,
                'action' => 'upstream.test',
                'message' => (string) ($result['message'] ?? '插件能力解析失败'),
                'data' => ['healthy' => false, 'capability' => $capability],
            ];
        }

        return [
            'success' => true,
            'action' => 'upstream.test',
            'message' => '插件加载正常，能力解析成功',
            'data' => ['healthy' => true, 'capability' => $capability],
        ];
    }

    /**
     * @throws BusinessException
     */
    private function assertTestDomain(IntegrationPlugin $plugin, string $expectedDomain, string $label): void
    {
        if (! Schema::hasTable('integration_plugins')) {
            throw new BusinessException('插件系统尚未初始化', 42200);
        }

        if ((string) $plugin->domain !== $expectedDomain) {
            throw new BusinessException("该测试类型仅适用于{$label}插件", 42200);
        }

        if (! $plugin->isEnabled()) {
            throw new BusinessException('插件未启用，无法执行测试', 42200);
        }
    }

    private function verificationCode(): string
    {
        return (string) random_int(100000, 999999);
    }

    /**
     * @return array<string, IntegrationPlugin>
     */
    private function installedPluginMap(?string $domain = null): array
    {
        if (! Schema::hasTable('integration_plugins')) {
            return [];
        }

        return IntegrationPlugin::query()
            ->when($domain !== null && trim($domain) !== '', fn ($query) => $query->where('domain', trim($domain)))
            ->get()
            ->keyBy(fn (IntegrationPlugin $plugin): string => $plugin->domain.':'.$plugin->slug)
            ->all();
    }

    /**
     * @return array<string, IntegrationPlugin>
     */
    private function enabledPluginMap(?string $domain = null): array
    {
        if (! Schema::hasTable('integration_plugins')) {
            return [];
        }

        return IntegrationPlugin::query()
            ->where('status', IntegrationPlugin::STATUS_ENABLED)
            ->when($domain !== null && trim($domain) !== '', fn ($query) => $query->where('domain', trim($domain)))
            ->orderBy('id')
            ->get()
            ->groupBy(fn (IntegrationPlugin $plugin): string => (string) $plugin->domain)
            ->map(fn ($plugins): IntegrationPlugin => $plugins->first())
            ->all();
    }

    private function manifestPayload(
        PluginManifest $manifest,
        ?IntegrationPlugin $plugin = null,
        ?IntegrationPlugin $enabledPlugin = null,
        array $referenceCountsByPluginId = [],
        array $latestRuntimeLogsByPluginId = [],
    ): array {
        $referenceCounts = $this->resolvedPluginReferenceCounts($plugin, $referenceCountsByPluginId);
        $enableDisabledReason = $this->enableDisabledReason($manifest->domain, $plugin, $enabledPlugin);

        return [
            'id' => $plugin?->id,
            'domain' => $manifest->domain,
            'slug' => $manifest->slug,
            'key' => $manifest->key,
            'name' => $manifest->name,
            'version' => $manifest->version,
            'entry_class' => $manifest->entryClass,
            'provider_class' => $manifest->providerClass,
            'capabilities' => $manifest->capabilities,
            'config_schema' => $manifest->configSchema,
            'base_path' => $manifest->basePath,
            'is_installed' => $plugin instanceof IntegrationPlugin,
            'is_enabled' => $plugin?->isEnabled() ?? false,
            'can_enable' => $plugin instanceof IntegrationPlugin && ! $plugin->isEnabled() && $enableDisabledReason === null,
            'enable_disabled_reason' => $enableDisabledReason,
            'status' => (int) ($plugin?->status ?? IntegrationPlugin::STATUS_DISABLED),
            'installed_at' => $plugin?->installed_at?->format('Y-m-d H:i:s'),
            'updated_at' => $plugin?->updated_at?->format('Y-m-d H:i:s'),
            'binding_counts' => $referenceCounts,
            'business_reference_count' => array_sum($referenceCounts),
            'latest_runtime_log' => $this->resolvedLatestRuntimeLog($plugin, $latestRuntimeLogsByPluginId),
            'manifest_missing' => false,
        ];
    }

    /**
     * 为文件目录已丢失但仍在数据库中的插件生成列表条目。
     *
     * @return array<string, mixed>
     */
    private function missingManifestPayload(
        IntegrationPlugin $plugin,
        ?IntegrationPlugin $enabledPlugin = null,
        array $referenceCountsByPluginId = [],
        array $latestRuntimeLogsByPluginId = [],
    ): array {
        $referenceCounts = $this->resolvedPluginReferenceCounts($plugin, $referenceCountsByPluginId);
        $enableDisabledReason = $this->enableDisabledReason((string) $plugin->domain, $plugin, $enabledPlugin);

        return [
            'id' => $plugin->id,
            'domain' => $plugin->domain,
            'slug' => $plugin->slug,
            'key' => $plugin->plugin_key,
            'name' => $plugin->name,
            'version' => $plugin->version,
            'entry_class' => $plugin->entry_class,
            'provider_class' => $plugin->provider_class,
            'capabilities' => $plugin->capabilities_json ?? [],
            'config_schema' => [],
            'base_path' => null,
            'is_installed' => true,
            'is_enabled' => $plugin->isEnabled(),
            'can_enable' => ! $plugin->isEnabled() && $enableDisabledReason === null,
            'enable_disabled_reason' => $enableDisabledReason,
            'status' => (int) ($plugin->status ?? IntegrationPlugin::STATUS_DISABLED),
            'installed_at' => $plugin->installed_at?->format('Y-m-d H:i:s'),
            'updated_at' => $plugin->updated_at?->format('Y-m-d H:i:s'),
            'binding_counts' => $referenceCounts,
            'business_reference_count' => array_sum($referenceCounts),
            'latest_runtime_log' => $this->resolvedLatestRuntimeLog($plugin, $latestRuntimeLogsByPluginId),
            'manifest_missing' => true,
        ];
    }

    private function isDemoManifest(PluginManifest $manifest): bool
    {
        foreach ([$manifest->slug, $manifest->key] as $identifier) {
            $normalized = strtolower(trim($identifier));
            if ($normalized === 'demo' || str_starts_with($normalized, 'demo_') || str_starts_with($normalized, 'demo-')) {
                return true;
            }
        }

        return str_starts_with(strtolower(trim($manifest->name)), 'demo ');
    }

    private function enableDisabledReason(string $domain, ?IntegrationPlugin $plugin, ?IntegrationPlugin $enabledPlugin): ?string
    {
        if (! $plugin instanceof IntegrationPlugin || $plugin->isEnabled()) {
            return null;
        }

        if (! PluginDomain::requiresSingleEnabledPlugin($domain)) {
            return null;
        }

        if (! $enabledPlugin instanceof IntegrationPlugin || (int) $enabledPlugin->id === (int) $plugin->id) {
            return null;
        }

        return PluginInstaller::singleEnabledDomainMessage($enabledPlugin);
    }

    /**
     * 卸载会硬删绑定关系，历史支付记录的 plugin_id 也会被外键置空（payments_plugin_fk 为 nullOnDelete），
     * 属于不可逆操作。存在任何业务引用时要求管理端显式确认后再走强制卸载。
     */
    private function assertUninstallable(IntegrationPlugin $plugin): void
    {
        $counts = $this->pluginReferenceCounts($plugin, $this->pluginReferenceTables());
        if ($counts === []) {
            return;
        }

        throw new BusinessException($this->uninstallBlockedMessage($counts), 42200);
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function uninstallBlockedMessage(array $counts): string
    {
        $labels = self::REFERENCE_TABLE_LABELS;
        $details = [];

        foreach ($counts as $table => $count) {
            $details[] = ($labels[$table] ?? $table)." {$count} 条";
        }

        return '插件仍被业务数据引用（'.implode('、', $details).'），卸载会删除这些绑定关系且无法恢复，请确认后再强制卸载';
    }

    /**
     * @param  array<int, string>  $tables
     * @return array<string, int>
     */
    private function pluginReferenceCounts(?IntegrationPlugin $plugin, array $tables): array
    {
        $pluginId = (int) ($plugin?->id ?? 0);
        if ($pluginId <= 0) {
            return [];
        }

        $counts = [];
        foreach ($this->availableTables($tables) as $table) {
            $count = DB::table($table)->where('plugin_id', $pluginId)->count();
            if ($count > 0) {
                $counts[$table] = (int) $count;
            }
        }

        return $counts;
    }

    /**
     * 列表和详情只统计绑定表：这些表小、有索引，且是“插件被哪些业务场景选用”的真实答案。
     * payments / gateway_logs / message_logs 这类历史大表只在卸载校验时才统计。
     *
     * @param  array<int, IntegrationPlugin>  $plugins
     * @return array<int, array<string, int>>
     */
    private function pluginReferenceCountsByPlugin(array $plugins): array
    {
        $pluginIds = $this->pluginIds($plugins);
        if ($pluginIds === []) {
            return [];
        }

        $countsByPluginId = array_fill_keys($pluginIds, []);
        foreach ($this->availableTables($this->bindingReferenceTables()) as $table) {
            $counts = DB::table($table)
                ->select('plugin_id', DB::raw('COUNT(*) as aggregate'))
                ->whereIn('plugin_id', $pluginIds)
                ->groupBy('plugin_id')
                ->pluck('aggregate', 'plugin_id');

            foreach ($counts as $pluginId => $count) {
                $pluginId = (int) $pluginId;
                $count = (int) $count;
                if ($pluginId > 0 && $count > 0) {
                    $countsByPluginId[$pluginId][$table] = $count;
                }
            }
        }

        return $countsByPluginId;
    }

    private function deleteUpstreamRuntimeBindings(IntegrationPlugin $plugin): void
    {
        $pluginId = (int) $plugin->id;
        if ($pluginId <= 0) {
            return;
        }

        $this->deletePluginRows('service_upstream_bindings', $pluginId);
        $this->deletePluginRows('product_upstream_bindings', $pluginId);
    }

    private function deletePluginRows(string $table, int $pluginId): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'plugin_id')) {
            return;
        }

        DB::table($table)->where('plugin_id', $pluginId)->delete();
    }

    /**
     * @param  array<int, IntegrationPlugin>  $plugins
     * @return array<int, array<string, mixed>|null>
     */
    private function latestRuntimeLogsByPlugin(array $plugins): array
    {
        $pluginIds = $this->pluginIds($plugins);
        if ($pluginIds === []) {
            return [];
        }

        $logsByPluginId = array_fill_keys($pluginIds, null);
        if (! Schema::hasTable('integration_plugin_runtime_logs')) {
            return $logsByPluginId;
        }

        $rows = DB::table('integration_plugin_runtime_logs')
            ->whereIn('plugin_id', $pluginIds)
            ->orderBy('plugin_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['plugin_id', 'id', 'trace_id', 'action', 'status', 'error_message', 'created_at']);

        foreach ($rows as $row) {
            $pluginId = (int) ($row->plugin_id ?? 0);
            if ($pluginId > 0 && $logsByPluginId[$pluginId] === null) {
                $logsByPluginId[$pluginId] = $this->formatRuntimeLog($row);
            }
        }

        return $logsByPluginId;
    }

    /**
     * @return array<string, int>
     */
    private function resolvedPluginReferenceCounts(?IntegrationPlugin $plugin, array $referenceCountsByPluginId): array
    {
        $pluginId = (int) ($plugin?->id ?? 0);
        if ($pluginId <= 0) {
            return [];
        }

        if (array_key_exists($pluginId, $referenceCountsByPluginId)) {
            return $referenceCountsByPluginId[$pluginId];
        }

        return $this->pluginReferenceCounts($plugin, $this->bindingReferenceTables());
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolvedLatestRuntimeLog(?IntegrationPlugin $plugin, array $latestRuntimeLogsByPluginId): ?array
    {
        $pluginId = (int) ($plugin?->id ?? 0);
        if ($pluginId <= 0) {
            return null;
        }

        if (array_key_exists($pluginId, $latestRuntimeLogsByPluginId)) {
            return $latestRuntimeLogsByPluginId[$pluginId];
        }

        return $this->latestRuntimeLog($plugin);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestRuntimeLog(?IntegrationPlugin $plugin): ?array
    {
        $pluginId = (int) ($plugin?->id ?? 0);
        if ($pluginId <= 0 || ! Schema::hasTable('integration_plugin_runtime_logs')) {
            return null;
        }

        $row = DB::table('integration_plugin_runtime_logs')
            ->where('plugin_id', $pluginId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first(['id', 'trace_id', 'action', 'status', 'error_message', 'created_at']);

        if ($row === null) {
            return null;
        }

        return $this->formatRuntimeLog($row);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatRuntimeLog(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'trace_id' => (string) ($row->trace_id ?? ''),
            'action' => (string) ($row->action ?? ''),
            'status' => (string) ($row->status ?? ''),
            'error_message' => (string) ($row->error_message ?? ''),
            'created_at' => $row->created_at === null ? null : (string) $row->created_at,
        ];
    }

    /**
     * @param  array<int, IntegrationPlugin>  $plugins
     * @return array<int, int>
     */
    private function pluginIds(array $plugins): array
    {
        return array_values(array_unique(array_filter(
            array_map(
                fn (IntegrationPlugin $plugin): int => (int) $plugin->id,
                $plugins,
            ),
            fn (int $pluginId): bool => $pluginId > 0,
        )));
    }

    /**
     * @param  array<int, string>  $tables
     * @return array<int, string>
     */
    private function availableTables(array $tables): array
    {
        return array_values(array_filter(
            $tables,
            fn (string $table): bool => Schema::hasTable($table) && Schema::hasColumn($table, 'plugin_id'),
        ));
    }

    /**
     * 绑定表：表达“插件被哪个业务场景选用”，卸载时会被硬删。
     *
     * @return array<int, string>
     */
    private function bindingReferenceTables(): array
    {
        return [
            'integration_plugin_bindings',
            'supplier_plugin_bindings',
            'product_upstream_bindings',
            'service_upstream_bindings',
        ];
    }

    /**
     * 卸载校验用的全量引用表：绑定表 + 历史业务记录表。
     *
     * @return array<int, string>
     */
    private function pluginReferenceTables(): array
    {
        return array_merge($this->bindingReferenceTables(), [
            'service_runtime_snapshots',
            'service_connection_snapshots',
            'service_provision_attempts',
            'integration_plugin_runtime_logs',
            'payments',
            'payment_callbacks',
            'gateway_logs',
            'message_logs',
        ]);
    }
}
