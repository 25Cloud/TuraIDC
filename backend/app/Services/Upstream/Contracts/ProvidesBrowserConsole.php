<?php

declare(strict_types=1);

namespace App\Services\Upstream\Contracts;

/** 上游提供完整浏览器控制台，不能把页面地址解释成 noVNC WebSocket。 */
interface ProvidesBrowserConsole {}
