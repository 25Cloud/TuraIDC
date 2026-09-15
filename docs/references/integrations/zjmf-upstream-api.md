# 魔方财务对接参考（TuraIDC 作为上游）

TuraIDC 实现了魔方财务的上游服务商协议：魔方财务在「上游」里把 TuraIDC 配置为 API 类型上游，其客户购买/续费/控制 TuraIDC 侧产品时由本协议承载。路由定义见 `backend/routes/v2-zjmf-upstream.php`，实现位于 `backend/app/Http/Controllers/ZjmfUpstream/` 与 `backend/app/Services/ZjmfUpstream/`。

## 下游侧配置（魔方财务管理端）

- 上游 API 地址（hostname）：`https://你的域名/api/v2/zjmf`。**必须带 `/api/v2/zjmf` 前缀**，漏掉前缀时登录请求会命中不到路由（HTTP 404，魔方财务侧表现为「请求失败,HTTP状态码:404」）。
- 账号：使用 TuraIDC 的一个普通客户账号。**由客户自己在控制台「上游 API 对接」页开启**（`/client/upstream-api`，接口 `/api/v2/client/upstream-api/*`），开启后系统生成独立的 `api_username` 与 `api_password`（密码仅显示一次，可随时重置），并确保账号 `status=1`。该凭据与账号登录密码、与「API 密钥」页的开放接口密钥都是独立的，互不影响。
- 账号条件在登录与鉴权两处强制一致：账号被停用或关闭上游 API 接入后，登录会直接返回 `status=400`，不会出现「登录成功但每次请求 405」的死循环。

> 注意：魔方财务对接（本页协议 `/api/v2/zjmf`）与自有开放接口（`/api/v2/open`，控制台「API 密钥」页）是**两套独立鉴权**。魔方财务只走本页协议，API 密钥在该场景不可用；另一个 TuraIDC 实例对接才用 API 密钥（配 `tura_open_api` 插件）。

## 登录与鉴权

1. 魔方财务调用 `POST /zjmf_api_login`（form 编码的 `username` / `password`），成功返回 `{"jwt": "...", "status": 200}`。
2. 后续业务请求携带 `Authorization: Bearer <jwt>`。
3. JWT 为 HS256 自签自验，有效期 7200 秒（对齐魔方财务 `createJwt`）。魔方财务会长期缓存 JWT，仅在收到 `status=405` 时强制重登一次，重登仍 405 则报「API账号密码错误」——因此每约 2 小时会出现一次协议内的 405 自愈请求，属正常现象。

## 响应约定

HTTP 层固定 200，业务状态放在 body 的 `status`：

| status | 语义                                           |
| ------ | ---------------------------------------------- |
| 200    | 成功                                           |
| 1001   | 支付类操作「已支付完成」（魔方财务映射为成功） |
| 400    | 业务失败（msg 为用户可读原因）                 |
| 405    | JWT 失效，触发魔方财务强制重登                 |

注意：魔方财务的 `commonCurl` 对 HTTP 非 200 的响应统一转换为 `code=500`，不会透传 HTTP 状态码；若对接报错中带「HTTP状态码:404/429」字样，先检查 hostname 前缀与登录限流（20 次/分钟）。

## 接口清单

- 登录：`POST /zjmf_api_login`（免鉴权，单独限流）
- 商品：`GET /cart/all`、`GET /api/product/proinfo`、`GET /api/product/prodetail`、`GET /cart/get_product_config`、`GET /cart/ontrialmax`
- 购买开通：`GET /user_info`、`POST /cart/clear`、`POST /cart/add_to_shop`、`POST /cart/settle`、`POST /provision/default`、`POST /provision/custom/{id}`
- 主机：`GET /host/header`、`POST /host/renew`、`POST /host/cancel`、`POST /provision/button`（无参控制按钮，`id` + `func`）
- 控制：`POST /dcim/on|off|reboot|hard 相关`、`POST /dcim/novnc|kvm|ikvm|bmc|rescue|crack_pass|reinstall|cancel_task|refresh_power_status|refresh_all_power_status|hide_result|check_reinstall|buy_reinstall_times|buy_flow_packet`、`GET /dcim/traffic_usage|/host/trafficusage`、`GET /dcim/detail|/dcim/resintall_status`
- 升级：`POST /upgrade/upgrade_config_post|checkout_config_upgrade|upgrade_product_post|checkout_upgrade_product`
- 余额：`POST /apply_credit`（余额支付，成功返回 1001 与 `data.hostid[0]`=服务 ID）、`POST /apply_credit_limit`（暂不支持信用额，返回 400）
- 推送：`POST /api/ticket_reply/sync`、`POST /upload_image`

## 自定义 Tab（面板型产品）

CDN、虚拟主机等「面板型产品」在魔方财务侧靠自定义 tab 承载控制台与登录信息，链路为：

1. `GET /host/header` 返回 `module_client_area`（`[{key, name}]`）声明可用 tab，返回 `module_client_main_area`（`[{name, value}]`）供下游渲染登录信息（面板地址、用户名、密码、端口）。
2. 下游点击 tab 时 `POST /zjmf_api/provision/custom/content`（入参 `id`=上游主机 id、`key`=tab 标识、`api_url`、`now_jwt`），取回 `{status:200, data:{html}}`。
3. 页面内动作提交到 `POST /provision/custom/{id}` 透传执行。

中间层（TuraIDC 自身接供应商时）的处理顺序对齐魔方财务：

1. 服务接入可控供应商 → **透传**供应商的 `module_client_area` / `module_client_main_area` / `module_button`，取内容时把下游的 `api_url` 继续下传，动作原样转发；
2. 未接入可控供应商的面板型产品 → 用本地连接凭据渲染面板（面板地址、用户名、密码、端口）；
3. 机房型产品（云服务器/裸机）不下发自定义区域，功能入口统一在 `/dcim/*`。

`host_data` 同时下发 `show_traffic_usage`（有流量配额时为 true），下游据此决定是否渲染流量用量。

注意：取内容必须走上面的 API 协议端点（用 API JWT 鉴权）。魔方财务的客户区路由 `GET /provision/custom/content` 只认客户区登录会话（`client_user_login_token_` 缓存），API JWT 调它会取不到内容。

## 排查指引

持续 405 时查看 TuraIDC 日志中的 `[zjmf-upstream] 鉴权拒绝` 记录，`reason` 字段：

- `disabled`：服务端上游 API 未开放（`services.zjmf_upstream.enabled`）。
- `jwt_invalid`：JWT 缺失、签名不符或过期——检查魔方财务侧是否携带 Bearer 头、两端时钟是否偏差过大。
- `account_unavailable`：对接账号被停用或未开启上游 API 接入，到客户控制台「上游 API 对接」页重新开启（或恢复账号状态）后重新登录。

**魔方侧提示「鉴权失败」（登录即失败）时**，按以下顺序排查：

1. 上游地址是否带 `/api/v2/zjmf` 前缀（`https://域名/api/v2/zjmf`）；
2. 客户是否已在控制台「上游 API 对接」页开启，并使用了页面上给出的**用户名与一次性密码**；
3. 若曾重置密码，魔方侧需同步更新为新密码（旧密码立即失效）。

其余协议细节（字段名、幂等语义、降级策略）以路由文件与各 Service 的注释为准，魔方财务侧调用点可对照其源码 `app/zjmf.php` 的 `zjmfCurl` 与 `app/common/logic/Host.php`。
