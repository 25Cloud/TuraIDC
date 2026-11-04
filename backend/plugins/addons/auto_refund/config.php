<?php

declare(strict_types=1);

use TuraIDC\Plugins\Addons\AutoRefund\AutoRefundPlugin;
use TuraIDC\Plugins\Addons\AutoRefund\Providers\AutoRefundServiceProvider;

/**
 * 自助退款插件清单（插件标准 v2）。
 *
 * 上游是智简魔方财务的开源退款插件 `AutoRefund`（`plugins/addons/auto_refund`），
 * 它对下游暴露两套接口：
 *
 * 1. `api.php`（插件间对接）：表单 POST + `api_key` + `timestamp` + `action`；
 * 2. 站点自带工单接口（`/v1/login_api` + `/submitticket`）：登录换 JWT 后提交工单。
 *
 * 本插件完整复刻它在**上游站点内部**的四种退款类型与三项策略（类型/规则/要求逐商品配置，
 * 落在 `auto_refund_product_rules`），四种类型分别走：
 *
 * - `manual`      …… 只落台账，管理员审核后结算；
 * - `auto`        …… 落台账后立刻走既有账务链路退款到余额；
 * - `bridge`      …… 调上游 `api.php` 的 `submit`（提交时**不传金额**）；
 * - `api_ticket`  …… 建本站工单后再按 `ticket_protocol` 投递上游工单接口。
 *
 * 数据表由**核心迁移**创建（`2026_11_01_000001_create_auto_refund_applications_table`、
 * `2026_11_03_000001_create_auto_refund_product_rules_table`、
 * `2026_11_03_000002_add_channel_and_ticket_to_auto_refund_applications`），
 * 插件不能自己注册迁移（插件标准 v2 §3），本目录下不保留 `database/`。
 * 不在这里声明任何运行期默认值副本：所有可配置项都从本文件的 `config` schema 由后台配置页写入
 * `integration_plugin_configs`，运行时统一经
 * `PluginConfigRepository::resolvedConfigByDomainAndSlug('addons','auto_refund')` 读取。
 */
return [
    'info' => [
        // —— v1 必填 ——
        'domain' => 'addons',
        'slug' => 'auto_refund',
        'key' => 'auto_refund',
        'name' => '自助退款',
        'version' => '1.1.0',
        'entry' => AutoRefundPlugin::class,
        'capabilities' => [
            'auto_refund.apply',
            'auto_refund.cancel',
            'auto_refund.settle',
            'auto_refund.status',
            'auto_refund.product_rule',
        ],

        // —— v2 新增 ——
        'manifest_version' => 2,
        'provider' => AutoRefundServiceProvider::class,
        'min_platform_version' => '1.0.0',
        'description' => '控制台自助退款：四种退款类型、逐商品策略、上游申请或上游工单、本地台账与结算',
        'author' => '25Cloud',
        'homepage' => 'https://github.com/25Cloud/TuraIDC',
        'license' => 'AGPL-3.0-or-later',
        'extra' => [
            // 上游契约要点（README「上游契约」一节的机器可读摘要，供运维核对）
            'upstream_protocol' => 'AutoRefund api.php v1（application/x-www-form-urlencoded）',
            'upstream_default_endpoint' => 'https://www.zixiyun.cn/plugins/addons/auto_refund/api.php',
            'upstream_actions' => ['query', 'submit', 'cancel'],
            'upstream_ticket_protocols' => ['zjfm', 'tura'],
            // 插件自建表：卸载不删（插件标准 v2 §3），需要清理时由站长按 README 手动执行
            'tables' => ['auto_refund_applications', 'auto_refund_product_rules'],
        ],
    ],
    'config' => [
        'boundary_notice' => [
            'title' => '能力边界',
            'type' => 'notice',
            'theme' => 'info',
            'content' => '退款类型、退款规则与退款要求**逐商品**配置（管理端「自助退款 → 商品策略」），
本页只放两条到上游的出站通道：`api.php`（插件间对接）与上游工单接口（API 工单退款）。
实际退多少钱以上游核算或管理员确认为准，本地只给预估；结算仍受既有账单可退上限保护。',
        ],
        'enabled' => [
            'title' => '启用自助退款',
            'type' => 'switch',
            'value' => true,
            'required' => false,
            'description' => '关闭后控制台退款中心显示未开放，已存在的台账不受影响。',
        ],
        'api_base_url' => [
            'title' => '上游接口地址（插件间对接）',
            'type' => 'url',
            'value' => 'https://www.zixiyun.cn/plugins/addons/auto_refund/api.php',
            'required' => false,
            'placeholder' => 'https://你的上游域名/plugins/addons/auto_refund/api.php',
            'description' => '上游 AutoRefund 插件的 api.php 完整地址；必须是 POST 端点，不要填目录。只给「插件间对接」类型使用，留空表示不用这条通道。',
        ],
        'api_key' => [
            'title' => '上游 API KEY（插件间对接）',
            'type' => 'password',
            'value' => '',
            'required' => false,
            'secret' => true,
            'placeholder' => '请输入上游 AutoRefund 发放的完整密钥',
            'description' => '上游页面上显示的是掩码值，掩码值无效，必须填完整密钥；保存后不会明文回显。留空时「插件间对接」通道判定未就绪并拒绝提交。',
        ],
        'request_timeout' => [
            'title' => '请求超时（秒）',
            'type' => 'number',
            'value' => 15,
            'required' => false,
            'min' => 1,
            'max' => 120,
            'step' => 1,
            'description' => '上游请求总超时（api.php 与工单接口共用）。超时按「结果未知」处理并占住该服务的提交闸门。',
        ],
        'console_notice' => [
            'title' => '控制台说明文案',
            'type' => 'textarea',
            'value' => '',
            'required' => false,
            'rows' => 4,
            'description' => '展示在控制台退款中心顶部的规则说明，留空则使用默认文案。',
        ],
        'settle_remark' => [
            'title' => '结算备注模板',
            'type' => 'text',
            'value' => '自助退款申请结算',
            'required' => false,
            'placeholder' => '自助退款申请结算',
            'description' => '写进退款单的备注前缀，后端会附上申请编号便于对账。',
        ],
        'upstream_precheck' => [
            'title' => '提交前校验上游主机',
            'type' => 'switch',
            'value' => true,
            'required' => false,
            'description' => '开启后，「插件间对接」类型提交前先调 query 确认该机器在上游可见，避免提交必然失败的申请。',
        ],
        'ticket_protocol' => [
            'title' => '上游工单协议',
            'type' => 'select',
            'value' => 'zjfm',
            'required' => false,
            'options' => [
                ['label' => '智简魔方财务（login_api + submitticket）', 'value' => 'zjfm'],
                ['label' => 'TuraIDC（/ticket/create）', 'value' => 'tura'],
            ],
            'description' => '「API 工单退款」类型用哪套协议把工单投递到上游。上游是智简魔方财务选 zjfm；上游也是 TuraIDC 选 tura。',
        ],
        'ticket_hostname' => [
            'title' => '上游站点地址（工单接口）',
            'type' => 'url',
            'value' => '',
            'required' => false,
            'placeholder' => 'https://你的上游域名',
            'description' => 'zjfm 协议用它在后面拼 `/v1/login_api` 与 `/submitticket`；不要带 api.php 路径。',
            'visible_when' => ['field' => 'ticket_protocol', 'operator' => 'eq', 'value' => 'zjfm'],
        ],
        'ticket_username' => [
            'title' => '上游账号（工单接口）',
            'type' => 'text',
            'value' => '',
            'required' => false,
            'placeholder' => '上游代理账号邮箱或用户名',
            'description' => 'zjfm 协议 `login_api` 的用户名。',
            'visible_when' => ['field' => 'ticket_protocol', 'operator' => 'eq', 'value' => 'zjfm'],
        ],
        'ticket_api_key' => [
            'title' => '上游工单接口密钥',
            'type' => 'password',
            'value' => '',
            'required' => false,
            'secret' => true,
            'placeholder' => '上游发放的 API KEY',
            'description' => 'zjfm 协议 `login_api` 的 api_key，用于换取 JWT。留空时「API 工单退款」通道判定未就绪并拒绝提交，绝不会带空密钥发单。',
            'visible_when' => ['field' => 'ticket_protocol', 'operator' => 'eq', 'value' => 'zjfm'],
        ],
        'ticket_department_id' => [
            'title' => '上游工单部门 ID',
            'type' => 'text',
            'value' => '',
            'required' => false,
            'placeholder' => '例如 2',
            'description' => 'zjfm 的 `dptid`，tura 的 `upstream_department_id`。tura 缺省时回退既有「工单传递规则」匹配；两者都没有会报「未配置上游工单部门」，不会猜部门发单。',
        ],
        'ticket_title_template' => [
            'title' => '工单标题模板',
            'type' => 'text',
            'value' => '【退款申请】{product_name} - {host_id}',
            'required' => false,
            'description' => '支持占位符 {product_name} {host_id} {amount} {reason}，留空用内置默认。',
        ],
        'ticket_content_template' => [
            'title' => '工单内容模板',
            'type' => 'textarea',
            'value' => "商品：{product_name}\n主机：{host_id}\n申请金额：{amount}\n退款原因：{reason}\n\n请协助处理该机器的退款，谢谢。",
            'required' => false,
            'rows' => 6,
            'description' => '支持占位符 {product_name} {host_id} {amount} {reason}，留空用内置默认。',
        ],
    ],
];
