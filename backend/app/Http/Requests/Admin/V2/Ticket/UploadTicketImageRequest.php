<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Ticket;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class UploadTicketImageRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'file' => ['required', 'image', 'mimetypes:image/jpeg,image/png,image/webp', 'max:5120'],
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
        ];
    }
}
