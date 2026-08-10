# 二五云IDC财务系统 · 用户控制台（frontend-user-v4-console）

用户控制台前端应用，负责用户登录、服务管理、订单、发票、财务充值、工单等自助服务。

> 本文档为该子项目说明。完整项目文档见根目录 [README](../../README.md)，部署指南见 [DEPLOYMENT.md](../../DEPLOYMENT.md)，变更记录见 [CHANGELOG.md](../../CHANGELOG.md)。

## 技术栈

- Vue 3.5 / TypeScript / Vite 6
- TDesign Vue Next（UI 组件库）
- Pinia / Vue Router / ECharts
- 共享包：`@ewyfinance/shared`（本仓库 monorepo workspace）

## 开发

```bash
# 在仓库根目录安装依赖后
npm run dev:user-v4-console

# 或进入本目录单独启动
cd frontend-user-v4-console && npm run dev
```

开发端口为 5173（见 vite.config.ts）。

## 构建

```bash
# 在仓库根目录执行，构建脚本会读取 backend/.env 注入环境变量
npm run build:user-v4-console

# 或一次性构建全部前端
npm run build:frontends
```

构建产物输出至本目录 `dist/`，作为独立静态站点部署。

## 许可

本项目基于 [TDesign Vue Next Starter](https://github.com/Tencent/tdesign-vue-next-starter) 二次开发，本目录保留其 [MIT 许可](./LICENSE)。整体项目采用 Apache-2.0（见根目录 [LICENSE](../../LICENSE)）。
