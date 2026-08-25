<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\Ticket;
use App\Models\TicketUpstreamDeliveryLog;
use App\Models\User;
use App\Services\System\AdminLogService;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * 「工单上游投递日志」列表/汇总的行为规格。
 *
 * 该链路此前用 ROW_NUMBER() OVER (PARTITION BY ...) 实现「每工单最新事件」，
 * 是全仓唯一的 MySQL 8.0-only 构造，且此前没有任何测试覆盖。改为 5.7 兼容的
 * 分组极值写法后，这些断言锁住与旧实现逐字一致的语义：
 * 最新事件的判定（occurred_at 优先、同秒按 id 平局）、status 只筛最新事件、
 * 嵌套历史上限 UPSTREAM_NESTED_LOG_LIMIT 条但 log_count 记全量、列表按最新事件倒序。
 *
 * 断言范围用 supplier_id 过滤锁定在本测试播种的数据内——测试库是共享库，
 * 不能对全表计数做假设。
 */
class AdminUpstreamDeliveryLogQueryTest extends TestCase
{
    private const NESTED_LIMIT = 20;

    /** @var array{supplier: Supplier, tickets: array<string, Ticket>} */
    private array $seeded;

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = bin2hex(random_bytes(4));
        $user = User::query()->create([
            'email' => 'upstream-latest-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'nickname' => '最新事件语义测试用户',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);
        $supplier = Supplier::query()->create([
            'name' => '最新事件语义供应商',
            'code' => 'latest-sem-'.$suffix,
            'status' => 1,
        ]);

        $makeTicket = fn (string $subject): Ticket => Ticket::query()->create([
            'user_id' => $user->id,
            'department' => 'support',
            'subject' => $subject,
            'priority' => 2,
            'status' => 1,
        ]);
        $tickets = [
            'tie' => $makeTicket('同秒平局工单'),
            'plain' => $makeTicket('普通两事件工单'),
            'bulk' => $makeTicket('超嵌套上限工单'),
        ];

        $row = fn (Ticket $ticket, string $status, Carbon $at, string $event = 'notified'): array => [
            'ticket_id' => $ticket->id,
            'direction' => 'outbound',
            'operation' => 'ticket.create',
            'event' => $event,
            'status' => $status,
            'reason_code' => null,
            'provider_key' => 'zjmf_finance_api',
            'supplier_id' => $supplier->id,
            'attempt' => null,
            'message' => $status.' @ '.$at->format('H:i:s'),
            'occurred_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ];

        $base = Carbon::parse('2026-08-25 10:00:00');
        $rows = [
            // tie 工单：10:05 有两条同秒事件，最新事件必须按 id 平局取后插入的 sending
            $row($tickets['tie'], 'delivered', $base->copy()),
            $row($tickets['tie'], 'failed', $base->copy()->addMinutes(5)),
            $row($tickets['tie'], 'sending', $base->copy()->addMinutes(5)),
            // plain 工单：最新为 11:00 delivered
            $row($tickets['plain'], 'pending', $base->copy()->subHour()),
            $row($tickets['plain'], 'delivered', $base->copy()->addHour()),
        ];
        // bulk 工单：22 条（超上限 20），最新为 12:00 failed
        for ($i = 0; $i < 21; $i++) {
            $rows[] = $row($tickets['bulk'], 'pending', $base->copy()->addMinutes(30 + $i));
        }
        $rows[] = $row($tickets['bulk'], 'failed', $base->copy()->addHours(2));
        TicketUpstreamDeliveryLog::query()->insert($rows);

        $this->seeded = ['supplier' => $supplier, 'tickets' => $tickets];
    }

    /** @return array<string, mixed> */
    private function list(array $extraFilters = []): array
    {
        return app(AdminLogService::class)->getUpstreamLogs(
            array_merge(['supplier_id' => $this->seeded['supplier']->id], $extraFilters),
            page: 1,
            perPage: 10,
        );
    }

    public function test_list_returns_latest_event_per_ticket_ordered_by_latest_time(): void
    {
        $payload = $this->list();
        $tickets = $this->seeded['tickets'];

        $this->assertSame(3, (int) $payload['total']);
        $this->assertSame(
            [(int) $tickets['bulk']->id, (int) $tickets['plain']->id, (int) $tickets['tie']->id],
            array_map(static fn (array $item): int => $item['ticket_id'], $payload['data']),
            '列表须按各工单最新事件时间倒序（12:00 > 11:00 > 10:05）'
        );
        $this->assertSame(
            ['failed', 'delivered', 'sending'],
            array_map(static fn (array $item): string => $item['status'], $payload['data']),
            '每行状态必须取该工单最新事件；同秒平局须按 id 取后插入的一条'
        );
        $this->assertSame('最新事件语义供应商', $payload['data'][0]['supplier_name']);
    }

    public function test_nested_events_are_capped_but_log_count_keeps_full_total(): void
    {
        $bulkRow = $this->list()['data'][0];

        $this->assertSame(22, $bulkRow['log_count'], 'log_count 必须是该工单全量事件数');
        $this->assertCount(self::NESTED_LIMIT, $bulkRow['logs'], '嵌套历史最多 UPSTREAM_NESTED_LOG_LIMIT 条');
        $this->assertSame('failed', $bulkRow['logs'][0]['status'], '嵌套第一条必须是最新事件');
        $occurred = array_map(static fn (array $log): string => (string) $log['occurred_at'], $bulkRow['logs']);
        $sorted = $occurred;
        rsort($sorted);
        $this->assertSame($sorted, $occurred, '嵌套历史必须按时间倒序');
    }

    public function test_status_filter_applies_to_latest_event_only(): void
    {
        $payload = $this->list(['status' => 'failed']);
        $tickets = $this->seeded['tickets'];

        // tie 工单历史里有 failed，但其最新事件是 sending——不得因历史命中而入选。
        $this->assertSame(1, (int) $payload['total']);
        $this->assertSame((int) $tickets['bulk']->id, $payload['data'][0]['ticket_id']);
        // 嵌套历史不受 status 筛选：bulk 的 20 条里绝大多数是 pending
        $nestedStatuses = array_unique(array_map(static fn (array $log): string => $log['status'], $payload['data'][0]['logs']));
        $this->assertContains('pending', $nestedStatuses);
    }

    public function test_summary_counts_latest_event_statuses(): void
    {
        $summary = app(AdminLogService::class)->getUpstreamLogsSummary([
            'supplier_id' => $this->seeded['supplier']->id,
        ]);

        $this->assertSame(
            ['total' => 3, 'failed' => 1, 'delivered' => 1, 'skipped' => 0, 'pending' => 0, 'sending' => 1],
            $summary
        );
    }
}
