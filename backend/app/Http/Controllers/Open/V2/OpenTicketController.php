<?php

declare(strict_types=1);

namespace App\Http\Controllers\Open\V2;

use App\Http\Controllers\Controller;
use App\Http\Resources\Ticket\V2\TicketReplyResource;
use App\Models\Ticket;
use App\Services\Ticket\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 开放接口 - 工单。
 *
 * 与用户控制台走同一套 TicketService：归属校验、内容净化、附件校验、上游同步
 * 全部由服务层负责，这里只做「按密钥所属用户取数据」和字段投影，不重复实现业务规则。
 */
class OpenTicketController extends Controller
{
    /** 列表默认页大小与上限：对齐控制台 page_size 的 50 上限 */
    private const LIST_DEFAULT_PAGE_SIZE = 20;

    private const LIST_MAX_PAGE_SIZE = 50;

    public function __construct(private readonly TicketService $tickets) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->attributes->get('api_key_user');
        $data = $request->validate([
            'keyword' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([0, 1, 2, 3, '0', '1', '2', '3'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:'.self::LIST_MAX_PAGE_SIZE],
        ]);

        // page 由 Laravel 分页器自己从查询串读取，这里只需给出页大小。
        // 筛选键必须显式过滤：把 null 传进 filters 会把「未筛选」变成 status=null 查询。
        $filters = [];
        if (isset($data['keyword']) && trim((string) $data['keyword']) !== '') {
            $filters['keyword'] = (string) $data['keyword'];
        }
        if (isset($data['status']) && $data['status'] !== '') {
            $filters['status'] = (int) $data['status'];
        }

        $paginator = $this->tickets->clientList(
            (int) $user->id,
            $filters,
            max(1, min((int) ($data['page_size'] ?? self::LIST_DEFAULT_PAGE_SIZE), self::LIST_MAX_PAGE_SIZE)),
        );

        $items = [];
        foreach ($paginator->items() as $ticket) {
            if ($ticket instanceof Ticket) {
                $items[] = $this->present($ticket);
            }
        }

        return $this->success([
            'list' => $items,
            'total' => (int) $paginator->total(),
            'page' => (int) $paginator->currentPage(),
            'page_size' => (int) $paginator->perPage(),
        ]);
    }

    public function show(Request $request, int $ticket): JsonResponse
    {
        $user = $request->attributes->get('api_key_user');

        return $this->success([
            'ticket' => $this->tickets->v2Detail($this->tickets->clientTicket((int) $user->id, $ticket)),
        ]);
    }

    public function replies(Request $request, int $ticket): JsonResponse
    {
        $user = $request->attributes->get('api_key_user');
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $this->tickets->v2Replies(
            $this->tickets->clientTicket((int) $user->id, $ticket),
            max(1, min((int) ($data['page_size'] ?? 20), 100)),
        );

        $items = [];
        foreach ($paginator->items() as $reply) {
            $items[] = is_array($reply) ? (new TicketReplyResource($reply))->resolve() : $reply;
        }

        return $this->success([
            'list' => $items,
            'total' => (int) $paginator->total(),
            'page' => (int) $paginator->currentPage(),
            'page_size' => (int) $paginator->perPage(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->attributes->get('api_key_user');
        // 字段与约束对齐控制台 StoreRequest；department 取值由 TicketService 常量兜住，
        // 不在这里另抄一份，避免两边漂移。
        $data = $request->validate([
            'department' => ['required', Rule::in(TicketService::DEPARTMENTS)],
            'subject' => ['required', 'string', 'max:200'],
            'content' => ['nullable', 'string', 'max:10000'],
            'priority' => ['nullable', 'integer', 'between:1,4'],
            'service_id' => ['nullable', 'integer'],
            'attachments' => ['nullable', 'array', 'max:9'],
            'attachments.*' => ['required', 'string', 'max:255'],
        ]);

        $ticket = $this->tickets->create((int) $user->id, $data);

        return $this->success($this->present($ticket), '工单提交成功');
    }

    public function reply(Request $request, int $ticket): JsonResponse
    {
        $user = $request->attributes->get('api_key_user');
        $data = $request->validate([
            'content' => ['nullable', 'string', 'max:10000'],
            'attachments' => ['nullable', 'array', 'max:9'],
            'attachments.*' => ['required', 'string', 'max:255'],
            'quote_reply_id' => ['nullable', 'integer'],
        ]);

        $row = $this->tickets->clientReply(
            $this->tickets->clientTicket((int) $user->id, $ticket),
            (int) $user->id,
            $data['content'] ?? null,
            $data['attachments'] ?? [],
            isset($data['quote_reply_id']) ? (int) $data['quote_reply_id'] : null,
        );

        return $this->success([
            'ticket_id' => $ticket,
            'reply' => $row,
        ], '回复成功');
    }

    /**
     * 工单投影：只给开放接口消费的最小字段集，明细走 GET /tickets/{ticket}。
     *
     * @return array<string, mixed>
     */
    private function present(Ticket $ticket): array
    {
        return [
            'id' => (int) $ticket->id,
            'subject' => (string) $ticket->subject,
            'status' => (int) $ticket->status,
            'department' => (string) $ticket->department,
            'priority' => (int) $ticket->priority,
            'service_id' => $ticket->service_id !== null ? (int) $ticket->service_id : null,
            'created_at' => $ticket->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $ticket->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
