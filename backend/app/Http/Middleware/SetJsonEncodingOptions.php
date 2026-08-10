<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\ApiResponseBuilder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class SetJsonEncodingOptions
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof JsonResponse) {
            return $response;
        }

        $response->setEncodingOptions(
            $response->getEncodingOptions() | ApiResponseBuilder::JSON_ENCODING_OPTIONS
        );

        return $response;
    }
}
