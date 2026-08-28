# TuraIDC VitePress 文档官网维护指南

本文是 `docs-web/` 文档官网的统一维护入口，说明日常维护、构建与发布方式。文档官网是独立的 VitePress 应用，读取 `docs/` 作为内容源，不复制文档，也不参与三个业务前端的构建和部署。

## 目录职责

| 路径                                                          | 职责                                                         |
| ------------------------------------------------------------- | ------------------------------------------------------------ |
| `docs/`                                                       | 项目长期文档的唯一内容源。                                   |
| `docs-web/`                                                   | VitePress 应用、主题、首页组件与静态品牌资源。               |
| `docs-web/.vitepress/config.ts`                               | 站点元信息、导航、侧栏、URL 重写和中文标题约束。             |
| `docs-web/.vitepress/theme/Layout.vue`                        | 首页与默认文档布局的切换入口（挂载 Nolebase 增强阅读面板）。 |
| `docs-web/.vitepress/theme/components/HomePortal.vue`         | 文档官网首页（深浅色流光与入场动效）。                       |
| `docs-web/.vitepress/theme/components/DeploymentSelector.vue` | 快速开始页的生产部署方式选择器。                             |
| `docs-web/public/branding/`                                   | Logo 与 favicon 等原样复制的静态资源。                       |

不要在 `docs-web/` 中复制 Markdown 正文。正文始终直接维护在 `docs/`，VitePress 通过 `srcDir: "../docs"` 读取。

## 本地启动

在项目根目录安装工作区依赖：

```cmd
npm install
```

启动开发服务：

```cmd
npm run dev:docs -- --host 127.0.0.1 --port 5176
```

浏览器访问 `http://127.0.0.1:5176/`。开发服务支持内容和主题热更新。

## 内容维护规则

### 中文内容与英文 URL

- 文档正文、文章一级标题、导航文字和侧栏文字保持中文。
- `docs/` 下的目录名和文件名必须使用 ASCII 英文；Markdown 文件名使用 `kebab-case`。
- Markdown 内部链接必须指向英文路径，不得重新引用迁移前的中文路径。
- 每篇文章必须包含中文一级标题，例如 `# 宝塔部署项目指南`。
- 页面标题与侧栏文章名称从文章一级标题读取，不从英文文件名生成。
- 不为旧中文 URL 增加重定向或运行时兼容层。

新增页面示例：

```text
docs/references/operations/reverse-proxy.md
```

```markdown
# 反向代理配置指南

正文内容……
```

对应页面 URL 为 `/references/operations/reverse-proxy`，页面显示标题仍为“反向代理配置指南”。

### 索引页规则

目录索引使用 `README.md` 或 `index.md`。`docs-web/.vitepress/config.ts` 会把目录中的 `README.md` 重写为 `index.md`，因此：

- `docs/references/README.md` 对应 `/references/`；
- `docs/designs/index.md` 对应 `/designs/`；
- 普通 Markdown 文件去掉 `.md` 后形成页面 URL。

### 侧栏目录名称

侧栏文章名称自动读取中文一级标题。侧栏中的目录分组使用 `directoryTitles` 映射保持中文显示。

新增会进入自动侧栏的子目录时，必须同时在 `docs-web/.vitepress/config.ts` 的 `directoryTitles` 中登记中文名称。遗漏登记会使构建直接失败，避免把 `active`、`backend` 等英文目录名暴露给读者。

### 自动生成文档

`docs/generated/` 中的内容只能通过对应脚本刷新，不能手工修改。后端 API 清单使用：

```cmd
php backend/scripts/export_api_inventory.php
```

刷新后执行文档检查和官网构建。

## 导航与首页维护

主导航和侧栏结构在 `docs-web/.vitepress/config.ts` 中维护。当前一级架构为：

1. 快速开始
2. 开发指南
3. 系统架构
4. API 文档
5. 系统运维

新增导航入口时使用英文链接，显示文字保持中文。不要通过硬编码英文文件名生成读者可见标题。

首页内容在 `HomePortal.vue` 中维护。修改首页时同步检查：

- 桌面端与移动端首屏没有内容重叠或文字溢出；
- Hero 仍以 TuraIDC 为核心，品牌图片从 `/branding/` 读取；
- 首页、导航和快捷入口使用英文 URL；
- 主题色流光保持静态、柔和，并在四周平滑淡出；
- 无障碍名称、键盘焦点和外部链接属性保持完整。

快速开始页的部署方式入口由 `DeploymentSelector.vue` 提供。新增部署方式时，应先在 `docs/references/operations/` 建立生产部署文档，再增加入口。

## 构建与验证

文档内容变更至少执行：

```cmd
npm run docs:check
```

官网配置、主题、组件、静态资源或路径变更还必须执行：

```cmd
npm run build:docs
```

生产构建输出位于：

```text
docs-web/.vitepress/dist/
```

需要本地检查生产产物时执行：

```cmd
npm run preview --workspace turaidc-docs-web -- --host 127.0.0.1 --port 4173
```

提交前至少人工抽查首页、快速开始、系统架构、API 清单和一篇深层目录文章，并确认：

- 页面 URL 全部为英文；
- 文章标题和侧栏显示为中文；
- 站内链接没有 `404`；
- Logo、favicon 和首页入口正常加载；
- 移动端没有横向滚动或元素遮挡。

## 独立构建与部署

文档官网与业务前端独立存在：

- `npm run build:frontends` 只构建 `frontend-admin-v3/`、`frontend-user-v3-www/` 和 `frontend-user-v4-console/`；
- `npm run build:docs` 只构建 `docs-web/`；
- 文档产物不会写入三个业务前端的 `dist/`，也不会写入 `backend/public/`；
- 发布系统应把 `docs-web/.vitepress/dist/` 作为独立静态站点产物部署。

当前站点链接和品牌资源按域名根路径配置。若部署到 `/docs/` 等子路径，必须先在 VitePress 配置中设置 `base`，并同步验证首页组件中的站内链接和静态资源路径。

静态服务器应支持无扩展名 URL。使用 Nginx 时，需要让 `/quick-start` 等请求能够命中对应 HTML 页面或按静态托管平台的 clean URL 规则处理。

`docs-web/package-lock.json` 必须入库。EdgeOne Pages 等独立构建环境的构建命令应使用 `npm ci` 精确还原依赖：无锁安装曾导致线上产物缺失 chunk 引用（`framework.*.js` 404 → 页面水合报 `shallowRef` 错误）。修改 `docs-web` 依赖后需在 `docs-web/` 下重新 `npm install` 刷新该 lock 并一并提交。

## 常见问题

### 构建提示文档缺少中文一级标题

确认 Markdown 中存在以 `# ` 开头的中文一级标题。文件名为英文是正常的，不要把文章标题改成英文来匹配文件名。

### 构建提示目录缺少中文侧栏标题

在 `docs-web/.vitepress/config.ts` 的 `directoryTitles` 中为新增目录登记中文显示名称，然后重新构建。

### 新页面返回 404

依次检查文件是否位于 `docs/`、链接是否去掉 `.md`、路径大小写是否一致，以及静态服务器是否支持 clean URL。目录首页还需检查 `README.md` 或 `index.md` 的重写规则。

### 文档检查通过但页面入口不可见

`docs:check` 负责文档结构和链接校验，不会自动决定主导航信息架构。需要在 `docs-web/.vitepress/config.ts`、`HomePortal.vue` 或对应页面组件中增加入口。

### 修改没有出现在生产站点

确认发布流程执行的是 `npm run build:docs`，并部署了最新的 `docs-web/.vitepress/dist/`。`npm run build:frontends` 不会构建或发布文档官网。

## 发布检查清单

1. 更新 `docs/` 正文、索引和相关路径引用。
2. 必要时更新 VitePress 导航、目录中文映射或首页入口。
3. 执行 `npm run docs:check`。
4. 执行 `npm run build:docs`。
5. 预览生产产物并抽查英文 URL、中文标题和移动端布局。
6. 独立发布 `docs-web/.vitepress/dist/`，不要混入业务前端产物。

维护过程中不得把密码、Token、私钥、生产数据或测试账号凭据写入正文、配置、构建日志或静态资源。
