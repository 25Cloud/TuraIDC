<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\ClassifiesDeliveryRetries;
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
    use ClassifiesDeliveryRetries;
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
        // 邮箱为空/非法：跳过而不是把异常抛进重试链（登录提醒不该为坏地址重投 3 次）
        $email = $this->deliverableRecipient($this->email);
        if ($email === null) {
            Log::warning('跳过收件人非法的登录提醒邮件', SensitiveDataSanitizer::sanitize([
                'user_id' => $this->userId,
                'email' => $this->email,
                'failure_reason' => 'invalid_recipient',
            ]));

            return;
        }

        $this->guardDelivery(fn () => $notificationService->sendLoginEmailAlertToAddress(
            $email,
            $this->displayName,
            $this->loginAt,
            $this->ip,
            $this->userAgent,
            $this->deliveryKey()
        ));
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('异步发送用户登录邮件提醒失败', SensitiveDataSanitizer::sanitize(array_merge(
            $this->deliveryFailureContext($exception),
            [
                'user_id' => $this->userId,
                'email' => $this->email,
                'ip' => $this->ip,
                'message' => $exception->getMessage(),
            ]
        )));
    }
}
