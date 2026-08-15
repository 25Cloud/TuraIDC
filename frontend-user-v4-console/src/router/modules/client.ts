import {
  CatalogIcon,
  CouponIcon,
  DashboardIcon,
  FileIcon,
  GiftIcon,
  HelpCircleIcon,
  NotificationIcon,
  ServerIcon,
  ServiceIcon,
  UserCircleIcon,
  UserSafetyIcon,
  WalletIcon,
} from 'tdesign-icons-vue-next';
import { shallowRef } from 'vue';
import type { RouteRecordRaw } from 'vue-router';

import Layout from '@/layouts/index.vue';

const title = (zhCN: string, enUS = zhCN) => ({ zh_CN: zhCN, en_US: enUS });
const icon = (component: unknown) => shallowRef(component);

export default [
  {
    path: '/',
    redirect: '/client/dashboard',
  },
  {
    path: '/client/login',
    name: 'ClientLogin',
    component: () => import('@/pages/client-auth/login.vue'),
    meta: { title: title('用户登录', 'Login'), guest: true, robots: 'noindex,nofollow' },
  },
  {
    path: '/client/login-as',
    name: 'ClientLoginAs',
    component: () => import('@/pages/client-auth/login-as.vue'),
    meta: { title: title('代登录', 'Login As'), guest: true, robots: 'noindex,nofollow' },
  },
  {
    path: '/client/register',
    name: 'ClientRegister',
    component: () => import('@/pages/client-auth/register.vue'),
    meta: { title: title('用户注册', 'Register'), guest: true, robots: 'noindex,nofollow' },
  },
  {
    path: '/client/forgot-password',
    name: 'ClientForgotPassword',
    component: () => import('@/pages/client-auth/forgot-password.vue'),
    meta: { title: title('找回密码', 'Forgot Password'), guest: true, robots: 'noindex,nofollow' },
  },
  {
    path: '/client',
    component: Layout,
    redirect: '/client/dashboard',
    meta: {
      title: title('用户控制台', 'Client Console'),
      requireAuth: true,
      role: 'client',
      robots: 'noindex,nofollow',
    },
    children: [
      {
        path: '/client/overview',
        redirect: '/client/dashboard',
        meta: { title: title('首页', 'Home'), icon: icon(DashboardIcon), requireAuth: true, orderNo: 10 },
        children: [
          {
            path: '/client/dashboard',
            name: 'ClientDashboard',
            component: () => import('@/pages/client/dashboard/index.vue'),
            meta: {
              title: title('控制台', 'Console'),
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
        meta: { title: title('产品与服务', 'Products'), icon: icon(ServerIcon), requireAuth: true, orderNo: 20 },
        children: [
          {
            path: '/client/services',
            name: 'ClientServices',
            component: () => import('@/pages/client/services/index.vue'),
            meta: { title: title('我的服务'), icon: icon(ServerIcon), requireAuth: true, orderNo: 10 },
          },
          {
            path: '/client/catalog',
            name: 'ClientCatalog',
            component: () => import('@/pages/client/catalog/index.vue'),
            meta: { title: title('产品目录'), icon: icon(CatalogIcon), requireAuth: true, orderNo: 20 },
          },
        ],
      },
      {
        path: '/client/trade',
        redirect: '/client/orders',
        meta: { title: title('交易记录', 'Trade'), icon: icon(FileIcon), requireAuth: true, orderNo: 30 },
        children: [
          {
            path: '/client/orders',
            name: 'ClientOrders',
            component: () => import('@/pages/client/orders/index.vue'),
            meta: { title: title('订单记录'), icon: icon(FileIcon), requireAuth: true, orderNo: 10 },
          },
          {
            path: '/client/invoices',
            name: 'ClientInvoices',
            component: () => import('@/pages/client/invoices/index.vue'),
            meta: { title: title('账单记录'), icon: icon(FileIcon), requireAuth: true, orderNo: 20 },
          },
        ],
      },
      {
        path: '/client/wallet',
        redirect: '/client/recharge',
        meta: { title: title('钱包与充值', 'Wallet'), icon: icon(WalletIcon), requireAuth: true, orderNo: 40 },
        children: [
          {
            path: '/client/recharge',
            name: 'ClientRecharge',
            component: () => import('@/pages/client/recharge/index.vue'),
            meta: {
              title: title('账户充值'),
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
            meta: { title: title('充值记录'), icon: icon(WalletIcon), requireAuth: true, orderNo: 20 },
          },
        ],
      },
      {
        path: '/client/promo',
        redirect: '/client/coupons',
        meta: { title: title('优惠与活动', 'Promotions'), icon: icon(CouponIcon), requireAuth: true, orderNo: 50 },
        children: [
          {
            path: '/client/coupons',
            name: 'ClientCoupons',
            component: () => import('@/pages/client/coupons/index.vue'),
            meta: { title: title('优惠券中心'), icon: icon(CouponIcon), requireAuth: true, orderNo: 10 },
          },
          {
            path: '/client/referral',
            name: 'ClientReferral',
            component: () => import('@/pages/client/referral/index.vue'),
            meta: { title: title('推荐奖励'), icon: icon(GiftIcon), requireAuth: true, orderNo: 20 },
          },
        ],
      },
      {
        path: '/client/support',
        redirect: '/client/tickets',
        meta: { title: title('帮助与支持', 'Help & Support'), icon: icon(ServiceIcon), requireAuth: true, orderNo: 90 },
        children: [
          {
            path: '/client/tickets',
            name: 'ClientTickets',
            component: () => import('@/pages/client/tickets/index.vue'),
            meta: { title: title('工单支持'), icon: icon(ServiceIcon), requireAuth: true, orderNo: 10 },
          },
          {
            path: '/client/notices',
            name: 'ClientNotices',
            component: () => import('@/pages/client/notices/index.vue'),
            meta: { title: title('系统公告'), icon: icon(NotificationIcon), requireAuth: true, orderNo: 20 },
          },
          {
            path: '/client/help',
            name: 'ClientHelp',
            component: () => import('@/pages/client/help/index.vue'),
            meta: { title: title('帮助中心'), icon: icon(HelpCircleIcon), requireAuth: true, orderNo: 30 },
          },
        ],
      },
      {
        path: '/client/account',
        redirect: '/client/verification',
        meta: { title: title('账户设置', 'Settings'), icon: icon(UserCircleIcon), requireAuth: true, orderNo: 100 },
        children: [
          {
            path: '/client/verification',
            name: 'ClientVerification',
            component: () => import('@/pages/client/verification/index.vue'),
            meta: { title: title('实名认证'), icon: icon(UserSafetyIcon), requireAuth: true, orderNo: 10 },
          },
          {
            path: '/client/profile',
            name: 'ClientProfile',
            component: () => import('@/pages/client/profile/index.vue'),
            meta: { title: title('个人资料'), icon: icon(UserCircleIcon), requireAuth: true, orderNo: 20 },
          },
        ],
      },
      {
        path: 'order/create',
        name: 'ClientOrderCreate',
        component: () => import('@/pages/client/order-create/index.vue'),
        meta: { title: title('确认购买'), requireAuth: true, hidden: true, activeMenu: '/client/catalog' },
      },
      {
        path: 'checkout-resume',
        name: 'ClientCheckoutResume',
        component: () => import('@/pages/client/checkout-resume/index.vue'),
        meta: { title: title('创建账单中'), requireAuth: true, hidden: true, activeMenu: '/client/invoices' },
      },
      {
        path: 'services/:id',
        name: 'ClientServiceDetail',
        component: () => import('@/pages/client/service-console/entry.vue'),
        meta: {
          title: title('实例控制台'),
          requireAuth: true,
          hidden: true,
          activeMenu: '/client/services',
          keepAlive: false,
        },
      },
      {
        path: 'services/:id/compute',
        name: 'ClientComputeConsole',
        component: () => import('@/pages/client/service-console/compute/index.vue'),
        meta: {
          title: title('云服务器控制台'),
          requireAuth: true,
          hidden: true,
          activeMenu: '/client/services',
          keepAlive: false,
        },
      },
      {
        path: 'services/:id/port-mapping',
        name: 'ClientNatConsole',
        component: () => import('@/pages/client/service-console/nat/index.vue'),
        meta: {
          title: title('端口映射控制台'),
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
          title: title('账单支付'),
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
        meta: { title: title('账单详情'), requireAuth: true, hidden: true, activeMenu: '/client/invoices' },
      },
      {
        path: 'orders/:id',
        name: 'ClientOrderDetail',
        component: () => import('@/pages/client/order-detail/index.vue'),
        meta: { title: title('订单详情'), requireAuth: true, hidden: true, activeMenu: '/client/orders' },
      },
      {
        path: 'payments/:id',
        name: 'ClientPaymentDetail',
        component: () => import('@/pages/client/payment-detail/index.vue'),
        meta: { title: title('充值详情'), requireAuth: true, hidden: true, activeMenu: '/client/payments' },
      },
      {
        path: 'balance-logs',
        name: 'ClientBalanceLogsDeprecated',
        redirect: '/result/404',
        meta: { title: title('余额流水'), requireAuth: true, hidden: true },
      },
      {
        path: 'tickets/:id',
        name: 'ClientTicketDetail',
        component: () => import('@/pages/client/ticket-detail/index.vue'),
        meta: { title: title('工单详情'), requireAuth: true, hidden: true, activeMenu: '/client/tickets' },
      },
      {
        path: 'ticket-conversations/:id',
        name: 'ClientTicketConversation',
        component: () => import('@/pages/client/ticket-detail/index.vue'),
        meta: { title: title('工单交流'), requireAuth: true, hidden: true, activeMenu: '/client/tickets' },
      },
      {
        path: 'notices/:id',
        name: 'ClientNoticeDetail',
        component: () => import('@/pages/client/notice-detail/index.vue'),
        meta: { title: title('公告详情'), requireAuth: true, hidden: true, activeMenu: '/client/notices' },
      },
      {
        path: 'help/:id',
        name: 'ClientHelpDetail',
        component: () => import('@/pages/client/help-detail/index.vue'),
        meta: { title: title('帮助详情'), requireAuth: true, hidden: true, activeMenu: '/client/help' },
      },
    ],
  },
] satisfies RouteRecordRaw[];
