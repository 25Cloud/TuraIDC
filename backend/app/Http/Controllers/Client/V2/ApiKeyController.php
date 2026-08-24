<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\V2\ApiKey\StoreApiKeyRequest;
use App\Http\Requests\Client\V2\ApiKey\UpdateApiKeyRequest;
use App\Models\ApiKey;
use App\Models\ApiKeyUsageLog;
use App\Services\OpenApi\ApiKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiKeyController extends Controller
{
    public function __construct(private readonly ApiKeyService $keys) {}

    public function index(Request $request): JsonResponse
    {
        $items = ApiKey::query()
            ->where('user_id', (int) $request->user()->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (ApiKey $key) => $this->present($key));

        return $this->success(['list' => $items]);
    }

    public function store(StoreApiKeyRequest $request): JsonResponse
    {
        $data = $request->validated();
        [$key, $plain] = $this->keys->createForUser(
            $request->user(),
            (string) $data['name'],
            (array) ($data['scopes'] ?? []),
            isset($data['expires_at']) && $data['expires_at'] !== '' ? (string) $data['expires_at'] : null,
            (array) ($data['ip_allowlist'] ?? []),
        );

        return $this->success([
            'key' => $this->present($key),
            'secret' => $plain,
            'secret_warning' => '密钥仅显示这一次，请立即妥善保存。',
        ], 'API 密钥创建成功');
    }

    public function update(UpdateApiKeyRequest $request, int $id): JsonResponse
    {
        $key = $this->findOwnedKey($request, $id);
        $updated = $this->keys->update($key, $request->validated());

        return $this->success(['key' => $this->present($updated)], 'API 密钥已更新');
    }

    public function setStatus(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:enabled,disabled']]);
        $key = $this->findOwnedKey($request, $id);
        $key->forceFill(['status' => $data['status']])->save();

        return $this->success(['key' => $this->present($key)], $data['status'] === 'enabled' ? '密钥已启用' : '密钥已停用');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $key = $this->findOwnedKey($request, $id);
        $key->delete();

        return $this->success([], 'API 密钥已删除');
    }

    public function usageLogs(Request $request, int $id): JsonResponse
    {
        $this->findOwnedKey($request, $id);

        $logs = ApiKeyUsageLog::query()
            ->where('api_key_id', $id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (ApiKeyUsageLog $log) => [
                'method' => (string) $log->method,
                'path' => (string) $log->path,
                'status_code' => (int) $log->status_code,
                'ip' => (string) $log->ip,
                'duration_ms' => (int) $log->duration_ms,
                'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
            ]);

        return $this->success(['list' => $logs]);
    }

    private function findOwnedKey(Request $request, int $id): ApiKey
    {
        $key = ApiKey::query()
            ->where('user_id', (int) $request->user()->id)
            ->find($id);

        if (! $key) {
            abort(404);
        }

        return $key;
    }

    private function present(ApiKey $key): array
    {
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
        ];
    }
}
