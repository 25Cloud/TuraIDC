<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Gateways\YiPay\Controller;

class IndexController
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function notifyHandle(array $payload): bool
    {
        return $payload !== [];
    }
}
