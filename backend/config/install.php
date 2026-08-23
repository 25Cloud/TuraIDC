<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | 部署级安装令牌
    |--------------------------------------------------------------------------
    |
    | Web 安装向导（/install）在 APP_KEY 为空、CSRF 豁免期间对外可访问，
    | 必须由部署者在环境变量 INSTALL_TOKEN 中显式提供令牌才能使用；
    | 未配置令牌时 Web 向导整体禁用（改用 php artisan app:install）。
    |
    | 令牌不写入生成的 .env、不进入安装表单字段，仅通过请求头
    | X-Install-Token 或 URL 参数 token 传入，用于首次访问打开向导。
    |
    */

    'token' => (string) env('INSTALL_TOKEN', ''),
];
