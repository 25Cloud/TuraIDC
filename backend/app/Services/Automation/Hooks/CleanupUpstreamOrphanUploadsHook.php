<?php

declare(strict_types=1);

namespace App\Services\Automation\Hooks;

use App\Services\Ticket\TicketUpstreamCallbackService;

final class CleanupUpstreamOrphanUploadsHook
{
    public function handle(string $hook, array $context): array
    {
        return app(TicketUpstreamCallbackService::class)->cleanupOrphanUploads();
    }
}
