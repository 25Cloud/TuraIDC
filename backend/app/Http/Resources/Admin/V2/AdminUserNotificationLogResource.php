<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use App\Http\Resources\Admin\V2\Concerns\StripsSensitiveResourceData;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminUserNotificationLogResource extends JsonResource
{
    use StripsSensitiveResourceData;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = $this->resource instanceof Arrayable
            ? $this->resource->toArray()
            : (is_array($this->resource) ? $this->resource : []);

        return [
            'id' => (int) ($item['id'] ?? 0),
            'channel' => (string) ($item['channel'] ?? (array_key_exists('to_email', $item) ? 'email' : 'sms')),
            'phone' => (string) ($item['phone'] ?? ''),
            'to_email' => (string) ($item['to_email'] ?? $item['recipient'] ?? ''),
            'template_code' => (string) ($item['template_code'] ?? ''),
            'subject' => (string) ($item['subject'] ?? ''),
            'provider' => (string) ($item['provider'] ?? ''),
            'driver_key' => (string) ($item['driver_key'] ?? ''),
            'trace_id' => (string) ($item['trace_id'] ?? ''),
            'request_id' => (string) ($item['request_id'] ?? ''),
            'status' => (string) ($item['status'] ?? ''),
            'error_msg' => (string) ($item['error_msg'] ?? ''),
            'sent_at' => $this->dateTime($item['sent_at'] ?? null),
            'created_at' => $this->dateTime($item['created_at'] ?? null),
        ];
    }

    private function dateTime(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        $normalized = trim((string) ($value ?? ''));

        return $normalized !== '' ? $normalized : null;
    }
}
