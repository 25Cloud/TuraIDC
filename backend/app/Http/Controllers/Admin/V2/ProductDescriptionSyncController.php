<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\Product\BatchUpdateDescriptionAutoSyncRequest;
use App\Http\Requests\Admin\V2\Product\BatchUpdateGroupDescriptionAutoSyncRequest;
use App\Http\Requests\Admin\V2\Product\SyncGroupDescriptionRequest;
use App\Http\Requests\Admin\V2\Product\SyncProductDescriptionRequest;
use App\Http\Requests\Admin\V2\Product\UpdateDescriptionSyncSettingsRequest;
use App\Models\Product;
use App\Services\ProductCatalog\ProductDescriptionSyncService;
use App\Services\System\SettingService;
use Illuminate\Http\JsonResponse;

/**
 * 商品描述的上游同步接口。
 *
 * 写路径只有「从上游拉」这一种语义：描述字段的人工编辑仍走商品表单，
 * 这里不提供通用的描述写接口（同一份数据不给第二个写入口）。
 * 全局节拍复用 automation 分组的既有键与 AutomationScheduleExpression 词表，
 * 不另造一套调度配置。
 */
class ProductDescriptionSyncController extends Controller
{
    public function __construct(
        private readonly ProductDescriptionSyncService $sync,
        private readonly SettingService $settings,
    ) {}

    /**
     * 单商品立即同步上游描述。
     */
    public function syncProduct(SyncProductDescriptionRequest $request, Product $product): JsonResponse
    {
        $overwrite = (bool) ($request->validated()['overwrite'] ?? true);

        $result = $this->sync->syncProducts([(int) $product->id], $overwrite);

        return $this->success($result, '商品描述同步完成');
    }

    /**
     * 批量立即同步上游描述。
     */
    public function syncBatch(SyncProductDescriptionRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $productIds = array_map('intval', (array) ($validated['product_ids'] ?? []));

        if ($productIds === []) {
            throw new BusinessException('请至少选择一个商品');
        }

        $result = $this->sync->syncProducts($productIds, (bool) ($validated['overwrite'] ?? true));

        return $this->success($result, '商品描述同步完成');
    }

    /**
     * 批量开关商品级自动同步。
     */
    public function autoSync(BatchUpdateDescriptionAutoSyncRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->sync->setAutoSync(
            array_map('intval', (array) $validated['product_ids']),
            (bool) $validated['enabled']
        );

        return $this->success($result, $result['enabled'] ? '已开启描述自动同步' : '已关闭描述自动同步');
    }

    /**
     * 分组批量同步上游描述。
     */
    public function syncGroup(SyncGroupDescriptionRequest $request, int $group): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->sync->syncGroup(
            $group,
            (int) $validated['level'],
            (bool) ($validated['overwrite'] ?? true)
        );

        return $this->success($result, '分组商品描述同步完成');
    }

    /**
     * 分组批量开关自动同步。
     */
    public function autoSyncGroup(BatchUpdateGroupDescriptionAutoSyncRequest $request, int $group): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->sync->setGroupAutoSync(
            $group,
            (int) $validated['level'],
            (bool) $validated['enabled']
        );

        return $this->success($result, $result['enabled'] ? '已开启描述自动同步' : '已关闭描述自动同步');
    }

    /**
     * 全局同步设置：节拍 + 统计。
     */
    public function settings(): JsonResponse
    {
        $automation = $this->settings->getAutomationConfig();

        return $this->success([
            'schedule_mode' => (string) ($automation['product_description_sync_schedule_mode'] ?? ''),
            'schedule_time' => (string) ($automation['product_description_sync_schedule_time'] ?? ''),
            'stats' => $this->sync->stats(),
        ]);
    }

    /**
     * 保存全局同步节拍。
     */
    public function updateSettings(UpdateDescriptionSyncSettingsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $this->settings->saveGroupSettings('automation', [
            'product_description_sync_schedule_mode' => $validated['schedule_mode'],
            'product_description_sync_schedule_time' => $validated['schedule_time'],
        ]);

        $automation = $this->settings->getAutomationConfig();

        return $this->success([
            'schedule_mode' => (string) $automation['product_description_sync_schedule_mode'],
            'schedule_time' => (string) $automation['product_description_sync_schedule_time'],
        ], '同步设置已保存');
    }
}
