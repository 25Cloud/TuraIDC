<?php

namespace Database\Factories;

use App\Models\ContentArticle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ContentArticleFactory extends Factory
{
    protected $model = ContentArticle::class;

    public function definition(): array
    {
        $title = $this->faker->sentence(4);

        return [
            'content_type' => ContentArticle::TYPE_NOTICE,
            'category_id' => null,
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::random(6),
            'content' => $this->faker->paragraphs(2, true),
            'status' => ContentArticle::STATUS_DRAFT,
            'is_pinned' => 0,
            'is_recommended' => 0,
            'sort_order' => 0,
            'view_count' => 0,
        ];
    }
}
