<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\V2\Notification\ListNotificationFeedRequest;
use App\Http\Requests\Client\V2\Notification\ListNotificationsRequest;
use App\Http\Requests\Client\V2\Notification\ShowNotificationUnreadCountRequest;
use App\Services\Content\ContentV2QueryService;
use App\Services\Notification\InboxService;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(
        private readonly ContentV2QueryService $contentQueryService,
        private readonly InboxService $inboxService,
    ) {}

    public function index(ListNotificationsRequest $request)
    {
        return $this->success($this->contentQueryService->notifications(
            (int) $request->user()->id,
            $request->unreadOnly(),
            $request->pageNumber(),
            $request->perPage()
        ));
    }

    public function unreadCount(ShowNotificationUnreadCountRequest $request)
    {
        return $this->success([
            'count' => $this->inboxService->unreadCount((int) $request->user()->id),
        ]);
    }

    public function feed(ListNotificationFeedRequest $request)
    {
        return $this->success([
            'list' => $this->inboxService->feed((int) $request->user()->id, $request->limit()),
            'unread_count' => $this->inboxService->unreadCount((int) $request->user()->id),
        ]);
    }

    public function markAllRead(Request $request)
    {
        $this->inboxService->markAllRead((int) $request->user()->id);

        return $this->success();
    }
}
