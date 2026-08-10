<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\IntegrationPlugin;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class UpdateIntegrationPluginConfigRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'config' => ['required', 'array'],
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        $config = $this->validated()['config'] ?? [];

        return is_array($config) ? $config : [];
    }
}
