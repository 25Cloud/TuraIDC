---
status: tech-debt
updated: 2026-08-26
owner: backend-platform
---

# 上游目录接口的库存字段不可信（仅记录，未修复）

- 文档性质：缺陷记录与实测证据，**不含改动**
- 涉及代码：[`ProductSyncService::syncUpstreamProductStocks`](../../../backend/app/Services/ProductCatalog/ProductSyncService.php)、[`ZjmfCatalogService`](../../../backend/plugins/servers/zjmf_finance/lib/ZjmfCatalogService.php)
- 相关基线：[MySQL 版本兼容基线](../../references/database/mysql-version-compatibility.md)
- 当前状态：**只记录、不动手**。缺陷源自开源初始提交，处置权留给上游维护者。

## 背景

某方（ZJMF）的商品目录接口 `/cart/all` 会为每个商品返回 `stock_control` / `qty` / `stock`，看上去可以用一次请求拿到全部商品的库存。但实测证明**这批字段不可信**。

2026-08-26 在生产镜像站用已配置的上游（供应商「极点」，473 个商品）实测：

| 项目                   | 结果                                                            |
| ---------------------- | --------------------------------------------------------------- |
| `/cart/all` 单次请求   | 570 ms，返回 473 个商品，全部带 `stock` 字段                    |
| `/cart/all` 的判定     | 473 个**全部**报 `stock_control=0`（即"不限量"，归一化为 `-1`） |
| 与商品详情接口交叉比对 | 均匀抽样 24 个，**14 个不一致（58%）**                          |

不一致样本（`catalog` = `/cart/all`，`config` = `/cart/get_product_config`）：

```
id=494  catalog=-1  config=76   香港2区CN2 8H8G
id=571  catalog=-1  config=0    美国3区9929 2H1G     ← 实际已售罄
id=775  catalog=-1  config=0    十堰电信.高防.WY2     ← 实际已售罄
id=980  catalog=-1  config=10   宁波电信高防 32H48G
id=536  catalog=-1  config=18   德阳云电脑 A型
id=763  catalog=-1  config=97   十堰 NAT全能云 C型 -Gold
```

**危险方向**：把限量商品误判为不限量，其中两个实际已售罄。`normalizeCatalogStock()` 在 `stock_control != 1` 时一律返回 `-1`，而列表接口根本不返回真实的 `stock_control`，于是全部落入"不限量"分支。

## 影响面

**批量对接导入路径**（`ProductSyncService.php` 中 `'stock' => $this->resolveRemoteCatalogStock($supplierProduct)`）取的正是 catalog 的值，因此**从批量对接界面导入的商品，初始库存几乎必然是 `-1`（不限量），哪怕上游实际只剩 0 件**。

缓解现状（均已存在，无需额外改动）：

- 下单时 `assertProductCanBeProvisioned()` 走 strict 实时校验，用的是**商品详情接口**，库存不足会直接抛「该商品库存不足，无法继续下单」，**不会真的卖超**；
- 新导入商品的 `stock_synced_at` 为空，排在定时库存同步的队首，第一轮（15 分钟内）即被详情接口纠正。

因此实际风险窗口是：**导入后到首轮同步之间，商品列表页可能显示错误的库存数字**。

## 归属

| 项                 | 结论                                         |
| ------------------ | -------------------------------------------- |
| 引入提交           | `21d6bd0` chore: 开源初始化二五云IDC财务系统 |
| 时间 / 作者        | 2026-08-10，上游原始开发者                   |
| 是否由后续 PR 引入 | 否                                           |

同一提交中的 `resolvePreferredRemoteStock()` 明确实现了"优先商品详情、目录仅兜底"的优先级，说明原作者知晓目录数据不够可靠；但导入路径单独调用了 `resolveRemoteCatalogStock()`，绕开了该优先级。从代码无法判断这是疏漏还是为导入速度所做的取舍。

## 建议修法（未执行）

批量对接本就会逐商品拉取配置项（`fetchBatchProductConfigOptions`），真实库存已在该响应内，导入时改为从中取值即可，**不新增任何上游请求**。

未执行的原因：缺陷属上游原始代码，按既定约定"发现上游冗余或缺陷只记录、不动手，把处置权留给上游维护者"处理。

## 进度

- 2026-08-26：实测确认并记录，未做任何代码改动。

## 决策日志

- 2026-08-26：确认缺陷来自开源初始提交而非后续 PR（`git log -S` 验证）；评估后决定只记录不修复，避免在与本缺陷无关的任务中改动上游原始逻辑。
