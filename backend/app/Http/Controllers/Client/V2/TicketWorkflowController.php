<?php

namespace App\Http\Controllers\Client\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\V2\Action\ClientActionRequest;
use App\Http\Requests\Client\V2\Ticket\ListTicketsRequest;
use App\Http\Requests\Client\V2\Ticket\ReplyRequest;
use App\Http\Requests\Client\V2\Ticket\ServiceOptionsRequest;
use App\Http\Requests\Client\V2\Ticket\StoreRequest;
use App\Http\Requests\Client\V2\Ticket\UploadImageRequest;
use App\Services\Ticket\TicketService;
use App\Traits\ApiResponse;

class TicketWorkflowController extends Controller
{
    use ApiResponse;

    public function __construct(private TicketService $ticketService) {}

    public function index(ListTicketsRequest $request)
    {
        $paginator = $this->ticketService->clientList(
            (int) $request->user()->id,
            $request->filters(),
            $request->perPage()
        );

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

    public function reply(ReplyRequest $request, int $id)
    {
        $ticket = $this->ticketService->clientTicket((int) $request->user()->id, $id);
        $data = $request->validated();
        $reply = $this->ticketService->clientReply(
            $ticket,
            (int) $request->user()->id,
            $data['content'] ?? null,
            $data['attachments'] ?? [],
            $data['quote_reply_id'] ?? null,
        );

        return $this->success($reply, '回复成功');
    }

    public function close(ClientActionRequest $request, int $id)
    {
        $ticket = $this->ticketService->clientTicket((int) $request->user()->id, $id);
        $this->ticketService->clientClose($ticket, (int) $request->user()->id);

        return $this->success(null, '工单已关闭');
    }

    public function reopen(ClientActionRequest $request, int $id)
    {
        $ticket = $this->ticketService->clientTicket((int) $request->user()->id, $id);
        $updated = $this->ticketService->reopen($ticket, [
            'operator_type' => 'client',
            'operator_id' => (int) $request->user()->id,
            'trace_id' => (string) $request->header('X-Request-Id', ''),
        ]);

        return $this->success([
            'id' => (int) $updated->id,
            'status' => (int) $updated->status,
        ], '工单已重新开启');
    }
}
