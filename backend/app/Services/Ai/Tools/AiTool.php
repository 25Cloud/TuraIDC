<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

/**
 * AI 工具基类。
 *
 * 一个工具一件事：声明给模型看的名称/描述/参数 schema，自己在 run() 里调既有业务 Service。
 * 禁止在工具里直接拼 SQL、直接打上游 HTTP、直接改余额——余额与退款类方法一律不得注册为工具。
 */
abstract class AiTool
{
    public const RISK_READ = 'read';

    public const RISK_WRITE = 'write';

    public const RISK_DANGEROUS = 'dangerous';

    abstract public function name(): string;

    /** 给模型看的中文描述，必须写清能做什么、不能做什么 */
    abstract public function description(): string;

    /**
     * JSON Schema（对象根）：properties / required / additionalProperties=false。
     *
     * @return array<string, mixed>
     */
    abstract public function parameters(): array;

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{summary: string, data: mixed}
     */
    abstract public function run(AiToolContext $context, array $arguments): array;

    public function risk(): string
    {
        return self::RISK_READ;
    }

    /** 与开放 API 的 scope 域对齐，供后续按域授权使用 */
    public function scopes(): array
    {
        return [];
    }

    /** 确认卡片标题：默认取描述，写操作建议覆盖成动作名 */
    public function title(array $arguments): string
    {
        return $this->description();
    }

    /** 确认卡片里的影响面说明 */
    public function impact(array $arguments): string
    {
        return '';
    }

    /** 金额（分）；没有金额返回 null */
    public function amountCents(array $arguments): ?int
    {
        return null;
    }

    /**
     * 工具声明的值对象，注册表用它拼给模型的 tools 数组。
     *
     * @return array{name: string, description: string, parameters: array<string, mixed>}
     */
    public function descriptor(): array
    {
        return [
            'name' => $this->name(),
            'description' => $this->description(),
            'parameters' => $this->parameters(),
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    protected function objectSchema(array $properties, array $required = []): array
    {
        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
    }
}
