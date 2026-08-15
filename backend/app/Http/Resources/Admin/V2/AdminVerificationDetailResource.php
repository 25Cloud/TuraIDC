<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminVerificationDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $verification = is_array($this->resource) ? $this->resource : [];

        return [
            'id' => (int) ($verification['id'] ?? 0),
            'display_name' => (string) ($verification['display_name'] ?? ''),
            'email' => (string) ($verification['email'] ?? ''),
            'phone' => (string) ($verification['phone'] ?? ''),
            'real_name' => (string) ($verification['real_name'] ?? ''),
            'id_card_masked' => (string) ($verification['id_card_masked'] ?? ''),
            'verification_status' => (int) ($verification['verification_status'] ?? 0),
            'verification_status_label' => User::verificationStatusLabel((int) ($verification['verification_status'] ?? 0)),
            'verification_message' => (string) ($verification['verification_message'] ?? ''),
            'verification_biz_code' => (string) ($verification['verification_biz_code'] ?? ''),
            'verification_method_label' => (string) ($verification['verification_method_label'] ?? ''),
            'verification_type_label' => (string) ($verification['verification_type_label'] ?? ''),
            'document_type_label' => (string) ($verification['document_type_label'] ?? ''),
            'identity_region_label' => (string) ($verification['identity_region_label'] ?? ''),
            'created_at' => $verification['created_at'] ?? null,
            'updated_at' => $verification['updated_at'] ?? null,
            'verified_at' => $verification['verified_at'] ?? null,
        ];
    }
}
