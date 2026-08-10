<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\UserService;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use App\Http\Requests\Admin\V2\Concerns\RejectsLegacyPagination;

class DeleteUserServiceRequest extends AdminFormRequest
{
    use RejectsLegacyPagination;

    public function rules(): array
    {
        return $this->allPaginationRules();
    }
}
