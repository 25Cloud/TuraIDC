# 构建与发布

本目录（管理后台前端）作为独立静态站点发布。

## 构建

在仓库根目录执行：

```bash
npm run build:admin-v3
```

构建脚本 `backend/scripts/build_frontends.mjs` 会读取 `backend/.env` 注入构建环境变量，产物输出至本目录 `dist/`。

## 发布

- 将 `dist/` 目录部署到 Nginx / CDN 静态站点，SPA 需配置历史路由回退：

```nginx
location / {
    try_files $uri $uri/ /index.html;
}
```

- 详细部署步骤见根目录 [DEPLOYMENT.md](../../DEPLOYMENT.md)。
