<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminPermissions;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApiSmokeTest extends TestCase
{
    private AdminUser $adminUser;

    private User $clientUser;

    private string $adminToken;

    private string $clientToken;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('idc.verification.key', 'api-smoke-callback-key');

        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'smoke-super-admin-'.$suffix,
            'label' => 'Smoke Super Admin',
            'permissions' => [AdminPermissions::ALL],
        ]);

        $this->adminUser = AdminUser::query()->create([
            'username' => 'smoke-admin-'.$suffix,
            'password' => 'secret123',
            'role_id' => (int) $role->id,
            'nickname' => 'Smoke Admin',
            'email' => 'smoke-admin-'.$suffix.'@example.com',
            'status' => 1,
        ]);
        $this->adminToken = $this->adminUser->createToken('smoke-admin-token')->plainTextToken;

        $this->clientUser = User::query()->create([
            'email' => 'smoke-client-'.$suffix.'@example.com',
            'password' => 'secret123',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Smoke Client',
            'real_name' => '',
            'id_card' => '',
            'verification_status' => 0,
            'verification_message' => '',
            'verification_certify_id' => null,
            'member_level_id' => null,
            'total_sales_amount' => '0.00',
            'referrer_user_id' => null,
            'verified_at' => null,
        ]);
        $this->clientToken = $this->clientUser->createToken('smoke-client-token')->plainTextToken;
    }

    public function test_all_api_routes_do_not_return_server_errors_in_smoke_mode(): void
    {
        $failures = [];

        foreach ($this->apiRoutes() as $route) {
            $method = $this->resolveSmokeMethod($route);
            $uri = $this->resolveRouteUri($route);
            $server = ['CONTENT_TYPE' => 'application/json', 'Accept' => 'application/json'];
            $payload = $this->resolvePayload($route, $method);

            if ($this->routeRequiresAdmin($route)) {
                $server['HTTP_AUTHORIZATION'] = 'Bearer '.$this->adminToken;
            } elseif ($this->routeRequiresClient($route)) {
                $server['HTTP_AUTHORIZATION'] = 'Bearer '.$this->clientToken;
            }

            $response = match ($method) {
                'GET' => $this->call('GET', $uri, [], [], [], $server),
                'POST' => $this->call('POST', $uri, $payload, [], [], $server),
                'PUT' => $this->call('PUT', $uri, $payload, [], [], $server),
                'PATCH' => $this->call('PATCH', $uri, $payload, [], [], $server),
                'DELETE' => $this->call('DELETE', $uri, $payload, [], [], $server),
                default => $this->call($method, $uri, $payload, [], [], $server),
            };

            if ($response->getStatusCode() >= 500 && ! $this->isExpectedReadinessFailure($uri, $response->getStatusCode(), $response->getContent())) {
                $failures[] = sprintf(
                    '[%s] %s => %d %s',
                    $method,
                    $uri,
                    $response->getStatusCode(),
                    $this->summarizeResponse($response->getContent())
                );
            }
        }

        $this->assertSame([], $failures, implode(PHP_EOL, $failures));
    }

    private function isExpectedReadinessFailure(string $uri, int $statusCode, string $content): bool
    {
        if ($uri !== '/api/ready' || $statusCode !== 503) {
            return false;
        }

        $payload = json_decode($content, true);

        return is_array($payload)
            && ($payload['code'] ?? null) === 50300
            && ($payload['data']['status'] ?? null) === 'not_ready';
    }

    /**
     * @return Route[]
     */
    private function apiRoutes(): array
    {
        return collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn (Route $route): bool => Str::startsWith($route->uri(), 'api/'))
            ->values()
            ->all();
    }

    private function resolveSmokeMethod(Route $route): string
    {
        $methods = collect($route->methods())
            ->reject(fn (string $method): bool => $method === 'HEAD')
            ->values()
            ->all();

        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $preferred) {
            if (in_array($preferred, $methods, true)) {
                return $preferred;
            }
        }

        return $methods[0] ?? 'GET';
    }

    private function resolveRouteUri(Route $route): string
    {
        $uri = '/'.ltrim($route->uri(), '/');

        preg_match_all('/\{([^}]+)\}/', $uri, $matches);
        foreach ($matches[1] ?? [] as $rawParameter) {
            $parameter = trim((string) $rawParameter, '?');
            $uri = str_replace('{'.$rawParameter.'}', $this->resolveRouteParameterValue($parameter), $uri);
        }

        return $uri;
    }

    private function resolveRouteParameterValue(string $parameter): string
    {
        return match ($parameter) {
            'paymentNo' => 'SMOKE-PAYMENT-NO',
            'token' => 'smoke-token',
            'path' => 'smoke-path',
            default => '999999',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvePayload(Route $route, string $method): array
    {
        $uri = $route->uri();

        if ($uri === 'api/v2/site/products/{productId}/quote') {
            return [
                'billing_cycle' => 'monthly',
                'config' => [],
                'quantity' => 1,
            ];
        }

        if ($uri === 'api/v2/client/verification/callback') {
            return $this->signVerificationCallbackPayload([
                'certify_id' => 'smoke-certify-id',
            ]);
        }

        return $method === 'GET' ? [] : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function signVerificationCallbackPayload(array $payload): array
    {
        $payload['timestamp'] = (string) now()->timestamp;
        $payload['nonce'] = 'api-smoke-'.Str::random(12);
        $payload['sign'] = hash_hmac('sha256', $this->canonicalVerificationPayload($payload), 'api-smoke-callback-key');

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function canonicalVerificationPayload(array $payload): string
    {
        unset($payload['sign'], $payload['signature']);
        $this->ksortRecursive($payload);

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function ksortRecursive(array &$payload): void
    {
        ksort($payload);

        foreach ($payload as &$value) {
            if (is_array($value)) {
                $this->ksortRecursive($value);
            }
        }
    }

    private function routeRequiresAdmin(Route $route): bool
    {
        return in_array('App\Http\Middleware\EnsureAdminAuthenticated', $route->gatherMiddleware(), true);
    }

    private function routeRequiresClient(Route $route): bool
    {
        return in_array('App\Http\Middleware\EnsureClientAuthenticated', $route->gatherMiddleware(), true);
    }

    private function summarizeResponse(string $content): string
    {
        $summary = trim(strip_tags($content));
        $summary = preg_replace('/\s+/', ' ', $summary) ?? '';

        return Str::limit($summary, 200, '...');
    }
}
