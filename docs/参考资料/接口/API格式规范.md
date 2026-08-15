# API 格式规范

- 文档性质：现行方向
- 对齐时间：`2026-07-20`
- 读者画像：后端开发、前端联调、接口测试、文档维护人员
- 适用范围：`backend` 下所有返回 JSON 的 HTTP API，主要覆盖 `/api/v2/admin/*`、`/api/v2/client/*`、`/api/v2/site/*` 及少量独立公共接口。

## 1. 文档目标

本文定义本仓库后端 API 的统一请求处理、统一返回格式、错误码区间、分页结构、校验失败结构和新增接口的落地约束。

发生冲突时按以下顺序处理：

1. 当前代码真实行为、真实路由、受控验证结果
2. `AGENTS.md`
3. `docs/设计文档/后端/API直接重构方案.md`
4. 本文档
5. 旧接口习惯或历史蓝图

注意：当前开发文档、开发规范和其他约束性文档不具备完全真实性。API 治理必须以当前代码检查为主，发现本文档与当前代码冲突时，先记录冲突，再按检查结论更新本文档或相关实现。

接口审查时，历史旧接口快照仅作为审查证据；当前目标结构以 `API直接重构方案.md`、本文和自动生成的 `后端API清单.md` 为准。

### 1.1 直接重构边界

API 直接重构已完成，后续遵循以下 V2 边界：

- 旧接口：`/api/admin/*`、`/api/client/*`、`/api/site/*` 已删除，不保留结构、字段或参数兼容层。
- 当前接口：统一落在 `/api/v2/admin/*`、`/api/v2/client/*`、`/api/v2/site/*`，按本文和 `API直接重构方案.md` 一次性采用新规范。
- 前端调用：按页面或业务域完整消费 V2 接口，不混用历史结构。
- 文档维护：路由、请求、响应、测试和字段边界以 V2 接口为准。

## 2. 统一响应外层

除文件流、受控资源查看等非 JSON 响应外，API 统一返回以下外层结构：

```json
{
  "code": 0,
  "message": "操作成功",
  "data": {},
  "timestamp": 1760000000
}
```

字段说明：

- `code`
  - 业务码
  - 成功固定为 `0`
  - 失败为非 `0` 整数
- `message`
  - 给前端和联调使用的可读提示
  - 必须使用简体中文，不得返回英文、拼音、繁体中文或第三方原始错误文案
- `data`
  - 业务数据
  - 可为对象、数组、标量或 `null`
- `timestamp`
  - Unix 秒级时间戳

当前实现来源：

- `backend/app/Support/ApiResponseBuilder.php`
- `backend/app/Traits/ApiResponse.php`

## 3. 成功响应规范

### 3.1 普通成功

```json
{
  "code": 0,
  "message": "操作成功",
  "data": {
    "id": 123,
    "name": "示例"
  },
  "timestamp": 1760000000
}
```

### 3.2 无返回数据

用于删除、退出、纯状态切换等操作：

```json
{
  "code": 0,
  "message": "已退出登录",
  "data": null,
  "timestamp": 1760000000
}
```

### 3.3 列表/分页成功

标准分页结构放在 `data` 内：

```json
{
  "code": 0,
  "message": "操作成功",
  "data": {
    "list": [],
    "total": 0,
    "page": 1,
    "page_size": 20
  },
  "timestamp": 1760000000
}
```

分页字段含义：

- `list`: 当前页数据
- `total`: 总条数
- `page`: 当前页码
- `page_size`: 当前每页数量

当前标准分页由 `ApiResponseBuilder::pagination(...)` 生成。

## 4. 失败响应规范

失败时外层结构不变，只是 `code != 0`。

```json
{
  "code": 40400,
  "message": "商品不存在或已下架",
  "data": null,
  "timestamp": 1760000000
}
```

### 4.1 业务码到 HTTP 状态码映射

默认映射规则：

| 业务码区间 | 默认 HTTP 状态 |
| --- | --- |
| `50000+` | `500` |
| `42200+` | `422` |
| `40900+` | `409` |
| `40400+` | `404` |
| `40300+` | `403` |
| `40100+` | `401` |
| `40000+` | `400` |

说明：

- 当前映射逻辑位于 `ApiResponseBuilder::httpStatusForErrorCode(...)`
- 个别接口可显式覆盖 HTTP 状态码，例如回调验签失败当前返回 `code=40001`，HTTP 状态为 `401`

### 4.2 当前常见业务码

| 业务码 | 语义 | 当前常见来源 |
| --- | --- | --- |
| `0` | 成功 | 全局统一 |
| `40001` | 签名验证失败 | `VerifyCallbackSignature` |
| `40100` | 未登录、未认证或登录已过期 | 认证异常、权限中间件 |
| `40300` | 无权限、角色不匹配、账号被禁用 | `ensure.admin` / `ensure.client` / `permission` |
| `40301` | 邮箱验证或实名认证前置条件未满足 | `EnsureEmailIsVerified` |
| `40400` | 资源不存在 | 控制器内资源查询失败 |
| `42200` | 参数验证失败或一般业务校验失败 | `ValidationException`、`BusinessException` |
| `50000` | 通用服务端错误 | 未分类服务异常 |

## 5. 参数校验失败格式

Laravel `ValidationException` 已统一改写为 JSON：

```json
{
  "code": 42200,
  "message": "参数验证失败",
  "data": {
    "errors": {
      "email": [
        "邮箱不能为空"
      ],
      "page_size": [
        "page size 必须大于 0"
      ]
    }
  },
  "timestamp": 1760000000
}
```

约定：

- HTTP 状态码固定 `422`
- 详细字段错误放在 `data.errors`
- `errors` 的值保持 Laravel 原生数组结构，前端不要假设只有一条消息

当前实现来源：`backend/bootstrap/app.php`

## 6. 鉴权与权限相关格式

### 6.1 Token 认证

管理端与用户端都通过 Header 传 Bearer Token：

```http
Authorization: Bearer <token>
Accept: application/json
```

### 6.2 认证失败

示例：

```json
{
  "code": 40100,
  "message": "未登录或登录已过期",
  "data": null,
  "timestamp": 1760000000
}
```

### 6.3 权限不足/角色不匹配

示例：

```json
{
  "code": 40300,
  "message": "无操作权限",
  "data": null,
  "timestamp": 1760000000
}
```

### 6.4 前置条件不满足

例如邮箱未验证或未完成实名认证：

```json
{
  "code": 40301,
  "message": "请先完成实名认证",
  "data": null,
  "timestamp": 1760000000
}
```

## 7. 分页与查询参数规范

### 7.1 标准分页参数

新增接口统一使用：

- `page`
- `page_size`

示例：

```http
GET /api/v2/admin/products?page=1&page_size=20
```

### 7.2 历史参数边界

历史旧接口曾存在 `per_page` 等兼容参数；当前 V2 路由不再接受或返回这些字段。

规则：

- V2 接口禁止接受 `per_page`、`pageSize`、`limit/offset` 作为标准分页参数，除非该接口不是分页列表而是明确的业务限制参数，例如监控点数量上限。
- V2 分页返回结构只能使用 `page_size`，不得额外返回 `per_page`。
- 前端请求统一使用 `page`、`page_size`。

## 8. 字段与命名规范

### 8.1 通用命名

- URL 路径：小写、按资源语义命名
- 请求字段：`snake_case`
- 响应字段：`snake_case`
- 表名、字段名、筛选参数名保持一致优先

### 8.2 布尔参数

如果控制器通过 `booleanQuery(...)` 读取 query 参数，应接受常见布尔表达：

- `1` / `0`
- `true` / `false`
- `on` / `off`
- `yes` / `no`

非法布尔值应抛出校验异常，统一进入 `42200`

## 9. 文件上传与非 JSON 例外

### 9.1 文件上传

上传类接口请求体允许使用 `multipart/form-data`，但响应仍应回到统一 JSON 外层。

### 9.2 非 JSON 例外

以下接口可不走统一 JSON 外层：

- 直接文件流响应
- 受控资源预览
- WebSocket

当前典型例外：

- `/api/secure-assets/view`

除这些例外，普通 HTTP API 不应返回裸数组、裸字符串或自定义包裹结构。

## 10. API 请求工程化规范

API 请求处理必须工程化，避免 Controller 里散落参数读取、类型转换、默认值、权限上下文和业务前置判断。

### 10.1 请求入口

- 新增写操作、复杂查询、批量操作、导入导出、支付/账单/实名/上游相关接口必须使用 `FormRequest`。
- 简单只读详情接口可以使用路由参数，但资源归属、权限和业务可见性必须交给 Service 或 Policy/权限中间件处理。
- Controller 不应直接调用 `$request->validate(...)`，除非是极小范围兼容旧接口，并在后续治理中迁移到 FormRequest。
- Controller 不应手写大量 `$request->input(...)`、`$request->query(...)` 后再拼业务数组；应优先使用 `$request->validated()`。

### 10.2 请求字段

- 请求字段统一使用 `snake_case`。
- 布尔、枚举、金额、日期范围、分页参数必须在 FormRequest 中完成校验和归一化。
- 金额字段必须明确单位；涉及财务、余额、支付时优先使用整数分或字符串 decimal，不允许隐式 float。
- 日期时间字段必须明确格式和时区，接口文档中需说明。
- 批量 ID 使用数组，例如 `ids: [1, 2, 3]`，禁止逗号字符串作为新增接口默认格式。

### 10.3 查询参数归一化

- 列表接口统一使用 `page`、`page_size`。
- 排序字段必须白名单映射，禁止直接把用户输入传给 `orderBy` / `orderByRaw`。
- 筛选字段必须有明确类型，避免空字符串、`null`、`0`、`false` 在业务层语义混乱。
- 新接口不做旧参数兼容；旧接口如已有兼容逻辑，保持原实现边界，不在重构中扩散到 v2。

### 10.4 请求 DTO 与上下文

当接口参数会跨多个 Service 传递，或涉及支付、实名、第三方上游、批量操作时，推荐引入请求 DTO 或命令对象：

```php
final class CreatePaymentCommand
{
    public function __construct(
        public readonly int $invoiceId,
        public readonly string $gateway,
        public readonly int $amountCents,
        public readonly int $actorId,
        public readonly string $traceId,
    ) {}
}
```

约束：

- DTO 字段使用业务语义命名，不照搬第三方字段。
- DTO 不直接读取 Request。
- DTO 不包含 secret、token、私钥等敏感值。
- `actor_id`、`trace_id`、`ip_address`、`operator_*` 等上下文由 Controller 或中间件收集后传入 Service，不在深层 Service 里重复读取全局请求。

## 11. API 响应工程化规范

API 响应必须由统一响应构造器、Resource 或明确 DTO 转换，避免 Controller、Service、第三方驱动各自拼 JSON。

### 11.1 响应来源

- Controller 返回 `$this->success(...)`、`$this->paginate(...)`、`$this->error(...)` 或抛出统一业务异常。
- 业务 Service 返回领域对象、DTO、数组或分页器，不直接返回 HTTP Response。
- 第三方 Provider 返回内部 DTO 或统一异常，不把第三方原始响应直接传给 Controller 或前端。
- Model 不应直接作为复杂接口响应暴露，优先通过 Resource 控制字段。

### 11.2 Resource 与字段治理

- 面向前端的字段由 Resource 或专用 Response DTO 统一转换。
- 响应字段统一 `snake_case`。
- 敏感字段默认不返回，例如 password、token、secret、身份证完整号、私钥、第三方原始签名。
- 状态字段必须返回稳定 code；展示文案可由前端映射或后端明确输出，但不能散落魔法字符串。
- 涉及金额时必须明确单位，例如 `amount_cents`、`amount` + 文档说明，不允许前端猜测。

### 11.2.1 敏感字段与凭据输出

- 设置类接口遇到敏感 key 时，响应 `value` 必须为空字符串或安全占位，不返回原始值。
- 设置类接口可返回 `has_value` 和 `masked_value`，但 `masked_value` 必须是真掩码，例如 `******`，不得等于原始值。
- 敏感配置编辑遵循“空值保留、非空替换”：提交空字符串表示不修改旧密钥；提交非空值才替换并按模型规则加密保存。
- 供应商接口不得在列表、详情、创建、更新响应中返回 `api_key` 原值。
- 供应商凭据只允许返回 `has_api_key`、必要掩码或存在状态，字段必须由 Resource 或控制器白名单显式控制。
- 第三方原始错误只能进入脱敏日志或内部排查上下文，不得作为 API `message` 或前端提示直接展示。
- `payments` 表记录属于真实资金流或财务审计链路，维护命令、清理命令、API 和测试均不得物理删除 Payment 记录。
- 公开 VNC token 接口不得返回 VNC `password`，公开 token 必须一次性消费，后续 relay token 只能在受控短 TTL 内使用。
- 用户端账单详情必须使用独立 Resource/DTO 白名单，不得复用管理端详情，不得返回 `raw_status` 等内部审计字段；用户本人相关支付记录可返回 `payment_no`（系统/商家订单号）与 `trade_no`（第三方支付订单号）用于对账展示。
- 余额恢复、混合支付撤销、退款等财务反转不得删除原始流水，必须用反向流水、状态投影或审计记录保留完整链路。

### 11.3 错误响应

- 业务异常统一映射为 `code != 0` 的响应。
- 第三方异常必须转换成项目内部错误码和简体中文 `message`，不直接暴露供应商原始错误。
- 表单校验错误统一使用 `42200` 和 `data.errors`。
- 鉴权、权限、前置条件必须区分 `40100`、`40300`、`40301`。
- 不允许接口一部分返回 `code=0` 表示失败，另一部分用 HTTP 500 表示失败。

### 11.4 文案语言规范

- 所有 API 返回的 `message` 必须为简体中文。
- `data.errors` 中面向前端展示的校验错误必须为简体中文。
- 第三方供应商返回的英文、繁体中文、错误码、原始异常信息，必须在后端映射为项目内部简体中文提示。
- 日志可以保留第三方原始错误摘要，但 API 响应不得直接暴露第三方原文。
- 后端内部异常类、变量名、枚举名可以使用英文；用户可见响应文案必须使用简体中文。
- 自动化测试应优先断言业务码和关键字段；涉及用户展示文案时，应断言为简体中文。
- 前端错误提示统一走各端请求层或 `toUserMessage(error, fallback)` 等可信中文兜底，不得直接展示 axios/browser/第三方 `error.message`。
- 无业务明确要求时，管理端和用户端不开放英文自动切换入口；403、404、500、登录、表单、通知、空状态、加载态和操作结果提示均使用简体中文。

### 11.5 兼容与版本边界

- 旧接口冻结，不得为了 v2 重构删除、重命名或新增结构性字段。
- v2 新接口不继承旧接口的冗余字段、历史兼容字段和列表详情混用结构。
- 必须变更旧接口行为时，应在报告或需求中说明影响面，并同步前端调用、接口文档和测试；这类变更只允许用于安全漏洞或明确 bug。
- 历史兼容字段只保留在旧接口内，不进入 v2 接口。
- 同一业务对象在管理端、用户端、站点端可有不同 Resource，但核心状态 code 和金额单位必须一致。

### 11.5.1 大响应与字段边界

新接口必须主动控制响应体积：

- 单次 JSON 响应目标上限为 `100KB`。
- 公开站点首屏接口目标上限为 `50KB`。
- 管理端树选择器、目录选择器首包目标上限为 `70KB`。
- 列表 Resource 不返回详情字段、完整长文本、完整配置 schema、完整关联树、完整日志上下文或第三方原始响应。
- 大文本列表字段返回 `excerpt`，完整 `content`、`body`、`html` 只在详情接口返回。
- 图片、二维码、附件、视频返回 `url` 或 `asset_id`，禁止把 base64 放入普通 JSON 响应。
- 嵌套层级默认不超过 3 层；超过 3 层必须拆为子资源、分页或独立详情接口。

### 11.6 第三方错误与回调

- 支付、实名、短信、上游、邮件、对象存储等第三方错误统一经过 `ProviderErrorMapper` 或等价映射层转换为简体中文业务提示。
- 第三方原始 `msg/message/error` 仅允许写入脱敏日志，不能进入 API `message`、`data.errors` 或前端提示。
- 回调入口必须先通过签名中间件；涉及自研回调时优先使用 HMAC-SHA256、timestamp、nonce 和 replay cache。
- 业务 Service 层仍需保留幂等、金额/状态校验、事务锁和审计日志，不能只依赖路由层验签。

## 12. 新增接口落地规则

新增 API 时遵循以下实现方式：

1. 控制器保持薄层，只做参数接收、鉴权、调用服务、返回结果。
2. 成功响应优先使用：
   - `$this->success(...)`
   - `$this->paginate(...)`
3. 失败响应优先使用：
   - `$this->error(...)`
   - `throw new BusinessException(...)`
4. 参数校验优先使用 `FormRequest`。
5. 列表接口默认提供 `page`、`page_size`。
6. 不要手写一套与统一外层不同的 JSON 结构。

7. 请求归一化优先放在 FormRequest 或 Request DTO。
8. 响应字段优先通过 Resource 或 Response DTO 管理。
9. 第三方请求/响应不直接穿透到 Controller 或前端。
10. v2 新接口必须列表与详情 Resource 分离。
11. v2 新接口不得复用旧宽 Resource 作为默认输出。
12. v2 新接口必须有 Feature 测试覆盖鉴权、权限、校验失败、成功响应和字段白名单。

### 12.1 推荐控制器写法

```php
public function index(ListProductsRequest $request)
{
    $paginator = $this->productService->paginate($request->validated());

    return $this->paginate($paginator, ProductResource::class);
}

public function show(int $id)
{
    $product = $this->productService->findVisible($id);

    if (! $product) {
        return $this->error(40400, '商品不存在或已下架');
    }

    return $this->success(new ProductResource($product));
}
```

## 13. 联调注意事项

- 前端成功判断以 `code === 0` 为准，不要只看 HTTP 200
- 前端失败提示优先读取 `message`，并按简体中文展示
- 表单校验错误优先读取 `data.errors`
- 新接口变更字段名或嵌套层级时，必须同步更新前端调用
- 自动化或第三方回调接口如果要覆盖 HTTP 状态码，必须保持业务码和 `message` 仍可读

## 14. 维护建议

- 返回格式发生变化时，优先更新本文，再更新前后端调用
- 路由清单变化后，重刷 `docs/自动生成/接口/后端API清单.md`
- 若新增特殊错误码区间，应同步补充本文第 4 节
