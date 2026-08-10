<?php

declare(strict_types=1);

namespace App\Services\Verification\Data;

final readonly class VerificationCallbackRequest
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public array $payload,
        public array $headers,
        public string $method,
        public string $path,
        public string $rawBody,
    ) {}
}
