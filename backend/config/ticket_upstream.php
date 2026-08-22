<?php

declare(strict_types=1);

return [
    'callback_secret' => env('TICKET_UPSTREAM_CALLBACK_SECRET', ''),

    // 上游 /upload_image 是否启用。默认关闭，必须在管理端开启后才能配置工单传递规则。
    'upload_image_enabled' => filter_var(
        env('TICKET_UPSTREAM_UPLOAD_IMAGE_ENABLED', false),
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE
    ) ?? false,

    // 上游 /upload_image 是否强制凭证校验。
    // 魔方财务（上游 zjmfv376）的 pushTicketReply 上传不支持携带 id/token 校验参数，
    // 因此该开关必须保持默认 false，放行无凭证上传以保证回调附件可用（fail-open）。
    // 带凭证的上传仍会强制匹配校验，不受此开关影响；
    // 将来上游支持携带凭证后可改回 true（fail-closed）。
    'upload_token_required' => filter_var(
        env('TICKET_UPSTREAM_UPLOAD_TOKEN_REQUIRED', false),
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE
    ) ?? false,

    // 上游附件上传的防滥用配置（可在管理端「工单传递设置」页调整，存于 settings 表 ticket_upstream 组）：
    // - upload_allowed_ips：白名单 IP/CIDR（逗号或换行分隔），白名单内不限速
    // - upload_rate_limit：非白名单来源每分钟上传次数上限，0 表示不限速
    // - upload_block_non_whitelisted：true 时直接拒绝白名单外的所有上传（忽略 rate_limit）
    'upload_allowed_ips' => env('TICKET_UPSTREAM_UPLOAD_ALLOWED_IPS', ''),
    'upload_rate_limit' => (int) env('TICKET_UPSTREAM_UPLOAD_RATE_LIMIT', 30),
    'upload_block_non_whitelisted' => filter_var(
        env('TICKET_UPSTREAM_UPLOAD_BLOCK_NON_WHITELISTED', true),
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE
    ) ?? true,

    // 上传文件「已保存但未被工单回复引用」的自动删除保留期（分钟）。
    // 上传成功返回 savename 后，若超过该时长仍未被回调持久化引用，文件会被每分钟清理任务删除。
    'upload_unused_retention_minutes' => (int) env('TICKET_UPSTREAM_UPLOAD_UNUSED_RETENTION_MINUTES', 5),
];
