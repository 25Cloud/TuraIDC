<?php

declare(strict_types=1);

use TuraIDC\Plugins\Addons\AiAssistant\AiAssistantLifecycle;
use TuraIDC\Plugins\Addons\AiAssistant\AiAssistantPlugin;
use TuraIDC\Plugins\Addons\AiAssistant\Providers\AiAssistantServiceProvider;

/**
 * AI 助手插件清单（插件标准 v2）。
 *
 * 运行时默认值在 config/ai.php（合并进 `ai_gateway` 配置键）；
 * 业务配置（网关地址、模型、密钥、额度等）仍由 settings 组 `ai` 承载，
 * 管理端「AI 设置」页是唯一编辑入口 —— 这里不再重复声明同一批字段，
 * 避免出现两份真源。
 */
return [
    'info' => [
        // —— v1 必填 ——
        'domain' => 'addons',
        'slug' => 'ai_assistant',
        'key' => 'ai_assistant',
        'name' => 'AI 助手',
        'version' => '1.0.0',
        'entry' => AiAssistantPlugin::class,
        'capabilities' => ['ai.chat', 'ai.tools'],

        // —— v2 新增 ——
        'manifest_version' => 2,
        'provider' => AiAssistantServiceProvider::class,
        'min_platform_version' => '1.0.0',
        'description' => '站内 AI 助手：对话、工具调用与权限分级',
        'author' => '25Cloud',
        'homepage' => 'https://github.com/25Cloud/TuraIDC',
        'license' => 'AGPL-3.0-or-later',
        'lifecycle' => AiAssistantLifecycle::class,
        'upgrade' => [
            'from' => ['1.0.0'],
            // 数据表在核心迁移里（ai_*），插件不带迁移；升级只做幂等自检与配置补齐。
            'migrations' => false,
        ],
        'extra' => [
            // 运行时可调配置所在的 settings 分组，供运维与后续插件市场展示使用
            'settings_group' => 'ai',
            'config_key' => 'ai_gateway',
        ],
    ],
    'config' => [
        'boundary_notice' => [
            'title' => '配置入口说明',
            'type' => 'notice',
            'theme' => 'info',
            'content' => 'AI 网关地址、模型、密钥与额度在管理端「AI 设置」页维护（settings 组 ai，密钥加密保存）。
本插件只负责对话、工具调用与管理端治理接口，不在这里重复保存同一批配置。',
        ],
        'runtime_config' => [
            'title' => '运行时默认值',
            'type' => 'readonly',
            'value' => 'plugins/addons/ai_assistant/config/ai.php → 配置键 ai_gateway',
        ],
    ],
];
