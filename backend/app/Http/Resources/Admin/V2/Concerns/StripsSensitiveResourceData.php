<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2\Concerns;

trait StripsSensitiveResourceData
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function stripSensitiveKeys(array $payload): array
    {
        $clean = [];

        foreach ($payload as $key => $value) {
            $normalized = strtolower((string) $key);
            if ($this->isSensitiveKey($normalized)) {
                continue;
            }

            $clean[$key] = is_array($value) ? $this->stripSensitiveKeys($value) : $value;
        }

        return $clean;
    }

    protected function isSensitiveKey(string $key): bool
    {
        if ($key === 'has_password') {
            return false;
        }

        foreach (['password', 'secret', 'api_key', 'raw_response', 'third_party_response', 'token'] as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }
}
