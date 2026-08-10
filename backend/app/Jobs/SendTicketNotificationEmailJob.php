<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\System\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendTicketNotificationEmailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public array $backoff = [30, 120, 300];

    /**
     * @param  array<string, string|int|float|bool|null>  $params
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $to,
        public string $templateCode,
        public array $params = [],
        public array $context = [],
    ) {
        $this->afterCommit();
    }

    public function handle(NotificationService $notificationService): void
    {
        $notificationService->sendTemplateEmail($this->to, $this->templateCode, $this->params);
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('异步发送工单通知邮件失败', array_merge($this->context, [
            'to_email' => $this->to,
            'template_code' => $this->templateCode,
            'message' => $exception->getMessage(),
        ]));
    }
}
