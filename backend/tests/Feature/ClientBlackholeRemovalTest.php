<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ClientBlackholeRemovalTest extends TestCase
{
    public function test_client_blackhole_routes_are_not_registered(): void
    {
        $uris = collect(Route::getRoutes())
            ->map(static fn ($route): string => $route->uri())
            ->filter(static fn (string $uri): bool => str_starts_with($uri, 'api/v2/client/blackhole'))
            ->values()
            ->all();

        $this->assertSame([], $uris);
    }
}
