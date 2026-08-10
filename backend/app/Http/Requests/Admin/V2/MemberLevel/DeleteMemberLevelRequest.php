<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\MemberLevel;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use App\Models\MemberLevel;

class DeleteMemberLevelRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'memberLevel' => ['required', 'integer', 'min:1'],
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $memberLevel = $this->route('memberLevel');

        $this->merge([
            'memberLevel' => $memberLevel instanceof MemberLevel ? $memberLevel->getKey() : $memberLevel,
        ]);
    }
}
