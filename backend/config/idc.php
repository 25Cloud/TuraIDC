<?php

/*
|--------------------------------------------------------------------------
| IDC 业务配置
|--------------------------------------------------------------------------
*/

return [
    // 站点名称
    'site_name' => env('IDC_SITE_NAME', '图拉云'),

    // 订单自动取消时间（分钟）
    'order_auto_cancel_minutes' => 30,

    // 账单逾期宽限天数
    'invoice_overdue_grace_days' => 3,

    // 服务暂停后自动终止天数
    'service_terminate_days' => 30,

    // 自动续费提前天数（到期前 N 天自动扣款）
    'auto_renew_days_before' => 3,

    // 计费周期映射（天数）
    'billing_cycle_days' => [
        'monthly' => 30,
        'quarterly' => 90,
        'semi' => 180,
        'yearly' => 365,
        'biennial' => 730,
        'triennial' => 1095,
    ],

    // 支持的支付网关
    'payment_gateways' => [
        'balance' => '余额支付',
        'alipay' => '支付宝',
        'wechat' => '微信支付',
    ],
    // 实名认证配置
    'verification' => [
        'api' => env('VERIFICATION_API', ''),
        'key' => env('VERIFICATION_KEY', ''),
        'biz_code' => env('VERIFICATION_BIZ_CODE', 'FACE'),
        'api_endpoint' => env('VERIFICATION_API_ENDPOINT', 'https://idc.stay33.cn/realname/certapi.php'),
        'free_attempts' => env('VERIFICATION_FREE_ATTEMPTS', 3), // 单用户免费认证次数
        'retry_fee' => env('VERIFICATION_RETRY_FEE', 2.00), // 失败后再次认证费用（元）
    ],

    // 主机面板接口
    'hosting_panel_api' => [
        // 部分上游会基于 User-Agent 对既有面板流量做兼容放行，这里保留旧版请求头作为默认值。
        'user_agent' => 'mozilla/5.0 (compatible; msie 5.01; windows nt 5.0)',
        'ssl_verify' => env('HOSTING_PANEL_API_SSL_VERIFY', env('APP_ENV') !== 'local'),
        'ca_bundle' => env('HOSTING_PANEL_API_CA_BUNDLE', ''),
        'allowed_hosts' => env('HOSTING_PANEL_API_ALLOWED_HOSTS', ''),
        'jwt_cache_store' => env('HOSTING_PANEL_API_JWT_CACHE_STORE', 'redis'),
        'dns_resolver_timeout' => (int) env('HOSTING_PANEL_API_DNS_TIMEOUT', 3),
        'connect_timeout' => (int) env('HOSTING_PANEL_API_CONNECT_TIMEOUT', 15),
        'timeout' => 900,
    ],

    // 短信配置
    'sms' => [
        'api_endpoint' => env('SMS_API_ENDPOINT', 'https://dypnsapi.aliyuncs.com/'),
    ],

    // GeeTest 行为验证
    'geetest' => [
        'enabled' => env('GEETEST_ENABLED', false),
        'captcha_id' => env('GEETEST_CAPTCHA_ID', ''),
        'captcha_key' => env('GEETEST_CAPTCHA_KEY', ''),
    ],

    'vnc_relay' => [
        'host' => env('VNC_RELAY_HOST', '127.0.0.1'),
        'port' => (int) env('VNC_RELAY_PORT', 8100),
        'path' => env('VNC_RELAY_PATH', '/ws/vnc'),
        'ssl_verify' => env('VNC_RELAY_SSL_VERIFY', env('APP_ENV') !== 'local'),
        'ca_bundle' => env('VNC_RELAY_CA_BUNDLE', ''),
        'connect_timeout' => (int) env('VNC_RELAY_CONNECT_TIMEOUT', 10),
        // 本地联调常在代理 fake-ip 环境（Clash TUN 等）运行，任意域名统一解析为
        // 198.18/15 与 fc00::/7 ULA 假地址，SSRF 内网段拦截会误杀合法 VNC 上游，
        // 且本地 Relay 仅监听 127.0.0.1 内网端口。默认本地跳过校验、生产保持严格，
        // 可用 VNC_RELAY_ALLOW_PRIVATE_UPSTREAM 显式覆盖（生产不建议开启）。
        'allow_private_upstream' => (bool) env('VNC_RELAY_ALLOW_PRIVATE_UPSTREAM', env('APP_ENV') === 'local'),
    ],

    'frontend' => [
        'dist_path' => env('FRONTEND_DIST_PATH', base_path('../frontend-client/dist')),
    ],

];
