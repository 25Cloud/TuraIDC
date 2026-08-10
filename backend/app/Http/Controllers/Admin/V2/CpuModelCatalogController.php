<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\SpecCatalog\SaveCpuModelCatalogRequest;
use App\Http\Requests\Admin\V2\SpecCatalog\ShowCpuModelCatalogRequest;
use App\Http\Resources\Admin\V2\AdminCpuModelCatalogGroupResource;
use App\Services\ProductCatalog\CpuModelCatalogService;
use Illuminate\Http\JsonResponse;

class CpuModelCatalogController extends Controller
{
    public function __construct(
        private readonly CpuModelCatalogService $cpuModelCatalogService,
    ) {}

    public function index(ShowCpuModelCatalogRequest $request): JsonResponse
    {
        return $this->success($this->catalogPayload($this->cpuModelCatalogService->getCatalog()));
    }

    public function update(SaveCpuModelCatalogRequest $request): JsonResponse
    {
        return $this->success(
            $this->catalogPayload($this->cpuModelCatalogService->saveCatalog($request->catalog())),
            'CPU 型号目录已更新'
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function catalogPayload(array $items): array
    {
        $list = collect($items)
            ->map(fn (array $item): array => (new AdminCpuModelCatalogGroupResource($item))->resolve())
            ->values()
            ->all();

        return [
            'list' => $list,
            'total' => count($list),
            'page' => 1,
            'page_size' => count($list),
        ];
    }
}
