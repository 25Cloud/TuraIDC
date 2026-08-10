<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\IntegrationPlugin;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use App\Services\Integrations\Plugins\PluginDomain;
use Illuminate\Validation\Rule;

class InstallIntegrationPluginRequest extends AdminFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'domain' => trim((string) $this->input('domain', '')),
            'slug' => trim((string) $this->input('slug', '')),
        ]);
    }

    public function rules(): array
    {
        return [
            'domain' => ['required', 'string', Rule::in(PluginDomain::values())],
            'slug' => ['required', 'string', 'max:120'],
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
        ];
    }

    public function domain(): string
    {
        return (string) $this->validated()['domain'];
    }

    public function slug(): string
    {
        return (string) $this->validated()['slug'];
    }
}
