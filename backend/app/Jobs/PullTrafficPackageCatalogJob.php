<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\BusinessException;
use App\Services\Admin\V2\AdminCatalogActionV2Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 一键拉取流量包档位（后台任务）。
 *
 * 为什么要有它：`pullCatalogTemplateForAdmin()` 会串行访问上游 HTTP，最坏情况几分钟，
 * 放在请求里同步跑会让管理端页面长时间白屏、网关超时、重试又重复拉动上游。
 * 改成「提交即返回 task_id，前端按 2 秒轮询进度」后，请求只做参数校验与入队。
 *
 * 状态存在 Cache 键 `admin:traffic-package-pull:{taskId}`（TTL 1 小时），
 * 因为这是一次性、可丢弃的进度数据，不值得建表；过期后前端按「任务不存在或已过期」提示重试。
 *
 * 进度节点说明：10%（校验参数）与 90%（整理导入模板）由本任务自己做；
 * 30%（选择上游实例）与 60%（读取上游流量包档位）落在
 * `ServiceTrafficPackageService::pullCatalogTemplateForAdmin()` 内部，该方法不可修改，
 * 因此这两个节点只能作为「当前正在等待的阶段」标签在调用前后写入——
 * 阻塞期间读到的是 60%，前端同时显示已耗时，管理员据此判断上游到底卡了多久。
 */
final class PullTrafficPackageCatalogJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** 进度缓存键前缀：控制器与任务共用同一处定义，避免两边写错前缀对不上 */
    public const CACHE_PREFIX = 'admin:traffic-package-pull:';

    /** 进度缓存存活 1 小时：够管理员刷新几次页面，过期即视为任务失效 */
    public const CACHE_TTL_SECONDS = 3600;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    /** 单次任务最长跑 10 分钟：与前端 10 分钟轮询上限对齐，超时由队列判失败 */
    public int $timeout = 600;

    /** 上游一次拉取要么成要么败，重试只会重复访问上游并多写一份结果 */
    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $payload  FormRequest 已校验过的入参
     */
    public function __construct(public string $taskId, public array $payload)
    {
        // 刻意落在 default：queue:drain 只消费 provision / business / automation 三组，
        // 单独开一个 traffic_package 队列会在存量部署上永远没人消费（前端一直 pending 到超时）。
        // 代价是拉取期间 business 组被占住，而这是管理员手动触发的低频操作，可以接受。
        $this->onQueue('default');
        $this->afterCommit();
    }

    public static function cacheKey(string $taskId): string
    {
        return self::CACHE_PREFIX.$taskId;
    }

    /**
     * 提交瞬间的初始状态：接口返回它，轮询第一帧也读它。
     *
     * @return array<string, mixed>
     */
    public static function initialState(string $taskId): array
    {
        return [
            'task_id' => $taskId,
            'status' => self::STATUS_PENDING,
            'progress' => 0,
            'step' => '任务已提交，等待队列执行',
            'result' => null,
            'error' => null,
            'started_at' => null,
            'finished_at' => null,
        ];
    }

    /**
     * @return array<string, mixed>|null 过期或从未提交时返回 null
     */
    public static function readState(string $taskId): ?array
    {
        if ($taskId === '') {
            return null;
        }

        $state = Cache::get(self::cacheKey($taskId));

        return is_array($state) ? $state : null;
    }

    /**
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed> 合并后的状态
     */
    public static function writeState(string $taskId, array $patch): array
    {
        $state = array_merge(self::readState($taskId) ?? self::initialState($taskId), $patch);

        Cache::put(self::cacheKey($taskId), $state, self::CACHE_TTL_SECONDS);

        return $state;
    }

    /**
     * 落初始状态并返回，供提交接口直接回包。
     *
     * @return array<string, mixed>
     */
    public static function seedState(string $taskId): array
    {
        $state = self::initialState($taskId);

        Cache::put(self::cacheKey($taskId), $state, self::CACHE_TTL_SECONDS);

        return $state;
    }

    public function handle(AdminCatalogActionV2Service $actions): void
    {
        self::writeState($this->taskId, [
            'status' => self::STATUS_RUNNING,
            'progress' => 5,
            'step' => '任务开始执行',
            'started_at' => now()->toDateTimeString(),
        ]);

        try {
            $this->progress(10, '校验商品分类与拉取来源');
            $payload = $this->assertPayload();

            // 下面两次写进度都在同一段阻塞调用前面：30% 是上游服务的第一步（选实例），
            // 60% 才是真正耗时的那一步（读档位）。中间无法插桩，前端靠已耗时判断卡了多久。
            $this->progress(30, '选择上游实例');
            $this->progress(60, '读取上游流量包档位（上游较慢时需要几分钟）');

            $result = $actions->pullTrafficPackageCatalog($payload);

            $this->progress(90, '生成导入模板');

            self::writeState($this->taskId, [
                'status' => self::STATUS_DONE,
                'progress' => 100,
                'step' => '拉取完成',
                'result' => $this->normalizeResult($result),
                'error' => null,
                'finished_at' => now()->toDateTimeString(),
            ]);
        } catch (\Throwable $exception) {
            self::writeState($this->taskId, [
                'status' => self::STATUS_FAILED,
                'step' => '拉取失败',
                'error' => $this->failureMessage($exception),
                'finished_at' => now()->toDateTimeString(),
            ]);

            Log::warning('[流量包拉取] 后台任务失败', [
                'task_id' => $this->taskId,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            // 继续抛：队列侧要把这次执行记成失败（tries=1 直接进 failed_jobs），
            // 同步降级时也让控制器拿到原始异常，按老契约回 422。
            throw $exception;
        }
    }

    /**
     * 队列把任务判死时的兜底（超时被杀、进程重启等 handle 里的 catch 没跑到的情况）。
     */
    public function failed(\Throwable $exception): void
    {
        $state = self::readState($this->taskId);
        if (($state['status'] ?? '') === self::STATUS_DONE) {
            return;
        }

        self::writeState($this->taskId, [
            'status' => self::STATUS_FAILED,
            'step' => '拉取失败',
            'error' => $this->failureMessage($exception),
            'finished_at' => now()->toDateTimeString(),
        ]);

        Log::error('[流量包拉取] 后台任务异常退出', [
            'task_id' => $this->taskId,
            'message' => $exception->getMessage(),
            'exception' => $exception::class,
        ]);
    }

    private function progress(int $progress, string $step): void
    {
        self::writeState($this->taskId, [
            'status' => self::STATUS_RUNNING,
            'progress' => max(0, min(99, $progress)),
            'step' => $step,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function assertPayload(): array
    {
        $secondGroupId = (int) ($this->payload['second_product_group_id'] ?? 0);
        $thirdGroupId = (int) ($this->payload['third_product_group_id'] ?? 0);

        if ($secondGroupId <= 0 && $thirdGroupId <= 0) {
            throw new BusinessException('请选择有效的商品分类');
        }

        return $this->payload;
    }

    /**
     * 统一结果形状：老前端直接读 data.packages，新前端读 data.result，两边都要拿得到。
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function normalizeResult(array $result): array
    {
        if (array_key_exists('packages', $result) && ! is_array($result['packages'])) {
            $result['packages'] = [];
        }

        return $result;
    }

    private function failureMessage(\Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        return $message === '' ? '拉取失败，请稍后重试' : $message;
    }
}
