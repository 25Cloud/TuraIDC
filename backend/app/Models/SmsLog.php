<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SmsLog extends Model
{
    protected $fillable = [
        'plugin_id',
        'driver_key',
        'trace_id',
        'phone',
        'template_code',
        'params',
        'content',
        'status',
        'provider',
        'request_id',
        'error_msg',
        'sent_at',
    ];

    protected $casts = [
        'params' => 'array',
        'sent_at' => 'datetime',
        'trace_id' => 'string',
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
                'origin_type' => 'sms_log',
                'origin_id' => (int) $this->id,
            ],
            [
                'channel' => 'sms',
                'recipient' => trim((string) ($this->phone ?? '')),
                'template_code' => trim((string) ($this->template_code ?? '')) ?: null,
                'subject' => null,
                'content' => (string) ($this->content ?? ''),
                'params_json' => is_array($this->params ?? null)
                    ? json_encode($this->params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
                'provider' => trim((string) ($this->provider ?? '')) ?: null,
                'request_id' => trim((string) ($this->request_id ?? '')) ?: null,
                'status' => trim((string) ($this->status ?? 'pending')),
                'error_msg' => trim((string) ($this->error_msg ?? '')) ?: null,
                'sent_at' => $this->sent_at,
                'created_at' => $this->created_at ?? now(),
                'updated_at' => $this->updated_at ?? now(),
            ]
        );
    }
}
