/**
 * 官网首页的唯一文案源。
 *
 * 事实口径来自仓库 README 与 docs/：能力清单、许可与安全对比都必须与
 * 这两处一致。改文案时先改 README 的事实，再回到这里同步，避免站点
 * 与产品文档长期漂移。
 */

export const SITE_URL = "https://www.turaidc.com";
export const DOCS_URL = "https://docs.turaidc.com";
export const REPO_URL = "https://github.com/25Cloud/TuraIDC";
export const CONTACT_MAIL = "contact@turaidc.com";

export const SITE = {
  name: "TuraIDC 图拉云",
  title: "TuraIDC 图拉云 · 开源 IDC / 云服务业务财务系统",
  description:
    "TuraIDC 是面向 IDC 与云服务商的开源业务财务系统：商品、订单、计费、自动开通、财务、工单、分销返佣一个系统跑完。Laravel 12 + Vue 3，三端分离，插件化对接上游，可自托管、可审计。",
  edition: "AGPL-3.0-or-later · 全量开源",
};

export const NAV_LINKS = [
  { text: "能力", href: "#features" },
  { text: "闭环", href: "#flow" },
  { text: "安全", href: "#security" },
  { text: "部署", href: "#deploy" },
  { text: "定价", href: "#pricing" },
  { text: "FAQ", href: "#faq" },
];

export const HERO = {
  eyebrow: "开源 IDC 业务系统",
  titleLead: "开源",
  titleAccent: "IDC",
  titleTail: "业务与财务系统",
  lead: "商品、订单、计费、自动开通、财务、工单、分销返佣——过去拿 Excel 补、拿脚本补单的活，现在一个系统跑完。",
  subLead:
    "Laravel 12 + Vue 3，管理端、官网门户、用户控制台三端分离；上游、支付、短信、验证码全部插件化。自托管、可审计、不留后门。",
  primaryCta: { text: "阅读文档", href: DOCS_URL },
  secondaryCta: { text: "GitHub 源码", href: REPO_URL },
  ghostCta: {
    text: "从魔方财务迁移 →",
    href: `${DOCS_URL}/guide/migrate/zjmf-finance`,
  },
};

export const HERO_STATS = [
  { value: "3", unit: "端", label: "管理端 / 官网 / 用户控制台" },
  { value: "8", unit: "大", label: "商品、订单、财务、用户等业务域" },
  {
    value: "6",
    unit: "类",
    label: "供应商 / 支付 / 短信 / 验证码 / 实名 / 邮件",
  },
  { value: "0", unit: "元", label: "开源版永久免费，功能不阉割" },
];

export const FEATURES = [
  {
    icon: "shop",
    title: "商品与计费",
    desc: "产品分组、配置项、月付季付年付多周期、自动续费、库存与代理折扣，价格一律服务端重算。",
  },
  {
    icon: "task",
    title: "订单与开通",
    desc: "下单、支付回调、上游自动开通、失败重试、到期挂起与释放，全链路留痕。",
  },
  {
    icon: "money",
    title: "财务",
    desc: "余额账户、充值、发票、退款、对账与账务流水，金额按分累加，不用浮点。",
  },
  {
    icon: "user",
    title: "用户体系",
    desc: "注册登录、实名认证、会员等级、分销返佣、推广提现，外加账号与 IP 维度的登录风控。",
  },
  {
    icon: "ticket",
    title: "工单",
    desc: "部门分配、超时自动关闭、图片上传防护，还能把工单转发给上游处理。",
  },
  {
    icon: "notification",
    title: "通知",
    desc: "站内信、邮件、短信模板可配置，渠道插件化，发送失败有重试与日志。",
  },
  {
    icon: "time",
    title: "自动化",
    desc: "调度任务、心跳监控、定时对账、日志归档，少盯一眼也不会漏单。",
  },
  {
    icon: "layers",
    title: "插件机制",
    desc: "供应商、支付、短信、验证码、实名、邮件六类插件各自独立，换上游不用改内核。",
  },
];

export const FLOW_STEPS = [
  { icon: "shop", title: "商品配置", desc: "分组、配置项、周期、库存" },
  { icon: "wallet", title: "下单支付", desc: "报价令牌 + 服务端重算" },
  { icon: "rocket", title: "自动开通", desc: "上游插件下发，失败重试" },
  { icon: "refresh", title: "计费续费", desc: "到期提醒、自动续费、升降配" },
  { icon: "chart", title: "财务对账", desc: "流水、退款、发票、月度营收" },
  { icon: "ticket", title: "工单通知", desc: "超时关闭、邮件短信触达" },
];

export const SECURITY_NONE = [
  {
    title: "零元购金额篡改",
    desc: "订单与续费金额一律服务端按规则重算，客户端传的计费乘数改不动应付金额。",
  },
  {
    title: "上游凭据外泄",
    desc: "上游令牌只由服务端持有，客户端与匿名请求拿不到原始 JWT。",
  },
  {
    title: "文件任意上传",
    desc: "上传接口强制认证，校验类型、大小与配额，拒绝匿名大文件。",
  },
  {
    title: "功能锁定与后门",
    desc: "没有授权校验、没有功能阉割、没有隐藏远程控制，代码全部可审计。",
  },
  {
    title: "跨租户越权",
    desc: "资源查询与操作一律绑定当前用户所有权，越权统一返回 403 / 404。",
  },
  {
    title: "支付回调重放",
    desc: "回调幂等 + 交易号唯一约束，同一笔交易只入账、只履约一次。",
  },
  {
    title: "常见注入与伪造",
    desc: "SQL 参数化查询，不可信代理头不参与 IP 判定，Cookie 带安全属性并启用 CSRF 防护。",
  },
];

export const SECURITY_HAVE = [
  {
    title: "服务端权威计算",
    desc: "金额、库存、优惠券、续费全部服务端重算并加锁，扛得住并发和手改参数。",
  },
  {
    title: "完整审计日志",
    desc: "资金、订单、后台操作全部留痕，支持导出与归档。",
  },
  {
    title: "登录风控",
    desc: "账号 + IP 级软锁定，验证码按目标维度限流，多入口统一校验。",
  },
  {
    title: "插件化架构",
    desc: "六类插件边界清晰，链路可替换，不会绑死在一家上游上。",
  },
  {
    title: "自动化兜底",
    desc: "调度、心跳、定时对账、失败重试与自动挂起释放。",
  },
  {
    title: "开源可审计",
    desc: "AGPL-3.0-or-later，社区可自行审查、修复与二次开发。",
  },
];

export const DEPLOY_PATHS = [
  {
    icon: "terminal",
    title: "Docker Compose",
    tag: "最快",
    desc: "一台机器一条 compose，前后端、MySQL、Redis 一起起来，适合先跑通再慢慢调。",
    href: `${DOCS_URL}/guide/deploy/docker-1panel`,
    linkText: "Docker 部署文档",
  },
  {
    icon: "control",
    title: "宝塔面板",
    tag: "最常见",
    desc: "已经有宝塔的站长：建四个站点，伪静态照抄文档，PHP 与定时任务在面板里管。",
    href: `${DOCS_URL}/guide/deploy/bt-panel`,
    linkText: "宝塔部署文档",
  },
  {
    icon: "server",
    title: "裸机源码",
    tag: "最可控",
    desc: "自己管 PHP-FPM、MySQL、Redis 与 Nginx，手脚最全，也最清楚每一条请求去了哪。",
    href: `${DOCS_URL}/guide/deploy/bare-metal`,
    linkText: "裸机部署文档",
  },
];

export const MIGRATE = {
  title: "从智简魔方财务搬过来",
  desc: "产品、用户、订单、上游、实名数据有完整迁移路径；也能作为上游被魔方财务对接，旧生态不用一次性推倒。",
  points: [
    "存量数据整库迁移，迁移前后都有校验清单",
    "自带魔方财务兼容插件，可被老系统当作上游调用",
    "反过来也能把魔方财务当供应商接进来",
  ],
  href: `${DOCS_URL}/guide/migrate/zjmf-finance`,
  linkText: "查看迁移指南",
};

export const PRICING = [
  {
    name: "开源版",
    price: "0",
    unit: "元 · 永久",
    license: "AGPL-3.0-or-later",
    features: [
      "功能与商业授权版完全一致",
      "无授权校验、无功能阉割、无远程控制",
      "可自行部署、修改、审计",
      "对外提供服务时按 AGPL 提供对应源码",
    ],
    cta: { text: "看许可证说明", href: `${DOCS_URL}/guide/licensing` },
    tone: "default" as const,
  },
  {
    name: "商业授权",
    price: "200",
    unit: "元 · 每年",
    license: "闭源商用可选路径",
    features: [
      "用于闭源分发或无法履行 AGPL 源码义务的场景",
      "不改变任何功能，也不解锁额外能力",
      "适合需要合规审查的团队",
      "许可与署名要求以 LICENSE-COMMERCIAL.md 为准",
    ],
    cta: { text: "了解商业授权", href: `${DOCS_URL}/guide/licensing` },
    tone: "featured" as const,
  },
];

export const FAQ_ITEMS = [
  {
    q: "这套系统免费吗？",
    a: "开源版永久免费，许可证是 AGPL-3.0-or-later。功能不阉割，也没有授权校验和后门，代码全部可以审查。",
  },
  {
    q: "能闭源商用吗？",
    a: "需要通过商业授权（200 元 / 年）取得 AGPL 之外的许可。不走商业授权又对外提供服务时，要按 AGPL 向使用者提供完整对应源码，包括你自己的改动。",
  },
  {
    q: "从智简魔方财务能迁过来吗？",
    a: "能。产品、用户、订单、上游、实名数据都有对应的迁移路径和校验清单；系统也能作为上游被魔方财务对接，先并行跑一段时间再切。",
  },
  {
    q: "支持哪些上游和支付渠道？",
    a: "供应商、支付、短信、验证码、实名、邮件都是插件。内置智简魔方、KangHostx、易支付等，其余按插件规范自己写，不需要改内核。",
  },
  {
    q: "服务器要什么环境？",
    a: "后端 PHP 8.3+ 与 Laravel 12，数据库 MySQL 5.7.8+（推荐 8.0），缓存 Redis 6+；三端前端构建成静态产物，Nginx 直接托管。具体机器配置看部署文档。",
  },
  {
    q: "二次开发从哪下手？",
    a: "先读架构与前端规范，再挑一个业务域下手。插件统一放在 backend/plugins/{domain}/{slug}/，不要往系统级路由、调度和全局中间件里塞逻辑。",
  },
];

export const FOOTER_GROUPS = [
  {
    title: "文档",
    links: [
      { text: "快速开始", href: `${DOCS_URL}/quick-start` },
      { text: "部署与调度", href: `${DOCS_URL}/guide/deploy/scheduling` },
      { text: "接口文档", href: `${DOCS_URL}/develop/api` },
      { text: "插件开发", href: `${DOCS_URL}/develop/plugins` },
    ],
  },
  {
    title: "项目",
    links: [
      { text: "源码仓库", href: REPO_URL },
      { text: "版本发布", href: `${REPO_URL}/releases` },
      { text: "问题反馈", href: `${REPO_URL}/issues` },
      { text: "许可证说明", href: `${DOCS_URL}/guide/licensing` },
    ],
  },
  {
    title: "联系",
    links: [
      { text: CONTACT_MAIL, href: `mailto:${CONTACT_MAIL}` },
      { text: "架构说明", href: `${DOCS_URL}/develop/architecture` },
      { text: "来自魔方财务", href: `${DOCS_URL}/guide/migrate/zjmf-finance` },
    ],
  },
];
