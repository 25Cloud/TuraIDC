# 工作规则

## 基本原则

- 使用 CMD 且保持 UTF-8；先读与任务相关的文档和现有代码，再修改。
- 保持最小改动，不做无关重构；无调用方的废弃代码不保留兼容层。
- 工作区可能是脏的，绝不回滚或覆盖不是当前任务造成的改动。
- 临时内容仅放系统临时目录并在结束时清理；禁止用输出重定向把临时结果写入仓库。
- 密钥、密码、Token、私钥和生产数据不得进入文档、提交或日志；测试凭据通过受控渠道取得。

## 事实与优先级

冲突时依次以运行代码与测试、数据库 `information_schema`、自动生成文档、当前设计/规格、参考资料为准。视觉实现还受 [DESIGN.md](../DESIGN.md) 约束。

## 修改边界

- 后端控制器保持薄层；上游调用进入专用 Service、Driver 或插件，禁止 Controller 直接 `Http::*`。
- 支付记录只改状态，不物理删除；迁移只新增，不补跑历史激进迁移。
- 插件特有逻辑、路由、调度、中间件和语言文件必须收敛在 `backend/plugins/{domain}/{slug}/`，不得污染系统级扩展点。
- 三端 UI 框架不可混用：admin-v3 与 v4-console 用 TDesign，v3-www 用 Element Plus。
- 管理端不加头部说明卡片；v4-console 财务页面不加指标卡片。
- 自动生成 API 清单不得手工编辑；修改路由后运行生成脚本。

## 验证与提交

- 文档改动运行 `pnpm run docs:check`；必要时运行 `pnpm run docs:freshness`。
- 后端改动运行受影响的 `php artisan test`；前端按受影响应用运行 `pnpm run build`，重构按各应用规范补充 `verify:refactor`。
- 每个子任务先展示改动与验证摘要；得到用户确认后独立提交，格式为 `Fix:中文描述`、`Feat:中文描述` 或 `Refactor:中文描述`。

具体工程约束从 [BACKEND.md](../BACKEND.md)、[FRONTEND.md](../FRONTEND.md)、[DATABASE.md](../DATABASE.md) 和 [参考索引](../参考资料/README.md) 按需进入。
