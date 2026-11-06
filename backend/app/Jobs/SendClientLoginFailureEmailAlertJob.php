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

class SendClientLoginFailureEmailAlertJob implements ShouldQueue
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
        public string $account,
        public string $attemptAt,
        public string $ip,
        public ?string $userAgent = null,
    ) {
        $this->afterCommit();
    }

    public function handle(NotificationService $notificationService): void
    {
        $email = $this->deliverableRecipient($this->email);
        if ($email === null) {
            Log::warning('跳过收件人非法的登录失败提醒邮件', SensitiveDataSanitizer::sanitize([
                'user_id' => $this->userId,
                'email' => $this->email,
                'failure_reason' => 'invalid_recipient',
            ]));

            return;
        }

        $this->guardDelivery(fn () => $notificationService->sendLoginFailureEmailAlertToAddress(
            $email,
            $this->displayName,
            $this->account,
            $this->attemptAt,
            $this->ip,
            $this->userAgent,
            $this->deliveryKey()
        ));
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('异步发送用户登录失败提醒邮件失败', SensitiveDataSanitizer::sanitize(array_merge(
            $this->deliveryFailureContext($exception),
            [
                'user_id' => $this->userId,
                'email' => $this->email,
                'account' => $this->account,
                'ip' => $this->ip,
                'message' => $exception->getMessage(),
            ]
        )));
    }
}
