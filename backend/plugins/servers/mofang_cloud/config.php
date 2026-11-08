<?php

declare(strict_types=1);

use App\Services\Upstream\Contracts\ProvidesConsoleAccess;
use App\Services\Upstream\Contracts\ProvidesConsoleRuntime;
use App\Services\Upstream\Contracts\ProvidesHostSuspension;
use App\Services\Upstream\Contracts\ProvidesHostTermination;
use App\Services\Upstream\Contracts\ProvidesOrderProvisioning;
use App\Services\Upstream\Contracts\ProvidesProvisioning;
use App\Services\Upstream\Contracts\ProvidesStatusSync;
use TuraIDC\Plugins\Servers\MofangCloud\Logic\MofangCloud;

/**
 * 魔方云上游插件清单。
 *
 * `info.capabilities` 是能力的唯一真源：平台据此决定能调用哪些能力，
 * 插件入口 `Logic\MofangCloud` 也从这里读同一份清单，不另存一份。
 */
return [
    'info' => [
        'domain' => 'upstream',
        'slug' => 'mofang_cloud',
        'key' => 'mofang_cloud_api',
        'name' => '魔方云接口',
        'version' => '1.0.0',
        'entry' => MofangCloud::class,
        'capabilities' => [
            ProvidesConsoleAccess::class,
            ProvidesConsoleRuntime::class,
            ProvidesHostSuspension::class,
            ProvidesHostTermination::class,
            // 编排层以 ProvidesProvisioning/ProvidesRenewal 作为能力门槛，子接口虽然通过
            // 继承满足 instanceof，supports() 需要显式列出才会命中。
            ProvidesProvisioning::class,
            ProvidesOrderProvisioning::class,
            ProvidesStatusSync::class,
        ],
        'extra' => [],
    ],
    'config' => [
        'mofang_cloud_notice' => [
            'title' => '配置说明',
            'type' => 'notice',
            'theme' => 'info',
            'content' => '该插件直连魔方云（不是魔方财务）。同一个魔方云站点只支持一种接口代际：'
                .'老版本走 /v1，新版本走 /api/v1，两者不可混用；'
                .'地址、账号与密钥在供应商编辑页按「接口代际」对应的含义填写。',
        ],
        'provider_key' => [
            'title' => '上游标识',
            'type' => 'readonly',
            'value' => 'mofang_cloud_api',
            'description' => '供应商绑定的 provider_key 必须保持该值。',
        ],
    ],
];
