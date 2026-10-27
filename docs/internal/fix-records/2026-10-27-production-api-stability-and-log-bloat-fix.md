# 生产接口稳定性与日志膨胀修复

- 状态：已修复并上线（二五云4）
- 发现方式：读生产 `laravel.log`（1.2GB）、`/www/wwwlogs/*.log`、php-fpm 配置，配合端到端实测
- 涉及范围：`backend/app/Http/Resources/Finance`、`backend/app/Services/Upstream/Drivers/HostingPanelApi`、`backend/app/Services/ClientServiceConsole`、`backend/plugins/servers/zjmf_finance`、`backend/app/Http/Middleware`、`backend/.env.example`、`deploy/logrotate/`

## 现象

对生产日志按状态码统计当日 `api.25y.cn.log`，4xx/5xx 分布为 404 ×78835、499 ×4591、422 ×2382、500 ×2298、401 ×532、502 ×144。其中 500 集中在少数几个高频接口，且 Laravel 日志单文件已达 1.2GB。

## 根因与修复

### 1. `GET /api/v2/client/finance/ledger` 与 `GET /api/v2/client/balance-logs` 稳定 500

**根因**：`FinanceLedgerResource::isAutoRenew()` 直接调用 `method_exists($payment, 'callbackPayload')`。资金流水里有大量不关联支付记录的行（充值、调账、推荐奖励），此时 `$payment` 为 `null`，PHP 8 对 `method_exists(null, ...)` 抛 `TypeError`，整个接口 500。同文件第 26、154 行的同类调用都写了 `$payment && ...` 守卫，只有这一处漏了。

**证据**：`production.ERROR: method_exists(): Argument #1 ($object_or_class) must be of type object|string, null given at .../FinanceLedgerResource.php:126`，单用户连续 7 次重试全部失败。

**修复**：判空后再取方法，并加注释说明流水可以没有支付记录。

### 2. `Undefined variable $http_response_header` 掩盖了真实的上游网络错误

**根因**：`HostingPanelApiTransport`（它是全部 `zjmf_api_*` 供应商的唯一出口）在 `@file_get_contents()` 之后用 `is_array($http_response_header)` 取响应头。实测 PHP 在**连接被拒 / DNS 解析失败 / 超时**时根本不会创建这个变量，于是 `is_array()` 先触发 "Undefined variable" 警告，被 Laravel 错误处理器转成 `ErrorException` 抛出，真正的网络错误信息被整个盖掉。

**证据**：最近 60MB 日志里 `[定时任务] 上游商品库存同步失败` / `ZJMF 财务认证刷新失败` / `用户产品状态同步失败` 合计约 1700 条，message 清一色是 `Undefined variable $http_response_header`，看不出是 DNS、超时还是证书问题。

**修复**：`is_array($http_response_header ?? null)`（4 处：`HostingPanelApiTransport::requestText()`、`requestWithMeta()`、WAF 挑战重试分支，以及 `ServiceConsoleAssetService::download()`）。`??` 对未定义变量不产生警告，网络失败时会正常走 `$output === false` 分支并记录 `error_get_last()` 的真实原因。

### 3. `[ZJMF 财务状态同步] 返回失败` 刷屏且无上下文

**根因**：`ZjmfStatusService::assertSuccess()` 每失败一次就写一条 warning，且只有 `action`/`status`/`message`，没有供应商、服务、主机 ID。而其中绝大多数是**持续性失败**——虚拟主机被上游回 406「不支持的方法」、上游已删除的实例被回「未找到该产品」——每一轮同步都重新记一遍。

**证据**：最近 100MB 日志里该条占 13664 条，其中「不能执行该操作」6475、「不支持的方法」3878、「未找到该产品」3311。

**修复**：`assertSuccess()` / `extractParallelPayload()` / `extractHostPayload()` / `extractRuntimePayload()` / `resolveHostRuntimePayload()` 逐层透传 `supplier_id`+`service_id`+`host_id`；用 Redis `Cache::add` 做 5 分钟去重（同一服务同一 action 同一 status 同一 message 只记首条），缓存不可用时宁可多记。降级日志「读取电源状态失败」同样去重。

### 4. 上游接口响应日志占日志体积绝大部分

**根因**：`HostingPanelApiTransport` 每次调用都按 INFO 写一条完整响应摘要（`summarizeLogResponse` 按 240 字符截断字段、10 条预览，单条仍达数 KB），而定时任务每天发起数万次。

**证据**：最近 100MB 日志里 `[主机面板接口]` 101581 条、`[ZJMF 财务接口]` 8948 条，两者合计占全部日志条数的 2/3 以上。

**修复**：新增 `logUpstreamResponse()`，按「状态码 + 耗时」决定级别：`http_code === 0`（连状态行都没拿到）或 `>= 400` 或耗时 `>= 2000ms` 才写 warning 并保留完整摘要，其余 2xx 快响应降为 debug（生产 `LOG_LEVEL=info` 时不落盘）。摘要用闭包延迟构造，正常路径连 json 编码都省掉。排查上游问题时把 `LOG_LEVEL` 调成 `debug` 可恢复全量。

### 5. 应用日志从未轮转 + `.env.example` 默认值就是爆盘配方

**根因**：`backend/.env.example` 里写着 `LOG_STACK=single` 和 `LOG_LEVEL=debug`。`single` 通道固定写一个文件且永不切分，`debug` 把上面第 4 条的全量响应一起落盘；系统层也没有任何 logrotate 规则管应用日志。

**修复**：`.env.example` 改为 `LOG_STACK=daily`、`LOG_LEVEL` 留空按环境推导；新增 `deploy/logrotate/turaidc` 作为兜底（`daily` / `rotate 7` / `compress` / `copytruncate`，因为 Monolog 长期持有 append 句柄）。站长侧说明见 `docs/guide/maintain/log-archive.md` §5。

### 6. php-fpm slowlog 实际未生效

**根因**：`php-fpm.conf` 的 `slowlog = var/log/slow.log` 是**相对路径**，实际按 master 进程工作目录解析；该目录不存在时 php-fpm 静默放弃写慢日志，于是「哪个接口慢」长期只能靠猜。另外 `request_slowlog_timeout` 只有 0.3 秒，真正的慢请求淹没在阈值边缘的噪声里。

**修复**：改为绝对路径 `/www/server/php/83/var/log/slow.log`，阈值提到 1 秒，`php-fpm -t` 校验通过后 reload 生效。

### 7. 部署时 `php-fpm restart` 造成 1~2 秒 502 窗口

**根因**：`/etc/init.d/php-fpm-83 restart` 会先 SIGTERM 停掉全部 worker 再起新的，期间回源请求直接 502。生产日志里 144 条 502 全部落在这类重启窗口内。

**约定**：部署改配置/代码后用 `reload`（SIGHUP，平滑重载），不要用 `restart`。

## 配套增强

新增 `App\Http\Middleware\RecordRequestTiming`，挂在 api 中间件栈最外层：

- 每个 API 响应带 `X-Response-Time-Ms`，压测与运维可直接读到服务端耗时，不必再靠 access log 反推（nginx 未记录 `$request_time`）；
- 耗时超过 `app.slow_request_threshold_ms`（默认 1000，可用 `SLOW_REQUEST_THRESHOLD_MS` 调）时写一条 `[慢请求]` 警告，路径里的纯数字段归一化为 `{id}` 便于聚合。

它只做两次 `microtime()`，不查库、不解析 body。

## 验证

- `php artisan test`：2170 passed / 3 skipped / 0 failed（本次改动后全量回归）
- 生产实测：`/api/v2/site/home`、`/api/v2/site/config`、`/api/v2/site/products/{id}/stock`、`/api/v2/site/products/{id}` 均 200，热态 90~140ms
- 修复后 `GET /api/v2/client/finance/ledger?tab=invoices` 返回 200
- 上游网络失败时日志中不再出现 `Undefined variable $http_response_header`，改为可读的连接错误

## 未处理 / 待跟进

- `/zjmf_api_login` 累计约 3.7 万次 404（`/seo/www/zjmf_api_login` 另 1 万余次）：外部扫描器持续探测魔方财务的登录端点，不是本站缺陷；如需止噪应在 nginx/WAF 层直接 444。
- 生产 `laravel.log` 的 1.2GB 存量需要在装机上手工截断或等 logrotate 首轮切分（写文件与删除操作由运维执行）。
- 慢请求的真实分布要等 slowlog 与 `[慢请求]` 日志积累一段时间后再定。
