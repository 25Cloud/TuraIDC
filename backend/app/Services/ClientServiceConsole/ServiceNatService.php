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

    private const CUSTOM_MODULE_CONTENT_UNAVAILABLE_TTL_SECONDS = 600;

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
        $cacheKey = $this->buildNatAclContextCacheKey($service);

        $cached = ! $fresh ? Cache::get($cacheKey) : null;
        if (! $fresh && is_array($cached)) {
            return [$this->detailService->resolveRuntimeCapabilityForSupplier($supplier), $supplier, $hostId, '', $cached];
        }

        $runtime = $this->detailService->resolveRuntimeCapabilityForSupplier($supplier);
        $jwt = $runtime->login($supplier);
        $modules = $this->detailService->fetchSupportedModules($supplier, $hostId, $jwt);
        $module = collect($modules)->first(fn ($item) => is_array($item) && $this->isNatAclModule($item));

        throw_if(! is_array($module), new BusinessException('当前主机未开放 NAT 转发模块', 42200));

        $moduleKey = trim((string) ($module['function'] ?? '')) ?: 'nat_acl';
        $moduleName = trim((string) ($module['name'] ?? '')) ?: 'NAT 转发';
        $html = is_callable([$runtime, 'fetchCustomModulePage'])
            ? $runtime->fetchCustomModulePage($supplier, $hostId, $moduleKey, $jwt)
            : $this->fetchCustomModulePage($runtime, $supplier, $hostId, $jwt, $moduleKey);
        $page = $this->parseNatAclPage($html);

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

    public function forgetNatAclContextCache(Service $service): void
    {
        if ((int) ($service->id ?? 0) <= 0) {
            return;
        }

        Cache::forget($this->buildNatAclContextCacheKey($service));
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

    private function parseNatAclPage(string $html): array
    {
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return [
            'endpoint' => $this->extractSecurityGroupEndpoint($html),
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
     */
    public function fetchCustomModulePage(object $runtime, Supplier $supplier, int $hostId, string $jwt, string $moduleKey): string
    {
        $html = '';
        $contentUnavailableCacheKey = $this->buildCustomModuleContentUnavailableCacheKey($supplier->id, $hostId, $moduleKey);

        if (! Cache::get($contentUnavailableCacheKey)) {
            try {
                $rootUrl = $this->resolveSupplierRootUrl($supplier);
                $contentUrl = $rootUrl.'/provision/custom/content?'.http_build_query([
                    'id' => $hostId,
                    'key' => $moduleKey,
                ]);
                $contentResponse = $runtime->getText($supplier, $contentUrl, $jwt);
                $html = $this->normalizeModulePageBody($contentResponse);
            } catch (\Throwable) {
                $html = '';
            }

            if (trim($html) === '') {
                Cache::put($contentUnavailableCacheKey, true, now()->addSeconds(self::CUSTOM_MODULE_CONTENT_UNAVAILABLE_TTL_SECONDS));
            }
        }

        if (trim($html) === '') {
            $moduleResponse = $runtime->getText(
                $supplier,
                "/v1/hosts/{$hostId}/module/custom",
                $jwt,
                ['key' => $moduleKey]
            );
            $html = $this->normalizeModulePageBody($moduleResponse);
        }

        throw_if(trim($html) === '', new BusinessException('自定义模块页面为空', 50000));

        return $html;
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

    private function buildCustomModuleContentUnavailableCacheKey(int $supplierId, int $hostId, string $moduleKey): string
    {
        return "custom_module_content_unavailable:{$supplierId}:{$hostId}:".sha1($moduleKey);
    }
}
