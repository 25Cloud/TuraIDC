<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use App\Models\ContentArticle;
use Illuminate\Http\Request;

class AdminContentArticleDetailResource extends AdminContentArticleListResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ContentArticle $article */
        $article = $this->resource;

        return array_merge(parent::toArray($request), [
            'content' => (string) $article->content,
            'keywords' => $article->keywords,
            'remark' => $article->remark,
        ]);
    }
}
