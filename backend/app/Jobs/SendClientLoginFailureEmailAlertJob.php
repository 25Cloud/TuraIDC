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

class SendClientLoginFailureEmailAlertJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public array $backoff = [30, 120, 300];

    public function __construct(
        public int $userId,
        public string $email,
        public string $displayName,
        public string $account,
        public string $attemptAt,
        public string $ip,
        public ?string $userAgent = null,
    ) {
        $this->afterCommit();
    }

    public function handle(NotificationService $notificationService): void
    {
        $notificationService->sendLoginFailureEmailAlertToAddress(
            $this->email,
            $this->displayName,
            $this->account,
            $this->attemptAt,
            $this->ip,
            $this->userAgent
        );
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('异步发送用户登录失败提醒邮件失败', [
            'user_id' => $this->userId,
            'email' => $this->email,
            'account' => $this->account,
            'ip' => $this->ip,
            'message' => $exception->getMessage(),
        ]);
    }
}
