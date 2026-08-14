# 图拉云业务/财务系统

> 新一代的IDC云服务经营系统

![License](https://img.shields.io/badge/license-Apache--2.0-blue)
![PHP](https://img.shields.io/badge/PHP-%3E%3D8.2-777BB4)
![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20)
![Vue](https://img.shields.io/badge/Vue-3-4FC08D)
![CI](https://github.com/25Cloud/TuraIDC/actions/workflows/ci.yml/badge.svg)

面向 IDC / 云服务商的业务与财务经营系统。覆盖商品管理、订单计费、自动开通、发票、充值、分销返佣、工单、通知等完整业务闭环，提供管理后台、官网门户与用户控制台三端前端。

## 功能特性

- **商品与计费**：产品分组、产品配置项、多计费周期（月付/季付/年付）、自动续费、库存管理
- **订单与开通**：下单、支付回调、自动开通（对接上游供应商）、失败重试、自动挂起/释放
- **财务**：余额账户、充值、发票、退款、对账、账务流水与明细
- **用户体系**：注册登录、实名认证（可选）、会员等级、分销与返佣、推广提现
- **工单系统**：部门分配、工单回复、超时自动关闭
- **通知**：站内信、邮件、短信（模板可配置）
- **自动化**：调度任务、心跳监控、定时对账、日志归档
- **插件机制**：供应商适配、支付网关、短信、验证码、邮件均以插件形式接入

## 技术栈

| 端 | 技术 |
| --- | --- |
| 后端 | Laravel 12（PHP 8.2+）、Sanctum、MySQL 8、Redis |
| 管理后台 | Vue 3 + TypeScript + TDesign Vue Next + Vite |
| 官网门户 | Vue 3 + JavaScript + Element Plus + Vite |
| 用户控制台 | Vue 3 + TypeScript + TDesign Vue Next + Vite |
| 共享包 | shared（跨端会话、HTTP、状态、组件） |

## 目录结构

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

## 环境要求

- PHP 8.2+（扩展：`pdo_mysql`、`redis`、`mbstring`、`openssl` 等）
- MySQL 8.0+
- Redis 6.0+（生产环境必需，分布式锁依赖 Redis）
- Composer 2.x
- Node.js 20+（构建前端）

## 快速开始（开发环境）

### 1. 后端

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
# 编辑 .env：DB_HOST / DB_PORT / DB_DATABASE / DB_USERNAME / DB_PASSWORD 等

# 初始化数据库（新库）：先导入完整结构，再执行增量迁移
mysql -u root -p finance < database/schema/mysql-schema.sql
php artisan migrate --force

# 写入系统默认配置
php artisan db:seed --class=Database\\Seeders\\SettingsSeeder

# 创建管理员（role_id 需为超级管理员角色 id）
php artisan tinker
App\Models\AdminUser::create([
    'username' => 'admin',
    'password' => 'your-strong-password',
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

## 构建前端产物

`npm run build:frontends` 会读取 `backend/.env` 中的 `APP_URL` / `FRONTEND_URL` / `CLIENT_CONSOLE_URL` / `ADMIN_URL`（四个地址必须互不相同且协议一致），依次构建三端并输出到各自 `dist/`。

## 文档

- [部署指南 DEPLOYMENT.md](./DEPLOYMENT.md)
- [贡献指南 CONTRIBUTING.md](./CONTRIBUTING.md)
- [变更记录 CHANGELOG.md](./CHANGELOG.md)

## 开源许可

本项目采用 [Apache License 2.0](./LICENSE) 开源。

> 说明：本项目管理后台与控制台基于 [TDesign Vue Next Starter](https://github.com/Tencent/tdesign-vue-next-starter) 构建，相关目录保留其 MIT 许可声明。
