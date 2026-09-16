---
status: 进行中
updated: 2026-09-16
owner: backend
---

# 绑定表写入放大治理（binlog 收敛）

## 背景

生产环境观察到 `supplier_plugin_bindings` 与 `product_upstream_bindings` 两张表
被程序每隔几秒整表重写一遍，每天产生约 9GB binlog。空间暂时充足，但写入压力持续存在。

排查结论（定位到具体代码路径，非数据库配置问题）：

1. **无条件写**：`UpstreamBindingWriter::syncSupplierBinding()` /
   `syncProductBinding()` 用 `updateOrInsert` 无条件重写整行，调用方是
   「确保绑定存在」这类幂等语义——`ProductSyncService::syncUpstreamProductStocks()`
   每个心跳槽（15 分钟）为全部已绑定商品回写一次库存快照，
   `ServiceUpstreamBindingWriter::resolveProductBindingId()` 在每次服务详情访问、
   每轮状态同步、每次电源操作时都会调一次。
2. **写放大**：`syncProductBinding()` 内部又先调 `syncSupplierBinding()`，
   于是同一行供应商绑定会被「每个商品一次」地重写（473 个商品即 473 次/轮）。
3. **密文不确定性**：`secret_json` 用 `Crypt::encryptString` 每次重新加密，
   随机 IV 让同一份明文每次密文都不同，即便加了逐列比对也永远判成「有变化」。
4. **时间戳驱动写入**：`last_synced_at` / `synced_at` / `checked_at`
   每次调用都写 `now()`，与嵌套在 JSON 快照里的同名时间戳一起，
   让「数据没变」的调用也必然是内容不同的行写入。
5. **同类放大**：同一调用路径还会无条件重写 `service_upstream_bindings`、
   `service_runtime_snapshots`、`service_connection_snapshots` 三张表。

MySQL 的 binlog 是行格式且 `binlog_row_image=FULL`：只要发出 UPDATE，
即便只改一个时间戳，也要记录整行的前后镜像，因此这些「无变化写入」的成本
与真实变更完全一致。

## 范围与验收

范围：把上述写入路径改成变更感知，业务数据真正变化时才发 UPDATE。

非目标：不改动 `services` 表的状态同步写入（见「未覆盖与后续」）。

- [x] 供应商绑定：数据未变化时不产生 UPDATE。
- [x] 供应商绑定：密钥明文未变时复用原密文，密文不再因随机 IV 变化。
- [x] 商品绑定：仅库存同步时间戳前移时不产生 UPDATE；库存变化时正常落库。
- [x] 服务绑定与两张快照表：业务字段未变时不产生 UPDATE；状态变化时正常落库。
- [x] 回归测试固化「无变化不写库」，并在修复前确认会失败。
- [x] 全量测试与基线失败集合零差异。

## 实施步骤

1. 新增 `App\Services\Integrations\Plugins\Concerns\PersistsBindingRowsOnChange`：
   逐列比对、易变时间戳（列级与 JSON 内嵌）不参与比对、密钥明文未变时复用密文。
2. 改造 `UpstreamBindingWriter::syncSupplierBinding()` / `syncProductBinding()`：
   先按唯一键取现有行，行不存在才插入，存在则仅在业务列变化时 UPDATE。
3. 改造 `ServiceUpstreamBindingWriter::syncServiceState()` /
   `syncRuntimeSnapshot()` / `syncConnectionSnapshot()`：同一套规则。
4. 新增 `tests/Feature/BindingChangeAwareWriteTest.php` 固化上述行为。

## 风险与回滚

- 语义变化：`last_synced_at` / `updated_at` 不再随每次同步前移，
  含义变为「最近一次数据变化时间」。已核对：三端前端均未展示这两个字段，
  管理端商品详情只在接口里暴露 `last_synced_at`，无消费方。
- 比对口径：JSON 列按解码后的结构做宽松比较（`1` 与 `'1'` 视为同一份数据），
  顺序无关；空值 `null`/`''`/`[]` 视为等价。误判方向偏向「少写」，
  因此对每个判定都补了「真变化必须写」的反向断言。
- 回滚：改动集中在两个写入口与一个 trait，`git revert` 单个提交即可恢复原行为，
  无数据结构变更、无迁移。
- 监控：上线后观察 `mysqlbinlog` 中两张表的 UPDATE 频次与 `SHOW BINARY LOGS`
  增长速度是否回落到与真实库存变化同量级。

## 未覆盖与后续

- `ServiceStatusSyncService::syncService()` 每轮对每个服务执行
  `$service->forceFill([...])->save()`，其中 `provision_data` 内嵌
  `last_synced_at` / `last_status_sync_at` / `connection_cached_at` 每次都写 `now()`，
  `services` 表因此同样存在无变化重写。该行为可能是刻意的（`updated_at` 用于展示
  「最近同步」），改为按变化写入会影响展示语义，需产品侧确认后再动。
- `BackfillServiceUpstreamBindingsCommand` 与 `PluginDataBackfillService` 仍是
  `updateOrInsert`，但它们只由人工执行的一次性回填命令驱动，不构成周期性放大。

## 进度

- [x] 定位放大来源并量化调用频率与行写成本。
- [x] 抽出变更感知写入能力（trait）。
- [x] 改造两个绑定写入器（5 张表）。
- [x] 回归测试固化，并确认修复前失败、修复后通过。
- [x] 全量测试基线对比。

## 决策日志

| 日期       | 决策                                     | 原因                                                                                            |
| ---------- | ---------------------------------------- | ----------------------------------------------------------------------------------------------- |
| 2026-09-16 | 用「易变字段白名单」而非「仅比对时间戳」 | 时间戳既在列上也在 JSON 快照内嵌，逐处特判会散落各调用方；白名单集中在 trait 里可一次说清口径。 |
| 2026-09-16 | 密钥列改为明文比对后复用密文             | 随机 IV 让密文每次不同，是逐列比对失效的直接原因；重新加密只在明文真的变化时发生。              |
| 2026-09-16 | 不引入时间戳节流刷新                     | 三端均未展示绑定表时间戳，节流只是折中；直接按「真变化才写」实现更简单，也无隐藏写入。          |
| 2026-09-16 | 服务级三张表一并改造                     | 它们与服务绑定同属一条调用路径，只修两张表会让放大从商品绑定平移到服务绑定，问题不收敛。        |
| 2026-09-16 | 放宽 JSON 比对为宽松相等                 | 上游回传 `1` 与本地存的 `'1'` 是同一份数据，严格比对会把类型差异当成变化，反而重新引入写入。    |
