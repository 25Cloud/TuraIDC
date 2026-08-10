<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\SpecCatalog\ListInstanceSpecCatalogRequest;
use App\Http\Requests\Admin\V2\SpecCatalog\SaveInstanceSpecCatalogRequest;
use App\Http\Resources\Admin\V2\AdminInstanceSpecCatalogItemResource;
use App\Services\ProductCatalog\InstanceSpecCatalogService;
use Illuminate\Http\JsonResponse;

class InstanceSpecCatalogController extends Controller
{
    public function __construct(
        private readonly InstanceSpecCatalogService $instanceSpecCatalogService,
    ) {}

    public function index(ListInstanceSpecCatalogRequest $request): JsonResponse
    {
        $filters = $request->filters();

        return $this->success($this->catalogPayload(
            $this->instanceSpecCatalogService->getCatalog(
                (string) ($filters['keyword'] ?? ''),
                (string) ($filters['binding_status'] ?? '')
            )
        ));
    }

    public function update(SaveInstanceSpecCatalogRequest $request): JsonResponse
    {
        return $this->success(
            $this->catalogPayload($this->instanceSpecCatalogService->saveCatalog($request->catalog())),
            '实例规格目录已更新'
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function catalogPayload(array $items): array
    {
        $list = collect($items)
            ->map(fn (array $item): array => (new AdminInstanceSpecCatalogItemResource($item))->resolve())
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
