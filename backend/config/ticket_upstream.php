<?php

declare(strict_types=1);

return [
    'callback_secret' => env('TICKET_UPSTREAM_CALLBACK_SECRET', ''),

    // 上游 /upload_image 是否强制凭证校验。
    // 部署中的上游 zjmfv376 无法同步配套修改（pushTicketReply 上传不带 id/token），
    // 因此默认 false 放行无凭证上传以保证回调附件可用；带凭证的上传仍强制匹配。
    // 一旦上游可携带凭证，应恢复 true（fail-closed）。
    'upload_token_required' => env('TICKET_UPSTREAM_UPLOAD_TOKEN_REQUIRED', false),

    // 上游附件上传的防滥用配置（可在管理端「工单传递设置」页调整，存于 settings 表 ticket_upstream 组）：
    // - upload_allowed_ips：白名单 IP/CIDR（逗号或换行分隔），白名单内不限速
    // - upload_rate_limit：非白名单来源每分钟上传次数上限，0 表示不限速
    'upload_allowed_ips' => env('TICKET_UPSTREAM_UPLOAD_ALLOWED_IPS', ''),
    'upload_rate_limit' => (int) env('TICKET_UPSTREAM_UPLOAD_RATE_LIMIT', 30),

    // 上传文件「已保存但未被工单回复引用」的自动删除保留期（分钟）。
    // 上传成功返回 savename 后，若超过该时长仍未被回调持久化引用，文件会被每分钟清理任务删除。
    'upload_unused_retention_minutes' => (int) env('TICKET_UPSTREAM_UPLOAD_UNUSED_RETENTION_MINUTES', 5),
];
