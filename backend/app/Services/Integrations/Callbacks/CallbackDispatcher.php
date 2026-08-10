<?php

declare(strict_types=1);

namespace App\Services\Integrations\Callbacks;

use App\Exceptions\IntegrationException;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Support\Facades\Log;

class CallbackDispatcher
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(string $provider, string $event, array $payload, callable $handler): mixed
    {
        $provider = trim($provider);
        $event = trim($event);

        try {
            return $handler($payload);
        } catch (IntegrationException $exception) {
            $this->logFailure($provider, $event, $payload, $exception);

            throw $exception;
        } catch (\Throwable $exception) {
            $this->logFailure($provider, $event, $payload, $exception);

            throw new IntegrationException(
                provider: $provider,
                action: $event,
                previous: $exception,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function logFailure(string $provider, string $event, array $payload, \Throwable $exception): void
    {
        Log::warning('[第三方回调] 分发失败', SensitiveDataSanitizer::sanitize([
            'provider' => $provider,
            'event' => $event,
            'payload' => $payload,
            'message' => $exception->getMessage(),
            'exception' => $exception::class,
        ]));
    }
}
