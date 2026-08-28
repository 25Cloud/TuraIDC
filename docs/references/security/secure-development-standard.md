# 安全开发规范

本规范约束 TuraIDC 后端的安全实现方式。它不是清单式的"注意事项"，每一条都对应本仓库真实发生过的缺陷，并给出可执行的自检命令。

与其他文档的分工：`AGENTS.md`「关键约束入口」给出不可违反的一句话结论，本文给出理由、边界与验证方法。冲突时以运行代码和测试为准，并回来更新本文。

> **关于 WAF 层的落地状态**：第 2 节描述的入站 WAF（`backend/config/waf.php`、`App\Support\Waf\Firewall`、`WebApplicationFirewall` 中间件）由独立 PR 引入，本文发布时可能尚未合并。本文对它的**约束与边界判断**不依赖该 PR——那些结论来自"正则载荷匹配无法识别身份"这一结构性事实，WAF 未上线时同样适用（此时更不能把鉴权问题推给它）。文件路径待该 PR 合并后即可对应。

---

## 1. 分层模型

安全不是某一层的属性，而是若干**职责不可互相替代**的层叠加的结果。任何"某层已经防了，这层可以松一点"的推理都是错的。

| 层 | 负责 | 实现位置 | 不能替代的 |
| --- | --- | --- | --- |
| 入站 WAF | 拦明显攻击载荷、自动化扫描 | `backend/config/waf.php`、`App\Support\Waf\Firewall`、`WebApplicationFirewall` 中间件 | 身份、权限、凭据真伪 |
| 认证 | 你是谁 | `auth:sanctum`、各协议的令牌/签名服务 | 你能做什么 |
| 授权 | 你能做什么 | RBAC、scope 校验、Policy | 输入是否合法 |
| 输入校验 | 参数结构与业务合法性 | `FormRequest` | 输出是否安全 |
| 输出净化 / 编码 | 渲染时不产生可执行内容 | `RichHtmlSanitizer`、`TextSanitizer`、`htmlspecialchars` | 数据层是否被注入 |
| 数据访问 | SQL 不被拼接 | Eloquent / 查询构造器参数绑定 | 以上任何一层 |

---

## 2. WAF：能做什么，绝不能指望它做什么

### 能做

- 挡自动化扫描器与批量探测（UA 特征、敏感路径探测、备份包猜测）；
- 挡形态明显的注入载荷（`union select`、`information_schema`、`into outfile`、代码执行函数名）；
- 降低被未知漏洞踩中的概率与日志噪音。

### 不能做

WAF 是**正则载荷匹配**，`Firewall::inspect()` 只看 path / query / body / cookie / UA 的字符串形态，**没有身份概念**。

这不是理论推断。2026-08-28 的审计中，`zjmf_bridge` 插件被发现可伪造任意用户 JWT（`ZjmfTokenService` 以空串作 HMAC 密钥），PoC 打 `/zjmf/v1/user` 返回 200 —— **而当时 WAF 正在这条路由上生效**（该中间件 prepend 在 `api` 组，桥接路由走 `middleware(['api', ...])`；`waf.except` 中的 `api/v2/zjmf/*` 豁免的是上游协议路径，桥接在 `zjmf/v1`，不在豁免名单）。原因很简单：伪造的 JWT 与合法 JWT 逐字节同构，没有任何正则能区分。

### 禁止

- **禁止**把鉴权、授权、越权、凭据伪造问题"交给 WAF 解决"。这类缺陷必须在认证 / 授权层修。
- **禁止**因为有 WAF 就放松参数绑定、`FormRequest` 校验或富文本白名单净化。
- **禁止**用 WAF 规则兜底业务校验（例如"用规则挡住负数金额"）——业务约束属于 `FormRequest` 与领域服务。

### 规则与豁免的纪律

- 豁免名单（`waf.except`）每加一条都是一个洞。必须在该条目旁写明**为什么这条路径承载的合法内容会被规则误伤**，不能只写路径。
- 规则库改动先以观察模式（只记录不拦截）运行，确认无误伤后再切拦截。
- 规则只针对攻击特征，不针对业务词汇。过于宽泛的规则（裸 `substr(`、裸 `user(`）误伤代价大于收益，不要加。
- 富文本字段（文章正文、工单内容）合法包含 HTML，入站层拦 `<div` 会直接打断业务；XSS 由输出层白名单净化负责。

---

## 3. 密钥纪律：读到空值必须拒绝服务

这是本仓库出现过的最高危缺陷模式。

```php
// 错误：密钥为空时 HMAC 用空串当 key，任何人都能算出"正确"签名
return hash_hmac('sha256', $input, (string) config('x.secret', ''), true);
```

### 规则

1. **空即拒绝。** 任何签名、HMAC、令牌密钥读到空值时必须**同时**拒绝签发与拒绝校验。绝不允许降级为"空密钥照常计算"。
2. **签发与校验同源同判。** 同一个密钥若被多个服务使用，取值方式（含 `trim()`）与"算不算已配置"的判断必须一致。`zjmf_bridge` 的漏洞正是因为签名服务挡了空值、令牌服务没挡。
3. **必须写入 `backend/.env.example`**，并注明留空的后果。该键当时完全不在样例文件里，运维开启功能时没有任何提示。
4. **比对用 `hash_equals()`**，不得用 `==` / `===`（时序侧信道 + 类型混淆）。
5. **校验 `alg` 白名单。** JWT 类令牌即使实现上恒按固定算法重算签名，也应显式校验 header 中的算法，防止后续有人加算法分派时退化成 `alg=none`。

### 本仓库现状（2026-08-28 全量核对）

14 个签名点，除已修复的 `ZjmfTokenService` 外全部合规。合规形态可参照：

- 入口条件里带 `$secret === ''`：`demo_verification`、`stay33`、`demo_pay`、`VerifyTicketUpstreamCallbackSignature`；
- 取值方法内抛异常：`LeafFaceClient::appSecret()`；
- 入口 `isConfigured()` 检查：`GeetestCaptchaService`；
- `throw_if($secret === '', ...)`：`TicketUpstreamCallbackService`。

---

## 4. XSS：白名单，不是黑名单

### 禁止自制标签剥离

```php
// 错误：单遍剥离可以被重组绕过
return preg_replace('/<\/?script\b[^>]*>/iu', '', $value);
```

实测这段代码对 9 个常见载荷放行 8 个（`<img onerror>`、`<svg onload>`、`<iframe>`、`<object>`、`<embed>`、`<body onload>`、`javascript:` 协议全部原样通过）。最严重的一条：

```
输入: <scr<script>ipt>alert(1)</scr</script>ipt>
输出: <script>alert(1)</script>
```

输入原本不含完整 `script` 标签，剥掉内层后剩余部分**重组成了一个可执行标签**——净化反而使情况变坏。这就是黑名单方案的固有缺陷，不是这条正则写得不好。

同理，`strip_tags($html, $allowed)` **不可**用于安全净化：它的第二个参数只做标签名白名单，属性一律原样留下，`<img src=x onerror=...>` 的 `img` 在白名单里于是整条带着 `onerror` 通过。

### 三个工具的分工

| 场景 | 用 | 说明 |
| --- | --- | --- |
| 需要保留富文本（文章正文等） | `RichHtmlSanitizer::sanitize()` | DOM 白名单三层：标签、属性、URL 协议；`iframe/object/embed/script/svg/style/form/base/link/meta` 连内容一起删；解析失败整段转义而非回退原文 |
| 只要纯文本（昵称、备注、摘要） | `TextSanitizer::clean()` / `nullable()` | 剥掉全部标签 |
| 服务端拼 HTML 时插入变量 | `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')` | 输出编码，非净化 |

`strip_tags(Str::markdown($content))` 用于**生成纯文本摘要**是正当的内容转换，不属于本节约束范围——但它的产物不得再当作"已净化的 HTML"使用。

### 模板类字段的特殊注意

若某处会把管理员撰写的内容**作为 HTML 原样输出**（例如通知模板正文），仅对替换进去的参数值做 `htmlspecialchars` 是不够的——模板本体同样是不可信输入，必须过 `RichHtmlSanitizer`。

---

## 5. SQL 注入：参数绑定是唯一手段

- 一律使用 Eloquent / 查询构造器，让驱动做参数绑定。
- `whereRaw` / `selectRaw` / `havingRaw` / `orderByRaw` / `DB::raw` 若确有必要，**变量必须走绑定参数**，不得字符串拼接；`orderBy` 的列名与方向必须过白名单枚举，绑定参数不适用于标识符。
- `DB::statement` / `DB::unprepared` 只允许出现在迁移与安装流程中，且不得含任何请求来源的值。

本仓库现状：`backend/plugins/` 下上述 API 命中数为 **0**，全部走参数绑定。这是要维持的基线。

---

## 6. 命令执行与反序列化

- `exec` / `popen` / `shell_exec` 的所有变量部分必须 `escapeshellarg()`，数值用 `%d` 格式化。现有 2 处（`SyncUpstreamsCommand`、`VncRelayEnsureCommand`）均符合。
- `unserialize()` 必须带 `['allowed_classes' => [...]]` 白名单。禁止无参调用。
- `eval` / `assert` / `create_function` / `system` / `passthru`：**禁止**，当前全仓命中数为 0。

---

## 7. 插件的额外约束

插件是本仓库安全边界最容易被绕开的地方（`zjmf_bridge` 的漏洞即出自此）。

- 全部特有逻辑收敛到 `backend/plugins/{domain}/{slug}/`；
- **不得注册系统级路由、调度或全局中间件**；
- **不得自建鉴权中间件绕过统一认证入口**。插件确需自有协议鉴权时，必须复用平台的密钥纪律与比对工具，且不得让同一凭据在插件内出现两套判断口径；
- 所有回调入口必须验签（三个支付网关均已实现）；
- 插件不需要 SSL 与 CA（项目既定决策），因此**不得**以"关掉证书校验"为由放松其他校验。

---

## 8. 自检清单

改动涉及认证、签名、渲染或数据访问时，跑一遍：

```bash
cd backend

# 签名密钥是否有空值守卫（每个命中点都要能指出它的守卫在哪一行）
grep -rn "hash_hmac" app plugins --include=*.php | grep -v /tests/

# 是否有用 == 比对签名的（应为空）
grep -rnE "(signature|sign|token|expected|hmac)[^;]*\s(===?|!==?)\s*[^;]*(signature|sign|token|expected|hmac)" \
  app plugins --include=*.php | grep -v /tests/ | grep -v hash_equals

# 插件内原始 SQL（应为空）
grep -rnE "DB::(raw|statement|unprepared)|whereRaw|selectRaw|havingRaw|orderByRaw" plugins --include=*.php | grep -v /tests/

# 危险函数（应只剩已知的 exec/popen/unserialize 三处，且均已加固）
grep -rnE "\b(eval|assert|exec|shell_exec|system|passthru|popen|proc_open|create_function|unserialize)\s*\(" \
  app plugins --include=*.php | grep -v /tests/

# 自制的标签剥离 / 注入过滤（命中即需判断是否应改用白名单净化器）
grep -rniE "preg_(match|replace)[^;]*(script|onerror|onload|javascript:|iframe|union|select)" app plugins --include=*.php | grep -v /tests/

# 新增的密钥类配置项是否已写入样例
grep -c "_SECRET\|_KEY" .env.example
```

新增签名 / 令牌 / 回调链路时，测试必须包含：

1. 密钥未配置时拒绝服务；
2. 伪造凭据被拒绝（**用手工拼装的凭据，不要经由本项目的签发服务**——攻击者手里没有你的代码）；
3. 合法凭据仍然通过的阳性对照。

### 反向验证

安全用例必须做反向验证：把**仅测试文件**打在未修复的代码上，确认它们如期失败。恒真的安全测试比没有测试更危险。

注意用例不要引用"修复才引入的常量"，否则反向验证会因未定义符号而 Error，掩盖真正要证明的行为差异——应当断言**可观测行为**（实际落库的字符串、实际返回的状态码）。

---

## 9. 本规范的实证依据

上述结论来自 2026-08-28 对 `backend/app` 与 `backend/plugins`（7 个插件域）的一次全量核对，方法为：签名点逐个追溯密钥来源与守卫位置、比对方式全量枚举、原始 SQL 与危险函数全仓 grep、XSS 净化点逐个判断用途，其中黑名单过滤器的绕过以本地 PHP 实际执行 9 个载荷确认。

发现并已修复：`zjmf_bridge` 令牌伪造。
发现待修复：通知模板字段的黑名单式净化（第 4 节的反例即取自该处）。
