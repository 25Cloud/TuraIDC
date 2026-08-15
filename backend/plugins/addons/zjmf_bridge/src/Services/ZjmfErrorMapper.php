<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\ZjmfBridge\Services;

class ZjmfErrorMapper
{
    public function fromTuraIDCCode(int $code): int
    {
        return match (true) {
            $code === 0 => 200,
            $code >= 50000 => 500,
            $code >= 42200 => 422,
            $code >= 40900 => 409,
            $code >= 40400 => 404,
            $code >= 40300 => 403,
            $code >= 40100 => 401,
            $code >= 40000 => 400,
            default => 500,
        };
    }
}
