<?php

declare(strict_types=1);

namespace App\Services\Ticket;

use InvalidArgumentException;

final class TicketUpstreamCallbackToken
{
    public static function forServiceId(int $serviceId, ?string $applicationKey = null): string
    {
        if ($serviceId <= 0) {
            throw new InvalidArgumentException('服务 ID 必须为正整数');
        }

        $key = $applicationKey ?? (string) config('app.key', '');

        return hash_hmac('md5', 'ticket-upstream-callback:'.$serviceId, $key);
    }
}
