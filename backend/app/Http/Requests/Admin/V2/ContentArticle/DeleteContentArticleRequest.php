<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\ContentArticle;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class DeleteContentArticleRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
            'type' => ['prohibited'],
            'content_category_id' => ['prohibited'],
        ];
    }
}
