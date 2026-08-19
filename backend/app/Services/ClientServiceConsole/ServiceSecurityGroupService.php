<?php

declare(strict_types=1);

namespace App\Services\ClientServiceConsole;

use App\Exceptions\BusinessException;
use App\Models\OperationLog;
use App\Models\Service;
use App\Models\User;
use App\Services\System\OperationLogService;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 安全组子服务
 * 负责：getSecurityGroupsForUser、getSecurityGroupRulesForUser、createSecurityGroupForUser、
 *       applySecurityGroupForUser、deleteSecurityGroupForUser、createSecurityRuleForUser、
 *       deleteSecurityRuleForUser 及安全组 HTML 解析方法
 */
class ServiceSecurityGroupService
{
    private const SECURITY_GROUP_CONTEXT_CACHE_TTL_SECONDS = 600;

    private const SECURITY_GROUP_MODULE_UNAVAILABLE_ERROR_CODE = 42201;

    private const SECURITY_GROUP_MODULE_KEYWORDS = ['安全组', '安全', 'securitygroup', 'security_group', 'security-group', 'security', 'secgroup', 'firewall', 'acl'];

    public function __construct(
        private readonly OperationLogService $operationLogService,
        private readonly ServiceDetailService $detailService,
        private readonly ServiceTransformService $transformService,
        private readonly ServiceNatService $natService,
    ) {}

    public function getSecurityGroupsForUser(User $user, int $serviceId, bool $fresh = false): array
    {
        $service = $this->detailService->findUserService($user, $serviceId, [
            'product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires',
            'product.productGroup.secondProductGroup.firstProductGroup',
            'product.supplier',
        ]);

        if (! $this->transformService->canManageService($service)) {
            return [
                'supported' => false,
                'message' => '当前服务暂不支持安全组管理',
                'error' => '',
                'module_key' => '',
                'module_name' => '',
                'host_type' => '',
                'directions' => [],
                'protocols' => [],
                'groups' => [],
                'can_create' => false,
            ];
        }

        try {
            $context = $this->resolveSecurityGroupContext($service, $fresh);

            return [
                'supported' => true,
                'message' => $context['groups'] === [] ? '当前暂无安全组' : '',
                'error' => '',
                'module_key' => $context['module_key'],
                'module_name' => $context['module_name'],
                'host_type' => $context['host_type'],
                'directions' => $context['directions'],
                'protocols' => $context['protocols'],
                'groups' => $context['groups'],
                'can_create' => (bool) ($context['can_create'] ?? true),
            ];
        } catch (BusinessException $exception) {
            Log::warning('[服务控制台] 读取安全组失败', [
                'service_id' => $service->id,
                'message' => SensitiveDataSanitizer::sanitizeText($exception->getMessage()),
            ]);

            $moduleUnavailable = $exception->getErrorCode() === self::SECURITY_GROUP_MODULE_UNAVAILABLE_ERROR_CODE;

            return [
                'supported' => false,
                'message' => $moduleUnavailable ? $exception->getMessage() : '',
                'error' => $moduleUnavailable ? '' : '读取安全组失败，请稍后重试',
                'module_key' => '',
                'module_name' => '',
                'host_type' => '',
                'directions' => [],
                'protocols' => [],
                'groups' => [],
                'can_create' => false,
            ];
        } catch (\Throwable $exception) {
            Log::warning('[服务控制台] 读取安全组失败', [
                'service_id' => $service->id,
                'message' => SensitiveDataSanitizer::sanitizeText($exception->getMessage()),
            ]);

            return [
                'supported' => false,
                'message' => '',
                'error' => '读取安全组失败，请稍后重试',
                'module_key' => '',
                'module_name' => '',
                'host_type' => '',
                'directions' => [],
                'protocols' => [],
                'groups' => [],
                'can_create' => false,
            ];
        }
    }

    public function getSecurityGroupRulesForUser(User $user, int $serviceId, int $groupId): array
    {
        $service = $this->detailService->findUserService($user, $serviceId, [
            'product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires',
            'product.productGroup.secondProductGroup.firstProductGroup',
            'product.supplier',
        ]);
        $securityGroupContext = $this->resolveSecurityGroupContext($service, true);
        $this->throwIfNativeSecurityGroupContext($securityGroupContext);
        $this->assertSecurityGroupBoundToCurrentHost($service, $groupId);

        $result = $this->callSecurityGroupAction($service, 'showSecurityRules', ['id' => $groupId], '读取安全组规则');
        $payload = $this->detailService->extractPayload($result['response']);
        $raw = $payload['list'] ?? [];
        $list = is_array($raw) ? (is_array($raw['preview'] ?? null) ? $raw['preview'] : array_values(array_filter($raw, 'is_array'))) : [];

        return [
            'group_id' => $groupId,
            'host_type' => $result['context']['host_type'],
            'list' => collect($list)
                ->filter(fn ($item) => is_array($item))
                ->map(fn (array $item) => $this->normalizeSecurityRuleItem($item, $result['context']['host_type']))
                ->values()->all(),
        ];
    }

    public function createSecurityGroupForUser(User $user, int $serviceId, array $data, array $context = []): array
    {
        $service = $this->detailService->findUserService($user, $serviceId, [
            'product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires',
            'product.productGroup.secondProductGroup.firstProductGroup',
            'product.supplier',
        ]);
        $name = trim((string) ($data['name'] ?? ''));
        $this->assertSecurityGroupNameAvailable($service, $name);

        $result = $this->callSecurityGroupAction($service, 'createSecurityGroup', [
            'name' => $name,
            'description' => trim((string) ($data['description'] ?? '')),
        ], '创建安全组');
        $this->forgetSecurityGroupContextCache($service);

        $message = trim((string) ($result['response']['msg'] ?? '')) ?: '安全组创建成功';
        $this->operationLogService->writeServiceConsoleLog($service, 'service.console.security_group.create', [
            'category' => 'security_group',
            'summary' => '创建安全组',
            'host_id' => (int) ($result['context']['host_id'] ?? 0),
            'group_name' => trim((string) ($data['name'] ?? '')),
            'description' => trim((string) ($data['description'] ?? '')),
            'message' => $message,
        ], $context);

        return ['message' => $message];
    }

    public function applySecurityGroupForUser(User $user, int $serviceId, int $groupId, array $context = []): array
    {
        $service = $this->detailService->findUserService($user, $serviceId, [
            'product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires',
            'product.productGroup.secondProductGroup.firstProductGroup',
            'product.supplier',
        ]);

        $securityGroupContext = $this->assertSecurityGroupVisibleToCurrentHost($service, $groupId);
        $runtime = $this->detailService->resolveRuntimeCapabilityForSupplier($securityGroupContext['supplier']);

        if ($this->isNativeSecurityGroupContext($securityGroupContext)) {
            throw_if(
                ! is_callable([$runtime, 'applySecurityGroup']),
                new BusinessException('当前上游未提供安全组应用能力', 42200)
            );

            $response = $runtime->applySecurityGroup(
                $securityGroupContext['supplier'],
                $groupId,
                (int) $securityGroupContext['host_id'],
                $securityGroupContext['jwt']
            );
            $this->detailService->assertSuccess($response, '应用安全组');
            $result = ['response' => $response, 'context' => $securityGroupContext];
        } else {
            $result = $this->callSecurityGroupAction(
                $service,
                'linkSecurityGroup',
                ['id' => $groupId],
                '应用安全组',
                $securityGroupContext
            );
        }
        $this->forgetSecurityGroupContextCache($service);

        $message = trim((string) ($result['response']['msg'] ?? '')) ?: '安全组已应用';
        $this->operationLogService->writeServiceConsoleLog($service, 'service.console.security_group.apply', [
            'category' => 'security_group',
            'summary' => '应用安全组',
            'host_id' => (int) ($result['context']['host_id'] ?? 0),
            'group_id' => $groupId,
            'group_name' => $this->resolveSecurityGroupName((array) ($result['context']['groups'] ?? []), $groupId),
            'message' => $message,
        ], $context);

        return ['message' => $message];
    }

    public function deleteSecurityGroupForUser(User $user, int $serviceId, int $groupId, array $context = []): array
    {
        $service = $this->detailService->findUserService($user, $serviceId, [
            'product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires',
            'product.productGroup.secondProductGroup.firstProductGroup',
            'product.supplier',
        ]);

        $securityGroupContext = $this->assertSecurityGroupVisibleToCurrentHost($service, $groupId);

        $result = $this->callSecurityGroupAction(
            $service,
            'delSecurityGroup',
            ['id' => $groupId],
            '删除安全组',
            $securityGroupContext
        );
        $this->forgetSecurityGroupContextCache($service);

        $message = trim((string) ($result['response']['msg'] ?? '')) ?: '安全组已删除';
        $this->operationLogService->writeServiceConsoleLog($service, 'service.console.security_group.delete', [
            'category' => 'security_group',
            'summary' => '删除安全组',
            'host_id' => (int) ($result['context']['host_id'] ?? 0),
            'group_id' => $groupId,
            'group_name' => $this->resolveSecurityGroupName((array) ($result['context']['groups'] ?? []), $groupId),
            'message' => $message,
        ], $context);

        return ['message' => $message];
    }

    public function createSecurityRuleForUser(User $user, int $serviceId, int $groupId, array $data, array $context = []): array
    {
        $service = $this->detailService->findUserService($user, $serviceId, [
            'product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires',
            'product.productGroup.secondProductGroup.firstProductGroup',
            'product.supplier',
        ]);

        $securityGroupContext = $this->assertSecurityGroupVisibleToCurrentHost($service, $groupId);
        $direction = trim((string) ($data['direction'] ?? ''));
        $protocol = trim((string) ($data['protocol'] ?? ''));
        $port = trim((string) ($data['port'] ?? ''));
        $ip = trim((string) ($data['ip'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));

        $this->assertSecurityRulePayload($securityGroupContext, $direction, $protocol, $port, $ip);

        $result = $this->callSecurityGroupAction(
            $service,
            'createSecurityRule',
            [
                'id' => $groupId,
                'direction' => $direction,
                'protocol' => $protocol,
                'port' => $port,
                'ip' => $ip,
                'description' => $description,
            ],
            '创建安全组规则',
            $securityGroupContext
        );
        $this->forgetSecurityGroupContextCache($service);

        $message = trim((string) ($result['response']['msg'] ?? '')) ?: '规则创建成功';
        $this->operationLogService->writeServiceConsoleLog($service, 'service.console.security_rule.create', [
            'category' => 'security_rule',
            'summary' => '创建安全组规则',
            'host_id' => (int) ($result['context']['host_id'] ?? 0),
            'group_id' => $groupId,
            'group_name' => $this->resolveSecurityGroupName((array) ($result['context']['groups'] ?? []), $groupId),
            'direction' => $direction,
            'direction_label' => $this->transformService->resolveSelectOptionLabel((array) ($result['context']['directions'] ?? []), $direction),
            'protocol' => $protocol,
            'protocol_label' => $this->transformService->resolveSelectOptionLabel((array) ($result['context']['protocols'] ?? []), $protocol),
            'port' => $port,
            'ip' => $ip,
            'description' => $description,
            'message' => $message,
        ], $context);

        return ['message' => $message];
    }

    public function deleteSecurityRuleForUser(User $user, int $serviceId, int $groupId, int $ruleId, array $context = []): array
    {
        $service = $this->detailService->findUserService($user, $serviceId, [
            'product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires',
            'product.productGroup.secondProductGroup.firstProductGroup',
            'product.supplier',
        ]);

        $securityGroupContext = $this->assertSecurityGroupVisibleToCurrentHost($service, $groupId);

        $result = $this->callSecurityGroupAction(
            $service,
            'delSecurityRule',
            [
                'id' => $ruleId,
                'group' => $groupId,
            ],
            '删除安全组规则',
            $securityGroupContext
        );
        $this->forgetSecurityGroupContextCache($service);

        $message = trim((string) ($result['response']['msg'] ?? '')) ?: '规则已删除';
        $this->operationLogService->writeServiceConsoleLog($service, 'service.console.security_rule.delete', [
            'category' => 'security_rule',
            'summary' => '删除安全组规则',
            'host_id' => (int) ($result['context']['host_id'] ?? 0),
            'group_id' => $groupId,
            'group_name' => $this->resolveSecurityGroupName((array) ($result['context']['groups'] ?? []), $groupId),
            'rule_id' => $ruleId,
            'message' => $message,
        ], $context);

        return ['message' => $message];
    }

    // ── Security group context resolution ─────────────────────────────────

    public function resolveSecurityGroupContext(Service $service, bool $fresh = false): array
    {
        [$supplier, $hostId] = $this->detailService->resolveManagedSupplierAndHost($service);
        $cacheKey = $this->buildSecurityGroupContextCacheKey($service);
        $runtime = $this->detailService->resolveRuntimeCapabilityForSupplier($supplier);
        $mode = is_callable([$runtime, 'getSecurityGroups']) ? 'native' : 'custom';

        if (! $fresh && ($cached = Cache::get($cacheKey)) && is_array($cached)) {
            $cached['supplier'] = $supplier;
            if (
                trim((string) ($cached['jwt'] ?? '')) !== ''
                && (($cached['mode'] ?? 'custom') === $mode)
            ) {
                return $cached;
            }
        }

        $jwt = $runtime->login($supplier);

        if ($mode === 'native') {
            $response = $runtime->getSecurityGroups($supplier, 1, 9999, $jwt);
            $this->detailService->assertSuccess($response, '读取安全组');
            $payload = $this->detailService->extractPayload($response);
            $rawGroups = $this->extractNativeSecurityGroupRows($payload);
            $ownedBindings = $this->resolveOwnedSecurityGroupBindingsForService($service);
            $groups = collect($rawGroups)
                ->map(fn (array $item) => $this->normalizeNativeSecurityGroupItem($item, $hostId))
                ->filter()
                ->filter(function (array $item) use ($ownedBindings, $rawGroups): bool {
                    if ((bool) ($item['is_applied'] ?? false)) {
                        return true;
                    }

                    return $this->isSecurityGroupBoundToBindings($ownedBindings, (int) ($item['id'] ?? 0), $rawGroups);
                })
                ->values()
                ->all();

            $context = [
                'mode' => 'native',
                'supplier' => $supplier,
                'supplier_id' => $supplier->id,
                'host_id' => $hostId,
                'jwt' => $jwt,
                'module_key' => 'security_group',
                'module_name' => '安全组',
                'host_type' => '',
                'directions' => [],
                'protocols' => [],
                'raw_groups' => $rawGroups,
                'groups' => $groups,
                'can_create' => false,
            ];

            Cache::put(
                $cacheKey,
                collect($context)->except('supplier')->all(),
                now()->addSeconds(self::SECURITY_GROUP_CONTEXT_CACHE_TTL_SECONDS)
            );

            return $context;
        }

        $modules = $this->detailService->fetchSupportedModules($supplier, $hostId, $jwt, $fresh);
        $module = collect($modules)->first(fn ($item) => is_array($item) && $this->isSecurityGroupModule($item));

        throw_if(! is_array($module), new BusinessException('当前主机未开放安全组模块', self::SECURITY_GROUP_MODULE_UNAVAILABLE_ERROR_CODE));

        $moduleKey = trim((string) ($module['function'] ?? ''));
        $moduleName = trim((string) ($module['name'] ?? '')) ?: '安全组';
        $html = is_callable([$runtime, 'fetchCustomModulePage'])
            ? $runtime->fetchCustomModulePage($supplier, $hostId, $moduleKey, $jwt)
            : $this->natService->fetchCustomModulePage($runtime, $supplier, $hostId, $jwt, $moduleKey);
        $actionEndpoint = is_callable([$runtime, 'getCustomModuleActionEndpoint'])
            ? $runtime->getCustomModuleActionEndpoint($supplier, $hostId)
            : null;
        $page = $this->parseSecurityGroupPage($service, $html, $actionEndpoint);

        $context = [
            'mode' => 'custom',
            'supplier' => $supplier,
            'supplier_id' => $supplier->id,
            'host_id' => $hostId,
            'jwt' => $jwt,
            'module_key' => $moduleKey,
            'module_name' => $moduleName,
            'endpoint' => $page['endpoint'],
            'host_type' => $page['host_type'],
            'directions' => $page['directions'],
            'protocols' => $page['protocols'],
            'raw_groups' => $page['raw_groups'],
            'groups' => $page['groups'],
            'html' => $page['html'],
            'can_create' => true,
        ];

        Cache::put(
            $cacheKey,
            collect($context)->except('supplier')->all(),
            now()->addSeconds(self::SECURITY_GROUP_CONTEXT_CACHE_TTL_SECONDS)
        );

        return $context;
    }

    public function forgetSecurityGroupContextCache(Service $service): void
    {
        if ((int) ($service->id ?? 0) <= 0) {
            return;
        }

        Cache::forget($this->buildSecurityGroupContextCacheKey($service));
    }

    // ── Private helpers ────────────────────────────────────────────────────

    private function callSecurityGroupAction(Service $service, string $func, array $payload, string $action, ?array $context = null): array
    {
        $context ??= $this->resolveSecurityGroupContext($service);
        $this->throwIfNativeSecurityGroupContext($context);
        $runtime = $this->detailService->resolveRuntimeCapabilityForSupplier($context['supplier']);
        $requestPayload = array_merge($payload, ['func' => $func]);
        $response = is_callable([$runtime, 'submitCustomModuleAction'])
            ? $runtime->submitCustomModuleAction($context['supplier'], $context['endpoint'], $requestPayload, $context['jwt'])
            : $runtime->post(
                $context['supplier'],
                $context['endpoint'],
                $requestPayload,
                $context['jwt'],
                ['content-type: application/x-www-form-urlencoded']
            );
        $this->detailService->assertSuccess($response, $action);

        return ['context' => $context, 'response' => $response];
    }

    private function assertSecurityRulePayload(array $context, string $direction, string $protocol, string $port, string $ip): void
    {
        $this->assertSecurityOptionAllowed((array) ($context['directions'] ?? []), $direction, '方向');
        $this->assertSecurityOptionAllowed((array) ($context['protocols'] ?? []), $protocol, '协议');
        $this->assertSecurityPortExpression($port);
        $this->assertSecurityIpExpression($ip);
    }

    private function assertSecurityOptionAllowed(array $options, string $value, string $label): void
    {
        if ($value === '') {
            throw new BusinessException('请选择安全组规则'.$label, 42200);
        }

        if ($options === []) {
            return;
        }

        $allowed = collect($options)
            ->filter(fn ($item): bool => is_array($item))
            ->map(fn (array $item): string => trim((string) ($item['value'] ?? '')))
            ->filter(fn (string $item): bool => $item !== '')
            ->values()
            ->all();

        throw_if(
            $allowed !== [] && ! in_array($value, $allowed, true),
            new BusinessException('安全组规则'.$label.'不在当前上游允许范围内', 42200)
        );
    }

    private function assertSecurityPortExpression(string $value): void
    {
        throw_if($value === '', new BusinessException('请填写安全组规则端口', 42200));

        $segments = preg_split('/\s*,\s*/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        throw_if($segments === [], new BusinessException('请填写安全组规则端口', 42200));

        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '*') {
                continue;
            }

            if (preg_match('/^\d{1,5}$/', $segment) === 1) {
                $port = (int) $segment;
                throw_if($port < 1 || $port > 65535, new BusinessException('端口必须在 1-65535 之间', 42200));
                continue;
            }

            if (preg_match('/^(\d{1,5})-(\d{1,5})$/', $segment, $matches) === 1) {
                $start = (int) $matches[1];
                $end = (int) $matches[2];
                throw_if($start < 1 || $start > 65535 || $end < 1 || $end > 65535 || $start > $end, new BusinessException('端口范围必须在 1-65535 之间且起始端口不能大于结束端口', 42200));
                continue;
            }

            throw new BusinessException('端口格式不正确，仅支持单端口、端口范围或英文逗号分隔列表', 42200);
        }
    }

    private function assertSecurityIpExpression(string $value): void
    {
        throw_if($value === '', new BusinessException('请填写安全组规则授权地址', 42200));

        $segments = preg_split('/\s*,\s*/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        throw_if($segments === [], new BusinessException('请填写安全组规则授权地址', 42200));

        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '*') {
                continue;
            }

            if (filter_var($segment, FILTER_VALIDATE_IP) !== false) {
                continue;
            }

            if (preg_match('/^([^\/]+)\/(\d{1,3})$/', $segment, $matches) === 1) {
                $ip = trim((string) $matches[1]);
                $prefix = (int) $matches[2];
                $isV4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
                $isV6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
                throw_if(! $isV4 && ! $isV6, new BusinessException('授权地址必须是合法 IP 或 CIDR', 42200));
                throw_if($isV4 && ($prefix < 0 || $prefix > 32), new BusinessException('IPv4 CIDR 前缀必须在 0-32 之间', 42200));
                throw_if($isV6 && ($prefix < 0 || $prefix > 128), new BusinessException('IPv6 CIDR 前缀必须在 0-128 之间', 42200));
                continue;
            }

            throw new BusinessException('授权地址必须是合法 IP、CIDR 或英文逗号分隔列表', 42200);
        }
    }

    private function isNativeSecurityGroupContext(array $context): bool
    {
        return ($context['mode'] ?? '') === 'native';
    }

    private function throwIfNativeSecurityGroupContext(array $context): void
    {
        throw_if(
            $this->isNativeSecurityGroupContext($context),
            new BusinessException('当前上游仅支持安全组列表和应用操作', 42200)
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractNativeSecurityGroupRows(array $payload): array
    {
        $rows = $payload['list'] ?? $payload['security_groups'] ?? [];
        if (! is_array($rows)) {
            return [];
        }

        if (! array_is_list($rows) && isset($rows['id'])) {
            $rows = [$rows];
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeNativeSecurityGroupItem(array $item, int $hostId): ?array
    {
        $id = (int) (($item['id'] ?? $item['security_group_id'] ?? 0) ?: 0);
        if ($id <= 0) {
            return null;
        }

        return [
            'id' => $id,
            'name' => trim((string) ($item['name'] ?? $item['security_group_name'] ?? $item['title'] ?? '')),
            'description' => trim((string) ($item['description'] ?? $item['remark'] ?? $item['notes'] ?? '')),
            'can_view' => false,
            'can_add_rule' => false,
            'can_apply' => true,
            'can_delete' => false,
            'apply_disabled' => false,
            'delete_disabled' => true,
            'is_applied' => $this->isNativeSecurityGroupAppliedToHost($item, $hostId),
            'raw' => $item,
        ];
    }

    private function isNativeSecurityGroupAppliedToHost(array $item, int $hostId): bool
    {
        foreach (['is_applied', 'applied'] as $key) {
            if (array_key_exists($key, $item)) {
                return in_array($item[$key], [true, 1, '1', 'true', 'yes'], true);
            }
        }

        foreach (['host_id', 'hostid', 'service_id'] as $key) {
            if ((int) ($item[$key] ?? 0) === $hostId) {
                return true;
            }
        }

        foreach (['host_ids', 'hosts'] as $key) {
            $hosts = $item[$key] ?? [];
            if (is_string($hosts)) {
                $hosts = preg_split('/[\s,]+/', trim($hosts), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            }

            foreach ((array) $hosts as $host) {
                $candidateId = is_array($host)
                    ? (int) (($host['id'] ?? $host['host_id'] ?? 0) ?: 0)
                    : (int) $host;

                if ($candidateId === $hostId) {
                    return true;
                }
            }
        }

        return false;
    }

    private function assertSecurityGroupNameAvailable(Service $service, string $name): void
    {
        if ($name === '') {
            return;
        }

        $context = $this->resolveSecurityGroupContext($service, true);
        $normalizedName = $this->normalizeKeywordText($name);
        $groups = (array) ($context['raw_groups'] ?? []);
        if ($groups === []) {
            $groups = (array) ($context['groups'] ?? []);
        }

        $exists = collect($groups)
            ->contains(fn ($item) => is_array($item)
                && $this->normalizeKeywordText((string) ($item['name'] ?? '')) === $normalizedName);

        throw_if($exists, new BusinessException('安全组名称已存在，请换一个名称', 42200));
    }

    private function assertSecurityGroupBoundToCurrentHost(Service $service, int $groupId): void
    {
        if ($groupId <= 0) {
            throw new BusinessException('安全组不存在或未绑定到当前主机', 42200);
        }

        $bindings = $this->resolveOwnedSecurityGroupBindingsForService($service);
        $context = $this->resolveSecurityGroupContext($service, true);
        throw_if(
            ! $this->isSecurityGroupBoundToBindings($bindings, $groupId, (array) ($context['raw_groups'] ?? [])),
            new BusinessException('安全组不存在或未绑定到当前主机', 42200)
        );
    }

    private function assertSecurityGroupVisibleToCurrentHost(Service $service, int $groupId): array
    {
        if ($groupId <= 0) {
            throw new BusinessException('安全组不存在或不允许当前服务操作', 42200);
        }

        $context = $this->resolveSecurityGroupContext($service, true);
        $visible = collect((array) ($context['groups'] ?? []))
            ->contains(fn ($item) => is_array($item) && (int) ($item['id'] ?? 0) === $groupId);

        throw_if(
            ! $visible,
            new BusinessException('安全组不存在或不允许当前服务操作', 42200)
        );

        return $context;
    }

    private function isSecurityGroupBoundToBindings(array $bindings, int $groupId, array $availableGroups = []): bool
    {
        if ($groupId <= 0) {
            return false;
        }

        $boundIds = array_values(array_filter(
            array_map(static fn ($value) => (int) $value, (array) ($bindings['ids'] ?? [])),
            static fn (int $value) => $value > 0
        ));

        if (in_array($groupId, $boundIds, true)) {
            return true;
        }

        $boundNames = array_values(array_filter(
            array_map(fn ($value) => $this->normalizeKeywordText((string) $value), (array) ($bindings['names'] ?? [])),
            static fn (string $value) => $value !== ''
        ));
        if ($boundNames === []) {
            return false;
        }

        $groupName = $this->resolveSecurityGroupName($availableGroups, $groupId);
        if ($groupName === '') {
            return false;
        }

        return in_array($this->normalizeKeywordText($groupName), $boundNames, true);
    }

    private function isSecurityGroupModule(array $module): bool
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

        if (str_contains($text, 'nat') || str_contains($text, '端口转发') || str_contains($text, '端口映射')) {
            return false;
        }

        return ($type === '' || strtolower($type) === 'custom')
            && collect(self::SECURITY_GROUP_MODULE_KEYWORDS)
                ->contains(fn (string $keyword) => str_contains($text, $this->normalizeKeywordText($keyword)));
    }

    private function normalizeKeywordText(string $value): string
    {
        return preg_replace('/\s+/u', '', mb_strtolower(trim($value), 'UTF-8')) ?? '';
    }

    private function parseSecurityGroupPage(Service $service, string $html, ?string $actionEndpoint = null): array
    {
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $endpoint = trim((string) $actionEndpoint);
        if ($endpoint === '') {
            $endpoint = $this->extractSecurityGroupEndpoint($html);
        }
        $ownedBindings = $this->resolveOwnedSecurityGroupBindingsForService($service);

        throw_if($endpoint === '', new BusinessException('已发现安全组模块，但未解析到上游请求地址', 50000));

        return [
            'html' => $html,
            'endpoint' => $endpoint,
            'host_type' => $this->extractSecurityGroupHostType($html),
            'directions' => $this->extractSecuritySelectOptions($html, 'direction'),
            'protocols' => $this->extractSecuritySelectOptions($html, 'protocol'),
            'raw_groups' => $this->extractSecurityGroupRows($html),
            'groups' => $this->extractSecurityGroupRows($html, $ownedBindings),
        ];
    }

    private function extractSecurityGroupEndpoint(string $html): string
    {
        if (preg_match('/url\s*:\s*[\'"]([^\'"]*\/provision\/custom\/[^\'"]+)[\'"]/iu', $html, $matches) === 1) {
            return trim((string) ($matches[1] ?? ''));
        }

        return '';
    }

    private function extractSecurityGroupHostType(string $html): string
    {
        if (preg_match('/var\s+host_type\s*=\s*[\'"]([^\'"]+)[\'"]/iu', $html, $matches) === 1) {
            return trim((string) ($matches[1] ?? '')) ?: 'host';
        }

        return 'host';
    }

    private function extractSecuritySelectOptions(string $html, string $fieldName): array
    {
        $xpath = $this->createHtmlXPath($html);
        if (! $xpath) {
            return [];
        }

        $options = $xpath->query("//select[@name='{$fieldName}']/option");
        if (! $options) {
            return [];
        }

        $normalized = [];
        foreach ($options as $option) {
            $value = trim((string) ($option->attributes?->getNamedItem('value')?->nodeValue ?? ''));
            if ($value === '') {
                continue;
            }

            $item = ['value' => $value, 'label' => $this->normalizeHtmlNodeText($option)];
            $port = trim((string) ($option->attributes?->getNamedItem('data-port')?->nodeValue ?? ''));
            if ($port !== '') {
                $item['port'] = $port;
            }

            $normalized[] = $item;
        }

        return $normalized;
    }

    private function extractSecurityGroupRows(string $html, array $ownedBindings = []): array
    {
        $xpath = $this->createHtmlXPath($html);
        if (! $xpath) {
            return [];
        }

        $rows = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' table-responsive ')]//table[1]/tbody/tr");
        if (! $rows) {
            return [];
        }

        $groups = [];
        foreach ($rows as $row) {
            $rowClass = strtolower(trim((string) ($row->attributes?->getNamedItem('class')?->nodeValue ?? '')));
            if ($rowClass !== '' && str_contains($rowClass, 'expandline')) {
                continue;
            }

            $cells = [];
            $cellNodes = $xpath->query('./td', $row);
            if (! $cellNodes) {
                continue;
            }
            foreach ($cellNodes as $cell) {
                $cells[] = $cell;
            }
            if (count($cells) < 2) {
                continue;
            }

            $actionState = $this->extractSecurityGroupRowActions($xpath, $row);
            $groupId = (int) ($actionState['id'] ?? 0);
            if ($groupId <= 0) {
                continue;
            }

            $rawName = $this->normalizeHtmlNodeText($cells[0]);
            $isAppliedByName = preg_match('/^[（(]\s*当前\s*[)）]\s*/u', $rawName) === 1;
            $normalizedName = trim((string) (preg_replace('/^[（(]\s*当前\s*[)）]\s*/u', '', $rawName) ?? $rawName));
            $isApplied = (bool) ($actionState['is_applied'] ?? false) || $isAppliedByName;

            $groups[] = [
                'id' => $groupId,
                'name' => $normalizedName !== '' ? $normalizedName : $rawName,
                'description' => $this->normalizeHtmlNodeText($cells[1]),
                'can_view' => (bool) ($actionState['can_view'] ?? false),
                'can_add_rule' => (bool) ($actionState['can_add_rule'] ?? true),
                'can_apply' => (bool) ($actionState['can_apply'] ?? false),
                'can_delete' => (bool) ($actionState['can_delete'] ?? false),
                'apply_disabled' => (bool) ($actionState['apply_disabled'] ?? false) || $isApplied,
                'delete_disabled' => (bool) ($actionState['delete_disabled'] ?? false),
                'apply_text' => (string) ($actionState['apply_text'] ?? ''),
                'delete_text' => (string) ($actionState['delete_text'] ?? ''),
                'view_text' => (string) ($actionState['view_text'] ?? ''),
                'add_rule_text' => (string) ($actionState['add_rule_text'] ?? ''),
                'is_applied' => $isApplied,
            ];
        }

        if ($ownedBindings === []) {
            return $groups;
        }

        return $this->filterSecurityGroupsByOwnedBindings($groups, $ownedBindings);
    }

    private function filterSecurityGroupsByOwnedBindings(array $groups, array $ownedBindings = []): array
    {
        if ($groups === []) {
            return [];
        }

        $ownedIds = array_fill_keys(
            array_values(array_filter(
                array_map(static fn ($value) => (int) $value, (array) ($ownedBindings['ids'] ?? [])),
                static fn (int $value) => $value > 0
            )),
            true
        );
        $ownedNames = array_fill_keys(
            array_values(array_filter(
                array_map(fn ($value) => $this->normalizeKeywordText((string) $value), (array) ($ownedBindings['names'] ?? [])),
                static fn (string $value) => $value !== ''
            )),
            true
        );

        return array_values(array_map(function (array $group) use ($ownedIds, $ownedNames): array {
            // 用户拥有的安全组，应允许删除和应用
            $groupId = (int) ($group['id'] ?? 0);
            $normalizedName = $this->normalizeKeywordText((string) ($group['name'] ?? ''));
            $isOwned = ($groupId > 0 && isset($ownedIds[$groupId])) || ($normalizedName !== '' && isset($ownedNames[$normalizedName]));
            $isApplied = (bool) ($group['is_applied'] ?? false);

            if ($isOwned) {
                // 强制启用删除和应用权限（上游HTML解析可能失败）
                $group['can_delete'] = true;
                $group['can_apply'] = true;
                // 已应用的安全组不能重复应用
                $group['apply_disabled'] = $isApplied;
                // 删除不限制（上游会判断是否允许删除已应用的安全组）
                $group['delete_disabled'] = false;
                if (empty($group['delete_text'])) {
                    $group['delete_text'] = '删除';
                }
                if (empty($group['apply_text'])) {
                    $group['apply_text'] = '应用';
                }
            }

            return $group;
        }, array_filter($groups, function (array $group) use ($ownedIds, $ownedNames): bool {
            if ((bool) ($group['is_applied'] ?? false)) {
                return true;
            }

            $groupId = (int) ($group['id'] ?? 0);
            if ($groupId > 0 && isset($ownedIds[$groupId])) {
                return true;
            }

            $normalizedName = $this->normalizeKeywordText((string) ($group['name'] ?? ''));

            return $normalizedName !== '' && isset($ownedNames[$normalizedName]);
        })));
    }

    private function resolveOwnedSecurityGroupBindingsForService(Service $service): array
    {
        $hostId = $this->resolveSecurityGroupBindingHostId($service);
        if ($hostId <= 0) {
            return ['ids' => [], 'names' => []];
        }

        $logs = OperationLog::query()
            ->where('module', 'service')
            ->whereIn('action', [
                'service.console.security_group.create',
                'service.console.security_group.apply',
                'service.console.security_group.delete',
            ])
            ->where('context->host_id', $hostId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['action', 'context']);

        return $this->collectOwnedSecurityGroupBindingsFromLogs($logs, $hostId);
    }

    private function collectOwnedSecurityGroupBindingsFromLogs(iterable $logs, int $hostId): array
    {
        if ($hostId <= 0) {
            return ['ids' => [], 'names' => []];
        }

        $ownedIds = [];
        $ownedNames = [];

        foreach ($logs as $log) {
            $detail = is_array($log)
                ? (array) ($log['detail'] ?? $log['context'] ?? [])
                : (array) ($log->detail ?? []);
            if ((int) ($detail['host_id'] ?? 0) !== $hostId) {
                continue;
            }

            $action = is_array($log) ? (string) ($log['action'] ?? '') : (string) ($log->action ?? '');
            $groupId = (int) ($detail['group_id'] ?? 0);
            $normalizedName = $this->normalizeKeywordText((string) ($detail['group_name'] ?? ''));

            if ($action === 'service.console.security_group.delete') {
                if ($groupId > 0) {
                    unset($ownedIds[$groupId]);
                }
                if ($normalizedName !== '') {
                    unset($ownedNames[$normalizedName]);
                }

                continue;
            }

            if ($groupId > 0) {
                $ownedIds[$groupId] = true;
            }
            if ($normalizedName !== '') {
                $ownedNames[$normalizedName] = true;
            }
        }

        return [
            'ids' => array_map('intval', array_keys($ownedIds)),
            'names' => array_map('strval', array_keys($ownedNames)),
        ];
    }

    private function resolveSecurityGroupBindingHostId(Service $service): int
    {
        try {
            [, $hostId] = $this->detailService->resolveManagedSupplierAndHost($service);

            return (int) $hostId;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function extractSecurityGroupRowActions(\DOMXPath $xpath, \DOMNode $row): array
    {
        $buttons = $xpath->query('.//*[@data-id or self::button or self::a]', $row);
        $state = [
            'id' => 0,
            'can_view' => false,
            'can_add_rule' => true,
            'can_apply' => false,
            'can_delete' => false,
            'apply_disabled' => false,
            'delete_disabled' => false,
            'apply_text' => '',
            'delete_text' => '',
            'view_text' => '',
            'add_rule_text' => '',
            'is_applied' => false,
        ];

        if (! $buttons) {
            return $state;
        }

        foreach ($buttons as $button) {
            $dataId = (int) (($button->attributes?->getNamedItem('data-id')?->nodeValue ?? 0) ?: 0);
            if ($dataId > 0 && $state['id'] === 0) {
                $state['id'] = $dataId;
            }

            $class = strtolower(trim((string) ($button->attributes?->getNamedItem('class')?->nodeValue ?? '')));
            $text = $this->normalizeHtmlNodeText($button);
            $disabled = strtolower(trim((string) ($button->attributes?->getNamedItem('data-disabled')?->nodeValue ?? ''))) === 'true'
                || $button->attributes?->getNamedItem('disabled') !== null;

            if ($class !== '' && str_contains($class, 'trview') || str_contains($text, '查看')) {
                $state['can_view'] = true;
                $state['view_text'] = $text !== '' ? $text : '查看规则';

                continue;
            }

            if (($class !== '' && str_contains($class, 'apply')) || str_contains($text, '应用')) {
                $state['can_apply'] = true;
                $state['apply_disabled'] = $disabled;
                $state['apply_text'] = $text !== '' ? $text : '应用';
                $state['is_applied'] = $disabled && preg_match('/已应用|当前/u', $text) === 1;

                continue;
            }

            if (($class !== '' && str_contains($class, 'deletegroup')) || str_contains($text, '删除')) {
                $state['can_delete'] = true;
                $state['delete_disabled'] = $disabled;
                $state['delete_text'] = $text !== '' ? $text : '删除';

                continue;
            }

            if (str_contains($text, '策略')) {
                $state['can_add_rule'] = true;
                $state['add_rule_text'] = $text !== '' ? $text : '新增规则';
            }
        }

        return $state;
    }

    private function normalizeSecurityRuleItem(array $item, string $hostType): array
    {
        return [
            'id' => (int) (($item['id'] ?? 0) ?: 0),
            'description' => trim((string) ($item['description'] ?? '')),
            'direction' => trim((string) ($item['direction'] ?? '')),
            'direction_label' => match (strtolower(trim((string) ($item['direction'] ?? '')))) {
                'in' => '入方向',
                'out' => '出方向',
                default => '--',
            },
            'protocol' => trim((string) ($item['protocol'] ?? '')),
            'port' => $this->normalizeSecurityRulePort($item),
            'ip' => $this->normalizeSecurityRuleIp($item),
            'action' => trim((string) ($item['action'] ?? '')),
            'action_label' => match (strtolower(trim((string) ($item['action'] ?? '')))) {
                'accept' => '允许',
                'deny' => '拒绝',
                default => '',
            },
            'priority' => isset($item['priority']) ? (int) $item['priority'] : null,
            'lock' => isset($item['lock']) ? (int) $item['lock'] : 0,
            'create_time' => trim((string) ($item['create_time'] ?? '')),
            'host_type' => $hostType,
            'raw' => $item,
        ];
    }

    private function normalizeSecurityRulePort(array $item): string
    {
        $port = trim((string) ($item['port'] ?? ''));
        if ($port !== '') {
            return $port;
        }

        $startPort = trim((string) ($item['start_port'] ?? ''));
        $endPort = trim((string) ($item['end_port'] ?? ''));

        if ($startPort === '' && $endPort === '') {
            return '';
        }

        return $startPort !== '' && $startPort === $endPort
            ? $startPort
            : trim($startPort.'-'.$endPort, '-');
    }

    private function normalizeSecurityRuleIp(array $item): string
    {
        $ip = trim((string) ($item['ip'] ?? ''));
        if ($ip !== '') {
            return $ip;
        }

        $startIp = trim((string) ($item['start_ip'] ?? ''));
        $endIp = trim((string) ($item['end_ip'] ?? ''));

        if ($startIp === '' && $endIp === '') {
            return '';
        }

        return $startIp !== '' && $startIp === $endIp
            ? $startIp
            : trim($startIp.'-'.$endIp, '-');
    }

    private function resolveSecurityGroupName(array $groups, int $groupId): string
    {
        if ($groupId <= 0) {
            return '';
        }

        $group = collect($groups)->first(fn ($item) => is_array($item) && (int) ($item['id'] ?? 0) === $groupId);

        return trim((string) ($group['name'] ?? ''));
    }

    private function buildSecurityGroupContextCacheKey(Service $service): string
    {
        return 'sg_ctx:service:'.(int) $service->id;
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
}
