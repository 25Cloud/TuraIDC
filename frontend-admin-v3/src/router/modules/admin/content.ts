import type { RouteRecordRaw } from 'vue-router';

export const contentRoutes: RouteRecordRaw[] = [
  {
    path: 'content/notices',
    name: 'AdminContentNotices',
    component: () => import('@/pages/content/index.vue'),
    meta: {
      title: {
        zh_CN: '系统公告',
        zh_TW: '系統公告',
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
        zh_TW: '新增公告',
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
        zh_TW: '編輯公告',
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
        zh_TW: '幫助中心',
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
        zh_TW: '新增幫助文章',
        en_US: 'New Article',
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
        zh_TW: '編輯幫助文章',
        en_US: 'Edit Article',
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
        zh_TW: '媒體庫',
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
        zh_TW: '站點資訊',
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
        zh_TW: '首頁裝修',
        en_US: 'Site Hero',
      },
      permission: 'site.view',
      settingsTab: 'site_hero',
      keepAliveName: 'AdminSettings',
    },
  },
  {
    // 选购页与独立配置页的展示层配置单独成页：这些开关属于「商品怎么卖」，
    // 与站点信息里的名称/联系方式挤在同一张表单里既难找也容易误改。
    path: 'product-display',
    name: 'AdminProductDisplay',
    component: () => import('@/pages/product-display/index.vue'),
    meta: {
      title: {
        zh_CN: '商品展示',
        zh_TW: '商品展示',
        en_US: 'Display',
      },
      permission: 'site.view',
    },
  },
  {
    path: 'notifications',
    name: 'AdminNotifications',
    component: () => import('@/pages/notifications/index.vue'),
    meta: {
      title: {
        zh_CN: '邮件模板',
        zh_TW: '郵件模板',
        en_US: 'Emails',
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
        zh_TW: '簡訊模板',
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
        zh_TW: 'API 介面',
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
        zh_TW: '郵件模板詳情',
        en_US: 'Email Detail',
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
        zh_TW: '簡訊模板詳情',
        en_US: 'SMS Detail',
      },
      permission: 'settings.view',
      hidden: true,
    },
  },
];
