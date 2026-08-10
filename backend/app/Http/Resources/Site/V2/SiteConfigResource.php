<?php

declare(strict_types=1);

namespace App\Http\Resources\Site\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SiteConfigResource extends JsonResource
{
    private const FIELDS = [
        'site_name',
        'browser_title',
        'site_logo',
        'site_favicon',
        'client_console_icon',
        'service_qq_group',
        'service_phone',
        'service_email',
        'service_hours',
        'support_group_title',
        'support_group_text',
        'support_group_qr',
        'support_group_link',
        'terms_url',
        'privacy_url',
    ];

    /**
     * @return array<string, string>
     */
    public function toArray(Request $request): array
    {
        $payload = is_array($this->resource) ? $this->resource : [];

        return collect(self::FIELDS)
            ->mapWithKeys(static fn (string $field): array => [$field => (string) ($payload[$field] ?? '')])
            ->all();
    }
}
