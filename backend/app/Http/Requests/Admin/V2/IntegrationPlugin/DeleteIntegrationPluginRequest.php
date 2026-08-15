<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\IntegrationPlugin;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class DeleteIntegrationPluginRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'force' => ['sometimes', 'boolean'],
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
        ];
    }

    /**
     * 强制卸载：跳过业务引用校验，硬删绑定关系。仅在管理端二次确认后传入。
     */
    public function force(): bool
    {
        return $this->boolean('force');
    }
}
