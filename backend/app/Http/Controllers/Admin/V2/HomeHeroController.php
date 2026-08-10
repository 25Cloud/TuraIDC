<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\HomeHero\ShowHomeHeroRequest;
use App\Http\Requests\Admin\V2\HomeHero\UpdateHomeHeroRequest;
use App\Http\Resources\Admin\V2\AdminHomeHeroResource;
use App\Services\Content\HomeHeroService;
use App\Services\Content\MediaFileService;
use Illuminate\Http\JsonResponse;

class HomeHeroController extends Controller
{
    public function __construct(
        private readonly HomeHeroService $homeHeroService,
        private readonly MediaFileService $mediaFileService,
    ) {}

    public function show(ShowHomeHeroRequest $request): JsonResponse
    {
        return $this->success(AdminHomeHeroResource::make($this->payload())->resolve());
    }

    public function update(UpdateHomeHeroRequest $request): JsonResponse
    {
        $data = $request->validated();
        $saved = $this->homeHeroService->saveHero(
            (array) ($data['slides'] ?? []),
            (array) ($data['features'] ?? []),
        );

        return $this->success(
            AdminHomeHeroResource::make($this->payload($saved))->resolve(),
            '首页 Banner 已保存',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(?array $hero = null): array
    {
        $hero ??= $this->homeHeroService->getHero();

        return [
            'slides' => (array) ($hero['slides'] ?? []),
            'features' => (array) ($hero['features'] ?? []),
            'defaults' => [
                'slides' => $this->homeHeroService->defaultSlides(),
                'features' => $this->homeHeroService->defaultFeatures(),
            ],
            'options' => [
                'shape' => HomeHeroService::SHAPE_OPTIONS,
                'ribbon_type' => HomeHeroService::RIBBON_TYPE_OPTIONS,
                'videos' => $this->mediaFileService->listHeroVideos(),
            ],
        ];
    }
}
