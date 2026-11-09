<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\MofangCloud\Lib;

use App\Models\Supplier;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * 按代际产出魔方云客户端，并负责「接口代际分辨」。
 *
 * 分辨顺序（与魔方财务一致：显式声明优先，缺省才猜）：
 * 1. 供应商配置里显式选了「老版本」或「新版本」→ 直接用，绝不试探；
 * 2. 选了「自动」或没选 → 先按新版本 `GET /api/v1/test` 探活，失败再按老版本登录试探；
 * 3. 两者都失败 → 报错并把两边的失败原因都带出来，并提示手动指定代际。
 *
 * 探测结果按供应商缓存 1 小时，避免每次请求都多打一轮。
 */
final class CloudClientFactory
{
    private const MODE_CACHE_TTL_SECONDS = 3600;

    /**
     * @var array<int, CloudApiClient>
     */
    private array $clientCache = [];

    public function __construct(
        private readonly CloudCredentialsResolver $resolver,
    ) {}

    public function make(Supplier $supplier): CloudApiClient
    {
        $supplierId = (int) $supplier->id;
        if (isset($this->clientCache[$supplierId])) {
            return $this->clientCache[$supplierId];
        }

        $credentials = $this->resolver->resolve($supplier);
        $declared = $this->resolver->declaredMode($supplier);
        $mode = $declared ?? $this->detect($supplier, $credentials);

        $client = $this->build($credentials->withMode($mode));
        $this->clientCache[$supplierId] = $client;

        return $client;
    }

    /**
     * 「测试连接」用的诊断结果，不改缓存、不抛异常。
     *
     * @return array{mode:?string,ok:bool,message:string,probe:array<string, string>}
     */
    public function diagnose(Supplier $supplier): array
    {
        $credentials = $this->resolver->resolve($supplier);
        $declared = $this->resolver->declaredMode($supplier);
        $probe = [];

        foreach (CloudApiMode::cases() as $mode) {
            try {
                $probe[$mode->value] = $this->probe($credentials->withMode($mode)) ? 'ok' : '未通过校验';
            } catch (Throwable $exception) {
                $probe[$mode->value] = $exception->getMessage();
            }
        }

        $resolved = $declared ?? (($probe[CloudApiMode::Dcims->value] === 'ok')
            ? CloudApiMode::Dcims
            : (($probe[CloudApiMode::Legacy->value] === 'ok') ? CloudApiMode::Legacy : null));

        if ($resolved === null) {
            return [
                'mode' => null,
                'ok' => false,
                'message' => '两种接口代际都连不上，请核对地址与凭据',
                'probe' => $probe,
            ];
        }

        if ($declared !== null && $probe[$declared->value] !== 'ok') {
            return [
                'mode' => $declared->value,
                'ok' => false,
                'message' => '已指定'.$declared->label().'，但探测失败：'.$probe[$declared->value],
                'probe' => $probe,
            ];
        }

        return [
            'mode' => $resolved->value,
            'ok' => true,
            'message' => '连接正常，使用'.$resolved->label(),
            'probe' => $probe,
        ];
    }

    private function build(CloudCredentials $credentials): CloudApiClient
    {
        return match ($credentials->mode) {
            CloudApiMode::Legacy => new LegacyCloudClient($credentials),
            CloudApiMode::Dcims => new DcimsCloudClient($credentials),
        };
    }

    private function detect(Supplier $supplier, CloudCredentials $credentials): CloudApiMode
    {
        $cacheKey = $this->modeCacheKey($supplier, $credentials);
        $cached = Cache::get($cacheKey);
        if (is_string($cached)) {
            $mode = CloudApiMode::tryFrom($cached);
            if ($mode instanceof CloudApiMode) {
                return $mode;
            }
        }

        try {
            if ($this->probe($credentials->withMode(CloudApiMode::Dcims))) {
                Cache::put($cacheKey, CloudApiMode::Dcims->value, self::MODE_CACHE_TTL_SECONDS);

                return CloudApiMode::Dcims;
            }

            $newApiError = '接口返回未通过校验';
        } catch (Throwable $exception) {
            $newApiError = $exception->getMessage();
        }

        try {
            if ($this->probe($credentials->withMode(CloudApiMode::Legacy))) {
                Cache::put($cacheKey, CloudApiMode::Legacy->value, self::MODE_CACHE_TTL_SECONDS);

                return CloudApiMode::Legacy;
            }

            $oldApiError = '接口返回未通过校验';
        } catch (Throwable $exception) {
            $oldApiError = $exception->getMessage();
        }

        throw new CloudApiException(
            '无法分辨魔方云接口代际。新版本接口（/api/v1）：'.$newApiError
            .'；老版本接口（/v1）：'.$oldApiError
            .'。请在供应商配置里手动指定接口代际',
            null,
            null
        );
    }

    private function probe(CloudCredentials $credentials): bool
    {
        if ($credentials->mode === CloudApiMode::Dcims) {
            return (new DcimsCloudClient($credentials))->ping();
        }

        (new LegacyCloudClient($credentials))->token(forceRefresh: true);

        return true;
    }

    private function modeCacheKey(Supplier $supplier, CloudCredentials $credentials): string
    {
        return 'mofang_cloud:api_mode:'.$credentials->supplierId.':'.sha1($credentials->baseUrl);
    }
}
