# 魔方财务对接参考（TuraIDC 作为上游）

TuraIDC 实现了魔方财务的上游服务商协议：魔方财务在「上游」里把 TuraIDC 配置为 API 类型上游，其客户购买/续费/控制 TuraIDC 侧产品时由本协议承载。路由定义见 `backend/routes/v2-zjmf-upstream.php`，实现位于 `backend/app/Http/Controllers/ZjmfUpstream/` 与 `backend/app/Services/ZjmfUpstream/`。

## 下游侧配置（魔方财务管理端）

- 上游 API 地址（hostname）：`https://你的域名/api/v2/zjmf`。**必须带 `/api/v2/zjmf` 前缀**，漏掉前缀时登录请求会命中不到路由（HTTP 404，魔方财务侧表现为「请求失败,HTTP状态码:404」）。
- 账号：使用 TuraIDC 的一个普通客户账号，要求 `status=1` 且已开启 API 接入（`api_open=1`），并设置独立的 API 用户名与密码（`api_username` / `api_password`，与管理后台登录密码无关）。
- 账号条件在登录与鉴权两处强制一致：账号被停用或关闭 API 接入后，登录会直接返回 `status=400`，不会出现「登录成功但每次请求 405」的死循环。

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

## 排查指引

持续 405 时查看 TuraIDC 日志中的 `[zjmf-upstream] 鉴权拒绝` 记录，`reason` 字段：

- `disabled`：服务端上游 API 未开放（`services.zjmf_upstream.enabled`）。
- `jwt_invalid`：JWT 缺失、签名不符或过期——检查魔方财务侧是否携带 Bearer 头、两端时钟是否偏差过大。
- `account_unavailable`：对接账号被停用或未开启 API 接入，到 TuraIDC 客户管理里恢复后重新登录。

其余协议细节（字段名、幂等语义、降级策略）以路由文件与各 Service 的注释为准，魔方财务侧调用点可对照其源码 `app/zjmf.php` 的 `zjmfCurl` 与 `app/common/logic/Host.php`。
