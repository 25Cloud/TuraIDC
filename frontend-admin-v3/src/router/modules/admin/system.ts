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
        zh_TW: '日誌中心',
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
        zh_TW: '系統日誌',
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
        zh_TW: '執行日誌',
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
        zh_TW: '管理員登入',
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
        zh_TW: 'API 日誌',
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
        zh_TW: '簡訊日誌',
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
        zh_TW: '郵件日誌',
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
        zh_TW: '自動任務日誌',
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
        zh_TW: '閘道器日誌',
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
        zh_TW: '工單推送日誌',
        en_US: 'Push Logs',
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
        zh_TW: '上傳/清理日誌',
        en_US: 'Uploads',
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
        zh_TW: '定時任務',
        en_US: 'Schedules',
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
        zh_TW: '日誌清理',
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
        zh_TW: '員工管理',
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
        zh_TW: '角色許可權',
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
        zh_TW: '資料庫狀態',
        en_US: 'Database',
      },
      permission: 'database.view',
      keepAlive: false,
    },
  },
  {
    path: 'system/redis',
    name: 'AdminRedisStatus',
    component: () => import('@/pages/admin/system/redis/index.vue'),
    meta: {
      title: {
        zh_CN: '缓存状态',
        zh_TW: '快取狀態',
        en_US: 'Cache Status',
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
        zh_TW: '自動化策略',
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
        zh_TW: '日誌歸檔',
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
        zh_TW: '外掛管理',
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
        zh_TW: '人機驗證',
        en_US: 'Captcha',
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
        zh_TW: '實名認證',
        en_US: 'Verification',
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
        zh_TW: '支付渠道',
        en_US: 'Payment',
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
        zh_TW: '郵件傳送',
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
        zh_TW: '簡訊傳送',
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
        zh_TW: '上游開通',
        en_US: 'Upstream',
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
        zh_TW: '功能擴充套件',
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
        zh_TW: '推薦獎勵',
        en_US: 'Referral',
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
        zh_TW: '開放介面',
        en_US: 'Open API',
      },
      permission: 'open_api.view',
    },
  },
];
