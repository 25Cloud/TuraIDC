<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use App\Models\User;
use App\Support\AdminPrivacy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminVerificationListItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;
        $privacy = AdminPrivacy::fromRequest($request);
        $email = (string) $user->email;
        $phone = (string) ($user->phone ?? '');
        $realName = (string) ($user->real_name ?? '');

        return [
            'id' => (int) $user->id,
            'email' => $privacy->email($email),
            'phone' => $privacy->phone($phone),
            'nickname' => (string) ($user->nickname ?? ''),
            'display_name' => $privacy->displayName($user->display_name, $email, $phone, $realName),
            'real_name' => $privacy->name($realName),
            'id_card_masked' => $privacy->idCard($user->id_card),
            'verification_status' => (int) ($user->verification_status ?? 0),
            'verification_status_label' => User::verificationStatusLabel((int) ($user->verification_status ?? 0)),
            'verification_message' => (string) ($user->verification_message ?? ''),
            'created_at' => $user->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
