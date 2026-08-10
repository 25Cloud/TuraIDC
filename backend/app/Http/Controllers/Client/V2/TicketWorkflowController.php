<?php

namespace App\Http\Controllers\Client\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\V2\Ticket\ReplyRequest;
use App\Http\Requests\Client\V2\Ticket\ServiceOptionsRequest;
use App\Http\Requests\Client\V2\Ticket\StoreRequest;
use App\Http\Requests\Client\V2\Ticket\UploadImageRequest;
use App\Models\Ticket;
use App\Services\Ticket\TicketService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class TicketWorkflowController extends Controller
{
    use ApiResponse;

    public function __construct(private TicketService $ticketService) {}

    public function index(Request $request)
    {
        $filters = $request->only(['keyword', 'status']);
        $perPage = max(1, min((int) $request->input('page_size', 15), 50));
        $paginator = $this->ticketService->clientList($request->user()->id, $filters, $perPage);

        return $this->paginate($paginator);
    }

    public function store(StoreRequest $request)
    {
        $data = $request->validated();

        $ticket = $this->ticketService->create($request->user()->id, $data);

        return $this->success($ticket, '工单提交成功');
    }

    public function serviceOptions(ServiceOptionsRequest $request)
    {
        $data = $request->validated();

        return $this->success(
            $this->ticketService->clientServiceOptions(
                (int) $request->user()->id,
                (string) ($data['keyword'] ?? ''),
                (int) ($data['limit'] ?? 20),
            )
        );
    }

    public function uploadImage(UploadImageRequest $request)
    {
        $data = $request->validated();

        $image = $this->ticketService->uploadImage($request->user()->id, 'client', $data['file']);

        return $this->success($image, '图片上传成功');
    }

    public function show(Request $request, int $id)
    {
        $ticket = Ticket::where('user_id', $request->user()->id)->findOrFail($id);

        return $this->success($this->ticketService->detail($ticket));
    }

    public function reply(ReplyRequest $request, int $id)
    {
        $ticket = Ticket::where('user_id', $request->user()->id)->findOrFail($id);
        $data = $request->validated();
        $reply = $this->ticketService->clientReply(
            $ticket,
            $request->user()->id,
            $data['content'] ?? null,
            $data['attachments'] ?? [],
            $data['quote_reply_id'] ?? null,
        );

        return $this->success($reply, '回复成功');
    }

    public function recall(Request $request, int $id, int $replyId)
    {
        $ticket = Ticket::where('user_id', $request->user()->id)->findOrFail($id);
        $this->ticketService->recallReply($ticket, $replyId, $request->user()->id);

        return $this->success(null, '消息已撤回');
    }

    public function close(Request $request, int $id)
    {
        $ticket = Ticket::where('user_id', $request->user()->id)->findOrFail($id);
        $this->ticketService->clientClose($ticket, $request->user()->id);

        return $this->success(null, '工单已关闭');
    }
}
