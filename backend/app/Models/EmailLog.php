<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
        if (! $this->exists || ! Schema::hasTable('notification_logs')) {
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
