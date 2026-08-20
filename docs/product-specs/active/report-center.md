# 管理员后台报表中心方案

> 版本：v2
> 日期：2026-07-05
> 状态：待复核，未排期

---

## 一、概述

在管理端侧边栏新增「报表中心」一级菜单（权限码 `finance.report`，已有），包含 5 个子页面，覆盖经营概览、商品销售、充消分析、续费分析、即将到期预警。每个页面支持**本月（逐日）、近12月（逐月）、区间汇总**三个数据层次。

## 当前实现边界（2026-07-22）

- 已有后端能力：`/api/v2/admin/dashboard/monthly-revenue`、`/api/v2/admin/finance/new-customer-daily-summary`、`/api/v2/admin/finance/product-income-summary`。
- 管理端已有经营仪表盘与新增客户页面；尚未发现商品销售、充值与消费、续费分析、即将到期服务这四个专页及对应完整 API。
- 本文其余内容是待评审的产品规格，实施前应拆分为执行计划并重新核对路由、数据口径和权限。

---

## 二、菜单结构

```
📊 报表中心 (finance.report)
├── 📈 经营概览          /admin/overview
├── 🛒 商品销售分析       /admin/product-sales
├── 💰 充值与消费        /admin/charge-consumption
├── 🔄 续费分析          /admin/renewal-analysis
└── ⏰ 即将到期服务       /admin/expiring-services
```

---

## 三、通用交互规范

每个报表页顶部统一筛选区：

```
[快捷切换: 本月 | 近3月 | 近6月 | 近12月]  [自定义日期范围]  [查询]
```

行为：

| 选择             | 图表粒度 | 明细表   | 汇总行 |
| ---------------- | -------- | -------- | :----: |
| 本月             | 逐日     | 每日一行 |   ✅   |
| 近 N 月 / 自定义 | 逐月     | 每月一行 |   ✅   |

明细表末行固定为**合计行**（加粗 + `t-table` 的 `foot` 插槽或行样式区分）。

---

## 四、各页面详细设计

### 4.1 经营概览

**定位：** 老板/运营一眼看核心指标与趋势。

**数据来源：** 复用 `dashboard/stats`、`dashboard/monthly-revenue`、`new-customer-daily-summary`。

| 区块               | 本月视图                                         | 近12月视图                                               |
| ------------------ | ------------------------------------------------ | -------------------------------------------------------- |
| 汇总卡片（4-5 个） | 本月营业额、本月新增客户、本月订单数、活跃服务数 | 近12月累计营业额、累计新增客户、月均营业额、月均新增客户 |
| 趋势图             | 本月逐日营收折线                                 | 近12月逐月营收 + 新增客户双轴图                          |
| 明细表             | 日期、新增客户、新增订单、营业额（每日）         | 月份、新增客户、新增订单、营业额（逐月）+ **合计行**     |

**新增后端：** 无。全部复用现有 API。

---

### 4.2 商品销售分析

**定位：** 看清卖什么赚钱、收入结构。

**数据来源：** 增强 `product-income-summary`。

| 区块     | 本月视图                                           | 近12月视图                                   |
| -------- | -------------------------------------------------- | -------------------------------------------- |
| 汇总卡片 | 本月新购收入、续费收入、总销量                     | 近12月累计新购收入、累计续费收入、累计总销量 |
| 分类饼图 | 本月按一级商品分组收入占比                         | 近12月按一级分组累计收入占比                 |
| 趋势图   | 本月逐日收入折线                                   | 近12月逐月新购 vs 续费堆叠柱状图             |
| 明细表   | 商品排行：商品名、新购收入、续费收入、销量（本月） | 按月展开或按商品排行 + **合计行**            |

**后端：** 增强现有 `productIncomeSummary`，新增 `year` 参数返回逐月数据。

响应结构：

```json
{
  "code": 0,
  "data": {
    "range": { "start": "2026-01", "end": "2026-07" },
    "summary": {
      "new_income": "12800.00",
      "new_quantity": 34,
      "renew_income": "9600.00",
      "renew_quantity": 28,
      "total_amount": "22400.00",
      "total_quantity": 62
    },
    "monthly": [
      {
        "month": "2026-01",
        "new_income": "1500.00",
        "new_quantity": 5,
        "renew_income": "1200.00",
        "renew_quantity": 4,
        "total_amount": "2700.00",
        "total_quantity": 9
      }
    ],
    "list": [
      {
        "product_id": 1,
        "product_name": "云服务器-标准版",
        "product_type": "vps",
        "new_income": "5000.00",
        "new_quantity": 12,
        "renew_income": "4000.00",
        "renew_quantity": 10,
        "total_amount": "9000.00",
        "total_quantity": 22
      }
    ]
  }
}
```

---

### 4.3 充值与消费

**定位：** 资金流入流出全景。

**数据来源：** 新增 `charge-consumption-monthly`。

| 区块     | 本月视图                             | 近12月视图                                |
| -------- | ------------------------------------ | ----------------------------------------- |
| 汇总卡片 | 本月充值总额/笔数、消费总额/笔数     | 近12月累计充值/消费总额、月均充值/消费    |
| 趋势图   | 本月逐日充值 vs 消费双折线           | 近12月逐月充值 vs 消费双柱图              |
| 消费构成 | 本月消费类型环形图（新购/续费/升级） | 近12月按类型堆叠柱状图                    |
| 明细表   | 每日充值额/笔数、消费额/笔数         | 逐月充值额/笔数、消费额/笔数 + **合计行** |

**新增 API：** `GET /api/v2/admin/charge-consumption-monthly`

参数：`year`（如 `2026`）或 `start_date` + `end_date`

查询逻辑：

- 充值：`payments` 表，`status=1`，`gateway_key=alipay`，按 `paid_at` 聚合 `amount` 和 `COUNT`
- 消费：`invoices` 表，`status=1`，按 `paid_at` + `type` 聚合 `paid_amount` 和 `COUNT`

---

### 4.4 续费分析

**定位：** 续费是 SaaS 生命线。

**数据来源：** 新增 `renewal-monthly`。

| 区块     | 本月视图                           | 近12月视图                                                           |
| -------- | ---------------------------------- | -------------------------------------------------------------------- |
| 汇总卡片 | 本月续费台数、续费金额、续费用户数 | 近12月累计续费台数/金额/用户数                                       |
| 续费率   | 本月到期台数 → 续费台数 → 续费率   | 近12月逐月续费率折线                                                 |
| 趋势图   | 本月逐日续费台数                   | 近12月逐月续费台数 + 金额双轴图                                      |
| 明细表   | 每日续费明细                       | 逐月续费：月份、到期台数、续费台数、续费率(%)、续费金额 + **合计行** |

**新增 API：** `GET /api/v2/admin/renewal-monthly`

参数：`year`（如 `2026`）或 `start_date` + `end_date`

查询逻辑：

- 续费台数：`invoices` 表 `type=renew`，`status=1`，按 `paid_at` 按月聚合，对 `service_id` 去重计数
- 续费金额：同上，聚合 `paid_amount`
- 续费用户数：同上，对 `user_id` 去重计数
- 到期台数：续费订单关联的 `service_id` 去重计数（近似值）

> **注意：** "到期台数"无法从当前 `services.expires_at` 精确反推（续费后已更新为未来时间），此处用续费订单关联的 `service_id` 去重作为近似值，后续可考虑新增 `service_expiry_log` 表做精确追踪。

---

### 4.5 即将到期服务

**定位：** 运营主动触达，减少流失。

**数据来源：** 新增 `expiring-services`。

| 区块       | 内容                                                                     |
| ---------- | ------------------------------------------------------------------------ |
| 统计卡片   | 7 天内到期台数、30 天内到期台数、60 天内到期台数                         |
| 按商品分布 | 各商品到期台数柱状图（取 30 天内维度）                                   |
| 明细表     | 用户邮箱（脱敏）、服务名、商品名、到期时间、状态，按到期时间升序，带分页 |
| 操作       | 每行："查看用户"、"查看服务"链接                                         |

**无近12月视图**（此页向前看，不做历史回顾；历史到期趋势见 4.4 续费分析页）。

**新增 API：** `GET /api/v2/admin/expiring-services`

参数：`days`（7/30/60）、`page`、`page_size`

查询逻辑：`services` 表 `status=1`（运行中），`expires_at BETWEEN now() AND now()+N days`。

---

## 五、后端新增 API 汇总

| #   | 方法  | 路径                                       | 说明                    | 权限             | 新建/增强 |
| --- | ----- | ------------------------------------------ | ----------------------- | ---------------- | :-------: |
| 1   | `GET` | `/api/v2/admin/product-income-summary`     | 商品收入逐月 + 汇总     | `finance.report` |   增强    |
| 2   | `GET` | `/api/v2/admin/charge-consumption-monthly` | 充消逐月 + 汇总         | `finance.report` |   新建    |
| 3   | `GET` | `/api/v2/admin/renewal-monthly`            | 续费逐月 + 汇总         | `finance.report` |   新建    |
| 4   | `GET` | `/api/v2/admin/expiring-services`          | 即将到期服务列表 + 统计 | `finance.report` |   新建    |

> 4 个接口全部挂 `permission:finance.report`（已有），无需新增权限码。

---

## 六、前端新增文件

```
frontend-admin-v3/src/
├── pages/
│   ├── overview/                  # 经营概览（新建）
│   │   └── index.vue
│   ├── product-sales/             # 商品销售分析（新建）
│   │   └── index.vue
│   ├── charge-consumption/        # 充值与消费（新建）
│   │   └── index.vue
│   ├── renewal-analysis/          # 续费分析（新建）
│   │   └── index.vue
│   └── expiring-services/         # 即将到期服务（新建）
│       └── index.vue
├── api/
│   └── financeReport.ts           # 报表 API 封装（新建）
└── router/modules/admin/
    └── finance.ts                 # 路由注册（修改）
```

图表使用项目现有 `echarts` 依赖；不新增第二套图表库。

菜单图标：`ChartIcon`、`ShopIcon`、`MoneyCircleIcon`、`RefreshIcon`、`TimeIcon`（均来自 `tdesign-icons-vue-next`）。

---

## 七、后端文件规划

```
backend/app/
├── Http/Controllers/Admin/
│   └── FinanceReportController.php          # 新建：汇聚报表 API
├── Http/Requests/Admin/FinanceReport/
│   ├── ChargeConsumptionMonthlyRequest.php   # 新建
│   ├── RenewalMonthlyRequest.php             # 新建
│   └── ExpiringServicesRequest.php           # 新建
├── Services/
│   └── AdminFinanceReportService.php         # 新建：报表查询服务
└── Support/
    └── AdminPermissions.php                  # 无需修改（finance.report 已存在）
```

路由注册在 `backend/routes/v2-admin.php`，挂到现有 `permission:finance.report` 组。

---

## 八、数据维度矩阵

| 页面         | 本月（逐日） | 近12月（逐月） |   区间汇总   | 明细表合计行 |
| ------------ | :----------: | :------------: | :----------: | :----------: |
| 经营概览     |      ✅      |       ✅       | ✅ 累计+月均 |      ✅      |
| 商品销售分析 |      ✅      |       ✅       |      ✅      |      ✅      |
| 充值与消费   |      ✅      |       ✅       | ✅ 累计+月均 |      ✅      |
| 续费分析     |      ✅      |       ✅       |   ✅ 累计    |      ✅      |
| 即将到期服务 | ✅ 前向预警  |       —        |      —       |     分页     |

---

## 九、实施顺序

| 优先级 | 页面         | 理由                                     |
| :----: | ------------ | ---------------------------------------- |
|   P0   | 商品销售分析 | 后端 API 已有基础，仅需增强 + 前端从零建 |
|   P1   | 经营概览     | 全部复用现有 API，纯前端组合             |
|   P1   | 即将到期服务 | 运营刚需，1 个新 API                     |
|   P2   | 续费分析     | 1 个新 API                               |
|   P2   | 充值与消费   | 1 个新 API                               |

---

## 十、注意事项

- 管理端列表页不新增"头部说明卡片"，直接从筛选区和指标卡片开始（§6 规则）。
- 金额列表格右对齐，状态统一用 `shared/statusConfig.js` 浅底标签。
- 涉及用户邮箱的列表需走 `AdminPrivacy` 脱敏。
- 所有图表使用品牌色 `#165DFF` 体系，与页面风格一致。
- 不引入第二套 UI 库或图表库。
- `productIncomeSummary` 增强需兼容旧调用方式（`month` / `start_date+end_date` 参数继续有效）。
