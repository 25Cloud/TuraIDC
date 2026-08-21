<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Gateways\AliPay\Controller;

use Illuminate\Http\Response;
use TuraIDC\Plugins\Gateways\AliPay\AliPayPlugin;

class IndexController
{
    public function __construct(
        private readonly AliPayPlugin $plugin,
    ) {}

    public function notifyHandle(array $payload): bool
    {
        return $this->plugin->verifyNotify($payload);
    }

    public function returnHandle(array $payload): bool
    {
        return $this->plugin->verifyNotify($payload);
    }

    public function buildNotifyResponse(bool $success): Response
    {
        return $this->plugin->buildNotifyResponse($success);
    }
}
