<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Gateways\DemoPay\Controller;

use TuraIDC\Plugins\Gateways\DemoPay\DemoPayPlugin;
use Illuminate\Http\Response;

class IndexController
{
    public function __construct(
        private readonly DemoPayPlugin $plugin,
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
