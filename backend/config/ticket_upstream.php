<?php

declare(strict_types=1);

return [
    'callback_secret' => env('TICKET_UPSTREAM_CALLBACK_SECRET', ''),

    // 上游 /upload_image 是否强制凭证校验。
    // 部署中的上游 zjmfv376 无法同步配套修改（pushTicketReply 上传不带 id/token），
    // 因此默认 false 放行无凭证上传以保证回调附件可用；带凭证的上传仍强制匹配。
    // 一旦上游可携带凭证，应恢复 true（fail-closed）。
    'upload_token_required' => env('TICKET_UPSTREAM_UPLOAD_TOKEN_REQUIRED', false),

    // 上游附件上传目录中孤儿文件的保留天数；超过保留期且未被任何工单回复引用的文件
    // 由每日清理任务删除，用于缓解无凭证上传带来的磁盘占用。
    'upload_retention_days' => (int) env('TICKET_UPSTREAM_UPLOAD_RETENTION_DAYS', 7),
];
