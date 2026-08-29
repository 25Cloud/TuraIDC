---
status: current
updated: 2026-08-29
owner: frontend
---

# 视觉与交互规范

本文是三端视觉 token、布局与交互的当前约束；实现与本文冲突时，按工作规则处理并回写本文。视觉基线是浅色企业级云服务控制台 + 清爽官网，不做深色大屏后台。

## 1. 总体定位

当前前端分工：

- `frontend-admin-v3`：管理端，TDesign Vue Next。
- `frontend-user-v3-www`：官网、登录注册和用户入口，Element Plus。
- `frontend-user-v4-console`：新版用户控制台，TDesign Vue Next。

## 2. 通用视觉 Token

- 品牌主色：`#165DFF`
- 主色 hover：`#0E4FCC`
- 页面背景：`#F5F7FB`
- 二级背景：`#F8FAFC`
- 卡片背景：`#FFFFFF`
- 边框：`#E5EAF3`
- 分割线：`#EEF2F7`
- 主文本：`#1F2937`
- 次文本：`#5B6B82`
- 占位文本：`#94A0B2`
- 成功：`#12B76A` / 浅底 `#EAFBF3`
- 警告：`#F59E0B` / 浅底 `#FFF6E5`
- 危险：`#F04438` / 浅底 `#FFF1F0`

排版使用系统字体栈：`Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif`。

## 3. 布局原则

- 控制台页面以浅灰背景承载白色内容区，信息优先于装饰。
- 页面区块间距按 8px 栅格，常用间距 `16px` / `20px` / `24px`。
- 卡片只用于承载独立业务内容、重复项、弹窗和表单区块，不把页面 section 层层套卡片。
- 状态统一用浅底标签，不直接使用高饱和纯色文本。
- 表格表头用浅灰底，行高保持稳定，金额列可右对齐。
- 按钮高度常用 `36px` - `40px`；主操作蓝底白字，次操作白底描边。

## 4. 管理端 `frontend-admin-v3`

- 使用 TDesign 组件、TDesign Icons、TDesign Starter 布局和 `src/style/` Less 体系。
- 普通管理列表页不要新增说明型页头大卡片，不做 `Hero + 三卡片` 公式。
- 列表页从筛选、指标、工具栏或表格卡片开始；详情页的返回、保存、刷新放紧凑工具栏。
- 表单页必须有校验、提交 loading、禁用态和错误反馈。
- 权限、路由、菜单、面包屑、用户菜单沿用现有 layout/store/router 模式。
- 禁止混入 Element Plus 组件、图标和样式。

## 5. 官网与用户入口 `frontend-user-v3-www`

- 使用 Element Plus 与 `src/assets/styles/variables.scss`、`global.scss`、`element/index.scss`。
- 官网首页、产品页、登录页可以有更强视觉表达，但要保持可读、真实、业务导向。
- 用户中心、账单、服务、工单等高频业务页仍使用浅背景 + 白卡片 + 品牌蓝操作。
- 购买和结算流程不要改成营销页布局，优先清晰的配置、价格、优惠和确认状态。

## 6. 用户控制台 `frontend-user-v4-console`

- 使用 TDesign 组件、TDesign Icons、`src/style/` Less 体系和 `shared/user-v3` 控制台组件。
- 控制台页面重视扫描效率：清晰导航、稳定列表、明确状态、紧凑操作。
- 详情、账单、服务控制台、工单聊天等页面优先复用 `PageScaffold`、`DataState`、`StatusTag`、抽屉/弹窗组件。
- 禁止官网式大 Hero、深色大屏、重渐变背景和纯装饰卡片。

## 7. 图标与素材

- TDesign 端：只用 `tdesign-icons-vue-next`。
- Element Plus 端：只用 `@element-plus/icons-vue`。
- 不用 emoji 代替功能图标。
- 品牌与公共素材优先放 `public/branding/` 或按语义归类到 `public/img/`。

## 8. 禁止项

- 禁止把后台默认做成深色大屏风格。
- 禁止在普通列表页使用官网式 Hero 横幅。
- 禁止整页铺满高饱和渐变、玻璃拟态、发光装饰。
- 禁止同一页面混用多套圆角、阴影、主色和 UI 框架。
- 禁止把业务页面做成只展示说明文案的营销卡片。

## 关联文档

- [前端项目规范](FRONTEND.md)：三端工程约束与验证命令。
- [架构现状说明](ARCHITECTURE.md)：系统结构与运行模型。
