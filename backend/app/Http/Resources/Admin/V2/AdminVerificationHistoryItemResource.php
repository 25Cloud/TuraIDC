<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminVerificationHistoryItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $history = is_array($this->resource) ? $this->resource : [];

        return [
            'id' => (int) ($history['id'] ?? 0),
            'real_name' => (string) ($history['real_name'] ?? ''),
            'id_card_masked' => (string) ($history['id_card_masked'] ?? ''),
            'verification_status' => (int) ($history['verification_status'] ?? 0),
            'verification_status_label' => User::verificationStatusLabel((int) ($history['verification_status'] ?? 0)),
            'verification_message' => (string) ($history['verification_message'] ?? ''),
            'verification_method_label' => (string) ($history['verification_method_label'] ?? ''),
            'verification_type_label' => (string) ($history['verification_type_label'] ?? ''),
            'submitted_at' => $history['submitted_at'] ?? null,
            'completed_at' => $history['completed_at'] ?? null,
        ];
    }
}
