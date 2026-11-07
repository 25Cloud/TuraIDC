<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\ZjmfFinance\Lib;

use App\Exceptions\BusinessException;
use App\Models\Supplier;
use Illuminate\Support\Facades\Log;

/**
 * 商品配置统一入口。
 *
 * 上游的「商品配置」在魔方财务里有两条路：
 * - api/product/prodetail  3.7.6 → 3.8.0 都有，返回 {data:{detail:{pid:{...商品行+配置项}}}}
 * - cart/get_product_config 老站可用；3.8.0 起要求下游 API 账号，有些商家直接删了这条路由
 *
 * 本类是唯一出口：按供应商档位排候选、失败自动换另一条、把走通的那条记下来，
 * 并把 prodetail 的返回体归一成 cart/get_product_config 的老结构
 * （data.products / data.product_pricings / data.config_groups / data.customfields），
 * 这样所有既有的解析代码不用改。
 */
final class ZjmfProductConfigGateway
{
    private const PRODETAIL_PATH = '/api/product/prodetail';

    private const CART_PATH = '/cart/get_product_config';

    private const MEMORY_CAPABILITY = 'product_config';

    public function __construct(
        private readonly ZjmfFinanceTransport $transport,
        private readonly ZjmfCapabilityMemory $memory = new ZjmfCapabilityMemory,
    ) {}

    /**
     * 取单个上游商品的配置，返回与 cart/get_product_config 同结构的响应。
     *
     * @param  array<string, string>  $cookieJar  购物车会话 cookie（老站需要）
     * @param  list<string>  $headers
     * @return array<string, mixed>
     */
    public function fetch(
        Supplier $supplier,
        int $productId,
        ?string $jwt = null,
        array &$cookieJar = [],
        array $headers = [],
    ): array {
        if ($productId <= 0) {
            throw new BusinessException('上游商品编号无效，无法读取商品配置');
        }

        $jwt = $jwt ?? $this->transport->login($supplier);
        $errors = [];

        foreach ($this->endpointOrder($supplier) as $endpoint) {
            try {
                if ($endpoint === ZjmfCompatibilityProfile::PRODUCT_CONFIG_PRODETAIL) {
                    $response = $this->requestDetail($supplier, [$productId], $jwt);

                    $envelope = self::legacyEnvelopeFromProdetail($productId, $response);
                    if ($envelope !== null) {
                        $this->memory->remember((int) $supplier->id, self::MEMORY_CAPABILITY, $endpoint);

                        return $envelope;
                    }

                    $errors[$endpoint] = '上游未返回该商品详情';

                    continue;
                }

                $response = $this->requestLegacy($supplier, $productId, $jwt, $cookieJar, $headers);
                if ((int) ($response['status'] ?? 0) === 200) {
                    $this->memory->remember((int) $supplier->id, self::MEMORY_CAPABILITY, $endpoint);

                    return $response;
                }

                $errors[$endpoint] = trim((string) ($response['msg'] ?? '')) ?: '上游返回异常';
            } catch (\Throwable $exception) {
                $errors[$endpoint] = $exception->getMessage();

                Log::warning('[ZJMF 财务接口] 读取上游商品配置失败', [
                    'supplier_id' => (int) $supplier->id,
                    'upstream_product_id' => $productId,
                    'endpoint' => $endpoint,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        throw new BusinessException($this->failureMessage($errors));
    }

    /**
     * 批量取商品配置，返回 productId => 归一后的响应（拿不到的键不出现）。
     *
     * prodetail 支持一次带多个 pids，所以新版上游一次请求就能拿一批；
     * 老站没有批量能力，退回逐条并发（保持原先的 Http::pool 通道）。
     *
     * @param  array<int, int>  $productIds
     * @return array<int, array<string, mixed>>
     */
    public function fetchMany(Supplier $supplier, array $productIds, ?string $jwt = null, int $chunkSize = 12): array
    {
        $ids = collect($productIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        $jwt = $jwt ?? $this->transport->login($supplier);
        $chunkSize = max(1, min($chunkSize, 20));

        foreach ($this->endpointOrder($supplier) as $endpoint) {
            $chunkSizeForEndpoint = $endpoint === ZjmfCompatibilityProfile::PRODUCT_CONFIG_PRODETAIL ? $chunkSize : 8;
            $results = [];

            foreach (array_chunk($ids, $chunkSizeForEndpoint) as $chunk) {
                $results += $endpoint === ZjmfCompatibilityProfile::PRODUCT_CONFIG_PRODETAIL
                    ? $this->fetchChunkViaProdetail($supplier, $chunk, $jwt)
                    : $this->fetchChunkViaCart($supplier, $chunk, $jwt);
            }

            if ($results !== []) {
                $this->memory->remember((int) $supplier->id, self::MEMORY_CAPABILITY, $endpoint);

                Log::debug('[ZJMF 财务接口] 批量商品配置通道', [
                    'supplier_id' => (int) $supplier->id,
                    'endpoint' => $endpoint,
                    'requested' => count($ids),
                    'resolved' => count($results),
                ]);

                return $results;
            }
        }

        return [];
    }

    /**
     * 把 api/product/prodetail 的返回体归一成 cart/get_product_config 的老结构。
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>|null
     */
    public static function legacyEnvelopeFromProdetail(int $productId, array $response): ?array
    {
        if ((int) ($response['status'] ?? 0) !== 200) {
            return null;
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : null;
        if ($data === null) {
            return null;
        }

        $detail = self::extractDetailRow($data, $productId);
        if ($detail === null) {
            return null;
        }

        return [
            'status' => 200,
            'msg' => (string) ($response['msg'] ?? '请求成功'),
            'data' => [
                'products' => $detail,
                'customfields' => is_array($detail['customfields'] ?? null) ? $detail['customfields'] : [],
                'product_pricings' => is_array($detail['product_pricings'] ?? null) ? $detail['product_pricings'] : [],
                'config_groups' => is_array($detail['config_groups'] ?? null) ? $detail['config_groups'] : [],
                'config_links' => is_array($detail['config_links'] ?? null) ? $detail['config_links'] : [],
                'advanced' => is_array($detail['advanced'] ?? null) ? $detail['advanced'] : [],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private static function extractDetailRow(array $data, int $productId): ?array
    {
        $details = $data['detail'] ?? null;
        if (is_array($details)) {
            foreach ([(string) $productId, $productId] as $key) {
                if (isset($details[$key]) && is_array($details[$key])) {
                    return $details[$key];
                }
            }
        }

        foreach (['products', 'product', 'info'] as $key) {
            $candidate = $data[$key] ?? null;
            if (is_array($candidate) && (int) ($candidate['id'] ?? 0) === $productId) {
                return $candidate;
            }

            if (is_array($candidate)) {
                foreach ($candidate as $item) {
                    if (is_array($item) && (int) ($item['id'] ?? 0) === $productId) {
                        return $item;
                    }
                }
            }
        }

        if ((int) ($data['id'] ?? 0) === $productId) {
            return $data;
        }

        return null;
    }

    /**
     * @param  list<int>  $productIds
     * @return array<int, array<string, mixed>>
     */
    private function fetchChunkViaProdetail(Supplier $supplier, array $productIds, string $jwt): array
    {
        try {
            $response = $this->requestDetail($supplier, $productIds, $jwt);
        } catch (\Throwable $exception) {
            Log::warning('[ZJMF 财务接口] 批量读取上游商品详情失败', [
                'supplier_id' => (int) $supplier->id,
                'upstream_product_ids' => $productIds,
                'message' => $exception->getMessage(),
            ]);

            return [];
        }

        $results = [];
        foreach ($productIds as $productId) {
            $envelope = self::legacyEnvelopeFromProdetail($productId, $response);
            if ($envelope !== null) {
                $results[$productId] = $envelope;
            }
        }

        return $results;
    }

    /**
     * @param  list<int>  $productIds
     * @return array<int, array<string, mixed>>
     */
    private function fetchChunkViaCart(Supplier $supplier, array $productIds, string $jwt): array
    {
        try {
            $responses = $this->transport->parallelGet(
                $supplier,
                collect($productIds)->mapWithKeys(fn (int $productId) => [
                    (string) $productId => [
                        'uri' => self::CART_PATH,
                        'query' => ['pid' => $productId],
                    ],
                ])->all(),
                $jwt,
            );
        } catch (\Throwable $exception) {
            Log::warning('[ZJMF 财务接口] 批量商品配置并发拉取失败，回退串行', [
                'supplier_id' => (int) $supplier->id,
                'message' => $exception->getMessage(),
            ]);

            $responses = [];
        }

        $results = [];
        foreach ($productIds as $productId) {
            $response = $responses[(string) $productId]['response'] ?? null;
            if (is_array($response) && (int) ($response['status'] ?? 0) === 200) {
                $results[$productId] = $response;

                continue;
            }

            try {
                $single = $this->requestLegacy($supplier, $productId, $jwt);
                if ((int) ($single['status'] ?? 0) === 200) {
                    $results[$productId] = $single;
                }
            } catch (\Throwable) {
                // 单条失败不影响整批，调用方按缺键处理
            }
        }

        return $results;
    }

    /**
     * @param  list<int>  $productIds
     * @return array<string, mixed>
     */
    private function requestDetail(Supplier $supplier, array $productIds, string $jwt): array
    {
        return $this->transport->get(
            $supplier,
            self::PRODETAIL_PATH,
            $jwt,
            ['pids' => $productIds],
        );
    }

    /**
     * @param  array<string, string>  $cookieJar  购物车会话 cookie（老站需要，响应里的 Set-Cookie 会回填进来）
     * @param  list<string>  $headers
     * @return array<string, mixed>
     */
    private function requestLegacy(
        Supplier $supplier,
        int $productId,
        string $jwt,
        array &$cookieJar = [],
        array $headers = [],
    ): array {
        $headers = array_merge(['Accept: application/json'], $headers);
        if ($cookieJar !== []) {
            $pairs = [];
            foreach ($cookieJar as $name => $value) {
                $name = trim((string) $name);
                $value = trim((string) $value);
                if ($name !== '' && $value !== '') {
                    $pairs[] = $name.'='.$value;
                }
            }

            if ($pairs !== []) {
                $headers[] = 'Cookie: '.implode('; ', $pairs);
            }
        }

        // 必须用 requestWithMeta：购物车会话靠响应里的 Set-Cookie 维持，
        // 裸 get() 拿不到响应头，后续 add_to_shop 会丢会话。
        $meta = $this->transport->requestWithMeta(
            $supplier,
            'GET',
            self::CART_PATH,
            [],
            $jwt,
            $headers,
            ['pid' => $productId]
        );

        $this->mergeResponseCookies($cookieJar, (array) ($meta['headers'] ?? []));

        return is_array($meta['response'] ?? null) ? $meta['response'] : [];
    }

    /**
     * @param  array<string, string>  $cookieJar
     * @param  array<int, mixed>  $responseHeaders
     */
    private function mergeResponseCookies(array &$cookieJar, array $responseHeaders): void
    {
        foreach ($responseHeaders as $header) {
            $line = trim((string) $header);
            if (! str_starts_with(strtolower($line), 'set-cookie:')) {
                continue;
            }

            $pair = explode(';', substr($line, 11), 2)[0];
            [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
            $name = trim($name);
            $value = trim($value);
            if ($name !== '' && $value !== '') {
                $cookieJar[$name] = $value;
            }
        }
    }

    /**
     * @return list<string>
     */
    private function endpointOrder(Supplier $supplier): array
    {
        $order = ZjmfCompatibilityProfile::fromSupplier($supplier)->productConfigOrder();
        $remembered = $this->memory->recall((int) $supplier->id, self::MEMORY_CAPABILITY);

        if ($remembered === null || ! in_array($remembered, $order, true)) {
            return $order;
        }

        return array_values(array_unique([$remembered, ...$order]));
    }

    /**
     * @param  array<string, string>  $errors
     */
    private function failureMessage(array $errors): string
    {
        $parts = [];
        foreach ($errors as $endpoint => $message) {
            $label = $endpoint === ZjmfCompatibilityProfile::PRODUCT_CONFIG_PRODETAIL
                ? 'api/product/prodetail'
                : 'cart/get_product_config';
            $parts[] = $label.'：'.($message !== '' ? $message : '未知错误');
        }

        return '读取上游商品配置失败（'.implode('；', $parts).'）。'
            .'请确认该供应商的魔方财务版本：3.7.6 及更早选「老版本」，3.8.0 及以后选「3.8.0+」，'
            .'或把「接口版本」留空交给自动探测';
    }
}
