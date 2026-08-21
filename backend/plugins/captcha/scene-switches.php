<?php

declare(strict_types=1);

/**
 * captcha 域各插件共用的「启用场景」开关字段声明。
 *
 * 用法：在插件的 config.php 里合并进 config 段，让开关出现在插件管理界面中——
 *
 *     'config' => array_merge([ ...插件自有字段... ], require __DIR__.'/../scene-switches.php'),
 *
 * 放在域目录根下而非某个插件目录内：PluginScanner 只把 captcha/ 下的**子目录**当作插件
 * （见 PluginScanner::scan 的 files->directories()），根下的 .php 文件不会被误识别为插件。
 *
 * 键名必须与 App\Services\Auth\CaptchaPolicyService::SCENE_CONFIG_KEYS 一致；
 * 读取与默认值兜底都在那个类里集中处理，这里只做界面声明，不含任何逻辑。
 * 未保存过的开关按「默认开启」处理，因此新装插件即为全场景保护。
 *
 * @return array<string, array<string, mixed>>
 */
return [
    'scene_divider' => [
        'title' => '启用场景',
        'type' => 'divider',
    ],
    'scene_notice' => [
        'title' => '场景说明',
        'type' => 'notice',
        'theme' => 'info',
        'content' => '关闭某个场景后，该入口不再要求人机验证。'
            .'重置密码、验证码登录这类操作必须先获取邮箱/短信验证码，'
            .'其人机验证已在「发送邮箱/手机验证码」环节完成，因此不再单独设开关，避免重复验证。'
            .'另外，当某个登录场景的开关被关闭时，系统会自动改用「失败次数软锁定」兜底，'
            .'该入口不会完全失去防护。',
    ],
    'scene_client_login' => [
        'title' => '用户登录',
        'type' => 'switch',
        'value' => true,
        'required' => false,
        'description' => '前台用户登录时要求人机验证。',
    ],
    'scene_client_register' => [
        'title' => '用户注册',
        'type' => 'switch',
        'value' => true,
        'required' => false,
        'description' => '前台用户注册提交时要求人机验证。'
            .'注册是刷号的主要入口，且攻击者每次使用新账号，按失败次数计数的风控在此天然失效，'
            .'建议保持开启。',
    ],
    'scene_admin_login' => [
        'title' => '管理员登录',
        'type' => 'switch',
        'value' => true,
        'required' => false,
        'description' => '后台管理员登录时要求人机验证。管理员账号权限最高，建议保持开启。',
    ],
    'scene_email_code' => [
        'title' => '发送邮箱验证码',
        'type' => 'switch',
        'value' => true,
        'required' => false,
        'description' => '请求发送邮箱验证码时要求人机验证。'
            .'该开关同时保护依赖邮箱验证码的重置密码与验证码登录。',
    ],
    'scene_phone_code' => [
        'title' => '发送手机验证码',
        'type' => 'switch',
        'value' => true,
        'required' => false,
        'description' => '请求发送短信验证码时要求人机验证。'
            .'短信按条计费，是最直接的成本入口；该开关同时保护依赖短信验证码的重置密码与验证码登录。',
    ],
];
