<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AutoRefund\Services;

use App\Services\Integrations\Plugins\PluginConfigRepository;
use App\Services\Integrations\Plugins\PluginDomain;
use App\Support\OptionalFeature;
use TuraIDC\Plugins\Addons\AutoRefund\Models\AutoRefundProductRule;

/**
 * 自助退款的配置读取与规范化。
 *
 * 唯一配置来源是插件配置表：`integration_plugin_configs`（由管理端插件配置页按
 * config.php 的 schema 写入，密钥进 secret_json 加密列）。
 * 这里**不读 settings 表**，也不缓存解密结果——凭据必须每次读当前值，
 * 否则管理员轮换密钥后长驻队列还会用旧值（见 PluginConfigRepository 的注释）。
 *
 * 默认值与 config.php 的 `value` 保持一致：schema 里的默认值不会被持久化，
 * 未保存过的项只能在运行时兜底。
 *
 * 第二阶段起，**退款类型/规则/要求不再是全局配置**，而是逐商品落在
 * `auto_refund_product_rules`（见 AutoRefundProductRule）。这里只保留两样东西：
 * 上游链路凭据（`api.php` 一条、上游工单一条）与全局行为开关。
 */
final class AutoRefundSettings
{
    public const SLUG = 'auto_refund';

    public const DOMAIN = PluginDomain::ADDONS;

    /** 上游接口默认地址（与 config.php 的 api_base_url 默认值一致） */
    public const DEFAULT_API_BASE_URL = 'https://www.zixiyun.cn/plugins/addons/auto_refund/api.php';

    /** 工单协议：智简魔方财务真上游（login_api + submitticket） */
    public const TICKET_PROTOCOL_ZJFM = 'zjfm';

    /** 工单协议：上游也是 TuraIDC（复用既有工单投递链路） */
    public const TICKET_PROTOCOL_TURA = 'tura';

    public const DEFAULT_TICKET_TITLE_TEMPLATE = '【退款申请】{product_name} - {host_id}';

    public const DEFAULT_TICKET_CONTENT_TEMPLATE = "商品：{product_name}\n主机：{host_id}\n申请金额：{amount}\n退款原因：{reason}\n\n请协助处理该机器的退款，谢谢。";

    /** @var array<string, mixed>|null */
    private ?array $resolved = null;

    public function __construct(
        private readonly PluginConfigRepository $configRepository,
    ) {}

    /**
     * 平台侧的启用状态（插件已安装且已启用），与配置里的 enabled 开关不是同一件事：
     * 前者决定接口存不存在，后者决定业务开不开放。
     */
    public function pluginEnabled(): bool
    {
        return OptionalFeature::enabled(OptionalFeature::AUTO_REFUND);
    }

    public function enabled(): bool
    {
        return $this->boolValue('enabled', true);
    }

    public function apiBaseUrl(): string
    {
        return $this->stringValue('api_base_url');
    }

    /**
     * 上游 `api.php` 密钥。只允许在 ApiClient 组装表单时读取，
     * 绝不进日志、进前端、进台账摘要。
     */
    public function apiKey(): string
    {
        return $this->stringValue('api_key');
    }

    public function hasApiKey(): bool
    {
        return $this->apiKey() !== '';
    }

    public function requestTimeoutSeconds(): int
    {
        $value = $this->intValue('request_timeout', 15);

        return max(1, min($value, 120));
    }

    public function consoleNotice(): string
    {
        $notice = $this->stringValue('console_notice');
        if ($notice !== '') {
            return $notice;
        }

        return '退款申请会提交给上游服务商审核，审核期间可在本页自行取消；'
            .'实际退款金额以上游核算为准，本站展示的金额仅为预估。';
    }

    public function settleRemark(): string
    {
        $remark = $this->stringValue('settle_remark');

        return $remark === '' ? '自助退款申请结算' : $remark;
    }

    public function upstreamPrecheck(): bool
    {
        return $this->boolValue('upstream_precheck', true);
    }

    /**
     * 工单通道协议：`zjfm`（默认，智简魔方真上游）或 `tura`（上游也是 TuraIDC）。
     */
    public function ticketProtocol(): string
    {
        $protocol = $this->stringValue('ticket_protocol');

        return in_array($protocol, [self::TICKET_PROTOCOL_ZJFM, self::TICKET_PROTOCOL_TURA], true)
            ? $protocol
            : self::TICKET_PROTOCOL_ZJFM;
    }

    public function ticketProtocolLabel(): string
    {
        return $this->ticketProtocol() === self::TICKET_PROTOCOL_TURA
            ? 'TuraIDC 工单接口（/ticket/create）'
            : '智简魔方财务（login_api + submitticket）';
    }

    /**
     * 上游站点根地址（不含 `/plugins/addons/auto_refund/api.php` 这类路径）。
     * `zjfm` 协议用它拼 `/v1/login_api` 与 `/submitticket`。
     */
    public function ticketHostname(): string
    {
        return rtrim($this->stringValue('ticket_hostname'), '/');
    }

    public function ticketUsername(): string
    {
        return $this->stringValue('ticket_username');
    }

    /**
     * 上游工单接口密钥（`zjfm` 协议 `login_api` 的 `api_key`）。
     */
    public function ticketApiKey(): string
    {
        return $this->stringValue('ticket_api_key');
    }

    /**
     * 上游工单部门 ID（`zjfm` 的 `dptid`；`tura` 的 `upstream_department_id`）。
     * 两种协议都优先用它，`tura` 缺省时回退到既有 `ticket_delivery_rules` 匹配。
     */
    public function ticketDepartmentId(): string
    {
        return $this->stringValue('ticket_department_id');
    }

    public function ticketTitleTemplate(): string
    {
        $template = $this->stringValue('ticket_title_template');

        return $template === '' ? self::DEFAULT_TICKET_TITLE_TEMPLATE : $template;
    }

    public function ticketContentTemplate(): string
    {
        $template = $this->stringValue('ticket_content_template');

        return $template === '' ? self::DEFAULT_TICKET_CONTENT_TEMPLATE : $template;
    }

    /**
     * `bridge`（插件间对接）通道是否就绪：`api.php` 地址 + 密钥。
     */
    public function bridgeReady(): bool
    {
        return $this->apiBaseUrl() !== '' && $this->hasApiKey();
    }

    /**
     * `api_ticket`（上游工单）通道是否就绪。
     *
     * `zjfm` 要求「上游根地址 + 用户名 + 密钥 + 部门」四项齐全；
     * `tura` 复用本站既有工单投递链路，缺部门时由 `ticket_delivery_rules` 兜底，
     * 因此不在这里要求它。缺任何一项都判「未就绪」，绝不允许带空密钥发单。
     */
    public function ticketChannelReady(): bool
    {
        if ($this->ticketProtocol() === self::TICKET_PROTOCOL_TURA) {
            return true;
        }

        return $this->ticketHostname() !== ''
            && $this->ticketUsername() !== ''
            && $this->ticketApiKey() !== ''
            && $this->ticketDepartmentId() !== '';
    }

    /**
     * `api_ticket` 通道未就绪时的具体缺项（给用户/管理员看，不含任何密钥值）。
     *
     * @return array<int, string>
     */
    public function ticketChannelMissing(): array
    {
        if ($this->ticketProtocol() === self::TICKET_PROTOCOL_TURA) {
            return [];
        }

        $missing = [];
        if ($this->ticketHostname() === '') {
            $missing[] = 'ticket_hostname';
        }
        if ($this->ticketUsername() === '') {
            $missing[] = 'ticket_username';
        }
        if ($this->ticketApiKey() === '') {
            $missing[] = 'ticket_api_key';
        }
        if ($this->ticketDepartmentId() === '') {
            $missing[] = 'ticket_department_id';
        }

        return $missing;
    }

    /**
     * 是否至少有一条出站通道可用（`bridge` 或 `api_ticket`）。
     *
     * 注意这**不等于**「退款中心开放」：`manual` / `auto` 两类策略不出站，
     * 只要商品策略里配了它们，页面就该开放。「开放」的判定在
     * `AutoRefundService::isOpen()`，那里还要看有没有启用的商品策略。
     */
    public function anyChannelReady(): bool
    {
        return $this->bridgeReady() || $this->ticketChannelReady();
    }

    /**
     * 控制台 `bootstrap` 用的只读摘要：不含密钥、不含上游原文。
     *
     * `policy` 自第二阶段起只是「默认值 + 说明」：真正生效的是每个商品自己的策略，
     * 全局这里给不出唯一答案，硬编一个反而误导。
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'plugin_enabled' => $this->pluginEnabled(),
            'enabled' => $this->enabled(),
            'configured' => $this->anyChannelReady(),
            'upstream_configured' => $this->apiBaseUrl() !== '',
            'has_api_key' => $this->hasApiKey(),
            'bridge_ready' => $this->bridgeReady(),
            'ticket_ready' => $this->ticketChannelReady(),
            'policy' => [
                'scope' => 'per_product',
                'note' => '退款类型、退款规则与退款要求逐商品配置，本页展示的是该商品自己的策略；'
                    .'未配置或已停用的商品不参与自助退款。',
                'default_refund_type' => AutoRefundProductRule::TYPE_MANUAL,
                'default_refund_rule' => AutoRefundProductRule::RULE_DURATION,
                'default_requirement' => AutoRefundProductRule::REQUIREMENT_PRODUCT_FIRST,
                'refund_types' => AutoRefundProductRule::typeOptions(),
                'refund_rules' => AutoRefundProductRule::ruleOptions(),
                'requirements' => AutoRefundProductRule::requirementOptions(),
                'upstream_precheck' => $this->upstreamPrecheck(),
            ],
            'ticket' => [
                'protocol' => $this->ticketProtocol(),
                'protocol_label' => $this->ticketProtocolLabel(),
                'ready' => $this->ticketChannelReady(),
                'missing' => $this->ticketChannelMissing(),
                'department_id' => $this->ticketDepartmentId(),
                'hostname' => $this->ticketHostname(),
            ],
            'upstream_host' => $this->maskedHost(),
            'request_timeout' => $this->requestTimeoutSeconds(),
            'console_notice' => $this->consoleNotice(),
        ];
    }

    /**
     * 后台排错用的上游地址（去掉路径，只留 scheme + host），避免把带签名的地址整条外泄。
     */
    public function maskedHost(): string
    {
        $url = $this->apiBaseUrl();
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['host'])) {
            return '';
        }

        $scheme = (string) ($parts['scheme'] ?? 'https');

        return $scheme.'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /**
     * 全量已解析配置（含密钥），仅供本插件内部使用；不要返回给控制器。
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        if ($this->resolved === null) {
            $this->resolved = $this->configRepository
                ->resolvedConfigByDomainAndSlug(self::DOMAIN, self::SLUG);
        }

        return $this->resolved;
    }

    private function stringValue(string $key): string
    {
        $value = $this->raw()[$key] ?? null;

        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }

    private function boolValue(string $key, bool $default): bool
    {
        $value = $this->raw()[$key] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes', 'enabled'], true);
        }

        return $default;
    }

    private function intValue(string $key, int $default): int
    {
        $value = $this->raw()[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        if (is_string($value) && is_numeric(trim($value))) {
            return (int) round((float) trim($value));
        }

        return $default;
    }
}
