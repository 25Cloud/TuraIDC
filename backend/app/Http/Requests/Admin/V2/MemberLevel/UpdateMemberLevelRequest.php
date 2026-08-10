<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\MemberLevel;

use App\Models\MemberLevel;

class UpdateMemberLevelRequest extends CreateMemberLevelRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'memberLevel' => ['required', 'integer', 'min:1'],
        ]);
    }

    protected function prepareForValidation(): void
    {
        $memberLevel = $this->route('memberLevel');

        $this->merge([
            'memberLevel' => $memberLevel instanceof MemberLevel ? $memberLevel->getKey() : $memberLevel,
        ]);
    }
}
