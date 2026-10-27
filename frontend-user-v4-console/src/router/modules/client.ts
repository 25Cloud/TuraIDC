import {
  ApiIcon,
  CatalogIcon,
  CouponIcon,
  DashboardIcon,
  FileIcon,
  GiftIcon,
  HelpCircleIcon,
  LockOnIcon,
  NotificationIcon,
  ServerIcon,
  ServiceIcon,
  UserCircleIcon,
  VerifiedIcon,
  WalletIcon,
} from 'tdesign-icons-vue-next';
import { shallowRef } from 'vue';
import type { RouteRecordRaw } from 'vue-router';

import AiRobotAvatar from '@/components/ai/AiRobotAvatar.vue';
import Layout from '@/layouts/index.vue';

// zh_HK 缺省跟随 zh_TW：香港用繁体，缺这一项时面包屑与菜单会回落到简体。
const title = (zhCN: string, enUS = zhCN, zhTW = zhCN, zhHK = zhTW) => ({
  zh_CN: zhCN,
  zh_TW: zhTW,
  zh_HK: zhHK,
  en_US: enUS,
});
const icon = (component: unknown) => shallowRef(component);

// 「账户设置」整组页面共用一个动态 import，打包器会合并成同一个 chunk，
// 组内切换只加载一次。详见 pages/client/account/index.ts 的说明。
const loadAccountPage = () => import('@/pages/client/account');

export default [
  {
    path: '/',
    redirect: '/client/dashboard',
  },
  {
    path: '/client/login',
    name: 'ClientLogin',
    component: () => import('@/pages/client-auth/login.vue'),
    meta: { title: title('用户登录', 'Login', '使用者登入'), guest: true, robots: 'noindex,nofollow' },
  },
  {
    path: '/client/login-as',
    name: 'ClientLoginAs',
    component: () => import('@/pages/client-auth/login-as.vue'),
    meta: { title: title('代登录', 'Login As', '代理登入'), guest: true, robots: 'noindex,nofollow' },
  },
  {
    path: '/client/register',
    name: 'ClientRegister',
    component: () => import('@/pages/client-auth/register.vue'),
    meta: { title: title('用户注册', 'Register', '使用者註冊'), guest: true, robots: 'noindex,nofollow' },
  },
  {
    path: '/client/forgot-password',
    name: 'ClientForgotPassword',
    component: () => import('@/pages/client-auth/forgot-password.vue'),
    meta: { title: title('找回密码', 'Forgot Password', '找回密碼'), guest: true, robots: 'noindex,nofollow' },
  },
  {
    path: '/client',
    component: Layout,
    redirect: '/client/dashboard',
    meta: {
      title: title('用户控制台', 'Client Console', '使用者控制台'),
      requireAuth: true,
      role: 'client',
      robots: 'noindex,nofollow',
    },
    children: [
      {
        path: '/client/overview',
        redirect: '/client/dashboard',
        meta: { title: title('首页', 'Home', '首頁'), icon: icon(DashboardIcon), requireAuth: true, orderNo: 10 },
        children: [
          {
            path: '/client/dashboard',
            name: 'ClientDashboard',
            component: () => import('@/pages/client/dashboard/index.vue'),
            meta: {
              title: title('控制台', 'Console', '控制台'),
              icon: icon(DashboardIcon),
              requireAuth: true,
              robots: 'noindex,nofollow',
              orderNo: 10,
            },
          },
        ],
      },
      {
        path: '/client/products',
        redirect: '/client/services',
        meta: {
          title: title('商品与服务', 'Products', '商品與服務'),
          icon: icon(ServerIcon),
          requireAuth: true,
          orderNo: 20,
        },
        children: [
          {
            path: '/client/services',
            name: 'ClientServices',
            component: () => import('@/pages/client/services/index.vue'),
            meta: {
              title: title('我的实例', 'My Instances', '我的實例'),
              icon: icon(ServerIcon),
              requireAuth: true,
              orderNo: 10,
            },
          },
          {
            path: '/client/catalog',
            name: 'ClientCatalog',
            component: () => import('@/pages/client/catalog/index.vue'),
            meta: {
              title: title('购买商品', 'Buy Products', '購買商品'),
              icon: icon(CatalogIcon),
              requireAuth: true,
              orderNo: 20,
            },
          },
        ],
      },
      {
        path: '/client/trade',
        redirect: '/client/orders',
        meta: { title: title('交易记录', 'Trade', '交易記錄'), icon: icon(FileIcon), requireAuth: true, orderNo: 30 },
        children: [
          {
            path: '/client/orders',
            name: 'ClientOrders',
            component: () => import('@/pages/client/orders/index.vue'),
            meta: {
              title: title('订单记录', 'Order Records', '訂單記錄'),
              icon: icon(FileIcon),
              requireAuth: true,
              orderNo: 10,
            },
          },
          {
            path: '/client/invoices',
            name: 'ClientInvoices',
            component: () => import('@/pages/client/invoices/index.vue'),
            meta: {
              title: title('账单记录', 'Invoice Records', '賬單記錄'),
              icon: icon(FileIcon),
              requireAuth: true,
              orderNo: 20,
            },
          },
        ],
      },
      {
        path: '/client/wallet',
        redirect: '/client/recharge',
        meta: {
          title: title('钱包与充值', 'Wallet', '錢包與充值'),
          icon: icon(WalletIcon),
          requireAuth: true,
          orderNo: 40,
        },
        children: [
          {
            path: '/client/recharge',
            name: 'ClientRecharge',
            component: () => import('@/pages/client/recharge/index.vue'),
            meta: {
              title: title('账户充值', 'Top Up', '賬戶充值'),
              icon: icon(WalletIcon),
              requireAuth: true,
              orderNo: 10,
              keepAlive: false,
            },
          },
          {
            path: '/client/payments',
            name: 'ClientPayments',
            component: () => import('@/pages/client/payments/index.vue'),
            meta: {
              title: title('充值记录', 'Top-up Records', '充值記錄'),
              icon: icon(WalletIcon),
              requireAuth: true,
              orderNo: 20,
            },
          },
        ],
      },
      {
        path: '/client/promo',
        redirect: '/client/coupons',
        meta: {
          title: title('优惠与活动', 'Promotions', '優惠與活動'),
          icon: icon(CouponIcon),
          requireAuth: true,
          orderNo: 50,
        },
        children: [
          {
            path: '/client/coupons',
            name: 'ClientCoupons',
            component: () => import('@/pages/client/coupons/index.vue'),
            meta: {
              title: title('优惠券中心', 'Coupons', '優惠券中心'),
              icon: icon(CouponIcon),
              requireAuth: true,
              orderNo: 10,
            },
          },
          {
            path: '/client/referral',
            name: 'ClientReferral',
            component: () => import('@/pages/client/referral/index.vue'),
            meta: {
              title: title('推荐奖励', 'Referral Rewards', '推薦獎勵'),
              icon: icon(GiftIcon),
              requireAuth: true,
              orderNo: 20,
            },
          },
        ],
      },
      {
        path: '/client/support',
        redirect: '/client/tickets',
        meta: {
          title: title('帮助与支持', 'Help & Support', '幫助與支援'),
          icon: icon(ServiceIcon),
          requireAuth: true,
          orderNo: 90,
        },
        children: [
          {
            path: '/client/tickets',
            name: 'ClientTickets',
            component: () => import('@/pages/client/tickets/index.vue'),
            meta: {
              title: title('工单支持', 'Ticket Support', '工單支援'),
              icon: icon(ServiceIcon),
              requireAuth: true,
              orderNo: 10,
            },
          },
          {
            path: '/client/notices',
            name: 'ClientNotices',
            component: () => import('@/pages/client/notices/index.vue'),
            meta: {
              title: title('系统公告', 'Announcements', '系統公告'),
              icon: icon(NotificationIcon),
              requireAuth: true,
              orderNo: 20,
            },
          },
          {
            path: '/client/help',
            name: 'ClientHelp',
            component: () => import('@/pages/client/help/index.vue'),
            meta: {
              title: title('帮助中心', 'Help Center', '幫助中心'),
              icon: icon(HelpCircleIcon),
              requireAuth: true,
              orderNo: 30,
            },
          },
        ],
      },
      {
        path: '/client/account',
        redirect: '/client/profile',
        meta: {
          title: title('账户设置', 'Settings', '賬戶設定'),
          icon: icon(UserCircleIcon),
          requireAuth: true,
          orderNo: 100,
        },
        children: [
          {
            path: '/client/profile',
            name: 'ClientProfile',
            component: () => loadAccountPage().then((m) => m.ProfilePage),
            meta: {
              title: title('个人资料', 'Profile', '個人資料'),
              icon: icon(UserCircleIcon),
              requireAuth: true,
              orderNo: 10,
            },
          },
          {
            // 与个人资料平级，不用 /client/profile/... 前缀：
            // 侧边栏高亮是按路径前缀匹配的，若作为个人资料的子路径，
            // 选中时两个菜单项会拿到同一个 value 而一起点亮
            path: '/client/security',
            name: 'ClientSecurity',
            component: () => loadAccountPage().then((m) => m.ProfilePage),
            meta: {
              title: title('账户安全', 'Account Security', '賬戶安全'),
              icon: icon(LockOnIcon),
              requireAuth: true,
              orderNo: 20,
            },
          },
          {
            path: '/client/notification',
            name: 'ClientNotification',
            component: () => loadAccountPage().then((m) => m.ProfilePage),
            meta: {
              title: title('消息提醒', 'Notifications', '訊息提醒'),
              icon: icon(NotificationIcon),
              requireAuth: true,
              orderNo: 30,
            },
          },
          {
            // 兼容旧的嵌套路径
            path: '/client/profile/security',
            redirect: { path: '/client/security' },
            meta: { requireAuth: true, hidden: true },
          },
          {
            path: '/client/profile/notification',
            redirect: { path: '/client/notification' },
            meta: { requireAuth: true, hidden: true },
          },
          {
            path: '/client/verification',
            name: 'ClientVerification',
            component: () => loadAccountPage().then((m) => m.VerificationPage),
            meta: {
              title: title('实名认证', 'Identity Verification', '實名認證'),
              icon: icon(VerifiedIcon),
              requireAuth: true,
              orderNo: 40,
            },
          },
          {
            path: '/client/api-keys',
            name: 'ClientApiKeys',
            component: () => loadAccountPage().then((m) => m.ApiKeysPage),
            meta: {
              title: title('API 凭据', 'API Credentials', 'API 憑據'),
              icon: icon(ApiIcon),
              requireAuth: true,
              orderNo: 50,
            },
          },
          {
            // 两套凭据已合并到「API 凭据」页的页内标签；旧路径保留跳转到魔方财务对接分区
            path: '/client/upstream-api',
            redirect: { path: '/client/api-keys', query: { tab: 'upstream' } },
            meta: { title: title('API 凭据', 'API Credentials', 'API 憑據'), requireAuth: true, hidden: true },
          },
        ],
      },
      {
        // AI 助手独立页：浮窗「在独立页打开」与侧边栏都进这里。
        // 路径写成绝对形式（等价于 client/ai）：菜单直接用路由记录的 path 做导航，
        // 相对路径会按当前页末级段解析，从详情页点进去会跳到错误地址。
        // keepAlive 关闭，避免离开后仍挂着一份流式会话。
        path: '/client/ai',
        name: 'ClientAi',
        component: () => import('@/pages/client/ai/index.vue'),
        meta: {
          title: title('AI 助手', 'AI Assistant', 'AI 助理'),
          // 侧边栏图标与浮球、抽屉头部、消息头像统一用同一个机器人头 SVG，
          // 不再用 tdesign 的对话气泡（AI 助手不是客服按钮）
          icon: icon(AiRobotAvatar),
          requireAuth: true,
          orderNo: 110,
          keepAlive: false,
        },
      },
      {
        path: 'order/create',
        name: 'ClientOrderCreate',
        component: () => import('@/pages/client/order-create/index.vue'),
        meta: {
          title: title('确认购买', 'Confirm Purchase', '確認購買'),
          requireAuth: true,
          hidden: true,
          activeMenu: '/client/catalog',
        },
      },
      {
        path: 'checkout-resume',
        name: 'ClientCheckoutResume',
        component: () => import('@/pages/client/checkout-resume/index.vue'),
        meta: {
          title: title('创建账单中', 'Creating Invoice', '建立賬單中'),
          requireAuth: true,
          hidden: true,
          activeMenu: '/client/invoices',
        },
      },
      {
        path: 'services/:id',
        name: 'ClientServiceDetail',
        component: () => import('@/pages/client/service-console/index.vue'),
        meta: {
          title: title('实例控制台', 'Instance Console', '例項控制台'),
          requireAuth: true,
          hidden: true,
          activeMenu: '/client/services',
          keepAlive: false,
        },
      },
      {
        // 兼容旧入口 /compute：同一套动态控制台，tabs 由上游能力动态下发
        path: 'services/:id/compute',
        name: 'ClientComputeConsole',
        component: () => import('@/pages/client/service-console/index.vue'),
        meta: {
          title: title('实例控制台', 'Instance Console', '例項控制台'),
          requireAuth: true,
          hidden: true,
          activeMenu: '/client/services',
          keepAlive: false,
        },
      },
      {
        // 兼容旧入口 /port-mapping：NAT 能力随控制台动态 tab 一并下发
        path: 'services/:id/port-mapping',
        name: 'ClientNatConsole',
        component: () => import('@/pages/client/service-console/index.vue'),
        meta: {
          title: title('实例控制台', 'Instance Console', '例項控制台'),
          requireAuth: true,
          hidden: true,
          activeMenu: '/client/services',
          keepAlive: false,
        },
      },
      {
        path: 'invoices/:id/pay',
        name: 'ClientInvoicePay',
        component: () => import('@/pages/client/invoice-detail/index.vue'),
        meta: {
          title: title('账单支付', 'Pay Invoice', '賬單支付'),
          requireAuth: true,
          hidden: true,
          activeMenu: '/client/invoices',
          keepAlive: false,
        },
      },
      {
        path: 'invoices/:id',
        name: 'ClientInvoiceDetail',
        component: () => import('@/pages/client/invoice-detail-view/index.vue'),
        meta: {
          title: title('账单详情', 'Invoice Details', '賬單詳情'),
          requireAuth: true,
          hidden: true,
          activeMenu: '/client/invoices',
        },
      },
      {
        path: 'orders/:id',
        name: 'ClientOrderDetail',
        component: () => import('@/pages/client/order-detail/index.vue'),
        meta: {
          title: title('订单详情', 'Order Details', '訂單詳情'),
          requireAuth: true,
          hidden: true,
          activeMenu: '/client/orders',
        },
      },
      {
        path: 'payments/:id',
        name: 'ClientPaymentDetail',
        component: () => import('@/pages/client/payment-detail/index.vue'),
        meta: {
          title: title('充值详情', 'Top-up Details', '充值詳情'),
          requireAuth: true,
          hidden: true,
          activeMenu: '/client/payments',
        },
      },
      {
        path: 'balance-logs',
        name: 'ClientBalanceLogsDeprecated',
        redirect: '/result/404',
        meta: { title: title('余额流水', 'Balance History', '餘額流水'), requireAuth: true, hidden: true },
      },
      {
        path: 'tickets/:id',
        name: 'ClientTicketDetail',
        component: () => import('@/pages/client/ticket-detail/index.vue'),
        meta: {
          title: title('工单详情', 'Ticket Details', '工單詳情'),
          requireAuth: true,
          hidden: true,
          activeMenu: '/client/tickets',
        },
      },
      {
        path: 'ticket-conversations/:id',
        name: 'ClientTicketConversation',
        component: () => import('@/pages/client/ticket-detail/index.vue'),
        meta: {
          title: title('工单交流', 'Ticket Conversation', '工單交流'),
          requireAuth: true,
          hidden: true,
          activeMenu: '/client/tickets',
        },
      },
      {
        path: 'notices/:id',
        name: 'ClientNoticeDetail',
        component: () => import('@/pages/client/notice-detail/index.vue'),
        meta: {
          title: title('公告详情', 'Announcement Details', '公告詳情'),
          requireAuth: true,
          hidden: true,
          activeMenu: '/client/notices',
        },
      },
      {
        path: 'help/:id',
        name: 'ClientHelpDetail',
        component: () => import('@/pages/client/help-detail/index.vue'),
        meta: {
          title: title('帮助详情', 'Help Article', '幫助詳情'),
          requireAuth: true,
          hidden: true,
          activeMenu: '/client/help',
        },
      },
    ],
  },
] satisfies RouteRecordRaw[];
