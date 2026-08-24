<?php

declare(strict_types=1);

return [
    // 工单自动预回复开关。开启后，用户新建工单时系统会以配置的管理员账号名义
    // 自动回复配置内容（如「请耐心等待管理员回复」），工单状态变为「员工回复」。
    // 实际生效值优先取 settings 表 ticket_pre_reply 组（管理端可配置）。
    'enabled' => filter_var(
        env('TICKET_PRE_REPLY_ENABLED', false),
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE
    ) ?? false,

    // 以「谁」的名义发送预回复：管理员账号 ID。
    'admin_user_id' => (int) env('TICKET_PRE_REPLY_ADMIN_USER_ID', 0),

    // 预回复内容（纯文本，多行）。
    'content' => (string) env('TICKET_PRE_REPLY_CONTENT', ''),

    // 传递到上游的工单专用预回复内容。命中工单传递规则、会推送到上游的工单
    // 优先使用该内容；留空时回退使用 content。
    'upstream_content' => (string) env('TICKET_PRE_REPLY_UPSTREAM_CONTENT', ''),
];
