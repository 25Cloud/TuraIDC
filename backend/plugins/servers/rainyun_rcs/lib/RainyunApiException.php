<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\RainyunRcs\Lib;

use App\Exceptions\BusinessException;

final class RainyunApiException extends BusinessException
{
    public function __construct(string $message, public readonly int $upstreamCode = 0, public readonly bool $uncertain = false)
    {
        parent::__construct($message, 42200);
    }
}
