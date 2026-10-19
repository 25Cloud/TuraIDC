import type { RouteRecordRaw } from 'vue-router';

export const marketingRoutes: RouteRecordRaw[] = [
  {
    path: 'member-levels',
    name: 'AdminMemberLevels',
    component: () => import('@/pages/member-levels/index.vue'),
    meta: {
      title: {
        zh_CN: '会员等级',
        zh_TW: '會員等級',
        en_US: 'Member Levels',
      },
      permission: 'member_level.list',
    },
  },
  {
    path: 'agent-discounts',
    name: 'AdminAgentDiscounts',
    component: () => import('@/pages/agent-discounts/index.vue'),
    meta: {
      title: {
        zh_CN: '代理折扣',
        zh_TW: '代理折扣',
        en_US: 'Discounts',
      },
      permission: 'agent_discount.list',
    },
  },
  {
    path: 'coupons',
    name: 'AdminCoupons',
    component: () => import('@/pages/products/coupons/index.vue'),
    meta: {
      title: {
        zh_CN: '优惠券列表',
        zh_TW: '優惠券列表',
        en_US: 'Coupons',
      },
      permission: 'product.list',
      couponTab: 'coupons',
      keepAliveName: 'AdminCoupons',
    },
  },
  {
    path: 'coupon-campaigns',
    name: 'AdminCouponCampaigns',
    component: () => import('@/pages/products/coupons/index.vue'),
    meta: {
      title: {
        zh_CN: '活动券管理',
        zh_TW: '活動券管理',
        en_US: 'Campaigns',
      },
      permission: 'product.list',
      couponTab: 'campaigns',
      keepAliveName: 'AdminCoupons',
    },
  },
  {
    path: 'referral',
    name: 'AdminReferral',
    component: () => import('@/pages/referral/index.vue'),
    meta: {
      title: {
        zh_CN: '推广概览',
        zh_TW: '推廣概覽',
        en_US: 'Overview',
      },
      permission: 'referral.list',
      referralTab: 'overview',
      keepAliveName: 'AdminReferral',
    },
  },
  {
    path: 'referral/rewards',
    name: 'AdminReferralRewards',
    component: () => import('@/pages/referral/index.vue'),
    meta: {
      title: {
        zh_CN: '奖励记录',
        zh_TW: '獎勵記錄',
        en_US: 'Rewards',
      },
      permission: 'referral.list',
      referralTab: 'rewards',
      keepAliveName: 'AdminReferral',
    },
  },
  {
    path: 'referral/withdrawals',
    name: 'AdminReferralWithdrawals',
    component: () => import('@/pages/referral/index.vue'),
    meta: {
      title: {
        zh_CN: '提现审核',
        zh_TW: '提現稽核',
        en_US: 'Withdrawals',
      },
      permission: 'referral_withdrawal.list',
      referralTab: 'withdrawals',
      keepAliveName: 'AdminReferral',
    },
  },
];
