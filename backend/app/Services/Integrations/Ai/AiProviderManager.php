<?php

declare(strict_types=1);

namespace App\Services\Integrations\Ai;

use App\Contracts\Integrations\Ai\AiProviderInterface;
use App\Exceptions\BusinessException;
use App\Services\Ai\AiConfig;
use App\Services\Integrations\Ai\Drivers\AnthropicMessagesDriver;
use App\Services\Integrations\Ai\Drivers\ChatCompletionsDriver;
use App\Services\Integrations\Ai\Drivers\ResponsesDriver;

/**
 * 驱动解析：按站点配置的协议返回对应实现。
 *
 * 支持三种上游协议：对话补全、Responses、Anthropic Messages。
 * 测试可用 use() 注入 fake 驱动，业务代码永远只依赖 AiProviderInterface。
 */
final class AiProviderManager
{
    private ?AiProviderInterface $override = null;

    public function __construct(private readonly AiConfig $config) {}

    /** 测试与本地联调用：注入替身驱动 */
    public function use(?AiProviderInterface $driver): void
    {
        $this->override = $driver;
    }

    public function driver(?string $protocol = null): AiProviderInterface
    {
        if ($this->override instanceof AiProviderInterface) {
            return $this->override;
        }

        $protocol = $protocol ?? $this->config->protocol();
        $baseUrl = $this->config->baseUrl();

        if ($baseUrl === '') {
            throw new BusinessException('AI 网关地址未配置，请先在管理端填写网关地址', 42200, 422);
        }

        $apiKey = $this->config->apiKey();
        // 密钥缺省即拒绝：绝不带着空密钥去请求，那等于把失败原因藏到上游。
        // 例外只给本机/内网自建模型（Ollama、vLLM 常不校验密钥），公网地址一律必须配密钥。
        if ($apiKey === '' && ! $this->isLocalEndpoint($baseUrl)) {
            throw new BusinessException('AI 网关密钥未配置，请先在管理端填写 API 密钥', 42200, 422);
        }

        $connectTimeout = (int) config('ai_gateway.connect_timeout', 15);

        return match ($protocol) {
            'responses' => new ResponsesDriver($baseUrl, $apiKey, $connectTimeout),
            'anthropic_messages' => new AnthropicMessagesDriver($baseUrl, $apiKey, $connectTimeout),
            default => new ChatCompletionsDriver($baseUrl, $apiKey, $connectTimeout),
        };
    }

    public function configured(): bool
    {
        return $this->config->isConfigured();
    }

    /** 本机或私有网段的自建模型允许空密钥（Ollama / vLLM 默认不校验） */
    private function isLocalEndpoint(string $baseUrl): bool
    {
        $host = (string) parse_url($baseUrl, PHP_URL_HOST);
        if ($host === '' || $host === 'localhost') {
            return $host !== '';
        }

        return filter_var($host, FILTER_VALIDATE_IP) !== false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
