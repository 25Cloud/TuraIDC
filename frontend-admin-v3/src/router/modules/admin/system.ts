import type { RouteRecordRaw } from 'vue-router';

const integrationPluginDomains = ['captcha', 'verification', 'payment', 'mail', 'sms', 'upstream', 'addons'] as const;

function resolveIntegrationPluginDomain(value: unknown): (typeof integrationPluginDomains)[number] {
  const domain = Array.isArray(value) ? value[0] : value;
  return integrationPluginDomains.includes(domain as (typeof integrationPluginDomains)[number])
    ? (domain as (typeof integrationPluginDomains)[number])
    : 'captcha';
}

export const systemRoutes: RouteRecordRaw[] = [
  {
    path: 'logs',
    name: 'AdminLogs',
    component: () => import('@/pages/logs/index.vue'),
    meta: {
      title: {
        zh_CN: '日志中心',
        en_US: 'Logs',
      },
      permission: 'log.list',
      keepAlive: false,
      logTab: 'system',
    },
  },
  {
    path: 'logs/system',
    name: 'AdminSystemLogs',
    component: () => import('@/pages/logs/index.vue'),
    meta: {
      title: {
        zh_CN: '系统日志',
        en_US: 'System Logs',
      },
      permission: 'log.list',
      keepAlive: false,
      logTab: 'system',
    },
  },
  {
    path: 'logs/runtime',
    name: 'AdminRuntimeLogs',
    component: () => import('@/pages/logs/index.vue'),
    meta: {
      title: {
        zh_CN: '运行日志',
        en_US: 'Runtime Logs',
      },
      permission: 'log.list',
      keepAlive: false,
      logTab: 'runtime',
    },
  },
  {
    path: 'logs/admin-logins',
    name: 'AdminLoginLogs',
    component: () => import('@/pages/logs/index.vue'),
    meta: {
      title: {
        zh_CN: '管理员登录',
        en_US: 'Admin Logins',
      },
      permission: 'log.list',
      keepAlive: false,
      logTab: 'admin-logins',
    },
  },
  {
    path: 'logs/api',
    name: 'AdminApiLogs',
    component: () => import('@/pages/logs/index.vue'),
    meta: {
      title: {
        zh_CN: 'API 日志',
        en_US: 'API Logs',
      },
      permission: 'log.list',
      keepAlive: false,
      logTab: 'api',
    },
  },
  {
    path: 'logs/sms',
    name: 'AdminSmsLogs',
    component: () => import('@/pages/logs/index.vue'),
    meta: {
      title: {
        zh_CN: '短信日志',
        en_US: 'SMS Logs',
      },
      permission: 'log.list',
      keepAlive: false,
      logTab: 'sms',
    },
  },
  {
    path: 'logs/email',
    name: 'AdminEmailLogs',
    component: () => import('@/pages/logs/index.vue'),
    meta: {
      title: {
        zh_CN: '邮件日志',
        en_US: 'Email Logs',
      },
      permission: 'log.list',
      keepAlive: false,
      logTab: 'email',
    },
  },
  {
    path: 'logs/tasks',
    name: 'AdminTaskLogs',
    component: () => import('@/pages/logs/index.vue'),
    meta: {
      title: {
        zh_CN: '自动任务日志',
        en_US: 'Task Logs',
      },
      permission: 'log.list',
      keepAlive: false,
      logTab: 'tasks',
    },
  },
  {
    path: 'logs/gateway',
    name: 'AdminGatewayLogs',
    component: () => import('@/pages/logs/index.vue'),
    meta: {
      title: {
        zh_CN: '网关日志',
        en_US: 'Gateway Logs',
      },
      permission: 'log.list',
      keepAlive: false,
      logTab: 'gateway',
    },
  },
  {
    path: 'logs/upstream',
    name: 'AdminUpstreamDeliveryLogs',
    component: () => import('@/pages/logs/index.vue'),
    meta: {
      title: {
        zh_CN: '工单推送日志',
        en_US: 'Ticket Push Logs',
      },
      permission: 'log.list',
      keepAlive: false,
      logTab: 'upstream',
    },
  },
  {
    path: 'logs/upstream-uploads',
    name: 'AdminUpstreamUploadLogs',
    component: () => import('@/pages/logs/index.vue'),
    meta: {
      title: {
        zh_CN: '上传/清理日志',
        en_US: 'Upload & Cleanup Logs',
      },
      permission: 'log.list',
      keepAlive: false,
      logTab: 'upstream-uploads',
    },
  },
  {
    path: 'logs/schedules',
    name: 'AdminScheduleTasks',
    component: () => import('@/pages/logs/index.vue'),
    meta: {
      title: {
        zh_CN: '定时任务',
        en_US: 'Scheduled Tasks',
      },
      permission: 'schedule.view',
      keepAlive: false,
      logTab: 'schedules',
    },
  },
  {
    path: 'logs/cleanup',
    name: 'AdminLogCleanup',
    component: () => import('@/pages/logs/index.vue'),
    meta: {
      title: {
        zh_CN: '日志清理',
        en_US: 'Log Cleanup',
      },
      permission: 'log.list',
      keepAlive: false,
      logTab: 'cleanup',
    },
  },
  {
    path: 'system/staff',
    name: 'AdminStaff',
    component: () => import('@/pages/admin/system/staff/index.vue'),
    meta: {
      title: {
        zh_CN: '员工管理',
        en_US: 'Staff',
      },
      permission: 'staff.list',
    },
  },
  {
    path: 'system/roles',
    name: 'AdminRoles',
    component: () => import('@/pages/admin/system/roles/index.vue'),
    meta: {
      title: {
        zh_CN: '角色权限',
        en_US: 'Roles',
      },
      permission: 'role.list',
    },
  },
  {
    path: 'system/database',
    name: 'AdminDatabaseStatus',
    component: () => import('@/pages/admin/system/database/index.vue'),
    meta: {
      title: {
        zh_CN: '数据库状态',
        en_US: 'Database Status',
      },
      permission: 'database.view',
      keepAlive: false,
    },
  },
  {
    path: 'automation',
    name: 'AdminAutomation',
    component: () => import('@/pages/settings/index.vue'),
    meta: {
      title: {
        zh_CN: '自动化策略',
        en_US: 'Automation',
      },
      permission: 'schedule.view',
      settingsTab: 'automation',
      keepAliveName: 'AdminSettings',
    },
  },
  {
    path: 'log-archive',
    name: 'AdminLogArchiveSettings',
    component: () => import('@/pages/settings/index.vue'),
    meta: {
      title: {
        zh_CN: '日志归档',
        en_US: 'Log Archive',
      },
      permission: 'settings.view',
      settingsTab: 'log_archive',
      keepAliveName: 'AdminSettings',
    },
  },
  {
    path: 'payment',
    name: 'AdminPayment',
    redirect: '/admin/integration-plugins/payment',
  },
  {
    path: 'integration-plugins',
    name: 'AdminIntegrationPlugins',
    redirect: (to) => {
      const query = { ...to.query };
      delete query.domain;
      return {
        path: `/admin/integration-plugins/${resolveIntegrationPluginDomain(to.query.domain)}`,
        query,
      };
    },
    meta: {
      title: {
        zh_CN: '插件管理',
        en_US: 'Plugins',
      },
      permission: 'integration_plugin.view',
    },
  },
  {
    path: 'integration-plugins/captcha',
    name: 'AdminCaptchaPlugins',
    component: () => import('@/pages/integration-plugins/index.vue'),
    meta: {
      title: {
        zh_CN: '人机验证',
        en_US: 'Captcha Plugins',
      },
      permission: 'integration_plugin.view',
      pluginDomain: 'captcha',
      keepAliveName: 'AdminIntegrationPlugins',
    },
  },
  {
    path: 'integration-plugins/verification',
    name: 'AdminVerificationPlugins',
    component: () => import('@/pages/integration-plugins/index.vue'),
    meta: {
      title: {
        zh_CN: '实名认证',
        en_US: 'Verification Plugins',
      },
      permission: 'integration_plugin.view',
      pluginDomain: 'verification',
      keepAliveName: 'AdminIntegrationPlugins',
    },
  },
  {
    path: 'integration-plugins/payment',
    name: 'AdminPaymentPlugins',
    component: () => import('@/pages/integration-plugins/index.vue'),
    meta: {
      title: {
        zh_CN: '支付渠道',
        en_US: 'Payment Plugins',
      },
      permission: 'integration_plugin.view',
      pluginDomain: 'payment',
      keepAliveName: 'AdminIntegrationPlugins',
    },
  },
  {
    path: 'integration-plugins/mail',
    name: 'AdminMailPlugins',
    component: () => import('@/pages/integration-plugins/index.vue'),
    meta: {
      title: {
        zh_CN: '邮件发送',
        en_US: 'Mail Plugins',
      },
      permission: 'integration_plugin.view',
      pluginDomain: 'mail',
      keepAliveName: 'AdminIntegrationPlugins',
    },
  },
  {
    path: 'integration-plugins/sms',
    name: 'AdminSmsPlugins',
    component: () => import('@/pages/integration-plugins/index.vue'),
    meta: {
      title: {
        zh_CN: '短信发送',
        en_US: 'SMS Plugins',
      },
      permission: 'integration_plugin.view',
      pluginDomain: 'sms',
      keepAliveName: 'AdminIntegrationPlugins',
    },
  },
  {
    path: 'integration-plugins/upstream',
    name: 'AdminUpstreamPlugins',
    component: () => import('@/pages/integration-plugins/index.vue'),
    meta: {
      title: {
        zh_CN: '上游开通',
        en_US: 'Upstream Plugins',
      },
      permission: 'integration_plugin.view',
      pluginDomain: 'upstream',
      keepAliveName: 'AdminIntegrationPlugins',
    },
  },
  {
    path: 'integration-plugins/addons',
    name: 'AdminAddonPlugins',
    component: () => import('@/pages/integration-plugins/index.vue'),
    meta: {
      title: {
        zh_CN: '功能扩展',
        en_US: 'Addon Plugins',
      },
      permission: 'integration_plugin.view',
      pluginDomain: 'addons',
      keepAliveName: 'AdminIntegrationPlugins',
    },
  },
  {
    path: 'referral-settings',
    name: 'AdminReferralSettings',
    component: () => import('@/pages/settings/index.vue'),
    meta: {
      title: {
        zh_CN: '推荐奖励',
        en_US: 'Referral Settings',
      },
      permission: 'settings.view',
      settingsTab: 'referral',
      keepAliveName: 'AdminSettings',
    },
  },
  {
    path: 'open-api',
    name: 'AdminOpenApi',
    component: () => import('@/pages/open-api/index.vue'),
    meta: {
      title: {
        zh_CN: '开放接口',
        en_US: 'Open API',
      },
      permission: 'open_api.view',
    },
  },
];
