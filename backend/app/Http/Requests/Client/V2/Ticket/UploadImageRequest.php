<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\Ticket;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;

class UploadImageRequest extends ClientFormRequest
{
    public function rules(): array
    {
        return [
            'file' => ['required', 'image', 'mimetypes:image/jpeg,image/png,image/webp', 'max:5120'],
        ];
    }
}
