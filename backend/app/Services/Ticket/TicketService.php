<?php

declare(strict_types=1);

namespace App\Services\Ticket;

use App\Constants\ServiceStatus;
use App\Constants\UserNotificationType;
use App\Exceptions\BusinessException;
use App\Jobs\SendTicketNotificationEmailJob;
use App\Models\AdminUser;
use App\Models\Product;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Services\ClientServiceConsole\ServiceTransformService;
use App\Services\Integrations\Plugins\PluginBindingResolver;
use App\Services\Notification\UserNotificationService;
use App\Services\System\NotificationService;
use App\Services\System\UploadedAssetReferenceService;
use App\Support\AdminPermissions;
use App\Support\AdminPrivacy;
use App\Support\PublicUrl;
use App\Support\SecureAsset;
use App\Support\ServiceHostname;
use App\Support\TextSanitizer;
use App\Support\UploadedImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Predis\Client;

class TicketService
{
    private const ADMIN_ASSIGNABLE_USERS_LIMIT = 100;

    private static array $tableExistsCache = [];

    private ?bool $databaseQueueReady = null;

    private static function tableExists(string $table): bool
    {
        return self::$tableExistsCache[$table] ??= Schema::hasTable($table);
    }

    public const DEPARTMENTS = ['sales', 'support', 'billing', 'abuse'];

    public const DEPT_LABELS = ['sales' => '销售', 'support' => '技术支持', 'billing' => '财务', 'abuse' => '投诉'];

    public const PRIORITIES = [1 => '低', 2 => '中', 3 => '高', 4 => '紧急'];

    public const STATUS_OPEN = 0;

    public const STATUS_CLIENT_REPLY = 1;

    public const STATUS_STAFF_REPLY = 2;

    public const STATUS_CLOSED = 3;

    public const STATUS_LABELS = [0 => '开启', 1 => '客户回复', 2 => '员工回复', 3 => '已关闭'];

    public const CLOSE_REASON_ADMIN = 'admin';

    public const CLOSE_REASON_CLIENT = 'client';

    public const CLOSE_REASON_AUTO = 'auto';

    public const CLOSE_REASON_LABELS = [
        'admin' => '管理员关闭',
        'client' => '用户关闭',
        'auto' => '自动关闭',
    ];

    public function __construct(
        private UploadedAssetReferenceService $uploadedAssetReferenceService,
        private NotificationService $notificationService,
        private ServiceTransformService $serviceTransformService,
        private UserNotificationService $userNotificationService,
        private TicketDeliveryService $ticketDeliveryService,
    ) {}

    /**
     * 客户创建工单
     */
    public function create(int $userId, array $data): Ticket
    {
        $subject = TextSanitizer::clean((string) ($data['subject'] ?? ''));
        $content = $this->normalizeReplyContent($data['content'] ?? null);
        $attachments = $this->normalizeAttachments($data['attachments'] ?? [], 'client', $userId);
        $this->ensureReplyPayload($content, $attachments);
        throw_if($subject === '', new BusinessException('工单标题不能为空'));

        $ticket = DB::transaction(function () use ($userId, $data, $subject, $content, $attachments) {
            if (! empty($data['service_id'])) {
                $svc = Service::where('id', $data['service_id'])->where('user_id', $userId)->first();
                throw_if(! $svc, new BusinessException('服务不存在'));
            }

            $ticket = Ticket::create([
                'user_id' => $userId,
                'department' => $data['department'],
                'subject' => $subject,
                'priority' => $data['priority'] ?? 2,
                'status' => self::STATUS_OPEN,
                'service_id' => $data['service_id'] ?? null,
            ]);

            TicketReply::create([
                'ticket_id' => $ticket->id,
                'user_id' => $userId,
                'content' => $content,
                'is_staff' => 0,
                'sender_type' => 'client',
                'sender_name' => null,
                'attachments' => $attachments,
                'created_at' => now(),
            ]);

            return $ticket->fresh();
        });

        $this->notifyAdminsOfNewTicket($ticket, $content, $attachments !== []);
        $this->ticketDeliveryService->queueTicket($ticket);

        return $ticket;
    }

    /**
     * 客户回复
     */
    public function clientReply(Ticket $ticket, int $userId, ?string $content, array $attachmentPaths = [], ?int $quoteReplyId = null): array
    {
        throw_if($ticket->user_id !== $userId, new BusinessException('无权操作'));
        throw_if($ticket->status === self::STATUS_CLOSED, new BusinessException('工单已关闭'));

        $content = $this->normalizeReplyContent($content);
        $attachments = $this->normalizeAttachments($attachmentPaths, 'client', $userId);
        $this->ensureReplyPayload($content, $attachments);

        if ($quoteReplyId !== null) {
            $quoted = TicketReply::where('ticket_id', $ticket->id)->find($quoteReplyId);
            throw_if(! $quoted, new BusinessException('引用的消息不存在'));
            throw_if($quoted->recalled_at !== null, new BusinessException('不能引用已撤回的消息'));
        }

        [$formattedReply, $replyId] = DB::transaction(function () use ($ticket, $userId, $content, $attachments, $quoteReplyId) {
            // 行锁内重读：关闭与回复并发时先到先得，关闭已提交则拒绝再写入回复，防止已关闭工单被重新打开。
            $lockedTicket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            throw_if((int) $lockedTicket->status === self::STATUS_CLOSED, new BusinessException('工单已关闭'));

            $reply = TicketReply::create([
                'ticket_id' => $lockedTicket->id,
                'user_id' => $userId,
                'content' => $content,
                'is_staff' => 0,
                'sender_type' => 'client',
                'sender_name' => null,
                'attachments' => $attachments,
                'quote_reply_id' => $quoteReplyId,
                'created_at' => now(),
            ]);
            $lockedTicket->update(['status' => self::STATUS_CLIENT_REPLY]);

            $lockedTicket->loadMissing('user:id,email,nickname');

            return [$this->formatReply($reply, $lockedTicket->user?->display_name ?: '客户'), (int) $reply->id];
        });

        $this->notifyAdminsOfClientReply($ticket, $content, $attachments !== []);
        $reply = TicketReply::query()->find($replyId);
        if ($reply instanceof TicketReply) {
            $this->ticketDeliveryService->queueClientReply($reply);
        }

        return $formattedReply;
    }

    /**
     * 客户关闭工单
     */
    public function clientClose(Ticket $ticket, int $userId): void
    {
        throw_if($ticket->user_id !== $userId, new BusinessException('无权操作'));
        throw_if($ticket->status === self::STATUS_CLOSED, new BusinessException('工单已关闭'));
        $this->closeTicketAndReplaceAttachments($ticket, self::CLOSE_REASON_CLIENT);
    }

    /**
     * 管理端回复
     */
    public function staffReply(Ticket $ticket, int $staffId, ?string $content, array $attachmentPaths = [], ?int $quoteReplyId = null): array
    {
        throw_if($ticket->status === self::STATUS_CLOSED, new BusinessException('工单已关闭'));

        $content = $this->normalizeReplyContent($content);
        $attachments = $this->normalizeAttachments($attachmentPaths, 'admin', $staffId);
        $this->ensureReplyPayload($content, $attachments);
        $staff = AdminUser::query()->find($staffId);
        $staffName = $staff?->nickname ?: $staff?->username ?: '员工';

        if ($quoteReplyId !== null) {
            $quoted = TicketReply::where('ticket_id', $ticket->id)->find($quoteReplyId);
            throw_if(! $quoted, new BusinessException('引用的消息不存在'));
            throw_if($quoted->recalled_at !== null, new BusinessException('不能引用已撤回的消息'));
        }

        [$formattedReply, $replyId] = DB::transaction(function () use ($ticket, $staffId, $content, $attachments, $staffName, $quoteReplyId) {
            // 行锁内重读：关闭与回复并发时先到先得，关闭已提交则拒绝再写入回复。
            $lockedTicket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            throw_if((int) $lockedTicket->status === self::STATUS_CLOSED, new BusinessException('工单已关闭'));

            $reply = TicketReply::create([
                'ticket_id' => $lockedTicket->id,
                'user_id' => $staffId,
                'content' => $content,
                'is_staff' => 1,
                'sender_type' => 'admin',
                'sender_name' => $staffName,
                'attachments' => $attachments,
                'quote_reply_id' => $quoteReplyId,
                'created_at' => now(),
            ]);
            $lockedTicket->update(['status' => self::STATUS_STAFF_REPLY]);

            return [$this->formatReply($reply, $staffName), (int) $reply->id];
        });

        $this->notifyClientOfStaffReply($ticket, $staffName, $content, $attachments !== []);
        $reply = TicketReply::query()->find($replyId);
        if ($reply instanceof TicketReply) {
            $this->ticketDeliveryService->queueStaffReply($reply);
        }

        return $formattedReply;
    }

    /**
     * 管理端关闭工单
     */
    public function staffClose(Ticket $ticket): void
    {
        throw_if($ticket->status === self::STATUS_CLOSED, new BusinessException('工单已关闭'));
        $this->closeTicketAndReplaceAttachments($ticket, self::CLOSE_REASON_ADMIN);
    }

    public function autoClose(Ticket $ticket): void
    {
        if ((int) $ticket->status === self::STATUS_CLOSED) {
            return;
        }

        // 仅在本次真正执行关闭时才通知：并发被抢先关闭/员工已关闭时，closeTicketAndReplaceAttachments
        // 行锁内重读发现已关闭会跳过，若仍无条件通知会重复发信或发出误导的"已自动关闭"文案。
        $closed = $this->closeTicketAndReplaceAttachments($ticket, self::CLOSE_REASON_AUTO);
        if (! $closed) {
            return;
        }

        // 自动关闭后通知客户（模板 100025 接线），附件已软删保留可重开追溯。
        $this->notifyClientOfAutoClose($ticket);
    }

    /**
     * 重开已关闭工单：CLOSED → OPEN，并清空关闭原因。
     * 附件在关闭时已软删保留，重开后历史附件仍可查看。
     */
    public function reopen(Ticket $ticket, array $context = []): Ticket
    {
        DB::transaction(function () use ($ticket) {
            $lockedTicket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);

            throw_if(
                (int) $lockedTicket->status !== self::STATUS_CLOSED,
                new BusinessException('仅已关闭工单可重开')
            );

            $lockedTicket->forceFill([
                'status' => self::STATUS_OPEN,
                'close_reason' => null,
            ])->save();

            // 重开后恢复历史附件可见（清除软删标记）。
            TicketReply::query()
                ->where('ticket_id', $lockedTicket->id)
                ->whereNotNull('attachments')
                ->get(['id', 'attachments'])
                ->each(function (TicketReply $reply): void {
                    $attachments = collect($reply->attachments ?? [])
                        ->map(function ($attachment) {
                            if (is_array($attachment)) {
                                $attachment['deleted'] = false;
                            }

                            return $attachment;
                        })
                        ->all();

                    $reply->update(['attachments' => $attachments]);
                });
        });

        $updatedTicket = $ticket->fresh() ?? $ticket;

        $this->userNotificationService->create(
            (int) ($updatedTicket->user_id ?? 0),
            UserNotificationType::TICKET_REOPENED,
            '工单已重新开启',
            "工单「{$updatedTicket->subject}」已重新开启，请继续跟进。",
            '/client/tickets/'.$updatedTicket->id,
            [
                'ticket_id' => (int) $updatedTicket->id,
                'reopened_by' => (string) ($context['operator_type'] ?? 'admin'),
                'trace_id' => (string) ($context['trace_id'] ?? ''),
            ]
        );

        return $updatedTicket;
    }

    /**
     * 撤回消息（两分钟内，仅发送者本人可操作）
     */
    public function recallReply(Ticket $ticket, int $replyId, int $operatorId, bool $isStaff = false): void
    {
        $reply = TicketReply::where('ticket_id', $ticket->id)->findOrFail($replyId);

        throw_if((int) $reply->user_id !== $operatorId || (int) $reply->is_staff !== ($isStaff ? 1 : 0), new BusinessException('只能撤回自己发送的消息'));
        throw_if($reply->recalled_at !== null, new BusinessException('该消息已撤回'));

        $createdAt = $reply->created_at instanceof Carbon ? $reply->created_at : Carbon::parse((string) $reply->created_at);
        throw_if($createdAt->diffInSeconds(now()) > 120, new BusinessException('超过两分钟，无法撤回'));

        $reply->update([
            'recalled_at' => now(),
            'content' => '',
            'attachments' => [],
        ]);
    }

    /**
     * 管理端指派处理人
     */
    public function assign(Ticket $ticket, int $assigneeId): Ticket
    {
        $assignee = AdminUser::query()->withResolvedPermissionRelations()->find($assigneeId);

        throw_if(! $assignee, new BusinessException('管理员不存在'));
        throw_if((int) $assignee->status !== 1, new BusinessException('管理员已禁用'));
        throw_if(! $assignee->hasPermission(AdminPermissions::TICKET_REPLY), new BusinessException('该管理员无工单处理权限'));

        $ticket->update(['assignee_id' => $assigneeId]);

        return $ticket->fresh();
    }

    public function clientTicket(int $userId, int $ticketId): Ticket
    {
        return Ticket::query()
            ->where('user_id', $userId)
            ->findOrFail($ticketId);
    }

    /**
     * 客户端列表
     */
    public function clientList(int $userId, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = Ticket::where('user_id', $userId);

        if (self::tableExists('ticket_replies')) {
            $query->with(['replies' => fn ($q) => $q->latest('created_at')->limit(1)]);
        }

        if (isset($filters['status']) && $filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['keyword'])) {
            $query->where('subject', 'like', "%{$filters['keyword']}%");
        }

        return $query->orderByDesc('updated_at')->paginate($perPage);
    }

    /**
     * 管理端列表
     */
    public function adminList(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = Ticket::with(['user:id,email,nickname', 'assignee:id,username,nickname']);

        if (isset($filters['status']) && $filters['status'] !== '') {
            if ((string) $filters['status'] === 'ongoing') {
                $query->whereIn('status', [
                    self::STATUS_OPEN,
                    self::STATUS_CLIENT_REPLY,
                    self::STATUS_STAFF_REPLY,
                ]);
            } else {
                $query->where('status', $filters['status']);
            }
        }

        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (! empty($filters['department'])) {
            $query->where('department', $filters['department']);
        }

        if (! empty($filters['keyword'])) {
            $kw = $filters['keyword'];
            $query->where(function ($q) use ($kw) {
                $q->where('subject', 'like', "%{$kw}%")
                    ->orWhere('id', $kw);
            });
        }

        return $query->orderByDesc('updated_at')->paginate($perPage);
    }

    /**
     * 工单详情（含全部回复）
     */
    public function detail(Ticket $ticket): array
    {
        $ticket->load([
            'user:id,email,nickname',
            'service:id,name,domain,product_id,billing_cycle,amount,status,provision_data,expires_at',
            'assignee:id,username,nickname',
        ]);

        $clientName = $ticket->user?->display_name ?: '客户';
        $replies = $this->resolveTicketReplies($ticket, $clientName);

        return [
            'id' => (int) $ticket->id,
            'user_id' => (int) $ticket->user_id,
            'department' => $ticket->department,
            'subject' => $ticket->subject,
            'priority' => (int) $ticket->priority,
            'status' => (int) $ticket->status,
            'service_id' => $ticket->service_id ? (int) $ticket->service_id : null,
            'assignee_id' => $ticket->assignee_id ? (int) $ticket->assignee_id : null,
            'close_reason' => $ticket->close_reason,
            'close_reason_label' => self::CLOSE_REASON_LABELS[$ticket->close_reason ?? ''] ?? null,
            'created_at' => $ticket->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $ticket->updated_at?->format('Y-m-d H:i:s'),
            'user' => $ticket->user ? [
                'id' => (int) $ticket->user->id,
                'email' => $ticket->user->email,
                'nickname' => $ticket->user->nickname,
                'display_name' => $ticket->user->display_name,
            ] : null,
            'service' => $ticket->service ? [
                'id' => (int) $ticket->service->id,
                'name' => $ticket->service->name,
                'display_name' => $this->resolveServiceDisplayName($ticket->service),
                ...$this->resolveLinkedServicePayload($ticket->service),
            ] : null,
            'assignee' => $ticket->assignee ? [
                'id' => (int) $ticket->assignee->id,
                'username' => $ticket->assignee->username,
                'nickname' => trim((string) $ticket->assignee->nickname) !== '' ? $ticket->assignee->nickname : $ticket->assignee->username,
            ] : null,
            'replies' => $replies,
        ];
    }

    /**
     * 工单 v2 详情，不内嵌回复列表，也不构造服务连接凭据。
     *
     * @return array<string, mixed>
     */
    public function v2Detail(Ticket $ticket, bool $includeUpstreamDelivery = false): array
    {
        $productColumns = Product::optionalSelectColumns([
            'id',
            'custom_display_name',
            'product_type',
            'service_type_code',
            'product_group_id',
            'config_options',
            'purchase_requires',
        ]);

        if (! in_array('id', $productColumns, true)) {
            array_unshift($productColumns, 'id');
        }

        $ticket->load([
            'user:id,email,nickname',
            'service:id,name,domain,product_id,billing_cycle,amount,status,provision_data,expires_at',
            'service.product:'.implode(',', $productColumns),
            'assignee:id,username,nickname',
            ...($includeUpstreamDelivery ? ['upstreamBinding'] : []),
        ]);

        return [
            'id' => (int) $ticket->id,
            'user_id' => (int) $ticket->user_id,
            'department' => (string) $ticket->department,
            'department_label' => self::DEPT_LABELS[$ticket->department] ?? (string) $ticket->department,
            'subject' => (string) $ticket->subject,
            'priority' => (int) $ticket->priority,
            'priority_label' => self::PRIORITIES[(int) $ticket->priority] ?? (string) $ticket->priority,
            'status' => (int) $ticket->status,
            'status_label' => self::STATUS_LABELS[(int) $ticket->status] ?? (string) $ticket->status,
            'service_id' => $ticket->service_id ? (int) $ticket->service_id : null,
            'assignee_id' => $ticket->assignee_id ? (int) $ticket->assignee_id : null,
            'close_reason' => $ticket->close_reason,
            'close_reason_label' => self::CLOSE_REASON_LABELS[$ticket->close_reason ?? ''] ?? null,
            'created_at' => $ticket->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $ticket->updated_at?->format('Y-m-d H:i:s'),
            'user' => $ticket->user ? [
                'id' => (int) $ticket->user->id,
                'email' => $ticket->user->email,
                'nickname' => $ticket->user->nickname,
                'display_name' => $ticket->user->display_name,
            ] : null,
            'service' => $ticket->service ? $this->resolveLinkedServiceSummaryPayload($ticket->service) : null,
            'assignee' => $ticket->assignee ? [
                'id' => (int) $ticket->assignee->id,
                'username' => $ticket->assignee->username,
                'nickname' => trim((string) $ticket->assignee->nickname) !== '' ? $ticket->assignee->nickname : $ticket->assignee->username,
            ] : null,
            'replies_summary' => [
                'total' => $this->countReplies($ticket),
                'default_page_size' => 20,
            ],
            ...($includeUpstreamDelivery ? ['upstream_delivery' => $this->ticketDeliveryService->ticketStatus($ticket)] : []),
        ];
    }

    public function v2Replies(Ticket $ticket, int $perPage = 20): LengthAwarePaginator
    {
        $ticket->loadMissing('user:id,email,nickname');
        $clientName = $ticket->user?->display_name ?: '客户';
        $perPage = max(1, min($perPage, 100));

        if (self::tableExists('ticket_replies')) {
            $paginator = TicketReply::query()
                ->where('ticket_id', (int) $ticket->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->paginate($perPage);

            $items = collect($paginator->items());
            $staffMap = AdminUser::query()
                ->whereIn('id', $items->where('is_staff', 1)->pluck('user_id')->unique()->values())
                ->get(['id', 'username', 'nickname'])
                ->mapWithKeys(fn (AdminUser $admin) => [
                    (int) $admin->id => trim((string) $admin->nickname) !== '' ? $admin->nickname : $admin->username,
                ])
                ->all();

            $paginator->setCollection($items
                ->map(fn (TicketReply $reply) => $this->formatReply(
                    $reply,
                    $reply->sender_type === 'upstream_admin'
                        ? ((string) ($reply->sender_name ?: '上游客服'))
                        : ($reply->is_staff ? ($staffMap[(int) $reply->user_id] ?? '员工') : $clientName)
                ))
                ->values());

            return $paginator;
        }

        $replies = collect($this->resolveTicketReplies($ticket, $clientName));
        $page = LengthAwarePaginator::resolveCurrentPage();

        return new LengthAwarePaginator(
            $replies->slice(($page - 1) * $perPage, $perPage)->values(),
            $replies->count(),
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'query' => request()->query(),
            ]
        );
    }

    /**
     * 管理端统计
     */
    public function adminSummary(): array
    {
        return [
            'open' => Ticket::where('status', self::STATUS_OPEN)->count(),
            'client_reply' => Ticket::where('status', self::STATUS_CLIENT_REPLY)->count(),
            'closed_today' => Ticket::where('status', self::STATUS_CLOSED)
                ->whereDate('updated_at', today())->count(),
            'total' => Ticket::count(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function adminAssignableUsers(): array
    {
        $privacy = AdminPrivacy::current();
        $columns = ['id', 'username', 'nickname', 'role_id'];
        $hasEmailColumn = Schema::hasColumn('admin_users', 'email');

        if ($hasEmailColumn) {
            $columns[] = 'email';
        }

        $assignableUsers = [];

        AdminUser::query()
            ->select($columns)
            ->withResolvedPermissionRelations()
            ->where('status', 1)
            ->chunkByIdDesc(self::ADMIN_ASSIGNABLE_USERS_LIMIT, function ($admins) use (&$assignableUsers, $privacy, $hasEmailColumn): bool {
                foreach ($admins as $admin) {
                    if (! $admin->hasPermission(AdminPermissions::TICKET_REPLY)) {
                        continue;
                    }

                    $assignableUsers[] = [
                        'id' => (int) $admin->id,
                        'username' => (string) $admin->username,
                        'nickname' => (string) $admin->display_name,
                        'email' => $hasEmailColumn ? $privacy->email((string) ($admin->email ?? '')) : '',
                    ];

                    if (count($assignableUsers) >= self::ADMIN_ASSIGNABLE_USERS_LIMIT) {
                        return false;
                    }
                }

                return true;
            }, 'id');

        return $assignableUsers;
    }

    public function clientServiceOptions(int $userId, string $keyword = '', int $limit = 20): array
    {
        $query = Service::query()
            ->select(['id', 'user_id', 'product_id', 'name', 'domain', 'status', 'provision_data'])
            ->with(['product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires'])
            ->where('user_id', $userId);

        $keyword = trim($keyword);
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword) {
                $builder->where('name', 'like', '%'.$keyword.'%')
                    ->orWhere('domain', 'like', '%'.$keyword.'%')
                    ->orWhere('id', $keyword);

                if (self::tableExists('service_connection_snapshots')) {
                    $likeKeyword = '%'.$keyword.'%';
                    $builder->orWhereExists(function ($subQuery) use ($likeKeyword): void {
                        $subQuery
                            ->selectRaw('1')
                            ->from('service_connection_snapshots as scs')
                            ->whereColumn('scs.service_id', 'services.id')
                            ->where(function ($connectionQuery) use ($likeKeyword): void {
                                $connectionQuery
                                    ->where('scs.hostname', 'like', $likeKeyword)
                                    ->orWhere('scs.ip_address', 'like', $likeKeyword)
                                    ->orWhere('scs.connection_json->requested_host', 'like', $likeKeyword)
                                    ->orWhere('scs.connection_json->custom_hostname', 'like', $likeKeyword)
                                    ->orWhere('scs.connection_json->username', 'like', $likeKeyword)
                                    ->orWhere('scs.connection_json->internal_ip', 'like', $likeKeyword);
                            });
                    });
                } else {
                    $builder->orWhere('provision_data->requested_host', 'like', '%'.$keyword.'%');
                }
            });
        }

        return $query->orderByDesc('id')
            ->limit(max(1, min($limit, 50)))
            ->get()
            ->map(fn (Service $service) => [
                'id' => (int) $service->id,
                'name' => $this->resolveServiceDisplayName($service),
                'service_name' => trim((string) $service->name),
                'product_name' => trim((string) ($service->product?->name ?? '')),
                'domain' => ServiceHostname::resolveDisplayDomain($service, $this->serviceProvisionData($service)),
                'status' => (int) $service->status,
                'status_label' => ServiceStatus::$labels[(int) $service->status] ?? (string) $service->status,
            ])
            ->values()
            ->all();
    }

    private function notifyAdminsOfNewTicket(Ticket $ticket, string $content, bool $hasAttachments): void
    {
        $ticket->loadMissing('user:id,email,nickname');

        $recipients = $this->resolveTicketAdminRecipients($ticket);
        if ($recipients->isEmpty()) {
            return;
        }

        $department = self::DEPT_LABELS[$ticket->department] ?? (string) $ticket->department;
        $priority = self::PRIORITIES[(int) $ticket->priority] ?? (string) $ticket->priority;
        $status = self::STATUS_LABELS[(int) $ticket->status] ?? (string) $ticket->status;
        $clientName = $ticket->user?->display_name ?: ($ticket->user?->email ?: '客户');
        $preview = $this->buildReplyPreview($content, $hasAttachments);

        foreach ($recipients as $admin) {
            $this->sendTicketEmail(
                (string) $admin->email,
                NotificationService::TEMPLATE_TICKET_CREATED,
                [
                    'site_name' => $this->siteName(),
                    'recipient_name' => (string) $admin->display_name,
                    'ticket_id' => (int) $ticket->id,
                    'ticket_subject' => (string) $ticket->subject,
                    'department' => $department,
                    'priority' => $priority,
                    'status' => $status,
                    'client_name' => $clientName,
                    'client_email' => (string) ($ticket->user?->email ?: '未绑定'),
                    'message_preview' => $preview,
                ],
                [
                    'scene' => 'ticket_created',
                    'ticket_id' => (int) $ticket->id,
                    'recipient_admin_id' => (int) $admin->id,
                ]
            );
        }
    }

    private function notifyAdminsOfClientReply(Ticket $ticket, string $content, bool $hasAttachments): void
    {
        $ticket->loadMissing('user:id,email,nickname');

        $recipients = $this->resolveTicketAdminRecipients($ticket);
        if ($recipients->isEmpty()) {
            return;
        }

        $department = self::DEPT_LABELS[$ticket->department] ?? (string) $ticket->department;
        $priority = self::PRIORITIES[(int) $ticket->priority] ?? (string) $ticket->priority;
        $status = self::STATUS_LABELS[(int) $ticket->status] ?? (string) $ticket->status;
        $clientName = $ticket->user?->display_name ?: ($ticket->user?->email ?: '客户');
        $preview = $this->buildReplyPreview($content, $hasAttachments);

        foreach ($recipients as $admin) {
            $this->sendTicketEmail(
                (string) $admin->email,
                NotificationService::TEMPLATE_TICKET_CLIENT_REPLY,
                [
                    'site_name' => $this->siteName(),
                    'recipient_name' => (string) $admin->display_name,
                    'ticket_id' => (int) $ticket->id,
                    'ticket_subject' => (string) $ticket->subject,
                    'department' => $department,
                    'priority' => $priority,
                    'status' => $status,
                    'client_name' => $clientName,
                    'client_email' => (string) ($ticket->user?->email ?: '未绑定'),
                    'message_preview' => $preview,
                ],
                [
                    'scene' => 'ticket_client_reply',
                    'ticket_id' => (int) $ticket->id,
                    'recipient_admin_id' => (int) $admin->id,
                ]
            );
        }
    }

    private function notifyClientOfStaffReply(Ticket $ticket, string $staffName, string $content, bool $hasAttachments): void
    {
        $ticket->loadMissing('user:id,email,nickname');

        $email = trim((string) ($ticket->user?->email ?? ''));
        if ($email === '') {
            return;
        }

        $status = self::STATUS_LABELS[(int) $ticket->status] ?? (string) $ticket->status;
        $clientName = $ticket->user?->display_name ?: '客户';
        $preview = $this->buildReplyPreview($content, $hasAttachments);
        $ticketsUrl = $this->clientTicketsUrl();
        $this->sendTicketEmail($email, NotificationService::TEMPLATE_TICKET_STAFF_REPLY, [
            'site_name' => $this->siteName(),
            'display_name' => $clientName,
            'ticket_id' => (int) $ticket->id,
            'ticket_subject' => (string) $ticket->subject,
            'status' => $status,
            'staff_name' => $staffName,
            'message_preview' => $preview,
            'tickets_url' => $ticketsUrl,
            'login_tip' => $ticketsUrl === '' ? '请登录会员中心的工单页面查看详情。' : '',
        ], [
            'scene' => 'ticket_staff_reply',
            'ticket_id' => (int) $ticket->id,
            'recipient_user_id' => (int) ($ticket->user?->id ?? 0),
        ]);

        $this->userNotificationService->create(
            (int) ($ticket->user?->id ?? 0),
            UserNotificationType::TICKET_STAFF_REPLY,
            '工单收到回复',
            "工单「{$ticket->subject}」{$staffName} 回复：{$preview}",
            '/client/tickets/'.$ticket->id,
            ['ticket_id' => (int) $ticket->id]
        );
    }

    /**
     * 工单自动关闭通知：邮件模板 100025 + 站内信，告知客户工单因长期未跟进已自动关闭。
     */
    private function notifyClientOfAutoClose(Ticket $ticket): void
    {
        $ticket->loadMissing('user:id,email,nickname');

        $userId = (int) ($ticket->user_id ?? 0);
        $email = trim((string) ($ticket->user?->email ?? ''));

        if ($userId <= 0 && $email === '') {
            return;
        }

        $status = self::STATUS_LABELS[self::STATUS_CLOSED] ?? '已关闭';
        $clientName = $ticket->user?->display_name ?: '客户';
        $ticketsUrl = $this->clientTicketsUrl();

        if ($email !== '') {
            $this->sendTicketEmail($email, NotificationService::TEMPLATE_TICKET_AUTO_CLOSED, [
                'site_name' => $this->siteName(),
                'display_name' => $clientName,
                'ticket_id' => (int) $ticket->id,
                'ticket_subject' => (string) $ticket->subject,
                'status' => $status,
                'tickets_url' => $ticketsUrl,
                'login_tip' => $ticketsUrl === '' ? '请登录会员中心的工单页面查看详情。' : '',
            ], [
                'scene' => 'ticket_auto_closed',
                'ticket_id' => (int) $ticket->id,
                'recipient_user_id' => $userId,
            ]);
        }

        if ($userId > 0) {
            $this->userNotificationService->create(
                $userId,
                UserNotificationType::TICKET_AUTO_CLOSED,
                '工单已自动关闭',
                "工单「{$ticket->subject}」因长期未跟进已自动关闭，如需继续处理请重新开启或提交新工单。",
                '/client/tickets/'.$ticket->id,
                ['ticket_id' => (int) $ticket->id]
            );
        }
    }

    private function resolveTicketAdminRecipients(Ticket $ticket)
    {
        $admins = AdminUser::query()
            ->with('role')
            ->where('status', 1)
            ->whereNotNull('email')
            ->orderBy('id')
            ->get(['id', 'username', 'nickname', 'email', 'role_id'])
            ->filter(function (AdminUser $admin) {
                $email = trim((string) ($admin->email ?? ''));

                return $email !== '' && $admin->hasPermission(AdminPermissions::TICKET_REPLY);
            })
            ->unique(fn (AdminUser $admin) => mb_strtolower(trim((string) $admin->email)))
            ->values();

        if ($admins->isEmpty()) {
            return $admins;
        }

        $assigneeId = (int) ($ticket->assignee_id ?? 0);
        if ($assigneeId > 0) {
            $assignee = $admins->firstWhere('id', $assigneeId);
            if ($assignee) {
                return collect([$assignee]);
            }
        }

        return $admins;
    }

    private function buildReplyPreview(string $content, bool $hasAttachments): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $content));

        if ($text === '') {
            return $hasAttachments ? '本次消息包含图片附件，请登录系统查看。' : '请登录系统查看详情。';
        }

        $preview = Str::limit($text, 120, '...');

        return $hasAttachments ? $preview.'（含图片附件）' : $preview;
    }

    private function sendTicketEmail(string $to, string $templateCode, array $params, array $context = []): void
    {
        $email = trim($to);
        if ($email === '') {
            return;
        }

        if ($this->shouldUseQueue()) {
            SendTicketNotificationEmailJob::dispatch($email, $templateCode, $params, $context);

            return;
        }

        SendTicketNotificationEmailJob::dispatchAfterResponse($email, $templateCode, $params, $context);
    }

    private function shouldUseQueue(): bool
    {
        $driver = (string) config('queue.default', 'sync');

        if ($driver === '' || $driver === 'sync') {
            return false;
        }

        if ($driver === 'database') {
            return $this->databaseQueueIsReady();
        }

        if ($driver === 'redis') {
            return extension_loaded('redis') || class_exists(Client::class);
        }

        return true;
    }

    private function databaseQueueIsReady(): bool
    {
        if ($this->databaseQueueReady !== null) {
            return $this->databaseQueueReady;
        }

        $table = (string) config('queue.connections.database.table', 'jobs');

        try {
            $this->databaseQueueReady = $table !== '' && Schema::hasTable($table);
        } catch (\Throwable $exception) {
            Log::warning('[工单通知] 检查队列表失败，回退为 afterResponse/同步执行', [
                'table' => $table,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            $this->databaseQueueReady = false;
        }

        return $this->databaseQueueReady;
    }

    private function siteName(): string
    {
        return (string) config('idc.site_name', config('app.name', '图拉云'));
    }

    private function clientTicketsUrl(): string
    {
        return PublicUrl::console('/client/tickets');
    }

    public function uploadImage(int $actorId, string $actorType, UploadedFile $file): array
    {
        // 落盘到 storage/app/private/tickets/，不在 Web 根下，只能通过 SecureAsset 签名短链读取。
        // 历史文件仍在 public/uploads/tickets/ 下，读取端（normalizeAttachmentPath / SecureAsset）保留兼容。
        $directoryPath = 'private/tickets/temp';
        $directory = storage_path('app/'.str_replace('/', DIRECTORY_SEPARATOR, $directoryPath));
        File::ensureDirectoryExists($directory);

        $extension = UploadedImage::extension($file);
        $mimeType = UploadedImage::mimeType($file);
        $filename = sprintf(
            'ticket-%s-%d-%s.%s',
            $actorType,
            $actorId,
            now()->format('His').'-'.Str::lower(Str::random(12)),
            $extension
        );

        $file->move($directory, $filename);

        $attachment = $this->buildStoredAttachmentMeta(
            $directoryPath.'/'.$filename,
            UploadedImage::originalName($file, $filename),
            $mimeType
        );

        $reference = $this->uploadedAssetReferenceService->issue(
            UploadedAssetReferenceService::CATEGORY_TICKET_ATTACHMENT,
            (string) $attachment['path'],
            $actorType,
            $actorId
        );

        return $this->serializeAttachmentForClient($attachment, $reference['token']);
    }

    private function ensureReplyPayload(string $content, array $attachments): void
    {
        throw_if($content === '' && $attachments === [], new BusinessException('内容或图片至少填写一项'));
    }

    private function closeTicketAndReplaceAttachments(Ticket $ticket, string $reason = 'admin'): bool
    {
        $closed = false;

        DB::transaction(function () use ($ticket, $reason, &$closed) {
            // 行锁内重读：关闭与回复并发时先到先得，已被关闭则直接结束，避免重复清理。
            $lockedTicket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            if ((int) $lockedTicket->status === self::STATUS_CLOSED) {
                return;
            }

            $lockedTicket->update(['status' => self::STATUS_CLOSED, 'close_reason' => $reason]);
            $closed = true;

            // 附件软删保留：仅标记 deleted，不物理删除文件，重开工单后可恢复查看。
            $replies = TicketReply::where('ticket_id', $lockedTicket->id)
                ->whereNotNull('attachments')
                ->get(['id', 'attachments']);

            foreach ($replies as $reply) {
                $attachments = collect($reply->attachments ?? [])
                    ->map(function ($attachment) {
                        if (! is_array($attachment)) {
                            return $attachment;
                        }

                        $path = trim((string) ($attachment['path'] ?? ''));
                        if ($path !== '' && empty($attachment['deleted'])) {
                            $attachment['deleted'] = true;
                        }

                        return $attachment;
                    })
                    ->all();

                $reply->update(['attachments' => $attachments]);
            }
        });

        return $closed;
    }

    private function normalizeReplyContent(?string $content): string
    {
        return TextSanitizer::clean($content, true);
    }

    private function normalizeAttachments(array $attachments, string $ownerType, int $ownerId): array
    {
        $items = collect($attachments)
            ->map(function ($item) {
                if (is_array($item)) {
                    return [
                        'token' => $item['path'] ?? null,
                        'name' => $item['name'] ?? null,
                    ];
                }

                return [
                    'token' => $item,
                    'name' => null,
                ];
            })
            ->filter(fn ($item) => is_array($item) && is_string($item['token'] ?? null) && trim((string) $item['token']) !== '')
            ->map(function (array $item) use ($ownerType, $ownerId) {
                return $this->buildStoredAttachmentMeta(
                    $this->uploadedAssetReferenceService->resolve(
                        trim((string) $item['token']),
                        UploadedAssetReferenceService::CATEGORY_TICKET_ATTACHMENT,
                        $ownerType,
                        $ownerId
                    ),
                    $item['name'] ?? null,
                    null
                );
            })
            ->unique('path')
            ->values()
            ->all();

        if ($items === []) {
            return [];
        }

        throw_if(count($items) > 9, new BusinessException('单条消息最多上传 9 张图片'));

        return $items;
    }

    private function normalizeAttachmentPath(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            throw new BusinessException('图片不存在或已失效');
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            $path = (string) (parse_url($path, PHP_URL_PATH) ?: '');
        }

        $normalizedPath = str_replace('\\', '/', $path);
        $publicRoot = str_replace('\\', '/', public_path());
        $storageRoot = str_replace('\\', '/', storage_path('app'));

        if (str_starts_with($normalizedPath, $publicRoot.'/')) {
            $path = substr($normalizedPath, strlen($publicRoot) + 1);
        } elseif (str_starts_with($normalizedPath, $storageRoot.'/')) {
            $path = substr($normalizedPath, strlen($storageRoot) + 1);
        }

        $path = ltrim($path, '/');

        if (! str_starts_with($path, 'private/tickets/') && ! str_starts_with($path, 'uploads/tickets/')) {
            throw new BusinessException('工单图片路径不合法');
        }

        return $path;
    }

    private function buildStoredAttachmentMeta(string $path, ?string $name = null, ?string $mimeType = null): array
    {
        $path = $this->normalizeAttachmentPath($path);
        $absolutePath = SecureAsset::absolutePath($path);

        throw_if(! File::exists($absolutePath), new BusinessException('图片不存在或已失效'));

        $resolvedMimeType = (string) ($mimeType ?: File::mimeType($absolutePath) ?: '');
        throw_if(! str_starts_with($resolvedMimeType, 'image/'), new BusinessException('仅支持图片附件'));

        return [
            'name' => TextSanitizer::clean($name) !== '' ? TextSanitizer::clean($name) : basename($path),
            'path' => $path,
            'size' => (int) File::size($absolutePath),
            'mime_type' => $resolvedMimeType,
            'type' => 'image',
        ];
    }

    private function buildSecureAssetUrl(string $path): string
    {
        return SecureAsset::temporaryUrl($path, 10);
    }

    private function resolveServiceDisplayName(Service $service): string
    {
        $serviceName = trim((string) $service->name);
        $productName = trim((string) ($service->product?->name ?? ''));

        if ($serviceName !== '' && $productName !== '' && $serviceName !== $productName) {
            return $productName.' / '.$serviceName;
        }

        if ($serviceName !== '') {
            return $serviceName;
        }

        if ($productName !== '') {
            return $productName;
        }

        return '服务 #'.$service->id;
    }

    private function resolveLinkedServicePayload(Service $service): array
    {
        $service->loadMissing('product');
        $provisionData = $this->serviceProvisionData($service, includeSecrets: true);
        $cachedConnection = $this->serviceTransformService->readCachedConnection($provisionData);
        $connection = [
            'dedicated_ip' => trim((string) ($provisionData['dedicated_ip'] ?? ($cachedConnection['hostname'] ?? ''))),
            'internal_ip' => trim((string) ($cachedConnection['internal_ip'] ?? '')),
            'username' => trim((string) ($cachedConnection['username'] ?? '')),
            'password' => trim((string) ($cachedConnection['password'] ?? '')),
            'has_password' => trim((string) ($cachedConnection['password'] ?? '')) !== '',
            'port' => (int) (($cachedConnection['port'] ?? 0) ?: 0),
        ];
        $specs = $this->serviceTransformService->transformListItem($service)['specs'] ?? [];

        return [
            'domain' => (string) ($service->domain ?? ''),
            'status' => (int) $service->status,
            'billing_cycle' => (string) ($service->billing_cycle ?? ''),
            'amount' => number_format((float) $service->amount, 2, '.', ''),
            'expires_at' => $service->expires_at?->format('Y-m-d H:i:s'),
            'connection' => $connection,
            'specs' => is_array($specs) ? $specs : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveLinkedServiceSummaryPayload(Service $service): array
    {
        $service->loadMissing('product');
        $listItem = $this->serviceTransformService->transformListItem($service);
        $specs = is_array($listItem['specs'] ?? null) ? $listItem['specs'] : [];

        return [
            'id' => (int) $service->id,
            'name' => (string) $service->name,
            'display_name' => $this->resolveServiceDisplayName($service),
            'domain' => (string) ($listItem['domain'] ?? $service->domain ?? ''),
            'status' => (int) $service->status,
            'status_label' => (string) ($listItem['status_label'] ?? ''),
            'billing_cycle' => (string) ($service->billing_cycle ?? ''),
            'billing_cycle_label' => (string) ($listItem['billing_cycle_label'] ?? ''),
            'amount' => number_format((float) $service->amount, 2, '.', ''),
            'expires_at' => $service->expires_at?->format('Y-m-d H:i:s'),
            'specs' => $specs,
        ];
    }

    private function countReplies(Ticket $ticket): int
    {
        return TicketReply::query()->where('ticket_id', (int) $ticket->id)->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceProvisionData(Service $service, bool $includeSecrets = false): array
    {
        $legacy = is_array($service->provision_data ?? null) ? $service->provision_data : [];
        $projection = app(PluginBindingResolver::class)->serviceProvisionProjection($service, $includeSecrets);
        $provisionData = $projection === [] ? $legacy : array_replace($legacy, $projection);

        return $includeSecrets
            ? $provisionData
            : app(PluginBindingResolver::class)->sanitizeServiceProvisionData($provisionData);
    }

    private function formatReply(TicketReply $reply, string $senderName): array
    {
        $isRecalled = $reply->recalled_at !== null;

        $attachments = $isRecalled ? [] : collect($reply->attachments ?? [])->map(function ($item) use ($reply) {
            if (! is_array($item)) {
                return null;
            }
            if (! empty($item['deleted'])) {
                $publicId = trim((string) ($item['path'] ?? '')) !== ''
                    ? $this->uploadedAssetReferenceService->publicId((string) $item['path'])
                    : 'deleted-'.$reply->id;

                return [
                    'id' => $publicId,
                    'name' => $item['name'] ?? '图片',
                    'path' => $publicId,
                    'url' => null,
                    'deleted' => true,
                    'type' => 'image',
                ];
            }
            try {
                $attachment = $this->buildStoredAttachmentMeta($item['path'] ?? '', $item['name'] ?? null, $item['mime_type'] ?? null);

                return $this->serializeAttachmentForClient($attachment);
            } catch (\Throwable $exception) {
                Log::warning('工单回复附件序列化失败', [
                    'reply_id' => $reply->id,
                    'path' => basename((string) ($item['path'] ?? '')),
                    'message' => $exception->getMessage(),
                    'exception' => $exception::class,
                ]);

                return null;
            }
        })->filter()->values()->all();

        $quote = null;
        if (! empty($reply->quote_reply_id)) {
            $quoted = TicketReply::where('ticket_id', $reply->ticket_id)->find((int) $reply->quote_reply_id);
            if ($quoted) {
                $quoted->loadMissing('ticket.user:id,nickname,email');
                $isQuotedStaff = (int) $quoted->is_staff === 1;
                $quotedSenderName = '客户';
                if ($isQuotedStaff) {
                    $admin = AdminUser::query()->find((int) $quoted->user_id);
                    $quotedSenderName = $admin?->nickname ?: $admin?->username ?: '员工';
                } else {
                    $quotedSenderName = $quoted->ticket?->user?->display_name ?: '客户';
                }
                $quote = [
                    'id' => (int) $quoted->id,
                    'sender_name' => $quotedSenderName,
                    'content' => $quoted->recalled_at !== null ? '消息已撤回' : Str::limit(trim((string) ($quoted->content ?? '')), 100),
                    'recalled' => $quoted->recalled_at !== null,
                ];
            }
        }

        return [
            'id' => (int) $reply->id,
            'ticket_id' => (int) $reply->ticket_id,
            'user_id' => (int) $reply->user_id,
            'content' => $isRecalled ? '' : $reply->content,
            'is_staff' => (int) $reply->is_staff,
            'sender_name' => (string) ($reply->sender_name ?: $senderName),
            'attachments' => $attachments,
            'recalled' => $isRecalled,
            'recalled_at' => $reply->recalled_at?->format('Y-m-d H:i:s'),
            'quote' => $quote,
            'created_at' => $reply->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function serializeAttachmentForClient(array $attachment, ?string $referenceToken = null): array
    {
        $path = (string) ($attachment['path'] ?? '');
        $publicId = $this->uploadedAssetReferenceService->publicId($path);

        return [
            'id' => $publicId,
            'name' => $attachment['name'] ?? '图片',
            'path' => $referenceToken ?? $publicId,
            'url' => $this->buildSecureAssetUrl($path),
            'size' => (int) ($attachment['size'] ?? 0),
            'mime_type' => (string) ($attachment['mime_type'] ?? ''),
            'type' => (string) ($attachment['type'] ?? 'image'),
        ];
    }

    private function resolveTicketReplies(Ticket $ticket, string $clientName): array
    {
        $ticket->load(['replies' => fn ($q) => $q->orderBy('created_at')]);

        $staffMap = AdminUser::query()
            ->whereIn('id', $ticket->replies->where('is_staff', 1)->pluck('user_id')->unique()->values())
            ->get(['id', 'username', 'nickname'])
            ->mapWithKeys(fn (AdminUser $admin) => [
                (int) $admin->id => trim((string) $admin->nickname) !== '' ? $admin->nickname : $admin->username,
            ])
            ->all();

        return $ticket->replies
            ->map(fn (TicketReply $reply) => $this->formatReply(
                $reply,
                $reply->sender_type === 'upstream_admin'
                    ? ((string) ($reply->sender_name ?: '上游客服'))
                    : ($reply->is_staff ? ($staffMap[(int) $reply->user_id] ?? '员工') : $clientName)
            ))
            ->values()
            ->all();
    }
}
