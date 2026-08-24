import type { RouteRecordRaw } from 'vue-router';

export const contentRoutes: RouteRecordRaw[] = [
  {
    path: 'content/notices',
    name: 'AdminContentNotices',
    component: () => import('@/pages/content/index.vue'),
    meta: {
      title: {
        zh_CN: '系统公告',
        en_US: 'Notices',
      },
      contentType: 'notice',
      permission: 'content.list',
      keepAliveName: 'AdminContentNotices',
    },
  },
  {
    path: 'content/notices/create',
    name: 'AdminContentNoticesCreate',
    component: () => import('@/pages/content/edit.vue'),
    meta: {
      title: {
        zh_CN: '新增公告',
        en_US: 'Create Notice',
      },
      contentType: 'notice',
      permission: 'content.manage',
      hidden: true,
    },
  },
  {
    path: 'content/notices/:id',
    name: 'AdminContentNoticesEdit',
    component: () => import('@/pages/content/edit.vue'),
    meta: {
      title: {
        zh_CN: '编辑公告',
        en_US: 'Edit Notice',
      },
      contentType: 'notice',
      permission: 'content.manage',
      hidden: true,
    },
  },
  {
    path: 'content/help',
    name: 'AdminContentHelp',
    component: () => import('@/pages/content/index.vue'),
    meta: {
      title: {
        zh_CN: '帮助中心',
        en_US: 'Help Center',
      },
      contentType: 'help',
      permission: 'content.list',
      keepAliveName: 'AdminContentNotices',
    },
  },
  {
    path: 'content/help/create',
    name: 'AdminContentHelpCreate',
    component: () => import('@/pages/content/edit.vue'),
    meta: {
      title: {
        zh_CN: '新增帮助文章',
        en_US: 'Create Help Article',
      },
      contentType: 'help',
      permission: 'content.manage',
      hidden: true,
    },
  },
  {
    path: 'content/help/:id',
    name: 'AdminContentHelpEdit',
    component: () => import('@/pages/content/edit.vue'),
    meta: {
      title: {
        zh_CN: '编辑帮助文章',
        en_US: 'Edit Help Article',
      },
      contentType: 'help',
      permission: 'content.manage',
      hidden: true,
    },
  },
  {
    path: 'content/media-library',
    name: 'AdminMediaLibrary',
    component: () => import('@/pages/content/media-library.vue'),
    meta: {
      title: {
        zh_CN: '媒体库',
        en_US: 'Media Library',
      },
      permission: 'content.list',
    },
  },
  {
    path: 'site-info',
    name: 'AdminSiteInfo',
    component: () => import('@/pages/settings/index.vue'),
    meta: {
      title: {
        zh_CN: '站点信息',
        en_US: 'Site Info',
      },
      permission: 'site.view',
      settingsTab: 'site_basic',
      keepAliveName: 'AdminSettings',
    },
  },
  {
    path: 'site-hero',
    name: 'AdminSiteHero',
    component: () => import('@/pages/settings/index.vue'),
    meta: {
      title: {
        zh_CN: '首页装修',
        en_US: 'Site Hero',
      },
      permission: 'site.view',
      settingsTab: 'site_hero',
      keepAliveName: 'AdminSettings',
    },
  },
  {
    path: 'notifications',
    name: 'AdminNotifications',
    component: () => import('@/pages/notifications/index.vue'),
    meta: {
      title: {
        zh_CN: '邮件模板',
        en_US: 'Email Templates',
      },
      permission: 'settings.view',
      notificationTab: 'email-templates',
      keepAliveName: 'AdminNotifications',
    },
  },
  {
    path: 'notifications/sms-templates',
    name: 'AdminNotificationSmsTemplates',
    component: () => import('@/pages/notifications/index.vue'),
    meta: {
      title: {
        zh_CN: '短信模板',
        en_US: 'SMS Templates',
      },
      permission: 'settings.view',
      notificationTab: 'sms-templates',
      keepAliveName: 'AdminNotifications',
    },
  },
  {
    path: 'notifications/api-directory',
    name: 'AdminNotificationApiDirectory',
    component: () => import('@/pages/notifications/index.vue'),
    meta: {
      title: {
        zh_CN: 'API 接口',
        en_US: 'API Directory',
      },
      permission: 'settings.view',
      notificationTab: 'api-directory',
      keepAliveName: 'AdminNotifications',
    },
  },
  {
    path: 'notifications/email-templates/:code',
    name: 'AdminNotificationEmailTemplateDetail',
    component: () => import('@/pages/notifications/email-template-detail/index.vue'),
    meta: {
      title: {
        zh_CN: '邮件模板详情',
        en_US: 'Email Template Detail',
      },
      permission: 'settings.view',
      hidden: true,
    },
  },
  {
    path: 'notifications/sms-templates/:code',
    name: 'AdminNotificationSmsTemplateDetail',
    component: () => import('@/pages/notifications/email-template-detail/index.vue'),
    meta: {
      title: {
        zh_CN: '短信模板详情',
        en_US: 'SMS Template Detail',
      },
      permission: 'settings.view',
      hidden: true,
    },
  },
];
