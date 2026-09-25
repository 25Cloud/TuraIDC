<?php

declare(strict_types=1);

namespace App\Http\Controllers\ZjmfUpstream;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ZjmfUpstream\ZjmfUpstreamTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 上游工单接口（被魔方财务对接）：/ticket/create、/ticket/reply。
 *
 * 魔方财务工单投递（ticketDeliver / ticketReplyDeliver）先经 /upload_image
 * 上传附件拿 savename，再携 attachment[] 调用本组接口。
 */
class TicketController extends Controller
{
    public function __construct(
        private readonly ZjmfUpstreamTicketService $tickets,
    ) {}

    public function create(Request $request): JsonResponse
    {
        return response()->json($this->tickets->create($this->user($request), $request->all()), 200);
    }

    public function reply(Request $request): JsonResponse
    {
        return response()->json($this->tickets->reply($this->user($request), $request->all()), 200);
    }

    private function user(Request $request): User
    {
        $user = $request->attributes->get('zjmf_upstream_user');

        return $user instanceof User ? $user : $request->user();
    }
}
