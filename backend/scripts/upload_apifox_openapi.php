<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route as RouteFacade;

define('LARAVEL_START', microtime(true));

$basePath = dirname(__DIR__);

require $basePath.'/vendor/autoload.php';

$app = require $basePath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$options = parseCliOptions($argv);
$codexConfig = (string) ($options['codex-config'] ?? defaultCodexConfigPath());
$mcpServer = (string) ($options['mcp-server'] ?? 'turaidc-apifox-mcp');
$projectId = (string) ($options['project-id'] ?? '');
$token = (string) getenv('APIFOX_ACCESS_TOKEN');

if ($projectId === '' || $token === '') {
    [$configProjectId, $configToken] = readApifoxMcpConfig($codexConfig, $mcpServer);
    $projectId = $projectId !== '' ? $projectId : $configProjectId;
    $token = $token !== '' ? $token : $configToken;
}

if ($projectId === '') {
    fwrite(STDERR, "缺少 Apifox projectId，请传 --project-id=xxx 或配置 turaidc-apifox-mcp。\n");
    exit(1);
}

if ($token === '') {
    fwrite(STDERR, "缺少 APIFOX_ACCESS_TOKEN，请设置环境变量或配置 turaidc-apifox-mcp.env。\n");
    exit(1);
}

$openApi = buildOpenApiSpec();
$endpointCount = countOpenApiOperations($openApi);

if (isset($options['output'])) {
    file_put_contents((string) $options['output'], json_encode($openApi, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
}

if (array_key_exists('dry-run', $options)) {
    fwrite(STDOUT, sprintf(
        "已生成 OpenAPI，接口数: %d，路径数: %d，未上传（dry-run）。\n",
        $endpointCount,
        count($openApi['paths'])
    ));
    exit(0);
}

$payload = [
    'input' => json_encode($openApi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'options' => [
        'endpointOverwriteBehavior' => 'OVERWRITE_EXISTING',
        'schemaOverwriteBehavior' => 'OVERWRITE_EXISTING',
        'updateFolderOfChangedEndpoint' => true,
        'prependBasePath' => false,
        'deleteUnmatchedResources' => filter_var($options['delete-unmatched'] ?? false, FILTER_VALIDATE_BOOL),
    ],
];

$url = sprintf('https://api.apifox.com/v1/projects/%s/import-openapi?locale=zh-CN', rawurlencode($projectId));

try {
    [$status, $responseBody] = importWithLaravelHttp($url, $token, $payload);
} catch (Throwable $e) {
    if (! str_contains($e->getMessage(), 'SSL certificate problem')) {
        throw $e;
    }

    fwrite(STDOUT, "PHP cURL 缺少本机 CA 配置，改用系统 curl.exe 重试。\n");
    [$status, $responseBody] = importWithCurlCli($url, $token, $payload);
}

if ($status < 200 || $status >= 300) {
    fwrite(STDERR, sprintf(
        "Apifox 导入失败，HTTP %d: %s\n",
        $status,
        maskSensitiveText($responseBody)
    ));
    exit(1);
}

$body = json_decode($responseBody, true);
if (! is_array($body)) {
    fwrite(STDERR, 'Apifox 导入响应不是有效 JSON: '.maskSensitiveText($responseBody)."\n");
    exit(1);
}

$counters = $body['data']['counters'] ?? [];
$errors = $body['data']['errors'] ?? [];

fwrite(STDOUT, sprintf(
    "Apifox 导入完成，生成接口数: %d，路径数: %d。\n",
    $endpointCount,
    count($openApi['paths'])
));

if ($counters !== []) {
    fwrite(STDOUT, '导入统计: '.json_encode($counters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
}

if ($errors !== []) {
    fwrite(STDOUT, '导入提示: '.json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
}

function buildOpenApiSpec(): array
{
    $schemas = baseComponentSchemas();
    $routes = collect(RouteFacade::getRoutes()->getRoutes())
        ->filter(fn (Route $route): bool => str_starts_with($route->uri(), 'api/'))
        ->map(function (Route $route): array {
            $methods = array_values(array_filter($route->methods(), fn (string $method): bool => $method !== 'HEAD'));
            $middleware = array_values(array_unique($route->middleware()));
            $uri = '/'.ltrim($route->uri(), '/');
            $action = resolveAction($route);
            $actionParts = resolveActionParts($action);

            return [
                'route' => $route,
                'group' => resolveGroup($uri),
                'methods' => $methods,
                'path' => $uri,
                'action' => $action,
                'controller' => $actionParts['controller'],
                'controller_method' => $actionParts['method'],
                'request_classes' => resolveFormRequestClasses($actionParts['controller'], $actionParts['method']),
                'auth' => resolveAuth($middleware),
                'permission' => resolvePermission($middleware),
                'middleware' => implode(', ', $middleware),
            ];
        })
        ->sortBy([
            ['group', 'asc'],
            ['path', 'asc'],
        ])
        ->values();

    $paths = [];

    foreach ($routes as $route) {
        foreach ($route['methods'] as $method) {
            $paths[$route['path']][strtolower($method)] = buildOperation($route, $method, $schemas);
        }
    }

    ksort($paths);

    $tags = $routes
        ->pluck('group')
        ->unique()
        ->values()
        ->map(fn (string $group): array => [
            'name' => $group,
            'description' => $group.' API',
        ])
        ->all();

    return [
        'openapi' => '3.1.0',
        'info' => [
            'title' => 'TuraIDC Backend API',
            'description' => '由 Laravel 路由表自动生成，仅记录真实路由、鉴权、中间件和统一响应外层；请求字段以 FormRequest/控制器实现为准。',
            'version' => date('Y-m-d-His'),
        ],
        'tags' => $tags,
        'paths' => $paths,
        'components' => [
            'schemas' => $schemas,
            'securitySchemes' => [
                'AdminBearerAuth' => [
                    'type' => 'http',
                    'scheme' => 'bearer',
                    'bearerFormat' => 'Sanctum Token',
                    'description' => '管理端 Sanctum Token，前端存储键 admin_token。',
                ],
                'ClientBearerAuth' => [
                    'type' => 'http',
                    'scheme' => 'bearer',
                    'bearerFormat' => 'Sanctum Token',
                    'description' => '用户端 Sanctum Token，前端存储键 client_token。',
                ],
            ],
        ],
        'servers' => [
            [
                'url' => 'http://127.0.0.1:8000',
                'description' => '本地开发',
            ],
        ],
    ];
}

function buildOperation(array $route, string $method, array &$schemas): array
{
    $requestInfo = buildRequestInfo($route, $method);
    $responseInfo = inferResponseInfo($route, $method, $schemas);
    $parameters = array_merge(
        buildPathParameters($route['path'], $requestInfo['fields']),
        $requestInfo['query_parameters']
    );

    $operation = [
        'tags' => [$route['group']],
        'summary' => operationSummary($route, $method),
        'description' => operationDescription($route, $requestInfo, $responseInfo),
        'operationId' => makeOperationId($method, $route['path']),
        'parameters' => $parameters,
        'responses' => buildResponses($route['auth'], $requestInfo, $responseInfo),
        'security' => buildSecurity($route['auth']),
        'x-apifox-folder' => $route['group'],
        'x-apifox-status' => 'released',
        'x-turaidc-controller-action' => $route['action'],
        'x-turaidc-middleware' => $route['middleware'],
    ];

    if ($route['permission'] !== '') {
        $operation['x-turaidc-permission'] = $route['permission'];
    }

    if ($requestInfo['request_body'] !== null) {
        $operation['requestBody'] = $requestInfo['request_body'];
    }

    return $operation;
}

function buildPathParameters(string $path, array $fieldMetas = []): array
{
    preg_match_all('/\{([^}]+)\}/', $path, $matches);
    $fieldsByName = [];
    foreach ($fieldMetas as $field) {
        $fieldsByName[$field['name']] = $field;
    }

    return array_map(
        function (string $name) use ($fieldsByName): array {
            $field = $fieldsByName[$name] ?? null;

            return [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => $field['schema'] ?? ['type' => inferSchemaTypeFromName($name)],
                'example' => $field['example'] ?? exampleForName($name, ['type' => inferSchemaTypeFromName($name)], []),
                'description' => $field['description'] ?? '路由路径参数',
            ];
        },
        $matches[1] ?? []
    );
}

function buildResponses(string $auth, array $requestInfo, array $responseInfo): array
{
    $responses = [
        '200' => [
            'description' => $responseInfo['description'],
            'content' => [
                $responseInfo['content_type'] => [
                    'schema' => $responseInfo['schema'],
                    'examples' => [
                        'success' => [
                            'summary' => '成功示例',
                            'value' => $responseInfo['example'],
                        ],
                    ],
                ],
            ],
        ],
        '422' => [
            'description' => '参数验证失败',
            'content' => [
                'application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/ValidationErrorResponse'],
                    'examples' => [
                        'validation_error' => [
                            'summary' => '参数错误示例',
                            'value' => validationErrorExample($requestInfo['fields']),
                        ],
                    ],
                ],
            ],
        ],
    ];

    if ($auth !== 'public') {
        $responses['401'] = [
            'description' => '未登录或登录已过期',
            'content' => [
                'application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/ApiResponse'],
                    'examples' => [
                        'unauthenticated' => [
                            'summary' => '未登录示例',
                            'value' => [
                                'code' => 40100,
                                'message' => '未登录或登录已过期',
                                'data' => null,
                                'timestamp' => 1760000000,
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $responses['403'] = [
            'description' => '无操作权限或前置条件不满足',
            'content' => [
                'application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/ApiResponse'],
                    'examples' => [
                        'forbidden' => [
                            'summary' => '无权限示例',
                            'value' => [
                                'code' => 40300,
                                'message' => '无操作权限',
                                'data' => null,
                                'timestamp' => 1760000000,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    return $responses;
}

function baseComponentSchemas(): array
{
    return [
        'ApiResponse' => [
            'type' => 'object',
            'properties' => [
                'code' => ['type' => 'integer', 'description' => '业务码，成功固定为 0'],
                'message' => ['type' => 'string', 'description' => '中文提示信息'],
                'data' => [
                    'description' => '业务数据，可为对象、数组、标量或 null',
                    'oneOf' => [
                        ['type' => 'object', 'additionalProperties' => true],
                        ['type' => 'array', 'items' => true],
                        ['type' => 'string'],
                        ['type' => 'integer'],
                        ['type' => 'number'],
                        ['type' => 'boolean'],
                        ['type' => 'null'],
                    ],
                ],
                'timestamp' => ['type' => 'integer', 'description' => 'Unix 秒级时间戳'],
            ],
            'required' => ['code', 'message', 'data', 'timestamp'],
        ],
        'PaginationResponseData' => [
            'type' => 'object',
            'properties' => [
                'list' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true], 'description' => '当前页数据'],
                'total' => ['type' => 'integer', 'description' => '总条数'],
                'page' => ['type' => 'integer', 'description' => '当前页码'],
                'page_size' => ['type' => 'integer', 'description' => '每页数量'],
            ],
            'required' => ['list', 'total', 'page', 'page_size'],
        ],
        'ValidationErrorResponse' => [
            'type' => 'object',
            'properties' => [
                'code' => ['type' => 'integer', 'const' => 42200],
                'message' => ['type' => 'string', 'const' => '参数验证失败'],
                'data' => [
                    'type' => 'object',
                    'properties' => [
                        'errors' => [
                            'type' => 'object',
                            'additionalProperties' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
                'timestamp' => ['type' => 'integer'],
            ],
            'required' => ['code', 'message', 'data', 'timestamp'],
        ],
    ];
}

function buildRequestInfo(array $route, string $method): array
{
    $fields = [];
    $errors = [];

    foreach ($route['request_classes'] as $class) {
        try {
            $rules = extractFormRequestRules($class, $route['route']);
            foreach ($rules as $name => $rule) {
                $fields[$name] = fieldMetaFromRules((string) $name, $rule, $class);
            }
        } catch (Throwable $e) {
            $rules = extractFormRequestRulesFromSource($class);
            if ($rules !== []) {
                foreach ($rules as $name => $rule) {
                    $fields[$name] = fieldMetaFromRules((string) $name, $rule, $class);
                }
                $errors[] = class_basename($class).': rules() 动态部分无法执行，已按源码静态字段导出。';

                continue;
            }

            $errors[] = class_basename($class).': '.$e->getMessage();
        }
    }

    $fields = array_values($fields);
    $pathNames = pathParameterNames($route['path']);
    $fieldFilter = fn (array $field): bool => ! in_array($field['name'], $pathNames, true);

    $queryParameters = [];
    $requestBody = null;

    if (in_array($method, ['GET', 'DELETE'], true)) {
        $queryParameters = buildQueryParameters(array_values(array_filter($fields, $fieldFilter)));
    } elseif ($fields !== []) {
        $requestBody = buildRequestBody(array_values(array_filter($fields, $fieldFilter)));
    }

    return [
        'classes' => $route['request_classes'],
        'fields' => $fields,
        'query_parameters' => $queryParameters,
        'request_body' => $requestBody,
        'errors' => $errors,
    ];
}

function extractFormRequestRules(string $class, Route $route): array
{
    /** @var FormRequest $request */
    $request = new $class;
    $request->setContainer(app());

    if (app()->bound('redirect')) {
        $request->setRedirector(app('redirect'));
    }

    $request->setRouteResolver(fn (): Route => $route);
    $request->setUserResolver(fn (): null => null);

    return method_exists($request, 'rules') ? $request->rules() : [];
}

function extractFormRequestRulesFromSource(string $class): array
{
    if (! class_exists($class) || ! method_exists($class, 'rules')) {
        return [];
    }

    try {
        $reflection = new ReflectionMethod($class, 'rules');
        $file = $reflection->getFileName();
        $lines = is_string($file) && is_file($file) ? file($file) : false;
        if ($lines === false) {
            return [];
        }

        $source = implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
        preg_match_all('/[\'"]([^\'"]+)[\'"]\s*=>\s*(.+?)(?:,\s*$|\n)/m', $source, $matches, PREG_SET_ORDER);
        $rules = [];

        foreach ($matches as $match) {
            $name = $match[1];
            if ($name === '' || str_contains($name, ' ')) {
                continue;
            }

            $rules[$name] = parseSourceRuleValue($match[2]);
        }

        return $rules;
    } catch (Throwable) {
        return [];
    }
}

function parseSourceRuleValue(string $value): array|string
{
    $value = trim($value);
    if (preg_match('/^[\'"]([^\'"]+)[\'"]$/', trim($value, ','), $match) === 1) {
        return $match[1];
    }

    preg_match_all('/[\'"]([^\'"]+)[\'"]/', $value, $matches);
    $parts = [];
    foreach ($matches[1] ?? [] as $part) {
        if (str_contains($part, ':') || in_array($part, ['required', 'nullable', 'sometimes', 'string', 'integer', 'numeric', 'boolean', 'array', 'file', 'image', 'email', 'url', 'date'], true)) {
            $parts[] = $part;
        }
    }

    return $parts;
}

function fieldMetaFromRules(string $name, mixed $rule, string $requestClass): array
{
    $parts = normalizeRuleParts($rule);
    $schema = schemaFromRules($name, $parts);
    $example = exampleForName($name, $schema, $parts);

    if ($example !== null) {
        $schema['example'] = $example;
    }

    return [
        'name' => $name,
        'request_class' => $requestClass,
        'required' => hasRequiredRule($parts),
        'rules' => $parts,
        'schema' => $schema,
        'example' => $example,
        'description' => describeFieldRules($parts),
    ];
}

function normalizeRuleParts(mixed $rule): array
{
    if (is_string($rule)) {
        return array_values(array_filter(explode('|', $rule), fn (string $part): bool => trim($part) !== ''));
    }

    if (! is_array($rule)) {
        return [stringifyRule($rule)];
    }

    $parts = [];
    foreach ($rule as $part) {
        if (is_string($part) && str_contains($part, '|')) {
            array_push($parts, ...array_values(array_filter(explode('|', $part))));

            continue;
        }

        $parts[] = stringifyRule($part);
    }

    return array_values(array_filter(array_map('trim', $parts), fn (string $part): bool => $part !== ''));
}

function stringifyRule(mixed $rule): string
{
    if (is_string($rule)) {
        return $rule;
    }

    if (is_object($rule) && method_exists($rule, '__toString')) {
        return (string) $rule;
    }

    if ($rule instanceof Closure) {
        return 'custom';
    }

    return is_object($rule) ? class_basename($rule) : (string) $rule;
}

function hasRequiredRule(array $parts): bool
{
    foreach ($parts as $part) {
        $name = strtolower(strtok($part, ':') ?: $part);
        if ($name === 'required' || str_starts_with($name, 'required_')) {
            return true;
        }
    }

    return false;
}

function schemaFromRules(string $name, array $parts): array
{
    $schema = ['type' => inferSchemaTypeFromName($name)];

    foreach ($parts as $part) {
        $rule = strtolower(strtok($part, ':') ?: $part);
        $value = str_contains($part, ':') ? substr($part, strpos($part, ':') + 1) : null;

        match ($rule) {
            'integer' => $schema['type'] = 'integer',
            'numeric', 'decimal' => $schema['type'] = 'number',
            'boolean' => $schema['type'] = 'boolean',
            'array' => $schema['type'] = 'array',
            'file', 'image', 'mimes', 'mimetypes' => $schema = ['type' => 'string', 'format' => 'binary'],
            'email' => $schema['format'] = 'email',
            'url' => $schema['format'] = 'uri',
            'date', 'date_format', 'before', 'after' => $schema['format'] = 'date-time',
            'nullable', 'sometimes' => $schema['nullable'] = true,
            default => null,
        };

        if ($rule === 'in' && $value !== null) {
            $schema['enum'] = array_values(array_filter(
                array_map(fn (string $item): string => trim(stripslashes($item), " \t\n\r\0\x0B\"'\\"), explode(',', $value)),
                fn (string $item): bool => $item !== ''
            ));
            if ($schema['enum'] !== [] && allStringsAreIntegers($schema['enum'])) {
                $schema['type'] = 'integer';
                $schema['enum'] = array_map('intval', $schema['enum']);
            }

            $schema['enum'] = array_values(array_unique($schema['enum']));
        }

        if ($value !== null && in_array($rule, ['min', 'max', 'size'], true)) {
            $numericValue = is_numeric($value) ? (float) $value : null;
            if ($numericValue === null) {
                continue;
            }

            if (($schema['type'] ?? '') === 'string') {
                $schema[$rule === 'min' ? 'minLength' : ($rule === 'max' ? 'maxLength' : 'minLength')] = (int) $numericValue;
                if ($rule === 'size') {
                    $schema['maxLength'] = (int) $numericValue;
                }
            } elseif (in_array($schema['type'] ?? '', ['integer', 'number'], true)) {
                $schema[$rule === 'min' ? 'minimum' : ($rule === 'max' ? 'maximum' : 'minimum')] = $numericValue;
                if ($rule === 'size') {
                    $schema['maximum'] = $numericValue;
                }
            }
        }
    }

    if (($schema['type'] ?? '') === 'array' && ! isset($schema['items'])) {
        $schema['items'] = ['type' => 'object', 'additionalProperties' => true];
    }

    return $schema;
}

function allStringsAreIntegers(array $values): bool
{
    foreach ($values as $value) {
        if (! preg_match('/^-?\d+$/', (string) $value)) {
            return false;
        }
    }

    return true;
}

function inferSchemaTypeFromName(string $name): string
{
    $last = strtolower((string) preg_replace('/^.*\./', '', $name));

    if ($last === 'id' || str_ends_with($last, '_id') || str_ends_with($last, '_count') || in_array($last, ['page', 'page_size', 'status', 'sort_order', 'quantity', 'stock'], true)) {
        return 'integer';
    }

    if (str_contains($last, 'amount') || str_contains($last, 'price') || str_contains($last, 'fee') || str_contains($last, 'rate')) {
        return 'number';
    }

    if (str_starts_with($last, 'is_') || str_starts_with($last, 'has_') || str_starts_with($last, 'enable') || str_starts_with($last, 'require_')) {
        return 'boolean';
    }

    return 'string';
}

function describeFieldRules(array $parts): string
{
    if ($parts === []) {
        return '当前字段未声明校验规则';
    }

    return 'Laravel 校验规则: '.implode(' | ', $parts);
}

function buildQueryParameters(array $fields): array
{
    return array_map(
        fn (array $field): array => [
            'name' => $field['name'],
            'in' => 'query',
            'required' => $field['required'],
            'schema' => $field['schema'],
            'example' => $field['example'],
            'description' => $field['description'],
        ],
        $fields
    );
}

function buildRequestBody(array $fields): array
{
    [$schema, $example] = buildSchemaAndExampleFromFields($fields);
    $contentType = requestBodyContentType($fields);

    return [
        'required' => hasAnyRequiredField($fields),
        'content' => [
            $contentType => [
                'schema' => $schema,
                'examples' => [
                    'default' => [
                        'summary' => '请求示例',
                        'value' => $example,
                    ],
                ],
            ],
        ],
    ];
}

function requestBodyContentType(array $fields): string
{
    foreach ($fields as $field) {
        if (($field['schema']['format'] ?? '') === 'binary') {
            return 'multipart/form-data';
        }
    }

    return 'application/json';
}

function hasAnyRequiredField(array $fields): bool
{
    foreach ($fields as $field) {
        if ($field['required']) {
            return true;
        }
    }

    return false;
}

function buildSchemaAndExampleFromFields(array $fields): array
{
    $schema = [
        'type' => 'object',
        'properties' => [],
    ];
    $example = [];

    foreach ($fields as $field) {
        addFieldToSchema($schema, $example, explode('.', $field['name']), $field);
    }

    cleanupSchema($schema);

    return [$schema, $example];
}

function addFieldToSchema(array &$schema, array &$example, array $segments, array $field): void
{
    $segment = array_shift($segments);
    if ($segment === null || $segment === '') {
        return;
    }

    if ($segment === '*') {
        $schema['type'] = 'array';
        $schema['items'] ??= ['type' => 'object', 'properties' => []];
        $itemExample = is_array($example[0] ?? null) ? $example[0] : [];

        if ($segments === []) {
            $schema['items'] = $field['schema'];
            $example = [$field['example']];

            return;
        }

        addFieldToSchema($schema['items'], $itemExample, $segments, $field);
        $example = [$itemExample];

        return;
    }

    $schema['type'] = 'object';
    unset($schema['items'], $schema['example']);
    $schema['properties'] ??= [];

    if ($segments === []) {
        $schema['properties'][$segment] = $field['schema'];
        $example[$segment] = $field['example'];

        if ($field['required']) {
            $schema['required'] ??= [];
            if (! in_array($segment, $schema['required'], true)) {
                $schema['required'][] = $segment;
            }
        }

        return;
    }

    $schema['properties'][$segment] ??= ['type' => 'object', 'properties' => []];
    $nextSegment = $segments[0] ?? null;
    if ($nextSegment === '*') {
        $schema['properties'][$segment]['type'] = 'array';
        $schema['properties'][$segment]['items'] ??= ['type' => 'object', 'properties' => []];
        unset($schema['properties'][$segment]['properties'], $schema['properties'][$segment]['example']);
    } else {
        $schema['properties'][$segment]['type'] = 'object';
        $schema['properties'][$segment]['properties'] ??= [];
        unset($schema['properties'][$segment]['items'], $schema['properties'][$segment]['example']);
    }

    $childExample = is_array($example[$segment] ?? null) ? $example[$segment] : [];
    addFieldToSchema($schema['properties'][$segment], $childExample, $segments, $field);
    $example[$segment] = $childExample;
}

function cleanupSchema(array &$schema): void
{
    if (($schema['type'] ?? '') === 'object') {
        if (($schema['properties'] ?? []) === []) {
            $schema['additionalProperties'] = true;
            unset($schema['properties']);
        } else {
            foreach ($schema['properties'] as &$property) {
                if (is_array($property)) {
                    cleanupSchema($property);
                }
            }
            unset($property);
        }
    }

    if (($schema['type'] ?? '') === 'array' && isset($schema['items']) && is_array($schema['items'])) {
        cleanupSchema($schema['items']);
    }
}

function exampleForName(string $name, array $schema, array $rules): mixed
{
    if (($schema['enum'] ?? []) !== []) {
        return $schema['enum'][0];
    }

    $last = strtolower((string) preg_replace('/^.*\./', '', $name));

    if (($schema['format'] ?? '') === 'binary') {
        return null;
    }

    return match (schemaType($schema, 'string')) {
        'integer' => integerExampleForName($last, $schema),
        'number' => numberExampleForSchema($schema),
        'boolean' => true,
        'array' => [],
        default => exampleStringForName($last, $rules),
    };
}

function schemaType(array $schema, string $default = 'object'): string
{
    $type = $schema['type'] ?? $default;
    if (is_array($type)) {
        foreach ($type as $candidate) {
            if (is_string($candidate) && $candidate !== 'null') {
                return $candidate;
            }
        }

        return 'null';
    }

    return is_string($type) ? $type : $default;
}

function integerExampleForName(string $name, array $schema): int
{
    if ($name === 'page_size') {
        return clampNumericExample(20, $schema, true);
    }

    return clampNumericExample(1, $schema, true);
}

function numberExampleForSchema(array $schema): int|float
{
    return clampNumericExample(100.00, $schema, false);
}

function clampNumericExample(int|float $default, array $schema, bool $integer): int|float
{
    $value = $default;

    if (isset($schema['minimum']) && is_numeric($schema['minimum']) && $value < (float) $schema['minimum']) {
        $value = (float) $schema['minimum'];
    }

    if (isset($schema['maximum']) && is_numeric($schema['maximum']) && $value > (float) $schema['maximum']) {
        $value = (float) $schema['maximum'];
    }

    return $integer ? (int) $value : $value;
}

function exampleStringForName(string $name, array $rules): string
{
    if (str_contains($name, 'email')) {
        return 'user@example.com';
    }

    if (str_contains($name, 'phone') || str_contains($name, 'mobile')) {
        return '13800138000';
    }

    if (str_contains($name, 'password')) {
        return 'Cheng2008li#7111';
    }

    if ($name === 'account') {
        return 'user@example.com';
    }

    if (str_contains($name, 'code')) {
        return '123456';
    }

    if (str_contains($name, 'token')) {
        return 'token_example';
    }

    if (str_contains($name, 'url')) {
        return 'https://example.com/callback';
    }

    if (str_contains($name, 'date') || str_contains($name, 'time')) {
        return '2026-07-05 12:00:00';
    }

    if ($name === 'gateway') {
        return 'alipay';
    }

    if ($name === 'cycle') {
        return 'monthly';
    }

    if ($name === 'direction') {
        return 'inbound';
    }

    if ($name === 'protocol') {
        return 'tcp';
    }

    if ($name === 'port') {
        return '22';
    }

    if ($name === 'ip') {
        return '0.0.0.0/0';
    }

    if (str_contains($name, 'title')) {
        return '示例标题';
    }

    if (str_contains($name, 'content')) {
        return '示例内容';
    }

    if (str_contains($name, 'name')) {
        return '示例名称';
    }

    if (str_contains($name, 'remark') || str_contains($name, 'description')) {
        return '示例备注';
    }

    return 'string';
}

function operationSummary(array $route, string $method): string
{
    $action = $route['controller_method'] !== '' ? $route['controller_method'] : strtolower($method);
    $target = trim((string) preg_replace('/^\/api\/(admin|client|site)\//', '', $route['path']), '/');
    $target = $target === '' ? trim($route['path'], '/') : $target;

    return sprintf('%s %s', actionVerbLabel($action, $method), $target);
}

function actionVerbLabel(string $action, string $method): string
{
    $action = strtolower($action);

    if (str_contains($action, 'index') || str_contains($action, 'list') || $method === 'GET') {
        return str_contains($action, 'show') || str_contains($action, 'detail') ? '查看' : '查询';
    }

    if (str_contains($action, 'store') || str_contains($action, 'create')) {
        return '创建';
    }

    if (str_contains($action, 'update') || $method === 'PUT' || $method === 'PATCH') {
        return '更新';
    }

    if (str_contains($action, 'delete') || str_contains($action, 'destroy') || $method === 'DELETE') {
        return '删除';
    }

    if (str_contains($action, 'login')) {
        return '登录';
    }

    if (str_contains($action, 'logout')) {
        return '退出登录';
    }

    return '执行';
}

function operationDescription(array $route, array $requestInfo, array $responseInfo): string
{
    $lines = [
        '### 接口说明',
        '- 路由: `'.implode('|', $route['methods']).' '.$route['path'].'`',
        '- 控制器动作: `'.$route['action'].'`',
        '- 鉴权: `'.$route['auth'].'`',
    ];

    if ($route['permission'] !== '') {
        $lines[] = '- 权限码: `'.$route['permission'].'`';
    }

    $lines[] = '- 中间件: `'.$route['middleware'].'`';
    $lines[] = '- 请求校验: '.($requestInfo['classes'] === [] ? '未声明 FormRequest' : '`'.implode('`, `', array_map('class_basename', $requestInfo['classes'])).'`');
    $lines[] = '- 返回格式: '.$responseInfo['note'];
    $lines[] = '';
    $lines[] = '### 响应约定';
    $lines[] = '- 成功业务码固定为 `0`。';
    $lines[] = '- JSON 接口统一返回 `code`、`message`、`data`、`timestamp`。';
    $lines[] = '- 参数校验失败返回 `42200`，字段错误位于 `data.errors`。';

    if ($requestInfo['errors'] !== []) {
        $lines[] = '';
        $lines[] = '### 自动抽取提示';
        foreach ($requestInfo['errors'] as $error) {
            $lines[] = '- '.$error;
        }
    }

    return implode("\n", $lines);
}

function inferResponseInfo(array $route, string $method, array &$schemas): array
{
    if ($route['path'] === '/api/secure-assets/view') {
        return [
            'content_type' => 'image/*',
            'description' => '图片文件流',
            'schema' => ['type' => 'string', 'format' => 'binary'],
            'example' => null,
            'note' => '文件流响应，不使用统一 JSON 外层。',
        ];
    }

    $source = getControllerMethodSource($route['controller'], $route['controller_method']);
    $dataSchema = inferSuccessDataSchema($route, $method, $source, $schemas);
    $example = apiResponseExample(successDataExample($route, $dataSchema), '操作成功');

    if ($route['path'] === '/api/health') {
        $dataSchema = [
            'type' => 'object',
            'properties' => [
                'version' => ['type' => 'string', 'example' => '1.0.0'],
            ],
        ];
        $example = [
            'code' => 0,
            'message' => 'ok',
            'data' => ['version' => '1.0.0'],
            'timestamp' => 1760000000,
        ];
    }

    return [
        'content_type' => 'application/json',
        'description' => '成功',
        'schema' => apiResponseSchema($dataSchema),
        'example' => $example,
        'note' => '统一 JSON 外层 `ApiResponse`。',
    ];
}

function inferSuccessDataSchema(array $route, string $method, string $source, array &$schemas): array
{
    $resourceClasses = extractResourceClassesFromSource($source, $route['controller']);
    $resourceSchema = null;
    if ($resourceClasses !== []) {
        $resourceSchema = resourceSchemaReference($resourceClasses[0], $schemas);
    }

    if (str_contains($source, 'paginate(') || (str_contains($source, "'list'") && str_contains($source, "'total'"))) {
        return [
            'type' => 'object',
            'properties' => [
                'list' => [
                    'type' => 'array',
                    'items' => $resourceSchema ?? ['type' => 'object', 'additionalProperties' => true],
                ],
                'total' => ['type' => 'integer'],
                'page' => ['type' => 'integer'],
                'page_size' => ['type' => 'integer'],
            ],
            'required' => ['list', 'total', 'page', 'page_size'],
        ];
    }

    if (str_contains($source, '::collection(')) {
        return [
            'type' => 'array',
            'items' => $resourceSchema ?? ['type' => 'object', 'additionalProperties' => true],
        ];
    }

    if ($resourceSchema !== null) {
        return $resourceSchema;
    }

    if ($method === 'DELETE' || preg_match('/@(destroy|logout|close|cancel)$/i', $route['action']) === 1) {
        return ['type' => 'null'];
    }

    return ['type' => 'object', 'additionalProperties' => true];
}

function apiResponseSchema(array $dataSchema): array
{
    return [
        'type' => 'object',
        'properties' => [
            'code' => ['type' => 'integer', 'const' => 0, 'description' => '业务码，成功固定为 0'],
            'message' => ['type' => 'string', 'description' => '中文提示信息'],
            'data' => $dataSchema + ['description' => '业务数据'],
            'timestamp' => ['type' => 'integer', 'description' => 'Unix 秒级时间戳'],
        ],
        'required' => ['code', 'message', 'data', 'timestamp'],
    ];
}

function successDataExample(array $route, array $dataSchema): mixed
{
    if (($dataSchema['type'] ?? '') === 'null') {
        return null;
    }

    if (($dataSchema['type'] ?? '') === 'array') {
        return [schemaExample($dataSchema['items'] ?? ['type' => 'object'])];
    }

    if (($dataSchema['properties']['list'] ?? null) !== null && isset($dataSchema['properties']['total'])) {
        return [
            'list' => [schemaExample($dataSchema['properties']['list']['items'] ?? ['type' => 'object'])],
            'total' => 1,
            'page' => 1,
            'page_size' => 20,
        ];
    }

    if (($dataSchema['type'] ?? '') === 'object') {
        return schemaExample($dataSchema);
    }

    return ['id' => 1];
}

function schemaExample(array $schema): mixed
{
    if (isset($schema['$ref'])) {
        return ['id' => 1, 'name' => '示例名称'];
    }

    if (($schema['example'] ?? null) !== null) {
        return $schema['example'];
    }

    return match (schemaType($schema)) {
        'integer' => clampNumericExample(1, $schema, true),
        'number' => clampNumericExample(100.00, $schema, false),
        'boolean' => true,
        'string' => 'string',
        'array' => [schemaExample($schema['items'] ?? ['type' => 'object'])],
        'null' => null,
        default => objectSchemaExample($schema),
    };
}

function objectSchemaExample(array $schema): array
{
    $properties = $schema['properties'] ?? [];
    if ($properties === []) {
        return ['id' => 1, 'name' => '示例名称'];
    }

    $example = [];
    foreach (array_slice($properties, 0, 12, true) as $name => $property) {
        $example[$name] = is_array($property) ? schemaExample($property) : null;
    }

    return $example;
}

function apiResponseExample(mixed $data, string $message): array
{
    return [
        'code' => 0,
        'message' => $message,
        'data' => $data,
        'timestamp' => 1760000000,
    ];
}

function validationErrorExample(array $fields): array
{
    $field = null;
    foreach ($fields as $candidate) {
        if ($candidate['required']) {
            $field = $candidate['name'];
            break;
        }
    }

    $field ??= $fields[0]['name'] ?? 'field';

    return [
        'code' => 42200,
        'message' => '参数验证失败',
        'data' => [
            'errors' => [
                $field => ['字段不能为空或格式不正确'],
            ],
        ],
        'timestamp' => 1760000000,
    ];
}

function getControllerMethodSource(string $controller, string $method): string
{
    if ($controller === '' || $method === '' || ! class_exists($controller) || ! method_exists($controller, $method)) {
        return '';
    }

    try {
        $reflection = new ReflectionMethod($controller, $method);
        $file = $reflection->getFileName();
        if (! is_string($file) || ! is_file($file)) {
            return '';
        }

        $lines = file($file);
        if ($lines === false) {
            return '';
        }

        return implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
    } catch (Throwable) {
        return '';
    }
}

function extractResourceClassesFromSource(string $source, string $controller): array
{
    if ($source === '' || $controller === '') {
        return [];
    }

    preg_match_all('/(?:new\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*Resource)|([A-Za-z_\\\\][A-Za-z0-9_\\\\]*Resource)::collection|([A-Za-z_\\\\][A-Za-z0-9_\\\\]*Resource)::class)/', $source, $matches);
    $candidates = array_values(array_unique(array_filter(array_merge($matches[1] ?? [], $matches[2] ?? [], $matches[3] ?? []))));
    $resolved = [];

    foreach ($candidates as $candidate) {
        $class = resolveClassNameFromController($candidate, $controller);
        if ($class !== '' && class_exists($class)) {
            $resolved[] = $class;
        }
    }

    return array_values(array_unique($resolved));
}

function resolveClassNameFromController(string $candidate, string $controller): string
{
    if (str_contains($candidate, '\\') && class_exists(ltrim($candidate, '\\'))) {
        return ltrim($candidate, '\\');
    }

    try {
        $reflection = new ReflectionClass($controller);
        $file = $reflection->getFileName();
        $source = is_string($file) && is_file($file) ? (string) file_get_contents($file) : '';
        preg_match_all('/^use\s+([^;]+);/m', $source, $matches);
        foreach ($matches[1] ?? [] as $use) {
            $use = trim($use);
            $alias = preg_match('/\s+as\s+([A-Za-z_][A-Za-z0-9_]*)$/i', $use, $aliasMatch) === 1
                ? $aliasMatch[1]
                : class_basename($use);

            if ($alias === $candidate) {
                return preg_replace('/\s+as\s+[A-Za-z_][A-Za-z0-9_]*$/i', '', $use) ?? $use;
            }
        }

        $namespace = $reflection->getNamespaceName();
        $sameNamespace = $namespace.'\\'.$candidate;
        if (class_exists($sameNamespace)) {
            return $sameNamespace;
        }
    } catch (Throwable) {
        return '';
    }

    return class_exists($candidate) ? $candidate : '';
}

function resourceSchemaReference(string $class, array &$schemas): array
{
    $name = class_basename($class);
    if (! isset($schemas[$name])) {
        $schemas[$name] = schemaFromResourceClass($class);
    }

    return ['$ref' => '#/components/schemas/'.$name];
}

function schemaFromResourceClass(string $class): array
{
    $properties = [];

    try {
        $reflection = new ReflectionMethod($class, 'toArray');
        $file = $reflection->getFileName();
        $lines = is_string($file) && is_file($file) ? file($file) : false;
        $source = $lines === false ? '' : implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
        preg_match_all('/[\'"]([A-Za-z0-9_]+)[\'"]\s*=>\s*([^,\n]+)/', $source, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $field = $match[1];
            if (isset($properties[$field])) {
                continue;
            }

            $properties[$field] = schemaFromResourceExpression($field, $match[2]);
        }
    } catch (Throwable) {
        // Keep the generated resource schema usable even when a resource is too dynamic to parse.
    }

    return [
        'type' => 'object',
        'properties' => $properties !== [] ? $properties : [
            'id' => ['type' => 'integer', 'description' => '资源 ID'],
        ],
        'additionalProperties' => $properties === [],
    ];
}

function schemaFromResourceExpression(string $field, string $expression): array
{
    $expression = trim($expression);

    if (str_starts_with($expression, '[')) {
        return ['type' => 'object', 'additionalProperties' => true, 'description' => '来自 Resource 嵌套对象'];
    }

    if (str_contains($expression, '::collection(')) {
        return ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]];
    }

    if (str_contains($expression, '(int)') || preg_match('/\bint\)/', $expression) === 1) {
        return ['type' => 'integer'];
    }

    if (str_contains($expression, '(bool)') || str_contains($expression, 'true') || str_contains($expression, 'false')) {
        return ['type' => 'boolean'];
    }

    if (str_contains($expression, '(array)') || str_ends_with($field, 's') && str_contains($expression, '?? []')) {
        return ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]];
    }

    if (str_contains($expression, 'number_format')) {
        return ['type' => 'string', 'description' => '金额字符串'];
    }

    if (str_contains($expression, 'format(') || str_ends_with($field, '_at')) {
        return ['type' => 'string', 'format' => 'date-time'];
    }

    return ['type' => inferSchemaTypeFromName($field)];
}

function buildSecurity(string $auth): array
{
    return match ($auth) {
        'admin' => [['AdminBearerAuth' => []]],
        'client' => [['ClientBearerAuth' => []]],
        'auth' => [['AdminBearerAuth' => []], ['ClientBearerAuth' => []]],
        default => [],
    };
}

function pathParameterNames(string $path): array
{
    preg_match_all('/\{([^}]+)\}/', $path, $matches);

    return $matches[1] ?? [];
}

function resolveGroup(string $uri): string
{
    $adminGroups = [
        '/api/v2/admin/login' => '管理端 / 认证',
        '/api/v2/admin/auth' => '管理端 / 认证',
        '/api/v2/admin/dashboard' => '管理端 / 仪表盘',
        '/api/v2/admin/users' => '管理端 / 用户',
        '/api/v2/admin/invoices' => '管理端 / 账单',
        '/api/v2/admin/orders' => '管理端 / 订单',
        '/api/v2/admin/services' => '管理端 / 服务',
        '/api/v2/admin/os-options' => '管理端 / 服务',
        '/api/v2/admin/suppliers' => '管理端 / 供应商',
        '/api/v2/admin/products' => '管理端 / 产品',
        '/api/v2/admin/product-groups' => '管理端 / 产品分组',
        '/api/v2/admin/product-types' => '管理端 / 产品类型',
        '/api/v2/admin/coupons' => '管理端 / 优惠券',
        '/api/v2/admin/coupon-campaigns' => '管理端 / 优惠券',
        '/api/v2/admin/coupon-product-groups' => '管理端 / 优惠券',
        '/api/v2/admin/content' => '管理端 / 内容',
        '/api/v2/admin/media-files' => '管理端 / 媒体',
        '/api/v2/admin/media-file-reindexes' => '管理端 / 媒体',
        '/api/v2/admin/tickets' => '管理端 / 工单',
        '/api/v2/admin/verifications' => '管理端 / 实名认证',
        '/api/v2/admin/integration-plugins' => '管理端 / Integration Plugins',
        '/api/v2/admin/integration-plugin-scans' => '管理端 / Integration Plugins',
        '/api/v2/admin/finance' => '管理端 / 财务',
        '/api/v2/admin/referral-withdrawals' => '管理端 / 分销',
        '/api/v2/admin/referral' => '管理端 / 分销',
        '/api/v2/admin/roles' => '管理端 / 角色权限',
        '/api/v2/admin/permissions' => '管理端 / 角色权限',
        '/api/v2/admin/staff' => '管理端 / 员工',
        '/api/v2/admin/settings' => '管理端 / 设置',
        '/api/v2/admin/notification-templates' => '管理端 / 设置',
        '/api/v2/admin/site' => '管理端 / 站点',
        '/api/v2/admin/log-cleanups' => '管理端 / 日志',
        '/api/v2/admin/log-summaries' => '管理端 / 日志',
        '/api/v2/admin/logs' => '管理端 / 日志',
        '/api/v2/admin/member-levels' => '管理端 / 会员等级',
        '/api/v2/admin/cpu-model-catalog' => '管理端 / 规格目录',
        '/api/v2/admin/instance-spec-catalog' => '管理端 / 规格目录',
        '/api/v2/admin/schedules' => '管理端 / 调度',
        '/api/v2/admin/schedule-triggers' => '管理端 / 调度',
    ];

    $clientGroups = [
        '/api/v2/client/auth' => '客户端 / 认证',
        '/api/v2/client/login' => '客户端 / 认证入口',
        '/api/v2/client/register' => '客户端 / 认证入口',
        '/api/v2/client/password' => '客户端 / 认证入口',
        '/api/v2/client/services' => '客户端 / 服务',
        '/api/v2/client/vnc-tokens' => '客户端 / VNC Token',
        '/api/v2/client/invoices' => '客户端 / 账单',
        '/api/v2/client/orders' => '客户端 / 订单',
        '/api/v2/client/tickets' => '客户端 / 工单',
        '/api/v2/client/verification' => '客户端 / 实名认证',
        '/api/v2/client/content' => '客户端 / 内容',
        '/api/v2/client/notices' => '客户端 / 内容',
        '/api/v2/client/help-articles' => '客户端 / 内容',
        '/api/v2/client/notifications' => '客户端 / 通知',
        '/api/v2/client/coupons' => '客户端 / 优惠券',
        '/api/v2/client/referral' => '客户端 / 分销',
        '/api/v2/client/finance' => '客户端 / 财务',
        '/api/v2/client/ledger' => '客户端 / 财务',
        '/api/v2/client/balance-logs' => '客户端 / 财务',
        '/api/v2/client/recharge' => '客户端 / 充值',
        '/api/v2/client/payments' => '客户端 / 支付记录',
        '/api/v2/client/payment' => '客户端 / 支付回调',
    ];

    $siteGroups = [
        '/api/v2/site/config' => '站点 / 首页',
        '/api/v2/site/home' => '站点 / 首页',
        '/api/v2/site/home-hero' => '站点 / 首页',
        '/api/v2/site/content' => '站点 / 内容',
        '/api/v2/site/notices' => '站点 / 内容',
        '/api/v2/site/help-articles' => '站点 / 内容',
        '/api/v2/site/products' => '站点 / 产品',
        '/api/v2/site/product-groups' => '站点 / 产品',
        '/api/v2/site/product-types' => '站点 / 产品',
        '/api/v2/site/product-purchase-context' => '站点 / 产品',
        '/api/health' => '公共 / 健康检查',
        '/api/secure-assets/view' => '公共 / 安全资源',
    ];

    return matchApiGroup($uri, $adminGroups)
        ?? matchApiGroup($uri, $clientGroups)
        ?? matchApiGroup($uri, $siteGroups)
        ?? match (true) {
            str_starts_with($uri, '/api/v2/admin/') => '管理端 / 其他',
            str_starts_with($uri, '/api/v2/client/') => '客户端 / 其他',
            str_starts_with($uri, '/api/v2/site/') => '站点 / 其他',
            default => '公共 / 其他',
        };
}

function matchApiGroup(string $uri, array $groups): ?string
{
    foreach ($groups as $prefix => $group) {
        if ($uri === $prefix || str_starts_with($uri, $prefix.'/')) {
            return $group;
        }
    }

    return null;
}

function resolveAction(Route $route): string
{
    $action = $route->getActionName();

    return $action === 'Closure' ? 'Closure' : $action;
}

function resolveActionParts(string $action): array
{
    if ($action === 'Closure' || ! str_contains($action, '@')) {
        return ['controller' => '', 'method' => ''];
    }

    [$controller, $method] = explode('@', $action, 2);

    return [
        'controller' => $controller,
        'method' => $method,
    ];
}

function resolveFormRequestClasses(string $controller, string $method): array
{
    if ($controller === '' || $method === '' || ! class_exists($controller) || ! method_exists($controller, $method)) {
        return [];
    }

    try {
        $reflection = new ReflectionMethod($controller, $method);
    } catch (Throwable) {
        return [];
    }

    $classes = [];
    foreach ($reflection->getParameters() as $parameter) {
        $type = $parameter->getType();
        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            continue;
        }

        $class = $type->getName();
        if (is_subclass_of($class, FormRequest::class)) {
            $classes[] = $class;
        }
    }

    return array_values(array_unique($classes));
}

function resolveAuth(array $middleware): string
{
    $middlewareText = implode(',', $middleware);

    if (str_contains($middlewareText, 'ensure.admin')) {
        return 'admin';
    }

    if (str_contains($middlewareText, 'ensure.client')) {
        return 'client';
    }

    if (str_contains($middlewareText, 'auth:sanctum')) {
        return 'auth';
    }

    return 'public';
}

function resolvePermission(array $middleware): string
{
    foreach ($middleware as $item) {
        if (str_starts_with($item, 'permission:')) {
            return substr($item, strlen('permission:'));
        }
    }

    return '';
}

function makeOperationId(string $method, string $path): string
{
    $id = strtolower($method).'_'.trim($path, '/');
    $id = preg_replace('/\{([^}]+)\}/', 'by_$1', $id) ?? $id;
    $id = preg_replace('/[^A-Za-z0-9_]+/', '_', $id) ?? $id;

    return trim($id, '_');
}

function countOpenApiOperations(array $openApi): int
{
    $count = 0;

    foreach ($openApi['paths'] as $path) {
        foreach (array_keys($path) as $method) {
            if (in_array($method, ['get', 'post', 'put', 'patch', 'delete', 'options'], true)) {
                $count++;
            }
        }
    }

    return $count;
}

function parseCliOptions(array $argv): array
{
    $options = [];

    foreach (array_slice($argv, 1) as $arg) {
        if (! str_starts_with($arg, '--')) {
            continue;
        }

        $arg = substr($arg, 2);
        [$key, $value] = array_pad(explode('=', $arg, 2), 2, true);
        $options[$key] = $value;
    }

    return $options;
}

function defaultCodexConfigPath(): string
{
    $home = getenv('USERPROFILE') ?: getenv('HOME') ?: '';

    return $home === '' ? '' : rtrim($home, '\\/').'/.codex/config.toml';
}

function readApifoxMcpConfig(string $path, string $serverName): array
{
    if ($path === '' || ! is_file($path)) {
        return ['', ''];
    }

    $content = file_get_contents($path);
    if ($content === false) {
        return ['', ''];
    }

    $serverPattern = '/\[mcp_servers\.'.preg_quote($serverName, '/').'\]([\s\S]*?)(?=\n\[|$)/';
    preg_match($serverPattern, $content, $serverMatch);
    $serverBlock = $serverMatch[1] ?? '';
    preg_match('/--project(?:-id)?=(\d+)/', $serverBlock, $projectMatch);

    $envPattern = '/\[mcp_servers\.'.preg_quote($serverName, '/').'\.env\]([\s\S]*?)(?=\n\[|$)/';
    preg_match($envPattern, $content, $envMatch);
    $envBlock = $envMatch[1] ?? '';
    preg_match('/APIFOX_ACCESS_TOKEN\s*=\s*[\'"]([^\'"]+)[\'"]/', $envBlock, $tokenMatch);

    return [$projectMatch[1] ?? '', $tokenMatch[1] ?? ''];
}

function importWithLaravelHttp(string $url, string $token, array $payload): array
{
    $response = Http::withToken($token)
        ->withHeaders([
            'X-Apifox-Api-Version' => '2024-03-28',
            'Accept' => 'application/json',
        ])
        ->timeout(180)
        ->post($url, $payload);

    return [$response->status(), $response->body()];
}

function importWithCurlCli(string $url, string $token, array $payload): array
{
    $payloadFile = tempnam(sys_get_temp_dir(), 'apifox_payload_');

    if ($payloadFile === false) {
        throw new RuntimeException('无法创建临时 payload 文件');
    }

    file_put_contents($payloadFile, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $curlConfig = implode("\n", [
        'silent',
        'show-error',
        'location',
        'request = "POST"',
        'url = "'.escapeCurlConfig($url).'"',
        'header = "Authorization: Bearer '.escapeCurlConfig($token).'"',
        'header = "X-Apifox-Api-Version: 2024-03-28"',
        'header = "Accept: application/json"',
        'header = "Content-Type: application/json"',
        'data-binary = "@'.escapeCurlConfig(str_replace('\\', '/', $payloadFile)).'"',
        'write-out = "\n__HTTP_STATUS__:%{http_code}"',
        '',
    ]);

    $process = proc_open(
        'curl.exe --config -',
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );

    if (! is_resource($process)) {
        @unlink($payloadFile);
        throw new RuntimeException('无法启动 curl.exe');
    }

    fwrite($pipes[0], $curlConfig);
    fclose($pipes[0]);

    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    @unlink($payloadFile);

    if ($exitCode !== 0) {
        throw new RuntimeException('curl.exe 调用失败: '.maskSensitiveText((string) $error));
    }

    $output = (string) $output;
    if (! preg_match('/\n__HTTP_STATUS__:(\d{3})\s*$/', $output, $matches)) {
        throw new RuntimeException('无法解析 curl.exe HTTP 状态: '.maskSensitiveText($output));
    }

    $body = preg_replace('/\n__HTTP_STATUS__:\d{3}\s*$/', '', $output) ?? '';

    return [(int) $matches[1], $body];
}

function escapeCurlConfig(string $value): string
{
    return str_replace(['\\', '"'], ['\\\\', '\"'], $value);
}

function maskSensitiveText(string $text): string
{
    return preg_replace('/Bearer\s+[A-Za-z0-9._-]+/i', 'Bearer <redacted>', $text) ?? $text;
}
