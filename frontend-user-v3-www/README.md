# 二五云IDC财务系统 · 官网门户（frontend-user-v3-www）

官网门户前端应用，负责品牌展示与购买入口。

> 完整项目文档见根目录 [README](../../README.md)，部署指南见 [DEPLOYMENT.md](../../DEPLOYMENT.md)。

- 文档性质：现行方案 / 官网与购买入口部署说明
- 对齐时间：2026-06-28
- 读者对象：负责官网与购买入口构建、部署的前端 / 运维同学

## 站点基础配置

用户端官网的基础展示信息由管理端维护：
- 站务 -> `基础信息`：维护站点名、浏览器标题、Logo、Favicon、客服联系方式与协议链接
- 站务 -> `首页 Banner`：维护官网首页首屏内容

## 构建命令

```bash
cd frontend-user-v3-www
npm run build
```

## 部署提示

Nginx SPA 回退：

```nginx
location / {
    try_files $uri $uri/ $uri.html /index.html;
}
```

静态资源预压缩：

```nginx
location /assets/ {
    gzip_static on;
    brotli_static on;
    expires 1y;
    add_header Cache-Control "public, immutable";
}
```

## 验证要求

```bash
cd frontend-user-v3-www
npm run build
```
