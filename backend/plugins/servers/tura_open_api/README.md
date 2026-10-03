# TuraIDC 开放接口上游驱动（tura_open_api）

把上游 TuraIDC 实例的开放接口（`/api/v2/open`）封装为本系统可对接的供应商能力，
用于纯自有协议的 TuraIDC↔TuraIDC 复合对接（无限级转售链）。上游无需安装 ZJMF、
无需兼容层——只要上游开启开放接口并提供 API 密钥即可。

## 供应商配置

| 字段     | 说明                                                   |
| -------- | ------------------------------------------------------ |
| 接口地址 | 上游站点根地址，如 `https://upstream.example.com`      |
| API 密钥 | 在上游「API 密钥」页创建的完整密钥（仅创建时展示一次） |

密钥需具备 `products/orders/services/finance` 读写权限；上游账户余额要充足（开通与
续费都从上游密钥账户余额扣款）。密钥为空时前置拒服务，不会发起无效请求。

## 能力与限制

已支持：

- 商品目录导入（开放 API 商品列表 + 按周期报价补价格），并还原上游真实的三级货架分组
- 商品配置项拉取（管理端「编辑商品 → 产品配置 → 拉取模板」）：从上游站点目录取真实的
  数据中心 / 操作系统 / CPU / 内存 / 磁盘 / 带宽 / 网络类型等配置项与可选项
- 自动开通：报价 → 下单（幂等键 `tura-open-provision-{orderId}`，队列重试安全）→ 余额支付 → 轮询上游账单/服务状态直到 Active
- 续费：上游同步建单并余额支付；响应丢失场景按上游账单状态恢复（含未支付补支付）
- 状态同步：分页拉取上游服务列表，维护本地状态与到期时间
- 供应商余额查询与低余额预警（复用 SupplierBalanceService）
- 供应商卡片：展示上游站点、上游余额与最近更新时间，并提供「同步余额」（真实调用
  `/open/balance` + `/open/keys/self`）与「批量导入/对接」动作。密钥属于敏感凭据，
  卡片与列表一律不回显（含前缀）
- 续费周期过滤：可续周期由上游 `/open/services/{id}/renewals` 真实返回，上游不可达时回退本地周期
- 控制台电源/重装透传（上游开放 API 支持的范围内）

已知限制：

- 开放 API 只返回 `id/name/product_type/stock`，货架分组与商品配置项都要额外补（见下节）；
  前台未上架的商品拿不到真实分组，仍由商品名推导，无法保证与上游后台分组逐字一致
- 配置项是「按需拉取」而非导入时自动带上：批量对接出来的商品初始没有配置项，需在商品编辑页
  点一次「拉取模板」，确认后保存
- 目录导入价格按月付报价推导，上游非月付周期的折扣会丢失（本地可手工调价）
- 不支持 VNC/监控/自定义模块透传（上游开放接口未提供）
- 不支持暂停/解除暂停与重置密码：开放接口未提供对应端点，命中时会报「当前上游链路不支持该操作」
- 无采购环检测：互为上下游的配置错误靠上游余额耗尽自然终止，配置时注意
- 层级越深资金预沉淀越多（每层都要在上游预存余额），开通延迟逐层叠加

## 商品名与展示名

`products` 表**没有 `name` 列**，`Product::setNameAttribute()` 会把传进来的 `name`
直接丢掉；`Product::getNameAttribute()` 依次退化为 `custom_display_name` →
`ProductDisplayNameResolver`（由配置项派生 CPU/内存） → 「未配置规格 #ID」。

所以上游商品名必须在导入时显式写进 `custom_display_name`，否则商品列表里只有占位文案。
下游 `ProductSyncService` 这边对应三处：

- `buildBulkConnectProductPayload()` 把上游商品名同时写进 `custom_display_name`；
- 重复对接时，已经人工设过展示名的商品不覆盖；
- 定时同步发现展示名为空时，用上游目录里的商品名回填一次
  （`resolveUpstreamProductDisplayNames()`）。

## 商品分组与配置项是怎么来的

开放协议把货架分组和配置项都漏掉了：`/api/v2/open/products` 只投影
`id/name/product_type/stock`，`/products/{id}` 详情同样只有这四个字段，
`/product-groups` 直接 404。但上游**站点自己的公开目录接口**（`/api/v2/site/*`，
官网前台在用）两样都带：

| 端点                                        | 用途                                               |
| ------------------------------------------- | -------------------------------------------------- |
| `/api/v2/site/product-types`                | 一级业务类型（云服务器、游戏云、CDN…）             |
| `/api/v2/site/product-groups`               | 二级分组（分页上限 `page_size=50`）                |
| `/api/v2/site/product-groups/{id}/children` | 三级分组                                           |
| `/api/v2/site/product-groups/{id}/products` | 分组下商品，每个商品自带一/二/三级分组名           |
| `/api/v2/site/products/{id}`                | 商品详情：完整 `config_options` + 多周期 `pricing` |

这套接口无需密钥（带密钥反而可能被网关拒），且**商品 ID 与开放接口是同一套**——实测站点
目录 808 个商品 100% 落在开放接口的 1095 个里，所以可以放心拿它给开放接口的商品补分组，
下单仍旧走开放接口。

实现要点：

- 只取二级分组的 `products?level=2` 即可覆盖全部商品：返回的商品自带三级分组名，实测与
  递归到三级结果完全一致，请求数从 ~160 降到 35。
- 商品清单以开放接口为准，站点目录只补分组；站点接口报错/超时一律静默降级为名称推导，
  不会让「刷新商品」整体失败。
- 分组映射按供应商缓存 6 小时。首次同步约 30s，命中缓存约 5s。
- 前台未上架的商品（实测 287 个）不在站点目录里，仍从商品名提取机房/系列前缀兜底；
  提取不到机房语义时退化为业务类型，保证每个商品都有归属。

配置项走同一条路：`getProductConfigTemplate()` 调 `/api/v2/site/products/{id}` 取
`config_options`，规范化后回填。上游同样是 TuraIDC，所以 `field` / `option_type` /
`parameter` / `sub` 的语义与本地一致，保留原值透传，只补 `option_mode`（空值按
是否区间型推导）、`config_id`、`order`、`sub_items` 等前端要用的键——特别是不重写
`option_name`，上游的「CentOS^CentOS-7.6.1810-x64」这类「父^子」形式改写反而丢信息。

按需拉取（一次一个商品，缓存 6 小时）；上游没有站点目录接口的老实例返回空配置，
管理员手工补即可，不会报错阻断编辑。

`fetchRealConfigOptions()` / `fetchBatchProductConfigOptions()` 也已接到同一条路上。
这两个方法此前是「返回空数组」的占位实现，后果很隐蔽：

- `fetchBatchProductConfigOptions()` 是定时任务
  （`ProductSyncService::syncUpstreamProductConfigOptions()`）的唯一数据源，
  它恒返回空 → 定时同步每轮都在 `$normalizedRemoteConfigOptions === []` 处静默跳过
  （统计里只体现在 `skipped_products`），已导入商品的空配置项因此**永远补不上**。
- 配置项为空 → `ProductDisplayNameResolver` 派生不出 CPU/内存 → 商品在后台和
  控制台一律显示「未配置规格 #ID」，看起来就像上游数据根本没下来。

批量方法现按 `$chunkSize` 分批真实拉取，并接受 `$deadline`：上游慢时宁可少拉几个，
也要在定时任务的整体时间预算内收尾（调用方按 `min(240s, 剩余预算)` 传入）。
