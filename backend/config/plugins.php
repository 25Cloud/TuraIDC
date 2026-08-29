<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | 插件市场配置
    |--------------------------------------------------------------------------
    |
    | 分发全部依赖 GitHub：索引是 raw.githubusercontent.com 上的 plugins.json，
    | 插件代码是 GitHub archive（tag / sha 固定引用，审核后不可被替换内容）。
    | 国内访问可通过加速镜像前缀解决（默认 ghfast.top，环境变量可覆盖）。
    |
    */

    'market' => [
        // 插件市场索引（plugins.json）原始地址。
        'index_url' => env('PLUGIN_MARKET_INDEX_URL', 'https://raw.githubusercontent.com/25Cloud/turaidc-plugin-index/main/plugins.json'),

        // 索引下载加速镜像前缀（拼在原始地址前，如 https://ghfast.top/ ），留空直连。
        'raw_mirror' => env('PLUGIN_MARKET_RAW_MIRROR', 'https://ghfast.top/'),

        // 插件代码 archive 下载模板。{repo} 替换为 owner/repo；{ref} 替换为
        // sha（若条目提供）或 refs/tags/{tag}。
        'archive_zip_url' => env('PLUGIN_MARKET_ARCHIVE_ZIP_URL', 'https://github.com/{repo}/archive/{ref}.zip'),

        // 代码下载加速镜像前缀（拼在 archive_zip_url 前）。
        'download_mirror' => env('PLUGIN_MARKET_DOWNLOAD_MIRROR', 'https://ghfast.top/'),

        // 插件包临时工作目录（storage/app/private/ 下）。
        'work_dir' => env('PLUGIN_MARKET_WORK_DIR', 'plugin-market'),

        // HTTP 超时（秒）。
        'timeout' => (int) env('PLUGIN_MARKET_TIMEOUT', 30),
    ],
];
