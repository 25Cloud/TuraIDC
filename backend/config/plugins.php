<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | 插件市场配置
    |--------------------------------------------------------------------------
    |
    | 分发默认只连接 GitHub 官方域名：索引是 raw.githubusercontent.com 上的
    | plugins.json，插件代码是 GitHub archive。任何自定义地址都必须落在
    | trusted_hosts allowlist 内；镜像默认关闭，避免把供应链交给未知第三方。
    |
    */

    'market' => [
        // 插件市场索引（plugins.json）原始地址。
        'index_url' => env('PLUGIN_MARKET_INDEX_URL', 'https://raw.githubusercontent.com/25Cloud/turaidc-plugin-index/main/plugins.json'),

        // 可信源主机 allowlist。自定义索引、镜像和 archive 地址均必须匹配。
        'trusted_hosts' => array_values(array_filter(array_map(
            static fn (string $host): string => strtolower(trim($host)),
            explode(',', (string) env('PLUGIN_MARKET_TRUSTED_HOSTS', 'raw.githubusercontent.com,github.com'))
        ))),

        // 仓库 allowlist；默认只允许主仓与官方 AI 发行镜像，新增插件必须显式追加
        // owner/repo。显式留空会拒绝所有市场包，不能退化为任意仓库。
        'trusted_repositories' => array_values(array_filter(array_map(
            static fn (string $repo): string => strtolower(trim($repo)),
            explode(',', (string) env('PLUGIN_MARKET_TRUSTED_REPOSITORIES', '25Cloud/TuraIDC,25Cloud/turaidc-plugin-ai-assistant'))
        ))),

        // 索引下载加速镜像前缀（拼在原始地址前），留空直连。
        'raw_mirror' => env('PLUGIN_MARKET_RAW_MIRROR', ''),

        // 插件代码 archive 下载模板。{repo} 替换为 owner/repo；{ref} 必须由索引
        // 提供的 40 位 commit sha 填充，拒绝漂移的 tag/branch。
        'archive_zip_url' => env('PLUGIN_MARKET_ARCHIVE_ZIP_URL', 'https://github.com/{repo}/archive/{ref}.zip'),

        // 代码下载加速镜像前缀（拼在 archive_zip_url 前），留空直连。
        'download_mirror' => env('PLUGIN_MARKET_DOWNLOAD_MIRROR', ''),

        // monorepo 条目（repo + path + sha）下载的是整仓快照，比独立仓库大一到两个数量级，
        // 用单独的超时上限；实际取 max(timeout, 本值)。
        'monorepo_timeout' => (int) env('PLUGIN_MARKET_MONOREPO_TIMEOUT', 120),

        // 插件包临时工作目录（storage/app/private/ 下）。
        'work_dir' => env('PLUGIN_MARKET_WORK_DIR', 'plugin-market'),

        // HTTP 超时（秒）。
        'timeout' => (int) env('PLUGIN_MARKET_TIMEOUT', 30),
    ],
];
