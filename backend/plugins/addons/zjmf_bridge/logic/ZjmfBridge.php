<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\ZjmfBridge\Logic;

use App\Exceptions\BusinessException;
use App\Models\User;
use App\Services\Auth\AuthService;
use Laravel\Sanctum\PersonalAccessToken;
use TuraIDC\Plugins\Addons\ZjmfBridge\Models\AgentApplication;
use TuraIDC\Plugins\Addons\ZjmfBridge\Services\ZjmfErrorMapper;
use TuraIDC\Plugins\Addons\ZjmfBridge\Services\ZjmfFinanceService;
use TuraIDC\Plugins\Addons\ZjmfBridge\Services\ZjmfProductCatalogService;
use TuraIDC\Plugins\Addons\ZjmfBridge\Services\ZjmfReconcileService;
use TuraIDC\Plugins\Addons\ZjmfBridge\Services\ZjmfServiceService;
use TuraIDC\Plugins\Addons\ZjmfBridge\Services\ZjmfTicketService;
use TuraIDC\Plugins\Addons\ZjmfBridge\Services\ZjmfTokenService;

class ZjmfBridge
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly ZjmfTokenService $tokens,
        private readonly ZjmfErrorMapper $errors,
        private readonly ZjmfFinanceService $finance,
        private readonly ZjmfProductCatalogService $catalog,
        private readonly ZjmfReconcileService $reconcile,
        private readonly ZjmfServiceService $services,
        private readonly ZjmfTicketService $tickets,
    ) {}

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function execute(array $request): array
    {
        $action = trim((string) ($request['action'] ?? ''));
        $config = is_array($request['config'] ?? null) ? $request['config'] : [];

        if ($action === 'addon.health_check') {
            return $this->ok($this->healthPayload($config), 'ZJMF Bridge Addon 加载正常');
        }

        if ($action !== 'zjmf.dispatch') {
            return $this->fail(404, '不支持的 ZJMF Bridge Addon 动作');
        }

        if (array_key_exists('enabled', $config) && (bool) $config['enabled'] !== true) {
            return $this->fail(503, 'ZJMF Bridge Addon 未启用', [], 404);
        }

        $payload = is_array($request['payload'] ?? null) ? $request['payload'] : [];
        $context = is_array($request['context'] ?? null) ? $request['context'] : [];
        $route = $this->routeKey($payload);

        try {
            return match ($route) {
                '',
                'health',
                'ping' => $this->health(),
                'system.health' => $this->systemHealth(),
                'auth.login' => $this->login($payload, $context),
                'auth.api_login' => $this->apiLogin($payload, $context),
                'user.show' => $this->user($context),
                'invoices.index' => $this->withUser($context, fn (User $user): array => $this->finance->invoices($user, $this->query($payload))),
                'invoices.show' => $this->withUser($context, fn (User $user): array => $this->finance->invoice($user, $this->id($payload))),
                'invoices.status' => $this->withUser($context, fn (User $user): array => $this->finance->invoiceStatus($user, $this->id($payload))),
                'invoices.fund' => $this->withUser($context, fn (User $user): array => $this->finance->payInvoiceByBalance($user, $this->id($payload), $this->operationContext($context, $user)), '支付成功'),
                'transactions.funds' => $this->withUser($context, fn (User $user): array => $this->finance->fundTransactions($user, $this->query($payload))),
                'funds.index' => $this->withUser($context, fn (User $user): array => $this->finance->funds($user, $this->query($payload))),
                'funds.store' => $this->withUser($context, fn (User $user): array => $this->finance->recharge($user, $this->body($payload), $this->operationContext($context, $user)), '充值二维码已生成'),
                'payments.index' => $this->withUser($context, fn (User $user): array => $this->finance->payments($user, $this->query($payload))),
                'payments.show' => $this->withUser($context, fn (User $user): array => $this->finance->payment($user, $this->id($payload))),
                'hosts.index' => $this->withUser($context, fn (User $user): array => $this->services->hosts($user, $this->query($payload))),
                'hosts.show' => $this->withUser($context, fn (User $user): array => $this->services->host($user, $this->id($payload))),
                'tickets.index' => $this->withUser($context, fn (User $user): array => $this->tickets->tickets($user, $this->query($payload))),
                'tickets.page' => $this->withUser($context, fn (User $user): array => $this->tickets->page($user, $this->query($payload))),
                'tickets.store' => $this->withUser($context, fn (User $user): array => $this->tickets->create($user, $this->body($payload)), '工单提交成功'),
                'tickets.show' => $this->withUser($context, fn (User $user): array => $this->tickets->ticket($user, $this->id($payload))),
                'tickets.reply' => $this->withUser($context, fn (User $user): array => $this->tickets->reply($user, $this->id($payload), $this->body($payload)), '回复成功'),
                'tickets.close' => $this->withUser($context, fn (User $user): array => $this->tickets->close($user, $this->id($payload)), '工单已关闭'),
                'reconcile.payments' => $this->business(fn (): array => $this->reconcile->payments($this->query($payload))),
                'reconcile.invoices' => $this->business(fn (): array => $this->reconcile->invoices($this->query($payload))),
                'products.index' => $this->product(fn (): array => $this->catalog->products($this->query($payload))),
                'products.config' => $this->product(fn (): array => $this->catalog->productConfig($this->query($payload))),
                'products.total' => $this->product(fn (): array => $this->catalog->quote($this->body($payload))),
                'hosts.cates' => $this->product(fn (): array => $this->catalog->categories($this->query($payload))),
                'cart.all' => $this->product(fn (): array => $this->catalog->cartAll()),
                'cart.get_product_config' => $this->product(fn (): array => $this->catalog->legacyProductConfig($this->query($payload))),
                'cart.credit' => $this->withUser($context, fn (User $user): array => $this->finance->credit($user)),
                'api.product.proinfo' => $this->product(fn (): array => $this->catalog->proInfo($this->pids($payload))),
                'api.product.prodetail' => $this->product(fn (): array => $this->catalog->proDetail($this->pids($payload))),
                default => $this->fail(404, 'ZJMF Bridge Addon 未匹配到接口'),
            };
        } catch (BusinessException $exception) {
            return $this->businessError($exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function healthCheck(): array
    {
        return [
            'healthy' => true,
            'message' => 'ZJMF Bridge Addon 加载正常',
            'details' => $this->healthPayload([]),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function healthPayload(array $config): array
    {
        return [
            'key' => 'zjmf_bridge',
            'enabled' => (bool) ($config['enabled'] ?? true),
            'base_path' => (string) config('zjmf_bridge.base_path', 'zjmf/v1'),
            'mode' => (string) config('zjmf_bridge.mode', 'strict'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function health(): array
    {
        return $this->ok([
            'service' => 'zjmf_bridge',
            'enabled' => true,
            'mode' => (string) config('zjmf_bridge.mode', 'strict'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function systemHealth(): array
    {
        return $this->ok([
            'service' => 'zjmf_bridge',
            'enabled' => true,
            'mode' => (string) config('zjmf_bridge.mode', 'strict'),
            'scope' => 'system.health',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function login(array $payload, array $context): array
    {
        $body = $this->body($payload);
        $account = trim((string) ($body['account'] ?? $body['email'] ?? $body['phone'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        if ($account === '' || $password === '') {
            throw new BusinessException('账号和密码不能为空', 42200, 422);
        }

        $result = $this->auth->clientLogin(
            $account,
            $password,
            (string) ($context['ip_address'] ?? ''),
            (string) ($context['user_agent'] ?? '')
        );
        $this->deleteInternalToken((string) ($result['token'] ?? ''));

        $user = (array) ($result['user'] ?? []);
        $userId = (int) ($user['id'] ?? 0);
        $ttl = (int) config('zjmf_bridge.token_ttl', 7200);
        $jwt = $this->tokens->issue([
            'sub' => 'client:'.$userId,
            'uid' => $userId,
            'scope' => $this->clientScopes(),
        ], $ttl);

        return $this->ok([
            'jwt' => $jwt,
            'token' => $jwt,
            'token_type' => 'JWT',
            'expires_in' => $ttl,
            'user' => $this->userPayload(User::query()->with(['account', 'memberLevel'])->findOrFail($userId)),
        ], '登录成功');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function apiLogin(array $payload, array $context): array
    {
        $body = $this->body($payload);
        $apiKey = trim((string) ($body['api_key'] ?? $body['password'] ?? $body['apikey'] ?? ''));

        if ($apiKey === '') {
            return $this->apiFail('API密钥不能为空');
        }

        $application = AgentApplication::query()
            ->where('api_key', $apiKey)
            ->whereIn('status', ['approved', 'pending'])
            ->first();

        if (! $application) {
            return $this->apiFail('API密钥无效');
        }

        $user = User::query()->with(['account', 'memberLevel'])->find($application->user_id);
        if (! $user) {
            return $this->apiFail('关联用户不存在');
        }

        $userId = (int) $user->id;
        $ttl = (int) config('zjmf_bridge.token_ttl', 7200);
        $jwt = $this->tokens->issue([
            'sub' => 'client:'.$userId,
            'uid' => $userId,
            'scope' => $this->clientScopes(),
        ], $ttl);

        return [
            'success' => true,
            'message' => '登录成功',
            'data' => [
                'http_status' => 200,
                'body' => [
                    'status' => 200,
                    'msg' => '登录成功',
                    'jwt' => $jwt,
                    'data' => [
                        'jwt' => $jwt,
                        'token' => $jwt,
                        'token_type' => 'JWT',
                        'expires_in' => $ttl,
                        'user' => $this->userPayload($user),
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function apiFail(string $message): array
    {
        return [
            'success' => false,
            'message' => $message,
            'data' => [
                'http_status' => 200,
                'body' => [
                    'status' => 400,
                    'msg' => $message,
                    'data' => null,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function user(array $context): array
    {
        $user = $this->resolveUser($context);
        if (! $user instanceof User) {
            return $this->fail(401, '未登录或登录已过期');
        }

        return $this->ok([
            'client' => $this->clientPayload($user),
            'country' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function withUser(array $context, callable $callback, string $message = 'success'): array
    {
        $user = $this->resolveUser($context);
        if (! $user instanceof User) {
            return $this->fail(401, '未登录或登录已过期');
        }

        return $this->business(fn (): array => $callback($user), $message);
    }

    /**
     * @return array<string, mixed>
     */
    private function business(callable $callback, string $message = 'success'): array
    {
        try {
            return $this->ok($callback(), $message);
        } catch (BusinessException $exception) {
            return $this->businessError($exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function product(callable $callback): array
    {
        try {
            return $this->ok($callback());
        } catch (BusinessException $exception) {
            return $this->businessError($exception);
        } catch (\Throwable) {
            return $this->fail(500, 'ZJMF Bridge 处理失败');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function businessError(BusinessException $exception): array
    {
        return $this->fail(
            $this->errors->fromTuraIDCCode($exception->getErrorCode()),
            $exception->getMessage()
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function operationContext(array $context, User $user): array
    {
        return [
            'actor_type' => 'client',
            'actor_user_id' => (int) $user->id,
            'actor_name' => (string) ($user->display_name ?? $user->nickname ?? $user->email ?? ''),
            'operator' => 'zjmf_bridge',
            'ip_address' => (string) ($context['ip_address'] ?? ''),
            'trace_id' => (string) ($context['trace_id'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function resolveUser(array $context): ?User
    {
        $userId = (int) ($context['zjmf_user_id'] ?? $context['actor_id'] ?? 0);
        if ($userId <= 0) {
            return null;
        }

        $user = User::query()->with(['account', 'memberLevel'])->find($userId);

        return $user instanceof User ? $user : null;
    }

    /**
     * @return list<string>
     */
    private function clientScopes(): array
    {
        return [
            'client.read',
            'finance.read',
            'finance.write',
            'payment.read',
            'payment.write',
            'service.read',
            'service.write',
            'ticket.read',
            'ticket.write',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        $memberLevel = $user->relationLoaded('memberLevel') ? $user->memberLevel : null;

        return [
            'id' => (int) $user->id,
            'email' => (string) ($user->email ?? ''),
            'phone' => (string) ($user->phone ?? ''),
            'nickname' => (string) ($user->nickname ?? ''),
            'display_name' => (string) ($user->display_name ?? ''),
            'cash_balance' => (string) $user->balance,
            'credit_limit' => (string) $user->credit_limit,
            'referral_code' => (string) ($user->referral_code ?? ''),
            'member_level_id' => $user->member_level_id,
            'member_level' => $memberLevel ? [
                'id' => (int) $memberLevel->id,
                'name' => (string) $memberLevel->name,
                'code' => (string) $memberLevel->code,
            ] : null,
            'status' => (int) $user->status,
            'is_verified' => (int) $user->is_verified,
            'real_name' => (string) $user->real_name,
            'verification_status' => (int) $user->verification_status,
            'last_login_at' => $user->last_login_at?->format('Y-m-d H:i:s'),
            'last_login_ip' => (string) ($user->last_login_ip ?? ''),
            'created_at' => $user->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * 标准 ZJMF 财务 /v1/user 接口 client 字段格式。
     *
     * @return array<string, mixed>
     */
    private function clientPayload(User $user): array
    {
        $memberLevel = $user->relationLoaded('memberLevel') ? $user->memberLevel : null;

        return [
            'id' => (int) $user->id,
            'username' => (string) ($user->email ?? ''),
            'email' => (string) ($user->email ?? ''),
            'phonenumber' => (string) ($user->phone ?? ''),
            'nickname' => (string) ($user->nickname ?? ''),
            'display_name' => (string) ($user->display_name ?? ''),
            'credit' => (string) $user->balance,
            'credit_limit' => (string) $user->credit_limit,
            'referral_code' => (string) ($user->referral_code ?? ''),
            'member_level_id' => $user->member_level_id,
            'member_level' => $memberLevel ? [
                'id' => (int) $memberLevel->id,
                'name' => (string) $memberLevel->name,
                'code' => (string) $memberLevel->code,
            ] : null,
            'status' => (int) $user->status,
            'is_verified' => (int) $user->is_verified,
            'real_name' => (string) $user->real_name,
            'verification_status' => (int) $user->verification_status,
            'last_login_at' => $user->last_login_at?->format('Y-m-d H:i:s'),
            'last_login_ip' => (string) ($user->last_login_ip ?? ''),
            'created_at' => $user->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function deleteInternalToken(string $plainTextToken): void
    {
        $tokenId = (int) strtok($plainTextToken, '|');
        if ($tokenId <= 0) {
            return;
        }

        PersonalAccessToken::query()->whereKey($tokenId)->delete();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function routeKey(array $payload): string
    {
        $name = trim((string) ($payload['route_name'] ?? ''));
        if (str_starts_with($name, 'zjmf.v1.')) {
            return substr($name, strlen('zjmf.v1.'));
        }

        return $name;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function query(array $payload): array
    {
        return is_array($payload['query'] ?? null) ? $payload['query'] : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function body(array $payload): array
    {
        return is_array($payload['body'] ?? null) ? $payload['body'] : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function id(array $payload): int
    {
        $parameters = is_array($payload['route_parameters'] ?? null) ? $payload['route_parameters'] : [];

        return (int) ($parameters['id'] ?? 0);
    }

    /**
     * 从 query 参数中提取 pids[] 为 int 数组。
     *
     * @param  array<string, mixed>  $payload
     * @return int[]
     */
    private function pids(array $payload): array
    {
        $query = $this->query($payload);
        $raw = $query['pids'] ?? [];

        if (! is_array($raw)) {
            $raw = [$raw];
        }

        return array_values(array_filter(array_map('intval', $raw), fn (int $v): bool => $v > 0));
    }

    /**
     * @return array<string, mixed>
     */
    private function ok(mixed $data = [], string $message = 'success', int $httpStatus = 200): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => [
                'http_status' => $httpStatus,
                'body' => [
                    'status' => 200,
                    'msg' => $message,
                    'data' => $data,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fail(int $status, string $message, mixed $data = [], ?int $httpStatus = null): array
    {
        return [
            'success' => false,
            'message' => $message,
            'data' => [
                'http_status' => $httpStatus ?? $this->httpStatusFor($status),
                'body' => [
                    'status' => $status,
                    'msg' => $message,
                    'data' => $data,
                ],
            ],
        ];
    }

    private function httpStatusFor(int $status): int
    {
        return match (true) {
            $status >= 500 => 500,
            $status >= 422 => 422,
            $status >= 409 => 409,
            $status >= 404 => 404,
            $status >= 403 => 403,
            $status >= 401 => 401,
            default => 400,
        };
    }
}
