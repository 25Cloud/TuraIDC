<?php

namespace App\Models;

use App\Support\DatabaseSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EmailLog extends Model
{
    protected $fillable = [
        'template_code',
        'to_email',
        'subject',
        'content',
        'status',
        'error_msg',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saved(function (self $log): void {
            $log->syncNotificationProjection();
        });
    }

    public function syncNotificationProjection(): void
    {
        // DatabaseSchema 记忆化：本方法挂在 saved 事件上，每次写日志都会走一次探测。
        // 群发场景（公告发给上千用户）下裸 Schema::hasTable 就是上千条 information_schema。
        if (! $this->exists || ! DatabaseSchema::hasTable('notification_logs')) {
            return;
        }

        DB::table('notification_logs')->updateOrInsert(
            [
                'origin_type' => 'email_log',
                'origin_id' => (int) $this->id,
            ],
            [
                'channel' => 'email',
                'recipient' => trim((string) ($this->to_email ?? '')),
                'template_code' => trim((string) ($this->template_code ?? '')) ?: null,
                'subject' => trim((string) ($this->subject ?? '')) ?: null,
                'content' => (string) ($this->content ?? ''),
                'params_json' => null,
                'provider' => null,
                'request_id' => null,
                'status' => trim((string) ($this->status ?? 'pending')),
                'error_msg' => trim((string) ($this->error_msg ?? '')) ?: null,
                'sent_at' => $this->sent_at,
                'created_at' => $this->created_at ?? now(),
                'updated_at' => $this->updated_at ?? now(),
            ]
        );
    }
}
