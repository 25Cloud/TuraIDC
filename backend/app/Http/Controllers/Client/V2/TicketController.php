<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\V2\Ticket\ListTicketRepliesRequest;
use App\Http\Requests\Client\V2\Ticket\ShowTicketRequest;
use App\Http\Resources\Ticket\V2\TicketDetailResource;
use App\Http\Resources\Ticket\V2\TicketReplyResource;
use App\Models\Ticket;
use App\Services\Ticket\TicketService;
use Illuminate\Http\JsonResponse;

class TicketController extends Controller
{
    public function __construct(
        private readonly TicketService $tickets,
    ) {}

    public function show(ShowTicketRequest $request, int $ticket): JsonResponse
    {
        return $this->success([
            'ticket' => (new TicketDetailResource($this->tickets->v2Detail($this->findUserTicket($request, $ticket))))->resolve(),
        ]);
    }

    public function replies(ListTicketRepliesRequest $request, int $ticket): JsonResponse
    {
        return $this->paginate(
            $this->tickets->v2Replies($this->findUserTicket($request, $ticket), (int) ($request->validated('page_size') ?? 20)),
            TicketReplyResource::class
        );
    }

    private function findUserTicket(ShowTicketRequest|ListTicketRepliesRequest $request, int $ticket): Ticket
    {
        return Ticket::query()
            ->where('user_id', (int) $request->user()->id)
            ->findOrFail($ticket);
    }
}
