<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\IntegrationPlugin;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use App\Services\Integrations\Plugins\PluginDomain;
use Illuminate\Validation\Rule;

class ScanIntegrationPluginsRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'domain' => ['nullable', 'string', Rule::in(PluginDomain::values())],
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
        ];
    }

    public function domain(): ?string
    {
        $domain = trim((string) ($this->validated()['domain'] ?? ''));

        return $domain !== '' ? $domain : null;
    }
}
