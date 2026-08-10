<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\MediaFile;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use finfo;
use Illuminate\Validation\Validator;

class StoreMediaFileRequest extends AdminFormRequest
{
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'video/mp4',
        'video/webm',
        'video/ogg',
        'video/quicktime',
        'video/x-m4v',
    ];

    public function rules(): array
    {
        return [
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
            'file' => ['required', 'file', 'mimetypes:'.implode(',', self::ALLOWED_MIME_TYPES), 'max:102400'],
            'group' => ['nullable', 'string', 'max:50', 'alpha_dash:ascii'],
        ];
    }

    /**
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $file = $this->file('file');
                if (! $file?->isValid()) {
                    return;
                }

                $realPath = $file->getRealPath();
                if (! is_string($realPath) || $realPath === '') {
                    $validator->errors()->add('file', '无法读取上传文件');

                    return;
                }

                $realMime = (new finfo(FILEINFO_MIME_TYPE))->file($realPath);

                if (! in_array($realMime, self::ALLOWED_MIME_TYPES, true)) {
                    $validator->errors()->add('file', '文件真实类型不被允许');
                }
            },
        ];
    }
}
