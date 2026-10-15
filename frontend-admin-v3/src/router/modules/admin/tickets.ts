import type { RouteRecordRaw } from 'vue-router';

export const ticketsRoutes: RouteRecordRaw[] = [
  {
    path: 'tickets',
    name: 'AdminTickets',
    component: () => import('@/pages/tickets/index.vue'),
    meta: {
      title: {
        zh_CN: '工单列表',
        zh_TW: '工單列表',
        en_US: 'Tickets',
      },
      permission: 'ticket.list',
    },
  },
  {
    path: 'ticket-delivery-rules',
    name: 'AdminTicketDeliveryRules',
    component: () => import('@/pages/tickets/delivery-settings/index.vue'),
    meta: {
      title: {
        zh_CN: '工单传递设置',
        zh_TW: '工單傳遞設定',
        en_US: 'Ticket Delivery Settings',
      },
      permission: 'ticket.delivery_manage',
    },
  },
  {
    path: 'ticket-pre-reply-settings',
    name: 'AdminTicketPreReplySettings',
    component: () => import('@/pages/tickets/pre-reply-settings/index.vue'),
    meta: {
      title: {
        zh_CN: '工单预回复设置',
        zh_TW: '工單預回覆設定',
        en_US: 'Ticket Pre-reply Settings',
      },
      permission: 'ticket.pre_reply_manage',
    },
  },
  {
    path: 'ticket-conversations/:id',
    name: 'AdminTicketConversation',
    component: () => import('@/pages/tickets/detail/index.vue'),
    meta: {
      title: {
        zh_CN: '工单交流',
        zh_TW: '工單交流',
        en_US: 'Ticket Conversation',
      },
      permission: 'ticket.list',
      hidden: true,
    },
  },
];
