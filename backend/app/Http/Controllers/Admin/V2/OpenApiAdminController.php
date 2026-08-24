<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\ApiKeyUsageLog;
use App\Models\User;
use App\Services\OpenApi\OpenApiConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OpenApiAdminController extends Controller
{
    public function __construct(private readonly OpenApiConfig $config) {}

    public function config(): JsonResponse
    {
        return $this->success([
            'enabled' => $this->config->enabled() ? 1 : 0,
            'require_phone' => $this->config->requirePhone() ? 1 : 0,
            'require_verified' => $this->config->requireVerified() ? 1 : 0,
            'max_keys_per_user' => $this->config->maxKeysPerUser(),
            'rate_limit' => $this->config->rateLimitPerMinute(),
        ]);
    }

    public function saveConfig(Request $request): JsonResponse
    {
        $data = $this->validate($request, [
            'enabled' => ['sometimes', 'in:0,1,true,false'],
            'require_phone' => ['sometimes', 'in:0,1,true,false'],
            'require_verified' => ['sometimes', 'in:0,1,true,false'],
            'max_keys_per_user' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'rate_limit' => ['sometimes', 'integer', 'min:1', 'max:3600'],
        ]);

        $values = [];
        foreach (['enabled', 'require_phone', 'require_verified'] as $booleanKey) {
            if (array_key_exists($booleanKey, $data)) {
                $values[$booleanKey] = in_array($data[$booleanKey], [1, '1', true, 'true'], true) ? '1' : '0';
            }
        }
        foreach (['max_keys_per_user', 'rate_limit'] as $integerKey) {
            if (array_key_exists($integerKey, $data)) {
                $values[$integerKey] = (string) (int) $data[$integerKey];
            }
        }

        if ($values !== []) {
            \App\Models\Setting::setValues(OpenApiConfig::GROUP, $values);
        }

        return $this->success($this->config->toArray(), '开放接口配置已更新');
    }

    public function keys(Request $request): JsonResponse
    {
        $page = max((int) $request->input('page', 1), 1);
        $pageSize = min(max((int) $request->input('page_size', 20), 1), 100);

        $query = ApiKey::query()->with('user:id,email,phone,nickname,status');

        $keyword = trim((string) $request->input('keyword', ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword): void {
                $builder->where('name', 'like', "%{$keyword}%")
                    ->orWhere('key_prefix', 'like', "%{$keyword}%");
            });
        }

        $status = trim((string) $request->input('status', ''));
        if (in_array($status, ['enabled', 'disabled'], true)) {
            $query->where('status', $status);
        }

        $paginator = $query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);

        $items = collect($paginator->items())->map(fn (ApiKey $key) => $this->present($key));

        return $this->success([
            'list' => $items,
            'total' => (int) $paginator->total(),
            'page' => $page,
            'page_size' => $pageSize,
        ]);
    }

    public function setStatus(Request $request, int $id): JsonResponse
    {
        $data = $this->validate($request, ['status' => ['required', 'in:enabled,disabled']]);
        $key = $this->findKey($id);

        $key->forceFill(['status' => $data['status']])->save();

        return $this->success(['key' => $this->present($key)], $data['status'] === 'enabled' ? '密钥已启用' : '密钥已停用');
    }

    public function destroy(int $id): JsonResponse
    {
        $key = $this->findKey($id);
        $key->delete();

        return $this->success([], 'API 密钥已删除');
    }

    public function usageLogs(Request $request, int $id): JsonResponse
    {
        $this->findKey($id);

        $page = max((int) $request->input('page', 1), 1);
        $pageSize = min(max((int) $request->input('page_size', 20), 1), 100);

        $paginator = ApiKeyUsageLog::query()
            ->where('api_key_id', $id)
            ->orderByDesc('created_at')
            ->paginate($pageSize, ['*'], 'page', $page);

        $items = collect($paginator->items())->map(fn (ApiKeyUsageLog $log) => [
            'method' => (string) $log->method,
            'path' => (string) $log->path,
            'status_code' => (int) $log->status_code,
            'ip' => (string) $log->ip,
            'duration_ms' => (int) $log->duration_ms,
            'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
        ]);

        return $this->success([
            'list' => $items,
            'total' => (int) $paginator->total(),
            'page' => $page,
            'page_size' => $pageSize,
        ]);
    }

    private function findKey(int $id): ApiKey
    {
        $key = ApiKey::query()->find($id);

        if (! $key) {
            throw ValidationException::withMessages(['id' => 'API 密钥不存在']);
        }

        return $key;
    }

    private function present(ApiKey $key): array
    {
        $user = $key->user;

        return [
            'id' => (int) $key->id,
            'name' => (string) $key->name,
            'key_prefix' => (string) $key->key_prefix,
            'secret_last4' => (string) $key->secret_last4,
            'scopes' => is_array($key->scopes) ? $key->scopes : [],
            'expires_at' => $key->expires_at?->format('Y-m-d H:i:s'),
            'ip_allowlist' => is_array($key->ip_allowlist) ? $key->ip_allowlist : [],
            'status' => (string) $key->status,
            'last_used_at' => $key->last_used_at?->format('Y-m-d H:i:s'),
            'created_at' => $key->created_at?->format('Y-m-d H:i:s'),
            'user' => $user instanceof User ? [
                'id' => (int) $user->id,
                'email' => (string) ($user->email ?? ''),
                'phone' => (string) ($user->phone ?? ''),
                'nickname' => (string) ($user->nickname ?? ''),
                'status' => (int) ($user->status ?? 0),
            ] : null,
        ];
    }
}
