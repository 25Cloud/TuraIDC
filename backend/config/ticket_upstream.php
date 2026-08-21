<?php

declare(strict_types=1);

return [
    'callback_secret' => env('TICKET_UPSTREAM_CALLBACK_SECRET', ''),

    // 上游 /upload_image 是否强制凭证校验。
    // 默认 true（fail-closed）：上游 pushTicketReply 需携带 id/token（与回调验签同源的
    // downstream_token）上传附件。上游系统未同步配套修改前，可临时置为 false 放行
    // 无凭证上传（会记录告警日志并重新暴露匿名上传风险），部署配套修改后应恢复 true。
    'upload_token_required' => env('TICKET_UPSTREAM_UPLOAD_TOKEN_REQUIRED', true),
];
