<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\Ticket\AssignTicketRequest;
use App\Http\Requests\Admin\V2\Ticket\CloseTicketRequest;
use App\Http\Requests\Admin\V2\Ticket\ListTicketAdminUsersRequest;
use App\Http\Requests\Admin\V2\Ticket\ListTicketRepliesRequest;
use App\Http\Requests\Admin\V2\Ticket\ListTicketsRequest;
use App\Http\Requests\Admin\V2\Ticket\RecallTicketReplyRequest;
use App\Http\Requests\Admin\V2\Ticket\ReopenTicketRequest;
use App\Http\Requests\Admin\V2\Ticket\ReplyTicketRequest;
use App\Http\Requests\Admin\V2\Ticket\ShowTicketRequest;
use App\Http\Requests\Admin\V2\Ticket\ShowTicketSummaryRequest;
use App\Http\Requests\Admin\V2\Ticket\UploadTicketImageRequest;
use App\Http\Resources\Admin\V2\AdminActionResultResource;
use App\Http\Resources\Ticket\V2\TicketAdminUserOptionResource;
use App\Http\Resources\Ticket\V2\TicketDetailResource;
use App\Http\Resources\Ticket\V2\TicketListItemResource;
use App\Http\Resources\Ticket\V2\TicketReplyResource;
use App\Http\Resources\Ticket\V2\TicketSummaryResource;
use App\Http\Resources\Ticket\V2\TicketUploadAttachmentResource;
use App\Models\Ticket;
use App\Services\Admin\V2\AdminTicketActionV2Service;
use App\Services\Ticket\TicketService;
use Illuminate\Http\JsonResponse;

class TicketController extends Controller
{
    public function __construct(
        private readonly TicketService $tickets,
        private readonly AdminTicketActionV2Service $actions,
    ) {}

    public function index(ListTicketsRequest $request): JsonResponse
    {
        return $this->paginate(
            $this->tickets->adminList($request->filters(), $request->pageSize()),
            TicketListItemResource::class
        );
    }

    public function summary(ShowTicketSummaryRequest $request): JsonResponse
    {
        return $this->success(TicketSummaryResource::make($this->tickets->adminSummary())->resolve());
    }

    public function adminUsers(ListTicketAdminUsersRequest $request): JsonResponse
    {
        return $this->success([
            'list' => TicketAdminUserOptionResource::collection(
                $this->tickets->adminAssignableUsers()
            )->resolve(),
        ]);
    }

    public function show(ShowTicketRequest $request, Ticket $ticket): JsonResponse
    {
        return $this->success([
            'ticket' => (new TicketDetailResource($this->tickets->v2Detail($ticket)))->resolve(),
        ]);
    }

    public function replies(ListTicketRepliesRequest $request, Ticket $ticket): JsonResponse
    {
        return $this->paginate(
            $this->tickets->v2Replies($ticket, (int) ($request->validated('page_size') ?? 20)),
            TicketReplyResource::class
        );
    }

    public function reply(ReplyTicketRequest $request, Ticket $ticket): JsonResponse
    {
        $reply = $this->tickets->staffReply(
            $ticket,
            (int) $request->user()->id,
            $request->content(),
            $request->attachments(),
            $request->quoteReplyId()
        );

        return $this->success([
            'reply' => TicketReplyResource::make($reply)->resolve(),
        ], '回复成功');
    }

    public function uploadImage(UploadTicketImageRequest $request): JsonResponse
    {
        $image = $this->tickets->uploadImage(
            (int) $request->user()->id,
            'admin',
            $request->file('file')
        );

        return $this->success([
            'attachment' => TicketUploadAttachmentResource::make($image)->resolve(),
        ], '图片上传成功');
    }

    public function close(CloseTicketRequest $request, Ticket $ticket): JsonResponse
    {
        $result = $this->actions->close($ticket);

        return $this->success(AdminActionResultResource::make($result)->resolve(), (string) $result['message']);
    }

    public function reopen(ReopenTicketRequest $request, Ticket $ticket): JsonResponse
    {
        $result = $this->actions->reopen($ticket, 'admin');

        return $this->success(AdminActionResultResource::make($result)->resolve(), (string) $result['message']);
    }

    public function assign(AssignTicketRequest $request, Ticket $ticket): JsonResponse
    {
        $result = $this->actions->assign($ticket, $request->assigneeId());

        return $this->success(AdminActionResultResource::make($result)->resolve(), (string) $result['message']);
    }

    public function recall(RecallTicketReplyRequest $request, Ticket $ticket, int $replyId): JsonResponse
    {
        $result = $this->actions->recallReply($ticket, $replyId, (int) $request->user()->id);

        return $this->success(AdminActionResultResource::make($result)->resolve(), (string) $result['message']);
    }
}
