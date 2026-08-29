# 后端 API 清单导航

## 1. 文档用途

- 给 `docs/generated/api/backend-api-catalog.md` 这份自动生成清单提供一份人类可读的业务导航
- 对齐时间：`2026-07-20`
- 本文手工维护，不会被导出脚本覆盖
- 具体方法、控制器动作、中间件和鉴权仍以 `docs/generated/api/backend-api-catalog.md` 为准

## 2. 分组速览

| 分组              | 路径范围                                 | 默认鉴权/特征                                                                                       |
| ----------------- | ---------------------------------------- | --------------------------------------------------------------------------------------------------- |
| 管理端            | `/api/v2/admin/*`                        | 除 `/api/v2/admin/login` 外，默认 `auth:sanctum + ensure.admin`，多数接口叠加 `permission:{code}`   |
| 用户端            | `/api/v2/client/*`                       | 公开认证、回调、VNC Token 和实名回调混在同一前缀下；其余主体接口默认 `auth:sanctum + ensure.client` |
| 公开站点          | `/api/v2/site/*`                         | 官网、公开产品、公开内容、报价、站点配置                                                            |
| 其他公开/受控接口 | `/api/health`、`/api/secure-assets/view` | 健康检查或按资源参数校验访问                                                                        |

## 3. 业务域速览

### 管理端（admin）

| 业务域         | 关键路径前缀                                                                                                                    | 说明                                                             |
| -------------- | ------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------- |
| 认证           | `/api/v2/admin/login`、`/api/v2/admin/auth/*`                                                                                   | 管理员登录、信息、资料更新、退出                                 |
| 仪表盘         | `/api/v2/admin/dashboard*`                                                                                                      | 首页指标与最近账单                                               |
| 用户           | `/api/v2/admin/users*`                                                                                                          | 用户列表、详情、充值、代登录、嵌套账单/服务/日志/工单            |
| 账单           | `/api/v2/admin/invoices*`、`/api/v2/admin/users/{user}/invoices*`                                                               | 当前主财务实体是发票/账单，不再以订单路由为主                    |
| 商品           | `/api/v2/admin/products*`、`/api/v2/admin/product-categories*`、`/api/v2/admin/product-groups*`、`/api/v2/admin/product-types*` | 商品、分类、分组、类型、批量同步与排序                           |
| 服务实例       | `/api/v2/admin/services*`、`/api/v2/admin/users/{user}/services*`                                                               | 管理端实例概览与用户下实例操作                                   |
| 供应商         | `/api/v2/admin/suppliers*`                                                                                                      | 供应商余额、商品拉取、批量对接                                   |
| 集成插件       | `/api/v2/admin/integration-plugins*`                                                                                            | 支付、实名、短信、邮件、上游插件扫描、安装、配置、启停、健康检查 |
| 优惠券         | `/api/v2/admin/coupons*`、`/api/v2/admin/coupon-campaigns*`                                                                     | 优惠券与活动发券                                                 |
| 推荐返佣       | `/api/v2/admin/referral*`、`/api/v2/admin/referral-withdrawals*`                                                                | 返佣概览、奖励、账变、提现审核                                   |
| 实名认证       | `/api/v2/admin/verifications*`                                                                                                  | 实名审核、详情、历史、解绑                                       |
| 工单           | `/api/v2/admin/tickets*`                                                                                                        | 工单列表、回复、关闭、指派、图片上传                             |
| 内容与媒体     | `/api/v2/admin/content*`、`/api/v2/admin/media-files*`                                                                          | 文章分类、文章内容、媒体库                                       |
| 日志           | `/api/v2/admin/logs*`                                                                                                           | API、短信、邮件、任务、系统、登录日志与清理                      |
| 设置与站点运营 | `/api/v2/admin/settings`、`/api/v2/admin/site/home-hero`                                                                        | 系统配置、站点首页 Hero                                          |
| 调度           | `/api/v2/admin/schedules*`                                                                                                      | 调度总览与手动触发                                               |
| 会员等级       | `/api/v2/admin/member-levels*`                                                                                                  | 等级配置                                                         |

### 用户端（client）

| 业务域   | 关键路径前缀                                                                                          | 说明                                                      |
| -------- | ----------------------------------------------------------------------------------------------------- | --------------------------------------------------------- |
| 认证     | `/api/v2/client/login`、`/api/v2/client/register`、`/api/v2/client/auth/*`、`/api/v2/client/password` | 登录、注册、找回密码、资料、通知偏好、支付宝账号          |
| 实名认证 | `/api/v2/client/verification*`                                                                        | 状态、初始化、二维码、重试、回调、扫码                    |
| 账单     | `/api/v2/client/invoices*`                                                                            | 当前用户侧下单与支付主实体是发票                          |
| 充值     | `/api/v2/client/recharge*`                                                                            | 充值下单与状态轮询                                        |
| 服务实例 | `/api/v2/client/services*`、`/api/v2/client/vnc-tokens/*`                                             | 实例详情、监控、续费、重装、VNC、NAT、安全组、流量包      |
| 余额     | `/api/v2/client/balance-logs*`                                                                        | 余额流水和汇总                                            |
| 优惠券   | `/api/v2/client/coupons*`                                                                             | 优惠券、汇总、领取；其中 `coupons/public*` 仍需客户端鉴权 |
| 推荐返佣 | `/api/v2/client/referral*`                                                                            | 概览、奖励、账变、提现申请                                |
| 工单     | `/api/v2/client/tickets*`                                                                             | 列表、详情、回复、关闭、上传图片                          |
| 内容     | `/api/v2/client/content/overview`、`/api/v2/client/notices*`、`/api/v2/client/help-articles*`         | 用户侧公告与帮助中心                                      |
| 支付回调 | `/api/v2/client/payment/alipay/notify`                                                                | 支付宝异步通知                                            |

### 公开站点与其他公开接口

| 业务域       | 关键路径前缀                                                                                                               | 说明                              |
| ------------ | -------------------------------------------------------------------------------------------------------------------------- | --------------------------------- |
| 站点配置     | `/api/v2/site/config`、`/api/v2/site/home`、`/api/v2/site/home-hero`                                                       | 官网基础配置、首页聚合、首页 Hero |
| 公开商品     | `/api/v2/site/product-types`、`/api/v2/site/product-groups*`、`/api/v2/site/product-categories*`、`/api/v2/site/products*` | 官网商品浏览、库存、报价          |
| 公开内容     | `/api/v2/site/content/overview`、`/api/v2/site/notices*`、`/api/v2/site/help-articles*`                                    | 官网公告与帮助中心                |
| 健康检查     | `/api/health`                                                                                                              | 服务可用性探针                    |
| 受控资源查看 | `/api/secure-assets/view`                                                                                                  | 资源查看，不属于普通公开静态资源  |

## 4. 核心业务流程 -> 关键接口

### 官网选购 -> 创建账单 -> 支付 -> 开通

| 步骤         | 接口                                                 |
| ------------ | ---------------------------------------------------- |
| 浏览商品     | `GET /api/v2/site/products`                          |
| 查看详情     | `GET /api/v2/site/products/{productId}`              |
| 获取报价     | `POST /api/v2/site/products/{productId}/quote`       |
| 创建账单     | `POST /api/v2/client/invoices`                       |
| 余额支付     | `POST /api/v2/client/invoices/{id}/pay/balance`      |
| 支付宝支付   | `POST /api/v2/client/invoices/{id}/pay/alipay`       |
| 查询支付状态 | `GET /api/v2/client/invoices/{id}/pay/alipay/status` |
| 查看账单详情 | `GET /api/v2/client/invoices/{id}`                   |
| 查看服务实例 | `GET /api/v2/client/services/{id}`                   |

### 服务续费

| 步骤         | 接口                                      |
| ------------ | ----------------------------------------- |
| 查看续费预览 | `GET /api/v2/client/services/{id}/renew`  |
| 生成续费账单 | `POST /api/v2/client/services/{id}/renew` |
| 后续支付     | 复用 `/api/v2/client/invoices/{id}/pay/*` |

### 流量包加购

| 步骤           | 接口                                                       |
| -------------- | ---------------------------------------------------------- |
| 查看流量包列表 | `GET /api/v2/client/services/{id}/traffic-packages`        |
| 获取加购报价   | `POST /api/v2/client/services/{id}/traffic-packages/quote` |
| 创建加购单     | `POST /api/v2/client/services/{id}/traffic-packages/order` |

### 实名认证

| 步骤         | 接口                                                                                    |
| ------------ | --------------------------------------------------------------------------------------- |
| 查询状态     | `GET /api/v2/client/verification/status`                                                |
| 获取费用配置 | `GET /api/v2/client/verification/fee-config`                                            |
| 初始化实名   | `POST /api/v2/client/verification/init`                                                 |
| 获取二维码   | `POST /api/v2/client/verification/qrcode`                                               |
| 重试流程     | `POST /api/v2/client/verification/restart`                                              |
| 异步回调     | `GET /api/v2/client/verification/callback`、`POST /api/v2/client/verification/callback` |
| 管理端查询   | `GET /api/v2/admin/verifications`                                                       |
| 管理端历史   | `GET /api/v2/admin/verifications/{user}/history`                                        |

### 工单

| 步骤       | 接口                                         |
| ---------- | -------------------------------------------- |
| 用户提交   | `POST /api/v2/client/tickets`                |
| 用户回复   | `POST /api/v2/client/tickets/{id}/reply`     |
| 用户关闭   | `POST /api/v2/client/tickets/{id}/close`     |
| 管理端列表 | `GET /api/v2/admin/tickets`                  |
| 管理端回复 | `POST /api/v2/admin/tickets/{ticket}/reply`  |
| 管理端指派 | `POST /api/v2/admin/tickets/{ticket}/assign` |

### 内容与媒体

| 步骤                    | 接口                                      |
| ----------------------- | ----------------------------------------- |
| 官网公告列表            | `GET /api/v2/site/notices`                |
| 官网帮助列表            | `GET /api/v2/site/help-articles`          |
| 用户侧内容总览          | `GET /api/v2/client/content/overview`     |
| 管理端文章列表          | `GET /api/v2/admin/content/articles`      |
| 管理端上传封面/正文图片 | `POST /api/v2/admin/content/upload-image` |
| 管理端媒体库            | `GET /api/v2/admin/media-files`           |

### 站点运营配置

| 步骤          | 接口                                |
| ------------- | ----------------------------------- |
| 拉取系统配置  | `GET /api/v2/admin/settings`        |
| 保存系统配置  | `POST /api/v2/admin/settings`       |
| 查看首页 Hero | `GET /api/v2/admin/site/home-hero`  |
| 更新首页 Hero | `POST /api/v2/admin/site/home-hero` |

## 5. 维护建议

- 新增或移除接口后，先执行 `php backend/scripts/export_api_inventory.php` 重刷 `docs/generated/api/backend-api-catalog.md`
- 如果业务主实体变化，例如从 `orders` 切到 `invoices`，必须同步更新本文的“业务域速览”和“核心业务流程”
- 新增公开接口时，同时检查它属于：
  - 站点公开
  - 客户端前缀下的公开认证能力
  - 回调接口
  - 受控资源接口
