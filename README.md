<p align="center">
  <img src="./logo.png" alt="TuraIDC Logo" width="360">
</p>

<h1 align="center">图拉云 TuraIDC 业务/财务系统</h1>

<p align="center">
  <strong>新一代 IDC / 云服务经营系统</strong> · 商品 · 订单 · 计费 · 自动开通 · 财务 · 工单 · 分销 全业务闭环
</p>

<p align="center">
  <a href="https://github.com/25Cloud/TuraIDC/releases"><img src="https://img.shields.io/github/v/release/25Cloud/TuraIDC?style=flat-square&label=Release" alt="Release"></a>
  <a href="https://github.com/25Cloud/TuraIDC/stargazers"><img src="https://img.shields.io/github/stars/25Cloud/TuraIDC?style=flat-square&label=Stars" alt="Stars"></a>
  <a href="https://github.com/25Cloud/TuraIDC/network"><img src="https://img.shields.io/github/forks/25Cloud/TuraIDC?style=flat-square&label=Forks" alt="Forks"></a>
  <a href="https://github.com/25Cloud/TuraIDC/graphs/contributors"><img src="https://img.shields.io/github/contributors/25Cloud/TuraIDC?style=flat-square&label=Contributors" alt="Contributors"></a>
  <a href="https://github.com/25Cloud/TuraIDC/issues"><img src="https://img.shields.io/github/issues/25Cloud/TuraIDC?style=flat-square&label=Issues" alt="Issues"></a>
  <a href="https://github.com/25Cloud/TuraIDC/actions/workflows/ci.yml"><img src="https://github.com/25Cloud/TuraIDC/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
  <br>
  <a href="./LICENSE"><img src="https://img.shields.io/badge/license-AGPL--3.0--or--later-blue?style=flat-square" alt="License"></a>
  <img src="https://img.shields.io/badge/PHP-%3E%3D8.3-777BB4?style=flat-square" alt="PHP">
  <img src="https://img.shields.io/badge/Laravel-12-FF2D20?style=flat-square" alt="Laravel">
  <img src="https://img.shields.io/badge/Vue-3-4FC08D?style=flat-square" alt="Vue">
  <a href="https://github.com/25Cloud/TuraIDC/commits/main"><img src="https://img.shields.io/github/last-commit/25Cloud/TuraIDC?style=flat-square&label=Last%20Commit" alt="Last Commit"></a>
</p>

<p align="center">
  <b>English</b> · <a href="docs/README.md">文档</a> · <a href="docs/DESIGN.md">设计</a> · <a href="docs/ARCHITECTURE.md">架构</a>
</p>

---

面向 IDC / 云服务商的业务与财务经营系统。覆盖商品管理、订单计费、自动开通、发票、充值、分销返佣、工单、通知等完整业务闭环，提供管理后台、官网门户与用户控制台三端前端，并通过可扩展插件机制对接上游供应商与第三方服务。

## ✨ 核心能力

| 领域          | 能力                                                                   |
| ------------- | ---------------------------------------------------------------------- |
| 🛒 商品与计费 | 产品分组、产品配置项、多计费周期（月付/季付/年付）、自动续费、库存管理 |
| 📦 订单与开通 | 下单、支付回调、自动开通（对接上游供应商）、失败重试、自动挂起/释放    |
| 💰 财务       | 余额账户、充值、发票、退款、对账、账务流水与明细                       |
| 👤 用户体系   | 注册登录、实名认证（可选）、会员等级、分销与返佣、推广提现             |
| 🎫 工单系统   | 部门分配、工单回复、超时自动关闭                                       |
| 🔔 通知       | 站内信、邮件、短信（模板可配置）                                       |
| ⚙️ 自动化     | 调度任务、心跳监控、定时对账、日志归档                                 |
| 🔌 插件机制   | 供应商适配、支付网关、短信、验证码、邮件均以插件形式接入               |

## 🔗 智简魔方财务（ZJMF Finance）双向兼容

- **对接智简魔方财务系统**：作为上游供应商接入，商品目录、库存、开通、续费、网络/安全操作等通过官方 API 完整适配。
- **支持被智简魔方财务系统原生对接（实验）**：通过内置 ZJMF Bridge 插件，为存量智简魔方财务系统提供兼容 API 与签名校验，让旧生态无缝迁移，降低替换成本。

## ❌ 我们没有

部分同类商业系统的常见问题，在本项目中刻意避免：

- **没有零元购金额篡改漏洞**：订单、续费金额一律由服务端按规则重算，客户端参数（如计费乘数）无法将应付金额改为零或负值。
- **没有对接上游不鉴权漏洞**：上游凭据仅由服务端持有，客户端与匿名请求无法获得原始上游令牌（JWT），所有上游调用经过鉴权与代理。
- **没有文件任意上传**：上传接口强制认证，并校验类型、大小与配额，拒绝匿名大文件上传与内存放大。
- **没有收费授权也没有后门**：完整开源（AGPL-3.0），无授权系统、无隐藏远程控制，代码可审计、可自行部署。
- **没有跨租户越权**：资源查询与操作一律绑定当前用户所有权，越权访问统一返回 403/404。
- **没有支付回调重放**：网关回调幂等 + 交易号唯一约束，同一笔交易只入账、只履约一次。
- **没有常见注入与伪造**：SQL 参数化查询、不可信代理头不参与 IP 判定、Cookie 带安全属性并启用 CSRF 防护。

## ✅ 我们有

- **服务端权威计算**：金额、库存、优惠券、续费全部服务端重算并加锁，防止并发与篡改。
- **完整审计日志**：关键资金、订单、后台操作留痕，支持导出与归档。
- **登录风控**：账号 + IP 级软锁定、验证码目标维度限流、多入口统一校验。
- **插件化架构**：供应商、支付、短信、验证码、实名、邮件均以插件接入，边界清晰、可替换。
- **自动化兜底**：调度任务、心跳监控、定时对账、失败重试与自动挂起/释放。
- **开源可审计**：AGPL-3.0-or-later 许可，无授权收费，社区可自行审查、修复与二次开发。

## 🏗️ 架构总览

```mermaid
flowchart TB
    subgraph Frontend["前端三端（Vue 3 + Vite）"]
        A[管理后台<br/>admin-v3]
        B[官网门户<br/>user-v3-www]
        C[用户控制台<br/>user-v4-console]
    end

    subgraph Backend["Laravel 12 后端（REST API）"]
        D[API 层<br/>V1 / V2]
        E[业务服务层<br/>订单 / 财务 / 开通 / 工单]
        F[插件引擎<br/>供应商 / 支付 / 短信 / 验证码 / 邮件]
        G[调度与队列<br/>Queue / Schedule]
    end

    subgraph Data["数据层"]
        H[(MySQL 8)]
        I[(Redis 6+)]
    end

    Frontend -->|HTTPS| D
    D --> E
    E --> F
    E --> G
    E --> H
    G --> H
    F --> H
    F --> I
    G --> I

    subgraph Upstream["上游生态（插件）"]
        J[智简魔方]
        K[KangHostx]
        L[易支付]
        M[……]
    end

    F <--> Upstream
```

## 🧩 技术栈

| 端         | 技术                                            |
| ---------- | ----------------------------------------------- |
| 后端       | Laravel 12（PHP 8.3+）、Sanctum、MySQL 8、Redis |
| 管理后台   | Vue 3 + TypeScript + TDesign Vue Next + Vite    |
| 官网门户   | Vue 3 + JavaScript + Element Plus + Vite        |
| 用户控制台 | Vue 3 + TypeScript + TDesign Vue Next + Vite    |
| 共享包     | shared（跨端会话、HTTP、状态、组件）            |

## 🤝 生态与合作伙伴

系统通过插件机制对接的成熟服务商生态：

| 类别       | 已适配                                         |
| ---------- | ---------------------------------------------- |
| 上游供应商 | 智简魔方财务（ZJMF）、KangHostx                |
| 支付网关   | 支付宝（AliPay）、易支付（EPay）               |
| 短信服务   | 阿里云短信（Aliyun）、Stay33                   |
| 验证码     | 极验（Geetest）、Vaptcha                       |
| 实名认证   | 百度人脸（BaiduFace）、LeafFace、Smapi、Stay33 |
| 邮件       | SMTP、多 SMTP 轮询（MultiSmtpRoundRobin）      |
| 桥接扩展   | ZJMF Bridge（存量智简魔方数据兼容）            |

## 🗺️ Roadmap

当前版本进行中的工作（详见 `docs/执行计划/进行中/`）：

- 后端专家团审查修复计划（认证、财务、并发安全域）收尾
- 定时任务体系重构方案
- 日志归档系统可靠性重构方案
- 数据库专家团审查修复计划
- 调度 VNC 上游开通续费插件审查修复
- 报表中心方案
- 日志检索与归档协同
- 短信验证码链路完善
- 告警通道接入（待产品/运维确认渠道）

## 📊 项目热度

<a href="https://www.star-history.com/?repos=25Cloud%2FTuraIDC&type=date&legend=top-left">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="https://api.star-history.com/chart?repos=25Cloud/TuraIDC&type=date&theme=dark&legend=top-left&sealed_token=DLZeeKcw05mIrh2HqJHHcah_CJlwXqq0_AW-LpSPtWsFoVE6Px46XG3BoaMrvOmP-N-Pcp22-B-fQna7PXyJum3iYhdzpsJD0xsfA7tCvxXDdFS3-MSOeg" />
    <source media="(prefers-color-scheme: light)" srcset="https://api.star-history.com/chart?repos=25Cloud/TuraIDC&type=date&legend=top-left&sealed_token=DLZeeKcw05mIrh2HqJHHcah_CJlwXqq0_AW-LpSPtWsFoVE6Px46XG3BoaMrvOmP-N-Pcp22-B-fQna7PXyJum3iYhdzpsJD0xsfA7tCvxXDdFS3-MSOeg" />
    <img alt="Star History Chart" src="https://api.star-history.com/chart?repos=25Cloud/TuraIDC&type=date&legend=top-left&sealed_token=DLZeeKcw05mIrh2HqJHHcah_CJlwXqq0_AW-LpSPtWsFoVE6Px46XG3BoaMrvOmP-N-Pcp22-B-fQna7PXyJum3iYhdzpsJD0xsfA7tCvxXDdFS3-MSOeg" />
  </picture>
</a>

## 🧑‍💻 贡献者

[![Contributors](https://contrib.rocks/image?repo=25Cloud/TuraIDC)](https://github.com/25Cloud/TuraIDC/graphs/contributors)

## 📁 目录结构

```
TuraIDC/
├── backend/                  # Laravel 后端
│   ├── app/                  # 业务代码（模型/服务/命令/中间件）
│   ├── config/               # 配置（含可选集成占位）
│   ├── database/
│   │   ├── schema/           # 完整结构基线 mysql-schema.sql（新环境初始化）
│   │   ├── migrations/       # 增量迁移
│   │   └── seeders/          # 系统默认配置
│   ├── plugins/              # 插件（供应商/支付/短信/验证码/邮件）
│   ├── routes/               # api.php / v2-admin.php / v2-client.php / web.php
│   └── scripts/              # 构建与运维脚本
├── frontend-admin-v3/        # 管理后台（端口 5174）
├── frontend-user-v3-www/     # 官网门户（端口 5175）
├── frontend-user-v4-console/ # 用户控制台（端口 5173）
└── shared/                   # 跨前端共享包
```

## 🔧 环境要求

- PHP 8.3+（扩展：`pdo_mysql`、`redis`、`mbstring`、`openssl`、`zip` 等）
- MySQL 8.0+
- Redis 6.0+（生产环境必需，分布式锁依赖 Redis）
- Composer 2.x
- Node.js 20+（构建前端）

## 🚀 快速开始（开发环境）

### 1. 后端

```bash
cd backend
cp .env.example .env
# 编辑 .env：DB_HOST / DB_PORT / DB_DATABASE / DB_USERNAME / DB_PASSWORD 等
composer install
php artisan key:generate

# 初始化数据库（新库）：先导入完整结构，再执行增量迁移
mysql -u root -p finance < database/schema/mysql-schema.sql
php artisan migrate --force

# 写入系统默认配置
php artisan db:seed --class=Database\\Seeders\\SettingsSeeder

# 创建管理员（role_id 需为超级管理员角色 id）
php artisan tinker
App\Models\AdminUser::create([
    'username' => 'admin',
    'password' => 'password',
    'role_id'  => 1,
    'nickname' => '管理员',
    'status'   => 1,
]);

# 启动开发服务（默认 127.0.0.1:8000）
php artisan serve
```

### 2. 前端

```bash
# 仓库根目录安装依赖（npm workspaces）
npm install

# 分别启动三端（开发端口：官网 5175 / 控制台 5173 / 管理端 5174）
npm run dev:user-v3-www
npm run dev:user-v4-console
npm run dev:admin-v3
```

### 3. 后台进程

生产环境需常驻两个进程（详见 [DEPLOYMENT.md](./DEPLOYMENT.md)）：

```bash
php artisan queue:work --queue=provision,referral,notification,coupon,default --sleep=1 --tries=3 --timeout=1200
php artisan schedule:work
```

## 🏗️ 构建前端产物

`npm run build:frontends` 会读取 `backend/.env` 中的 `APP_URL` / `FRONTEND_URL` / `CLIENT_CONSOLE_URL` / `ADMIN_URL`（四个地址必须互不相同且协议一致），依次构建三端并输出到各自 `dist/`。

## 📚 文档

- [部署指南 DEPLOYMENT.md](./DEPLOYMENT.md)
- [贡献指南 CONTRIBUTING.md](./CONTRIBUTING.md)
- [安全政策 SECURITY.md](./SECURITY.md)
- [行为准则 CODE_OF_CONDUCT.md](./CODE_OF_CONDUCT.md)
- [变更记录 CHANGELOG.md](./CHANGELOG.md)

## 📄 开源许可

本项目采用 [GNU Affero General Public License v3.0 或更高版本（AGPL-3.0-or-later）](./LICENSE) 开源。

> 说明：本项目管理后台与控制台基于 [TDesign Vue Next Starter](https://github.com/Tencent/tdesign-vue-next-starter) 构建；TuraIDC 自有代码采用 AGPL-3.0-or-later，TDesign 原始代码及其许可声明继续采用 MIT，详见各子目录的 `LICENSE`。
