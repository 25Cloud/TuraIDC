<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Dashboard;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class ShowDashboardStatsRequest extends AdminFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'per_page' => ['prohibited'],
        ];
    }
}
