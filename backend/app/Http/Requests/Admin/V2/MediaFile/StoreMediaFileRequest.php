<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\MediaFile;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use App\Support\MediaAssetTypes;
use finfo;
use Illuminate\Validation\Validator;

class StoreMediaFileRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
            'file' => ['required', 'file', 'mimetypes:'.implode(',', MediaAssetTypes::allowedMimeTypes()), 'max:102400'],
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

                // ext-fileinfo 不是必装扩展：缺它时 `new finfo()` 抛的是 Error 而不是 Exception，
                // 框架会直接当 500 抛出——没装该扩展的部署上，上传接口必然 500。
                // 上面的 mimetypes 规则本身走 Symfony 的 MimeTypeGuesser，fileinfo 缺失时会退化成
                // 扩展名推断，仍然挡得住明显不对的类型，所以这里跳过二次校验即可。
                if (! class_exists(finfo::class)) {
                    return;
                }

                $realMime = (new finfo(FILEINFO_MIME_TYPE))->file($realPath);

                if (! in_array($realMime, MediaAssetTypes::allowedMimeTypes(), true)) {
                    $validator->errors()->add('file', '文件真实类型不被允许');
                }
            },
        ];
    }
}
