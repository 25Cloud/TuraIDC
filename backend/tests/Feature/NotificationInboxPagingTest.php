<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\UserNotificationType;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notification\InboxService;
use App\Services\Notification\UserNotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 站内信列表的分页与查询开销。
 *
 * 修复前的 `InboxService::list()` 会把该用户**全部**站内信读进内存再 slice：
 * 通知累积到几百上千条后，翻第 1 页也要 hydrate 全量模型。
 * 现在总数走 SQL `count()`，明细只取到「本页末尾」。
 */
class NotificationInboxPagingTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, string> */
    private array $capturedQueries = [];

    public function test_list_only_loads_rows_needed_by_current_page(): void
    {
        $user = User::factory()->create();
        $this->seedNotifications($user, 120);

        $this->startQueryCapture();

        $payload = app(InboxService::class)->list((int) $user->id, false, 1, 10);

        $this->assertCount(10, $payload['list']);
        // 公告可能被其它用例写入，这里只断言「至少覆盖本人 120 条」
        $this->assertGreaterThanOrEqual(120, $payload['total']);

        $personalSelect = $this->firstQueryContainingAll(['from `user_notifications`', 'limit']);
        $this->assertNotNull($personalSelect, '明细查询必须带 limit，不能整表载入');

        // 总数单独走 count()，不该把明细一起捞出来
        $this->assertNotNull($this->firstQueryContainingAll(['count(*)', 'from `user_notifications`']));
    }

    public function test_second_page_returns_the_next_slice_with_same_total(): void
    {
        $user = User::factory()->create();
        $this->seedNotifications($user, 25);

        $first = app(InboxService::class)->list((int) $user->id, false, 1, 10);
        $second = app(InboxService::class)->list((int) $user->id, false, 2, 10);

        $this->assertSame($first['total'], $second['total']);
        $this->assertCount(10, $second['list']);

        $firstIds = array_column($first['list'], 'raw_id');
        $secondIds = array_column($second['list'], 'raw_id');
        $this->assertEmpty(array_intersect($firstIds, $secondIds), '两页不应重复');
    }

    public function test_marketing_preference_is_queried_once_per_user(): void
    {
        $user = User::factory()->create(['marketing_alert' => 0]);

        $service = app(UserNotificationService::class);
        $this->startQueryCapture();

        // 同一用户连续两次营销类通知：偏好只该查一次库
        $service->create((int) $user->id, UserNotificationType::ORDER_PAID, '营销一', '内容', null, [], true);
        $service->create((int) $user->id, UserNotificationType::ORDER_PAID, '营销二', '内容', null, [], true);

        $userQueries = array_values(array_filter(
            $this->capturedQueries,
            static fn (string $sql): bool => str_contains($sql, 'from `users`')
        ));

        $this->assertCount(1, $userQueries);
    }

    private function seedNotifications(User $user, int $count): void
    {
        $now = now();

        for ($index = 0; $index < $count; $index++) {
            UserNotification::query()->create([
                'user_id' => (int) $user->id,
                'type' => UserNotificationType::ORDER_PAID,
                'title' => '通知 '.$index,
                'content' => '内容 '.$index,
                'link' => '/client/orders',
                'read_at' => null,
                'created_at' => $now->copy()->subMinutes($index),
                'updated_at' => $now->copy()->subMinutes($index),
            ]);
        }
    }

    /**
     * 注册一次 SQL 监听并记录到实例属性。
     *
     * 注意不能把数组按引用交给闭包再作为返回值：方法返回时局部数组按值复制，
     * 拿到的永远是空数组（本用例第一版就踩了这个坑）。
     */
    private function startQueryCapture(): void
    {
        $this->capturedQueries = [];

        DB::listen(function ($query): void {
            $this->capturedQueries[] = (string) $query->sql;
        });
    }

    private function firstQueryContaining(string $needle): ?string
    {
        foreach ($this->capturedQueries as $sql) {
            if (str_contains($sql, $needle)) {
                return $sql;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function firstQueryContainingAll(array $needles): ?string
    {
        foreach ($this->capturedQueries as $sql) {
            foreach ($needles as $needle) {
                if (! str_contains($sql, $needle)) {
                    continue 2;
                }
            }

            return $sql;
        }

        return null;
    }
}
