---
name: turaidc
description: 在 TuraIDC 面板上替用户操作云服务器与账务——查询在售商品与报价、下单、续费、开关机、重装、重置密码、查看账单与余额、提交与回复工单。使用时需要用户自己的 tura_ API 密钥。
version: 1.0.0
license: AGPL-3.0-or-later
---

# TuraIDC 面板操作 skill

这是一个**给用户自己的 agent 用的** skill：把 TuraIDC 面板的业务能力当工具调用，
由用户自己的 harness / agent 决定要做什么，服务端只负责鉴权、计价、下发与审计。

- 接口基址：`{{base_url}}`
- 接口前缀：`/api/v2/open`
- 鉴权：`Authorization: Bearer <用户自己的 tura_ 密钥>`
- 完整机器可读清单：`{{base_url}}/api/v2/site/ai-skill/turaidc/manifest.json`

密钥由**用户本人**在面板「开发者 → API 密钥」创建，并勾选所需作用域：

| 作用域 | 能做什么 |
| --- | --- |
| `products` | 查在售商品、查报价（只读）|
| `orders` | 查账单；`write` 可下单、可用余额付款 |
| `services` | 查实例、续费预览、重装选项、升级预览、流量包档位；`write` 可开关机、续费、重装、重置密码、升级/流量包下单 |
| `finance` | 查余额 |
| `tickets` | 查工单；`write` 可提交工单、追加回复 |

---

## 铁律（先读，违反会造成真实扣款或误操作）

1. **写操作必须先向用户复述并取得同意**：对象（实例名 + ID）、动作、金额。用户没明确同意就不要调用任何 `write` 端点。
2. **续费会立即从余额扣款**：`POST /services/{id}/renew` 内部自动用余额支付，不是「只出账单」。余额不足时会失败，不会欠款。
3. **下单/升级/流量包只落账单，不扣款**：这三类 `POST` 只创建账单，付款必须再调 `POST /orders/{invoice}/pay`（也从余额扣）。
4. **金额一律以接口返回为准**，不要自己算、不要用历史价格。下单必须带 `GET /products/{id}/quotes` 返回的 `quote_token`。
5. **ID 必须来自查询结果**：先列实例/商品/账单，再拿 `id` 操作。不要猜测或编造 ID。
6. **下单必须带 `idempotency_key`**（≤64 字符，同一次下单重试要复用同一个值），否则网络重试会重复建单。
7. **密钥等价于账号**：不要打印、不要写进日志、不要提交进仓库。用完可以让用户调 `POST /keys/self/disable` 停用。
8. **回答一律用中文**，货币单位是人民币元（如 `9.90` 表示 9.90 元）。

---

## 响应约定

成功：HTTP 200，`{"code":0,"msg":"...","data":{...}}`
失败：非 2xx，`{"code":<业务码>,"msg":"<中文原因>",...}`

| HTTP | code | 含义与处理 |
| --- | --- | --- |
| 401 | 40100 | 缺少或错误的密钥 / 密钥已停用 → 让用户检查密钥 |
| 403 | 40300 | 密钥缺该作用域、IP 不在白名单、账号不可用 → 让用户在面板补权限 |
| 404 | 40400 | 对象不存在或不属于该账号 → 重新列一遍取真实 ID |
| 422 | — | 参数校验失败，`errors` 里是逐字段原因 → 按提示修正后重试 |

列表端点统一返回 `{"list":[...],"total":n,"page":p,"page_size":s}`，翻页用 `page` / `page_size`。
写端点另有频控（`open-api-write`），连续操作间隔请 ≥ 1 秒。

---

## 端点参考

### 商品与报价（`products`）

- `GET /products?page=1&page_size=100` → `list[{id,name,product_type,stock}]`
  全站只返回在售商品；`stock=-1` 表示不限量。
- `GET /products/{product}` → 单个商品 `{id,name,product_type,stock}`
- `GET /products/{product}/quotes?billing_cycle=monthly&quantity=1&config[参数]=值`
  → `{product_id,billing_cycle,original_total_amount,agent_amount,total_amount,agent_discount_rate,quote_token}`
  `config` 是商品配置项：键为配置项键、值为数量（滑块型）或选项值（选择型）。

### 账单（`orders`）

- `GET /orders?page=1&page_size=50` → `list[{id,invoice_no,product_name,amount,paid_amount,status,service_id,paid_at,created_at}]`
- `GET /orders/{invoice}` → 单张账单
- `POST /orders`（`write`）
  body：`{product_id, billing_cycle, quote_token, idempotency_key, quantity?, config?}`
  → `{id,invoice_no,amount}`（**未付款**）
- `POST /orders/{invoice}/pay`（`write`）→ 用余额支付；返回账单最新状态

### 实例（`services`）

- `GET /services?page=1&page_size=100` → `list[{id,name,product_name,status,expires_at,created_at}]`
- `GET /services/{service}` → 实例详情（含配置、面板信息等，结构随上游驱动）
- `GET /services/{service}/renewals?billing_cycle=annually` → 续费预览（不传周期则取默认周期）
- `POST /services/{service}/renew`（`write`）body：`{billing_cycle}` → **立即扣余额**并返回 `{invoice_id,invoice_no,amount,paid}`
- `GET /services/{service}/reinstall-options` → 可重装系统列表（取其中的 `os_id`）
- `POST /services/{service}/reinstall`（`write`）body：`{os_id}` → **会清空系统盘数据**，必须让用户明确确认
- `POST /services/{service}/password-resets`（`write`）body：`{password}`（8–100 位）
- `POST /services/{service}/power`（`write`）body：`{action}`，取值 `on` / `off` / `reboot` / `hard_off` / `hard_reboot`
- `GET /services/{service}/upgrades` → 可升级的配置项与目标选项
- `POST /services/{service}/upgrades/quotes`（`write`）body：`{product_id,billing_cycle,promo_code?}` → 升级差价
- `POST /services/{service}/upgrades/orders`（`write`）同上 body → 生成升级账单（未付款）
- `GET /services/{service}/flow-packages` → 可售流量包档位；`supported=false` 时 `message` 说明原因
- `POST /services/{service}/flow-packages/orders`（`write`）body：`{target_value}` → 生成流量包账单（未付款）

### 财务（`finance`）

- `GET /balance` → `{balance}` 账户余额（元）

### 工单（`tickets`）

- `GET /tickets?keyword=&status=0&page=1&page_size=20`
  `status`：0 开启 / 1 客户回复 / 2 员工回复 / 3 已关闭
  → `list[{id,subject,status,department,priority,service_id,created_at,updated_at}]`
- `GET /tickets/{ticket}` → 工单详情（含回复线程元信息）
- `GET /tickets/{ticket}/replies?page_size=20` → 回复列表
- `POST /tickets`（`write`）
  body：`{department, subject, content?, priority?, service_id?, attachments?}`
  `department`：`sales` 销售 / `support` 技术支持 / `billing` 财务 / `abuse` 投诉
  `priority`：1 低 / 2 中 / 3 高 / 4 紧急
  `content` 最多 10000 字；`attachments` 最多 9 条，元素是已上传图片的路径
- `POST /tickets/{ticket}/replies`（`write`）body：`{content?, attachments?, quote_reply_id?}`
  `content` 与 `attachments` 至少要有一项；已关闭的工单不能再回复。

### 密钥自管

- `GET /keys/self` → `{key_prefix,scopes,expires_at,last_used_at}`
- `POST /keys/self/disable` → 停用当前密钥（之后所有调用 401）

---

## 常用配方

**「我哪台机器快到期了」**
1. `GET /services?page_size=200`
2. 按 `expires_at` 升序取最近 3 台，报名称 + 到期时间 + 状态。

**「帮我续费 X 一年」**
1. `GET /services` 定位实例 → 拿到 `service_id`
2. `GET /services/{service}/renewals?billing_cycle=annually` 取价格
3. **向用户确认**：实例名 + 周期 + 金额
4. `POST /services/{service}/renew` body `{"billing_cycle":"annually"}`
5. 报 `paid` 与账单号；`paid=false` 说明余额不足或待支付，让用户去面板付款。

**「买一台 X」**
1. `GET /products` 找商品 → `product_id`
2. `GET /products/{product}/quotes?billing_cycle=monthly&config=...` 拿 `total_amount` 与 `quote_token`
3. **向用户确认**：商品 + 周期 + 配置 + 金额
4. `POST /orders`（带同一个 `idempotency_key` 便于安全重试）
5. 用户同意付款后再 `POST /orders/{invoice}/pay`

**「帮我提个工单」**
1. 需要实例时先 `GET /services` 拿 `service_id`
2. `POST /tickets` body `{department:"support",subject,content,service_id}`
3. 报工单号与状态；后续追问用 `POST /tickets/{id}/replies`。

---

## 不值得做的事

- 不要为「看看有什么」调用任何 `write` 端点。
- 不要在用户只是询问价格时下单或续费。
- 不要用 `power` 的 `hard_off` / `hard_reboot` 代替正常关机重启，除非用户明确要求强制操作。
- 不要把 `quote_token` 缓存起来跨天复用：它是按当时的商品与配置签发的。

---

## 版本

本文件版本 `1.0.0`。安装来源：`{{base_url}}/api/v2/site/ai-skill/turaidc/SKILL.md`
接口清单（含参数 schema）：`{{base_url}}/api/v2/site/ai-skill/turaidc/manifest.json`