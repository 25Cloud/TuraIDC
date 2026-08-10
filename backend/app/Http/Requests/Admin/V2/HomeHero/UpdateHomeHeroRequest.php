<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\HomeHero;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use App\Services\Content\HomeHeroService;
use Illuminate\Validation\Rule;

class UpdateHomeHeroRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'slides' => ['required', 'array', 'min:1', 'max:5'],
            'slides.*.key' => ['nullable', 'string', 'max:64'],
            'slides.*.rail_title' => ['required', 'string', 'max:20'],
            'slides.*.title' => ['required', 'string', 'max:80'],
            'slides.*.desc' => ['required', 'string', 'max:300'],
            'slides.*.primary_text' => ['required', 'string', 'max:20'],
            'slides.*.primary_path' => ['required', 'string', 'max:255'],
            'slides.*.secondary_text' => ['required', 'string', 'max:20'],
            'slides.*.secondary_path' => ['required', 'string', 'max:255'],
            // 以下视觉向字段后端按索引自动填充，管理端不再暴露
            'slides.*.shape' => ['nullable', Rule::in(HomeHeroService::SHAPE_OPTIONS)],
            'slides.*.video' => ['nullable', 'string', 'max:255'],
            'slides.*.ribbon' => ['nullable', 'string', 'max:10'],
            'slides.*.ribbon_type' => ['nullable', Rule::in(HomeHeroService::RIBBON_TYPE_OPTIONS)],

            'features' => ['required', 'array', 'min:1', 'max:5'],
            'features.*.key' => ['nullable', 'string', 'max:64'],
            'features.*.kicker' => ['required', 'string', 'max:20'],
            'features.*.title' => ['required', 'string', 'max:50'],
            'features.*.desc' => ['required', 'string', 'max:120'],
            'features.*.path' => ['nullable', 'string', 'max:255'],
            'per_page' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'slides.required' => '请至少配置一个轮播项',
            'slides.min' => '请至少配置一个轮播项',
            'slides.max' => '轮播项最多 5 个',
            'slides.*.rail_title.required' => '轮播导航名称不能为空',
            'slides.*.title.required' => '轮播标题不能为空',
            'slides.*.desc.required' => '轮播描述不能为空',
            'slides.*.primary_text.required' => '主按钮文案不能为空',
            'slides.*.primary_path.required' => '主按钮跳转地址不能为空',
            'slides.*.secondary_text.required' => '次按钮文案不能为空',
            'slides.*.secondary_path.required' => '次按钮跳转地址不能为空',
            'features.required' => '请至少配置一张特色卡片',
            'features.min' => '请至少配置一张特色卡片',
            'features.max' => '特色卡片最多 5 个',
            'features.*.kicker.required' => '卡片标签不能为空',
            'features.*.title.required' => '卡片标题不能为空',
            'features.*.desc.required' => '卡片描述不能为空',
        ];
    }
}
