import type { RouteRecordRaw } from 'vue-router';

/**
 * 自助退款页面（台账 + 商品策略）。
 *
 * 页面托管在核心（插件规范不允许插件注册管理端菜单与路由），能力来自
 * `addons/auto_refund` 插件：插件未启用或已卸载时 `/api/v2/admin/auto-refund/*` 整体 404。
 * 因此路由带 `requiresFeature`——侧栏分组隐藏（permission store），直接输地址也会被守卫
 * 退回首页并提示，而不是渲染一个接口全报错的台账。
 *
 * 权限码：路由只要求「能看账单列表」（后端台账与策略列表接口同样挂在 invoice.list 上），
 * 结算/驳回/作废与策略保存（PUT）在页面内按 invoice.manage 二次判定，上游自检与对账按
 * integration_plugin.test。插件不能自行注册权限码（插件规范第 9 节），所以这里不引入
 * auto_refund.* 新码。
 */
export const autoRefundRoutes: RouteRecordRaw[] = [
  {
    path: 'auto-refund/applications',
    name: 'AdminAutoRefundApplications',
    component: () => import('@/pages/auto-refund/applications/index.vue'),
    meta: {
      title: {
        zh_CN: '退款申请',
        zh_TW: '退款申請',
        en_US: 'Refund Applications',
      },
      permission: 'invoice.list',
      requiresFeature: 'auto_refund',
    },
  },
  {
    // 按商品配置退款类型/规则/要求：上游 AutoRefund 的配置粒度就是商品，
    // 全局一套配置时「产品首次」这类要求无处落地（执行计划第二阶段）。
    path: 'auto-refund/product-rules',
    name: 'AdminAutoRefundProductRules',
    component: () => import('@/pages/auto-refund/product-rules/index.vue'),
    meta: {
      title: {
        zh_CN: '退款商品策略',
        zh_TW: '退款商品策略',
        en_US: 'Product Refund Rules',
      },
      permission: 'invoice.list',
      requiresFeature: 'auto_refund',
    },
  },
];
