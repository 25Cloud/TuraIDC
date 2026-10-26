<?php

declare(strict_types=1);

/**
 * AI 网关静态默认值。
 *
 * 运行时可变项（协议、模型、密钥、额度等）走 settings 表 group=ai，
 * 由 App\Services\Ai\AiConfig 包一层读取；本文件只放不随站点变化的常量与 env 兜底。
 */
return [
    // 三种上游协议：OpenAI 对话补全、OpenAI Responses、Anthropic Messages
    'protocols' => [
        'chat_completions' => [
            'label' => 'OpenAI 对话补全（/v1/chat/completions）',
            'path' => '/v1/chat/completions',
        ],
        'responses' => [
            'label' => 'OpenAI Responses（/v1/responses）',
            'path' => '/v1/responses',
        ],
        'anthropic_messages' => [
            'label' => 'Anthropic Messages（/v1/messages）',
            'path' => '/v1/messages',
        ],
    ],

    'default_protocol' => env('AI_GATEWAY_PROTOCOL', 'chat_completions'),

    // 网络：连接超时短、总超时受 settings.request_timeout_seconds 控制
    'connect_timeout' => (int) env('AI_GATEWAY_CONNECT_TIMEOUT', 15),

    // 单轮工具调用上限与工具结果截断长度，防止模型自我循环烧钱
    'max_tool_rounds' => (int) env('AI_GATEWAY_MAX_TOOL_ROUNDS', 6),
    'max_tool_result_chars' => (int) env('AI_GATEWAY_MAX_TOOL_RESULT_CHARS', 4096),

    // 上下文装配：只带最近若干条消息
    'history_limit' => (int) env('AI_GATEWAY_HISTORY_LIMIT', 12),

    // 确认令牌有效期（分钟）
    'confirm_ttl_minutes' => (int) env('AI_GATEWAY_CONFIRM_TTL', 5),

    // 权限档位定义：label 供前端与日志使用
    'permission_modes' => [
        'read_only' => '仅可查看',
        'confirm' => '完全权限（写操作需确认）',
        'auto_approve' => '自动审批（beta）',
    ],

    // 默认关闭，所有入口在 enabled=1 之前保持不可见
    'enabled_default' => (int) env('AI_GATEWAY_ENABLED', 0),
];
