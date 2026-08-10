<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class IntegrationException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $action,
        string $message = '第三方服务暂时不可用，请稍后重试',
        public readonly array $context = [],
        int $code = 42200,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
