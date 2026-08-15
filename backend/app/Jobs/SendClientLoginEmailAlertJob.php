<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\System\NotificationService;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendClientLoginEmailAlertJob implements ShouldQueue
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
        public string $loginAt,
        public string $ip,
        public ?string $userAgent = null,
    ) {
        $this->afterCommit();
    }

    public function handle(NotificationService $notificationService): void
    {
        $notificationService->sendLoginEmailAlertToAddress(
            $this->email,
            $this->displayName,
            $this->loginAt,
            $this->ip,
            $this->userAgent
        );
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('异步发送用户登录邮件提醒失败', SensitiveDataSanitizer::sanitize([
            'user_id' => $this->userId,
            'email' => $this->email,
            'ip' => $this->ip,
            'message' => $exception->getMessage(),
        ]));
    }
}
