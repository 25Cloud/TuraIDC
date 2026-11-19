<?php

declare(strict_types=1);

namespace App\Services\ClientServiceConsole;

use App\Exceptions\BusinessException;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\User;
use App\Services\System\OperationLogService;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * NAT 转发子服务
 * 负责：getNatForwardingsForUser、createNatForwardingForUser、deleteNatForwardingForUser
 *       及 NAT ACL HTML 解析方法
 */
class ServiceNatService
{
    private const NAT_ACL_CONTEXT_CACHE_TTL_SECONDS = 120;

    /**
     * 「页面取不到」标记的存活时长。
     *
     * 原值 600 秒：上游抖动一次返回空，面板就被锁十分钟，客户看到的是「时好时坏」。
     * 现在标记不短路、也不削减重试次数，只把重试间隔拉长（见下面的 DELAY 常量），
     * 一次抖动最多影响一分钟内几轮刷新的手感，绝不再放大成整段不可用。
     */
    private const CUSTOM_MODULE_CONTENT_UNAVAILABLE_TTL_SECONDS = 60;

    /**
     * 自定义模块页面为空时的重试次数（含首次），用于吃掉上游的间歇性空响应。
     *
     * 实测（服务 3867 / 主机 672）连打 20 次：9 次正常、11 次空，空响应是随机的。
     * 按 55% 失败率算，3 次全空的概率约 17%，也就是每六次刷新就有一次面板报错；
     * 6 次全空降到 2.8%，8 次降到 0.8%。取 6 是「够稳」与「不把接口拖慢」的折中。
     */
    private const CUSTOM_MODULE_FETCH_ATTEMPTS = 6;

    /** 重试间隔（毫秒）。空响应多半是被上游负载均衡打到别的节点，换个时机就能拿到。 */
    private const CUSTOM_MODULE_FETCH_RETRY_DELAY_MS = 250;

    /**
     * 已落「不可用」标记时的重试间隔（毫秒）。
     *
     * 标记期内上游多半仍在抖动，把间隔拉长一点换更高的单次命中率；
     * 但尝试次数保持不变，不能用「减少机会」来省时间，那等于把抖动放大成故障。
     */
    private const CUSTOM_MODULE_FETCH_RETRY_DELAY_MS_WHEN_UNAVAILABLE = 500;

    /**
     * 解析 NAT 上下文时的重试轮数。
     *
     * 页面取到了但既无协议选项又无规则行，说明拿到的是残缺片段：这时缓存下来的上下文
     * 会让客户在 120 秒里反复看到「未返回可用 NAT 协议选项」。多取几轮换个完整页面。
     */
    private const NAT_CONTEXT_PARSE_ATTEMPTS = 3;

    /**
     * 上游返回的「假页面」特征。
     *
     * 魔方财务对不存在的路由回的是自带 404 模板（HTTP 200 + 一整页 HTML），
     * 长度 1297 字节、非空。按「非空即成功」判会把它当成真内容解析，
     * 结果是协议选项为空、新增按钮不存在 —— 客户看到的就是「面板打开了但加不了端口」。
     */
    private const MODULE_PAGE_PLACEHOLDER_MARKERS = [
        '抱歉找不到页面',
        '<title>404</title>',
        'maintain-box',
    ];

    private const NAT_ACL_MODULE_KEYWORDS = ['nat_acl', 'natacl', 'nat转发', 'nat 转发', '端口转发', '端口映射', '端口转发规则'];

    public function __construct(
        private readonly OperationLogService $operationLogService,
        private readonly ServiceDetailService $detailService,
        private readonly ServiceTransformService $transformService,
    ) {}

    public function getNatForwardingsForUser(User $user, int $serviceId): array
    {
        $service = $this->detailService->findUserService($user, $serviceId, [
            'product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires',
            'product.productGroup.secondProductGroup.firstProductGroup',
            'product.supplier',
        ]);

        if (! $this->transformService->canManageService($service)) {
            return [
                'supported' => false,
                'message' => '当前服务暂不支持 NAT 转发',
                'error' => '',
                'module_key' => '',
                'module_name' => 'NAT 转发',
                'endpoint' => '',
                'can_create' => false,
                'protocols' => [],
                'list' => [],
                'summary' => ['total' => 0],
            ];
        }

        try {
            [, , , , $natContext] = $this->resolveNatAclContext($service);

            return [
                'supported' => true,
                'message' => $natContext['list'] !== [] ? '' : '当前暂无端口转发规则',
                'error' => '',
                'module_key' => $natContext['module_key'],
                'module_name' => $natContext['module_name'],
                // 用户端不提交该地址，置空避免暴露上游请求地址（可能包含上游域名）。
                'endpoint' => '',
                'can_create' => $natContext['can_create'],
                'protocols' => $natContext['protocols'],
                'list' => $natContext['list'],
                'summary' => ['total' => count($natContext['list'])],
            ];
        } catch (\Throwable $exception) {
            Log::warning('[服务控制台] 读取 NAT 转发失败', [
                'service_id' => $service->id,
                'message' => SensitiveDataSanitizer::sanitizeText($exception->getMessage()),
            ]);

            return [
                'supported' => true,
                'message' => '',
                'error' => '读取 NAT 转发失败，请稍后重试',
                'module_key' => 'nat_acl',
                'module_name' => 'NAT 转发',
                'endpoint' => '',
                'can_create' => false,
                'protocols' => [],
                'list' => [],
                'summary' => ['total' => 0],
            ];
        }
    }

    public function createNatForwardingForUser(User $user, int $serviceId, array $data, array $context = []): array
    {
        $service = $this->detailService->findUserService($user, $serviceId, [
            'product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires',
            'product.productGroup.secondProductGroup.firstProductGroup',
            'product.supplier',
        ]);

        [$runtime, $supplier, $hostId, $jwt, $natContext] = $this->resolveNatAclContext($service, true);
        $protocol = trim((string) ($data['protocol'] ?? ''));
        $this->assertNatProtocolAllowed((array) ($natContext['protocols'] ?? []), $protocol);

        if ($this->isNativeNatContext($natContext)) {
            // 原生通道：驱动没实现 createNatRule 时按能力缺口拒绝，不落到空 endpoint 的 HTML 提交。
            throw_if(
                ! is_callable([$runtime, 'createNatRule']),
                new BusinessException('当前上游仅支持 NAT 转发列表', 42200)
            );

            $response = $runtime->createNatRule($supplier, $hostId, [
                'name' => trim((string) ($data['name'] ?? '')),
                'ext_port' => trim((string) ($data['ext_port'] ?? '')),
                'int_port' => trim((string) ($data['int_port'] ?? '')),
                'protocol' => $protocol,
            ], $jwt);
            $this->detailService->assertSuccess($response, '创建端口转发');
        } else {
            $payload = [
                'func' => 'addNatAcl',
                'name' => trim((string) ($data['name'] ?? '')),
                'ext_port' => trim((string) ($data['ext_port'] ?? '')),
                'int_port' => trim((string) ($data['int_port'] ?? '')),
                'select-protocol' => $protocol,
            ];
            $response = is_callable([$runtime, 'submitCustomModuleAction'])
                ? $runtime->submitCustomModuleAction($supplier, $natContext['endpoint'], $payload, $jwt)
                : $runtime->post(
                    $supplier,
                    $natContext['endpoint'],
                    $payload,
                    $jwt,
                    ['content-type: application/x-www-form-urlencoded']
                );
            $this->detailService->assertSuccess($response, '创建端口转发');
        }
        $this->forgetNatAclContextCache($service);

        $message = trim((string) ($response['msg'] ?? '')) ?: '端口转发创建成功';
        $this->operationLogService->writeServiceConsoleLog($service, 'service.console.nat_forwarding.create', [
            'category' => 'nat_forwarding',
            'summary' => '创建端口转发',
            'host_id' => $hostId,
            'forwarding_name' => trim((string) ($data['name'] ?? '')),
            'external_port' => trim((string) ($data['ext_port'] ?? '')),
            'internal_port' => trim((string) ($data['int_port'] ?? '')),
            'protocol' => $protocol,
            'protocol_label' => $this->transformService->resolveSelectOptionLabel((array) ($natContext['protocols'] ?? []), $protocol),
            'message' => $message,
        ], $context);

        return [
            'message' => $message,
            'detail' => $this->getNatForwardingsForUser($user, $serviceId),
        ];
    }

    public function deleteNatForwardingForUser(User $user, int $serviceId, int $forwardingId, array $context = []): array
    {
        $service = $this->detailService->findUserService($user, $serviceId, [
            'product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires',
            'product.productGroup.secondProductGroup.firstProductGroup',
            'product.supplier',
        ]);

        [$runtime, $supplier, $hostId, $jwt, $natContext] = $this->resolveNatAclContext($service, true);

        if ($this->isNativeNatContext($natContext)) {
            throw_if(
                ! is_callable([$runtime, 'deleteNatRule']),
                new BusinessException('当前上游仅支持 NAT 转发列表', 42200)
            );

            $response = $runtime->deleteNatRule($supplier, $hostId, (int) $forwardingId, $jwt);
            $this->detailService->assertSuccess($response, '删除端口转发');
        } else {
            $payload = ['func' => 'delNatAcl', 'id' => $forwardingId];
            $response = is_callable([$runtime, 'submitCustomModuleAction'])
                ? $runtime->submitCustomModuleAction($supplier, $natContext['endpoint'], $payload, $jwt)
                : $runtime->post(
                    $supplier,
                    $natContext['endpoint'],
                    $payload,
                    $jwt,
                    ['content-type: application/x-www-form-urlencoded']
                );
            $this->detailService->assertSuccess($response, '删除端口转发');
        }
        $this->forgetNatAclContextCache($service);

        $message = trim((string) ($response['msg'] ?? '')) ?: '端口转发删除成功';
        $this->operationLogService->writeServiceConsoleLog($service, 'service.console.nat_forwarding.delete', [
            'category' => 'nat_forwarding',
            'summary' => '删除端口转发 #'.$forwardingId,
            'host_id' => $hostId,
            'forwarding_id' => $forwardingId,
            'message' => $message,
        ], $context);

        return [
            'message' => $message,
            'detail' => $this->getNatForwardingsForUser($user, $serviceId),
        ];
    }

    // ── NAT ACL context resolution ─────────────────────────────────────────

    public function resolveNatAclContext(Service $service, bool $fresh = false): array
    {
        throw_if(! $this->transformService->canManageService($service), new BusinessException('当前服务暂不支持 NAT 转发', 42200));

        [$supplier, $hostId] = $this->detailService->resolveManagedSupplierAndHost($service);
        $runtime = $this->detailService->resolveRuntimeCapabilityForSupplier($supplier);

        // 能力驱动：驱动实现了 getNatRules 就走原生 API，否则沿用 HTML 自定义模块通道。
        // 判据只看驱动有没有这个方法，不看上游此刻有没有规则——空规则集不代表不支持。
        if (is_callable([$runtime, 'getNatRules'])) {
            return $this->resolveNativeNatContext($service, $runtime, $supplier, $hostId, $fresh);
        }

        $cacheKey = $this->buildNatAclContextCacheKey($service);

        $cached = ! $fresh ? Cache::get($cacheKey) : null;
        if (! $fresh && is_array($cached)) {
            return [$runtime, $supplier, $hostId, '', $cached];
        }

        $jwt = $runtime->login($supplier);

        // 模块列表本身也会抖（/host/header 会间歇性返回只剩「安全组」甚至直接报错）。
        // 拿不到列表不该让面板整个失败：退化成空列表，后面靠探模块页面定夺。
        try {
            $modules = $this->detailService->fetchSupportedModules($supplier, $hostId, $jwt);
        } catch (\Throwable $exception) {
            $modules = [];

            Log::warning('[端口转发] 读取上游模块列表失败，改为直接探测 NAT 模块页面', [
                'service_id' => (int) $service->id,
                'host_id' => $hostId,
                'message' => SensitiveDataSanitizer::sanitizeText($exception->getMessage()),
            ]);
        }

        $module = collect($modules)->first(fn ($item) => is_array($item) && $this->isNatAclModule($item));

        // 上游 host/header 的 module_client_area 会抖动：实测同一台主机连打 10 次，
        // NAT 模块只在 2~8 次里出现，其余次只剩「安全组」。所以「模块列表里没有 NAT」
        // 不能当成「没开放 NAT」——直接按默认 key 探模块页面，取到内容就说明模块在。
        // 少了这一刀，客户看到的端口转发面板会时有时无，或者退化成一个通用 iframe。
        $moduleKey = is_array($module) ? (trim((string) ($module['function'] ?? '')) ?: 'nat_acl') : 'nat_acl';
        $moduleName = is_array($module) ? (trim((string) ($module['name'] ?? '')) ?: 'NAT 转发') : 'NAT 转发';

        // 动作地址优先取本系统同源路由（供应商根地址 + 本系统主机 ID）：
        // 面板片段里的 url 可能是中间层再上游的绝对地址，既跨域也带对方主机 ID，不能直接回发。
        $actionEndpoint = is_callable([$runtime, 'getCustomModuleActionEndpoint'])
            ? $runtime->getCustomModuleActionEndpoint($supplier, $hostId)
            : null;

        // 页面取回来还有第三种失败形态：非空、也不是 404 模板，但里面既没有协议选项
        // 也没有规则行（上游偶发返回一段残缺片段）。这种页面解析出来的 context 加不了规则，
        // 缓存下来就会让客户在一个 120 秒窗口里反复看到「未返回可用 NAT 协议选项」。
        // 真页面一定有 select-protocol 或至少一行规则，据此再兜一层重试。
        $page = [];
        try {
            for ($parseAttempt = 1; $parseAttempt <= self::NAT_CONTEXT_PARSE_ATTEMPTS; $parseAttempt++) {
                $html = $this->fetchCustomModulePage($runtime, $supplier, $hostId, $jwt, $moduleKey);
                $page = $this->parseNatAclPage($html, $actionEndpoint);

                if (($page['protocols'] ?? []) !== [] || ($page['list'] ?? []) !== []) {
                    break;
                }

                if ($parseAttempt < self::NAT_CONTEXT_PARSE_ATTEMPTS) {
                    usleep(self::CUSTOM_MODULE_FETCH_RETRY_DELAY_MS * 1000);
                }
            }
        } catch (\Throwable $exception) {
            // 列表里有 NAT 却取不到页面 = 上游这一刻真的挂了，报可重试的错误；
            // 列表里没有 NAT 且页面也探不到，才敢判定这台主机确实没开放 NAT。
            throw new BusinessException(
                is_array($module) ? '读取 NAT 转发面板失败，请稍后重试' : '当前主机未开放 NAT 转发模块',
                is_array($module) ? 50000 : 42200
            );
        }

        throw_if(trim((string) ($page['endpoint'] ?? '')) === '', new BusinessException('未解析到 NAT 转发请求地址', 50000));

        $context = [
            'module_key' => $moduleKey,
            'module_name' => $moduleName,
            'endpoint' => (string) ($page['endpoint'] ?? ''),
            'protocols' => is_array($page['protocols'] ?? null) ? $page['protocols'] : [],
            'can_create' => (bool) ($page['can_create'] ?? false),
            'list' => is_array($page['list'] ?? null) ? $page['list'] : [],
        ];

        Cache::put($cacheKey, $context, now()->addSeconds(self::NAT_ACL_CONTEXT_CACHE_TTL_SECONDS));

        return [$runtime, $supplier, $hostId, $jwt, $context];
    }

    /**
     * 原生 NAT 上下文：驱动已按控制台口径把规则归一，这里只做形状兜底。
     *
     * 缓存键与 HTML 通道分开：「原生上下文没有 endpoint、有 mode」，
     * 共用同一个键会让另一种模式的载荷被错模式命中。
     *
     * @return array{0: object, 1: Supplier, 2: int, 3: string, 4: array<string, mixed>}
     */
    private function resolveNativeNatContext(Service $service, object $runtime, Supplier $supplier, int $hostId, bool $fresh): array
    {
        $cacheKey = $this->buildNativeNatContextCacheKey($service);

        if (! $fresh && is_array($cached = Cache::get($cacheKey))) {
            return [$runtime, $supplier, $hostId, '', $cached];
        }

        $jwt = $runtime->login($supplier);
        $response = $runtime->getNatRules($supplier, $hostId, $jwt);
        $this->detailService->assertSuccess($response, '读取端口转发');
        $payload = $this->detailService->extractPayload($response);

        $context = [
            'mode' => 'native',
            'module_key' => 'nat_acl',
            'module_name' => 'NAT 转发',
            // 用户端不提交该地址；原生通道根本没有页面地址，同样置空。
            'endpoint' => '',
            'protocols' => $this->normalizeNativeNatProtocols($payload['protocols'] ?? []),
            'can_create' => (bool) ($payload['can_create'] ?? false),
            'list' => $this->normalizeNativeNatRules($payload['list'] ?? []),
        ];

        Cache::put($cacheKey, $context, now()->addSeconds(self::NAT_ACL_CONTEXT_CACHE_TTL_SECONDS));

        return [$runtime, $supplier, $hostId, $jwt, $context];
    }

    /**
     * 原生规则项按控制台字段名重排：值类型收敛（id 为 int、开关为 bool），
     * 字段集合与 HTML 解析出来的一致，前端不用区分两种来源。
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalizeNativeNatRules(mixed $rules): array
    {
        if (! is_array($rules)) {
            return [];
        }

        return collect($rules)
            ->filter(fn ($item): bool => is_array($item))
            ->map(fn (array $item): array => [
                'id' => (int) ($item['id'] ?? 0),
                'name' => trim((string) ($item['name'] ?? '')),
                'external_address' => trim((string) ($item['external_address'] ?? '')),
                'external_host' => trim((string) ($item['external_host'] ?? '')),
                'external_port' => trim((string) ($item['external_port'] ?? '')),
                'internal_port' => trim((string) ($item['internal_port'] ?? '')),
                'protocol' => trim((string) ($item['protocol'] ?? '')),
                'protocol_label' => trim((string) ($item['protocol_label'] ?? '')),
                'can_delete' => in_array($item['can_delete'] ?? false, [true, 1, '1', 'true', 'yes'], true),
                'is_default' => in_array($item['is_default'] ?? false, [true, 1, '1', 'true', 'yes'], true),
            ])
            ->values()
            ->all();
    }

    /**
     * 原生协议白名单：与 HTML 下拉同形状（value/label），
     * 于是 assertNatProtocolAllowed() 与 resolveSelectOptionLabel() 都能直接复用。
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function normalizeNativeNatProtocols(mixed $protocols): array
    {
        if (! is_array($protocols)) {
            return [];
        }

        return collect($protocols)
            ->filter(fn ($item): bool => is_array($item))
            ->map(fn (array $item): array => [
                'value' => trim((string) ($item['value'] ?? '')),
                'label' => trim((string) ($item['label'] ?? '')),
            ])
            ->filter(fn (array $item): bool => $item['value'] !== '')
            ->values()
            ->all();
    }

    public function forgetNatAclContextCache(Service $service): void
    {
        if ((int) ($service->id ?? 0) <= 0) {
            return;
        }

        Cache::forget($this->buildNatAclContextCacheKey($service));
        // 原生上下文单独一份缓存，写操作后一并失效，避免回到列表时读到旧规则。
        Cache::forget($this->buildNativeNatContextCacheKey($service));
    }

    private function isNativeNatContext(array $context): bool
    {
        return ($context['mode'] ?? '') === 'native';
    }

    private function assertNatProtocolAllowed(array $options, string $protocol): void
    {
        throw_if($protocol === '', new BusinessException('请选择 NAT 转发协议', 42200));

        if ($options === []) {
            throw new BusinessException('当前上游未返回可用 NAT 协议选项，请刷新后重试', 42200);
        }

        $allowed = collect($options)
            ->filter(fn ($item): bool => is_array($item))
            ->map(fn (array $item): string => trim((string) ($item['value'] ?? '')))
            ->filter(fn (string $item): bool => $item !== '')
            ->values()
            ->all();

        throw_if(
            $allowed === [] || ! in_array($protocol, $allowed, true),
            new BusinessException('NAT 转发协议不在当前上游允许范围内', 42200)
        );
    }

    // ── NAT ACL HTML parsing ───────────────────────────────────────────────

    private function parseNatAclPage(string $html, ?string $actionEndpoint = null): array
    {
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $endpoint = trim((string) $actionEndpoint);
        if ($endpoint === '') {
            $endpoint = $this->extractSecurityGroupEndpoint($html);
        }

        return [
            'endpoint' => $endpoint,
            'can_create' => $this->canCreateNatAcl($html),
            'protocols' => $this->extractNatAclProtocolOptions($html),
            'list' => $this->extractNatAclRows($html),
        ];
    }

    private function canCreateNatAcl(string $html): bool
    {
        $xpath = $this->createHtmlXPath($html);
        if (! $xpath) {
            return false;
        }

        $buttons = $xpath->query("//button[contains(@data-target, 'natAclAddModal')]");

        return (bool) ($buttons && $buttons->length > 0);
    }

    private function extractNatAclProtocolOptions(string $html): array
    {
        $xpath = $this->createHtmlXPath($html);
        if (! $xpath) {
            return [];
        }

        $options = $xpath->query("//select[@name='select-protocol']/option");
        if (! $options) {
            return [];
        }

        $list = [];
        foreach ($options as $option) {
            $value = trim((string) ($option->attributes?->getNamedItem('value')?->nodeValue ?? ''));
            $label = $this->normalizeHtmlNodeText($option);

            if ($value === '' || $label === '') {
                continue;
            }

            $list[] = ['value' => $value, 'label' => $label];
        }

        return $list;
    }

    private function extractNatAclRows(string $html): array
    {
        $xpath = $this->createHtmlXPath($html);
        if (! $xpath) {
            return [];
        }

        $rows = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' table-responsive ')]//table[1]/tbody/tr");
        if (! $rows) {
            return [];
        }

        $list = [];
        foreach ($rows as $row) {
            $cells = [];
            $cellNodes = $xpath->query('./td', $row);
            if (! $cellNodes) {
                continue;
            }
            foreach ($cellNodes as $cell) {
                $cells[] = $cell;
            }
            if (count($cells) < 4) {
                continue;
            }

            $name = $this->normalizeHtmlNodeText($cells[0]);
            $externalAddress = $this->normalizeHtmlNodeText($cells[1]);
            $internalPort = $this->normalizeHtmlNodeText($cells[2]);
            $protocol = $this->normalizeHtmlNodeText($cells[3]);
            $deleteButton = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' deleteNAT ')]", $row);
            $deleteId = 0;

            if ($deleteButton && $deleteButton->length > 0) {
                $button = $deleteButton->item(0);
                $deleteId = (int) (($button?->attributes?->getNamedItem('data-id')?->nodeValue ?? 0) ?: 0);
            }

            [$externalHost, $externalPort] = $this->splitNatAclExternalAddress($externalAddress);

            $list[] = [
                'id' => $deleteId,
                'name' => $name !== '' ? $name : ('转发 #'.(count($list) + 1)),
                'external_address' => $externalAddress,
                'external_host' => $externalHost,
                'external_port' => $externalPort,
                'internal_port' => $internalPort,
                'protocol' => strtolower($protocol),
                'protocol_label' => $protocol,
                'can_delete' => $deleteId > 0,
                'is_default' => preg_match('/^默认$/u', $name) === 1,
            ];
        }

        return $list;
    }

    private function splitNatAclExternalAddress(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return ['', ''];
        }

        if (preg_match('/^(.*):(\d{1,5})$/', $value, $matches) === 1) {
            return [trim((string) ($matches[1] ?? '')), trim((string) ($matches[2] ?? ''))];
        }

        return [$value, ''];
    }

    private function extractSecurityGroupEndpoint(string $html): string
    {
        if (preg_match('/url\s*:\s*[\'"]([^\'"]*\/provision\/custom\/[^\'"]+)[\'"]/iu', $html, $matches) === 1) {
            return trim((string) ($matches[1] ?? ''));
        }

        return '';
    }

    private function isNatAclModule(array $module): bool
    {
        $type = trim((string) ($module['type'] ?? ''));
        $text = $this->normalizeKeywordText(
            implode(' ', array_filter([
                (string) ($module['function'] ?? ''),
                (string) ($module['name'] ?? ''),
            ]))
        );

        if ($text === '') {
            return false;
        }

        return ($type === '' || strtolower($type) === 'custom')
            && collect(self::NAT_ACL_MODULE_KEYWORDS)
                ->contains(fn (string $keyword) => str_contains($text, $this->normalizeKeywordText($keyword)));
    }

    private function normalizeKeywordText(string $value): string
    {
        return preg_replace('/\s+/u', '', mb_strtolower(trim($value), 'UTF-8')) ?? '';
    }

    /**
     * 读取实例自定义模块页面 HTML，供 NAT / 安全组等模块复用。
     *
     * 上游这个端点有两种失败形态，都必须当成失败重试：
     *   1) 返回空串 —— 实测服务 3867 连打 20 次：9 次正常、11 次空；
     *   2) 返回魔方财务自带的 404 模板页（1297 字节、HTTP 200）—— 非空，但里面没有
     *      select-protocol、没有新增按钮。按「非空即成功」判会把这张假页面当真内容解析，
     *      结果就是客户反馈的「面板能打开但加不了端口（提示未返回可用协议选项）」。
     *
     * 原实现还只试一次就写 10 分钟「内容不可用」标记，一次抖动把面板锁死十分钟。
     * 现在：多次重试 + 统一可用性判定，全部失败才落标记，且标记时长压到一分钟。
     */
    public function fetchCustomModulePage(object $runtime, Supplier $supplier, int $hostId, string $jwt, string $moduleKey): string
    {
        $html = '';
        $contentUnavailableCacheKey = $this->buildCustomModuleContentUnavailableCacheKey($supplier->id, $hostId, $moduleKey);

        $attempts = max(1, self::CUSTOM_MODULE_FETCH_ATTEMPTS);

        // 「不可用」标记只用于让重试间隔更保守，不再削减尝试次数。
        //
        // 曾经削到 2 次：结果是标记一旦落下，窗口内每次刷新的成功率都只有 70%，
        // 实测 10 轮里仍会掉 1 轮。真正稳妥的做法是保持同样的重试预算——
        // 6 次 × 45% 失败率，整轮全空概率已低于 1%，标记存在的意义只剩「下次别再等太久」。
        $retryDelayMs = Cache::get($contentUnavailableCacheKey)
            ? self::CUSTOM_MODULE_FETCH_RETRY_DELAY_MS_WHEN_UNAVAILABLE
            : self::CUSTOM_MODULE_FETCH_RETRY_DELAY_MS;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                // 走上游 API 协议端点取自定义面板 HTML（魔方财务用 API JWT 鉴权）；
                // 客户区路由 GET /provision/custom/content 只认客户区登录会话。
                $html = is_callable([$runtime, 'fetchCustomModulePage'])
                    ? (string) $runtime->fetchCustomModulePage($supplier, $hostId, $moduleKey, $jwt)
                    : $this->normalizeModulePageBody((string) $runtime->getText(
                        $supplier,
                        rtrim($this->resolveSupplierRootUrl($supplier), '/').'/zjmf_api/provision/custom/content',
                        $jwt,
                        ['id' => $hostId, 'key' => $moduleKey, 'now_jwt' => $jwt],
                    ));
            } catch (\Throwable) {
                $html = '';
            }

            if (self::isUsableModulePage($html)) {
                // 拿到内容就撤掉「不可用」标记，避免上一次抖动残留继续压低后续重试预算
                Cache::forget($contentUnavailableCacheKey);

                break;
            }

            if ($attempt < $attempts) {
                usleep($retryDelayMs * 1000);
            }
        }

        if (! self::isUsableModulePage($html)) {
            Cache::put($contentUnavailableCacheKey, true, now()->addSeconds(self::CUSTOM_MODULE_CONTENT_UNAVAILABLE_TTL_SECONDS));
        }

        if (! self::isUsableModulePage($html)) {
            // 另一套上游（魔方云 v1）的模块内容端点，魔方财务没有这个路由。
            // 它失败时同样会回 404 模板页，所以必须用同一套可用性判定，不能只看非空。
            try {
                $html = $this->normalizeModulePageBody((string) $runtime->getText(
                    $supplier,
                    "/v1/hosts/{$hostId}/module/custom",
                    $jwt,
                    ['key' => $moduleKey]
                ));
            } catch (\Throwable) {
                $html = '';
            }

            // 兜底源拿到内容同样要撤标记，否则标记会一直压低后续请求的重试预算
            if (self::isUsableModulePage($html)) {
                Cache::forget($contentUnavailableCacheKey);
            }
        }

        throw_if(! self::isUsableModulePage($html), new BusinessException('自定义模块页面为空', 50000));

        return $html;
    }

    /**
     * 判断一段上游响应是不是「真页面」。
     *
     * 抽成公开静态纯函数：它是「面板能不能用」的唯一判据，必须能脱离网络单测。
     */
    public static function isUsableModulePage(string $html): bool
    {
        if (trim($html) === '') {
            return false;
        }

        foreach (self::MODULE_PAGE_PLACEHOLDER_MARKERS as $marker) {
            if (str_contains($html, $marker)) {
                return false;
            }
        }

        return true;
    }

    private function normalizeModulePageBody(string $body): string
    {
        $body = trim($body, "\xEF\xBB\xBF");
        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            return $body;
        }

        $payload = $decoded['data'] ?? $decoded;

        if (is_string($payload) && trim($payload) !== '') {
            return $payload;
        }

        if (is_array($payload)) {
            foreach (['html', 'content', 'view', 'template'] as $key) {
                $value = $payload[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    return $value;
                }
            }
        }

        return $body;
    }

    private function resolveSupplierRootUrl(Supplier $supplier): string
    {
        return $this->detailService->resolveSupplierRootUrl($supplier);
    }

    private function createHtmlXPath(string $html): ?\DOMXPath
    {
        if (trim($html) === '') {
            return null;
        }

        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        return $loaded ? new \DOMXPath($dom) : null;
    }

    private function normalizeHtmlNodeText(\DOMNode $node): string
    {
        $text = html_entity_decode(trim((string) $node->textContent), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function buildNatAclContextCacheKey(Service $service): string
    {
        return 'nat_acl_ctx:service:'.(int) $service->id;
    }

    /**
     * 原生上下文单独一把键：与 HTML 上下文互不覆盖（两者字段不同，不能互相命中）。
     */
    private function buildNativeNatContextCacheKey(Service $service): string
    {
        return 'nat_acl_ctx_native:service:'.(int) $service->id;
    }

    private function buildCustomModuleContentUnavailableCacheKey(int $supplierId, int $hostId, string $moduleKey): string
    {
        return "custom_module_content_unavailable:{$supplierId}:{$hostId}:".sha1($moduleKey);
    }
}
