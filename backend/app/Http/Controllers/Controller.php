<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponse;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Validation\ValidationException;

class Controller extends BaseController
{
    use ApiResponse, AuthorizesRequests, ValidatesRequests;

    protected function booleanQuery(Request $request, string $key, bool $default = false): bool
    {
        if (! $request->has($key)) {
            return $default;
        }

        $value = $request->query($key);

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && in_array($value, [0, 1], true)) {
            return (bool) $value;
        }

        if (is_string($value)) {
            $trimmedValue = trim($value);

            if ($trimmedValue === '') {
                return $default;
            }

            $normalized = filter_var($trimmedValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($normalized !== null) {
                return $normalized;
            }
        }

        throw ValidationException::withMessages([
            $key => sprintf('%s 参数必须为布尔值', $key),
        ]);
    }
}
