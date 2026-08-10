<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use App\Http\Resources\Admin\V2\Concerns\StripsSensitiveResourceData;
use App\Models\OperationLog;
use App\Support\AdminPrivacy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OperationLog */
class AdminUserOperationLogResource extends JsonResource
{
    use StripsSensitiveResourceData;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $privacy = AdminPrivacy::fromRequest($request);
        $context = is_array($this->context ?? null) ? $this->context : (is_array($this->detail ?? null) ? $this->detail : []);

        return [
            'id' => (int) $this->id,
            'user_id' => $this->user_id !== null ? (int) $this->user_id : null,
            'user_type' => (string) ($this->user_type ?? ''),
            'action' => (string) ($this->action ?? ''),
            'module' => (string) ($this->module ?? ''),
            'subject_id' => $this->subject_id !== null
                ? (int) $this->subject_id
                : ($this->target_id !== null ? (int) $this->target_id : null),
            'context' => $this->stripSensitiveKeys($privacy->payload($context)),
            'ip_address' => $privacy->ip($this->ip_address),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
