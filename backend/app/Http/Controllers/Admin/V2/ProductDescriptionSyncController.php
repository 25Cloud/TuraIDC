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
 *
 * 返回口径：提示语必须反映真实结果。此前三个入口一律回「商品描述同步完成」，
 * 「上游商品没有描述」「上游目录里找不到该商品」「拉取上游目录失败」
 * 以及「上游描述与本地一致，什么都没改」全都被报成成功——管理员点了同步、
 * 页面提示成功、前台却没变化，最后只能判断成「同步坏了」。见 describeOutcome()。
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

        return $this->success([...$result, 'outcome' => $this->outcome($result)], $this->message($result));
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

        return $this->success([...$result, 'outcome' => $this->outcome($result)], $this->message($result));
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

        return $this->success([...$result, 'outcome' => $this->outcome($result)], $this->message($result));
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

    /**
     * 结果分类：前端据此决定用成功 / 警告 / 失败哪种提示。
     *
     * - synced：至少一个商品描述被真实改写；
     * - unchanged：都拉到了上游描述，但与本地逐字一致（上游没改，或本地已经是上游那份）；
     * - empty：上游确实没有可用描述；
     * - skipped：未绑定供应商 / 未绑定上游商品 / 供应商不支持目录 / 目录里找不到商品；
     * - failed：拉上游目录失败，一条都没拿到；
     * - partial：部分成功、部分没成。
     *
     * @param  array{items: array<int, array<string, mixed>>, summary: array<string, int>}  $result
     */
    private function outcome(array $result): string
    {
        $summary = $result['summary'] ?? [];
        $synced = (int) ($summary['synced'] ?? 0);
        $changed = (int) ($summary['changed'] ?? 0);
        $skipped = (int) ($summary['skipped'] ?? 0);
        $failed = (int) ($summary['failed'] ?? 0);

        if ($changed > 0) {
            return ($skipped + $failed) > 0 ? 'partial' : 'synced';
        }

        if ($failed > 0) {
            return $synced > 0 ? 'partial' : 'failed';
        }

        if ($synced > 0 && $changed === 0 && $skipped === 0) {
            return 'unchanged';
        }

        return $skipped > 0 ? 'skipped' : 'unchanged';
    }

    /**
     * 与 outcome 配套的提示语：把「为什么没变」直接说给管理员。
     *
     * 单条结果的原因（上游商品没有描述 / 目录里已找不到该商品 / 拉取上游目录失败）
     * 只有一条时直接拼进提示，管理员不用再翻日志。
     *
     * @param  array{items: array<int, array<string, mixed>>, summary: array<string, int>}  $result
     */
    private function message(array $result): string
    {
        $summary = $result['summary'] ?? [];
        $synced = (int) ($summary['synced'] ?? 0);
        $changed = (int) ($summary['changed'] ?? 0);
        $skipped = (int) ($summary['skipped'] ?? 0);
        $failed = (int) ($summary['failed'] ?? 0);
        $reason = $this->firstReason($result);

        if ($changed > 0) {
            $message = $synced === 1 && $skipped === 0 && $failed === 0
                ? '已同步上游描述'
                : "描述同步完成：已更新 {$changed} 个商品";

            if ($skipped > 0) {
                $message .= "，{$skipped} 个跳过";
            }

            if ($failed > 0) {
                $message .= "，{$failed} 个失败";
            }

            return $reason === '' || ($skipped + $failed) === 0 ? $message : $message.'（'.$reason.'）';
        }

        if ($failed > 0 && $synced === 0) {
            return '商品描述同步失败：'.($reason !== '' ? $reason : '拉取上游商品目录失败');
        }

        if ($synced > 0) {
            return '上游描述与本地一致，未产生变更';
        }

        if ($skipped > 0) {
            return '商品描述未同步：'.($reason !== '' ? $reason : '该商品没有可同步的上游描述');
        }

        return '没有需要同步的商品';
    }

    /**
     * 第一条未完成原因，用于把「为什么没变」写进提示。
     *
     * @param  array{items: array<int, array<string, mixed>>, summary: array<string, int>}  $result
     */
    private function firstReason(array $result): string
    {
        foreach ((array) ($result['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $status = (string) ($item['status'] ?? '');
            if ($status === 'skipped' || $status === 'failed') {
                return trim((string) ($item['message'] ?? ''));
            }
        }

        return '';
    }
}
