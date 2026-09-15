---
status: 进行中
updated: 2026-09-15
owner: backend-platform
---

# 魔方财务对接链路打通（TuraIDC 作为上游）

## 背景

魔方财务把 TuraIDC 配置为「上游」时，第一步登录即报鉴权失败。排查确认不是配置错误而是功能缺口，并顺带完成对 `routes/v2-zjmf-upstream.php` 全端点与魔方财务 3.7.6（参考源码 `zjmf376/`）调用点的逐条核对。

## 进度

- [x] **鉴权入口缺口（本次用户报告的阻断问题）**：`users.api_open` / `api_username` / `api_password` 三个字段此前**没有任何设置入口**（不在管理端用户编辑、不在用户控制台、无前端页面），而 `/zjmf_api_login` 强制要求 `api_open=1`，导致魔方对接第一步必然失败。
      修复：新增用户控制台「上游 API 对接」自助页（`/client/upstream-api` + `/api/v2/client/upstream-api/*`），开启时生成独立 `api_username` 与一次性明文 `api_password`（bcrypt 落库），支持重置密码与即时关停。
      口径确认：与「API 密钥」页（`/api/v2/open` 自有开放接口）是**两套独立鉴权**，互不影响；魔方财务只走 `/api/v2/zjmf`。
- [x] **P0：`/provision/default` 的 func 覆盖极不完整**。魔方对 zjmf_api 产品的开机/关机/重启/硬关机/硬重启/VNC/重装/改密/救援/电源状态**全部**走该端点（`zjmf376/app/common/logic/Host.php` 各调用点），TuraIDC 此前只处理 create/suspend/unsuspend/terminate，其余命中 `default => status=200` **静默受理**——下游判定成功但什么都没做（面板按钮"点了没反应"）。
      修复：分发全部 func 到本地 `ServicePowerService` / `ServiceVncService`；`status` 返回 `data.status + data.des`、`vnc` 返回 `data.url`（对齐魔方读取点）；救援同时接受 `rescueSystem`（客户端拼写）与 `rescue_system`（参考服务端拼写）；**未知 func 返回 400 明确失败**，不再静默成功。
- [x] **P1：ZJMF 前缀下的固定 HTTP 200 破口**。协议要求 HTTP 固定 200、业务状态放 `body.status`，但 404/422/429/500 会走通用 `api/*` 渲染，魔方 `commonCurl` 对非 200 统一转成 `code=500`，下游只显示「请求失败,HTTP状态码:xxx」且不会重登，上游业务错误信息全部丢失。
      修复：在 `bootstrap/app.php` 通用分支之前注册 ZJMF 专用异常渲染（按注册顺序先命中），401/405、422、429、未捕获异常一律转 `HTTP 200 + body.status`。
- [x] 回归：`ZjmfUpstreamApiTest` 13 → 18 例、新增 `UpstreamApiCredentialTest` 5 例；全量 1857 例与基线失败集合**零差异**。

- [ ] **P1-5/P1-6：`host/header` 字段补齐**。魔方管理端「上游信息」读 `host_data` 的 `regdate/domainstatus_desc/firstpaymentamount(_desc)/amount_desc/promo_code/payment(_zh)/billingcycle(_desc)/group/ocreate_time`，DCIM 依据字段 `dcim.auth/dcim.svg/dcim.flow_packet_use_list` 与 `reinstall_format_data_disk` 缺失——面板显示空白/错值，DCIM 型产品可能不显示电源与重装按钮。
- [ ] **P0-2：开通参数丢失**。`/cart/settle` 只取 `pid/billingcycle/qty`，下游用户在魔方下单时填的 `host`（主机名）、`password`、`configoptions` 未参与下单与开通（仅存进绑定 payload）；同步履约失败时 `apply_credit` 仍返回 1001 但 `data.hostid=[]`，魔方会写入空 dcimid 且无补偿。
- [ ] **P0-3：上游→下游推送没有发送方**。魔方下游侧已实现接收 `/api/host/sync`，TuraIDC 绑定表注释也写明「上游开通/状态变更后回推下游回调地址」，但全仓没有任何 `host/sync` 发送调用——上游暂停/到期/删除与工单回复无法主动同步。
- [ ] **P1-8：管理端缺端点** `/host/setdownstream`、`cart/hostinfo|summary|credit`（ZJMF 管理端「上游信息/下游汇总/上游余额」会调）。

## 决策日志

- 2026-09-15：**上游 API 凭据采用「用户控制台自助 + 独立 API 密码」**（用户确认）。理由：不把上游对接能力绑死在管理员操作上，与魔方自身语义一致（凭据独立于登录密码，改密不影响对接）；开关与重置在用户侧，风险可控且可即时失效。
- 2026-09-15：**未知 func 必须返回业务失败而非静默成功**。魔方对 `status=200` 一律判定命令成功，静默受理是最难排查的一类缺陷（按钮无反应、无报错、日志无痕）。
- 2026-09-15：**ZJMF 异常渲染必须注册在通用 `api/*` 分支之前**。Laravel 异常回调按注册顺序匹配、先命中者生效，顺序颠倒会被通用分支截走，协议兜底失效。
- 2026-09-15：`zjmf376` 参考副本仅含 `nokvm` 插件，CDN(lecdn)/虚拟主机(mhbt) 的插件侧差异无法在本仓库验证；本协议下两者对 `api_type=zjmf_api` 产品无分支差异，仅产品 `type` 映射不同。
