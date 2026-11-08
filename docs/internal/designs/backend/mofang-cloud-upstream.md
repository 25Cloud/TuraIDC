---
status: active
updated: 2026-11-08
owner: backend-platform
---

# 魔方云（DCIMS）直连上游的两代接口适配

状态：已实现控制台运行时与状态同步（v0.4.6 未发布），开通与增值能力待接
范围：`backend/plugins/servers/mofang_cloud/`
触发事件：2026-11-08 需要把魔方云作为独立上游接进来，且上游存在两代互不兼容的开放接口

## 背景

魔方云（DCIMS）与魔方财务是两个系统：财务是账务/商品/客户，云是实例与资源。此前本系统只做过「魔方财务作为上游」，魔方云本身没有驱动。

魔方财务自己倒是有两套魔方云客户端，而且**分别对应魔方云的两代接口**：

| 魔方财务版本 | 魔方云客户端                       | 接口                 | 鉴权                                                            |
| ------------ | ---------------------------------- | -------------------- | --------------------------------------------------------------- |
| 3.7.6 及更早 | `app/common/logic/DcimCloud.php`   | `{url}/v1{action}`   | `POST /v1/login?a=a` 表单换 token，业务请求带 `access-token` 头 |
| 3.8.0 及以后 | `app/common/logic/DcimsClient.php` | `{url}/api/v1{path}` | `Dcims-X-Appid/Nonce/Timestamp/Sign` 四头 + 双 HMAC 签名        |

两代**端点路径、鉴权方式、报文编码、响应信封全都不同**，不存在一次请求两边都能过的写法；混用的结果是必然的 404 或验签失败，而不是自动降级。因此本驱动的第一条设计约束是：**先分辨代际，再发请求，两代代码不共享传输细节**。

## 两代接口的事实（照抄自上游客户端，已核对）

### 老版本 `/v1`

- 地址：`{base}/v1{action}`；代理账号（`account_type=agent`）改走 `{base}/index.php?path={action}`。
- 登录：普通账号 `POST /v1/login?a=a`（表单 `username`/`password`），**响应体原文就是 token**（带引号，去引号即用）；代理账号 `POST index.php?path=token`，取 `data.token`。token 缓存 6 小时。
- 业务请求：`access-token` 头；写操作是 **`application/x-www-form-urlencoded`**，不是 JSON；命中 401 时强制重登一次并重放当次请求。
- 响应：**没有统一信封**——详情字段直接在顶层，列表在 `data` 里，错误表现为顶层 `error` 字段；成功以 HTTP 2xx 为准，写操作另需看 `status == "success"`。
- 状态机：`on` / `off` / `suspend` / `wait_reboot` / `task` / `paused`（`cold_migrate`、`hot_migrate` 归 `task`），未知归 `unknown`。

### 新版本 `/api/v1`

- 地址：`{base}/api/v1{path}`，JSON 报文。
- 签名（两次 HMAC-SHA256，密钥即 AppSecret）：
  1. 报文键名**递归升序**后取紧凑 JSON（空报文固定 `"{}"`，不转义斜杠与中文），
  2. `dataHash = HMAC(json, secret)`，
  3. `signData = {secret, data: dataHash, nonce, timestamp}` 按 key 升序后 `http_build_query`，
  4. `sign = HMAC(query, secret)`；`nonce` 16 位十六进制，`timestamp` 秒。
- **参与签名的 JSON 必须与真正发出去的报文逐字节一致**，因此实现里只编码一次、签名与请求体复用同一字符串。
- GET 的参数进查询串，且**签名前全部转字符串**。
- 响应：`{status:200|'success'|code:200, msg, data}`；业务失败靠信封判定，客户端本身不看 HTTP 状态码（上游 5xx 只要 body 是 JSON 也会被当业务响应，这是上游行为，本实现保留但把非 JSON 响应直接判为失败）。
- 实例路径统一为 `/server/{id}/...`，且 `id` 同时出现在**路径与报文**里——少一个签名就不匹配。

## 代际分辨

按「显式声明优先，缺省才探测」实现，与魔方财务一致：

1. 供应商配置 `provider_config.api_mode` 显式选了 `legacy` 或 `dcims` → 直接用，**不试探**；
2. 选 `auto` 或未选 → 先按新版本 `GET /api/v1/test` 探活，失败再按老版本登录试探；
3. 两者都失败 → 报错并把两边失败原因都带出，提示手动指定代际。

探测结果按「供应商 + 地址」缓存 1 小时，避免每次请求多打一轮。管理端供应商卡片的「测试连接」会分别报告两代探测结果，并把最终采用的代际显示出来；连不上时不抛异常，只把原因写成文案，避免整页卡片 500。

## 代码结构

| 文件                                                         | 职责                                                                                                                            |
| ------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------- |
| `config.php`                                                 | 插件清单；`info.capabilities` 是能力清单的唯一真源                                                                              |
| `logic/MofangCloud.php`                                      | 插件入口：`server.resolve_capability` / `server.supplier_form_schema` / `server.supplier.refresh_card` 动作，以及供应商卡片渲染 |
| `lib/CloudApiMode.php`                                       | 代际枚举 + 各代能力差异声明                                                                                                     |
| `lib/CloudCredentials.php` / `CloudCredentialsResolver.php`  | 从供应商绑定解析地址与凭据（老版 账号+密码 / 新版 AppID+AppSecret）                                                             |
| `lib/CloudApiClient.php`                                     | 传输接口：`request(method, path, payload, query)`                                                                               |
| `lib/LegacyCloudClient.php`                                  | 老版 `/v1` 客户端（access-token、401 重登重放、表单编码）                                                                       |
| `lib/DcimsCloudClient.php`                                   | 新版 `/api/v1` 客户端（双 HMAC 签名、JSON 报文、信封判定）                                                                      |
| `lib/CloudClientFactory.php`                                 | 按代际产出客户端 + 自动探测 + 诊断                                                                                              |
| `lib/CloudOperations.php`                                    | 能力接口：两代各自实现，返回结构对平台归一                                                                                      |
| `lib/LegacyCloudOperations.php` / `DcimsCloudOperations.php` | 两代能力实现                                                                                                                    |
| `lib/MofangCloudAdapter.php`                                 | 能力门面：把平台的方法名翻译成对应代际实现                                                                                      |

认证信息全部落在供应商绑定（`supplier_plugin_bindings`）既有字段里：`base_url`、`account_name`、加密的 `secret_json.api_key`，外加 `config_json` 里的 `api_mode` / `account_type`。**没有新增表或列**。

证书校验不由插件开关：仓库级契约要求插件一律按平台策略校验上游证书，不得自带 CA 或关闭校验（由 `PluginRuntimeRegistryIntegrationTest` 反向锁定）。

## 能力覆盖矩阵

| 能力                           | 老版本 `/v1`                    | 新版本 `/api/v1`                |
| ------------------------------ | ------------------------------- | ------------------------------- |
| 实例详情 / 列表字段归一        | ✅                              | ✅                              |
| 电源 开/关/重启                | ✅                              | ✅                              |
| 强制断电 / 强制重启            | ✅                              | ❌ 上游无对应端点，明确报错     |
| VNC                            | ✅                              | ✅                              |
| 改密                           | ✅                              | ✅                              |
| 重装（含镜像列表）             | ✅                              | ✅                              |
| 救援模式                       | ✅                              | ✅（`linux`/`windows`）         |
| 取消任务                       | ❌ 老接口无此端点，明确报错     | ✅                              |
| 任务/重装进度                  | 靠 `/status` 的 `task` 字段推断 | ✅ `/tasks` 带进度              |
| 流量用量 / 曲线                | ✅                              | ✅                              |
| 监控统计（CPU/内存/磁盘/网络） | ✅ `/statistics`                | ❌ 无对应端点，返回空           |
| 暂停 / 恢复 / 删除             | ✅                              | ✅                              |
| 镜像目录 / 区域目录            | ✅                              | 镜像 ✅、区域 ❌（上游不提供）  |
| 安全组 / NAT 转发              | ✅                              | ❌ 统一报「请到魔方云面板操作」 |

平台侧只使用归一化后的结构（`['status' => 200, 'msg' => ..., 'data' => ...]`），两代差异不出现在消费方。

## 刻意没有照抄的三处

魔方财务的实现里有三个缺陷，照抄会把 bug 一起搬进来：

1. **`editServerSales` 被映射成 `terminate`**（3.8.0 适配层）。改销售信息会直接删机。本实现不提供该动作。
2. **新版流量包配额分页不循环**（`return` 写在 `while` 第一轮），超过 100 条流量包会少算配额。本实现按需另行实现，不复用该逻辑。
3. **老版 `delNatAcl` / `delNatWeb` 用未定义变量拼 `hostid`**，导致删除请求缺参数。本实现按正确形态传 `?hostid={id}`。

## 取舍

- **不做「一次请求自动降级」**：代际选错必须显式失败并提示改配置，静默降级会让排障无从下手。
- **不声明未完成的能力**：`config.php` 里只列已实现的能力（控制台访问/运行时/暂停/删除/状态同步）。声明了平台就会调用，而两代开通参数还没接完，宁可少声明也不要让平台回退到通用 REST 路径——那对魔方云是错的端点。
- **老版写操作的成功判定分两档**：电源/VNC/重装/改密/救援/暂停/恢复/删机只认 `status === "success"`；NAT 增删额外接受 `200`；删机遇到 404 视为已删除（与上游语义一致）。
- **救援模式的临时密码**：老接口必填、平台侧又没有入参，取随机生成并回传，支持 `linux:密码` 形式显式指定。

## 验证

- `tests/Unit/MofangCloudHttpClientTest.php`：端点前缀、四头齐全、签名与所发报文一致、GET 参数转字符串、401 重登重放、`error` 字段转异常、代理账号取 token、代际取值映射。
- `tests/Unit/MofangCloudLegacyOperationsTest.php`：老版能力全量（详情归一化、状态机、安全组串行依赖、NAT、删机 404、流量换算等）。
- `tests/Feature/MofangCloudProviderWiringTest.php`：插件扫描/安装/注册为上游 provider、能力解析到适配器、供应商表单含代际开关。

## 未覆盖 / 下一步

1. 续费与账期（魔方云不承担计费，账期在本地；上游只有 `sync_data` 可回写到期时间）。
2. 改配（CPU/内存/带宽/IP/IPv6/磁盘扩容）：上游端点齐了，但平台侧还差一个「魔方云形态」的升级下单入口。
3. 快照 / 备份 / ISO 挂载 / 启动项（仅老版有），同样是上游能做、控制台还没有入口。
4. 安全组写操作（仅老版有），同上。
5. 真实魔方云联调：目前全部是 `Http::fake` 的协议级断言，没有打真机。

> 这三类（改配/快照/安全组写）刻意没有提前写进驱动接口：平台没有对应入口时，
> 写了就是没有调用方的死代码。等控制台入口落地时再按需补，成本比现在囤着低。
